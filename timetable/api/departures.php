<?php
declare(strict_types=1);

/**
 * Departures API
 *
 * This endpoint returns a consolidated list of departures for a set of
 * origin airports within a given time window.  It combines scheduled
 * departure information from the local SQL database (populated by
 * update_departures.php) with live and historical flight data from
 * Flightradar24.  Additionally it fetches METAR and TAF weather
 * forecasts for each destination airport at the time of departure and
 * classifies them into the standard FAA categories (VFR/MVFR/IFR/LIFR).
 * The returned rows contain the following fields:
 *   flight_iata   Flight code (e.g. VOI123)
 *   airline_name  Airline name (may be null)
 *   dep_iata      Departure airport IATA code
 *   arr_iata      Scheduled destination IATA code
 *   std_utc       Scheduled time of departure in ISO8601
 *   eta_utc       Estimated/actual time of departure in ISO8601
 *   atd_utc       Actual time of departure in ISO8601 (if available)
 *   sta_utc       Scheduled time of arrival (if known)
 *   eet_min       Estimated en‑route time in minutes
 *   status        One of: scheduled, active, landed, cancelled, diverted
 *   delay_min     Delay of departure in minutes (etd_std difference)
 *   wx_metar_cat  Weather category at destination at takeoff (VFR/MVFR/IFR/LIFR)
 *   wx_metar_raw  Raw METAR string
 *   wx_taf_cat    Weather category from TAF at ETA (VFR/MVFR/IFR/LIFR)
 *   wx_taf_raw    Raw TAF string
 *
 * Accepted GET parameters:
 *   dep_iata   Comma‑separated list of origin airport IATA codes.  If
 *              omitted, defaults to TIJ,MXL,PPE,HMO,GYM.
 *   start      Start of the time window (ISO8601).  Date portion is
 *              used for schedule; defaults to current UTC date at
 *              00:00Z.
 *   hours      Number of hours from start to consider; used for FR24
 *              query window (max 168).  Defaults to 24.
 *
 * This script should be called from the front‑end when the user
 * selects the "Salidas" mode.  It does not apply any status filter.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';

// Pull in METAR/TAF helpers from the fog module.  These functions
// allow us to fetch observations and forecasts from the Aviation
// Weather Center (AWC) and CAPMA.  The directory name may vary between
// deployments (plain `mmtj_fog` or an `_unzip` suffix), so we search
// the common locations and stop at the first match.
$fogLib = null;
$fogCandidates = [
  dirname(__DIR__, 2) . '/mmtj_fog/lib',
  dirname(__DIR__, 3) . '/mmtj_fog/lib',
  dirname(__DIR__, 2) . '/mmtj_fog_unzip/lib',
  dirname(__DIR__, 3) . '/mmtj_fog_unzip/lib',
];
foreach ($fogCandidates as $candidate) {
  $metarLib = rtrim($candidate, '/') . '/metar_multi.php';
  if (is_file($metarLib)) {
    $fogLib = rtrim($candidate, '/');
    break;
  }
}
if ($fogLib === null) {
  error_log('[departures] mmtj_fog library not found; checked: ' . implode(', ', $fogCandidates));
  http_response_code(200);
  echo json_encode([
    'ok' => false,
    'error' => 'fog_lib_missing',
    'message' => 'No se encontró la librería meteorológica (mmtj_fog). Verifica la ruta en el servidor.',
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
require_once $fogLib . '/metar_multi.php';
require_once $fogLib . '/metar_awc.php';
require_once $fogLib . '/capma.php';

if (!defined('WX_CACHE_DIR')) {
  define('WX_CACHE_DIR', __DIR__ . '/cache/wx');
}
if (!is_dir(WX_CACHE_DIR)) {
  @mkdir(WX_CACHE_DIR, 0775, true);
}
if (!defined('WX_SNAPSHOT_DIR')) {
  define('WX_SNAPSHOT_DIR', __DIR__ . '/cache/wx_snapshots');
}
if (!is_dir(WX_SNAPSHOT_DIR)) {
  @mkdir(WX_SNAPSHOT_DIR, 0775, true);
}
if (!defined('WX_BACKOFF_FILE')) {
  define('WX_BACKOFF_FILE', __DIR__ . '/cache/wx_backoff.json');
}

// Helper: respond with JSON and exit
function jexit(array $arr, int $code = 200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Helper: parse ISO8601 string to timestamp; return current time on failure
function parse_iso(string $iso): int {
  $ts = strtotime($iso);
  return $ts === false ? time() : $ts;
}

function wx_cache_path(string $icao, string $type): string {
  $icao = strtoupper($icao);
  $safe = preg_replace('/[^A-Z0-9]/', '_', $icao);
  return WX_CACHE_DIR . '/' . strtolower($type) . '_' . $safe . '.json';
}

function wx_cache_get(string $icao, string $type, int $ttl_seconds) {
  $fn = wx_cache_path($icao, $type);
  if (!is_file($fn)) return null;
  $txt = @file_get_contents($fn);
  if ($txt === false) return null;
  $payload = json_decode($txt, true);
  if (!is_array($payload) || !isset($payload['ts'])) return null;
  if ((time() - (int)$payload['ts']) > $ttl_seconds) return null;
  return $payload['data'] ?? null;
}

function wx_cache_put(string $icao, string $type, $data): void {
  $fn = wx_cache_path($icao, $type);
  $payload = ['ts' => time(), 'data' => $data];
  @file_put_contents($fn, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function awc_should_backoff(): bool {
  if (!is_file(WX_BACKOFF_FILE)) return false;
  $txt = @file_get_contents(WX_BACKOFF_FILE);
  if ($txt === false) return false;
  $data = json_decode($txt, true);
  if (!is_array($data) || empty($data['until'])) return false;
  return time() < (int)$data['until'];
}

function awc_register_failure(int $seconds = 120): void {
  $payload = ['until' => time() + max(30, $seconds)];
  @file_put_contents(WX_BACKOFF_FILE, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function awc_clear_backoff(): void {
  if (is_file(WX_BACKOFF_FILE)) {
    @unlink(WX_BACKOFF_FILE);
  }
}

function load_capma_metar_record(string $icao, array &$errors): ?array {
  $icao = strtoupper($icao);
  $cached = wx_cache_get($icao, 'capma_metar', 300);
  if ($cached !== null) return $cached;
  $raw = capma_get_metar($icao);
  if ($raw === '') return null;
  $metrics = capma_parse_metar_metrics($raw);
  $record = [
    'icao' => $icao,
    'raw' => $raw,
    'vis_sm' => $metrics['vis_sm'],
    'ceil_ft' => $metrics['ceil_ft'],
    'vv_ft' => $metrics['vv_ft'],
    'source' => 'CAPMA',
  ];
  wx_cache_put($icao, 'capma_metar', $record);
  return $record;
}

function load_capma_taf(string $icao): ?array {
  $icao = strtoupper($icao);
  $cached = wx_cache_get($icao, 'capma_taf', 1800);
  if ($cached !== null) {
    $raw = is_array($cached) ? ($cached['raw'] ?? '') : (string)$cached;
    return $raw !== '' ? ['raw' => $raw, 'source' => 'CAPMA'] : null;
  }
  $raw = capma_get_taf($icao);
  if ($raw === '') return null;
  wx_cache_put($icao, 'capma_taf', $raw);
  return ['raw' => $raw, 'source' => 'CAPMA'];
}

function load_awc_metars(array $icaos, array &$errors): array {
  $out = [];
  $pending = [];
  foreach ($icaos as $icao) {
    $icaoUp = strtoupper($icao);
    $cached = wx_cache_get($icaoUp, 'awc_metar', 300);
    if ($cached !== null) {
      $out[$icaoUp] = $cached;
    } else {
      $pending[] = $icaoUp;
    }
  }
  if (!empty($pending)) {
    if (awc_should_backoff()) {
      $errors[] = 'metar:backoff';
    } else {
      try {
        $fetched = fetch_awc_multi($pending);
        if (is_array($fetched) && !empty($fetched)) {
          awc_clear_backoff();
        }
        foreach ($fetched as $icaoKey => $rec) {
          $icaoUp = strtoupper((string)$icaoKey);
          $norm = [
            'icao' => $icaoUp,
            'raw' => $rec['raw'] ?? ($rec['raw_text'] ?? ''),
            'vis_sm' => isset($rec['vis_sm']) && $rec['vis_sm'] > 0 ? (float)$rec['vis_sm'] : null,
            'ceil_ft' => isset($rec['ceil_ft']) && $rec['ceil_ft'] > 0 ? (int)$rec['ceil_ft'] : null,
            'vv_ft' => isset($rec['vv_ft']) && $rec['vv_ft'] > 0 ? (int)$rec['vv_ft'] : null,
            'source' => 'AWC',
          ];
          $out[$icaoUp] = $norm;
          wx_cache_put($icaoUp, 'awc_metar', $norm);
        }
      } catch (Throwable $e) {
        $errors[] = 'metar:' . $e->getMessage();
        awc_register_failure();
      }
    }
  }
  return $out;
}

function load_awc_taf(string $icao, array &$errors): ?array {
  $icao = strtoupper($icao);
  $cached = wx_cache_get($icao, 'awc_taf', 1800);
  if ($cached !== null) {
    $raw = is_array($cached) ? ($cached['raw'] ?? '') : (string)$cached;
    return $raw !== '' ? ['raw' => $raw, 'source' => 'NOAA'] : null;
  }
  try {
    $res = adds_taf($icao);
    if (is_array($res) && !empty($res['raw_text'])) {
      $raw = trim((string)$res['raw_text']);
      if ($raw !== '') {
        wx_cache_put($icao, 'awc_taf', $raw);
        awc_clear_backoff();
        return ['raw' => $raw, 'source' => 'NOAA'];
      }
    }
  } catch (Throwable $e) {
    $errors[] = 'taf:' . $e->getMessage();
    awc_register_failure();
    return null;
  }
  return null;
}

function gather_weather_records(array $icaos, array &$errors): array {
  $metar = [];
  $taf = [];
  $icaos = array_values(array_unique(array_map('strtoupper', $icaos)));
  $mexIcaos = array_filter($icaos, static fn($icao) => strncmp($icao, 'MM', 2) === 0);
  foreach ($mexIcaos as $icao) {
    $rec = load_capma_metar_record($icao, $errors);
    if ($rec) {
      $metar[$icao] = $rec;
    }
    $tafRec = load_capma_taf($icao);
    if ($tafRec) {
      $taf[$icao] = $tafRec;
    }
  }
  $remainingMetar = array_filter($icaos, static fn($icao) => !isset($metar[$icao]));
  if (!empty($remainingMetar)) {
    $awcMetars = load_awc_metars($remainingMetar, $errors);
    foreach ($awcMetars as $icao => $rec) {
      if ($rec) {
        $metar[$icao] = $rec;
      }
    }
  }
  $remainingTaf = array_filter($icaos, static fn($icao) => !isset($taf[$icao]));
  foreach ($remainingTaf as $icao) {
    $tafRec = load_awc_taf($icao, $errors);
    if ($tafRec) {
      $taf[$icao] = $tafRec;
    }
  }
  return ['metar' => $metar, 'taf' => $taf];
}

function snapshot_key(array $row): ?string {
  $flight = strtoupper((string)($row['flight_iata'] ?? ''));
  $std = $row['std_utc'] ?? null;
  if ($flight === '' || !$std) return null;
  return $flight . '|' . $std;
}

function snapshot_path(string $key): string {
  return WX_SNAPSHOT_DIR . '/' . sha1($key) . '.json';
}

function snapshot_load(string $key): ?array {
  $fn = snapshot_path($key);
  if (!is_file($fn)) return null;
  $txt = @file_get_contents($fn);
  if ($txt === false) return null;
  $payload = json_decode($txt, true);
  return is_array($payload) ? $payload : null;
}

function snapshot_store(string $key, array $payload): void {
  if (!isset($payload['ts'])) {
    $payload['ts'] = time();
  }
  @file_put_contents(snapshot_path($key), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// FR24 API wrapper
function call_fr24(string $endpoint, array $params, array &$errors): ?array {
  $url = FR24_API_BASE . $endpoint;
  $query = http_build_query($params);
  if ($query) $url .= '?' . $query;
  $headers = [
    'Authorization: Bearer ' . FR24_API_TOKEN,
    'Accept: application/json',
    'Accept-Version: ' . FR24_API_VERSION,
  ];
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    // Reduced timeout and connect timeout to mitigate server 502 errors.
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
  ]);
  $body = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($err) {
    $errors[] = 'fr24:' . $err;
    return null;
  }
  if (!$body) {
    $errors[] = 'fr24:empty';
    return null;
  }
  $j = json_decode($body, true);
  if (!is_array($j)) {
    $errors[] = 'fr24:json';
    return null;
  }
  return $j;
}

// Retrieve the ICAO code for a given IATA using the FR24 airports
// endpoint.  Caches results in a static array to avoid repeated API
// calls.
function get_airport_icao(string $iata, array &$errors): ?string {
  static $cache = [];
  $iata = strtoupper(trim($iata));
  if ($iata === '') return null;
  if (isset($cache[$iata])) return $cache[$iata];
  $resp = call_fr24('/airports/full', ['iata' => $iata], $errors);
  $icao = null;
  if ($resp && isset($resp['data']) && is_array($resp['data']) && count($resp['data']) > 0) {
    $icao = strtoupper((string)($resp['data'][0]['icao'] ?? ''));
    if ($icao === '') $icao = null;
  }
  $cache[$iata] = $icao;
  return $icao;
}

// Classify weather conditions from a METAR observation into FAA categories.
// Accepts the record returned by fetch_awc_multi() and returns one of
// VFR, MVFR, IFR, LIFR.  A null record yields 'VFR'.
function classify_metar(?array $rec): string {
  if (!$rec) return 'VFR';
  $vis = isset($rec['vis_sm']) ? (float)$rec['vis_sm'] : null;
  $ceil = isset($rec['ceil_ft']) ? (int)$rec['ceil_ft'] : null;
  $vv = isset($rec['vv_ft']) ? (int)$rec['vv_ft'] : null;
  if ($vis !== null && $vis <= 0) $vis = null;
  if ($ceil !== null && $ceil <= 0) $ceil = null;
  if ($vv !== null && $vv <= 0) $vv = null;
  if ($vv !== null && ($ceil === null || $vv < $ceil)) $ceil = $vv;
  // LIFR: ceiling < 500 ft or visibility < 1 SM
  if (($ceil !== null && $ceil < 500) || ($vis !== null && $vis < 1.0)) return 'LIFR';
  // IFR: ceiling 500–999 ft or visibility 1–2.999 SM
  if (($ceil !== null && $ceil < 1000) || ($vis !== null && $vis < 3.0)) return 'IFR';
  // MVFR: ceiling 1,000–2,999 ft or visibility 3–4.999 SM
  if (($ceil !== null && $ceil < 3000) || ($vis !== null && $vis < 5.0)) return 'MVFR';
  return 'VFR';
}

// Roughly classify weather from a TAF string.  We do not attempt to
// resolve time groups; instead we scan the entire TAF and derive the
// worst‑case conditions likely to affect the flight.  This yields a
// conservative category but avoids complex parsing.  Categories are
// identical to classify_metar().
function classify_taf(?string $taf): string {
  if (!$taf) return 'VFR';
  $text = strtoupper(trim(preg_replace('/\s+/', ' ', $taf)));
  $tokens = preg_split('/\s+/', $text);
  $minCeil = null;
  $minVis = null;
  foreach ($tokens as $tok) {
    // Ceiling: BKNnnn, OVCnnn, VVnnn
    if (preg_match('/^(BKN|OVC|VV)(\d{3})/', $tok, $m)) {
      $ft = (int)$m[2] * 100;
      if ($minCeil === null || $ft < $minCeil) $minCeil = $ft;
    }
    // Visibility in statute miles: nSM or n/nSM
    if (preg_match('/^(\d+)SM$/', $tok, $m)) {
      $v = (float)$m[1];
      if ($minVis === null || $v < $minVis) $minVis = $v;
      continue;
    }
    if (preg_match('/^(\d)\/(\d)SM$/', $tok, $m)) {
      $v = (float)$m[1] / (float)$m[2];
      if ($minVis === null || $v < $minVis) $minVis = $v;
      continue;
    }
    // Metric visibility: 4‑digit number = meters
    if (preg_match('/^(\d{4})$/', $tok, $m)) {
      $meters = (float)$m[1];
      $mi = $meters / 1609.34;
      if ($minVis === null || $mi < $minVis) $minVis = $mi;
      continue;
    }
    // Fog or mist indicators degrade visibility
    if (strpos($tok, 'FG') !== false || strpos($tok, 'BR') !== false) {
      // treat as IFR unless already LIFR
      if ($minVis === null || $minVis > 2.0) $minVis = 2.0;
    }
  }
  // Determine category
  if (($minCeil !== null && $minCeil < 500) || ($minVis !== null && $minVis < 1.0)) return 'LIFR';
  if (($minCeil !== null && $minCeil < 1000) || ($minVis !== null && $minVis < 3.0)) return 'IFR';
  if (($minCeil !== null && $minCeil < 3000) || ($minVis !== null && $minVis < 5.0)) return 'MVFR';
  return 'VFR';
}

// ===== Read request parameters =====
$depParam = isset($_GET['dep_iata']) ? trim((string)$_GET['dep_iata']) : '';
// If dep_iata provided, use its comma‑separated values; otherwise use
// default airports mandated by the directive: TIJ,MXL,PPE,HMO,GYM
$depList = $depParam !== '' ? preg_split('/\s*,\s*/', strtoupper($depParam)) : ['TIJ','MXL','PPE','HMO','GYM'];
$depList = array_filter(array_map('trim', $depList), fn($x) => $x !== '');
if (empty($depList)) $depList = ['TIJ'];

$start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;
if ($hours < 1 || $hours > 168) $hours = 24;

// Normalise start: if absent, use current UTC date at 00:00Z
function normalize_start(string $s): string {
  if ($s === '') {
    $d = new DateTime('now', new DateTimeZone('UTC'));
    $d->setTime(0, 0, 0);
    return $d->format('Y-m-d\TH:i:00\Z');
  }
  // ensure trailing Z
  $s = rtrim($s, 'Z');
  $ts = strtotime($s . 'Z');
  $d = $ts ? (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('UTC')) : new DateTime('now', new DateTimeZone('UTC'));
  return $d->format('Y-m-d\TH:i:00\Z');
}
$from_iso = normalize_start($start);
$from_ts = parse_iso($from_iso);
$to_ts = $from_ts + $hours * 3600;
$to_iso = gmdate('Y-m-d\TH:i:00\Z', $to_ts);

// Extract the UTC date portion for schedule filter
$schedule_date = gmdate('Y-m-d', $from_ts);

// === Retrieve scheduled departures from DB ===
$errors = [];
$scheduleRows = [];
try {
  $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
  if ($db->connect_errno) {
    $errors[] = 'db:' . $db->connect_error;
  } else {
    $db->set_charset(DB_CHARSET);
    // Build IN clause for dep_icao list.  IATA codes are stored in dep_icao
    // column (which may contain either IATA or ICAO).  We normalise
    // comparisons to uppercase.
    $placeholders = implode(',', array_fill(0, count($depList), '?'));
    $query = 'SELECT flight_number AS flight_iata, airline, dep_icao, dst_icao, std_utc, sta_utc, delay_min, status'
           . ' FROM flights WHERE UPPER(dep_icao) IN (' . $placeholders . ') AND DATE(std_utc) = ?';
    $stmt = $db->prepare($query);
    if ($stmt) {
      // Bind dynamic number of dep codes + date
      $types = str_repeat('s', count($depList) + 1);
      $params = array_merge($depList, [$schedule_date]);
      $stmt->bind_param($types, ...$params);
      if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
          // Normalise schedule times to ISO strings
          $stdIso = null;
          $staIso = null;
          if ($row['std_utc']) {
            $ts = strtotime($row['std_utc']);
            if ($ts) $stdIso = gmdate('c', $ts);
          }
          if ($row['sta_utc']) {
            $ts = strtotime($row['sta_utc']);
            if ($ts) $staIso = gmdate('c', $ts);
          }
          $scheduleRows[] = [
            'flight_iata' => strtoupper((string)($row['flight_iata'] ?? '')),
            'airline_name' => $row['airline'] ?? null,
            'dep_iata' => strtoupper((string)($row['dep_icao'] ?? '')),
            'arr_iata' => strtoupper((string)($row['dst_icao'] ?? '')),
            'std_utc' => $stdIso,
            'sta_utc' => $staIso,
            'delay_min' => is_numeric($row['delay_min']) ? (int)$row['delay_min'] : null,
            'status' => strtolower((string)($row['status'] ?? 'scheduled')),
          ];
        }
        $stmt->close();
      } else {
        $errors[] = 'db:exec';
      }
    } else {
      $errors[] = 'db:prepare';
    }
    $db->close();
  }
} catch (Exception $e) {
  $errors[] = 'db:' . $e->getMessage();
}

// === Retrieve flight summary for the given window ===
// Build airports filter: outbound list separated by commas
$fr24Airports = 'outbound:' . implode(',', $depList);
// Limit results to 2000 to avoid huge responses (the FR24 API limit is 20k)
// Fetch flight summaries for the time window.  Using a smaller limit
// reduces memory usage and mitigates server timeouts.  The FR24 API
// defaults to 100 if not specified; here we choose 2000 as a
// reasonable upper bound for multi‑airport windows.
$summaryData = call_fr24('/flight-summary/full', [
  'airports' => $fr24Airports,
  'flight_datetime_from' => gmdate('Y-m-d\TH:i:00', $from_ts),
  'flight_datetime_to' => gmdate('Y-m-d\TH:i:00', $to_ts),
  'limit' => 2000,
  'sort' => 'asc',
], $errors);

// Map summary flights by flight code and takeoff time
$summaryMap = [];
if ($summaryData && isset($summaryData['data']) && is_array($summaryData['data'])) {
  foreach ($summaryData['data'] as $fs) {
    $flight = isset($fs['flight']) ? strtoupper((string)$fs['flight']) : null;
    if (!$flight) continue;
    $takeoff = $fs['datetime_takeoff'] ?? null;
    if (!$takeoff) $takeoff = null;
    $takeoffIso = null;
    $takeoffTs = null;
    if ($takeoff) {
      $takeoffTs = strtotime($takeoff);
      if ($takeoffTs) $takeoffIso = gmdate('c', $takeoffTs);
    }
    $landedIso = null;
    if (!empty($fs['datetime_landed'])) {
      $lt = strtotime($fs['datetime_landed']);
      if ($lt) $landedIso = gmdate('c', $lt);
    }
    $flightTime = isset($fs['flight_time']) && is_numeric($fs['flight_time']) ? (int)$fs['flight_time'] : null;
    $destIata = strtoupper((string)($fs['dest_iata'] ?? ''));
    $destActual = strtoupper((string)($fs['dest_iata_actual'] ?? ''));
    $summaryMap[$flight][] = [
      'takeoff_iso' => $takeoffIso,
      'takeoff_ts' => $takeoffTs,
      'landed_iso' => $landedIso,
      'flight_time' => $flightTime,
      'dest_iata' => $destIata,
      'dest_actual' => $destActual,
    ];
  }
}

// === Retrieve live flight positions ===
$liveData = call_fr24('/live/flight-positions/full', ['airports' => $fr24Airports], $errors);
$liveMap = [];
if ($liveData && isset($liveData['data']) && is_array($liveData['data'])) {
  foreach ($liveData['data'] as $f) {
    $flight = isset($f['flight']) ? strtoupper((string)$f['flight']) : null;
    if (!$flight) $flight = isset($f['callsign']) ? strtoupper((string)$f['callsign']) : null;
    if (!$flight) continue;
    $dest = strtoupper((string)($f['dest_iata'] ?? $f['dest_icao'] ?? ''));
    $liveMap[$flight] = [
      'dest' => $dest,
    ];
  }
}

// === Combine schedule, summary, and live data ===
$final = [];
$now = time();
// We will collect a set of destination ICAOs to batch‑fetch METAR
$neededIcaos = [];
$iataToIcao = [];
foreach ($scheduleRows as $row) {
  $flightCode = $row['flight_iata'];
  $stdIso = $row['std_utc'];
  $stdTs = $stdIso ? strtotime($stdIso) : null;
  $staIso = $row['sta_utc'];
  $arrIata = $row['arr_iata'];
  $status = 'scheduled';
  $etaIso = null; // estimated/actual departure
  $ataIso = null; // actual departure
  $eetMin = null;
  $delayMin = $row['delay_min'] ?? null;
  $destIataActual = $arrIata;
  // Check if there is a summary entry for this flight
  $summaryEntries = $summaryMap[$flightCode] ?? [];
  // Find the summary entry closest to scheduled departure (abs diff)
  $best = null;
  if ($stdTs !== null && $summaryEntries) {
    $minDiff = null;
    foreach ($summaryEntries as $sum) {
      if ($sum['takeoff_ts']) {
        $diff = abs($sum['takeoff_ts'] - $stdTs);
        if ($minDiff === null || $diff < $minDiff) {
          $minDiff = $diff;
          $best = $sum;
        }
      }
    }
  }
  if (!$best && $summaryEntries) {
    $best = $summaryEntries[0];
  }
  if ($best) {
    $etaIso = $best['takeoff_iso'];
    $ataIso = $best['takeoff_iso'];
    $eetMin = $best['flight_time'] !== null ? (int)round($best['flight_time'] / 60) : null;
    // Determine status: landed if landed_iso exists; active otherwise
    if ($best['landed_iso']) {
      $status = 'landed';
    } else {
      $status = 'active';
    }
    // Determine diversion: compare scheduled and actual destination
    $destActual = $best['dest_actual'];
    $destPlanned = $best['dest_iata'] ?: $arrIata;
    if ($destActual && $destPlanned && $destActual !== $destPlanned) {
      $status = 'diverted';
      $destIataActual = $destActual;
    }
  } else {
    // No summary; if current time > scheduled + 1h, mark as cancelled
    if ($row['status'] === 'cancelled') {
      $status = 'cancelled';
    } else if ($stdTs !== null && $stdTs < $now - 3600) {
      // If one hour has passed since scheduled departure and flight
      // not seen, mark cancelled
      $status = 'cancelled';
    }
  }
  // Live flights may update status to active
  if (isset($liveMap[$flightCode])) {
    if ($status === 'scheduled') $status = 'active';
  }
  // Determine EET from schedule if not available from summary
  if ($eetMin === null && $stdIso && $staIso) {
    $sTs = strtotime($stdIso);
    $aTs = strtotime($staIso);
    if ($sTs && $aTs) {
      $eetMin = (int)round(($aTs - $sTs) / 60);
    }
  }
  // Determine delay of departure: difference between eta and scheduled
  if ($etaIso && $stdIso) {
    $etaTs = strtotime($etaIso);
    $stdTs2 = strtotime($stdIso);
    if ($etaTs && $stdTs2) {
      $delayMin = (int)round(($etaTs - $stdTs2) / 60);
    }
  }
  // Collect destination ICAO for weather, caching lookups per IATA
  $destIataUse = strtoupper($destIataActual ?: $arrIata);
  if (isset($iataToIcao[$destIataUse])) {
    $destIcao = $iataToIcao[$destIataUse];
  } else {
    $destIcao = get_airport_icao($destIataUse, $errors);
    $iataToIcao[$destIataUse] = $destIcao;
  }
  if ($destIcao) {
    $neededIcaos[strtoupper($destIcao)] = true;
  }
  $final[] = [
    'flight_iata' => $flightCode,
    'airline_name' => $row['airline_name'] ?? null,
    'dep_iata' => $row['dep_iata'],
    'arr_iata' => $arrIata,
    'std_utc' => $stdIso,
    'sta_utc' => $staIso,
    'eta_utc' => $etaIso,
    'atd_utc' => $ataIso,
    'eet_min' => $eetMin,
    'status' => $status,
    'delay_min' => $delayMin,
    // placeholders for weather, will fill later
    'wx_metar_cat' => null,
    'wx_metar_raw' => null,
    'wx_taf_cat' => null,
    'wx_taf_raw' => null,
    'wx_metar_src' => null,
    'wx_taf_src' => null,
    'wx_snapshot_ts' => null,
    'wx_metar_frozen' => false,
    'wx_taf_frozen' => false,
    // include actual destination to allow UI to display alternate
    'dest_iata_actual' => $destIataActual,
    'dest_icao' => $destIcao,
  ];
}

// === Fetch METAR/TAF packages with CAPMA-first strategy ===
$weatherPackages = ['metar' => [], 'taf' => []];
if (!empty($neededIcaos)) {
  $weatherPackages = gather_weather_records(array_keys($neededIcaos), $errors);
}
$metarData = $weatherPackages['metar'] ?? [];
$tafData = $weatherPackages['taf'] ?? [];

// === Populate weather classifications ===
foreach ($final as &$row) {
  $destIataUse = strtoupper($row['dest_iata_actual'] ?? $row['arr_iata'] ?? '');
  $destIcao = $row['dest_icao'] ?? null;
  if (!$destIcao && $destIataUse !== '') {
    if (isset($iataToIcao[$destIataUse])) {
      $destIcao = $iataToIcao[$destIataUse];
    } else {
      $destIcao = get_airport_icao($destIataUse, $errors);
      $iataToIcao[$destIataUse] = $destIcao;
    }
  }
  $icaoKey = $destIcao ? strtoupper($destIcao) : null;
  $metarRec = $icaoKey && isset($metarData[$icaoKey]) ? $metarData[$icaoKey] : null;
  $row['wx_metar_cat'] = classify_metar(is_array($metarRec) ? $metarRec : null);
  $row['wx_metar_raw'] = is_array($metarRec) ? ($metarRec['raw'] ?? null) : null;
  $row['wx_metar_src'] = is_array($metarRec) ? ($metarRec['source'] ?? null) : null;

  $tafRec = $icaoKey && isset($tafData[$icaoKey]) ? $tafData[$icaoKey] : null;
  $tafRaw = is_array($tafRec) ? ($tafRec['raw'] ?? null) : (is_string($tafRec) ? $tafRec : null);
  $row['wx_taf_cat'] = classify_taf($tafRaw);
  $row['wx_taf_raw'] = $tafRaw;
  $row['wx_taf_src'] = is_array($tafRec) ? ($tafRec['source'] ?? null) : null;

  $snapKey = snapshot_key($row);
  $snapshot = $snapKey ? snapshot_load($snapKey) : null;
  if ($snapshot) {
    if (!empty($snapshot['metar_cat'])) $row['wx_metar_cat'] = $snapshot['metar_cat'];
    if (!empty($snapshot['metar_raw'])) $row['wx_metar_raw'] = $snapshot['metar_raw'];
    if (!empty($snapshot['metar_src'])) $row['wx_metar_src'] = $snapshot['metar_src'];
    if (!empty($snapshot['taf_cat'])) $row['wx_taf_cat'] = $snapshot['taf_cat'];
    if (!empty($snapshot['taf_raw'])) $row['wx_taf_raw'] = $snapshot['taf_raw'];
    if (!empty($snapshot['taf_src'])) $row['wx_taf_src'] = $snapshot['taf_src'];
    $row['wx_snapshot_ts'] = isset($snapshot['ts']) ? gmdate('c', (int)$snapshot['ts']) : null;
    $row['wx_metar_frozen'] = true;
    $row['wx_taf_frozen'] = !empty($row['wx_taf_raw']);
  } else {
    $row['wx_snapshot_ts'] = null;
    $row['wx_metar_frozen'] = false;
    $row['wx_taf_frozen'] = false;
    if ($snapKey && in_array($row['status'], ['active', 'landed', 'diverted'], true) && !empty($row['wx_metar_raw'])) {
      $captureTs = time();
      snapshot_store($snapKey, [
        'ts' => $captureTs,
        'metar_raw' => $row['wx_metar_raw'],
        'metar_cat' => $row['wx_metar_cat'],
        'metar_src' => $row['wx_metar_src'],
        'taf_raw' => $row['wx_taf_raw'],
        'taf_cat' => $row['wx_taf_cat'],
        'taf_src' => $row['wx_taf_src'],
        'status' => $row['status'],
      ]);
      $row['wx_snapshot_ts'] = gmdate('c', $captureTs);
      $row['wx_metar_frozen'] = true;
      $row['wx_taf_frozen'] = !empty($row['wx_taf_raw']);
    }
  }
}
unset($row);

// Return response
jexit([
  'ok' => true,
  'window' => ['from' => $from_iso, 'to' => $to_iso],
  'count' => count($final),
  'errors' => $errors,
  'rows' => $final,
]);