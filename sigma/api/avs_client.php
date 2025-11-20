<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';

function avs_get(string $endpoint, array $params = [], int $ttl = 60): array {
  $c    = cfg();
  $base = rtrim($c['AVS_BASE'] ?? 'https://api.aviationstack.com/v1', '/');
  $key  = $c['AVS_KEY']  ?? '';
  $params['access_key'] = $key;

  ksort($params);
  $cacheKey  = $endpoint.'?'.http_build_query($params);
  $hash      = substr(hash('sha256', $cacheKey), 0, 32);
  $cacheDir  = __DIR__.'/cache';
  if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
  $cacheFile = $cacheDir.'/avs_'.$hash.'.json';

  if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
    $raw = @file_get_contents($cacheFile);
    $j   = json_decode($raw, true);
    if (is_array($j)) return $j + ['ok'=>true];
  }

  $url = $base.'/'.$endpoint.'?'.http_build_query($params);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
  curl_setopt($ch, CURLOPT_TIMEOUT, 8);
  curl_setopt($ch, CURLOPT_USERAGENT, 'SIGMA-AVS/1.0');

  $raw = curl_exec($ch);

  if ($raw === false) {
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok'=>false,'error'=>'avs_http','url'=>$url,'curl_err'=>$err];
  }

  $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($httpCode >= 400) {
    return [
      'ok'    => false,
      'error' => 'avs_http_' . $httpCode,
      'url'   => $url,
      'body'  => substr($raw, 0, 500),
    ];
  }

  @file_put_contents($cacheFile, $raw);
  $j = json_decode($raw, true);
  if (!is_array($j)) return ['ok'=>false,'error'=>'avs_json','url'=>$url,'body'=>substr($raw,0,500)];

  return $j + ['ok'=>true,'_url'=>$url];
}
