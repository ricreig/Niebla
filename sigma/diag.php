<?php
declare(strict_types=1);
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ping($url, $timeout=5){
  $t0 = microtime(true);
  $ch = curl_init();
  curl_setopt_array($ch,[
    CURLOPT_URL=>$url,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_CONNECTTIMEOUT=>$timeout,
    CURLOPT_TIMEOUT=>$timeout,
    CURLOPT_USERAGENT=>'SIGMA-DIAG/1.0',
    CURLOPT_SSL_VERIFYPEER=>false,
  ]);
  $body = curl_exec($ch);
  $err  = curl_errno($ch) ? curl_error($ch) : '';
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
  $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
  curl_close($ch);
  $ms = (int)round((microtime(true)-$t0)*1000);
  return ['ok'=>$code>=200 && $code<400, 'code'=>$code, 'ms'=>$ms, 'bytes'=>strlen((string)$body), 'ct'=>$ct, 'err'=>$err, 'body'=>$body];
}
$root = realpath(__DIR__);
$pub  = $root.'/public';
$checks = [
  'ROOT_SIGMA'=>$root,
  'ROOT_PUBLIC'=>$pub,
  'PHP_VERSION'=>PHP_VERSION,
  'TZ'=>date_default_timezone_get(),
  'ext.curl'=>extension_loaded('curl')?'OK':'MISSING',
  'ext.json'=>extension_loaded('json')?'OK':'MISSING',
  'ext.mbstring'=>extension_loaded('mbstring')?'OK':'MISSING',
];
$host = $_SERVER['HTTP_HOST'] ?? 'ctareig.com';
$http = [
  'fri'    => "https://{$host}/mmtj_fog/public/api/fri.json",
  'metar'  => "https://{$host}/mmtj_fog/data/metar.json",
  'taf'    => "https://{$host}/mmtj_fog/data/taf.json",
  'flights'=> "https://{$host}/sigma/api/flights.php?hours=12",
  'health' => "https://{$host}/sigma/api/health.php",
];
$tailLog = @file_exists(__DIR__.'/error.log') ? trim(implode("\n", array_slice(file(__DIR__.'/error.log'), -200))) : 'N/D';
?>
<!doctype html>
<html lang="es" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnóstico SIGMA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>pre{white-space:pre-wrap}.table-fixed{table-layout:fixed}</style>
</head>
<body class="p-3">
<h1 class="h4">Diagnóstico · SIGMA</h1>

<h2 class="h6 mt-3">PHP y entorno</h2>
<table class="table table-sm table-striped table-fixed"><tbody>
<?php foreach($checks as $k=>$v): ?>
<tr><th style="width:220px"><?=h($k)?></th><td><?=h((string)$v)?></td></tr>
<?php endforeach; ?>
</tbody></table>

<h2 class="h6 mt-3">Rutas del proyecto</h2>
<table class="table table-sm table-striped">
<tbody><tr><th>ROOT_SIGMA</th><td><?=h($root)?></td></tr>
<tr><th>ROOT_PUBLIC</th><td><?=h($pub)?></td></tr></tbody></table>

<h2 class="h6 mt-3">Probes HTTP</h2>
<table class="table table-sm table-striped">
<thead><tr><th>Nombre</th><th>URL</th><th>OK</th><th>ms</th><th>bytes</th><th>CT</th></tr></thead>
<tbody>
<?php foreach($http as $name=>$url): $r=ping($url); ?>
<tr>
  <td><?=h($name)?></td>
  <td><a href="<?=h($url)?>" target="_blank" class="text-decoration-none"><?=h($url)?></a></td>
  <td><?=$r['ok']?'OK':'ERR '.$r['code']?></td>
  <td><?=$r['ms']?></td>
  <td><?=$r['bytes']?></td>
  <td><?=h($r['ct'])?></td>
</tr>
<?php endforeach; ?>
</tbody></table>

<h2 class="h6 mt-3">Validación JSON</h2>
<?php foreach($http as $name=>$url): $r=ping($url); ?>
<div class="mb-3"><div><strong><?=h($name)?></strong></div>
<?php if(!$r['ok']): ?>
  <div class="text-danger">HTTP error <?=h((string)$r['code'])?> <?=h($r['err'])?></div>
<?php else:
  $j = json_decode($r['body'], true);
  if(json_last_error()!==JSON_ERROR_NONE): ?>
    <div class="text-danger">JSON inválido: <?=h(json_last_error_msg())?></div>
  <?php else:
    $keys = implode(', ', array_keys((array)$j)); ?>
    <div class="small text-muted">Keys: <?=h($keys)?></div>
    <pre class="bg-dark p-2 rounded"><?=h(substr($r['body'],0,800))?></pre>
  <?php endif; endif; ?>
</div>
<?php endforeach; ?>

<h2 class="h6 mt-3">error.log (últimas 200 líneas)</h2>
<pre class="bg-dark p-2 rounded"><?=h($tailLog)?></pre>
</body></html>