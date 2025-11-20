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
require_once __DIR__ . '/lib/timetable_helpers.php';

/**
 * Parse string a DateTimeImmutable en UTC. Devuelve null si el valor es vacío
 * o no se puede interpretar.
 */
function get_timezone(string $name, DateTimeZone $fallback): DateTimeZone {
    try {
        return new DateTimeZone($name);
    } catch (Throwable $e) {
        return $fallback;
    }
}

function parse_time_with_timezone(?string $value, DateTimeZone $assumedTz, DateTimeZone $tzUtc): ?DateTimeImmutable {
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
            $dt = new DateTimeImmutable($value, $assumedTz);
        }
        return $dt->setTimezone($tzUtc);
    } catch (Throwable $e) {
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return (new DateTimeImmutable('@' . $ts))->setTimezone($tzUtc);
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
        'active'     => 'active',
        'airborne'   => 'en-route',
        'enroute'    => 'en-route',
        'en-route'   => 'en-route',
        'landed'     => 'landed',
        'arrived'    => 'landed',
        'diverted'   => 'diverted',
        'redirected' => 'diverted',
        'alternate'  => 'diverted',
        'cancelled'  => 'cancelled',
        'canceled'   => 'cancelled',
        'cncl'       => 'cancelled',
        'cancld'     => 'cancelled',
        'delayed'    => 'delayed',
        'delay'      => 'delayed',
        'taxi'       => 'taxi',
        'scheduled'  => 'scheduled',
        'incident'   => 'incident',
        'unknown'    => 'unknown',
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

function build_flight_codes(array $flight, array $airline): array {
    $out = ['flight_iata' => '', 'flight_icao' => '', 'number' => ''];
    $number = extract_numeric_suffix([
        $flight['number'] ?? null,
        $flight['iataNumber'] ?? null,
        $flight['iata_number'] ?? null,
        $flight['icaoNumber'] ?? null,
        $flight['icao_number'] ?? null,
    ]);
    $out['number'] = $number;

    $airIata = strtoupper(trim((string)($airline['iata'] ?? $airline['iataCode'] ?? '')));
    $airIcao = strtoupper(trim((string)($airline['icao'] ?? $airline['icaoCode'] ?? '')));

    $fltIata = strtoupper(trim((string)($flight['iata'] ?? $flight['iataNumber'] ?? '')));
    $fltIcao = strtoupper(trim((string)($flight['icao'] ?? $flight['icaoNumber'] ?? '')));

    if ($fltIata === '' && $airIata !== '' && $number !== '') {
        $fltIata = $airIata . $number;
    }
    if ($fltIcao === '' && $airIcao !== '' && $number !== '') {
        $fltIcao = $airIcao . $number;
    }

    $out['flight_iata'] = $fltIata;
    $out['flight_icao'] = $fltIcao;
    return $out;
}

function leg_key(array $row): string {
    $flightCode = timetable_normalize_code($row['callsign'] ?? $row['flight_number'] ?? $row['flight_icao'] ?? '');
    if ($flightCode === '' && !empty($row['operating_code'])) {
        $flightCode = timetable_normalize_code($row['operating_code']);
    }
    $timeKey = (string)($row['sta_utc'] ?? $row['eta_utc'] ?? $row['std_utc'] ?? '');
    $dep = timetable_normalize_code($row['dep_icao'] ?? $row['dep_iata'] ?? '');
    return $flightCode . '|' . $timeKey . '|' . $dep;
}

function status_priority(string $status): int {
    $map = [
        'landed'   => 6,
        'diverted' => 5,
        'incident' => 4,
        'en-route' => 3,
        'active'   => 3,
        'taxi'     => 2,
        'delayed'  => 1,
        'scheduled'=> 1,
        'cancelled'=> 0,
        'unknown'  => 0,
    ];
    return $map[strtolower($status)] ?? 0;
}

function merge_rows_with_codeshares(array $rows, int &$mergedCodeshares): array {
    $mergedCodeshares = 0;
    $out = [];
    foreach ($rows as $row) {
        $key = leg_key($row);
        if ($key === '||' || $key === '|') {
            $key = md5(json_encode($row));
        }
        if (!isset($out[$key])) {
            $out[$key] = $row;
            continue;
        }
        $current = $out[$key];
        $existingCodeshares = $current['codeshares'] ?? [];
        $incomingCodeshares = $row['codeshares'] ?? [];
        $before = count($existingCodeshares);
        $current['codeshares'] = array_values(array_unique(array_merge($existingCodeshares, $incomingCodeshares)));
        $mergedCodeshares += max(0, count($current['codeshares']) - $before);

        $scoreCurrent = status_priority($current['status'] ?? '');
        $scoreRow = status_priority($row['status'] ?? '');
        if (!empty($row['ac_reg'])) {
            $scoreRow += 1;
        }
        if (!empty($current['ac_reg'])) {
            $scoreCurrent += 1;
        }
        if (!empty($row['is_codeshare'])) {
            $scoreRow -= 1;
        }
        if (!empty($current['is_codeshare'])) {
            $scoreCurrent -= 1;
        }

        if ($scoreRow > $scoreCurrent) {
            $row['codeshares'] = $current['codeshares'];
            $current = $row;
        } else {
            foreach (['std_utc','eta_utc','ata_utc','sta_utc','delay_min','ac_reg','ac_type','airline','flight_number','callsign'] as $field) {
                if ((empty($current[$field]) || $current[$field] === null) && (!empty($row[$field]))) {
                    $current[$field] = $row[$field];
                }
            }
        }
        $out[$key] = $current;
    }
    return array_values($out);
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
        'arr_iata'    => $airportIata,
        'arr_icao'    => $airportIcao,
        'flight_date' => $targetDate,
    ];

    $statusFilter = 'scheduled,active,landed,diverted,cancelled';
    $useStatusFilter = true;

    $limit = 100;
    $offset = 0;
    $allRows = [];
    $page = 0;
    $count = 0;

    do {
        $params = $baseParams + ['limit' => $limit, 'offset' => $offset];
        if ($useStatusFilter) {
            $params['flight_status'] = $statusFilter;
        }

        $res = avs_get($endpoint, $params, $ttl);
        if (!($res['ok'] ?? false)) {
            $is400 = ($res['error'] ?? '') === 'avs_http_400';
            if ($is400 && $useStatusFilter) {
                // Algunos planes de AviationStack regresan 400 si se envía
                // `flight_status` con valores múltiples. Reintentamos sin
                // el filtro para no abortar el cron.
                $useStatusFilter = false;
                $offset = 0;
                $allRows = [];
                $page = 0;
                $count = $limit; // evita warning en la condición del while
                continue;
            }

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

    return ['ok' => true, 'rows' => $allRows, 'endpoint' => $endpoint, 'status_filter' => $useStatusFilter];
}

function normalize_avs_row(array $row, string $source, DateTimeZone $tzLocal, DateTimeZone $tzUtc, string $airportIata, string $airportIcao): ?array {
    $codesharedRaw = is_array($row['codeshared'] ?? null) ? $row['codeshared'] : null;

    $dep = is_array($row['departure'] ?? null) ? $row['departure'] : [];
    $arr = is_array($row['arrival'] ?? null) ? $row['arrival'] : [];
    $air = is_array($row['airline'] ?? null) ? $row['airline'] : [];
    $flt = is_array($row['flight'] ?? null) ? $row['flight'] : [];

    $marketingCode = '';
    if ($codesharedRaw) {
        $marketingCode = strtoupper(trim((string)($flt['iata'] ?? $flt['iataNumber'] ?? $flt['icao'] ?? $flt['icaoNumber'] ?? '')));
        $air = is_array($codesharedRaw['airline'] ?? null) ? $codesharedRaw['airline'] : $air;
        $flt = is_array($codesharedRaw['flight'] ?? null) ? $codesharedRaw['flight'] : $codesharedRaw;
    }

    $codes = build_flight_codes($flt, $air);
    $flightIata = strtoupper(trim($codes['flight_iata']));
    $flightIcao = strtoupper(trim($codes['flight_icao']));
    $flightNumber = $flightIata !== '' ? $flightIata : ($codes['number'] ?? '');
    $callsign = $flightIcao !== '' ? $flightIcao : null;

    $airlineName = null;
    if (isset($air['name']) && trim((string)$air['name']) !== '') {
        $airlineName = trim((string)$air['name']);
    }

    $ac = is_array($row['aircraft'] ?? null) ? $row['aircraft'] : [];
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

    $arrTzName = $arr['timezone'] ?? $tzLocal->getName();
    $arrTz = get_timezone($arrTzName, $tzLocal);
    $depTzName = $dep['timezone'] ?? (
        (($dep['iata'] ?? '') === $airportIata || ($dep['icao'] ?? '') === $airportIcao)
            ? $tzLocal->getName()
            : ($arr['timezone'] ?? $tzLocal->getName())
    );
    $depTz = get_timezone($depTzName, $tzLocal);

    $staUtcDt = parse_time_with_timezone($arr['scheduled'] ?? $arr['scheduledTime'] ?? $arr['scheduled_time'] ?? null, $arrTz, $tzUtc);
    $etaUtcDt = parse_time_with_timezone($arr['estimated'] ?? $arr['estimatedTime'] ?? $arr['estimated_runway'] ?? null, $arrTz, $tzUtc);
    $ataUtcDt = parse_time_with_timezone($arr['actual'] ?? $arr['actualTime'] ?? $arr['actual_runway'] ?? null, $arrTz, $tzUtc);
    if (!$staUtcDt && $etaUtcDt) {
        $staUtcDt = $etaUtcDt; // fallback to ETA para mantener el vuelo en la ventana del día.
    }

    $stdUtcDt = parse_time_with_timezone($dep['scheduled'] ?? $dep['scheduledTime'] ?? $dep['scheduled_time'] ?? null, $depTz, $tzUtc);
    $etdUtcDt = parse_time_with_timezone($dep['estimated'] ?? $dep['estimatedTime'] ?? $dep['estimated_runway'] ?? null, $depTz, $tzUtc);
    $atdUtcDt = parse_time_with_timezone($dep['actual'] ?? $dep['actualTime'] ?? $dep['actual_runway'] ?? null, $depTz, $tzUtc);
    $staUtc = $staUtcDt ? $staUtcDt->format('Y-m-d H:i:s') : null;
    $etaUtc = $etaUtcDt ? $etaUtcDt->format('Y-m-d H:i:s') : null;
    $ataUtc = $ataUtcDt ? $ataUtcDt->format('Y-m-d H:i:s') : null;
    $stdUtc = $stdUtcDt ? $stdUtcDt->format('Y-m-d H:i:s') : null;

    if ($staUtc === null && $etaUtc === null && $ataUtc === null) {
        return null;
    }

    $depCode = pick_code($dep, $airportIata);
    $arrCode = pick_code($arr, $airportIata);
    if ($arrCode !== $airportIata && $arrCode !== $airportIcao) {
        $arrCode = $airportIata;
    }

    $delayMin = 0;
    if (isset($arr['delay']) && is_numeric($arr['delay'])) {
        $delayMin = (int)$arr['delay'];
    } elseif ($etaUtcDt && $staUtcDt) {
        $delayMin = (int)round(($etaUtcDt->getTimestamp() - $staUtcDt->getTimestamp()) / 60);
    } elseif ($ataUtcDt && $staUtcDt) {
        $delayMin = (int)round(($ataUtcDt->getTimestamp() - $staUtcDt->getTimestamp()) / 60);
    }

    $statusOut = normalize_status((string)($row['flight_status'] ?? $row['status'] ?? 'scheduled'));
    if (in_array($statusOut, ['active', 'en-route'], true) && !$etaUtcDt) {
        $statusOut = 'taxi';
    }

    $codeshares = [];
    if ($marketingCode !== '') {
        $codeshares[] = $marketingCode;
    }

    return [
        'flight_number' => $flightNumber !== '' ? $flightNumber : null,
        'callsign'      => $callsign,
        'flight_icao'   => $flightIcao,
        'flight_iata'   => $flightIata,
        'operating_code'=> $flightIcao ?: $flightIata,
        'airline'       => $airlineName,
        'ac_reg'        => $acReg,
        'ac_type'       => $acType,
        'dep_icao'      => strtoupper($depCode),
        'dst_icao'      => strtoupper($arrCode),
        'std_utc'       => $stdUtc,
        'etd_utc'       => $etdUtcDt ? $etdUtcDt->format('Y-m-d H:i:s') : null,
        'atd_utc'       => $atdUtcDt ? $atdUtcDt->format('Y-m-d H:i:s') : null,
        'sta_utc'       => $staUtc,
        'eta_utc'       => $etaUtc,
        'ata_utc'       => $ataUtc,
        'delay_min'     => $delayMin,
        'status'        => $statusOut,
        'codeshares'    => $codeshares,
        'is_codeshare'  => (bool)$codesharedRaw,
        'source'        => $source,
    ];
}

$cfg = cfg();
$iata = strtoupper((string)($cfg['IATA'] ?? 'TIJ'));
$icao = strtoupper((string)($cfg['ICAO'] ?? 'MMTJ'));
$tzLocal = get_timezone($cfg['timezone'] ?? 'America/Tijuana', new DateTimeZone('America/Tijuana'));
$tzUtc = new DateTimeZone('UTC');

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
    $date = (new DateTimeImmutable('now', $tzLocal))->format('Y-m-d');
}

$rangeDays = 2;
$forceSingleDay = false;
$dryRun = false;
foreach ($cliArgs as $arg) {
    if (preg_match('/^--days=(\d{1,2})$/', $arg, $m)) {
        $rangeDays = max(1, min(5, (int)$m[1]));
    }
    if ($arg === '--single-day') {
        $forceSingleDay = true;
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}
if ($forceSingleDay) {
    $rangeDays = 1;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    sigma_stderr("[update_schedule] invalid date format: $date\n");
    exit(1);
}

$todayFetch = (new DateTimeImmutable('now', $tzLocal))->format('Y-m-d');
if ($date > $todayFetch) {
    $date = $todayFetch;
}

try {
    $anchor = new DateTimeImmutable($date . ' 00:00:00', $tzLocal);
} catch (Throwable $e) {
    sigma_stderr("[update_schedule] unable to build local start for $date: {$e->getMessage()}\n");
    exit(1);
}

$datesToFetch = [];
for ($i = 0; $i < $rangeDays; $i++) {
    $cursor = $anchor->modify('+' . $i . ' day');
    $cursorStr = $cursor->format('Y-m-d');
    $datesToFetch[$cursorStr] = true;
}
$datesToFetch = array_values(array_unique($datesToFetch));

$ttl = (isset($_GET['nocache']) || in_array('--nocache', $cliArgs, true)) ? 0 : 900;

$rawRows = [];
$fetchErrors = [];
$fetchedPerDate = [];
$fetchedTimetable = 0;
$effectiveDate = $datesToFetch[0] ?? $date;

$ttRes = avs_get('timetable', [
    'iataCode' => $iata,
    'type'     => 'arrival',
    'date'     => $effectiveDate,
], $ttl);
if ($ttRes['ok'] ?? false) {
    $ttData = is_array($ttRes['data'] ?? null) ? $ttRes['data'] : [];
    $fetchedTimetable = count($ttData);
    foreach ($ttData as $row) {
        $rawRows[] = ['timetable', $effectiveDate, $row];
    }
} else {
    $fetchErrors[] = sprintf('timetable err=%s', $ttRes['error'] ?? 'unknown');
}

foreach ($datesToFetch as $cursorDate) {
    $cursorRes = avs_fetch_day($iata, $icao, $cursorDate, $ttl);
    if (!($cursorRes['ok'] ?? false)) {
        $fetchErrors[] = sprintf('flights date=%s err=%s', $cursorDate, $cursorRes['error'] ?? 'unknown');
        continue;
    }
    $countCursor = isset($cursorRes['rows']) && is_array($cursorRes['rows']) ? count($cursorRes['rows']) : 0;
    $fetchedPerDate[$cursorDate] = $countCursor;
    foreach ($cursorRes['rows'] as $row) {
        $rawRows[] = ['flights', $cursorDate, $row];
    }
}

if (!$rawRows) {
    $perDateMsg = $fetchedPerDate ? implode(',', array_map(fn($d,$c)=>"$d:$c", array_keys($fetchedPerDate), $fetchedPerDate)) : 'none';
    $errMsg = $fetchErrors ? implode(';', $fetchErrors) : 'none';
    sigma_stderr("[update_schedule] no data fetched for " . implode(',', $datesToFetch) . " per_date=" . $perDateMsg . " errors=" . $errMsg . "\n");
    exit(2);
}

$totalApiRows = count($rawRows);
if ($dryRun) {
    $perDateMsg = $fetchedPerDate ? implode(',', array_map(fn($d,$c)=>"$d:$c", array_keys($fetchedPerDate), $fetchedPerDate)) : 'none';
    $summary = sprintf(
        '[update_schedule] dry-run airport=%s tz=%s dates=%s fetched_api=%d (timetable=%d flights=%s) per_date=%s errors=%s',
        $iata,
        $tzLocal->getName(),
        implode(',', $datesToFetch),
        $totalApiRows,
        $fetchedTimetable,
        array_sum($fetchedPerDate),
        $perDateMsg,
        $fetchErrors ? implode(';', $fetchErrors) : 'none'
    );
    sigma_stdout($summary . "\n");
    exit(0);
}

$normalizedRows = [];
$skippedNoSta = 0;
$skippedNoFlight = 0;
foreach ($rawRows as [$source, $targetDate, $row]) {
    $norm = normalize_avs_row($row, $source, $tzLocal, $tzUtc, $iata, $icao);
    if (!$norm) {
        $skippedNoSta++;
        continue;
    }
    if (($norm['flight_number'] ?? null) === null && ($norm['callsign'] ?? null) === null) {
        $skippedNoFlight++;
        continue;
    }
    $normalizedRows[] = $norm;
}

$codesharesMerged = 0;
$dedupedRows = merge_rows_with_codeshares($normalizedRows, $codesharesMerged);
$skippedOutOfRange = 0;

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
  status,
  codeshares_json
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
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
  status     = VALUES(status),
  codeshares_json = VALUES(codeshares_json)
SQL;

$ins = $db->prepare($sql);
if (!$ins) {
    sigma_stderr("[update_schedule] DB prepare error: " . $db->error . "\n");
    exit(3);
}

$flightNumber = $callsign = $airlineName = $acReg = $acType = $depCode = $arrCode = $stdUtc = $staUtc = $statusOut = $codesharesJson = null;
$delayMin = 0;
$ins->bind_param('sssssssssiss', $flightNumber, $callsign, $airlineName, $acReg, $acType, $depCode, $arrCode, $stdUtc, $staUtc, $delayMin, $statusOut, $codesharesJson);

$totalPersisted = 0;
$inserted = 0;
$updated = 0;
$skippedCodeshare = $codesharesMerged;

foreach ($dedupedRows as $row) {
    $flightNumber = $row['flight_number'] ?? null;
    $callsign = $row['callsign'] ?? null;
    $airlineName = $row['airline'] ?? null;
    $acReg = $row['ac_reg'] ?? null;
    $acType = $row['ac_type'] ?? null;
    $depCode = $row['dep_icao'] ?? null;
    $arrCode = $row['dst_icao'] ?? null;
    $stdUtc = $row['std_utc'] ?? null;
    $staUtc = $row['sta_utc'] ?? ($row['eta_utc'] ?? null);
    $delayMin = isset($row['delay_min']) ? (int)$row['delay_min'] : 0;
    $statusOut = $row['status'] ?? 'scheduled';
    $codesharesJson = !empty($row['codeshares']) ? json_encode(array_values(array_unique($row['codeshares']))) : null;

    if (!$staUtc) {
        $skippedNoSta++;
        continue;
    }

    if (!$ins->execute()) {
        sigma_stderr("[update_schedule] insert error for {$flightNumber}: " . $ins->error . "\n");
        continue;
    }
    $totalPersisted++;
    $aff = $ins->affected_rows;
    if ($aff === 1) {
        $inserted++;
    } elseif ($aff === 2) {
        $updated++;
    }
}

/*
 * SIGMA_TIMETABLE_CHECKLIST
 * 1) Time-range logic:
 *    - [ ] Confirmed timetable no longer discards valid same-day flights with “skipped_range”.
 *    - [ ] Confirmed full-day coverage for TIJ for a given local date.
 * 2) Local vs UTC:
 *    - [ ] Confirmed arrival and departure times are interpreted in the correct timezones.
 *    - [ ] Confirmed DB stores times consistently in UTC (or documented internal standard).
 *    - [ ] Confirmed frontend displays correct local times for TIJ.
 * 3) Codeshares:
 *    - [ ] Confirmed deduplication of AM180/LA7588/WS5785 and similar examples.
 *    - [ ] Confirmed only one row per physical leg in the timetable UI.
 *    - [ ] Confirmed marketing codeshares are preserved in a codeshares field or equivalent.
 * 4) Statuses:
 *    - [ ] Confirmed mapping for all statuses: landed, scheduled, cancelled, active, incident, diverted, redirected, unknown.
 *    - [ ] Confirmed frontend shows badges and filters for these statuses correctly.
 * 5) Midnight behavior:
 *    - [ ] Confirmed no “zero flights” gap around local midnight.
 *    - [ ] Confirmed the system automatically switches to the new local date when TIJ crosses 00:00.
 * 6) TAF panel:
 *    - [ ] Confirmed TAF card uses full vertical space as intended.
 *    - [ ] Confirmed TAF text wrapping and readability on desktop and mobile.
 * 7) Regression:
 *    - [ ] Confirmed no existing working features were removed (filters, sorting, FR24 enrichment, etc.).
 *    - [ ] Confirmed no PHP warnings/notices are generated in normal operation.
 */

$summary = sprintf(
    '[update_schedule] airport=%s tz=%s dates=%s (today_anchor=%s) total_api=%d persisted=%d inserted=%d updated=%d merged_codeshares=%d skipped_no_sta=%d skipped_no_flight=%d skipped_range=%d timetable=%d flights=%s errors=%s',
    $iata,
    $tzLocal->getName(),
    implode(',', $datesToFetch),
    $todayFetch,
    $totalApiRows,
    $totalPersisted,
    $inserted,
    $updated,
    $skippedCodeshare,
    $skippedNoSta,
    $skippedNoFlight,
    $skippedOutOfRange,
    $fetchedTimetable,
    array_sum($fetchedPerDate),
    $fetchErrors ? implode(';', $fetchErrors) : 'none'
);

$payload = [
    'ok' => true,
    'summary' => $summary,
    'stats' => [
        'airport' => $iata,
        'tz' => $tzLocal->getName(),
        'dates' => $datesToFetch,
        'today_anchor' => $todayFetch,
        'fetched' => $totalApiRows,
        'persisted' => $totalPersisted,
        'inserted' => $inserted,
        'updated' => $updated,
        'merged_codeshares' => $skippedCodeshare,
        'skipped_no_sta' => $skippedNoSta,
        'skipped_no_flight' => $skippedNoFlight,
        'skipped_out_of_range' => $skippedOutOfRange,
        'timetable_count' => $fetchedTimetable,
        'per_date' => $fetchedPerDate,
        'errors' => $fetchErrors,
    ],
];

if (PHP_SAPI === 'cli') {
    sigma_stdout($summary . "\n");
} else {
    json_response($payload);
}
