<?php
/* ========= Config y caches ========= */
$config = require __DIR__ . '/../config.php';
$paths  = $config['paths'];

/* NOAA/ADDs fallback libs existentes */
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/metar_awc.php';

/* ========= CAPMA ========= */
function capma_get_metar($icao){
  $url = "http://capma.mx/reportemetar/buscar_samx.php?id=$icao";
  $ch = curl_init();
  curl_setopt_array($ch,[
    CURLOPT_URL=>$url, CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>4,
    CURLOPT_USERAGENT=>'MMTJ-FogApp/1.0'
  ]);
  $html = curl_exec($ch);
  if (curl_errno($ch)) { curl_close($ch); return ''; }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code>=500 || !$html) return '';

  $dom=new DOMDocument(); libxml_use_internal_errors(true);
  $dom->loadHTML($html); libxml_clear_errors();
  $xp=new DOMXPath($dom);
  foreach($xp->query('//p[@id="tam_let_5"]') as $p){
    $metar=strtoupper(trim($p->nodeValue));
    if(preg_match('/\b'.preg_quote($icao,'/').'\b\s+\d{6}Z/',$metar)){
      $clean=preg_replace('/=\s*\d{6}$/','',$metar);
      $clean=preg_replace('/\s+/', ' ', $clean);
      $clean=preg_replace('/SM\s+/', 'SM ', $clean);
      return trim($clean);
    }
  }
  return '';
}
function capma_get_single_taf_from_url($url,$icao){
  $ch=curl_init();
  curl_setopt_array($ch,[
    CURLOPT_URL=>$url, CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>4,
    CURLOPT_USERAGENT=>'MMTJ-FogApp/1.0'
  ]);
  $html=curl_exec($ch);
  if (curl_errno($ch)) { curl_close($ch); return ''; }
  $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code>=500 || !$html) return '';
  $dom=new DOMDocument(); libxml_use_internal_errors(true);
  $dom->loadHTML($html); libxml_clear_errors();
  foreach($dom->getElementsByTagName('pre') as $pre){
    $tafText=trim($pre->nodeValue);
    if(preg_match('/^TAF(?:\s+(?:AMD|COR|RTD))?\s+'.preg_quote($icao,'/').'\b/i',$tafText)){
      $lines=explode("\n",$tafText);
      $clean=array_map(fn($ln)=>preg_replace('/\s+/', ' ', ltrim($ln)),$lines);
      return trim(implode("\n",$clean));
    }
  }
  return '';
}
function capma_get_taf($icao){
  return capma_get_single_taf_from_url('http://capma.mx/pronosticos/buscar_ftmx.php?id=ftmx53',$icao) ?: '';
}

/* ========= Clasificación ========= */
function determinar_alerta($txt){
  if(!$txt || strlen(trim($txt))<6) return 'alerta-unknown';
  if(preg_match('/\b(VV|OVC|BKN)(\d{3})\b/',$txt,$m)){
    $c=(int)$m[2];
    if($c<5) return 'alerta-magenta';
    if($c<10) return 'alerta';
    if($c<=30) return 'alerta-vis';
  }
  if(preg_match('/\b((\d{1,2})\s*)?(\d)\/(\d)\s*SM\b/',$txt,$mm)){
    $vis=0.0; if(!empty($mm[1])) $vis+=(int)$mm[1];
    if(!empty($mm[3]) && !empty($mm[4])) $vis+=(int)$mm[3]/max(1,(int)$mm[4]);
    if($vis<1) return 'alerta-magenta'; if($vis<3) return 'alerta'; if($vis<=5) return 'alerta-vis';
  } elseif(preg_match('/\b(\d{1,2})\s*SM\b/',$txt,$mm)){
    $vis=(float)$mm[1]; if($vis<1) return 'alerta-magenta'; if($vis<3) return 'alerta'; if($vis<=5) return 'alerta-vis';
  }
  if(preg_match('/\b(TS|CB|\+RA|\+SN|LLWS|WS|FC|VA|SS|DS|FG|BR)\b/i',$txt)) return 'alerta';
  return 'alerta-vfr';
}
function alerta_to_color($cls){
  return ['alerta-magenta'=>'magenta','alerta'=>'red','alerta-vis'=>'#3399ff','alerta-vfr'=>'#28a745'][$cls] ?? 'var(--bs-body-color)';
}
function colorize_taf_lines($taf_raw){
  if(!$taf_raw) return '';
  $out=[];
  foreach(explode("\n",$taf_raw) as $line){
    $line=trim($line); if($line==='') continue;
    if(preg_match('/^(TAF(?:\s+(?:AMD|COR|RTD))?\s+\w{4})\s+(.*)$/',$line,$m)){
      $out[]='<div style="margin-bottom:2px;"><span class="text-secondary">'.htmlspecialchars($m[1]).'</span> <span style="color:'.alerta_to_color(determinar_alerta($m[2])).';">'.htmlspecialchars($m[2]).'</span></div>';
    } else {
      $out[]='<div style="margin-bottom:2px;color:'.alerta_to_color(determinar_alerta($line)).';">'.htmlspecialchars($line).'</div>';
    }
  }
  return implode('',$out);
}

/* ========= Datos ========= */
$icao = $config['icao'] ?? 'MMTJ';
$metar_capma = capma_get_metar($icao);
$taf_capma   = capma_get_taf($icao);

$metar_cache = is_file($paths['metar']) ? json_decode(file_get_contents($paths['metar']), true) : null;
$taf_cache   = is_file($paths['taf'])   ? json_decode(file_get_contents($paths['taf']),   true) : null;

if(!$metar_capma && !$metar_cache) $metar_cache = adds_metar($icao, 6);
if(!$taf_capma && !$taf_cache)     $taf_cache   = adds_taf($icao, 30);

$pred = is_file($paths['predictions']) ? json_decode(file_get_contents($paths['predictions']), true) : ['points'=>[], 'tz'=>$config['timezone']];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ========= Parseo mínimos ========= */
$metar_txt = $metar_capma ?: ($metar_cache['raw_text'] ?? '');
$taf_txt   = $taf_capma   ?: ($taf_cache['raw_text'] ?? '');
$metar_src = $metar_capma ? 'CAPMA' : ($metar_cache ? 'NOAA/Cache' : 'N/D');
$taf_src   = $taf_capma   ? 'CAPMA' : ($taf_cache   ? 'NOAA/Cache' : 'N/D');

function parse_vis_sm($metar){
  if(!$metar) return null;
  if(preg_match('/\bP6SM\b/',$metar)) return 6.0;
  if(preg_match('/\b(\d{1,2})\s+(\d)\/(\d)\s*SM\b/',$metar,$m)) return (int)$m[1] + ((int)$m[2])/max(1,(int)$m[3]);
  if(preg_match('/\b(\d)\/(\d)\s*SM\b/',$metar,$m)) return (int)$m[1]/max(1,(int)$m[2]);
  if(preg_match('/\b(\d{1,2})\s*SM\b/',$metar,$m)) return (float)$m[1];
  return null;
}
function parse_rvr_ft_by_runway($metar){
  $out=[]; if(!$metar) return $out;
  if(preg_match_all('/\bR(09|27)\/(\d{4})FT[UDN]?/',$metar,$m,PREG_SET_ORDER)){
    foreach($m as $mm){ $out[$mm[1]]=(int)$mm[2]; }
  }
  return $out;
}
function parse_ceiling_ft($metar){
  $min=null; if(!$metar) return null;
  if(preg_match_all('/\b(VV|OVC|BKN)(\d{3})\b/',$metar,$m,PREG_SET_ORDER)){
    foreach($m as $mm){ $ft=(int)$mm[2]*100; if($min===null||$ft<$min) $min=$ft; }
  }
  return $min;
}
function status3($value,$thr){
  if($value===null) return ['⚪','N/D','text-secondary'];
  $eps=1e-6;
  if($value < $thr-$eps) return ['🔴','Bajo mínimos','text-danger'];
  if(abs($value-$thr)<= $eps) return ['🔵','A mínimos','text-info'];
  return ['🟢','Sobre mínimos','text-success'];
}
function fmt_sm_m($sm){
  if($sm===null) return 'N/D';
  $m=round($sm*1609.344);
  $sm_txt = (fmod($sm,1)==0) ? number_format($sm,0) : rtrim(rtrim(number_format($sm,2),'0'),'.');
  return $sm_txt.' SM ('.$m.' m)';
}
function fmt_vv($ft){ return $ft===null ? 'N/D' : $ft.' ft'; }

$vis_sm = parse_vis_sm($metar_txt);
$vis_m  = $vis_sm!==null ? $vis_sm*1609.344 : null;
$rvr_ft = parse_rvr_ft_by_runway($metar_txt);
$ceil_ft= parse_ceiling_ft($metar_txt);

function dep_status_for($rwy,$vis_m,$rvr_ft){
  $thr_m=200; $use_m=$vis_m;
  if(isset($rvr_ft[$rwy])) $use_m=$rvr_ft[$rwy]*0.3048;
  return status3($use_m,$thr_m);
}
[$st_dep_09,$st_dep_27] = [dep_status_for('09',$vis_m,$rvr_ft), dep_status_for('27',$vis_m,$rvr_ft)];
$st_arr_09_vv = status3($ceil_ft, 250);
$st_arr_09_vs = status3($vis_m, 800);
$st_arr_27_vv = status3($ceil_ft, 513);
$st_arr_27_vs = status3($vis_m, 1600);
?>
<!doctype html>
<html lang="es" data-bs-theme="dark">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=0.9, viewport-fit=cover">
	<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MMTJ FRI_v1">
<!-- Tinte de barras en navegador -->
<meta name="theme-color" media="(prefers-color-scheme: dark)"  content="#0d1422">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">

  <title>MMTJ Pronostico Riesgo Niebla /BR/FG</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Tu tema -->
  <link href="./metar.css?v=<?=time()?>" rel="stylesheet">

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/luxon@3/build/global/luxon.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.3.1/dist/chartjs-adapter-luxon.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8/hammer.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.umd.min.js"></script>

  <style>
    .table-sm td,.table-sm th{padding:.35rem .5rem}
    .sticky-controls{position:sticky;top:.5rem;z-index:10;background:transparent}
    .badge-dot{display:inline-block;width:.6rem;height:.6rem;border-radius:50%;margin-right:.35rem}
    .text-lifr{color:#d63384!important}.text-ifr{color:#dc3545!important}.text-mvfr{color:#0dcaf0!important}.text-vfr{color:#20c997!important}
    .row-peak{outline:1px dashed #d63384;outline-offset:-2px}
    code.metar-colored{display:block;padding:.5rem .75rem;border-radius:.5rem;background:rgba(108,117,125,.1)}
    code.metar-colored,.taf-colored{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;white-space:pre-wrap;font-variant-numeric:tabular-nums;letter-spacing:.02em}
    .taf-colored,.taf-colored *{-webkit-touch-callout:none}
    .taf-colored a,.taf-colored a.x-apple-data-detectors{color:inherit!important;text-decoration:none!important;pointer-events:none!important;cursor:text!important}
    .minimos-card .pill{padding:.15rem .4rem;border-radius:.35rem;background:rgba(108,117,125,.12)}
    .minimos-card .clickable{cursor:pointer}
    .minimos-card .fit-root{position:relative;overflow:hidden}
    .minimos-card .fit-scale{transform-origin:left top}
    .minimos-card table{white-space:nowrap}
    .minimos-card th,.minimos-card td{vertical-align:middle}
    .minimos-card .pill{display:inline-flex;align-items:center;gap:.4rem}
    @media (max-width:992px){.minimos-card .small{font-size:clamp(11px,1.2vw,13px)}}
  </style>
</head>
<body>
<script>
(function(){
  const SCALE = 0.9;
  const root = document.documentElement; // <html>
  const supportsZoom = CSS.supports('zoom','1');

  function applyScale(){
    if (supportsZoom){
      root.style.zoom = String(SCALE);                 // Chrome/Edge/Opera
      root.style.transform = ''; root.style.width = ''; // limpia fallback
    } else {
      // Firefox y casos que ignoran 'zoom' (incluye PWA WebKit en algunos modos)
      root.style.transformOrigin = 'top left';
      root.style.transform = 'scale(' + SCALE + ')';
      root.style.width = (100 / SCALE) + '%';          // compensa el scale
    }
  }

  applyScale();
  // Reaplica tras rotaciÃ³n o cambios del WebView
  window.addEventListener('orientationchange', ()=> setTimeout(applyScale, 250));
})();
</script>
<div class="container-fluid py-3 px-2 px-sm-3 px-lg-4">
  <!-- Header con reloj -->
  <header class="mb-3">
    <div class="d-flex align-items-baseline justify-content-between">
      <h1 class="text-strong h5 mb-0 mt-6">Jefatura Regional Tijuana</h1>
      <div class="small text-end">
        <div id="utcClock" class="fw-semibold">00:00:00 UTC</div>
        <div id="autoTimer" class="small text-soft">Última actualización: 00:00:00</div>
      </div>
    </div>
    <div class="text-soft small">SIGMA-LV • Apto. Intl. Tijuana 🌦️</div>

  </header>

  <!-- Grid superior 2Ã2 en â¥lg: FRI | PISTA  /  METAR | TAF -->
  <div class="row g-3 row-cols-1 row-cols-lg-2 align-items-stretch">

    <!-- FRI -->
<div class="col order-1 order-lg-1">
  <div id="fri-card" class="card fri-card p-3 h-100 mb-0">FRI | Fog Risk Indicator: Cargando…</div>
</div>

    <!-- PISTA -->
    <div class="col order-3 order-lg-2">
      <div class="card minimos-card h-100 shadow-soft">
        <div class="small card-body">
          <div id="minimos-fit" class="fit-root">
            <div class="fit-scale">
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-2">
                  <thead>
                  <tr>
                    <th></th>
                    <th class="text-center">Despegue</th>
                    <th class="text-center">Aterrizaje</th>
                  </tr>
                  </thead>
                  <tbody>
                  <tr class="clickable" data-bs-toggle="collapse" data-bs-target="#rw09x">
                    <th class="text-nowrap">RWY 09</th>
                    <td class="text-center">
                      <?php [$ico,$lbl,$cls]=$st_dep_09; ?>
                      <span class="<?=$cls?>"><?=$ico?></span>
                      <span class="pill">
                        <?= ($vis_sm===null && !isset($rvr_ft['09'])) ? 'VIS: N/D'
                           : (isset($rvr_ft['09']) ? 'RVR: '.round($rvr_ft['09']*0.3048).' m'
                           : ''.fmt_sm_m($vis_sm)) ?>
                      </span>
                    </td>
                    <td>
                      <?php [$i1,$l1,$c1]=$st_arr_09_vv; [$i2,$l2,$c2]=$st_arr_09_vs; ?>
                      <span class="me-1">ILS&nbsp;&nbsp;</span>
                      <span class="pill me-1 <?=$c1?>">VV <?=$i1?> <?=fmt_vv($ceil_ft)?></span>
                      <span class="pill <?=$c2?>">VIS <?=$i2?> <?= $vis_sm===null?'N/D':fmt_sm_m($vis_sm) ?></span>
                    </td>
                  </tr>
                  <tr class="collapse" id="rw09x">
                    <td colspan="3" class="small text-soft">
                      Pista 09 (ILS CAT I): techo ≥ 250 ft y vis ≥ 1/2 SM (800 m).
                    </td>
                  </tr>

                  <tr class="clickable" data-bs-toggle="collapse" data-bs-target="#rw27x">
                    <th class="text-nowrap">RWY 27</th>
                    <td class="text-center">
                      <?php [$ico,$lbl,$cls]=$st_dep_27; ?>
                      <span class="<?=$cls?>"><?=$ico?></span>
                      <span class="pill">
                        <?= ($vis_sm===null && !isset($rvr_ft['27'])) ? 'VIS: N/D'
                           : (isset($rvr_ft['27']) ? 'RVR: '.round($rvr_ft['27']*0.3048).' m'
                           : ''.fmt_sm_m($vis_sm)) ?>
                      </span>
                    </td>
                    <td>
                      <?php [$i1,$l1,$c1]=$st_arr_27_vv; [$i2,$l2,$c2]=$st_arr_27_vs; ?>
                      <span class="me-1">RNP</span>
                      <span class="pill me-1 <?=$c1?>">VV <?=$i1?> <?=fmt_vv($ceil_ft)?></span>
                      <span class="pill <?=$c2?>">VIS <?=$i2?> <?= $vis_sm===null?'N/D':fmt_sm_m($vis_sm) ?></span>
                    </td>
                  </tr>
                  <tr class="collapse" id="rw27x">
                    <td colspan="3" class="small text-soft">
                      Pista 27 (RNP): techo ≥ 513 ft y vis ≥ 1 SM (1600 m).
                    </td>
                  </tr>
                  </tbody>
                </table>
              </div><!--/table-responsive-->
            </div><!--/fit-scale-->
          </div><!--/fit-root-->
        </div>
      </div>
    </div>

    <!-- METAR -->
    <div class="col order-2 order-lg-3">
      <div class="card h-100 shadow-soft">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-baseline mb-2">
            <h2 class="h6 mb-0">METAR más reciente</h2>
            <div class="small text-soft">Fuente: <?=h($metar_src)?></div>
          </div>
          <?php
            if($metar_txt){
              $sev=determinar_alerta($metar_txt); $color=alerta_to_color($sev);
              echo '<code class="metar-colored mb-0" style="color:'.$color.'">'.h($metar_txt).'</code>';
            } else {
              echo '<div class="text-warning">Sin METAR disponible (CAPMA y fallback no respondieron).</div>';
            }
          ?>
        </div>
      </div>
    </div>

    <!-- TAF -->
    <div class="col order-4 order-lg-4">
      <div class="card h-100 shadow-soft">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-baseline mb-2">
            <h2 class="h6 mb-0">TAF</h2>
            <div class="small text-soft">Fuente: <?=h($taf_src)?></div>
          </div>
          <?php
            if($taf_txt){
              echo '<div class="small taf-colored rounded p-2" style="background:rgba(108,117,125,.08)">'.colorize_taf_lines($taf_txt).'</div>';
            } else {
              echo '<div class="text-warning">Sin TAF disponible (CAPMA y fallback no respondieron).</div>';
            }
          ?>
        </div>
      </div>
    </div>

  </div><!-- /row 2×2 -->

  <!-- Controles + gráfica + tabla -->
  <section class="mt-3">
    <div class="card shadow-soft">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-end sticky-controls pb-2 mb-2 border-bottom">
          <div><label class="form-label mb-1 small">Desde</label>
            <input type="datetime-local" id="startInput" class="form-control form-control-sm"></div>
          <div><label class="form-label mb-1 small">Hasta</label>
            <input type="datetime-local" id="endInput" class="form-control form-control-sm"></div>
          <div><label class="form-label mb-1 small">Umbral %</label>
            <input type="number" id="thrInput" class="form-control form-control-sm" min="0" max="100" step="5" value="50"></div>
          <div><label class="form-label mb-1 small">Bloque (min)</label>
            <select id="blkSel" class="form-select form-select-sm">
              <option>5</option><option>10</option><option selected>15</option><option>30</option>
            </select></div>
          <div class="ms-auto d-flex gap-2">
            <button id="applyBtn" class="btn btn-primary btn-sm">Aplicar</button>
            <button id="resetNightBtn" class="btn btn-outline-secondary btn-sm">Reset 22:00–10:00</button>
            <button id="resetZoomBtn" class="btn btn-outline-secondary btn-sm">Reset Zoom</button>
            <button id="csvBtn" class="btn btn-accent btn-sm">Descargar CSV</button>
          </div>
        </div>
        <canvas id="probChart" height="120"></canvas>
        <div class="mt-3"><div id="summary" class="small text-soft"></div></div>
      </div>
    </div>
  </section>

  <section class="mt-3">
    <div class="card shadow-soft">
      <div class="card-body">
        <h2 class="h6 mb-2">Tabla por bloques</h2>
        <div class="table-responsive">
          <table class="table table-sm table-striped table-hover align-middle mb-0 small" id="blkTable">
            <thead class="position-sticky"><tr>
              <th>Hora</th><th>Prob %</th><th>Temp °C</th><th>RH %</th><th>Viento kn</th>
            </tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <footer class="mt-4 text-soft small">
    Herramienta de cálculo y análisis de datos meteorológicos para predicciones analíticas de formación de niebla en MMTJ | Ricardo Reig Glz | Noviembre 2025 | V1.0 Beta Test
  </footer>
</div>

<script>
/* ===== Auto-fit card “Mínimos por pista” ===== */
(function(){
  const root=document.getElementById('minimos-fit'); if(!root) return;
  const scale=root.querySelector('.fit-scale');
  function fit(){
    scale.style.transform='none'; scale.style.width='100%';
    const cw=root.clientWidth||root.getBoundingClientRect().width;
    const iw=scale.scrollWidth;
    const s=Math.min(1, cw/Math.max(1,iw));
    scale.style.transform=`scale(${s})`;
    scale.style.width = s<1 ? (100/s)+'%' : '100%';
  }
  const ro=new ResizeObserver(fit); ro.observe(root);
  window.addEventListener('load',fit);
  window.addEventListener('orientationchange',()=>setTimeout(fit,100));
})();

/* ===== Reloj UTC + cronómetro ===== */
const AUTO_REFRESH_MS=120000;
let lastRefreshEpoch=Date.now();
function pad2(n){return String(n).padStart(2,'0');}
function tickUTC(){const d=new Date(); const t=`${pad2(d.getUTCHours())}:${pad2(d.getUTCMinutes())}:${pad2(d.getUTCSeconds())} UTC`; const el=document.getElementById('utcClock'); if(el) el.textContent=t;}
function tickAutoTimer(){const s=Math.floor((Date.now()-lastRefreshEpoch)/1000); const hh=pad2(Math.floor(s/3600)); const mm=pad2(Math.floor((s%3600)/60)); const ss=pad2(s%60); const el=document.getElementById('autoTimer'); if(el) el.textContent=`Última actualización: ${hh}:${mm}:${ss}`;}
setInterval(tickUTC,1000); tickUTC();
setInterval(tickAutoTimer,1000); tickAutoTimer();

/* ===== Persistencia UI ===== */
const LS_KEY='fog:UI';
function persistUI(){
  localStorage.setItem(LS_KEY, JSON.stringify({
    start: startInput.value, end: endInput.value, thr: thrInput.value, blk: blkSel.value
  }));
}
function restoreUI(){
  const raw=localStorage.getItem(LS_KEY); if(!raw) return false;
  try{ const st=JSON.parse(raw);
    if(st.start) startInput.value=st.start;
    if(st.end)   endInput.value=st.end;
    if(st.thr)   thrInput.value=st.thr;
    if(st.blk)   blkSel.value=st.blk;
    return true;
  }catch(e){ return false; }
}

/* ===== Ventana default = hora actual redondeada + 12 h ===== */
function startOfCurrentHour(){
  const d = new Date();
  d.setMinutes(0,0,0);
  return d;
}
function defaultRolling12hWindow(){
  const start = startOfCurrentHour();
  const end = new Date(start.getTime() + 12*60*60*1000);
  return { start, end };
}

/* ===== Predicción y gráfica ===== */
window.onerror=(m)=>{const el=document.getElementById('summary'); if(el) el.textContent='JS error: '+m;};
const pred = <?= json_encode($pred, JSON_UNESCAPED_SLASHES) ?>;
const points = Array.isArray(pred.points)?pred.points:[];
const P=points.map(p=>({x:new Date(p.time), y:Number(p.prob)*100, T:+p.temp_C, RH:+p.rh_pct, W:+p.wind_kn})).filter(r=>isFinite(r.x)&&isFinite(r.y));

(function(){const cand=window.ChartZoom||(window['chartjs-plugin-zoom']&&(window['chartjs-plugin-zoom'].default||window['chartjs-plugin-zoom'])); if(cand){Chart.register(cand);}})();

function fmtISO(dt){return new Date(dt).toLocaleString('sv-SE',{hour12:false}).replace(' ','T');}

/* ===== Formato pedido: D/M/YYYY HH:MML HHZ (sin segundos) ===== */
function fmtLabel(dt){
  const d = new Date(dt);
  const day = d.getDate();          // sin cero a la izquierda
  const mon = d.getMonth() + 1;     // sin cero a la izquierda
  const y   = d.getFullYear();
  const hh  = pad2(d.getHours());
  const mm  = pad2(d.getMinutes());
  const zH  = pad2(d.getUTCHours());
  return `${day}/${mon}/${y} ${hh}:${mm}L ${zH}Z`;
}

function defaultNightWindow(){const now=new Date(); const s=new Date(now); s.setHours(22,0,0,0); const e=new Date(s); e.setDate(e.getDate()+1); e.setHours(10,0,0,0); return {start:s,end:e};}
function clamp(x,a,b){return Math.max(a,Math.min(b,x));}
function lerp(a,b,t){return a+(b-a)*t;}
function buildSeries(stepMin,from,to){
  const out=[]; if(P.length<2) return out;
  for(let t=new Date(from); t<=to; t=new Date(t.getTime()+stepMin*60*1000)){
    let i=P.findIndex(pt=>pt.x>=t); if(i<=0) i=1; if(i>=P.length) i=P.length-1;
    const p0=P[i-1], p1=P[i]; const span=p1.x-p0.x; const at=t-p0.x; const w=span>0?clamp(at/span,0,1):0;
    out.push({t:new Date(t), p:lerp(p0.y,p1.y,w), T:lerp(p0.T,p1.T,w), RH:lerp(p0.RH,p1.RH,w), W:lerp(p0.W,p1.W,w)});
  } return out;
}

const ctx=document.getElementById('probChart').getContext('2d');
let chart=new Chart(ctx,{type:'line',
  data:{datasets:[
    {label:'Prob FG/BR %',data:P.map(r=>({x:r.x,y:r.y})),yAxisID:'y',tension:.25},
    {label:'Temp °C',data:P.map(r=>({x:r.x,y:r.T})),yAxisID:'y1',borderDash:[4,4],tension:.2},
    {label:'RH %',data:P.map(r=>({x:r.x,y:r.RH})),yAxisID:'y1',borderDash:[2,2],tension:.2},
    {label:'Viento kn',data:P.map(r=>({x:r.x,y:r.W})),yAxisID:'y1',borderDash:[6,3],tension:.2},
  ]},
  options:{
    responsive:true,parsing:true,normalized:true,
    scales:{
      x:{ type:'time',
          time:{ unit:'hour', displayFormats:{ hour:'HH:mm' } }, /* ticks sin segundos */
          title:{display:true,text:'Hora local'}
      },
      y:{title:{display:true,text:'Prob %'},min:0,max:100},
      y1:{position:'right',grid:{display:false},title:{display:true,text:'Vars'}}
    },
    plugins:{
      legend:{display:true},
      tooltip:{
        callbacks:{
          title:(items)=>{
            const v = items?.[0]?.parsed?.x ?? items?.[0]?.raw?.x;
            return fmtLabel(v);
          }
        }
      },
      zoom:{ zoom:{wheel:{enabled:true},pinch:{enabled:true},mode:'x'},
             pan:{enabled:true,mode:'x'} }
    },
    animation:false,interaction:{mode:'index',intersect:false}
  }
});

function updateView(from,to){
  const step=Number(blkSel.value||15);
  const series=buildSeries(step,from,to);
  const tbody=document.querySelector('#blkTable tbody');
  const summaryDiv=document.getElementById('summary');
  tbody.innerHTML='';
  const peak=series.reduce((a,b)=> b.p>(a?.p??-1)?b:a,null);
  for(const r of series){
    const cat=r.p>=75?'LIFR':r.p>=50?'IFR':r.p>=30?'MVFR':'VFR';
    const cls=cat==='LIFR'?'text-lifr':cat==='IFR'?'text-ifr':cat==='MVFR'?'text-mvfr':'text-vfr';
    const tr=document.createElement('tr'); if(peak && r.t.getTime()===peak.t.getTime()) tr.classList.add('row-peak');
    tr.innerHTML=`<td>${fmtLabel(r.t)}</td><td class="${cls}">${r.p.toFixed(1)}</td><td>${r.T.toFixed(1)}</td><td>${r.RH.toFixed(0)}</td><td>${r.W.toFixed(1)}</td>`;
    tbody.appendChild(tr);
  }
  const thr=Number(thrInput.value||50);
  const above=series.filter(r=>r.p>=thr);
  const first=above.length?above[0].t:null;
  const last =above.length?above[above.length-1].t:null;
  const durationMin=above.length*step;
  summaryDiv.innerHTML=`<div><span class="badge-dot bg-success"></span>Umbral: ${thr}% · Bloque: ${step} min</div>
    <div><strong>Inicio</strong>: ${first?fmtLabel(first):'—'}</div>
    <div><strong>Pico</strong>: ${peak?fmtLabel(peak.t)+' · '+peak.p.toFixed(1)+'%':'—'}</div>
    <div><strong>Fin</strong>: ${last?fmtLabel(last):'—'}</div>
    <div><strong>Duración ≥ umbral</strong>: ${durationMin} min</div>`;
}

function safeResetZoom(c){ if(c && typeof c.resetZoom==='function') c.resetZoom(); else { c.options.scales.x.min=undefined; c.options.scales.x.max=undefined; c.update('none'); } }
function applyRange(){
  const s=new Date(startInput.value), e=new Date(endInput.value);
  if(!isFinite(s)||!isFinite(e)||s>=e) return;
  safeResetZoom(chart); chart.options.scales.x.min=s; chart.options.scales.x.max=e; chart.update(); updateView(s,e); persistUI();
}
applyBtn.onclick=applyRange; blkSel.onchange=applyRange; thrInput.onchange=applyRange;
resetNightBtn.onclick=()=>{const {start,end}=defaultNightWindow(); startInput.value=fmtISO(start); endInput.value=fmtISO(end); safeResetZoom(chart); chart.options.scales.x.min=start; chart.options.scales.x.max=end; chart.update(); updateView(start,end); persistUI();};
resetZoomBtn.onclick=()=>{safeResetZoom(chart); chart.options.scales.x.min=undefined; chart.options.scales.x.max=undefined; chart.update(); const s=new Date(startInput.value), e=new Date(endInput.value); updateView(s,e); persistUI();};
probChart.addEventListener('wheel',()=>{const sc=chart.scales.x; if(sc&&sc.min&&sc.max){ startInput.value=fmtISO(new Date(sc.min)); endInput.value=fmtISO(new Date(sc.max)); updateView(new Date(sc.min),new Date(sc.max)); persistUI(); }},{passive:true});

/* Boot: si no hay estado, usar ventana “hora actual redondeada + 12 h” */
(function(){
  const had=restoreUI();
  if(!had){
    const {start,end}=defaultRolling12hWindow();
    startInput.value=fmtISO(start);
    endInput.value=fmtISO(end);
  }
  const s=new Date(startInput.value), e=new Date(endInput.value);
  chart.options.scales.x.min=s; chart.options.scales.x.max=e; chart.update(); updateView(s,e);
  lastRefreshEpoch=Date.now();
})();

/* Auto-refresh conservando UI */
setInterval(()=>{ persistUI(); lastRefreshEpoch=Date.now(); location.reload(); }, AUTO_REFRESH_MS);

/* API FRI explícita */
window.FRI_API = new URL('api/fri.json', document.baseURI).href;
</script>
<script src="./js/fri-card.js?v=<?=time()?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>