<?php
declare(strict_types=1);

if (!function_exists('cfg')) {
  function cfg(): array {
    static $c = null;
    if ($c !== null) {
      return $c;
    }
    $c = require __DIR__ . '/config.php';
    return $c;
  }
}
if (!function_exists('json_response')) {
  function json_response($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

if (!function_exists('sigma_timezone_name_for_airport')) {
  function sigma_timezone_name_for_airport(string $code): ?string
  {
    static $map = [
      'TIJ'  => 'America/Tijuana',
      'MMTJ' => 'America/Tijuana',
      'MXL'  => 'America/Tijuana',
      'MMML' => 'America/Tijuana',
      'PPE'  => 'America/Hermosillo',
      'MMPE' => 'America/Hermosillo',
      'HMO'  => 'America/Hermosillo',
      'MMHO' => 'America/Hermosillo',
      'GYM'  => 'America/Hermosillo',
      'MMGM' => 'America/Hermosillo',
    ];

    $code = strtoupper(trim((string)$code));
    if ($code === '') {
      return null;
    }

    return $map[$code] ?? null;
  }
}

if (!function_exists('sigma_timezone_for_airport')) {
  function sigma_timezone_for_airport(string $code): ?DateTimeZone
  {
    static $cache = [];

    $code = strtoupper(trim((string)$code));
    if ($code === '') {
      return null;
    }

    if (array_key_exists($code, $cache)) {
      return $cache[$code];
    }

    $name = sigma_timezone_name_for_airport($code);
    if ($name === null) {
      $cache[$code] = null;
      return null;
    }

    try {
      $cache[$code] = new DateTimeZone($name);
    } catch (Throwable $e) {
      $cache[$code] = null;
    }

    return $cache[$code];
  }
}

if (!function_exists('sigma_timezone')) {
  function sigma_timezone(): DateTimeZone
  {
    static $tz = null;

    if ($tz instanceof DateTimeZone) {
      return $tz;
    }

    $cfg = cfg();
    $candidates = [];

    $cfgTz = trim((string)($cfg['timezone'] ?? ''));
    if ($cfgTz !== '' && strcasecmp($cfgTz, 'UTC') !== 0 && strcasecmp($cfgTz, 'GMT') !== 0 && stripos($cfgTz, 'Etc/UTC') === false) {
      $candidates[] = $cfgTz;
    }

    $iata = strtoupper(trim((string)($cfg['IATA'] ?? '')));
    $icao = strtoupper(trim((string)($cfg['ICAO'] ?? ($cfg['icao'] ?? ''))));

    foreach ([$iata, $icao] as $code) {
      if ($code === '') {
        continue;
      }
      $name = sigma_timezone_name_for_airport($code);
      if ($name !== null) {
        $candidates[] = $name;
      }
    }

    if ($cfgTz !== '') {
      $candidates[] = $cfgTz;
    }

    $candidates[] = 'America/Tijuana';
    $candidates[] = 'UTC';

    $chosen = null;
    foreach ($candidates as $name) {
      try {
        $chosen = new DateTimeZone($name);
        break;
      } catch (Throwable $e) {
        continue;
      }
    }

    if (!$chosen) {
      $chosen = new DateTimeZone('UTC');
    }

    $tz = $chosen;
    // Garantiza que funciones sin timezone explícito respeten la zona local.
    try {
      date_default_timezone_set($tz->getName());
    } catch (Throwable $e) {
      // Ignorar fallas; PHP mantendrá el timezone previo.
    }

    return $tz;
  }
}

if (!function_exists('sigma_stream_stdout')) {
  /**
   * Devuelve un stream válido para stdout sin importar si el script corre
   * via CLI, FPM o web.  Si no se puede obtener un stream utilizable devuelve
   * null para que el caller pueda hacer fallback a error_log().
   *
   * @return resource|null
   */
  function sigma_stream_stdout()
  {
    if (defined('STDOUT')) {
      return STDOUT;
    }
    static $stdout = null;
    if ($stdout === null) {
      $stdout = @fopen('php://output', 'wb');
      if ($stdout === false) {
        $stdout = null;
      }
    }
    return $stdout;
  }
}

if (!function_exists('sigma_stream_stderr')) {
  /**
   * Devuelve un stream válido para stderr en CLI o ambiente web.
   *
   * @return resource|null
   */
  function sigma_stream_stderr()
  {
    if (defined('STDERR')) {
      return STDERR;
    }
    static $stderr = null;
    if ($stderr === null) {
      $stderr = @fopen('php://stderr', 'wb');
      if ($stderr === false) {
        // Algunos ambientes (p.ej. FPM) no tienen stderr, hacemos fallback.
        $stderr = sigma_stream_stdout();
      }
    }
    return $stderr;
  }
}

if (!function_exists('sigma_stdout')) {
  function sigma_stdout(string $message): void
  {
    $stream = sigma_stream_stdout();
    if (is_resource($stream)) {
      fwrite($stream, $message);
    } else {
      error_log($message);
    }
  }
}

if (!function_exists('sigma_stderr')) {
  function sigma_stderr(string $message): void
  {
    $stream = sigma_stream_stderr();
    if (is_resource($stream)) {
      fwrite($stream, $message);
    } else {
      error_log($message);
    }
  }
}
