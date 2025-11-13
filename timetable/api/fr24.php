<?php
declare(strict_types=1);

/**
 * Proxy endpoint that combines scheduled flight information from AviationStack
 * (timetable endpoint) with live flight status from Flightradar24.  This
 * endpoint accepts similar query parameters to avs.php (`arr_iata` or
 * `dep_iata`, `type`, `start`, `hours`, `ttl`, `status`) and produces a
 * normalized list of arrivals/departures with fields similar to avs.php:
 *   flight_iata, airline_name, dep_iata, arr_iata, sta_utc, eta_utc, ata_utc,
 *   status, delay_min, terminal, gate.
 *
 * It performs the following steps:
 * 1. Determines the airport (arrival or departure IATA) and the time
 *    window (currently only the date portion is used for schedule).
 * 2. Retrieves the timetable for the given date and airport from AviationStack
 *    using the timetable endpoint.  Results are cached using the built-in
 *    cache_get/cache_put functions to avoid exceeding daily request limits.
 * 3. Retrieves live flight positions from Flightradar24 using the
 *    live/flight-positions/full endpoint, filtered by inbound/outbound to the
 *    airport.  The FR24 API token and version are defined in config.php.
 * 4. Merges the scheduled and live data: each scheduled flight is updated
 *    with ETA and status if a live flight is found; flights whose scheduled
 *    time has passed and are not present in the live data are marked as
 *    cancelled; scheduled flights in the future remain scheduled.  Live
 *    flights not present in the schedule (e.g. charters) are included with
 *    unknown status.
 * 5. Returns a JSON object with `ok`, `window`, `count`, `errors` and `rows`.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';

// Helper: respond with JSON and exit
function jexit($arr, int $code = 200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Helper: parse ISO8601 to timestamp; returns current time on failure
function parse_iso($iso): int {
  $ts = strtotime($iso);
  return $ts === false ? time() : $ts;
}

// Read parameters
$arr = isset($_GET['arr_iata']) ? strtoupper(trim((string)$_GET['arr_iata'])) : '';
$dep = isset($_GET['dep_iata']) ? strtoupper(trim((string)$_GET['dep_iata'])) : '';
$type = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : '';
$start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;
$ttl = isset($_GET['ttl']) ? max(1, (int)$_GET['ttl']) : 5;
$statusFilter = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : '';

// Validate airport and type
if (!$arr && !$dep) {
  jexit(['ok' => false, 'error' => 'arr_iata o dep_iata requerido'], 400);
}
// Determine side: arrival or departure or both
if (!in_array($type, ['arrival', 'departure', 'both'], true)) {
  $type = $arr && $dep ? 'both' : ($arr ? 'arrival' : 'departure');
}
// Compute time window (we only use date for schedule)
function normalize_start($s) {
  if (!$s) return gmdate('Y-m-d\TH:i:00\Z');
  $s = rtrim($s, 'Z');
  $ts = strtotime($s . 'Z');
  return gmdate('Y-m-d\TH:i:00\Z', $ts ?: time());
}
$from_iso = normalize_start($start);
$from_ts = parse_iso($from_iso);
$to_ts = $from_ts + max(1, $hours) * 3600;
$to_iso = gmdate('Y-m-d\TH:i:00\Z', $to_ts);

// Determine date for schedule (UTC date of start)
$schedule_date = gmdate('Y-m-d', $from_ts);

// Determine IATA code and FR24 direction
$iata = $arr ?: $dep;
$fr24Dir = ($type === 'departure') ? 'outbound:' : 'inbound:';
$fr24AirportsParam = $fr24Dir . $iata;

// === Retrieve schedule from local database ===
// Instead of querying AviationStack on every request we read the pre-fetched
// timetable stored in the flights table.  The update_schedule.php script
// populates this table daily.  For arrivals, we select rows where the
// destination code matches the requested airport; for departures, we match
// on the departure code.  Only rows with sta_utc on the given date are
// returned.  Times are converted to ISO8601Z.
// Collect any errors encountered during DB or API calls.  Initialise here so
// it exists when the DB section runs.
$errors = [];
$scheduleRows = [];
try {
  $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
  if ($mysqli->connect_errno) {
    // On DB error, leave scheduleRows empty; live data will still show
    $errors[] = 'db:' . $mysqli->connect_error;
  } else {
    $mysqli->set_charset(DB_CHARSET);
    $query = '';
    if ($type === 'arrival') {
      $query = 'SELECT flight_number AS flight_iata, airline AS airline_name, dep_icao AS dep_iata, dst_icao AS arr_iata, sta_utc, std_utc, delay_min, status FROM flights WHERE dst_icao = ? AND DATE(sta_utc) = ?';
    } elseif ($type === 'departure') {
      $query = 'SELECT flight_number AS flight_iata, airline AS airline_name, dep_icao AS dep_iata, dst_icao AS arr_iata, sta_utc, std_utc, delay_min, status FROM flights WHERE dep_icao = ? AND DATE(std_utc) = ?';
    } else { // both
      $query = 'SELECT flight_number AS flight_iata, airline AS airline_name, dep_icao AS dep_iata, dst_icao AS arr_iata, sta_utc, std_utc, delay_min, status FROM flights WHERE (dst_icao = ? AND DATE(sta_utc) = ?) OR (dep_icao = ? AND DATE(std_utc) = ?)';
    }
    $stmt = $mysqli->prepare($query);
    if ($stmt) {
      if ($type === 'both') {
        $stmt->bind_param('ssss', $iata, $schedule_date, $iata, $schedule_date);
      } else {
        $stmt->bind_param('ss', $iata, $schedule_date);
      }
      if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
          $sta = $row['sta_utc'] ?? null;
          $std = $row['std_utc'] ?? null;
          $staIso = null;
          $stdIso = null;
          if ($sta) {
            $ts = strtotime($sta);
            $staIso = $ts ? gmdate('c', $ts) : null;
          }
          if ($std) {
            $ts = strtotime($std);
            $stdIso = $ts ? gmdate('c', $ts) : null;
          }
          $scheduleRows[] = [
            'flight_iata' => $row['flight_iata'],
            'airline_name' => $row['airline_name'],
            'dep_iata' => strtoupper($row['dep_iata'] ?? ''),
            'arr_iata' => strtoupper($row['arr_iata'] ?? ''),
            'sta_utc' => $staIso,
            'std_utc' => $stdIso,
            'delay_min' => is_numeric($row['delay_min']) ? (int)$row['delay_min'] : null,
            'status' => strtolower($row['status'] ?? 'scheduled'),
            'terminal' => null,
            'gate' => null,
          ];
        }
        $stmt->close();
      } else {
        $errors[] = 'db:exec';
      }
    } else {
      $errors[] = 'db:prepare';
    }
    $mysqli->close();
  }
} catch (Exception $e) {
  // swallow DB exceptions
  $errors[] = 'db:' . $e->getMessage();
}

// === Retrieve live data from Flightradar24 ===
function call_fr24(string $endpoint, array $params, array &$errors): ?array {
  $url = FR24_API_BASE . $endpoint;
  $query = http_build_query($params);
  if ($query) $url .= '?' . $query;
  $headers = [
    'Authorization: Bearer ' . FR24_API_TOKEN,
    'Accept: application/json',
    'Accept-Version: ' . FR24_API_VERSION,
  ];
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
  ]);
  $body = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($err) {
    $errors[] = 'fr24:' . $err;
    return null;
  }
  if (!$body) {
    $errors[] = 'fr24:empty';
    return null;
  }
  $j = json_decode($body, true);
  if (!is_array($j)) {
    $errors[] = 'fr24:json';
    return null;
  }
  return $j;
}

// Fetch live flights inbound/outbound to the airport
$liveData = call_fr24('/live/flight-positions/full', ['airports' => $fr24AirportsParam], $errors);
$liveRows = [];
if ($liveData && isset($liveData['data']) && is_array($liveData['data'])) {
  foreach ($liveData['data'] as $f) {
    // Extract relevant fields
    $flight = $f['flight'] ?? ($f['callsign'] ?? null);
    $flight = $flight ? strtoupper($flight) : null;
    if (!$flight) continue;
    $airline = $f['painted_as'] ?? ($f['operating_as'] ?? null);
    // Determine origin/destination
    $orig = strtoupper((string)($f['orig_iata'] ?? $f['orig_icao'] ?? ''));
    $dest = strtoupper((string)($f['dest_iata'] ?? $f['dest_icao'] ?? ''));
    $eta = $f['eta'] ?? null;
    // normalize eta to ISO
    if ($eta) {
      $ts = strtotime($eta);
      $eta = $ts ? gmdate('c', $ts) : null;
    }
    $liveRows[$flight] = [
      'flight_iata' => $flight,
      'airline_name' => $airline,
      'dep_iata' => $orig,
      'arr_iata' => $dest,
      'eta_utc' => $eta,
    ];
  }
}

// === Merge schedule and live data ===
$final = [];
// Map schedule by flight_iata + sta for uniqueness
$scheduleMap = [];
foreach ($scheduleRows as $row) {
  $key = ($row['flight_iata'] ?? '') . '|' . ($row['sta_utc'] ?? '');
  $scheduleMap[$key] = $row;
}

// Add or update scheduled flights
foreach ($scheduleMap as $key => $row) {
  $flightCode = $row['flight_iata'];
  $sta = $row['sta_utc'];
  $eta = null;
  $status = 'scheduled';
  $delay = $row['delay_min'] ?? null;
  $scheduleStatus = strtolower($row['status'] ?? 'scheduled');
  // Determine if there is live data for this flight
  if (isset($liveRows[$flightCode])) {
    $live = $liveRows[$flightCode];
    $eta = $live['eta_utc'] ?? null;
    // Detect diversion: if scheduled arrival IATA differs from live arrival
    $schedArr = strtoupper((string)($row['arr_iata'] ?? ''));
    $liveArr  = strtoupper((string)($live['arr_iata'] ?? ''));
    if ($schedArr && $liveArr && $schedArr !== $liveArr) {
      $status = 'diverted';
    } else {
      $status = 'active';
    }
    // Compute delay if possible (override DB delay)
    if ($sta && $eta) {
      $staTs = strtotime($sta);
      $etaTs = strtotime($eta);
      if ($staTs && $etaTs) {
        $delay = (int)round(($etaTs - $staTs) / 60);
      }
    }
  } else {
    // No live data; honour DB status if cancelled
    if ($scheduleStatus === 'cancelled') {
      $status = 'cancelled';
    } else {
      // If scheduled time is in the past by more than an hour, mark as landed
      if ($sta) {
        $staTs = strtotime($sta);
        if ($staTs && $staTs < time() - 3600) {
          $status = 'landed';
        }
      }
    }
  }
  $final[] = [
    'flight_iata' => $flightCode,
    'airline_name' => $row['airline_name'] ?? null,
    'dep_iata' => $row['dep_iata'] ?? null,
    'arr_iata' => $row['arr_iata'] ?? null,
    'sta_utc' => $sta,
    'eta_utc' => $eta,
    'ata_utc' => null,
    'status' => $status,
    'delay_min' => $delay,
    'terminal' => $row['terminal'] ?? null,
    'gate' => $row['gate'] ?? null,
  ];
}
// Add live flights not present in schedule (unknown flights)
foreach ($liveRows as $flight => $live) {
  // If flight not in schedule, include as unknown
  $exists = false;
  foreach ($final as $r) {
    if (isset($r['flight_iata']) && $r['flight_iata'] === $flight) {
      $exists = true; break;
    }
  }
  if (!$exists) {
    // Live flights not present in the schedule are typically charters or
    // unscheduled operations.  Treat them as active for display purposes.
    $final[] = [
      'flight_iata' => $live['flight_iata'],
      'airline_name' => $live['airline_name'],
      'dep_iata' => $live['dep_iata'],
      'arr_iata' => $live['arr_iata'],
      'sta_utc' => null,
      'eta_utc' => $live['eta_utc'],
      'ata_utc' => null,
      'status' => 'active',
      'delay_min' => null,
      'terminal' => null,
      'gate' => null,
    ];
  }
}

// Apply status filter if provided (comma-separated list)
if ($statusFilter) {
  $allowed = array_map('strtolower', array_filter(array_map('trim', explode(',', $statusFilter))));
  $final = array_filter($final, function($r) use($allowed) {
    return in_array(strtolower($r['status'] ?? ''), $allowed, true);
  });
}

// Output result
jexit([
  'ok' => true,
  'window' => ['from' => $from_iso, 'to' => $to_iso],
  'count' => count($final),
  'errors' => $errors,
  'rows' => array_values($final)
]);