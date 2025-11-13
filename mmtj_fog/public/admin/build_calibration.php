<?php
// public/admin/build_calibration.php
// Genera public/api/calibration.json a partir de SABANA (MMTJ) + CENTINELAS (KSDM, KSAN, etc.)

/* ===== Guardas y debug ===== */
$EXPECTED_KEY = 'calibracion25';
if (!isset($_GET['key']) || $_GET['key'] !== $EXPECTED_KEY) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo "403\n";
  exit;
}
$DEBUG = isset($_GET['debug']);
if ($DEBUG) {
  ini_set('display_errors', '1');
  // deja visibles warnings, pero sin molestar con deprecados que ya corregimos
  error_reporting(E_ALL & ~E_DEPRECATED);
  header('Content-Type: text/plain; charset=utf-8');
}

/* ===== Rutas ===== */
$ROOT     = dirname(__DIR__, 1);                   // .../public
$APP_ROOT = dirname($ROOT);                        // .../mmtj_fog
$DATA_DIR = $APP_ROOT . '/data';                   // CSV aquí
$OUT_JSON = $ROOT . '/api/calibration.json';       // salida

$SABANA_FILE     = $DATA_DIR . '/SABANA_METAR_MMTJ_20200104_20251104_ISO.csv';
$CENTINELAS_FILE = $DATA_DIR . '/Centinelas_01ENE20-04NOV25_ISO_ICAO4.csv';

/* ===== Parámetros ===== */
$mode    = strtolower($_GET['mode'] ?? 'hour');    // 'hour' o 'avg' (hour recomendado)
$w_sab   = max(0.0, floatval($_GET['w_sab']  ?? 2));
$w_cent  = max(0.0, floatval($_GET['w_cent'] ?? 1));
$icaos_q = trim($_GET['icaos'] ?? 'ALL');          // 'ALL' o lista "KSDM,KSAN"

/* ===== Utilidades ===== */
function csv_iter(string $file, string $delim = ',', string $encl = '"', string $esc = '\\') {
  if (!is_file($file)) return;
  $fp = fopen($file, 'r');
  if (!$fp) return;

  // Header con parámetros explícitos para evitar "Deprecated"
  $hdr = fgetcsv($fp, 0, $delim, $encl, $esc);
  if (!$hdr) { fclose($fp); return; }

  // Limpia BOM en el primer encabezado si existe
  if (isset($hdr[0])) $hdr[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$hdr[0]);
  $hdr = array_map(fn($h)=>trim((string)$h), $hdr);

  while (($row = fgetcsv($fp, 0, $delim, $encl, $esc)) !== false) {
    if (count($row) === 1 && $row[0] === null) continue;
    $rec = [];
    foreach ($hdr as $i => $h) { $rec[$h] = $row[$i] ?? null; }
    yield $rec;
  }
  fclose($fp);
}

function parse_vis_sm($metar) {
  if (!$metar) return null;
  if (preg_match('/\bP6SM\b/i', $metar)) return 6.0;
  if (preg_match('/\b(\d{1,2})\s+(\d)\/(\d)\s*SM\b/i', $metar, $m)) {
    return intval($m[1]) + (intval($m[2]) / max(1, intval($m[3])));
  }
  if (preg_match('/\b(\d)\/(\d)\s*SM\b/i', $metar, $m)) {
    return intval($m[1]) / max(1, intval($m[2]));
  }
  if (preg_match('/\b(\d{1,2})\s*SM\b/i', $metar, $m)) {
    return floatval($m[1]);
  }
  return null;
}
function sm_to_m($sm) { return $sm===null ? null : $sm * 1609.344; }
function hour_local_from_iso_utc($iso, $tz='America/Tijuana') {
  if (!$iso) return null;
  try {
    $dt = new DateTime($iso, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone($tz));
    return intval($dt->format('H'));
  } catch (Throwable $e) { return null; }
}

/* ===== Set de ICAO permitidos para CENTINELAS ===== */
$allowed_cent = null;
if ($icaos_q !== '' && strtoupper($icaos_q) !== 'ALL') {
  $allowed_cent = array_filter(array_map('trim', explode(',', strtoupper($icaos_q))));
}

/* ===== Acumuladores por hora local ===== */
$tz     = 'America/Tijuana';
$hours  = array_fill(0, 24, [
  'n'=>0, 'w_sum'=>0.0,
  'p200_w'=>0.0, 'p800_w'=>0.0, 'p1600_w'=>0.0
]);

/* ===== Procesar SABANA (MMTJ) ===== */
$seen_sab = 0; $used_sab = 0;
if (is_file($SABANA_FILE)) {
  foreach (csv_iter($SABANA_FILE) as $r) {
    $seen_sab++;
    $iso = $r['ts_utc']   ?? $r['timestamp'] ?? $r['datetime'] ?? null;
    $met = $r['metar']    ?? $r['raw'] ?? $r['raw_text'] ?? null;
    $icao= strtoupper(trim($r['icao'] ?? ''));
    if (!$iso || !$met || $icao!=='MMTJ') continue;   // SABANA solo MMTJ
    $h = hour_local_from_iso_utc($iso, $tz);
    if ($h===null) continue;

    $vis_sm = parse_vis_sm($met);
    $vis_m  = sm_to_m($vis_sm);
    if ($vis_m===null) continue;

    $w = $w_sab;
    $hours[$h]['n']    += 1;
    $hours[$h]['w_sum']+= $w;
    if ($vis_m < 200)  $hours[$h]['p200_w']  += $w;
    if ($vis_m < 800)  $hours[$h]['p800_w']  += $w;
    if ($vis_m < 1600) $hours[$h]['p1600_w'] += $w;
    $used_sab++;
  }
}

/* ===== Procesar CENTINELAS (múltiples aeropuertos, NO MMTJ) ===== */
$seen_cent = 0; $used_cent = 0; $icaos_used = [];
if (is_file($CENTINELAS_FILE)) {
  foreach (csv_iter($CENTINELAS_FILE) as $r) {
    $seen_cent++;
    $iso = $r['ts_utc']   ?? $r['timestamp'] ?? $r['datetime'] ?? null;
    $met = $r['metar']    ?? $r['raw'] ?? $r['raw_text'] ?? null;
    $icao= strtoupper(trim($r['icao'] ?? ''));
    if (!$iso || !$met || !$icao) continue;
    if ($icao === 'MMTJ') continue; // MMTJ ya está en SABANA
    if ($allowed_cent && !in_array($icao, $allowed_cent, true)) continue;

    $h = hour_local_from_iso_utc($iso, $tz);
    if ($h===null) continue;

    $vis_sm = parse_vis_sm($met);
    $vis_m  = sm_to_m($vis_sm);
    if ($vis_m===null) continue;

    $w = $w_cent;
    $hours[$h]['n']    += 1;
    $hours[$h]['w_sum']+= $w;
    if ($vis_m < 200)  $hours[$h]['p200_w']  += $w;
    if ($vis_m < 800)  $hours[$h]['p800_w']  += $w;
    if ($vis_m < 1600) $hours[$h]['p1600_w'] += $w;
    $used_cent++;
    $icaos_used[$icao] = true;
  }
}

/* ===== Probabilidades ===== */
$out = ['by_hour'=>[], 'meta'=>[
  'generated_at'=>gmdate('c'),
  'tz'=>$tz,
  'w_sab'=>$w_sab, 'w_cent'=>$w_cent,
  'sabana_seen'=>$seen_sab, 'sabana_used'=>$used_sab,
  'centinelas_seen'=>$seen_cent, 'centinelas_used'=>$used_cent,
  'icaos_used'=>array_keys($icaos_used)
]];
for ($h=0; $h<24; $h++) {
  $w = max(0.000001, $hours[$h]['w_sum']);
  $out['by_hour'][sprintf('%02d',$h)] = [
    'hour'=>$h,
    'n'   =>$hours[$h]['n'],
    'p_lt200'  => $hours[$h]['p200_w']  / $w,
    'p_lt800'  => $hours[$h]['p800_w']  / $w,
    'p_lt1600' => $hours[$h]['p1600_w'] / $w
  ];
}

/* ===== Escritura ===== */
@mkdir(dirname($OUT_JSON), 0775, true);
$ok = (bool)file_put_contents($OUT_JSON, json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);

if ($DEBUG) {
  echo "SABANA delimitador: \",\"\n";
  echo "SABANA headers: ts_utc | icao | metar | tipo\n";
  echo "Procesadas $seen_sab filas de SABANA, útiles: $used_sab\n";
  echo "CENTINELAS delimitador: \",\"\n";
  echo "CENTINELAS headers: ts_utc | icao | metar\n";
  echo "Procesadas $seen_cent filas de CENTINELAS, útiles: $used_cent\n\n";
  echo "Resumen por hora:\n";
  foreach ($out['by_hour'] as $hh=>$r) {
    printf("%02d: n=%d  p_lt200=%.3f  p_lt800=%.3f  p_lt1600=%.3f\n",
      $r['hour'], $r['n'], $r['p_lt200'], $r['p_lt800'], $r['p_lt1600']);
  }
  echo "\n".($ok ? "OK -> $OUT_JSON\n" : "ERROR al escribir $OUT_JSON\n");
} else {
  header('Content-Type: application/json; charset=utf-8');
  echo $ok ? json_encode(['ok'=>true,'path'=>$OUT_JSON,'meta'=>$out['meta']])
           : json_encode(['ok'=>false,'error'=>'write_failed']);
}