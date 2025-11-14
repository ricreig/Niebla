<?php
$config = require __DIR__ . '/config.php';
$paths  = $config['paths'];
$tz     = $config['timezone'];

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/openmeteo.php';
require_once __DIR__ . '/lib/metar_awc.php';
require_once __DIR__ . '/lib/model.php';

$icao = $config['icao'];

// 1) METAR/TAF (NOAA AWC)
$metar = adds_metar($icao, 6);
$taf   = adds_taf($icao, 30);
if ($metar) file_put_contents($paths['metar'], json_encode($metar, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
if ($taf)   file_put_contents($paths['taf'],   json_encode($taf,   JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$metar_hint = metar_lowvis_hint($metar);

// 2) Pronóstico horario (Open-Meteo GFS, viento en kt)
$fc = openmeteo_forecast_knots($config['lat'], $config['lon'], $tz);

// 3) Construcción de predicciones
$pred = ['generated_at'=>gmdate('c'), 'tz'=>$tz, 'points'=>[]];
if ($fc && isset($fc['time'])){
  $n = count($fc['time']);
  for ($i=0; $i<$n; $i++){
    $t_iso = $fc['time'][$i];
    $T  = $fc['temperature_2m'][$i] ?? null;
    $RH = $fc['relative_humidity_2m'][$i] ?? null;
    $WS = $fc['wind_speed_10m'][$i] ?? null;
    if ($T === null || $RH === null || $WS === null) continue;
    // hora local
    $dt = new DateTime($t_iso, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone($tz));
    $hour_local = intval($dt->format('G'));
    $p = prob_fog((float)$T,(float)$RH,(float)$WS,$hour_local,$metar_hint);
    $pred['points'][] = [
      'time' => $t_iso,
      'prob' => $p,
      'temp_C' => $T,
      'rh_pct' => $RH,
      'wind_kn'=> $WS
    ];
  }
}
file_put_contents($paths['predictions'], json_encode($pred, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

// 4) Respuesta JSON para cron
$resp = [
  'ok' => true,
  'metar_cached' => (bool)$metar,
  'taf_cached' => (bool)$taf,
  'forecast_cached' => (bool)$fc,
  'points' => isset($pred['points']) ? count($pred['points']) : 0
];
header('Content-Type: application/json'); echo json_encode($resp, JSON_PRETTY_PRINT);
