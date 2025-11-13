<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';

function http_json(string $url): ?array {
  $ch = curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,CURLOPT_USERAGENT=>'SIGMA-LV/1.0']);
  $out = curl_exec($ch);
  if (curl_errno($ch)) { curl_close($ch); return null; }
  $code = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code<200 || $code>=300 || !$out) return null;
  $j = json_decode($out, true);
  return is_array($j) ? $j : null;
}
function pick_raw(?array $a, array $cands): string {
  if(!$a) return '';
  foreach($cands as $k){ if(!empty($a[$k]) && is_string($a[$k])) return $a[$k]; }
  foreach(['data','result','metar','taf'] as $nest){
    if(isset($a[$nest]) && is_array($a[$nest])){
      foreach($cands as $k){ if(!empty($a[$nest][$k]) && is_string($a[$nest][$k])) return $a[$nest][$k]; }
    }
  }
  return '';
}
$cfg = cfg();
$m = http_json($cfg['urls']['metar'] ?? '');
$t = http_json($cfg['urls']['taf']   ?? '');
$metar_raw = pick_raw($m, ['metar_raw','raw','raw_text','metar','text']);
$taf_raw   = pick_raw($t, ['taf_raw','raw','raw_text','taf','text']);
json_response(['ok'=> (bool)($metar_raw||$taf_raw), 'metar_raw'=>$metar_raw, 'taf_raw'=>$taf_raw]);