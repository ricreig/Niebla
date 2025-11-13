<?php
declare(strict_types=1);
require_once __DIR__.'/rvr.php';

/** HTTP GET con cURL y fallback a file_get_contents */
function http_get(string $url, int $timeout=12): string|false {
  if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_USERAGENT => 'mmtj-fog/cron',
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

/** Acepta ISO8601, epoch s/ms/us, int/float/string */
function parse_awc_time(mixed $v): int {
  if (is_int($v)) return $v;
  if (is_float($v)) return (int)round($v);
  if (is_string($v) && is_numeric($v)) {
    $n = (float)$v;
    if ($n > 3e12) $n = $n/1e6; // micro → s
    if ($n > 9e11) $n = $n/1e3; // mili → s
    return (int)round($n);
  }
  if (is_string($v)) {
    $t = strtotime($v);
    return $t !== false ? $t : time();
  }
  return time();
}

/** Lee múltiples METAR desde AWC API v2 */
function fetch_awc_multi(array $icaos): array {
  if (empty($icaos)) return [];
  $list = implode(',', array_map('strtoupper', $icaos));
  $url = "https://aviationweather.gov/api/data/metar?ids={$list}&format=json&taf=false&hours=2";
  $raw = http_get($url, 12);
  if (!$raw) return [];

  $arr = json_decode($raw, true);
  if (!is_array($arr)) return [];

  $out = [];
  foreach ($arr as $row) {
    $icao = strtoupper($row['icaoId'] ?? $row['station'] ?? '');
    if (!$icao) continue;

    $rawt = $row['rawOb'] ?? $row['raw_text'] ?? $row['raw'] ?? '';
    $vis  = (float)($row['visib'] ?? $row['visibility'] ?? 0);   // SM
    $ceil = (int)($row['ceil'] ?? 0);                            // centenas ft
    $vv = 0;
    if ($rawt && preg_match('/VV(\d{3})/', $rawt, $mm)) $vv = (int)$mm[1]*100;

    $wind_dir = (int)($row['wdir'] ?? $row['wind_dir_degrees'] ?? -1);
    $wind_kt  = (float)($row['wspd'] ?? $row['wind_speed_kt'] ?? 0);

    $ts = parse_awc_time($row['obsTime'] ?? $row['observation_time'] ?? null);
    $wx = $row['wx'] ?? $row['wx_string'] ?? '';

    $rvrs = $rawt ? parse_rvr($rawt) : [];

    $out[$icao] = [
      'icao'     => $icao,
      'ts'       => $ts,
      'raw'      => $rawt,
      'vis_sm'   => $vis,
      'wx'       => $wx,
      'ceil_ft'  => $ceil>0? $ceil*100 : 0,
      'vv_ft'    => $vv,
      'wind_dir' => $wind_dir,
      'wind_kt'  => $wind_kt,
      'rvr'      => $rvrs,
      'source'   => 'AWC'
    ];
  }
  return $out;
}

function save_obs_batch(array $obs, string $data_dir): void {
  if (!is_dir($data_dir)) @mkdir($data_dir, 0775, true);
  foreach ($obs as $icao => $row) {
    $f = rtrim($data_dir, '/')."/{$icao}.json";
    @file_put_contents($f, json_encode($row, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  }
}
