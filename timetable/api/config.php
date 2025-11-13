<?php
declare(strict_types=1);
// Config key via ENV
$env_key = getenv('AVS_ACCESS_KEY') ?: '';
define('AVS_ACCESS_KEY', $env_key ?: '255f4bd5853f12734cf91e1053fc31a8');
// Endpoints (HTTPS)
define('AVS_ENDPOINT_TIMETABLE', 'https://api.aviationstack.com/v1/timetable');
define('AVS_ENDPOINT_FLIGHTS',   'https://api.aviationstack.com/v1/flights');
define('AVS_ENDPOINT_FUTURE',    'https://api.aviationstack.com/v1/flightsFuture');
define('AVS_LIMIT', 100);
define('CACHE_DIR', __DIR__ . '/cache');
if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
function avs_url(string $endpoint, array $params): string{
  $params['access_key'] = AVS_ACCESS_KEY;
  return $endpoint . '?' . http_build_query($params);
}
function cache_put(string $key, array $payload): void{
  @file_put_contents(CACHE_DIR . '/' . $key . '.json', json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function cache_get(string $key, int $ttl_minutes): ?array{
  $fn = CACHE_DIR . '/' . $key . '.json';
  if (!is_file($fn)) return null;
  if (time() - filemtime($fn) > $ttl_minutes*60) return null;
  $txt = @file_get_contents($fn); if($txt===false) return null;
  $data = json_decode($txt, true); return is_array($data)? $data : null;
}
function keyhash(array $parts): string{ ksort($parts); return hash('sha1', json_encode($parts)); }

// === Flightradar24 API ===
// API token for Flightradar24.  You can set the token via the environment
// variable FR24_API_TOKEN; otherwise a default sandbox token is used.  In
// production this should be kept secret and configured outside of the code.
$env_fr24 = getenv('FR24_API_TOKEN') ?: '';
// Use the production Flightradar24 API token provided by operations.  If
// the environment variable FR24_API_TOKEN is not defined, fall back to
// this static value.  Keeping the token here centralises configuration
// and allows the API to operate even when the web server does not
// propagate environment variables.  NOTE: do not commit real tokens to
// public repositories.
define('FR24_API_TOKEN', $env_fr24 ?: '019a797b-2593-7072-b590-25dd7c326041|whPw4X68F8Uo5vaVvKPPoCUrjlDD1hgToO4MJ6O12a11d1ce');
// Base URL for FR24 API.  Do not include trailing slash.
define('FR24_API_BASE', 'https://fr24api.flightradar24.com/api');
// Default API version; used in Accept-Version header.
define('FR24_API_VERSION', 'v1');

// === Database configuration for schedule storage ===
// These constants allow the timetable API to read flight schedules from the
// central SIGMA database instead of hitting AviationStack on every request.
// They mirror the settings in sigma_unzip/api/config.php.  In production
// these should be set via environment variables or included from a secure
// location.  The defaults here correspond to the provided Hostinger setup.
define('DB_HOST', getenv('DB_HOST') ?: 'mysql.hostinger.mx');
define('DB_USER', getenv('DB_USER') ?: 'u695435470_sigma');
define('DB_PASS', getenv('DB_PASS') ?: 'Seneam@mmtj25');
define('DB_NAME', getenv('DB_NAME') ?: 'u695435470_sigma');
define('DB_CHARSET', 'utf8mb4');

