<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/config.php';
function jexit($arr, int $code=200){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
$dep = isset($_GET['dep_iata']) ? strtoupper(trim((string)$_GET['dep_iata'])) : '';
$arr = isset($_GET['arr_iata']) ? strtoupper(trim((string)$_GET['arr_iata'])) : '';
$type = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : '';
$start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;
$ttl   = isset($_GET['ttl'])   ? max(1,(int)$_GET['ttl']) : 5;
$st    = isset($_GET['status'])? (string)$_GET['status'] : '';
if (!$dep && !$arr) jexit(['ok'=>false,'error'=>'dep_iata o arr_iata requerido'], 400);
if (!in_array($type, ['arrival','departure','both'], true)) $type = $dep && $arr ? 'both' : ($dep ? 'departure':'arrival');
function parse_utc($s){ if(!$s) return gmdate('Y-m-d\TH:i:00\Z'); $s=rtrim($s,'Z'); $ts=strtotime($s.'Z'); return gmdate('Y-m-d\TH:i:00\Z',$ts?:time()); }
$from_iso = parse_utc($start);
$from_ts  = strtotime($from_iso);
$to_ts    = $from_ts + max(1,$hours)*3600;
$to_iso   = gmdate('Y-m-d\TH:i:00\Z', $to_ts);
function days(int $a, int $b){ $o=[]; $c=strtotime(gmdate('Y-m-d\T00:00:00\Z',$a)); $e=strtotime(gmdate('Y-m-d\T00:00:00\Z',$b)); for($t=$c;$t<=$e;$t+=86400) $o[]=gmdate('Y-m-d',$t); return $o; }
function curl_json(string $url, array &$errors): ?array{
  $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
  $body=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if($err) $errors[]="curl:$err"; if(!$body){ $errors[]="http:$code:empty"; return null; }
  $j=json_decode($body,true); if(!$j){ $errors[]="json:$code"; return null; } if(isset($j['error'])) $errors[]="api:".$j['error']['code'].":".$j['error']['type']??''; return $j;
}
function normalize_rows(array $pages, string $side): array{
  $out=[]; foreach($pages as $row){ $dep=$row['departure']??[]; $arr=$row['arrival']??[]; $air=$row['airline']??[]; $flt=$row['flight']??[];
    $sta=$side==='arrival'?($arr['scheduled']??$arr['scheduledTime']??null):($dep['scheduled']??$dep['scheduledTime']??null);
    $eta=$side==='arrival'?($arr['estimated']??$arr['estimatedTime']??null):($dep['estimated']??$dep['estimatedTime']??null);
    $ata=$side==='arrival'?($arr['actual']??$arr['actualTime']??null):($dep['actual']??$dep['actualTime']??null);
    $status=$row['flight_status']??($row['status']??null); $delay=0; if($sta&&$eta){ $d=(strtotime($eta)-strtotime($sta))/60; if(is_finite($d)) $delay=(int)round($d); }
    $out[]=['flight_iata'=>$flt['iata']??($flt['iataNumber']??null),'airline_name'=>$air['name']??null,
      'dep_iata'=>strtoupper($dep['iata']??($dep['iataCode']??'')),'arr_iata'=>strtoupper($arr['iata']??($arr['iataCode']??'')),
      'sta_utc'=>$sta,'eta_utc'=>$eta,'ata_utc'=>$ata,'status'=>$status,'delay_min'=>$delay,
      'terminal'=>$side==='arrival'?($arr['terminal']??null):($dep['terminal']??null),'gate'=>$side==='arrival'?($arr['gate']??null):($dep['gate']??null),'_source'=>'avs']; }
  return $out;
}
function fetch_block(string $iata,string $side,string $date,string $status_csv,array &$errors): array{
  $today=strtotime(gmdate('Y-m-d')); $d_ts=strtotime($date); $pages=[]; $limit=AVS_LIMIT; $offset=0;
  if ($d_ts===$today){ // timetable hoy
    $params=['iataCode'=>$iata,'type'=>$side,'date'=>$date,'limit'=>$limit,'offset'=>$offset];
    do{ $params['offset']=$offset; $url=avs_url(AVS_ENDPOINT_TIMETABLE,$params); $j=curl_json($url,$errors); if(!$j) break;
        $data=$j['data']??[]; $count=is_array($data)?count($data):0; $pages=array_merge($pages,$data); $offset+=$limit; if($count<$limit) break; usleep(1200000);
    }while(true);
  } elseif ((int)round(($d_ts-$today)/86400)>7) { // >7d: flightsFuture
    $params=['iataCode'=>$iata,'type'=>$side,'date'=>$date,'limit'=>$limit,'offset'=>$offset];
    do{ $params['offset']=$offset; $url=avs_url(AVS_ENDPOINT_FUTURE,$params); $j=curl_json($url,$errors); if(!$j) break;
        $data=$j['data']??[]; $count=is_array($data)?count($data):0; $pages=array_merge($pages,$data); $offset+=$limit; if($count<$limit) break; usleep(1200000);
    }while(true);
  } else { // próximos <=7d: flights por fecha
    $params=[ $side==='arrival'?'arr_iata':'dep_iata'=>$iata, 'flight_status'=>($status_csv ?: 'scheduled,active,landed,diverted,cancelled'), 'flight_date'=>$date, 'limit'=>$limit,'offset'=>$offset];
    do{ $params['offset']=$offset; $url=avs_url(AVS_ENDPOINT_FLIGHTS,$params); $j=curl_json($url,$errors); if(!$j) break;
        $data=$j['data']??[]; $count=is_array($data)?count($data):0; $pages=array_merge($pages,$data); $offset+=$limit; if($count<$limit) break; usleep(1200000);
    }while(true);
  }
  return normalize_rows($pages,$side);
}
function cache_key(array $q){ ksort($q); return keyhash($q); }
$queries=[];
if ($type==='arrival'||$type==='both')   $queries[]=['side'=>'arrival','iata'=>($arr ?: $dep),'from'=>$from_iso,'to'=>$to_iso];
if ($type==='departure'||$type==='both') $queries[]=['side'=>'departure','iata'=>($dep ?: $arr),'from'=>$from_iso,'to'=>$to_iso];
$errs=[]; $rows=[];
foreach($queries as $q){
  $ck=cache_key(['iata'=>$q['iata'],'side'=>$q['side'],'from'=>$q['from'],'to'=>$q['to'],'status'=>$st]);
  if($cached=cache_get($ck,$ttl)){ $rows=array_merge($rows,$cached['rows']??[]); continue; }
  $dayrows=[]; foreach(days(strtotime($q['from']), strtotime($q['to'])) as $date){ $dayrows=array_merge($dayrows, fetch_block($q['iata'],$q['side'],$date,$st,$errs)); }
  cache_put($ck,['rows'=>$dayrows]); $rows=array_merge($rows,$dayrows);
}
$uniq=[]; $final=[];
foreach($rows as $r){ $key=($r['flight_iata']??'').'|'.($r['sta_utc']??'').'|'.($r['dep_iata']??'').'>'.($r['arr_iata']??''); if(isset($uniq[$key])) continue; $uniq[$key]=1; $final[]=$r; }
jexit(['ok'=>true,'window'=>['from'=>$from_iso,'to'=>$to_iso],'count'=>count($final),'errors'=>$errs,'rows'=>$final]);
