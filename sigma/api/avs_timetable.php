<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

// Parámetros básicos
$iata = strtoupper((string)($_GET['iata'] ?? 'TIJ'));
$type = strtolower((string)($_GET['type'] ?? 'arrival')); // por ahora sólo usamos arrivals
$date = trim((string)($_GET['date'] ?? ''));

// Normalizar tipo (por si en el futuro usas departures)
$type = ($type === 'departure') ? 'departure' : 'arrival';

// Si no viene fecha, usamos hoy UTC
if ($date === '') {
  $date = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
}

// Validar formato YYYY-MM-DD
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  json_response(['ok' => false, 'error' => 'bad_date_format', 'date' => $date], 400);
}

// Conexión
$db = db();
$db->set_charset('utf8mb4');

// Para arrivals: usamos dst_icao = IATA (TIJ) y fecha por STA
$sql = "
  SELECT
    id,
    flight_number,
    callsign,
    airline,
    dep_icao,
    dst_icao,
    std_utc,
    sta_utc,
    delay_min,
    status
  FROM flights
  WHERE dst_icao = ?
    AND DATE(sta_utc) = ?
  ORDER BY sta_utc ASC
";

$stmt = $db->prepare($sql);
if (!$stmt) {
  json_response(['ok' => false, 'error' => 'db_prepare', 'detail' => $db->error], 500);
}

$stmt->bind_param('ss', $iata, $date);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
  // Elegimos un ID “operacional”: ICAO si hay, si no el número IATA
  $flightIcao = $r['callsign'] ?: $r['flight_number'];

  $rows[] = [
    'id'          => isset($r['id']) ? (int)$r['id'] : null,
    'flight_icao' => $flightIcao,
    'flight_iata' => $r['flight_number'],
    'dep_iata'    => $r['dep_icao'],   // en tu import ya viene TIJ / códigos equivalentes
    'arr_iata'    => $r['dst_icao'],
    'eta_utc'     => $r['sta_utc'],    // por ahora ETA = STA programada
    'sta_utc'     => $r['sta_utc'],
    'delay_min'   => (int)$r['delay_min'],
    'status'      => $r['status'],     // 'scheduled' / 'cancelled'
  ];
}

json_response(['ok' => true, 'rows' => $rows]);