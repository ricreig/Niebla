<?php
return [
  'icao' => 'MMTJ',
  'lat'  => 32.541,   // MMTJ aprox
  'lon'  => -116.97,  // MMTJ aprox
  'timezone' => 'America/Tijuana',
  'paths' => [
    'metar' => __DIR__ . '/data/metar.json',
    'taf'   => __DIR__ . '/data/taf.json',
    'predictions' => __DIR__ . '/data/predictions.json',
  ],
	 'calib_token' => 'calibracion25',
];
