<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';

$icao = cfg()['ICAO'] ?? 'MMTJ';
$taf  = @file_get_contents('https://aviationweather.gov/api/data/taf?ids='.urlencode($icao).'&format=raw');
$met  = @file_get_contents('https://aviationweather.gov/api/data/metar?ids='.urlencode($icao).'&format=raw');
$taf  = $taf !== false ? trim($taf) : null;
$met  = $met !== false ? trim($met) : null;

function cat_color(string $txt): string {
  $T = strtoupper($txt); $vis=null; $cig=null;
  if (preg_match('/\\b(\\d{1,2})SM\\b/', $T, $m)) $vis=(int)$m[1];
  if (preg_match('/\\b(BKN|OVC)(\\d{3})\\b/', $T, $m)) $cig=(int)$m[2]*100;
  if ($vis !== null && $vis < 1) return 'LIFR';
  if ($cig !== null && $cig < 500) return 'LIFR';
  if (($vis !== null && $vis < 3) || ($cig !== null && $cig < 1000)) return 'IFR';
  if (($vis !== null && $vis < 5) || ($cig !== null && $cig < 3000)) return 'MVFR';
  return 'VFR';
}

json_response([
  'ok'=>true,'icao'=>$icao,
  'metar_raw'=>$met,'taf_raw'=>$taf,
  'metar_cat'=>$met?cat_color($met):null,
  'taf_cat'=>$taf?cat_color($taf):null,
]);