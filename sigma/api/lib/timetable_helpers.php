<?php
declare(strict_types=1);

if (!function_exists('timetable_normalize_code')) {
function timetable_normalize_code(?string $code): string {
    if ($code === null) {
        return '';
    }
    $code = strtoupper(trim($code));
    return preg_replace('/\s+/', '', $code);
}
}

if (!function_exists('timetable_dedup_key')) {
function timetable_dedup_key(array $row): string {
    $flight = timetable_normalize_code($row['flight_icao'] ?? $row['flight_iata'] ?? $row['flight_number'] ?? $row['callsign'] ?? '');
    if ($flight !== '' && preg_match('/^\d+$/', $flight) && !empty($row['airline_icao'])) {
        $flight = timetable_normalize_code($row['airline_icao'] . $flight);
    }
    if ($flight === '' && !empty($row['registration'])) {
        $flight = timetable_normalize_code($row['registration']);
    }
    if ($flight === '' && !empty($row['airline_icao']) && !empty($row['flight_number'])) {
        $flight = timetable_normalize_code($row['airline_icao'] . $row['flight_number']);
    }
    $sta = isset($row['sta_utc']) ? (string)$row['sta_utc'] : (isset($row['std_utc']) ? (string)$row['std_utc'] : ($row['eta_utc'] ?? ''));
    $dep = timetable_normalize_code($row['dep_iata'] ?? $row['dep_icao'] ?? '');
    return $flight . '|' . $sta . '|' . $dep;
}
}

if (!function_exists('timetable_status_score')) {
function timetable_status_score($status): int {
    $t = strtolower(trim((string)$status));
    if ($t === 'landed' || $t === 'arrived') return 4;
    if ($t === 'diverted' || $t === 'alternate') return 3;
    if ($t === 'active' || $t === 'en-route' || $t === 'enroute') return 2;
    if ($t === 'taxi') return 1;
    return 0;
}
}

if (!function_exists('timetable_merge_duplicate_rows')) {
function timetable_merge_duplicate_rows(array $rows): array {
    $groups = [];
    $keyIndex = [];
    $timeIndex = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $primaryKey = timetable_dedup_key($row);
        $registrationKey = '';
        if (!empty($row['registration'])) {
            $sta = isset($row['sta_utc']) ? (string)$row['sta_utc'] : (isset($row['std_utc']) ? (string)$row['std_utc'] : ($row['eta_utc'] ?? ''));
            $registrationKey = 'REG|' . timetable_normalize_code($row['registration']) . '|' . $sta;
        }
        $timeKey = '';
        if (!empty($row['sta_utc']) || !empty($row['std_utc']) || !empty($row['eta_utc'])) {
            $staTime = (string)($row['sta_utc'] ?? $row['std_utc'] ?? $row['eta_utc'] ?? '');
            $timeKey = $staTime . '|' . timetable_normalize_code($row['dep_iata'] ?? $row['dep_icao'] ?? '');
        }
        $groupId = null;
        if ($primaryKey && isset($keyIndex[$primaryKey])) {
            $groupId = $keyIndex[$primaryKey];
        } elseif ($registrationKey && isset($keyIndex[$registrationKey])) {
            $groupId = $keyIndex[$registrationKey];
        } elseif ($timeKey && isset($timeIndex[$timeKey]) && (!empty($row['registration']) || strtolower((string)($row['status'] ?? '')) === 'taxi')) {
            $groupId = $timeIndex[$timeKey];
        }
        if ($groupId === null) {
            $groupId = count($groups);
            $groups[$groupId] = [];
            if ($primaryKey && $primaryKey !== '||') {
                $keyIndex[$primaryKey] = $groupId;
            }
            if ($registrationKey) {
                $keyIndex[$registrationKey] = $groupId;
            }
            if ((!$primaryKey || $primaryKey === '||') && !$registrationKey) {
                $fallback = md5(json_encode($row));
                $keyIndex[$fallback] = $groupId;
            }
            if ($timeKey && !isset($timeIndex[$timeKey])) {
                $timeIndex[$timeKey] = $groupId;
            }
        }
        $groups[$groupId][] = $row;
    }
    $result = [];
    foreach ($groups as $items) {
        if (count($items) === 1) {
            $result[] = $items[0];
            continue;
        }
        usort($items, function ($a, $b) {
            $scoreA = (!empty($a['registration']) ? 4 : 0) + (!empty($a['sta_utc']) ? 2 : 0) + timetable_status_score($a['status'] ?? null);
            $scoreB = (!empty($b['registration']) ? 4 : 0) + (!empty($b['sta_utc']) ? 2 : 0) + timetable_status_score($b['status'] ?? null);
            if ($scoreA === $scoreB) {
                $tsA = isset($a['eta_utc']) ? strtotime((string)$a['eta_utc']) : 0;
                $tsB = isset($b['eta_utc']) ? strtotime((string)$b['eta_utc']) : 0;
                return $tsA <=> $tsB;
            }
            return $scoreB <=> $scoreA;
        });
        $result[] = $items[0];
    }
    return array_values($result);
}
}
