<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__.'/avs_client.php';

$iata = strtoupper((string)($_GET['iata'] ?? 'TIJ'));
$type = strtolower((string)($_GET['type'] ?? 'arrival')); // 'arrival'|'departure'
$ttl  = max(30, (int)($_GET['ttl'] ?? 60));
$date = trim((string)($_GET['date'] ?? ''));              // YYYY-MM-DD opcional

// Normaliza tipo
$type = ($type === 'departure') ? 'departure' : 'arrival';

// Si viene ?date=YYYY-MM-DD:
//  - Para día actual y próximos: usar 'timetable' (permite fecha).
//  - Para pasado: usar 'flights' con flight_date.
// Si NO viene date: usar 'timetable' día actual (comportamiento previo).
$params = ['iataCode' => $iata, 'type' => $type];

$endpoint = 'timetable';
if ($date !== '') {
  $params['date'] = $date;                 // muchos planes aceptan 'date' en timetable
  // En fallback para pasado, agrega flight_date y cambia endpoint:
  $today = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
  if ($date < $today) {
    $endpoint = 'flights';
    unset($params['date']);
    $params = [
      // filtra por llegada a TIJ/MMTJ vía IATA; agrega ICAO si tu plan lo soporta
      'arr_iata'    => $iata,
      'flight_date' => $date,
    ];
  }
}

$res = avs_get($endpoint, $params, $ttl);
if (!($res['ok'] ?? false)) {
  http_response_code(502);
  echo json_encode(['ok'=>false,'error'=>$res['error'] ?? 'avs_error','data'=>[], 'url'=>$res['_url'] ?? null], JSON_UNESCAPED_SLASHES);
  exit;
}
echo json_encode(['ok'=>true,'data'=>$res['data'] ?? [], '_url'=>$res['_url'] ?? null], JSON_UNESCAPED_SLASHES);