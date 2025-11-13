<?php
declare(strict_types=1);

/** GET helper */
function om_http_get(string $url, int $timeout=12): string|false {
  if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_USERAGENT => 'mmtj-fog/openmeteo',
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $out = curl_exec($ch);
    curl_close($ch);
    if ($out !== false) return $out;
  }
  if (ini_get('allow_url_fopen')) {
    $ctx = stream_context_create(['http'=>['timeout'=>$timeout],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    return @file_get_contents($url, false, $ctx);
  }
  return false;
}

/** Mapea Open-Meteo → llaves usadas en el sistema */
function fetch_openmeteo_ext(float $lat, float $lon): array {
  $params = http_build_query([
    'latitude'  => $lat,
    'longitude' => $lon,
    'hourly'    => 'temperature_2m,dewpoint_2m,cloudcover_low,boundary_layer_height,windspeed_10m,winddirection_10m',
    'timezone'  => 'UTC'
  ]);
  $url = "https://api.open-meteo.com/v1/forecast?{$params}";
  $raw = om_http_get($url, 12);
  if (!$raw) return [];

  $j = json_decode($raw, true);
  if (!is_array($j) || !isset($j['hourly'])) return [];

  $H = $j['hourly'];
  // normaliza nombres a los esperados por cron_ext.php
  return [
    'hourly' => [
      'time'                    => $H['time']                  ?? [],
      'temperature_2m'         => $H['temperature_2m']        ?? [],
      'dew_point_2m'           => $H['dewpoint_2m']           ?? [],
      'low_cloud_cover'        => $H['cloudcover_low']        ?? [],
      'boundary_layer_height'  => $H['boundary_layer_height'] ?? [],
      'wind_speed_10m'         => $H['windspeed_10m']         ?? [],
      'wind_direction_10m'     => $H['winddirection_10m']     ?? [],
    ]
  ];
}
