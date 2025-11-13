<?php declare(strict_types=1); ?>
<!doctype html><html lang="es"><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>JRTIJ | MMTJ · Timetable Operativo</title>
<link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="metar.css">
<style>
.spinner-border{display:inline-block;width:1rem;height:1rem;border:0.15em solid currentColor;border-right-color:transparent;border-radius:50%;animation:spinner-border .75s linear infinite;vertical-align:-0.125em}
.spinner-border-sm{width:0.9rem;height:0.9rem;border-width:0.15em}
.me-2{margin-right:0.5rem}
@keyframes spinner-border{to{transform:rotate(360deg);}}
</style>
<body>
<div style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true">
  <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
</div>
<header class="topbar">
  <h1>JRTIJ | MMTJ · Timetable Operativo</h1>
  <div class="top-actions">
    <div class="utc-clock"><span id="tzLabel">UTC</span>&nbsp;<span id="clock">--:--:--</span></div>
    <button id="tzToggle" class="btn small pill">Cambiar a LCL</button>
    <button id="csvBtn" class="btn small pill">CSV</button>
  </div>
</header>

<div class="wrap">
  <div class="btn-grid a4">
    <button id="quickPrev2h" class="btn pill">−2h</button>
    <button id="quickToday"  class="btn pill">Hoy 24H</button>
    <button id="quickTomorrow" class="btn pill">Mañana</button>
    <button id="quickNext2h" class="btn pill">+2h</button>
  </div>

  <section class="form-grid grid3">
    <div>
      <label>Aeropuerto IATA</label>
      <input id="iata" value="TIJ" maxlength="3" class="inp">
    </div>
    <div>
      <label>TTL caché (min)</label>
      <input id="ttl" type="number" class="inp" min="1" max="60" value="5">
    </div>
    <div class="span2">
      <label>Fecha y hora inicio (UTC)</label>
      <input id="start" type="datetime-local" class="inp dt">
    </div>

    <div>
      <label>Tipo de tabla</label>
      <div class="seg" id="typeSeg">
        <button class="seg-btn is-active" data-type="arrival">Llegadas</button>
        <button class="seg-btn" data-type="departure">Salidas</button>
        <button class="seg-btn" data-type="both">Ambas</button>
      </div>
    </div>
    <div>
      <label>Horas a mostrar</label>
      <input id="hours" type="number" class="inp" min="1" max="168" placeholder="vacío = 24h">
    </div>
    <div class="center">
      <button id="run" class="btn primary full">↻ Actualizar</button>
    </div>
  </section>

  <!-- Filtro de columnas -->
  <div class="dropdown" id="colsDrop">
    <button class="btn full" id="colsBtn">Filtro Columnas ▾</button>
    <div class="menu w420" id="colsMenu">
      <div class="row grid-3x3">
        <label><input type="checkbox" data-col="flight"  checked> VUELO</label>
        <label><input type="checkbox" data-col="route"   checked> RUTA</label>
        <label><input type="checkbox" data-col="est"     checked> ETA/ETD</label>
        <label><input type="checkbox" data-col="status"  checked> STATUS</label>
        <label><input type="checkbox" data-col="class"   checked> CLASE</label>
        <label><input type="checkbox" data-col="airline"> AEROLÍNEA</label>
        <label><input type="checkbox" data-col="sched"> STA/STD</label>
        <label><input type="checkbox" data-col="act"> ATA/ATD</label>
        <label><input type="checkbox" data-col="delay"> DEMORA</label>
        <label><input type="checkbox" data-col="gate"> TÉRM/GATE</label>
        <label><input type="checkbox" data-col="eet"> EET</label>
        <label><input type="checkbox" data-col="wx"> WX</label>
      </div>
    </div>
  </div>

  <!-- Filtro status 3×3 -->
  <div class="dropdown" id="statusDrop">
    <button class="btn full" id="statusBtn">Filtro Status ▾</button>
    <div class="menu w420" id="statusMenu">
      <div class="row grid-3x3">
        <label><input type="checkbox" value="scheduled" checked> PROGRAMADO</label>
        <label><input type="checkbox" value="active"    checked> EN VUELO</label>
        <label><input type="checkbox" value="landed"    checked> ATERRIZADO</label>
        <label><input type="checkbox" value="cancelled" checked> CANCELADO</label>
        <label><input type="checkbox" value="diverted"  checked> ALTERNO</label>
        <label><button type="button" id="statusReset" class="btn small line">RESET</button></label>
      </div>
      <div class="row">
        <button id="applyStatus" class="btn primary full">Aplicar filtros</button>
      </div>
    </div>
  </div>

  <!-- Tarjetas resumen -->
  <section class="small counters a8" id="cards"></section>

  <!-- Tabla -->
<div class="table-wrap">
  <table id="grid">
      <thead>
        <tr>
          <th class="col-flight">Vuelo</th>
          <th class="col-route">Ruta</th>
          <th class="col-est">ETA/ETD</th>
          <th class="col-status">Status</th>
          <th class="col-class">Clase</th>
          <th class="col-airline">Aerolínea</th>
          <th class="col-sched">STA/STD</th>
          <th class="col-act">ATA/ATD</th>
          <th class="col-delay">Demora</th>
          <th class="col-gate">Térm/Gate</th>
          <th class="col-eet">EET</th>
          <th class="col-wx">WX</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>
</div>

<div id="wxModal" class="wx-modal hidden" role="dialog" aria-modal="true" aria-labelledby="wxTitle">
  <div class="wx-modal__content">
    <div class="wx-modal__header">
      <h2 class="wx-modal__title" id="wxTitle">Detalle meteorológico</h2>
      <button type="button" class="wx-modal__close" id="wxClose" aria-label="Cerrar">×</button>
    </div>
    <div class="wx-modal__info" id="wxInfo"></div>
    <div class="wx-modal__grid">
      <section>
        <h3>METAR</h3>
        <pre id="wxMetar"></pre>
        <div class="wx-modal__source" id="wxMetarSource"></div>
      </section>
      <section>
        <h3>TAF</h3>
        <pre id="wxTaf"></pre>
        <div class="wx-modal__source" id="wxTafSource"></div>
      </section>
    </div>
  </div>
</div>

<script src="app.js"></script>
</body></html>