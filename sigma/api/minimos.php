<?php
declare(strict_types=1);

/* Helpers mínimos inline */
function http_json(string $u, int $timeout=4): ?array {
  $ctx = stream_context_create(['http'=>['timeout'=>$timeout]]);
  $raw = @file_get_contents($u, false, $ctx);
  if ($raw===false) return null;
  $j = json_decode($raw, true);
  return is_array($j) ? $j : null;
}
function site_origin(): string {
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['SERVER_PORT']??'')==='443');
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme.'://'.$host;
}

/* METAR desde mmtj_fog (rápido; si falla igual entregamos mínimos) */
$metarJ = http_json(site_origin().'/mmtj_fog/data/metar.json');
$metar  = $metarJ['raw_text'] ?? $metarJ['raw'] ?? $metarJ['metar'] ?? '';

/* Mínimos publicados (placeholder). Ajusta aquí si cambian. */
$min = [
  'arr'=>[
    'rwy09'=>['vv_ft'=>250, 'vis_m'=>800,  'rvr_ft'=>2625],
    'rwy27'=>['vv_ft'=>513, 'vis_m'=>1600, 'rvr_ft'=>5250],
  ],
  'dep'=>[
    'rwy09'=>['vis_m'=>200, 'rvr_ft'=>657],
    'rwy27'=>['vis_m'=>200, 'rvr_ft'=>657],
  ],
];

/* Si el METAR trae RVR por cabecera, sobrescribir (toma el valor superior si es variable). */
if ($metar && preg_match_all('/\bR(09|27)\/(\d{3,4})(?:V(\d{3,4}))?FT\w?\b/i', $metar, $m, PREG_SET_ORDER)) {
  foreach ($m as $mm) {
    $rw = ($mm[1]==='09') ? 'rwy09' : 'rwy27';
    $ft = isset($mm[3]) && $mm[3]!=='' ? (int)$mm[3] : (int)$mm[2];
    $min['dep'][$rw]['rvr_ft'] = $ft;
  }
}

/* Salida */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
echo json_encode(['ok'=>true,'minimos'=>$min], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
