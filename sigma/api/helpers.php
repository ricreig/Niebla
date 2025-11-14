<?php
declare(strict_types=1);

if (!function_exists('cfg')) {
  function cfg(): array {
    static $c=null; if($c!==null) return $c;
    $c = require __DIR__.'/config.php';
    return $c;
  }
}
if (!function_exists('json_response')) {
  function json_response($data,int $code=200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
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
