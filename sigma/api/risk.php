<?php
require __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';
$config = require __DIR__.'/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
if(!$from || !$to){
  http_response_code(400);
  echo json_encode(['error'=>'missing_params']); exit;
}

$mysqli = db();

// JOIN con alterno confirmado/planeado
$sql = "SELECT f.*, COALESCE(a.alt_conf_icao, a.alt_plan_icao) AS alt_icao
        FROM flights f
        LEFT JOIN alt_assign a ON a.flight_id = f.id
        WHERE f.sta_utc BETWEEN ? AND ?
        ORDER BY f.sta_utc";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('ss',$from,$to);
$stmt->execute();
$res = $stmt->get_result();
$rows=[];
while($r=$res->fetch_assoc()) $rows[]=$r;

// FRI desde fuente
function read_json_flexible($pathOrUrl){
  if(is_file($pathOrUrl)){
    $c = @file_get_contents($pathOrUrl);
    if($c) return json_decode($c,true);
  }
  $c = @file_get_contents($pathOrUrl);
  if($c) return json_decode($c,true);
  return null;
}
$fri = read_json_flexible($config['FRI_SOURCE']);
$fri_value = $fri['fri'] ?? ($fri['FRI'] ?? null);

// METAR/TAF snapshots opcionales para low-vis flags
$metar_raw = '';
$taf_raw = '';
$metar = $mysqli->query("SELECT raw FROM metar_snap WHERE icao='MMTJ' ORDER BY ts_utc DESC LIMIT 1")->fetch_assoc();
if($metar) $metar_raw = $metar['raw'];
$taf = $mysqli->query("SELECT raw FROM taf_snap WHERE icao='MMTJ' ORDER BY ts_utc DESC LIMIT 1")->fetch_assoc();
if($taf) $taf_raw = $taf['raw'];

$obs_lowvis = false;
$vis_sm = parse_vis_sm(' '.$metar_raw);
$vv_ft = parse_vv_ft(' '.$metar_raw);
if($vis_sm!==null && $vis_sm<=1.0) $obs_lowvis=true;
if($vv_ft!==null && $vv_ft<=300) $obs_lowvis=true;
if(has_fg_br(' '.$metar_raw)) $obs_lowvis=true;

$taf_lowvis = false;
if(has_fg_br(' '.$taf_raw)) $taf_lowvis=true;
if(preg_match('/ (\d\/\d|[0123])SM/', ' '.$taf_raw)) $taf_lowvis=true;

// Centinelas desde FRI si existen
$cent = 0.0;
if(isset($fri['centinelas']) && is_array($fri['centinelas'])){
  foreach($fri['centinelas'] as $c){
    if(!empty($c['fg']) || (!empty($c['vis_sm']) && $c['vis_sm']<=3)){
      $cent = max($cent, 1.0);
    }
  }
}

// Ensamble salida
$out=[];
$tz = new DateTimeZone('America/Tijuana');
foreach($rows as $r){
  $eta_local = new DateTime($r['sta_utc'].'Z'); $eta_local->setTimezone($tz);
  $h = intval($eta_local->format('H'));
  $demoraCritica = ($h>=4 && $h<=7) ? 1 : 0;

  list($pct,$bucket) = compute_risk(intval($fri_value), $taf_lowvis, $obs_lowvis, $cent, $demoraCritica);

  $out[] = [
    'flight_id'     => intval($r['id']),
    'flight_number' => $r['flight_number'],
    'callsign'      => $r['callsign'],
    'airline'       => $r['airline'],
    'dep_icao'      => $r['dep_icao'],
    'sta_utc'       => $r['sta_utc'],
    'delay_min'     => intval($r['delay_min']),
    'status'        => $r['status'] ?: 'scheduled',
    'alt_icao'      => $r['alt_icao'],
    'risk_pct'      => $pct,
    'bucket'        => $bucket
  ];
}

echo json_encode(['items'=>$out,'fri'=>$fri_value,'obs_lowvis'=>$obs_lowvis,'taf_lowvis'=>$taf_lowvis], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
