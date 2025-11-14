<?php
require_once __DIR__ . '/helpers.php';
$config = require __DIR__.'/config.php';

function db(){
  static $mysqli;
  global $config;
  if(!$mysqli){
    $mysqli = new mysqli(
      $config['DB']['HOST'],
      $config['DB']['USER'],
      $config['DB']['PASS'],
      $config['DB']['NAME']
    );
    if($mysqli->connect_errno){
      http_response_code(500);
      die(json_encode(['error'=>'db_connect','message'=>$mysqli->connect_error]));
    }
    $mysqli->set_charset($config['DB']['CHARSET']);
  }
  return $mysqli;
}

function json_out($data, $status=200){
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

function cache_get($key){
  $file = sys_get_temp_dir().'/sigma_cache_'.md5($key).'.json';
  if(is_file($file) && (time()-filemtime($file) < (require __DIR__.'/config.php')['CACHE_TTL'])){
    return file_get_contents($file);
  }
  return null;
}
function cache_set($key,$content){
  $file = sys_get_temp_dir().'/sigma_cache_'.md5($key).'.json';
  file_put_contents($file,$content);
}
