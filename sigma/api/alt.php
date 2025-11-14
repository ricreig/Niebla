<?php
require __DIR__.'/db.php';

$mysqli = db();
$action = $_GET['action'] ?? '';

if($_SERVER['REQUEST_METHOD']==='POST'){
  $flight_id = intval($_POST['flight_id'] ?? 0);
  if(!$flight_id) json_out(['error'=>'missing flight_id'],400);

  $stmt = $mysqli->prepare("INSERT INTO alt_assign
    (flight_id,alt_plan_icao,alt_conf_icao,aprobacion,extension,prognosis_sd,prognosis_voiti,prognosis_seneam,notas,assigned_utc)
    VALUES (?,?,?,?,?,?,?,?,?,?)");
  $stmt->bind_param('isssssssss',
    $flight_id,
    $_POST['alt_plan_icao'],
    $_POST['alt_conf_icao'],
    $_POST['aprobacion'],
    $_POST['extension'],
    $_POST['prognosis_sd'],
    $_POST['prognosis_voiti'],
    $_POST['prognosis_seneam'],
    $_POST['notas'],
    $_POST['assigned_utc']
  );
  if(!$stmt->execute()){
    json_out(['error'=>'insert_failed','message'=>$stmt->error],500);
  }
  json_out(['ok'=>true,'id'=>$stmt->insert_id]);
}

if($action==='summary'){
  $res = $mysqli->query("SELECT COALESCE(alt_conf_icao,alt_plan_icao) alt_icao, COUNT(*) total FROM alt_assign GROUP BY 1 ORDER BY total DESC");
  $rows=[]; while($r=$res->fetch_assoc()) $rows[]=$r;
  json_out(['summary'=>$rows]);
}

if($action==='by_flight'){
  $flight_id = intval($_GET['flight_id'] ?? 0);
  if(!$flight_id) json_out(['error'=>'missing flight_id'],400);
  $res = $mysqli->query("SELECT * FROM alt_assign WHERE flight_id=".$flight_id." ORDER BY updated_utc DESC");
  $rows=[]; while($r=$res->fetch_assoc()) $rows[]=$r;
  json_out(['items'=>$rows]);
}

json_out(['error'=>'unsupported'],400);
