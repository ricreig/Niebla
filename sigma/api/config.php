<?php
declare(strict_types=1);

// Definir aquí las constantes de FR24
$env_fr24 = getenv('FR24_API_TOKEN') ?: '';
if (!defined('FR24_API_TOKEN')) {
    define('FR24_API_TOKEN', $env_fr24 ?: '019a797b-2593-7072-b590-25dd7c326041|whPw4X68F8Uo5vaVvKPPoCUrjlDD1hgToO4MJ6O12a11d1ce');
}
if (!defined('FR24_API_BASE')) {
    define('FR24_API_BASE', 'https://fr24api.flightradar24.com/api');
}
if (!defined('FR24_API_VERSION')) {
    define('FR24_API_VERSION', 'v1');
}

return [
  'DB' => [
    'HOST'    => 'mysql.hostinger.mx',
    'USER'    => 'u695435470_sigma',
    'PASS'    => 'Seneam@mmtj25',
    'NAME'    => 'u695435470_sigma',
    'CHARSET' => 'utf8mb4',
  ],

  // Rutas de filesystem
  'ROOT_TIMETABLE' => '/home/u695435470/domains/atiscsl.esy.es/public_html/timetable',
  'ROOT_MMTJ_FOG'  => '/home/u695435470/domains/atiscsl.esy.es/public_html/mmtj_fog',
  'ROOT_SIGMA'     => '/home/u695435470/domains/atiscsl.esy.es/public_html/sigma',

  // Rutas web (para base_url() + .../api/*.php)
  'URL_TIMETABLE'  => '/timetable',
  'URL_MMTJ_FOG'   => '/mmtj_fog',
  'URL_SIGMA'      => '/sigma',

  // Defaults
  'IATA' => 'TIJ',
  'ICAO' => 'MMTJ',
  'DEFAULT_WINDOW_HOURS' => 12,
  'CACHE_TTL' => 90,
  'timezone' => 'America/Tijuana',
  'icao' => 'MMTJ',
  'urls' => [
    'avs'   => 'https://ctareig.com/timetable/api/avs.php',
    'fri'   => 'https://ctareig.com/mmtj_fog/public/api/fri.json',
    'metar' => 'https://ctareig.com/mmtj_fog/data/metar.json',
    'taf'   => 'https://ctareig.com/mmtj_fog/data/taf.json',
  ],
    'AVS_BASE' => 'https://api.aviationstack.com/v1',
    'AVS_KEY'  => '255f4bd5853f12734cf91e1053fc31a8',
];
