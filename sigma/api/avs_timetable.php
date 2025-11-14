<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$cfg = cfg();
$iata = strtoupper((string)($_GET['iata'] ?? ($cfg['IATA'] ?? 'TIJ')));
$type = strtolower((string)($_GET['type'] ?? 'arrival')) === 'departure' ? 'departure' : 'arrival';
$date = trim((string)($_GET['date'] ?? ''));
$tzName = $cfg['timezone'] ?? 'America/Tijuana';
$icao = strtoupper((string)($cfg['ICAO'] ?? 'MMTJ'));

try {
    $tzLocal = new DateTimeZone($tzName);
} catch (Throwable $e) {
    $tzLocal = new DateTimeZone('America/Tijuana');
}
$tzUtc = new DateTimeZone('UTC');

if ($date === '') {
    $date = (new DateTimeImmutable('now', $tzLocal))->format('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_response(['ok' => false, 'error' => 'bad_date_format', 'date' => $date], 400);
}

try {
    $localStart = new DateTimeImmutable($date . ' 00:00:00', $tzLocal);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'bad_date', 'detail' => $e->getMessage()], 400);
}
$localEnd = $localStart->modify('+1 day');
$fromUtc = $localStart->setTimezone($tzUtc);
$toUtc   = $localEnd->setTimezone($tzUtc);

$db = db();
$db->set_charset('utf8mb4');

if ($type === 'arrival') {
    $sql = "SELECT id, flight_number, callsign, airline, ac_reg, ac_type, dep_icao, dst_icao, std_utc, sta_utc, delay_min, status\n"
         . "FROM flights\n"
         . "WHERE (dst_icao = ? OR dst_icao = ?) AND sta_utc >= ? AND sta_utc < ?\n"
         . "ORDER BY sta_utc ASC, (callsign IS NULL) ASC, callsign ASC, flight_number ASC";
} else {
    $sql = "SELECT id, flight_number, callsign, airline, ac_reg, ac_type, dep_icao, dst_icao, std_utc, sta_utc, delay_min, status\n"
         . "FROM flights\n"
         . "WHERE (dep_icao = ? OR dep_icao = ?) AND std_utc >= ? AND std_utc < ?\n"
         . "ORDER BY std_utc ASC, (callsign IS NULL) ASC, callsign ASC, flight_number ASC";
}

$params = [$iata, $icao, $fromUtc->format('Y-m-d H:i:s'), $toUtc->format('Y-m-d H:i:s')];

$stmt = $db->prepare($sql);
if (!$stmt) {
    json_response(['ok' => false, 'error' => 'db_prepare', 'detail' => $db->error], 500);
}

$p1 = $params[0];
$p2 = $params[1];
$p3 = $params[2];
$p4 = $params[3];
$stmt->bind_param('ssss', $p1, $p2, $p3, $p4);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$seen = [];
while ($r = $res->fetch_assoc()) {
    $flightIata = $r['flight_number'] ? strtoupper((string)$r['flight_number']) : null;
    $flightIcao = $r['callsign'] ? strtoupper((string)$r['callsign']) : null;
    $staUtcStr = $r['sta_utc'] ?: null;
    $stdUtcStr = $r['std_utc'] ?: null;

    $staTs = $staUtcStr ? strtotime($staUtcStr . ' UTC') : false;
    $stdTs = $stdUtcStr ? strtotime($stdUtcStr . ' UTC') : false;

    $staUtcOut = $staTs ? gmdate('Y-m-d H:i:s', $staTs) : null;
    $stdUtcOut = $stdTs ? gmdate('Y-m-d H:i:s', $stdTs) : null;

    $delayMin = is_numeric($r['delay_min'] ?? null) ? (int)$r['delay_min'] : 0;
    $etaUtcOut = null;
    if ($staTs !== false) {
        $etaUtcOut = gmdate('Y-m-d H:i:s', $staTs + ($delayMin * 60));
    } elseif ($stdTs !== false) {
        $etaUtcOut = gmdate('Y-m-d H:i:s', $stdTs + ($delayMin * 60));
    }

    $status = strtolower((string)($r['status'] ?? 'scheduled'));
    if (in_array($status, ['active', 'en-route', 'enroute'], true) && !$etaUtcOut) {
        $status = 'taxi';
    }

    $key = ($flightIcao ?: $flightIata ?: ('ID' . $r['id'])) . '|' . ($staUtcOut ?? $stdUtcOut ?? '');
    if (isset($seen[$key])) {
        // Preferimos la primera fila (operador real) y descartamos códigos compartidos.
        continue;
    }
    $seen[$key] = true;

    $depCode = $r['dep_icao'] ? strtoupper((string)$r['dep_icao']) : null;
    $arrCode = $r['dst_icao'] ? strtoupper((string)$r['dst_icao']) : null;
    if ($type === 'arrival') {
        $arrCode = $iata;
    } elseif ($type === 'departure') {
        $depCode = $iata;
    }

    $rows[] = [
        'id'          => isset($r['id']) ? (int)$r['id'] : null,
        'flight_icao' => $flightIcao,
        'flight_iata' => $flightIata,
        'dep_iata'    => $depCode,
        'arr_iata'    => $arrCode,
        'std_utc'     => $stdUtcOut,
        'sta_utc'     => $staUtcOut,
        'eta_utc'     => $etaUtcOut,
        'delay_min'   => $delayMin,
        'status'      => $status,
    ];
}

json_response(['ok' => true, 'rows' => $rows]);
