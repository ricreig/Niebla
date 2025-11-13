<?php
declare(strict_types=1);

/**
 * Diag avanzado · MMTJ Timetable
 * - Verificación de estructura y permisos
 * - Estado de PHP y extensiones
 * - Clave y endpoints de Aviationstack
 * - Probas opcionales de red (curl) y llamada a api/avs.php
 * - Visor de error.log con tail y filtro
 */

header('Content-Type: text/html; charset=utf-8');

$ROOT = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$API  = __DIR__;
$PUB  = $ROOT . '/public';
$ERR  = $ROOT . '/error.log';

require __DIR__ . '/config.php';

// ---------- util ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function exists($p){ return is_file($p) ? 'OK' : (is_dir($p) ? 'DIR' : 'FALTA'); }
function perm($p){ return file_exists($p)? substr(sprintf('%o', fileperms($p)),-4) : '----'; }
function owner($p){ if(!file_exists($p)) return '-'; $u = function_exists('posix_getpwuid')? posix_getpwuid(fileowner($p)) : null; $g = function_exists('posix_getgrgid')? posix_getgrgid(filegroup($p)) : null; return ($u['name']??fileowner($p)).':'.($g['name']??filegroup($p)); }
function can_rw($p){ return (is_file($p) && is_writable($p)) || (is_dir($p) && is_writable($p)); }
function bytes_h($b){ $u=['B','KB','MB','GB']; $i=0; while($b>=1024 && $i<count($u)-1){$b/=1024;$i++;} return sprintf('%.1f %s',$b,$u[$i]); }
function env_or_const($name){
  if(getenv($name)) return ['src'=>'ENV','val'=>getenv($name)];
  if(defined($name)) return ['src'=>'CONST','val'=>constant($name)];
  return ['src'=>'NONE','val'=>null];
}
function tail_file($file, int $lines=200, ?string $grep=null): string {
  if(!is_file($file)) return "archivo no encontrado";
  $f = fopen($file,'rb');
  if(!$f) return "no se pudo abrir";
  $buffer = '';
  $chunk = 4096;
  $pos = -1;
  $linecnt = 0;
  $stat = fstat($f);
  $size = $stat['size'];
  if($size===0){ fclose($f); return ""; }
  $seek = 0;
  while($linecnt <= $lines && $seek < $size){
    $seek += $chunk;
    fseek($f, -$seek, SEEK_END);
    $buffer = fread($f, $chunk) . $buffer;
    $linecnt = substr_count($buffer, "
");
  }
  fclose($f);
  $out = implode("
", array_slice(explode("
", $buffer), -$lines));
  if($grep && $grep!==''){
    $lines = explode("
", $out);
    $rx = @preg_match('/'.$grep.'/', '')!==false ? '/'.$grep.'/' : null;
    $lines = array_values(array_filter($lines, function($l) use ($grep,$rx){
      if($rx!==null) return preg_match($rx, $l);
      return stripos($l, $grep)!==false;
    }));
    $out = implode("
", $lines);
  }
  return $out;
}

$act = $_GET['act'] ?? null;

// ---------- probes opcionales ----------
$probes = [
  'php' => [
    'PHP' => PHP_VERSION.' ('.php_sapi_name().')',
    'timezone' => date_default_timezone_get(),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'file_uploads' => ini_get('file_uploads'),
    'allow_url_fopen' => ini_get('allow_url_fopen'),
  ],
  'ext' => [
    'curl' => extension_loaded('curl')?'OK':'FALTA',
    'json' => extension_loaded('json')?'OK':'FALTA',
    'openssl' => extension_loaded('openssl')?'OK':'FALTA',
    'mbstring' => extension_loaded('mbstring')?'OK':'FALTA',
  ],
  'paths' => [
    'root' => $ROOT,
    'api'  => $API,
    'public' => $PUB,
    'cache'  => $API . '/cache',
    'error_log' => $ERR,
  ],
  'files' => [
    '/public/index.php' => exists($PUB.'/index.php').' '.perm($PUB.'/index.php').' '.owner($PUB.'/index.php'),
    '/public/app.js'    => exists($PUB.'/app.js').' '.perm($PUB.'/app.js').' '.owner($PUB.'/app.js'),
    '/public/styles.css'=> exists($PUB.'/styles.css').' '.perm($PUB.'/styles.css').' '.owner($PUB.'/styles.css'),
    '/public/metar.css' => exists($PUB.'/metar.css').' '.perm($PUB.'/metar.css').' '.owner($PUB.'/metar.css'),
    '/api/config.php'   => exists($API.'/config.php').' '.perm($API.'/config.php').' '.owner($API.'/config.php'),
    '/api/avs.php'      => exists($API.'/avs.php').' '.perm($API.'/avs.php').' '.owner($API.'/avs.php'),
    '/api/cache/'       => (is_dir($API.'/cache')?'DIR':'FALTA').' '.perm($API.'/cache').' '.owner($API.'/cache'),
    '/error.log'        => exists($ERR).' '.(file_exists($ERR)?perm($ERR):'----').' '.(file_exists($ERR)?owner($ERR):'-'),
  ]
];

$free = disk_free_space($ROOT);
$total= disk_total_space($ROOT);
$probes['disk'] = ['free'=>bytes_h($free), 'total'=>bytes_h($total)];

// AVS key
$keyInfo = env_or_const('AVS_ACCESS_KEY');
$probes['avs_key'] = ['source'=>$keyInfo['src'], 'present'=> (bool)$keyInfo['val'] && $keyInfo['val']!=='REEMPLAZA_CON_TU_CLAVE'];

// Quick curl info
if(extension_loaded('curl')){
  $cv = curl_version();
  $probes['curl'] = ['version'=>$cv['version'],'ssl_version'=>$cv['ssl_version'] ?? null,'libz'=>$cv['libz_version'] ?? null];
}

// Endpoint probes on demand
$ping = null;
if(isset($_GET['probe']) && $_GET['probe']==='1' && $keyInfo['val']){
  $endpoints = [
    'timetable' => AVS_ENDPOINT_TIMETABLE,
    'flights'   => AVS_ENDPOINT_FLIGHTS,
    'future'    => AVS_ENDPOINT_FUTURE,
  ];
  $ping = [];
  foreach($endpoints as $name=>$url){
    $u = $url.'?access_key='.rawurlencode($keyInfo['val']).'&limit=1';
    $ch = curl_init($u);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ping[$name] = ['code'=>$code,'err'=>$err?:null, 'snippet'=> $body ? substr($body,0,180) : null];
  }
}

// Tail error.log on demand
$tail = null;
if(isset($_GET['log'])){
  $lines = max(10, min(5000, (int)($_GET['lines'] ?? 400)));
  $grep  = $_GET['grep'] ?? null;
  $tail = tail_file($ERR, $lines, $grep);
}

// Quick call to local avs.php
$local_call = null;
if(isset($_GET['call']) && $_GET['call']==='1'){
  $arr = $_GET['arr'] ?? 'TIJ';
  $type= $_GET['type'] ?? 'arrival';
  $hours = (int)($_GET['hours'] ?? 12);
  $u = './avs.php?'+http_build_query(['arr_iata'=>$arr,'type'=>$type,'hours'=>$hours,'ttl'=>5]);
}

// ---------- HTML ----------
?><!doctype html>
<html lang="es"><meta charset="utf-8">
<title>Diag · Timetable</title>
<style>
:root{--bg:#0b1220;--fg:#e6edf3;--mut:#9aa4b2;--line:#22304f;--ok:#22c55e;--bad:#ef4444;--warn:#f59e0b}
body{background:var(--bg);color:var(--fg);font:14px ui-sans-serif,system-ui,Segoe UI,Roboto,Ubuntu,Arial;margin:0;padding:16px}
h1{font-size:18px;margin:0 0 10px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.box{border:1px solid var(--line);border-radius:10px;padding:12px;background:#0f172a}
pre{white-space:pre-wrap;background:#0b1326;border:1px solid #1f2937;border-radius:8px;padding:8px;max-height:50vh;overflow:auto}
code{background:#0b1326;border:1px solid #1f2937;border-radius:6px;padding:2px 6px}
.ok{color:var(--ok)} .bad{color:var(--bad)} .warn{color:var(--warn)}
label{display:inline-block;margin-right:8px;margin-bottom:6px}
input[type=text],input[type=number]{background:#0b1326;border:1px solid #1f2937;color:var(--fg);border-radius:8px;padding:6px}
button{background:#111827;color:var(--fg);border:1px solid #1f2937;border-radius:8px;padding:6px 10px;cursor:pointer}
a{color:#93c5fd}
table{width:100%;border-collapse:collapse}
td,th{border-bottom:1px solid #1f2937;padding:6px;text-align:left;vertical-align:top}
small{color:var(--mut)}
</style>

<h1>Diagnóstico del sitio</h1>

<div class="grid">
<div class="box">
  <h3>Estructura</h3>
  <table>
    <tbody>
      <?php foreach($probes['files'] as $k=>$v): ?>
        <tr><th><?=h($k)?></th><td><?=h($v)?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p><small>ROOT: <code><?=h($ROOT)?></code></small></p>
</div>

<div class="box">
  <h3>PHP y Extensiones</h3>
  <table>
    <tbody>
      <?php foreach($probes['php'] as $k=>$v): ?>
        <tr><th><?=h($k)?></th><td><?=h($v)?></td></tr>
      <?php endforeach; ?>
      <?php foreach($probes['ext'] as $k=>$v): ?>
        <tr><th><?=h($k)?></th><td class="<?= $v==='OK' ? 'ok':'bad' ?>"><?=h($v)?></td></tr>
      <?php endforeach; ?>
      <?php if(isset($probes['curl'])): ?>
        <tr><th>cURL</th><td><?=h(json_encode($probes['curl']))?></td></tr>
      <?php endif; ?>
      <tr><th>Disco</th><td><?=h($probes['disk']['free']).' libres de '.h($probes['disk']['total'])?></td></tr>
    </tbody>
  </table>
</div>

<div class="box">
  <h3>Aviationstack</h3>
  <p>Clave <b><?= $probes['avs_key']['present'] ? '<span class="ok">OK</span>' : '<span class="bad">NO</span>' ?></b> <small>(fuente: <?=h($probes['avs_key']['source'])?>)</small></p>
  <p>Endpoints:</p>
  <ul>
    <li>Timetable: <code><?=h(AVS_ENDPOINT_TIMETABLE)?></code></li>
    <li>Flights: <code><?=h(AVS_ENDPOINT_FLIGHTS)?></code></li>
    <li>Future: <code><?=h(AVS_ENDPOINT_FUTURE)?></code></li>
  </ul>
  <p><a href="?probe=1">Probar endpoints (GET 1 registro)</a></p>
  <?php if($ping!==null): ?>
    <pre><?=h(json_encode($ping, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre>
  <?php endif; ?>
  <p>Probar llamada local rápida: <a href="./avs.php?arr_iata=TIJ&type=arrival&hours=12&ttl=5" target="_blank"><code>./avs.php?arr_iata=TIJ&type=arrival&hours=12&ttl=5</code></a></p>
</div>

<div class="box">
  <h3>error.log</h3>
  <form>
    <input type="hidden" name="log" value="1">
    <label>Líneas: <input type="number" name="lines" value="<?= (int)($_GET['lines'] ?? 400) ?>" min="10" max="5000"></label>
    <label>Filtro (regex o texto): <input type="text" name="grep" value="<?= h($_GET['grep'] ?? '') ?>"></label>
    <button>Ver</button>
    <?php if(is_file($ERR)): ?>
      <small> Tamaño: <?=h(bytes_h(filesize($ERR)))?> · Perms: <?=h(perm($ERR))?> · Dueño: <?=h(owner($ERR))?></small>
    <?php endif; ?>
  </form>
  <?php if($tail!==null): ?><pre><?=h($tail)?></pre><?php endif; ?>
</div>

</div>

<hr>
<p><small>Tip: asegúrate que <code>/api/cache</code> sea escribible por el usuario del servidor web. Si falla cURL con SSL, revisa certificados en tu hosting.</small></p>
</html>
