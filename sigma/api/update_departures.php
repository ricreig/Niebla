<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/avs_client.php';

function parse_time_utc(?string $value): ?DateTimeImmutable {
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    try {
        if (preg_match('/[Zz]|[+\-]\d{2}:?\d{2}$/', $value)) {
            $dt = new DateTimeImmutable($value);
        } else {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }
        return $dt->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
    }
}

function pick_code(array $segment, string $fallback): string {
    $iata = strtoupper(trim((string)($segment['iata'] ?? $segment['iataCode'] ?? '')));
    $icao = strtoupper(trim((string)($segment['icao'] ?? $segment['icaoCode'] ?? '')));
    if ($iata !== '') {
        return $iata;
    }
    if ($icao !== '') {
        return $icao;
    }
    return $fallback;
}

function normalize_status(string $status): string {
    $t = strtolower(trim($status));
    if ($t === '') {
        return 'scheduled';
    }
    $map = [
        'active'   => 'active',
        'airborne' => 'en-route',
        'enroute'  => 'en-route',
        'en-route' => 'en-route',
        'landed'   => 'landed',
        'arrived'  => 'landed',
        'diverted' => 'diverted',
        'alternate'=> 'diverted',
        'cancelled'=> 'cancelled',
        'canceled' => 'cancelled',
        'cncl'     => 'cancelled',
        'cancld'   => 'cancelled',
        'delayed'  => 'delayed',
        'delay'    => 'delayed',
        'taxi'     => 'taxi',
        'scheduled'=> 'scheduled',
    ];
    return $map[$t] ?? $t;
}

$cfg = cfg();
$tzDefaultName = $cfg['timezone'] ?? 'America/Tijuana';
try {
    $tzDefault = new DateTimeZone($tzDefaultName);
} catch (Throwable $e) {
    $tzDefault = new DateTimeZone('America/Tijuana');
}

$cliArgs = $_SERVER['argv'] ?? [];
if (!is_array($cliArgs)) {
    $cliArgs = [];
}

$dateParam = $cliArgs[1] ?? ($_GET['date'] ?? '');
$date = trim((string)$dateParam);
if ($date === '') {
    $date = (new DateTimeImmutable('now', $tzDefault))->format('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    sigma_stderr("[update_departures] invalid date format: $date\n");
    exit(1);
}

$airports = ['TIJ','MXL','PPE','HMO','GYM'];

$db = db();
$db->set_charset('utf8mb4');

$sql = <<<SQL
INSERT INTO flights (
  flight_number,
  callsign,
  airline,
  ac_reg,
  ac_type,
  dep_icao,
  dst_icao,
  std_utc,
  sta_utc,
  delay_min,
  status
) VALUES (?,?,?,?,?,?,?,?,?,?,?)
ON DUPLICATE KEY UPDATE
  callsign   = VALUES(callsign),
  airline    = VALUES(airline),
  ac_reg     = VALUES(ac_reg),
  ac_type    = VALUES(ac_type),
  dep_icao   = VALUES(dep_icao),
  dst_icao   = VALUES(dst_icao),
  std_utc    = VALUES(std_utc),
  sta_utc    = VALUES(sta_utc),
  delay_min  = VALUES(delay_min),
  status     = VALUES(status)
SQL;

$ins = $db->prepare($sql);
if (!$ins) {
    sigma_stderr("[update_departures] DB prepare error: " . $db->error . "\n");
    exit(2);
}

$flightNumber = $callsign = $airlineName = $acReg = $acType = $depCode = $arrCode = $stdUtc = $staUtc = $statusOut = null;
$delayMin = 0;
$ins->bind_param('sssssssssis', $flightNumber, $callsign, $airlineName, $acReg, $acType, $depCode, $arrCode, $stdUtc, $staUtc, $delayMin, $statusOut);

$totalFetched = 0;
$inserted = 0;
$updated = 0;
$skippedCodeshare = 0;
$skippedNoStd = 0;
$skippedNoFlight = 0;
$skippedRange = 0;

foreach ($airports as $iata) {
    $iata = strtoupper($iata);
    $res = avs_get('timetable', [
        'iataCode' => $iata,
        'type'     => 'departure',
        'date'     => $date,
    ], 900);
    if (!($res['ok'] ?? false)) {
        sigma_stderr("[update_departures] failed to fetch timetable for {$iata}: " . ($res['error'] ?? 'unknown') . "\n");
        continue;
    }
    $data = $res['data'] ?? [];
    if (!is_array($data)) {
        continue;
    }

    $seen = [];
    foreach ($data as $row) {
        $totalFetched++;
        if (!empty($row['codeshared'])) {
            $skippedCodeshare++;
            continue;
        }

        $dep = is_array($row['departure'] ?? null) ? $row['departure'] : [];
        $arr = is_array($row['arrival'] ?? null) ? $row['arrival'] : [];
        $air = is_array($row['airline'] ?? null) ? $row['airline'] : [];
        $flt = is_array($row['flight'] ?? null) ? $row['flight'] : [];
        $ac  = is_array($row['aircraft'] ?? null) ? $row['aircraft'] : [];

        $flightIata = strtoupper(trim((string)($flt['iata'] ?? $flt['iataNumber'] ?? $flt['number'] ?? '')));
        $flightIcao = strtoupper(trim((string)($flt['icao'] ?? $flt['icaoNumber'] ?? '')));
        if ($flightIata === '' && $flightIcao === '') {
            $skippedNoFlight++;
            continue;
        }

        $flightNumber = $flightIata !== '' ? $flightIata : $flightIcao;
        $callsign = $flightIcao !== '' ? $flightIcao : null;

        $airlineName = null;
        if (isset($air['name']) && trim((string)$air['name']) !== '') {
            $airlineName = trim((string)$air['name']);
        }

        $acReg = isset($ac['registration']) ? strtoupper(trim((string)$ac['registration'])) : null;
        if ($acReg === '') {
            $acReg = null;
        }
        $acType = isset($ac['icao']) ? strtoupper(trim((string)$ac['icao'])) : null;
        if (!$acType && isset($ac['icao_code'])) {
            $acType = strtoupper(trim((string)$ac['icao_code']));
        }
        if (!$acType && isset($ac['iata'])) {
            $acType = strtoupper(trim((string)$ac['iata']));
        }
        if ($acType === '') {
            $acType = null;
        }

        $stdUtcDt = parse_time_utc($dep['scheduled'] ?? $dep['scheduledTime'] ?? $dep['scheduled_time'] ?? null);
        if (!$stdUtcDt) {
            $skippedNoStd++;
            continue;
        }
        $staUtcDt = parse_time_utc($arr['scheduled'] ?? $arr['scheduledTime'] ?? $arr['scheduled_time'] ?? null);
        $etaUtcDt = parse_time_utc($dep['estimated'] ?? $dep['estimatedTime'] ?? $dep['estimated_runway'] ?? null);

        $depTzName = $dep['timezone'] ?? $tzDefault->getName();
        try {
            $depTz = new DateTimeZone($depTzName);
        } catch (Throwable $e) {
            $depTz = $tzDefault;
        }
        $include = ($stdUtcDt->setTimezone($depTz)->format('Y-m-d') === $date);
        if (!$include && $etaUtcDt) {
            $include = ($etaUtcDt->setTimezone($depTz)->format('Y-m-d') === $date);
        }
        if (!$include) {
            $skippedRange++;
            continue;
        }

        $depCode = pick_code($dep, $iata);
        $arrCode = pick_code($arr, $arr['iata'] ?? $arr['icao'] ?? '');

        $delayMin = 0;
        if (isset($dep['delay']) && is_numeric($dep['delay'])) {
            $delayMin = (int)$dep['delay'];
        } elseif ($etaUtcDt) {
            $delayMin = (int)round(($etaUtcDt->getTimestamp() - $stdUtcDt->getTimestamp()) / 60);
        }

        $statusOut = normalize_status((string)($row['flight_status'] ?? $row['status'] ?? 'scheduled'));
        if (in_array($statusOut, ['active', 'en-route'], true) && !$etaUtcDt) {
            $statusOut = 'taxi';
        }

        $stdUtc = $stdUtcDt->format('Y-m-d H:i:s');
        $staUtc = $staUtcDt ? $staUtcDt->format('Y-m-d H:i:s') : null;

        $dupKey = ($callsign ?: $flightNumber) . '|' . $stdUtc;
        if (isset($seen[$dupKey])) {
            // Evita guardar múltiples filas del mismo vuelo por marketing/codeshare.
            continue;
        }
        $seen[$dupKey] = true;

        $depCode = strtoupper($depCode);
        $arrCode = $arrCode ? strtoupper($arrCode) : null;

        if (!$ins->execute()) {
            sigma_stderr("[update_departures] insert error for {$flightNumber}: " . $ins->error . "\n");
            continue;
        }
        $aff = $ins->affected_rows;
        if ($aff === 1) {
            $inserted++;
        } elseif ($aff === 2) {
            $updated++;
        }
    }
}

$summary = sprintf(
    '[update_departures] date=%s total_api=%d inserted=%d updated=%d skipped_codeshare=%d skipped_no_std=%d skipped_no_flight=%d skipped_range=%d',
    $date,
    $totalFetched,
    $inserted,
    $updated,
    $skippedCodeshare,
    $skippedNoStd,
    $skippedNoFlight,
    $skippedRange
);

sigma_stdout($summary . "\n");
