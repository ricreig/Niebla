// public/js/fri-card.js
(()=>{
  const el = document.getElementById('fri-card');
  if(!el) return;

  // Rutas robustas para subdominios
  const API_FRI = (window.FRI_API || new URL('api/fri.json', document.baseURI).href);
  const API_CAL = (window.FRI_CAL || new URL('api/calibration.json', document.baseURI).href);

  // Pull sin recargar la página
  const REFRESH_MS = 60_000;     // 60 s
  const STALE_SEC  = 10 * 60;    // 10 min para marcar "Desactualizado"

  // ===== Estilos fallback (si metar.css no trae clases de semáforo) =====
  (function ensureStyles(){
    if (document.getElementById('fri-card-styles')) return;
    const css = `
#fri-card{border:0}
/* Intensidad del “wash” de color en la tarjeta FRI */
.fri-bg-green     { background: rgba(25,135,84, 0.20); }
.fri-bg-amber     { background: rgba(255,193,7, 0.20); }
.fri-bg-red       { background: rgba(220,53,69, 0.20); }
.fri-bg-critical  { background: rgba(214,51,132,0.20); }
.fri-bg-unknown   { background: rgba(108,117,125,0.20); }
.badge-extremo { color: #fff; background-color: #D63384; }
.fri-foot{opacity:.85}
`;
    const st = document.createElement('style');
    st.id = 'fri-card-styles';
    st.textContent = css;
    document.head.appendChild(st);
  })();

  // ===== Utils =====
  const esc = s => String(s).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const pct = x => (Number.isFinite(x) ? (x*100).toFixed(x<0.01?2:1) : '0.0') + '%';

  // Etiqueta pedida por FRI: BAJO / MEDIO / ALTO / EXTREMO
  function labelFRI(v){
    const n = Number(v);
    if (!Number.isFinite(n)) return 'N/D';
    if (n > 80) return 'EXTREMO';
    if (n >= 61) return 'ALTO';
    if (n >= 31) return 'MEDIO';
    return 'BAJO';
  }

  function classify(fri){
    const v = Number(fri);
    if (!Number.isFinite(v)) return {key:'unknown', bg:'fri-bg-unknown', badge:'text-bg-secondary'};
    if (v > 80) return {key:'critical', bg:'fri-bg-critical', badge:'badge-extremo'};
    if (v >= 61) return {key:'red',      bg:'fri-bg-red',      badge:'text-bg-danger'};
    if (v >= 31) return {key:'amber',    bg:'fri-bg-amber',    badge:'text-bg-warning'};
    return            {key:'green',      bg:'fri-bg-green',    badge:'text-bg-success'};
  }

  // ===== Estado en memoria =====
  let calib = null; // estructura con promedios por hora

  // Intenta leer p(hora) desde distintas formas {by_hour:{'00':{...}}} o [{hour:0,...}]
  function getCalibForLocalHour(d=new Date()){
    if(!calib) return null;
    const hr = d.getHours();
    // Formato 1: { by_hour: { "00": {n, p_lt200, ...}, ... } }
    if (calib.by_hour && typeof calib.by_hour === 'object') {
      const key = String(hr).padStart(2,'0');
      return calib.by_hour[key] || null;
    }
    // Formato 2: [ {hour:0, n, p_lt200, ...}, ... ]
    if (Array.isArray(calib)) {
      const row = calib.find(r => Number(r.hour) === hr);
      return row || null;
    }
    return null;
  }

  // ===== Render =====
  function paint(data){
    const fri     = Number(data?.fri);
    // Antes: usábamos data.estado para imprimir "VERDE/ÁMBAR/…"
    // Ahora: badge con BAJO/MEDIO/ALTO/EXTREMO; dejamos estado original como tooltip.
    const estadoOriginal = (data?.estado ? String(data.estado) : '').toUpperCase();

    const razones = Array.isArray(data?.razones) ? data.razones : [];
    const tsISO   = data?.ts || null;
    const ts      = tsISO ? new Date(tsISO) : new Date();
    const ageSec  = Math.max(0, (Date.now() - ts.getTime())/1000);
    const stale   = ageSec > STALE_SEC;

    const cls = classify(fri);
    const cal = getCalibForLocalHour(new Date());
    const badgeText = labelFRI(fri);

    // Pie con histórico por hora si existe calibración
    let footer = '';
    if (cal) {
      // nombres posibles en JSON
      const n        = Number(cal.n ?? cal.count ?? 0);
      const p200     = Number(cal.p_lt200 ?? cal.p_vis_lt200 ?? 0);
      const p800     = Number(cal.p_lt800 ?? cal.p_vis_lt800 ?? 0);
      const p1600    = Number(cal.p_lt1600 ?? cal.p_vis_lt1600 ?? 0);
      const hrLocal  = new Date().toLocaleTimeString('es-MX',{hour:'2-digit',hour12:false});
      footer =
        `<div class="mt-2 small fri-foot">
           <span class="text-secondary">Histórico ${hrLocal}h:</span>
           &lt;200 m <strong>${pct(p200)}</strong> ·
           &lt;800 m <strong>${pct(p800)}</strong> ·
           &lt;1600 m <strong>${pct(p1600)}</strong>
         </div>`;
    }

    el.className = `alert mb-0 h-100 d-flex flex-column justify-content-between shadow-soft ${cls.bg}`;
    el.setAttribute('data-sev', cls.key);
    el.setAttribute('role','alert');

    // Tooltip muestra estadoOriginal si existe, útil para trazabilidad
    const badgeTitle = estadoOriginal || cls.key.toUpperCase();

    el.innerHTML =
      `<div class="d-flex justify-content-between align-items-center">
         <div>
           <span class="badge ${cls.badge} me-2" title="${esc(badgeTitle)}">${esc(badgeText)}</span>
           <strong>Fog Risk Indicator: ${Number.isFinite(fri) ? fri.toFixed(0) : 'N/D'}</strong>
         </div>
         <small class="text-secondary">${ts.toLocaleString('es-MX',{hour12:false})}${stale?' · Desactualizado':''}</small>
       </div>` +
      (razones.length
        ? `<div class="mt-1 small">${razones.map(r=>`&#8226; ${esc(r)}`).join(' &#183; ')}</div>`
        : '') +
      footer;
  }

  function fail(err, raw){
    const msg = (err && err.message) ? err.message : String(err || 'error');
    el.className = 'alert mb-0 h-100 d-flex flex-column justify-content-center shadow-soft fri-bg-unknown';
    el.innerHTML =
      `<strong>FRI no disponible</strong> <span class="small text-secondary">(${esc(msg)})</span>` +
      (raw ? `<div class="mt-1 small text-secondary">${esc(raw)}</div>` : '');
  }

  async function fetchFRI(){
    try{
      const res = await fetch(API_FRI, {cache:'no-cache', credentials:'omit'});
      if(!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      paint(data);
    }catch(e){ fail(e); }
  }

  // Carga calibración una vez, tolerante a esquemas distintos
  async function fetchCAL(){
    try{
      const res = await fetch(API_CAL, {cache:'no-cache', credentials:'omit'});
      if(!res.ok) return; // silencioso si no está
      const j = await res.json();
      // Normaliza a {by_hour:{'00':{...}}}
      if (j && typeof j === 'object' && j.by_hour) {
        calib = j;
      } else if (Array.isArray(j)) {
        const by = {};
        j.forEach(r=>{
          const h = String(Number(r.hour)||0).padStart(2,'0');
          by[h] = r;
        });
        calib = { by_hour: by };
      } else if (j && typeof j === 'object') {
        // quizá ya viene como mapa {'00':{...}}
        calib = { by_hour: j };
      }
    }catch{ /* opcional: no frena el flujo */ }
  }

  // Primer render y refrescos
  (async ()=>{
    await fetchCAL();   // best-effort
    await fetchFRI();   // pinta ya
    setInterval(fetchFRI, REFRESH_MS);
    // Relee calibración cada ~15 min por si la regeneras
    setInterval(fetchCAL, 15 * 60 * 1000);
  })();

})();