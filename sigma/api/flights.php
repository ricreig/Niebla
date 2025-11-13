<?php
declare(strict_types=1);

/* ========== Utilidades sin dependencias externas ========== */
function jres($data, int $code=200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
function int_param(string $k, int $def): int {
  $v = isset($_GET[$k]) ? (int)$_GET[$k] : $def;
  return $v > 0 ? $v : $def;
}
function origin_base(): string {
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme.'://'.$host;
}

/* Busca recursivamente el primer arreglo de filas (lista de hashes) */
function find_first_list($node) {
  if (is_array($node)) {
    $isList = array_keys($node) === range(0, count($node)-1);
    if ($isList && isset($node[0]) && is_array($node[0])) return $node;
    foreach ($node as $v) {
      $got = find_first_list($v);
      if ($got !== null) return $got;
    }
  }
  return null;
}

/* Busca un valor por candidatos de clave en profundidad */
function find_key_deep(array $a, array $cands) {
  $stack = [$a];
  $candsLower = array_map('strtolower', $cands);
  while ($stack) {
    $n = array_pop($stack);
    foreach ($n as $k => $v) {
      if (is_string($k) && in_array(strtolower($k), $candsLower, true)) return $v;
      if (is_array($v)) $stack[] = $v;
    }
  }
  return null;
}

/* Normaliza tiempos a ISO8601Z */
function norm_time($v): ?string {
  if (!$v) return null;
  if (is_numeric($v)) return gmdate('c', (int)$v);
  if (is_string($v)) {
    // aviationstack suele venir ISO; también soporta “2025-11-07 14:20:00”
    $t = strtotime(str_replace('T', ' ', $v));
    return $t ? gmdate('c', $t) : null;
  }
  return null;
}
function map_status($s): string {
  // Canonicalise various provider status values into a few common ones.
  $t = strtolower((string)$s);
  if (in_array($t, ['landed','arrived','arrival'], true)) return 'landed';
  if (in_array($t, ['active','airborne','en-route','enroute'], true)) return 'active';
  if (in_array($t, ['diverted','alternate','rerouted'], true)) return 'diverted';
  if (in_array($t, ['cancelled','canceled','cancld','cncl'], true)) return 'cancelled';
  if (in_array($t, ['scheduled','sched','programado'], true)) return 'scheduled';
  return $t ?: 'scheduled';
}

/* ========== Entrada ========== */
$hours = int_param('hours', 12);

/* ========== Fuente timetables integrada (FR24 + AVS) ========== */
// Construct the URL to the combined timetable proxy.  We always fetch
// arrivals for TIJ in the next $hours hours starting from now.  The
// timetable API returns a list under `rows`.
$frUrl = origin_base()."/timetable/api/fr24.php?arr_iata=TIJ&type=arrival&start=now&hours={$hours}&ttl=5";
$ctx  = stream_context_create(['http'=>['timeout'=>8]]);
$raw  = @file_get_contents($frUrl, false, $ctx);
if ($raw === false) jres(['ok'=>false,'error'=>'timetable_unreachable','url'=>$frUrl], 502);

$root = json_decode($raw, true);
if (!is_array($root)) jres(['ok'=>false,'error'=>'timetable_invalid_json'], 502);

/* Soporte para varios envoltorios: data | rows | result | lista directa | anidado */
$rows = [];
if (isset($root['data'])   && is_array($root['data']))   $rows = $root['data'];
elseif (isset($root['rows'])   && is_array($root['rows']))   $rows = $root['rows'];
elseif (isset($root['result']) && is_array($root['result'])) $rows = $root['result'];
else $rows = find_first_list($root) ?? [];

/* ========== Normalización robusta por heurística ========== */
$out = [];
foreach ($rows as $r) {
  if (!is_array($r)) continue;

  // If this row comes from the FR24/timetable proxy (flat structure), use
  // direct keys.  Detect by presence of sta_utc or eta_utc fields.
  if (isset($r['sta_utc']) || isset($r['eta_utc']) || isset($r['flight_iata'])) {
    $sta = norm_time($r['sta_utc'] ?? null);
    $eta = norm_time($r['eta_utc'] ?? null);
    $ata = norm_time($r['ata_utc'] ?? null);
    $dep_iata = strtoupper((string)($r['dep_iata'] ?? ''));
    if (!preg_match('/^[A-Z]{3}$/', $dep_iata)) $dep_iata = '';
    $flt_iata = strtoupper((string)($r['flight_iata'] ?? ''));
    // The first 2-3 letters are airline ICAO; derive flight ICAO if possible
    $aln_icao = '';
    $flt_icao = '';
    if ($flt_iata) {
      // Separate letters and digits
      if (preg_match('/^([A-Z]{2,4})(\d+)/', $flt_iata, $m)) {
        $aln_icao = $m[1];
        $flt_icao = $flt_iata;
      } else {
        $flt_icao = $flt_iata;
      }
    }
    $statusRaw = strtolower((string)($r['status'] ?? 'scheduled'));
    $delay  = is_numeric($r['delay_min'] ?? null) ? (int)$r['delay_min'] : 0;
    $out[] = [
      'eta_utc'       => $eta ?: ($sta ?: null),
      'sta_utc'       => $sta,
      'ata_utc'       => $ata,
      'dep_iata'      => $dep_iata,
      'delay_min'     => $delay,
      'status'        => $statusRaw,
      'flight_icao'   => $flt_icao,
      'flight_number' => $flt_iata,
      'airline_icao'  => $aln_icao,
      'fri_pct'       => -1,
      'eet_min'       => null,
    ];
    continue;
  }

  // Otherwise fall back to the AVS-style nested parsing
  // Filtra codeshare si lo marca la fuente
  $share = find_key_deep($r, ['codeshared','codeshare','shared']);
  if ($share) continue;

  $arrival = find_key_deep($r, ['arrival','arr']) ?: [];
  $depart  = find_key_deep($r, ['departure','depart','dep']) ?: [];
  $flight  = find_key_deep($r, ['flight','flt']) ?: [];
  $airline = find_key_deep($r, ['airline','operator','carrier','op']) ?: [];

  $eta = norm_time(find_key_deep((array)$arrival, ['estimatedTime','estimated','eta','arrival_estimated']));
  $sta = norm_time(find_key_deep((array)$arrival, ['scheduledTime','scheduled','sta','arrival_scheduled']));
  $ata = norm_time(find_key_deep((array)$arrival, ['actualTime','actual','ata','arrival_actual']));

  $dep_iata = strtoupper((string)(
      find_key_deep((array)$depart, ['iataCode','iata','origin_iata','from_iata'])
      ?? find_key_deep($r, ['dep_iata','origin_iata','from'])
      ?? ''
  ));
  if (!preg_match('/^[A-Z]{3}$/', $dep_iata)) $dep_iata = '';

  $aln_icao = strtoupper((string)(
      find_key_deep((array)$airline, ['icaoCode','icao','airline_icao','carrier_icao']) ?? ''
  ));
  if ($aln_icao && !preg_match('/^[A-Z]{2,4}$/', $aln_icao)) $aln_icao = '';

  $num = strtoupper((string)(
      find_key_deep((array)$flight, ['number','no','num']) ?? ''
  ));

  $flt_icao = strtoupper((string)(
      find_key_deep((array)$flight, ['icaoNumber','icao','flight_icao']) ?? ''
  ));
  if (!$flt_icao && $aln_icao && $num) {
    $flt_icao = $aln_icao . preg_replace('/^[A-Z]+/','', $num);
  }
  if ($flt_icao && !preg_match('/^[A-Z]{2,4}\d{1,4}$/', $flt_icao)) {
    // si vino algo raro, intenta tomar flight.iata como respaldo visual
    $flt_icao = strtoupper((string)(find_key_deep((array)$flight, ['iata']) ?? ''));
  }

  $status = map_status(
    find_key_deep($r, ['status','state','flight_status']) ?? 'scheduled'
  );
  $delay  = (int)(find_key_deep((array)$arrival, ['delay','delayed','delay_min']) ?? 0);

  $out[] = [
    'eta_utc'       => $eta ?: ($sta ?: null),
    'sta_utc'       => $sta,
    'ata_utc'       => $ata,
    'dep_iata'      => $dep_iata,
    'delay_min'     => $delay,
    'status'        => $status,
    'flight_icao'   => $flt_icao,
    'flight_number' => $num,
    'airline_icao'  => $aln_icao,
    'fri_pct'       => -1,
    'eet_min'       => null,
  ];
}

/* ========== Salida ========== */
jres([
  'ok'   => true,
  'from' => gmdate('c'),
  'to'   => gmdate('c', time()+$hours*3600),
  'rows' => $out
]);