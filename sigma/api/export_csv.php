<?php
require __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
if(!$from || !$to){ header('HTTP/1.1 400'); exit('from/to requeridos'); }

// Reutiliza risk.php internamente
$_GET['from']=$from; $_GET['to']=$to;
ob_start(); include __DIR__.'/risk.php'; $json = ob_get_clean();
$data = json_decode($json,true);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="riesgo_'+date('Ymd_His')+'.csv"');
$out = fopen('php://output','w');
fputcsv($out,['flight_id','flight_number','callsign','dep_icao','sta_utc','delay_min','risk_pct','bucket']);
foreach($data['items'] as $r){
  fputcsv($out,[$r['flight_id'],$r['flight_number'],$r['callsign'],$r['dep_icao'],$r['sta_utc'],$r['delay_min'],$r['risk_pct'],$r['bucket']]);
}
fclose($out);
