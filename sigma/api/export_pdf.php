<?php
require __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';
require __DIR__.'/../vendor/fpdf/fpdf.php';

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
if(!$from || !$to){ header('HTTP/1.1 400'); exit('from/to requeridos'); }

ob_start(); include __DIR__.'/risk.php'; $json = ob_get_clean();
$data = json_decode($json,true);

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Arial','',12);
$pdf->Cell(0,10,"SIGMA-LV · Ops Risk  MMTJ");
$pdf->Cell(0,10,"Ventana: $from → $to");
$pdf->Cell(0,10,"FRI: ".($data['fri'] ?? 'NA'));
$pdf->Cell(0,10,"Items: ".count($data['items']));
foreach($data['items'] as $r){
  $pdf->Cell(0,8, sprintf("%s %s ETA %s  riesgo %d%% %s",
    $r['flight_number'],$r['dep_icao'],$r['sta_utc'],$r['risk_pct'],strtoupper($r['bucket'])
  ));
}
$pdf->Output();
