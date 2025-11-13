<?php
declare(strict_types=1);

/* ========= Diagnóstico visible si lo ejecutas manualmente ========= */
error_reporting(E_ALL);
ini_set('display_errors', '1');

/* ========= Rutas y carpetas ========= */
$ROOT = __DIR__;                    // mmtj_fog/
$DATA = $ROOT . '/data';
$API  = $ROOT . '/public/api';

@mkdir($DATA,            0775, true);
@mkdir($DATA.'/obs',     0775, true);
@mkdir($DATA.'/nwp',     0775, true);
@mkdir($DATA.'/sat',     0775, true);
@mkdir($DATA.'/marine',  0775, true);
@mkdir($API,             0775, true);

/* ========= Log ========= */
$LOG = $DATA . '/cron_ext.log';
function logx(string $msg): void {
  global $LOG;
  @file_put_contents($LOG, '['.gmdate('c')."] ".$msg.PHP_EOL, FILE_APPEND);
}

/* ========= Utilidades ========= */
function write_json_atomic(string $path, array $payload): bool {
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) return false;
  $tmp = $path . '.tmp';
  if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  return @rename($tmp, $path);
}
function aget(array $arr, string $key, mixed $default=null): mixed {
  return array_key_exists($key, $arr) ? $arr[$key] : $default;
}
function aidx(?array $arr, int $idx, mixed $default=null): mixed {
  return (is_array($arr) && array_key_exists($idx, $arr)) ? $arr[$idx] : $default;
}

/* ========= Librerías ========= */
require_once __DIR__.'/lib/metar_multi.php';    // fetch_awc_multi(), save_obs_batch()
require_once __DIR__.'/lib/openmeteo_ext.php';  // fetch_openmeteo_ext()
require_once __DIR__.'/lib/fri.php';            // fri_score()

try {
  logx('=== CRON START ===');

  /* 1) OBS AWC — Sentinelas (sin MMES) */
  $sent = ['KSAN','KSDM','KNRS','KSEE','KMYF','MMTJ'];
  $obs  = fetch_awc_multi($sent);
  if (empty($obs)) logx('WARN AWC vacío');
  save_obs_batch($obs, $DATA.'/obs');

  $obs_mmtj  = $obs['MMTJ'] ?? [];
  $sentinels = array_values(array_filter($obs, fn($k)=>$k!=='MMTJ', ARRAY_FILTER_USE_KEY));
  logx('OK OBS count='.count($obs).' sentinels='.count($sentinels));

  /* 2) NWP Open-Meteo (MMTJ ~ posición central pista) */
  $mmtj_lat = 32.541; $mmtj_lon = -116.970;
  $nwp = fetch_openmeteo_ext($mmtj_lat, $mmtj_lon);
  if (empty($nwp)) logx('WARN NWP vacío');
  @file_put_contents($DATA.'/nwp/openmeteo.json', json_encode($nwp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

  /* 3) Satélite y mar (opcionales; si no existen no suman por caducidad en fri.php) */
  $sat    = is_file($DATA.'/sat/goes_fls.json')   ? json_decode(file_get_contents($DATA.'/sat/goes_fls.json'), true)   : [];
  $marine = is_file($DATA.'/marine/ndbc_sd.json') ? json_decode(file_get_contents($DATA.'/marine/ndbc_sd.json'), true) : [];

  /* 4) Features NWP “hora 0” con guardas robustas */
  $H = (isset($nwp['hourly']) && is_array($nwp['hourly'])) ? $nwp['hourly'] : [];

  $N0 = [
    'T'                     => aidx(aget($H,'temperature_2m', []),         0, null),
    'Td'                    => aidx(aget($H,'dew_point_2m', []),            0, null),
    'low_cloud_cover'       => aidx(aget($H,'low_cloud_cover', []),         0, null),
    'boundary_layer_height' => aidx(aget($H,'boundary_layer_height', []),   0, null),
    'wdir'                  => aidx(aget($H,'wind_direction_10m', []),      0, null),
    'wspd'                  => aidx(aget($H,'wind_speed_10m', []),          0, null),
  ];

  /* 5) Cálculo del FRI */
  $fri = fri_score($obs_mmtj, $N0, $sentinels, $sat, $marine);
  $fri['ts'] = gmdate('c');

  /* 6) Publicación: persistencia interna + endpoint estático para el front */
  if (!write_json_atomic($DATA.'/fri_now.json', $fri)) {
    throw new RuntimeException('No se pudo escribir data/fri_now.json');
  }
  if (!write_json_atomic($API.'/fri.json', $fri)) {
    throw new RuntimeException('No se pudo escribir public/api/fri.json');
  }

  logx('OK FRI '.json_encode($fri, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  echo 'FRI actualizado: '.json_encode($fri, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
  logx('=== CRON END ===');
} catch (Throwable $e) {
  $msg = $e->getMessage().' @ '.$e->getFile().':'.$e->getLine();
  logx('ERROR '.$msg);
  http_response_code(500);
  echo 'ERROR: '.$e->getMessage();
  exit(1);
}
