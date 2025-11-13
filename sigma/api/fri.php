<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';

function http_json(string $url): ?array {
  $ch = curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,CURLOPT_USERAGENT=>'SIGMA-LV/1.0']);
  $out = curl_exec($ch);
  if (curl_errno($ch)) { curl_close($ch); return null; }
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code<200 || $code>=300 || !$out) return null;
  $j = json_decode($out, true);
  return is_array($j) ? $j : null;
}

$src = cfg()['urls']['fri'] ?? '';
$j = $src ? http_json($src) : null;
$pct = 0;
if (is_array($j)) {
  if (isset($j['fri_pct'])) $pct = (int)$j['fri_pct'];
  elseif (isset($j['fri']['fri'])) $pct = (int)$j['fri']['fri'];
}
json_response(['ok'=>(bool)$j,'fri_pct'=>$pct,'src'=>$src]);