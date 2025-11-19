<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/timetable_helpers.php';

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

function window_bounds(int $hours, ?string $startIso): array {
  $startTs = null;
  if ($startIso) {
    $ts = strtotime($startIso);
    if ($ts !== false) {
      $startTs = $ts;
    }
  }
  if ($startTs === null) {
    $startTs = time();
  }
  if ($hours < 1) {
    $hours = 1;
  }
  $endTs = $startTs + ($hours * 3600);
  return [$startTs, $endTs];
}

function row_within_window(array $row, int $startTs, int $endTs): bool {
  $candidates = [];
  foreach (['eta_utc','sta_utc','ata_utc'] as $field) {
    if (!empty($row[$field])) {
      $ts = strtotime((string)$row[$field]);
      if ($ts !== false) {
        $candidates[] = $ts;
      }
    }
  }
  if (!$candidates) {
    // Sin hora utilizable: mantener el vuelo para evitar perder información.
    return true;
  }
  foreach ($candidates as $ts) {
    if ($ts >= $startTs && $ts <= $endTs) {
      return true;
    }
  }
  return false;
}

/* ========== Entrada ========== */
// Number of hours to search. Defaults to 12 if not provided or invalid.
$hours = int_param('hours', 12);

// Optional start time (ISO8601 or 'now'). When provided, the API will
// determine whether to use the local timetable (AVS + FR24 live) or
// fallback to FR24 flight-summary for historical dates.  When the
// requested window ends before the beginning of the current UTC day, the
// timetable table will not have schedules (aviationstack only provides
// current-day), so we fetch historical activity via the FR24 summary.
$start = $_GET['start'] ?? null;
// Normalise start parameter; handle 'now' specially.
$use_summary = false;
$startIso = null;
if ($start && strtolower($start) !== 'now') {
  // Ensure Z suffix (UTC). Accept both with and without trailing timezone offset.
  $startIso = preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/i', $start) ? $start : $start.'Z';
  $ts = strtotime($startIso);
  if ($ts !== false) {
    $startOfToday = strtotime(gmdate('Y-m-d').' 00:00:00 UTC');
    if ($startOfToday !== false) {
      $endTs = $ts + ($hours * 3600);
      if ($endTs <= $startOfToday) {
        $use_summary = true;
      }
    }
  }
}

// Determina la ventana solicitada (timestamps UTC)
[$windowStartTs, $windowEndTs] = window_bounds($hours, $startIso);
$windowStartIso = gmdate('c', $windowStartTs);
$windowEndIso   = gmdate('c', $windowEndTs);

/* ========== Recolección de vuelos ========== */
// Siempre construimos una lista de vuelos en $out.  Dependiendo del valor
// de $use_summary usamos FR24 summary (histórico) o la fuente combinada
// timetable (AVS + FR24) para fechas actuales.
$out = [];
if ($use_summary) {
  // ================= FR24 summary (sin AviationStack) ==================
  // Para fechas anteriores al día actual, FR24 summary es la única
  // fuente de vuelos; recuperamos todos los vuelos que hayan aterrizado
  // en TIJ durante la ventana especificada.  AviationStack no provee
  // horarios históricos.
  try {
    $token = getenv('FR24_API_TOKEN');
    if (!$token && defined('FR24_API_TOKEN')) {
      $token = FR24_API_TOKEN;
    }
    if ($token) {
      // Determine range: use provided startIso and hours
      $fromIso = $startIso ?: gmdate('Y-m-d\TH:i:s\Z');
      $fromTS  = strtotime($fromIso) ?: time();
      $toTS    = $fromTS + $hours * 3600;
      // Limit range to 14 days per FR24 documentation
      $maxEnd  = strtotime('+14 days', $fromTS);
      if ($toTS > $maxEnd) $toTS = $maxEnd;
      $toIso   = gmdate('Y-m-d\TH:i:s\Z', $toTS);
      $summaryURL = "https://fr24api.flightradar24.com/api/flight-summary/full?airports=inbound:TIJ&flight_datetime_from={$fromIso}&flight_datetime_to={$toIso}";
      $ctxOpts = [
        'http' => [
          'method'  => 'GET',
          'header'  => "Accept: application/json\r\nAccept-Version: v1\r\nAuthorization: Bearer {$token}\r\n",
          'timeout' => 8,
        ],
      ];
      $sumRaw = @file_get_contents($summaryURL, false, stream_context_create($ctxOpts));
      $sumJson = $sumRaw !== false ? json_decode($sumRaw, true) : null;
      if (is_array($sumJson) && isset($sumJson['data']) && is_array($sumJson['data'])) {
        foreach ($sumJson['data'] as $info) {
          if (!is_array($info)) continue;
          // Skip codeshare rows: if painted_as and operating_as are both present and differ, this entry is a marketing codeshare.
          $paintedAs   = strtoupper((string)($info['painted_as'] ?? ''));
          $operatingAs = strtoupper((string)($info['operating_as'] ?? ''));
          if ($paintedAs && $operatingAs && $paintedAs !== $operatingAs) {
            continue;
          }
          // Determine actual arrival time and departure code
          $eta = norm_time($info['datetime_landed'] ?? $info['datetime_land'] ?? $info['datetimeArrival'] ?? null);
          $ata = $eta; // actual arrival time equals ETA for landed flights
          $sta = null; // schedule unknown for past days
          $depIata = strtoupper((string)($info['orig_iata'] ?? $info['origin_iata'] ?? substr((string)($info['orig_icao'] ?? ''), -3)));
          if (!preg_match('/^[A-Z]{3}$/', $depIata)) $depIata = '';
          // Determine flight identifiers.  Prefer callsign (ICAO) when present.
          $call    = strtoupper((string)($info['callsign'] ?? ''));
          $fltIcao = strtoupper((string)($info['flight_icao'] ?? ''));
          $fltNum  = strtoupper((string)($info['flight'] ?? $info['flight_number'] ?? ''));
          // If callsign appears to be a valid ICAO flight code (letters + digits), use it as flight_icao.
          if ($call && preg_match('/^[A-Z]{2,4}\d+$/', $call)) {
            $fltIcao = $call;
          }
          // Derive airline ICAO from the flight_icao prefix or operating_as
          $alnIcao = '';
          if ($fltIcao && preg_match('/^([A-Z]{2,4})(\d+)/', $fltIcao, $m)) {
            $alnIcao = $m[1];
          } elseif ($operatingAs) {
            $alnIcao = $operatingAs;
          }
          // Status: landed by default
          $statusRaw = 'landed';
          // If dest actual different from TIJ, mark diverted
          $actualDest = strtoupper((string)($info['dest_iata_actual'] ?? $info['dest_icao_actual'] ?? ''));
          $destActual = null;
          if ($actualDest && $actualDest !== 'TIJ') {
            $statusRaw = 'diverted';
            $destActual = $actualDest;
          }
          // Registration (tail number)
          $reg = isset($info['reg']) && $info['reg'] !== null ? strtoupper((string)$info['reg']) : null;
          // Estimated enroute time (minutes) from flight_time or difference between takeoff and landing
          $flightTime = null;
          if (isset($info['flight_time']) && is_numeric($info['flight_time'])) {
            $flightTime = (int)$info['flight_time'];
          } elseif (isset($info['datetime_takeoff']) && isset($info['datetime_landed'])) {
            $flightTime = (int)$info['datetime_landed'] - (int)$info['datetime_takeoff'];
          }
          $eetMin = null;
          if ($flightTime !== null && $flightTime > 0) {
            $eetMin = (int)round($flightTime / 60);
          }
          $out[] = [
            'eta_utc'       => $eta,
            'sta_utc'       => $sta,
            'ata_utc'       => $ata,
            'dep_iata'      => $depIata,
            'delay_min'     => 0,
            'status'        => $statusRaw,
            'flight_icao'   => $fltIcao ?: '',
            'flight_number' => $fltNum ?: '',
            'airline_icao'  => $alnIcao ?: '',
            'fri_pct'       => -1,
            'eet_min'       => $eetMin,
            'dest_iata_actual' => $destActual,
            'registration'  => $reg,
          ];
        }
      }
    }
  } catch (Throwable $e) {
    // If summary fails, just return empty list
  }
} else {
  // ================= Combined timetable (current day) ==================
  // Construct the URL to the combined timetable proxy.  Use provided start if set; otherwise default to 'now'.
  $startParam = 'now';
  if ($startIso) {
    // encode ISO start; remove milliseconds if present
    $startParam = urlencode($startIso);
  }
  $frUrl = origin_base()."/timetable/api/fr24.php?arr_iata=TIJ&type=arrival&start={$startParam}&hours={$hours}&ttl=5";
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

  /* Normalización robusta por heurística */
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
      // If the flight is currently active/airborne and has no scheduled arrival, classify as taxi (pre-departure)
      if ($statusRaw === 'active' && !$sta) {
        $statusRaw = 'taxi';
      }
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
        // Destination airport actually flown to (if diverted); filled later
        'dest_iata_actual' => null,
        // Aircraft registration (tail number), filled later from FR24 flight summary
        'registration' => null,
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
    // If status is active and there is no scheduled arrival time (STA), treat as taxi
    if ($status === 'active' && !$sta) {
      $status = 'taxi';
    }
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
      // Destination airport actually flown to (if diverted); filled later
      'dest_iata_actual' => null,
      // Aircraft registration (tail number), filled later from FR24 flight summary
      'registration' => null,
    ];
  }
}

/*
 * Fetch flight summary from FR24 to detect diversions.
 * When a flight's actual destination (dest_iata_actual) differs from TIJ,
 * mark it as diverted and store the actual IATA.  This requires the
 * FR24_API_TOKEN environment variable to be set.  If unavailable or
 * the request fails, the rows remain untouched.  The summary covers
 * flights inbound to TIJ within the window [$now-$hours, $now+$hours].
 */
// Merge in diversion and registration data for current-day flights via FR24 summary.
if (!$use_summary) {
  try {
    // Attempt to get FR24 API token from environment variable; if not set, fall back to defined constant
    $token = getenv('FR24_API_TOKEN');
    if (!$token && defined('FR24_API_TOKEN')) {
      $token = FR24_API_TOKEN;
    }
    if ($token) {
      $nowTS = time();
      // Use a narrow window to limit API cost; FR24 allows up to 14 days range.
      $fromTS = max(0, $nowTS - $hours * 3600);
      $toTS   = $nowTS + $hours * 3600;
      $fromIso= gmdate('Y-m-d\TH:i:s\Z', $fromTS);
      $toIso  = gmdate('Y-m-d\TH:i:s\Z', $toTS);
      $summaryURL = "https://fr24api.flightradar24.com/api/flight-summary/full?airports=inbound:TIJ&flight_datetime_from={$fromIso}&flight_datetime_to={$toIso}";
      $ctxOpts = [
        'http' => [
          'method'  => 'GET',
          'header'  => "Accept: application/json\r\nAccept-Version: v1\r\nAuthorization: Bearer {$token}\r\n",
          'timeout' => 8,
        ],
      ];
      $sumRaw = @file_get_contents($summaryURL, false, stream_context_create($ctxOpts));
      $sumJson = $sumRaw !== false ? json_decode($sumRaw, true) : null;
      if (is_array($sumJson) && isset($sumJson['data']) && is_array($sumJson['data'])) {
            $destLookup = [];
            $regLookup  = [];
            $codeshareMap = [];
        foreach ($sumJson['data'] as $info) {
          if (!is_array($info)) continue;
          // Destination mapping
          $actual = strtoupper((string)($info['dest_iata_actual'] ?? $info['dest_icao_actual'] ?? ''));
          // Registration mapping
          $reg = isset($info['reg']) && $info['reg'] !== null ? strtoupper((string)$info['reg']) : null;
          // Keys for lookups
          $fIcao = strtoupper((string)($info['flight_icao'] ?? ''));
          $fNum  = strtoupper((string)($info['flight'] ?? $info['flight_number'] ?? ''));
          $call  = strtoupper((string)($info['callsign'] ?? ''));
          // Populate destination lookup
          if ($actual) {
            if ($fIcao) $destLookup[$fIcao] = $actual;
            if ($fNum)  $destLookup[$fNum]  = $actual;
            if ($call)  $destLookup[$call]  = $actual;
          }
          // Populate registration lookup
          if ($reg) {
            if ($fIcao) $regLookup[$fIcao] = $reg;
            if ($fNum)  $regLookup[$fNum]  = $reg;
            if ($call)  $regLookup[$call]  = $reg;
          }

              // Build codeshare mapping: when painted_as differs from operating_as,
              // map the codeshare flight code to the real operator's ICAO flight code.  We
              // do this for all known identifiers (flight_icao, flight number, callsign)
              // where the code matches the pattern [A-Z]{2,4}\d+.  The real code is
              // operating_as plus the numeric part of the original.
              $paintedAs   = strtoupper((string)($info['painted_as'] ?? ''));
              $operatingAs = strtoupper((string)($info['operating_as'] ?? ''));
              if ($paintedAs && $operatingAs && $paintedAs !== $operatingAs) {
                $cands = [];
                if ($fIcao) $cands[] = $fIcao;
                if ($fNum)  $cands[] = $fNum;
                if ($call)  $cands[] = $call;
                foreach ($cands as $code) {
                  if (preg_match('/^[A-Z]{2,4}\d+$/', $code)) {
                    $digits = preg_replace('/^[A-Z]{2,4}/','',$code);
                    if ($digits !== '') {
                      $realCode = $operatingAs . $digits;
                      $codeshareMap[$code] = $realCode;
                    }
                  }
                }
              }
        }
        foreach ($out as &$row) {
          $fltIcao = strtoupper((string)($row['flight_icao'] ?? ''));
          $fltNum  = strtoupper((string)($row['flight_number'] ?? ''));
          $actual = null;
          // Lookup destination (diversion)
          if ($fltIcao && isset($destLookup[$fltIcao])) $actual = $destLookup[$fltIcao];
          elseif ($fltNum && isset($destLookup[$fltNum])) $actual = $destLookup[$fltNum];
          // If row is diverted
          if ($actual && $actual !== 'TIJ') {
            $row['dest_iata_actual'] = $actual;
            $row['status'] = 'diverted';
          }
          // Fill registration when flight number missing
          if ((empty($row['flight_number']) || $row['flight_number'] === '' || $row['flight_number'] === null)) {
            $regVal = null;
            if ($fltIcao && isset($regLookup[$fltIcao])) {
              $regVal = $regLookup[$fltIcao];
            } elseif ($fltNum && isset($regLookup[$fltNum])) {
              $regVal = $regLookup[$fltNum];
            }
            if ($regVal) $row['registration'] = $regVal;
          }

              // If this flight appears as a codeshare, rewrite its flight_icao and airline
              // to use the real operator code.  The codeshareMap maps a flight code
              // (flight_icao, flight_number or callsign) to the true ICAO flight code.
              // We check both flight_icao and flight_number; update if found.
              $newIcao = null;
              if ($fltIcao && isset($codeshareMap[$fltIcao])) {
                $newIcao = $codeshareMap[$fltIcao];
              } elseif ($fltNum && isset($codeshareMap[$fltNum])) {
                $newIcao = $codeshareMap[$fltNum];
              }
              if ($newIcao) {
                $row['flight_icao'] = $newIcao;
                // update airline_icao to prefix of newIcao
                if (preg_match('/^([A-Z]{2,4})/', $newIcao, $m)) {
                  $row['airline_icao'] = $m[1];
                }
                // update flight_number to numeric part of newIcao
                $row['flight_number'] = preg_replace('/^[A-Z]{2,4}/','', $newIcao);
              }
              // Mark taxi: if flight is active and has no scheduled arrival (STA), it is taxiing.
              if (strcasecmp((string)$row['status'], 'active') === 0 && empty($row['sta_utc'])) {
                $row['status'] = 'taxi';
              }
        }
        unset($row);

            $out = timetable_merge_duplicate_rows($out);
      }
    }
  } catch (Throwable $e) {
    // swallow exceptions silently
  }
}

/* ========== Filtro final por ventana solicitada ========== */
$out = timetable_merge_duplicate_rows($out);
$out = array_values(array_filter($out, function($row) use ($windowStartTs, $windowEndTs) {
  if (!is_array($row)) return false;
  return row_within_window($row, $windowStartTs, $windowEndTs);
}));

/* ========== Salida ========== */
jres([
  'ok'   => true,
  'from' => $windowStartIso,
  'to'   => $windowEndIso,
  'rows' => $out
]);