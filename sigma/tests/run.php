<?php
declare(strict_types=1);
require __DIR__ . '/../api/lib/timetable_helpers.php';

function assert_true(bool $expr, string $msg): void {
    if (!$expr) {
        fwrite(STDERR, "Assertion failed: {$msg}\n");
        exit(1);
    }
}

$rows = [
    [
        'flight_icao' => 'AMX123',
        'sta_utc'     => '2024-07-08T10:00:00Z',
        'eta_utc'     => '2024-07-08T09:55:00Z',
        'dep_iata'    => 'MEX',
        'registration'=> 'XA-AMX',
        'status'      => 'landed',
    ],
    [
        'flight_icao' => 'AMX123',
        'sta_utc'     => '2024-07-08T10:00:00Z',
        'eta_utc'     => '2024-07-08T09:58:00Z',
        'dep_iata'    => 'MEX',
        'status'      => 'taxi',
    ],
    [
        'flight_number' => '123',
        'airline_icao'  => 'AMX',
        'sta_utc'       => '2024-07-08T10:00:00Z',
        'dep_iata'      => 'MEX',
        'registration'  => 'XA-AMX',
        'status'        => 'scheduled',
    ],
];
$dedup = timetable_merge_duplicate_rows($rows);
assert_true(count($dedup) === 1, 'Expected duplicate rows to collapse into one');
assert_true($dedup[0]['registration'] === 'XA-AMX', 'Preferred row should keep registration');
assert_true($dedup[0]['status'] === 'landed', 'Status landed should survive over taxi/scheduled');

$rows2 = [
    [
        'flight_icao' => 'VIV456',
        'sta_utc'     => '2024-07-08T12:00:00Z',
        'dep_iata'    => 'GDL',
        'status'      => 'scheduled',
    ],
    [
        'flight_number' => '456',
        'airline_icao'  => 'VIV',
        'sta_utc'       => '2024-07-08T12:00:00Z',
        'dep_iata'      => 'GDL',
        'status'        => 'scheduled',
    ],
    [
        'registration'  => 'XA-VIV',
        'sta_utc'       => '2024-07-08T12:00:00Z',
        'dep_iata'      => 'GDL',
        'status'        => 'landed',
    ],
];
$dedup2 = timetable_merge_duplicate_rows($rows2);
assert_true(count($dedup2) === 1, 'Registration-based key should dedupe codeshares');
assert_true($dedup2[0]['registration'] === 'XA-VIV', 'Row with registration wins');
assert_true($dedup2[0]['status'] === 'landed', 'Landed state should override scheduled');

echo "All timetable helper tests passed\n";
