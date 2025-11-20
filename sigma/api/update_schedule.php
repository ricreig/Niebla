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

// Fuerza el contexto global a UTC para que cualquier función que dependa de la
// zona horaria predeterminada (p.ej. strtotime en parses de respaldo) use el
// día UTC actual y no la hora local del servidor.
date_default_timezone_set('UTC');

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

function first_non_empty(array $src, array $keys): string {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $src)) {
            continue;
        }
        $val = $src[$key];
        if ($val === null) {
            continue;
        }
        $str = trim((string)$val);
        if ($str !== '') {
            return $str;
        }
    }
    return '';
}

function extract_numeric_suffix(array $candidates): string {
    foreach ($candidates as $candidate) {
        if ($candidate === null) {
            continue;
        }
        $str = trim((string)$candidate);
        if ($str === '') {
            continue;
        }
        if (preg_match('/(\d{1,4})$/', $str, $m)) {
            return $m[1];
        }
        if (preg_match('/(\d+)/', $str, $m)) {
            return $m[1];
        }
    }
    return '';
}

/**
 * Fetch all timetable rows for the given airport/date from AviationStack
 * using the flights endpoint (supports historical and same-day queries).
 * Returns an array with keys `ok` (bool), `rows` (array) and optional
 * `error`/`message`.
 */
function avs_fetch_day(string $airportIata, string $airportIcao, string $targetDate, int $ttl): array {
    // AviationStack flights endpoint: supports same-day and historical by
    // filtering with flight_date + arrival airport.
    $endpoint = 'flights';
    $baseParams = [
        'arr_iata'      => $airportIata,
        'arr_icao'      => $airportIcao,
        'flight_date'   => $targetDate,
        'flight_status' => 'scheduled,active,landed,diverted,cancelled',
    ];

    $limit = 100;
    $offset = 0;
    $allRows = [];
    $page = 0;

    do {
        $params = $baseParams + ['limit' => $limit, 'offset' => $offset];
        $res = avs_get($endpoint, $params, $ttl);
        if (!($res['ok'] ?? false)) {
            return [
                'ok'     => false,
                'error'  => $res['error'] ?? 'avs_error',
                'url'    => $res['_url'] ?? null,
                'params' => $params,
            ];
        }
        $chunk = $res['data'] ?? [];
        if (!is_array($chunk)) {
            $chunk = [];
        }
        $count = count($chunk);
        $allRows = array_merge($allRows, $chunk);
        $offset += $limit;
        $page++;
        // Safety: avoid infinite loops if the API ignores pagination.
        if ($page > 40) {
            break;
        }
    } while ($count === $limit);

    return ['ok' => true, 'rows' => $allRows, 'endpoint' => $endpoint];
}

$cfg = cfg();
$iata = strtoupper((string)($cfg['IATA'] ?? 'TIJ'));
$icao = strtoupper((string)($cfg['ICAO'] ?? 'MMTJ'));
$tzFetch = new DateTimeZone('UTC');

$cliArgs = $_SERVER['argv'] ?? [];
if (!is_array($cliArgs)) {
    $cliArgs = [];
}

$argDate = '';
foreach ($cliArgs as $idx => $arg) {
    if ($idx === 0) {
        continue;
    }
    if (strpos($arg, '--') === 0) {
        continue;
    }
    $argDate = $arg;
    break;
}
if ($argDate === '') {
    $argDate = $_GET['date'] ?? '';
}
$date = trim((string)$argDate);
if ($date === '') {
    $date = (new DateTimeImmutable('now', $tzFetch))->format('Y-m-d');
}

$rangeDays = 2;
$forceSingleDay = false;
foreach ($cliArgs as $arg) {
    if (preg_match('/^--days=(\d{1,2})$/', $arg, $m)) {
        $rangeDays = max(1, min(5, (int)$m[1]));
    }
    if ($arg === '--single-day') {
        $forceSingleDay = true;
    }
}
if ($forceSingleDay) {
    $rangeDays = 1;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    sigma_stderr("[update_schedule] invalid date format: $date\n");
    exit(1);
}

$todayFetch = (new DateTimeImmutable('now', $tzFetch))->format('Y-m-d');
if ($date > $todayFetch) {
    $date = $todayFetch;
}

try {
    $anchor = new DateTimeImmutable($date . ' 00:00:00', $tzFetch);
} catch (Throwable $e) {
    sigma_stderr("[update_schedule] unable to build local start for $date: {$e->getMessage()}\n");
    exit(1);
}

$datesToFetch = [];
for ($i = $rangeDays - 1; $i >= 0; $i--) {
    $cursor = $anchor->modify('-' . $i . ' day');
    $cursorStr = $cursor->format('Y-m-d');
    if ($cursorStr > $todayFetch) {
        continue;
    }
    $datesToFetch[$cursorStr] = true;
}
$datesToFetch = array_keys($datesToFetch);
sort($datesToFetch);
if (!$datesToFetch) {
    $datesToFetch = [$date];
}

$ttl = (isset($_GET['nocache']) || in_array('--nocache', $cliArgs, true)) ? 0 : 900;

$fetchedRows = [];
$fetchErrors = [];
foreach ($datesToFetch as $cursorDate) {
    $cursorRes = avs_fetch_day($iata, $icao, $cursorDate, $ttl);
    if (!($cursorRes['ok'] ?? false)) {
        $fetchErrors[] = sprintf('date=%s err=%s', $cursorDate, $cursorRes['error'] ?? 'unknown');
        continue;
    }
    foreach ($cursorRes['rows'] as $row) {
        $fetchedRows[] = [$cursorDate, $row];
    }
}

if (!$fetchedRows) {
    sigma_stderr("[update_schedule] no data fetched for " . implode(',', $datesToFetch) . " errors=" . implode(';', $fetchErrors) . "\n");
    exit(2);
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
    sigma_stderr("[update_schedule] DB prepare error: " . $db->error . "\n");
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

foreach ($fetchedRows as [$targetDate, $row]) {
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
    $flightIcao = strtoupper(trim(first_non_empty($flt, [
        'icao', 'icaoNumber', 'icao_code', 'icaoCode', 'icao_number', 'icao_num',
        'icaoFlightNumber', 'icao_flight_number', 'icao_full', 'icaoFull'
    ])));
    $airlineIcao = strtoupper(trim(first_non_empty($air, [
        'icao', 'icaoCode', 'icao_code', 'icaoNumber', 'icao_number', 'icaoPrefix', 'icao_prefix'
    ])));
    if ($airlineIcao === '') {
        $airlineIcao = '';
    }
    if ($flightIata === '' && $flightIcao === '') {
        $skippedNoFlight++;
        continue;
    }

    $flightNumber = $flightIata !== '' ? $flightIata : $flightIcao;
    if ($flightIcao === '' && $airlineIcao !== '') {
        $numSuffix = extract_numeric_suffix([
            $flt['number'] ?? null,
            $flt['iata'] ?? null,
            $flt['iataNumber'] ?? null,
            $flightNumber,
        ]);
        if ($numSuffix !== '') {
            $flightIcao = $airlineIcao . $numSuffix;
        }
    }
    // Algunas respuestas traen el callsign en un campo directo
    if ($flightIcao === '') {
        $directCallsign = strtoupper(trim(first_non_empty($row, ['callsign', 'callSign', 'flight_call_sign', 'flight_callsign'])));
        if ($directCallsign !== '') {
            $flightIcao = $directCallsign;
        }
    }
    if ($flightIcao !== '') {
        $flightIcao = preg_replace('/[^A-Z0-9]/', '', $flightIcao);
    }
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

    $include = ($staUtcDt->setTimezone($tzFetch)->format('Y-m-d') === $targetDate);
    if (!$include && $etaUtcDt) {
        $include = ($etaUtcDt->setTimezone($tzFetch)->format('Y-m-d') === $targetDate);
    }
    if (!$include && $ataUtcDt) {
        $include = ($ataUtcDt->setTimezone($tzFetch)->format('Y-m-d') === $targetDate);
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
        sigma_stderr("[update_schedule] insert error for {$flightNumber}: " . $ins->error . "\n");
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
    '[update_schedule] airport=%s tz=%s dates=%s (today_utc=%s) total_api=%d inserted=%d updated=%d skipped_codeshare=%d skipped_no_sta=%d skipped_no_flight=%d skipped_range=%d errors=%s',
    $iata,
    $tzFetch->getName(),
    implode(',', $datesToFetch),
    $todayFetch,
    $totalRows,
    $inserted,
    $updated,
    $skippedCodeshare,
    $skippedNoSta,
    $skippedNoFlight,
    $skippedOutOfRange,
    $fetchErrors ? implode(';', $fetchErrors) : 'none'
);

sigma_stdout($summary . "\n");
