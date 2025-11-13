<?php
declare(strict_types=1);

/**
 * update_schedule.php
 *
 * This script pulls the arrival timetable for TIJ from the AviationStack API
 * and stores the results into the `flights` table.  It is intended to be
 * executed by cron twice per day (e.g. every 12 hours) to persist the
 * scheduled arrivals in the database.  By caching the schedule in SQL we
 * avoid repeatedly hitting the limited AviationStack API and maintain a
 * historical record of operations.  Only the arrival side is currently
 * stored; departures could be added analogously.
 *
 * Example usage from CLI:
 *   php update_schedule.php 2025-11-12
 * If no date argument is given, the current UTC date is used.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/avs_client.php';

// Determine the date to fetch (YYYY-MM-DD).  If provided on the command
// line or via ?date=YYYY-MM-DD parameter, use that; otherwise default to
// current UTC date.
$dateParam = $argv[1] ?? ($_GET['date'] ?? '');
$date = trim($dateParam);
if ($date === '') {
  $date = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
}
// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  fwrite(STDERR, "Invalid date format: $date\n");
  exit(1);
}

// Airport IATA to import schedule for
$cfg = cfg();
$iata = strtoupper((string)($cfg['IATA'] ?? 'TIJ'));

// Fetch timetable from AviationStack using the helper client.  We force
// type=arrival because SIGMA focuses on inbound flights.  We pass a
// generous TTL so that repeated invocations during the same run will hit
// the cache.  The avs_client handles adding the access_key.
$res = avs_get('timetable', ['iataCode' => $iata, 'type' => 'arrival', 'date' => $date], 3600);
if (!($res['ok'] ?? false)) {
  fwrite(STDERR, "Failed to fetch timetable: " . ($res['error'] ?? 'unknown') . "\n");
  exit(2);
}
$data = $res['data'] ?? [];
if (!is_array($data)) $data = [];

// Prepare DB
$db = db();
$db->set_charset('utf8mb4');
$ins = $db->prepare(
  "INSERT INTO flights (flight_number, callsign, airline, dep_icao, dst_icao, std_utc, sta_utc, delay_min, status)\n"
  . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\n"
  . "ON DUPLICATE KEY UPDATE delay_min=VALUES(delay_min), status=VALUES(status)"
);
if (!$ins) {
  fwrite(STDERR, "DB prepare error: " . $db->error . "\n");
  exit(3);
}

$count = 0;
foreach ($data as $row) {
  // Extract nested fields with fallbacks
  $dep = $row['departure'] ?? [];
  $arr = $row['arrival'] ?? [];
  $air = $row['airline'] ?? [];
  $flt = $row['flight'] ?? [];

  // Flight number (prefer iataNumber or iata; fall back to icao)
  $flightNumber = $flt['iata'] ?? ($flt['iataNumber'] ?? null);
  if (!$flightNumber) {
    $flightNumber = $flt['icao'] ?? null;
  }
  $flightNumber = $flightNumber ? strtoupper((string)$flightNumber) : null;
  if (!$flightNumber) continue;

  // Callsign / flight ICAO (not always present); store uppercase or null
  $callsign = $flt['icao'] ?? null;
  $callsign = $callsign ? strtoupper((string)$callsign) : null;

  // Airline name
  $airlineName = $air['name'] ?? null;
  $airlineName = $airlineName ? trim((string)$airlineName) : null;

  // Departure and arrival airports (IATA or ICAO).  We store in dep_icao and dst_icao
  $depIata = strtoupper((string)($dep['iata'] ?? ($dep['iataCode'] ?? '')));
  $depIcao = strtoupper((string)($dep['icao'] ?? ''));
  $depCode = $depIata ?: $depIcao;

  $arrIata = strtoupper((string)($arr['iata'] ?? ($arr['iataCode'] ?? '')));
  $arrIcao = strtoupper((string)($arr['icao'] ?? ''));
  $arrCode = $arrIata ?: $arrIcao;

  // Scheduled times (UTC).  We convert to MySQL DATETIME (Y-m-d H:i:s) in UTC
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
  // Skip if STA is missing or date mismatch
  if (!$staUtc) continue;
  if (substr($staUtc, 0, 10) !== $date) continue;

  // Estimated time used to compute delay
  $etaIso = $arr['estimated'] ?? ($arr['estimatedTime'] ?? null);
  $delayMin = 0;
  if ($staIso && $etaIso) {
    $staTs = strtotime($staIso);
    $etaTs = strtotime($etaIso);
    if ($staTs && $etaTs) {
      $delayMin = (int)round(($etaTs - $staTs) / 60);
    }
  }

  // Status from AviationStack
  $statusRaw = $row['flight_status'] ?? ($row['status'] ?? 'scheduled');
  $statusRaw = strtolower((string)$statusRaw);
  // Map to simple DB status for schedule: we normalise to scheduled or cancelled
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
  $count++;
}

fwrite(STDOUT, "Imported $count flights for date $date\n");