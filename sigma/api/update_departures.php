<?php
declare(strict_types=1);

/**
 * update_departures.php
 *
 * This script populates the `flights` table with scheduled departures for
 * a set of airports.  It queries the AviationStack timetable endpoint
 * (limited to 10,000 monthly calls) and stores the resulting schedule in
 * the central SQL database.  Only departure rows for the specified date
 * are stored.  By caching these schedules locally, downstream APIs can
 * merge them with live Flightradar24 data without repeatedly hitting
 * AviationStack.
 *
 * Usage from CLI:
 *   php update_departures.php 2025-11-12
 * Without arguments, defaults to current UTC date.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/avs_client.php';

// Determine date
$dateParam = $argv[1] ?? ($_GET['date'] ?? '');
$date = trim($dateParam);
if ($date === '') {
  $date = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  fwrite(STDERR, "Invalid date format: $date\n");
  exit(1);
}

// List of airports to fetch departures for
$airports = ['TIJ','MXL','PPE','HMO','GYM'];

// Prepare DB
$db = db();
$db->set_charset('utf8mb4');
$ins = $db->prepare(
  "INSERT INTO flights (flight_number, callsign, airline, dep_icao, dst_icao, std_utc, sta_utc, delay_min, status)\n" .
  "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\n" .
  "ON DUPLICATE KEY UPDATE delay_min=VALUES(delay_min), status=VALUES(status)"
);
if (!$ins) {
  fwrite(STDERR, "DB prepare error: " . $db->error . "\n");
  exit(2);
}

$totalImported = 0;
foreach ($airports as $iata) {
  $iata = strtoupper($iata);
  // Fetch departures timetable for this airport and date
  $res = avs_get('timetable', [
    'iataCode' => $iata,
    'type'     => 'departure',
    'date'     => $date,
  ], 3600);
  if (!($res['ok'] ?? false)) {
    fwrite(STDERR, "Failed to fetch timetable for $iata: " . ($res['error'] ?? 'unknown') . "\n");
    continue;
  }
  $data = $res['data'] ?? [];
  if (!is_array($data)) $data = [];

  foreach ($data as $row) {
    $dep = $row['departure'] ?? [];
    $arr = $row['arrival'] ?? [];
    $air = $row['airline'] ?? [];
    $flt = $row['flight'] ?? [];
    // Flight number
    $flightNumber = $flt['iata'] ?? ($flt['iataNumber'] ?? null);
    if (!$flightNumber) $flightNumber = $flt['icao'] ?? null;
    $flightNumber = $flightNumber ? strtoupper((string)$flightNumber) : null;
    if (!$flightNumber) continue;
    // Callsign
    $callsign = $flt['icao'] ?? null;
    $callsign = $callsign ? strtoupper((string)$callsign) : null;
    // Airline
    $airlineName = $air['name'] ?? null;
    $airlineName = $airlineName ? trim((string)$airlineName) : null;
    // Departure and destination codes
    $depIata = strtoupper((string)($dep['iata'] ?? ($dep['iataCode'] ?? '')));
    $depIcao = strtoupper((string)($dep['icao'] ?? ''));
    $depCode = $depIata ?: $depIcao;
    $arrIata = strtoupper((string)($arr['iata'] ?? ($arr['iataCode'] ?? '')));
    $arrIcao = strtoupper((string)($arr['icao'] ?? ''));
    $arrCode = $arrIata ?: $arrIcao;
    // Scheduled times
    $stdIso = $dep['scheduled'] ?? ($dep['scheduledTime'] ?? null);
    $staIso = $arr['scheduled'] ?? ($arr['scheduledTime'] ?? null);
    $stdUtc = null;
    $staUtc = null;
    if ($stdIso) {
      $ts = strtotime($stdIso);
      if ($ts) $stdUtc = gmdate('Y-m-d H:i:s', $ts);
    }
    if ($staIso) {
      $ts = strtotime($staIso);
      if ($ts) $staUtc = gmdate('Y-m-d H:i:s', $ts);
    }
    // Skip if STD missing or date mismatch
    if (!$stdUtc) continue;
    if (substr($stdUtc, 0, 10) !== $date) continue;
    // Estimated departure used for delay
    $etdIso = $dep['estimated'] ?? ($dep['estimatedTime'] ?? null);
    $delayMin = 0;
    if ($stdIso && $etdIso) {
      $stdTs = strtotime($stdIso);
      $etdTs = strtotime($etdIso);
      if ($stdTs && $etdTs) $delayMin = (int)round(($etdTs - $stdTs) / 60);
    }
    // Status
    $statusRaw = strtolower((string)($row['flight_status'] ?? ($row['status'] ?? 'scheduled')));
    $dbStatus = in_array($statusRaw, ['cancelled','canceled','cncl','cancld'], true) ? 'cancelled' : 'scheduled';
    // Bind and execute
    $ins->bind_param('sssssssis',
      $flightNumber,
      $callsign,
      $airlineName,
      $depCode,
      $arrCode,
      $stdUtc,
      $staUtc,
      $delayMin,
      $dbStatus
    );
    if (!$ins->execute()) {
      fwrite(STDERR, "Insert error for flight $flightNumber: " . $ins->error . "\n");
      continue;
    }
    $totalImported++;
  }
}
fwrite(STDOUT, "Imported $totalImported departures for date $date\n");