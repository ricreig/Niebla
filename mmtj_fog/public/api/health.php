<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__, 2);
$data = $root . '/data';

$out = [
  'php_version' => PHP_VERSION,
  'cwd' => getcwd(),
  'root' => $root,
  'writable_data' => is_writable($data),
  'exists_fri_now' => is_file($data.'/fri_now.json'),
  'exists_obs_mmtj' => is_file($data.'/obs/MMTJ.json'),
  'allow_url_fopen' => (bool)ini_get('allow_url_fopen'),
  'curl_available' => function_exists('curl_init'),
  'time_utc' => gmdate('c'),
];
echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
