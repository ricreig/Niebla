<?php
require_once __DIR__ . '/http.php';
function openmeteo_forecast_knots($lat, $lon, $tz='auto'){
  $url = "https://api.open-meteo.com/v1/gfs?latitude=$lat&longitude=$lon&hourly=temperature_2m,relative_humidity_2m,wind_speed_10m,surface_pressure&windspeed_unit=kn&timezone=" . urlencode($tz);
  $j = http_get_json($url);
  if (!$j || !isset($j['hourly'])) return null;
  return $j['hourly'];
}
