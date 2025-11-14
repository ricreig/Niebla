<?php
declare(strict_types=1);

/**
 * update_schedule.php
 *
 * Importa el timetable de llegadas TIJ/MMTJ desde AviationStack y lo persiste
 * en la tabla `flights`.  Se ejecuta desde cron (CLI) tomando como parámetro
 * el día objetivo en hora local de Tijuana.  El script evita duplicados de
 * códigos compartidos, normaliza ICAO/IATA y conserva los estatus reales que
 * entrega AviationStack (scheduled, active, landed, etc.).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/avs_client.php';

/**
 * Parse string a DateTimeImmutable en UTC. Devuelve null si el valor es vacío
 * o no se puede interpretar.
 */
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
$iata = strtoupper((string)($cfg['IATA'] ?? 'TIJ'));
$icao = strtoupper((string)($cfg['ICAO'] ?? 'MMTJ'));
$tzName = $cfg['timezone'] ?? 'America/Tijuana';

try {
    $tzLocal = new DateTimeZone($tzName);
} catch (Throwable $e) {
    $tzLocal = new DateTimeZone('America/Tijuana');
}
$tzUtc = new DateTimeZone('UTC');

$argDate = $argv[1] ?? ($_GET['date'] ?? '');
$date = trim((string)$argDate);
if ($date === '') {
    $date = (new DateTimeImmutable('now', $tzLocal))->format('Y-m-d');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "[update_schedule] invalid date format: $date\n");
    exit(1);
}

$todayLocal = (new DateTimeImmutable('now', $tzLocal))->format('Y-m-d');
if ($date > $todayLocal) {
    $date = $todayLocal;
}

try {
    $localStart = new DateTimeImmutable($date . ' 00:00:00', $tzLocal);
} catch (Throwable $e) {
    fwrite(STDERR, "[update_schedule] unable to build local start for $date: {$e->getMessage()}\n");
    exit(1);
}
$localEnd = $localStart->modify('+1 day');
$utcRangeStart = $localStart->setTimezone($tzUtc);
$utcRangeEnd   = $localEnd->setTimezone($tzUtc);

$ttl = (isset($_GET['nocache']) || in_array('--nocache', $argv, true)) ? 0 : 900;
$res = avs_get('timetable', [
    'iataCode' => $iata,
    'type'     => 'arrival',
    'date'     => $date,
], $ttl);

if (!($res['ok'] ?? false)) {
    fwrite(STDERR, "[update_schedule] failed to fetch timetable: " . ($res['error'] ?? 'unknown') . "\n");
    exit(2);
}
$data = $res['data'] ?? [];
if (!is_array($data)) {
    $data = [];
}

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
    fwrite(STDERR, "[update_schedule] DB prepare error: " . $db->error . "\n");
    exit(3);
}

$flightNumber = $callsign = $airlineName = $acReg = $acType = $depCode = $arrCode = $stdUtc = $staUtc = $statusOut = null;
$delayMin = 0;
$ins->bind_param('sssssssssis', $flightNumber, $callsign, $airlineName, $acReg, $acType, $depCode, $arrCode, $stdUtc, $staUtc, $delayMin, $statusOut);

$seen = [];
$totalRows = 0;
$inserted = 0;
$updated = 0;
$skippedCodeshare = 0;
$skippedOutOfRange = 0;
$skippedNoSta = 0;
$skippedNoFlight = 0;

foreach ($data as $row) {
    $totalRows++;
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
    $acType = isset($ac['icao'] ) ? strtoupper(trim((string)$ac['icao'])) : null;
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
    $staUtcDt = parse_time_utc($arr['scheduled'] ?? $arr['scheduledTime'] ?? $arr['scheduled_time'] ?? null);
    if (!$staUtcDt) {
        $skippedNoSta++;
        continue;
    }

    $etaUtcDt = parse_time_utc($arr['estimated'] ?? $arr['estimatedTime'] ?? $arr['estimated_runway'] ?? null);
    $ataUtcDt = parse_time_utc($arr['actual'] ?? $arr['actualTime'] ?? $arr['actual_runway'] ?? null);

    $include = ($staUtcDt->setTimezone($tzLocal)->format('Y-m-d') === $date);
    if (!$include && $etaUtcDt) {
        $include = ($etaUtcDt->setTimezone($tzLocal)->format('Y-m-d') === $date);
    }
    if (!$include && $ataUtcDt) {
        $include = ($ataUtcDt->setTimezone($tzLocal)->format('Y-m-d') === $date);
    }
    if (!$include) {
        $skippedOutOfRange++;
        continue;
    }

    $depCode = pick_code($dep, $iata);
    $arrCode = pick_code($arr, $iata);
    if ($arrCode !== $iata && $arrCode !== $icao) {
        // Solo persistimos llegadas hacia TIJ/MMTJ.
        $arrCode = $iata;
    }

    $delayMin = 0;
    if (isset($arr['delay']) && is_numeric($arr['delay'])) {
        $delayMin = (int)$arr['delay'];
    } elseif ($etaUtcDt) {
        $delayMin = (int)round(($etaUtcDt->getTimestamp() - $staUtcDt->getTimestamp()) / 60);
    } elseif ($ataUtcDt) {
        $delayMin = (int)round(($ataUtcDt->getTimestamp() - $staUtcDt->getTimestamp()) / 60);
    }

    $statusOut = normalize_status((string)($row['flight_status'] ?? $row['status'] ?? 'scheduled'));
    if (in_array($statusOut, ['active', 'en-route'], true) && !$etaUtcDt) {
        // Sin ETA pero activo: lo marcamos como taxi para la UI.
        $statusOut = 'taxi';
    }

    $stdUtc = $stdUtcDt ? $stdUtcDt->format('Y-m-d H:i:s') : null;
    $staUtc = $staUtcDt->format('Y-m-d H:i:s');

    $dupKey = ($callsign ?: $flightNumber) . '|' . $staUtc;
    if (isset($seen[$dupKey])) {
        // Evita duplicados por códigos compartidos redundantes en el mismo ETA.
        continue;
    }
    $seen[$dupKey] = true;

    $arrCode = strtoupper($arrCode);
    $depCode = strtoupper($depCode);

    if (!$ins->execute()) {
        fwrite(STDERR, "[update_schedule] insert error for {$flightNumber}: " . $ins->error . "\n");
        continue;
    }
    $aff = $ins->affected_rows;
    if ($aff === 1) {
        $inserted++;
    } elseif ($aff === 2) {
        $updated++;
    }
}

$summary = sprintf(
    '[update_schedule] airport=%s tz=%s effective_date=%s (today_local=%s) total_api=%d inserted=%d updated=%d skipped_codeshare=%d skipped_no_sta=%d skipped_no_flight=%d skipped_range=%d',
    $iata,
    $tzLocal->getName(),
    $date,
    $todayLocal,
    $totalRows,
    $inserted,
    $updated,
    $skippedCodeshare,
    $skippedNoSta,
    $skippedNoFlight,
    $skippedOutOfRange
);

fwrite(STDOUT, $summary . "\n");
