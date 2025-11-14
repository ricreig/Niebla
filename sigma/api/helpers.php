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