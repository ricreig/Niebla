<?php
require_once __DIR__ . '/http.php';

function awc_metar_json($icao, $hours=6){
  $url = "https://aviationweather.gov/api/data/metar?ids=$icao&hours=$hours&format=json";
  $j = http_get_json($url);
  if (!$j || !is_array($j) || !count($j)) return null;
  $x = $j[0];
  return [
    'raw_text'        => $x['rawOb'] ?? $x['raw_text'] ?? null,
    'observation_time'=> $x['obsTime'] ?? $x['time'] ?? null,
    'visibility_sm'   => isset($x['visibility']) ? floatval($x['visibility']) :
                         (isset($x['visibility_sm']) ? floatval($x['visibility_sm']) : null),
    'wx_string'       => $x['wx'] ?? null,
    'ceiling'         => isset($x['ceil']) ? intval($x['ceil']) : null,
  ];
}

function awc_taf_json($icao, $hours=30){
  $url = "https://aviationweather.gov/api/data/taf?ids=$icao&hours=$hours&format=json";
  $j = http_get_json($url);
  if (!$j || !is_array($j) || !count($j)) return null;
  $x = $j[0];
  return ['raw_text' => $x['rawTAF'] ?? $x['raw_text'] ?? null];
}

/* Fallback a XML ADDs si el JSON directo falla */
function adds_metar($icao,$hours=6){
  $m = awc_metar_json($icao,$hours);
  if ($m) return $m;
  $base='https://aviationweather.gov/adds/dataserver_current/httpparam';
  $params = http_build_query([
    'dataSource'=>'metars','requestType'=>'retrieve','format'=>'xml',
    'stationString'=>$icao,'hoursBeforeNow'=>$hours,'mostRecent'=>'true'
  ]);
  $j = http_get_json("$base?$params");
  if (!$j || !isset($j['_xml'])) return null;
  $xml = $j['_xml'];
  $out = ['raw_text'=>null,'observation_time'=>null,'visibility_sm'=>null,'wx_string'=>null,'ceiling'=>null];
  $get = function($tag) use ($xml){ return preg_match("~<$tag>(.*?)</$tag>~s",$xml,$m)?trim($m[1]):null; };
  $out['raw_text'] = $get('raw_text');
  $out['observation_time'] = $get('observation_time');
  $v = $get('visibility_statute_mi'); if ($v!==null) $out['visibility_sm'] = floatval($v);
  $out['wx_string'] = $get('wx_string');
  if ($vv = $get('vert_vis_ft')) $out['ceiling'] = intval($vv);
  if ($out['ceiling']===null && preg_match('~ (BKN|OVC)(\d{3}) ~',' '.$out['raw_text'].' ',$m))
    $out['ceiling'] = intval($m[2])*100;
  return $out;
}
function adds_taf($icao,$hours=30){
  $t = awc_taf_json($icao,$hours);
  if ($t) return $t;
  $base='https://aviationweather.gov/adds/dataserver_current/httpparam';
  $params=http_build_query([
    'dataSource'=>'tafs','requestType'=>'retrieve','format'=>'xml',
    'stationString'=>$icao,'hoursBeforeNow'=>$hours,'mostRecent'=>'true'
  ]);
  $j=http_get_json("$base?$params");
  if(!$j || !isset($j['_xml'])) return null;
  if(preg_match("~<raw_text>(.*?)</raw_text>~s",$j['_xml'],$m))
    return ['raw_text'=>trim($m[1])];
  return null;
}
