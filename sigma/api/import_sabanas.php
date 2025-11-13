<?php
require __DIR__.'/db.php';
$mysqli = db();

if($_SERVER['REQUEST_METHOD']!=='POST'){
  http_response_code(405); exit;
}

if(!isset($_FILES['file'])){
  json_out(['error'=>'missing_file'],400);
}

$tmp = $_FILES['file']['tmp_name'];
$csv = fopen($tmp,'r');
$header = fgetcsv($csv);
$map = array_flip($header);

$insF = $mysqli->prepare("INSERT INTO flights(flight_number, callsign, airline, ac_reg, ac_type, dep_icao, dst_icao, std_utc, sta_utc, delay_min, status)
VALUES(?,?,?,?,?,?,?,?,?,?, 'scheduled')
ON DUPLICATE KEY UPDATE delay_min=VALUES(delay_min)");
$insF->bind_param('sssssssssi',$fn,$cs,$al,$reg,$typ,$dep,$dst,$std,$sta,$delay);

$insA = $mysqli->prepare("INSERT INTO alt_assign(flight_id, alt_plan_icao, alt_conf_icao, aprobacion, extension, prognosis_sd, prognosis_voiti, prognosis_seneam, notas, assigned_utc)
VALUES(?,?,?,?,?,?,?,?,?,?)");
$insA->bind_param('isssssssss',$fid,$altp,$altc,$apr,$ext,$sd,$voiti,$sen,$notas,$ass);

$count=0;
while(($row=fgetcsv($csv))!==false){
  $fn   = $row[$map['flight_number']] ?? null;
  $cs   = $row[$map['callsign']] ?? null;
  $al   = null;
  $reg  = $row[$map['ac_reg']] ?? null;
  $typ  = $row[$map['ac_type']] ?? null;
  $dep  = $row[$map['dep_icao']] ?? null;
  $dst  = $row[$map['dst_icao']] ?? 'TIJ';
  $std  = $row[$map['std_utc']] ?? null;
  $sta  = $row[$map['sta_utc']] ?? null;
  $delay= intval($row[$map['delay_min']] ?? 0);
  if(!$sta or !$fn){ continue; }
  $insF->execute();
  $fid = $mysqli->insert_id;
  if(!$fid){
    // Buscar id existente
    $q=$mysqli->query("SELECT id FROM flights WHERE flight_number='".$mysqli->real_escape_string($fn)."' AND sta_utc='".$mysqli->real_escape_string($sta)."' LIMIT 1");
    $r=$q->fetch_assoc(); $fid=intval($r['id']);
  }

  $altp = $row[$map['alt_plan_icao']] ?? null;
  $altc = $row[$map['alt_conf_icao']] ?? null;
  $apr  = $row[$map['aprobacion']] ?? null;
  $ext  = $row[$map['extension']] ?? null;
  $sd   = $row[$map['prognosis_sd']] ?? null;
  $voiti= $row[$map['prognosis_voiti']] ?? null;
  $sen  = $row[$map['prognosis_seneam']] ?? null;
  $notas= $row[$map['notas']] ?? null;
  $ass  = date('Y-m-d H:i:s');
  if($fid){
    $insA->execute();
    $count++;
  }
}

json_out(['ok'=>true,'imported'=>$count]);
