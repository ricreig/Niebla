(function(){
  const $  = (s)=>document.querySelector(s);
  const $$ = (s)=>Array.from(document.querySelectorAll(s));

  /* ===== DOM (compat xs/md) ===== */
  const tbody = $('#grid tbody');

  // reloj toggle y rango automático
  const clocks = ['#utcClock','#utcClock_m'].map(q=>$(q)).filter(Boolean);
  const rangeHints = ['#rangeHint','#rangeHint_m'].map(q=>$(q)).filter(Boolean);

  const updBtns = ['#btnApply','#btnApply_md'].map(q=>$(q)).filter(Boolean);

  // menús de estado y columnas en xs y md
  const statusMenus = ['#statusFilters','#statusFilters_md'].map(q=>$(q)).filter(Boolean);
  const colMenus    = ['#colToggles','#colToggles_md'].map(q=>$(q)).filter(Boolean);
  const filterInputs = ['#filterText','#filterText_m'].map(q=>$(q)).filter(Boolean);
  const filterClearBtns = ['#filterClear','#filterClear_m'].map(q=>$(q)).filter(Boolean);

  /* ===== Config ===== */
  const API_BASE      = window.API_BASE || (()=> {
    const u = new URL(location.href);
    u.pathname = u.pathname.replace(/\/public(?:\/.*)?$/, '/api/');
    u.search   = '';
    return u.origin + u.pathname;
  })();
  const PROVIDER      = String(window.TIMETABLE_PROVIDER || 'avs').toLowerCase(); // 'avs'|'flights'
  const IATA_AIRPORT  = window.IATA_AIRPORT || 'TIJ';

  /* ===== Estado ===== */
  let USE_LOCAL_TIME = false;
  let SORT_MODE = 'ETA'; // 'ETA' | 'SEC'
  let FILTER_TEXT = '';
  const RMK_STORE = new Map(); // key -> {sec,alt,note,stsOverride}
  window._baseRows = [];
  window._lastRows = [];
  const AUTO_RANGE_DAYS = 2;
  const REFRESH_MINUTES = Math.max(1, Number(window.TIMETABLE_REFRESH_MINUTES || 5));
  let CURRENT_RANGE = null;
  let REFRESHING = false;
  let AUTO_REFRESH_HANDLE = null;

  /* ===== Utils ===== */
  function jget(url, timeoutMs = 10000){
    const ctl = new AbortController();
    const t = setTimeout(()=>ctl.abort(), timeoutMs);
    return fetch(url, {cache:'no-store', signal:ctl.signal})
      .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
      .finally(()=>clearTimeout(t));
  }
  const pad2 = (n)=> String(Math.abs(n)).padStart(2,'0');

function toIsoUtc(isoLike){
  if(!isoLike) return null;
  let s = String(isoLike).trim();
  if(!s) return null;

  s = s.replace(' ', 'T');
  if(!s.includes('T') && /^\d{4}-\d{2}-\d{2}$/.test(s)){
    s += 'T00:00:00';
  }
  if(!/[Zz]$/.test(s) && !/[+\-]\d{2}:?\d{2}$/.test(s)){
    s += 'Z';
  }
  return s;
}

  function setAllText(els, txt){ els.forEach(el=>{ if(el) el.textContent = txt; }); }

  // ===== Spinner helpers =====
  function setBtnLoading(btnEl) {
    if(!btnEl) return;
    // Guardar el HTML original solo si no está ya guardado
    if(!btnEl.dataset.originalHtml){
      btnEl.dataset.originalHtml = btnEl.innerHTML;
      btnEl.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Actualizando…';
    }
    btnEl.disabled = true;
  }
  function clearBtnLoading(btnEl) {
    if(!btnEl) return;
    if(btnEl.dataset.originalHtml){
      btnEl.innerHTML = btnEl.dataset.originalHtml;
      delete btnEl.dataset.originalHtml;
    }
    btnEl.disabled = false;
  }

  function utcHumanRangeLabel(date){
    return `${pad2(date.getUTCDate())}/${pad2(date.getUTCMonth()+1)} ${pad2(date.getUTCHours())}:${pad2(date.getUTCMinutes())} UTC`;
  }
  function computeRangeState(){
    const now = new Date();
    const startLocal = new Date(now);
    startLocal.setHours(0,0,0,0); // inicio de ayer en hora local
    startLocal.setDate(startLocal.getDate() - 1);
    const endLocal = new Date(now);
    endLocal.setHours(23,59,59,999); // fin de hoy en hora local
    const startUTC = new Date(startLocal.toISOString());
    const endUTC = new Date(endLocal.toISOString());
    const rangeHours = Math.max(1, Math.ceil((endUTC.getTime() - startUTC.getTime()) / 3600000));
    const utcDates = [];
    const cursor = new Date(Date.UTC(startUTC.getUTCFullYear(), startUTC.getUTCMonth(), startUTC.getUTCDate()));
    const endCursor = new Date(Date.UTC(endUTC.getUTCFullYear(), endUTC.getUTCMonth(), endUTC.getUTCDate()));
    while(cursor <= endCursor){
      utcDates.push(`${cursor.getUTCFullYear()}-${pad2(cursor.getUTCMonth()+1)}-${pad2(cursor.getUTCDate())}`);
      cursor.setUTCDate(cursor.getUTCDate()+1);
    }
    return {
      startIso: startUTC.toISOString().replace(/\.\d{3}Z$/, 'Z'),
      endIso: endUTC.toISOString().replace(/\.\d{3}Z$/, 'Z'),
      hours: rangeHours,
      utcDates: Array.from(new Set(utcDates))
    };
  }
  function updateRangeLabels(range){
    const start = new Date(range.startIso);
    const end   = new Date(range.endIso);
    const label = `Ventana automática: ${utcHumanRangeLabel(start)} → ${utcHumanRangeLabel(end)}`;
    rangeHints.forEach(el => { if(el) el.textContent = label; });
  }
  function scheduleAutoRefresh(){
    if(AUTO_REFRESH_HANDLE) clearInterval(AUTO_REFRESH_HANDLE);
    AUTO_REFRESH_HANDLE = setInterval(()=> window.refresh(), REFRESH_MINUTES * 60 * 1000);
  }

  function startClock(){
    function tick(){
      const d = new Date();
      const hh = (USE_LOCAL_TIME? d.getHours()   : d.getUTCHours()).toString().padStart(2,'0');
      const mm = (USE_LOCAL_TIME? d.getMinutes() : d.getUTCMinutes()).toString().padStart(2,'0');
      const ss = (USE_LOCAL_TIME? d.getSeconds() : d.getUTCSeconds()).toString().padStart(2,'0');
      setAllText(clocks, (USE_LOCAL_TIME? 'LCL ' : 'UTC ') + `${hh}:${mm}:${ss}`);
    }
    tick();
    clearInterval(window.__clk);
    window.__clk = setInterval(tick, 1000);
    clocks.forEach(el => {
      if(!el) return;
      el.setAttribute('aria-pressed', USE_LOCAL_TIME ? 'true' : 'false');
      el.classList.toggle('clock-local', USE_LOCAL_TIME);
    });
  }

  function toggleClockMode(){
    USE_LOCAL_TIME = !USE_LOCAL_TIME;
    startClock();
    renderGrid(window._lastRows || []);
    applyColumnToggles();
  }

  function fmtETA(iso){
    if(!iso) return '—';
    const d = new Date(iso);
    if(!isFinite(d)) return '—';
    const dd = USE_LOCAL_TIME
      ? `${d.getFullYear().toString().slice(2)}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`
      : `${d.getUTCFullYear().toString().slice(2)}-${pad2(d.getUTCMonth()+1)}-${pad2(d.getUTCDate())}`;
    const hh = USE_LOCAL_TIME
      ? `${pad2(d.getHours())}:${pad2(d.getMinutes())} L`
      : `${pad2(d.getUTCHours())}:${pad2(d.getUTCMinutes())} Z`;
    return `<div class="cell-eta"><span class="d">${dd}</span><span class="t">${hh}</span></div>`;
  }

  /* ===== Estados ===== */
  // Mantengo r.RAW_STS como lo entrega AVS; mapeo a clave de 6 letras SOLO para UI/filtros.
  function rawToSTS6(raw){
    const t = String(raw||'').trim().toLowerCase();
    if (t==='airborne' || t==='active' || t==='enroute' || t==='en-route') return 'ENROUTE';
    if (t==='taxi') return 'TAXI';
    if (t==='landed') return 'LANDED';
    if (t==='scheduled') return 'SCHEDL';
    if (t==='diverted' || t==='redirected') return 'ALTERN';
    if (t==='incident' || t==='accident' || t==='irregular') return 'INCDNT';
    if (t==='canceled' || t==='cancelled' || t==='cncl' || t==='cancld') return 'CANCLD';
    if (t==='delayed' || t==='delay') return 'DELAYED';
    if (t==='unknown') return 'UNKNW';
    return 'UNKNW';
  }
  function effectiveSTS6(row){
    const over = getRMK(row).stsOverride;
    if (over) return over;

    const base = rawToSTS6(row.RAW_STS);
    // Si está en-route/active pero no hay ETA disponible, mostrar TAXI según la lógica solicitada.
    if (base === 'ENROUTE' && !(row.ETA || row.STA)) {
      return 'TAXI';
    }
    return base;
  }
  function badgeSTS6(sts6){
    return `<span class="badge-sts sts-${sts6}">${sts6}</span>`;
  }

  /* ===== FRI (clave 15 min + 1 decimal) ===== */
  function key15mUTC(iso){
    if(!iso) return null;
    const d = new Date(iso);
    if(!isFinite(d)) return null;
    const q = Math.floor(d.getUTCMinutes()/15)*15;
    return `${d.getUTCFullYear()}-${pad2(d.getUTCMonth()+1)}-${pad2(d.getUTCDate())}T${pad2(d.getUTCHours())}:${pad2(q)}`;
  }
  async function fetchFRIMap(){
    const j = await jget(`/mmtj_fog/data/predictions.json?__=${Date.now()}`);
    const out = Object.create(null);
    (Array.isArray(j?.points)? j.points : []).forEach(p=>{
      const k = key15mUTC(p.time || p.iso || p.ts);
      if(!k) return;
      const v = Math.round((Number(p.prob)||0)*1000)/10; // 1 decimal
      out[k] = v;
      const kHr = k.slice(0,13);
      if(out[kHr]==null) out[kHr] = v;
    });
    return out;
  }
  function friBadge(val){
    if(val==null || val==='N/D') return `<span class="badge bg-secondary">N/D</span>`;
    const n   = Number(val);
    const txt = Number.isFinite(n) ? n.toFixed(1) : String(val);
    const cls = n < 30 ? 'success' : n < 60 ? 'warning' : n < 80 ? 'danger' : 'primary';
    return `<span class="badge text-bg-${cls} badge-fri">${txt}</span>`;
  }
  function assignFRI(rows, friMap){
    rows.forEach(r=>{
      const kETA = key15mUTC(r.ETA);
      const kSTA = key15mUTC(r.STA || null);
      const v = (kETA && (friMap[kETA] ?? friMap[kETA?.slice(0,13)])) ??
                (kSTA && (friMap[kSTA] ?? friMap[kSTA?.slice(0,13)])) ?? null;
      if(v!=null) r.FRI = v;
    });
  }

  /* ===== Derivados de tiempo ===== */
  function deriveEET(row){
    const eta = new Date(row.ETA);
    if(!isFinite(eta)) return {txt:'—', cls:'eet-ontime'};

    // finalizados: gris
    const sts6 = effectiveSTS6(row);
    if(['LANDED','ALTERN','CANCLD'].includes(sts6)){
      if(row._ATA){
        const d = new Date(row._ATA);
        const hhmm = USE_LOCAL_TIME ? `${pad2(d.getHours())}:${pad2(d.getMinutes())} L`
                                    : `${pad2(d.getUTCHours())}:${pad2(d.getUTCMinutes())} Z`;
        return {txt: hhmm, cls:'eet-done'};
      }
      return {txt:'00:00', cls:'eet-done'};
    }

    const now = new Date();
    const diffMin = Math.max(0, Math.round((eta.getTime() - now.getTime())/60000));
    const hh = Math.floor(diffMin/60), mm = diffMin%60;
    if(hh===0 && mm===0) return {txt:'', cls:'eet-ontime'};

    // puntualidad simple vs STA
    const sta = row.STA ? new Date(row.STA) : null;
    let cls = 'eet-ontime';
    if(sta && isFinite(sta)){
      if(eta < sta) cls = 'eet-early';
      else if(eta > sta) cls = 'eet-late';
    }
    return {txt:`${pad2(hh)}:${pad2(mm)}`, cls};
  }

  function fmtDelay(min){
    const m = parseInt(min ?? '0',10) || 0;
    if(m<60) return `${m}m`;
    return `${pad2(Math.floor(m/60))}h${pad2(m%60)}m`;
  }

  /* ===== RMK helpers ===== */
  function rowTimeKey(r){
    return r.ETA || r.STA || r.STD || r._ATA || '';
  }
  function rowKey(r){
    const id = r.ID || '—';
    const ts = rowTimeKey(r);
    return `${id}__${(ts||'').slice(0,16)}__${r.ADEP||''}`;
  }
  function rowSortTs(r){
    const iso = rowTimeKey(r);
    if(!iso) return null;
    const ts = Date.parse(iso);
    return Number.isFinite(ts) ? ts : null;
  }
  function getRMK(r){ return RMK_STORE.get(rowKey(r)) || {}; }

  /* ===== Normalización de fila ===== */
  // Mantengo r.RAW_STS (AVS); otros campos para UI.
  function normRow({ eta, sta=null, std=null, id, adep, fri=null, dly='0m', raw_sts='unknown', meta=null }){
    const row = { ETA: eta, STA: sta, STD: std, ID: id, ADEP: adep, FRI: fri ?? 'N/D', DLY: dly, RAW_STS: raw_sts };
    if (meta) row._META = meta;
    return row;
  }

  // Algunos operadores (ej. Volaris, Viva, AMX) se buscan en FR24 por su
  // código IATA y no por ICAO.  Este mapa permite convertir el prefijo ICAO
  // a IATA para construir el enlace correcto.
  const ICAO_TO_IATA_FR24 = {
    'VOI': 'Y4',
    'VIV': 'VB',
    'AMX': 'AM'
  };

  function fr24SearchId(row){
    const meta = row?._META || {};
    const flightNumber = (meta.flight_number || '').toString().trim().toUpperCase();
    if (flightNumber) return flightNumber;

    const iata = (meta.flight_iata || '').toString().trim().toUpperCase();
    if (iata) return iata;

    const icao = (meta.flight_icao || row.ID || '').toString().toUpperCase();
    const m = icao.match(/^([A-Z]{2,3})([0-9].*)$/);
    if (m) {
      const mapped = ICAO_TO_IATA_FR24[m[1]];
      if (mapped) return `${mapped}${m[2]}`;
    }
    return icao || '';
  }

  /* ===== Fuente AVS por día ===== */
  const pickT = (o)=> [o?.estimatedTime,o?.estimated,o?.scheduledTime,o?.scheduled,o?.actualTime,o?.actual].find(Boolean) || null;

  function icaoFromPieces(airline, flight){
    const a = airline || {}, f = flight || {};
    const icao = a.icaoCode || a.icao || a.code_icao || a.codeIcao || a.iataCode || '';
    const num  = (f.icaoNumber && String(f.icaoNumber).replace(/^[A-Z]+/,'')) || f.number || f.iataNumber || '';
    return (icao && num) ? `${icao}${num}` : (f.icaoNumber || f.iataNumber || f.number || '—');
  }

  function preferIcao(row){
    const icao = (row.flight_icao || row.flight_ICAO || '').toString().toUpperCase();
    const airline = (row.airline_icao || row.AIRLINE_ICAO || '').toString().toUpperCase();
    const number = (row.flight_number || row.FLIGHT_NUMBER || '').toString().toUpperCase();
    if(icao) return icao;
    if(airline && number) return `${airline}${String(number).replace(/^[A-Z]+/, '')}`;
    return row.registration || row.ID || '—';
  }

  async function loadAVSForDate(yyyy_mm_dd){
    const url = `${API_BASE}avs_timetable.php?type=arrival&iata=${encodeURIComponent(IATA_AIRPORT)}&date=${encodeURIComponent(yyyy_mm_dd)}&ttl=60`;
    const j   = await jget(url);

    // backend normalizado -> {rows:[...]} o {ok:true,data:[...]}
    if (Array.isArray(j?.rows)) {
      return j.rows.map(r => normRow({
        eta: toIsoUtc(r.eta_utc || r.sta_utc || null),
        sta: toIsoUtc(r.sta_utc || r.eta_utc || null),
        std: toIsoUtc(r.std_utc || null),
        id : r.flight_icao || r.flight_iata || r.flight || '—',
        adep: r.dep_iata || r.dep_icao || '—',
        fri: r.fri ?? null,
        dly: fmtDelay(r.delay_min),
        raw_sts: r.status || 'unknown',
        meta: {
          flight_number: r.flight_number || r.flight || null,
          flight_iata  : r.flight_iata || r.flight_number || r.flight || null,
          flight_icao  : r.flight_icao || null
        }
      }));
    }

    const data = Array.isArray(j?.data) ? j.data : [];
    // omitir códigos compartidos
    const rows = data.filter(x=>!x.codeshared).map(x=>{
      const dep  = x.departure || {};
      const arr  = x.arrival   || {};
      const fl   = x.flight    || {};
      const al   = x.airline   || {};
      const etaISO = pickT(arr);
      const staISO = arr?.scheduledTime || arr?.scheduled || null;
      const stdISO = pickT(dep) || null;
      const id  = icaoFromPieces(al, fl);
      const adep= dep.iataCode || dep.iata || dep.icaoCode || dep.icao || '—';
      const dly = fmtDelay(parseInt(arr.delay ?? '0',10) || 0);
      const ata = arr?.actualTime || arr?.actual || null;
      const raw = x.status || x.flight_status || 'unknown';

      const meta = {
        flight_number: fl.number || fl.iataNumber || fl.icaoNumber || null,
        flight_iata: fl.iata || fl.iataNumber || fl.number || null,
        flight_icao: fl.icaoNumber || id || null,
        callsign   : fl.icaoNumber || null,
        airline    : al.name || al.iataCode || al.icaoCode || null,
        dep_iata   : dep.iataCode || dep.iata || null,
        dep_icao   : dep.icaoCode || dep.icao || null,
        dst_iata   : arr.iataCode || arr.iata || null,
        dst_icao   : arr.icaoCode || arr.icao || null,
        ac_type    : (x.aircraft || {}).icaoCode || null,
        ac_reg     : (x.aircraft || {}).regNumber || null
      };
      meta.route = meta.dep_icao && meta.dst_icao ? `${meta.dep_icao} → ${meta.dst_icao}` : null;

      const row = normRow({ eta: etaISO, sta: staISO, std: stdISO, id, adep, dly, raw_sts: raw, meta });
      if(ata) row._ATA = ata;
      return row;
    });
    return rows;
  }

  /* ===== Fuente integrada de vuelos (fr24 + avs) ===== */
  async function loadFlights(hours, startIso){
    // fallback to 24 hours if invalid
    const h = (typeof hours==='number' && hours>0) ? hours : 24;
    // Construct URL to flights.php with optional start parameter (ISO8601Z).  When
    // querying past days, the start parameter allows the backend to switch
    // automatically from the AVS+FR24 timetable to the FR24 summary (which has
    // historical data).  If startIso is falsy or empty, we omit the parameter
    // so that the backend defaults to `now`.
    let url = `${API_BASE}flights.php?hours=${encodeURIComponent(h)}`;
    if (startIso) {
      url += `&start=${encodeURIComponent(startIso)}`;
    }
    const j   = await jget(url);
    const data = Array.isArray(j?.rows) ? j.rows : (Array.isArray(j?.data) ? j.data : []);
    const rows = [];
    for(const r of data){
      // Normalize using the same schema as AVS
      const eta = r.eta_utc || r.ETA || null;
      const sta = r.sta_utc || r.STA || null;
      const std = r.std_utc || r.STD || null;
      // ID logic: prefer ICAO identifier; derive from airline+number if needed
      const id  = preferIcao(r);
      const adep= r.dep_iata || r.ADEP || '—';
      const fri = (typeof r.fri_pct === 'number' && r.fri_pct>=0) ? (Math.round(r.fri_pct*10)/10) : null;
      const dly = fmtDelay(parseInt(r.delay_min ?? '0',10) || 0);
      const raw = r.status || r.RAW_STS || 'unknown';
      const meta = {
        flight_number: r.flight_number || r.flight || null,
        flight_iata: r.flight_iata || r.flight_number || r.flight || null,
        flight_icao: preferIcao(r) || null,
        callsign   : r.callsign || r.call_sign || r.flight_icao || null,
        airline    : r.airline || r.airline_name || r.airline_iata || r.airline_icao || null,
        dep_iata   : r.dep_iata || r.departure_iata || r.ADEP || null,
        dep_icao   : r.dep_icao || r.departure_icao || null,
        dst_iata   : r.dst_iata || r.arr_iata || null,
        dst_icao   : r.dst_icao || r.arr_icao || null,
        ac_type    : r.ac_type || r.aircraft_icao || null,
        ac_reg     : r.ac_reg || r.aircraft_registration || null
      };
      meta.route = meta.dep_icao && meta.dst_icao ? `${meta.dep_icao} → ${meta.dst_icao}` : null;

      const row = normRow({ eta: eta, sta: sta, std: std, id: id, adep: adep, fri: fri, dly: dly, raw_sts: raw, meta });
      if (r.ata_utc || r._ATA) row._ATA = r.ata_utc || r._ATA;
      // Add alternate airport if provided
      if (r.dest_iata_actual) row._ALT = String(r.dest_iata_actual).toUpperCase();
      rows.push(row);
    }
    return rows;
  }

  /* ===== Filtros ===== */
  function statusWhitelist(){
    const chosen = new Set();
    statusMenus.forEach(menu=>{
      menu.querySelectorAll('input[type=checkbox]').forEach(ch=>{
        if(ch.checked) chosen.add(ch.value.toUpperCase()); // valores ya en 6 letras
      });
    });
    // por defecto incluimos todos los estados principales; si el usuario no elige ninguno, se muestran también los aterrizados
        return chosen.size ? chosen : new Set(['ENROUTE','SCHEDL','LANDED','DELAYED','ALTERN','CANCLD','INCDNT','UNKNW','TAXI']);
  }

  function applyColumnToggles(){
    if(!colMenus.length) return;
    const ths = Array.from(document.querySelectorAll('#grid thead th'));
    const mapIdx = {'ETA':0,'ID':1,'ADEP':2,'FRI':3,'EET':4,'DLY':5,'STS':6,'RMK':7};
    const state = Object.create(null); Object.keys(mapIdx).forEach(k => state[k] = false);

    colMenus.forEach(menu=>{
      menu.querySelectorAll('input[type="checkbox"]').forEach(ch=>{
        const name = String(ch.value||'').toUpperCase();
        if(name in state && ch.checked) state[name] = true;
      });
    });

    Object.entries(mapIdx).forEach(([name,idx])=>{
      const show = !!state[name];
      if(ths[idx]) ths[idx].style.display = show ? '' : 'none';
      $$('#grid tbody tr').forEach(tr=>{
        const td = tr.children[idx]; if(td) td.style.display = show ? '' : 'none';
      });
    });
  }

  /* ===== Orden y filtro de texto ===== */
  function sortRowsInPlace(rows){
    rows.sort((a,b)=>{
      if(SORT_MODE==='SEC'){
        const A = a._SEC ?? Infinity, B = b._SEC ?? Infinity;
        if(A!==B) return A-B;
      }
      const A = rowSortTs(a);
      const B = rowSortTs(b);
      const hasA = Number.isFinite(A);
      const hasB = Number.isFinite(B);
      if(hasA && hasB) return B - A; // más recientes primero
      if(hasA) return -1;  // A tiene hora, B no → A arriba
      if(hasB) return 1;   // B tiene hora, A no → B arriba
      return 0;
    });
    return rows;
  }

  function rowMatchesFilter(row, q){
    if(!q) return true;
    const needle = q.toUpperCase();
    const fields = [row.ID, row.ADEP, row._ALT]
      .map(v => (v==null ? '' : String(v)).toUpperCase());
    return fields.some(val => val.includes(needle));
  }

  function applyTextFilter(rows){
    const src = Array.isArray(rows) ? rows : [];
    const query = (FILTER_TEXT || '').trim();
    if(!query) return src.slice();
    return src.filter(r => rowMatchesFilter(r, query));
  }

  function renderCurrentView(){
    const base = Array.isArray(window._baseRows) ? window._baseRows.slice() : [];
    sortRowsInPlace(base);
    const view = applyTextFilter(base);
    renderGrid(view);
    applyColumnToggles();
  }

  /* ===== Orquestador ===== */
  async function loadTimetable(){
    const range = CURRENT_RANGE || computeRangeState();
    // 1) Determine provider
    if (PROVIDER === 'flights') {
      const hours = Math.max(1, Math.min(336, range.hours));
      const startIso = range.startIso;
      let rows = await loadFlights(hours, startIso);
      // FRI map (if fri_pct not already set)
      try{ assignFRI(rows, await fetchFRIMap()); }catch(_){ }
      // modal overrides
      rows.forEach(r=>{
        const st = RMK_STORE.get(rowKey(r)); if(!st) return;
        if(st.stsOverride) r._OVR_STS6 = st.stsOverride;
        if(st.sec != null) r._SEC = st.sec;
        if(st.alt) r._ALT = st.alt;
        if(st.note) r._NOTE = st.note;
      });

      // filter by status
      const allow = statusWhitelist();
      const filtered = rows.filter(r => allow.has(effectiveSTS6(r)));
      // sort by ETA or SEC
      return sortRowsInPlace(filtered);
    }

    // Default provider: AVS timetable
    const dates = (range.utcDates && range.utcDates.length) ? range.utcDates : [new Date().toISOString().slice(0,10)];
    // 2) fetch por día (AVS)
    const chunks = await Promise.all(dates.map(loadAVSForDate));
    // 3) aplanar resultados y eliminar duplicados en función de clave (ID+ETA+ADEP)
    let rows = [];
    const seen = new Map();
    for(const chunk of chunks){
      for(const r of chunk){
        const key = rowKey(r);
        if(!seen.has(key)){
          seen.set(key, r);
        }
      }
    }
    rows = Array.from(seen.values());
    // 4) FRI
    try{ assignFRI(rows, await fetchFRIMap()); }catch(_){ }
    // 5) overrides del modal
    rows.forEach(r=>{
      const st = RMK_STORE.get(rowKey(r)); if(!st) return;
      if(st.stsOverride) r._OVR_STS6 = st.stsOverride;
      if(st.sec != null) r._SEC = st.sec;
      if(st.alt) r._ALT = st.alt;
      if(st.note) r._NOTE = st.note;
    });
    // 6) filtro por estado (clave efectiva)
    const allow = statusWhitelist();
    const filtered = rows.filter(r => allow.has(effectiveSTS6(r)));
    // 7) orden
    return sortRowsInPlace(filtered);
  }

/* ===== Stats ===== */
function updateStatsCard(rows){
  const el = document.getElementById('stats'); 
  if (!el) return;

  const c = {ENROUTE:0,SCHEDL:0,LANDED:0,CANCLD:0,ALTERN:0,INCDNT:0,UNKNW:0};

  rows.forEach(r => {
    // Determine the 6-letter status code via effectiveSTS6
    const sts6 = effectiveSTS6(r);
    switch (sts6) {
      case 'ENROUTE':
      case 'TAXI':
        c.ENROUTE++; 
        break;
      case 'SCHEDL':
        c.SCHEDL++; 
        break;
      case 'LANDED':
        c.LANDED++; 
        break;
      case 'CANCLD':
        c.CANCLD++; 
        break;
      case 'ALTERN':
        c.ALTERN++; 
        break;
      case 'INCDNT':
        c.INCDNT++; 
        break;
      default:
        c.UNKNW++;
    }
  });

  const total = c.ENROUTE + c.SCHEDL + c.LANDED + c.CANCLD + c.ALTERN + c.INCDNT + c.UNKNW;

  const lines = [
    `<div class="item"><span class="label">En-Route:</span><span class="val">${c.ENROUTE}</span></div>`,
    `<div class="item"><span class="label">Scheduled:</span><span class="val">${c.SCHEDL}</span></div>`,
    `<div class="item"><span class="label">Landed:</span><span class="val">${c.LANDED}</span></div>`,
    `<div class="item"><span class="label">Canceled:</span><span class="val">${c.CANCLD}</span></div>`,
    `<div class="item"><span class="label">Diverted:</span><span class="val">${c.ALTERN}</span></div>`
  ];

  // Solo mostrar Incident si hay > 0
  if (c.INCDNT > 0) {
    lines.push(
      `<div class="item"><span class="label">Incident:</span><span class="val">${c.INCDNT}</span></div>`
    );
  }

  // Solo mostrar Unknown si hay > 0
  if (c.UNKNW > 0) {
    lines.push(
      `<div class="item"><span class="label">Unknown:</span><span class="val">${c.UNKNW}</span></div>`
    );
  }

  lines.push(
    `<div class="my-1"></div>`,
    `<div class="item fw-bold"><span class="label">Total Flights</span><span class="val">${total}</span></div>`
  );

  el.innerHTML = lines.join('');
}

  /* ===== Modal RMK (override visual, no toca RAW_STS) ===== */
  function ensureRMKModal(){
    let m = $('#rmkModal'); if(m) return m;
    const wrap = document.createElement('div');
    wrap.innerHTML = `
<div class="modal fade" id="rmkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-body-tertiary">
      <div class="modal-header">
        <h5 class="modal-title">Detalle de vuelo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="small text-muted mb-2" id="rmkHdr"></div>
        <div class="mb-3" id="rmkDetails"></div>
        <div class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label">Secuencia</label>
            <div class="input-group">
              <button class="btn btn-outline-secondary" id="secMinus">−</button>
              <input id="secVal" type="number" class="form-control" min="1" step="1" placeholder="—">
              <button class="btn btn-outline-secondary" id="secPlus">+</button>
            </div>
          </div>
          <div class="col-6">
            <label class="form-label">Alterno</label>
            <input id="altVal" type="text" class="form-control" placeholder="MMGL, MMLM…">
          </div>
        </div>
          <div class="mb-2">
          <label class="form-label me-2">Estatus (visual)</label>
          <div class="btn-group btn-group-sm flex-wrap" role="group">
                ${['ENROUTE','SCHEDL','DELAYED','ALTERN','CANCLD','LANDED','INCDNT','TAXI','UNKNW'].map(s=>`<button type="button" class="btn btn-outline-light btn-sts" data-sts="${s}">${s}</button>`).join('')}
            <button type="button" class="btn btn-outline-secondary" id="stsReset">Reset</button>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label">RMK</label>
          <textarea id="rmkTxt" class="form-control" rows="3" placeholder="Notas"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-primary" id="rmkSave">Guardar</button>
      </div>
    </div>
  </div>
</div>`;
    document.body.appendChild(wrap.firstElementChild);
    return $('#rmkModal');
  }

  function openRMK(row){
    const mEl  = ensureRMKModal();
    const hdr  = mEl.querySelector('#rmkHdr');
    const det  = mEl.querySelector('#rmkDetails');
    const secV = mEl.querySelector('#secVal');
    const altV = mEl.querySelector('#altVal');
    const txt  = mEl.querySelector('#rmkTxt');
    const btns = mEl.querySelectorAll('.btn-sts');
    const reset= mEl.querySelector('#stsReset');
    const save = mEl.querySelector('#rmkSave');

    hdr.textContent = `ETA ${new Date(row.ETA).toISOString().slice(11,16)}Z · ${row.ID} · ADEP ${row.ADEP} · RAW ${row.RAW_STS}`;

    const meta = row._META || {};
    const origin = meta.dep_icao || meta.dep_iata || row.ADEP || '—';
    const dest   = meta.dst_icao || meta.dst_iata || row._ALT || 'MMTJ';
    const route  = meta.route || ((origin && dest && origin !== '—') ? `${origin} → ${dest}` : null);

    const items = [
      {label:'ID IATA', val: meta.flight_iata || row.ID || '—'},
      {label:'ID ICAO', val: meta.flight_icao || row.ID || '—'},
      {label:'Callsign', val: meta.callsign || '—'},
      {label:'Aerolínea', val: meta.airline || '—'},
      {label:'Origen', val: origin},
      {label:'Destino', val: dest},
      {label:'Tipo aeronave', val: meta.ac_type || '—'},
      {label:'Matrícula', val: meta.ac_reg || '—'},
      {label:'Ruta', val: route || '—'}
    ];

    det.innerHTML = `
      <div class="small text-uppercase text-secondary fw-semibold mb-1">Información completa</div>
      <dl class="row row-cols-1 g-1 small mb-0">
        ${items.map(i => `<div class="col"><dt class="text-muted mb-0">${i.label}</dt><dd class="mb-1 fw-semibold">${i.val}</dd></div>`).join('')}
      </dl>`;

    const key   = rowKey(row);
    const stash = RMK_STORE.get(key) || {};
    secV.value = stash.sec ?? '';
    altV.value = stash.alt ?? row._ALT ?? '';
    txt.value  = stash.note ?? row._NOTE ?? '';
    btns.forEach(b => b.classList.toggle('active', stash.stsOverride===b.dataset.sts));

    mEl.querySelector('#secMinus').onclick = ()=>{ const v=parseInt(secV.value||'0',10)||0; secV.value= Math.max(1,v-1); };
    mEl.querySelector('#secPlus').onclick  = ()=>{ const v=parseInt(secV.value||'0',10)||0; secV.value= v+1; };

    btns.forEach(b=> b.onclick = ()=> {
      btns.forEach(x=>x.classList.remove('active'));
      b.classList.add('active');
      stash.stsOverride = b.dataset.sts;
    });
    reset.onclick = ()=>{
      btns.forEach(x=>x.classList.remove('active'));
      delete stash.stsOverride; delete stash.sec; delete stash.alt; delete stash.note;
      secV.value=''; altV.value=''; txt.value='';
      RMK_STORE.set(key, {...stash});
      renderGrid(window._lastRows||[]);
    };
    save.onclick = ()=>{
      const s = Object.assign({}, stash, {
        sec : secV.value? parseInt(secV.value,10): undefined,
        alt : altV.value?.trim() || undefined,
        note: txt.value?.trim() || undefined
      });
      RMK_STORE.set(key, s);
      renderGrid(window._lastRows || []);
      const modal = bootstrap.Modal.getOrCreateInstance(mEl); modal.hide();
    };

    bootstrap.Modal.getOrCreateInstance(mEl).show();
  }

  /* ===== Render ===== */
  function renderGrid(rows){
    tbody.innerHTML = '';
    if(!rows.length){
      tbody.innerHTML = `<tr><td colspan="8" class="text-muted">Sin datos de vuelos</td></tr>`;
      updateStatsCard(rows);
      return;
    }
    for(const r of rows){
      const {txt:eetTxt, cls:eetCls} = deriveEET(r);
      const stash = getRMK(r);
      const sts6 = effectiveSTS6(r);
      const fr24Id = fr24SearchId(r);
      const fr24Url = fr24Id ? `https://www.flightradar24.com/${encodeURIComponent(fr24Id)}` : '';

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="cell-eta-wrap">${fmtETA(r.ETA)}</td>
        <td class="cell-id">${fr24Url ? `<a href="${fr24Url}" target="_blank" rel="noopener">${r.ID}</a>` : r.ID}</td>
        <td>${r.ADEP}</td>
        <td>${friBadge(r.FRI)}</td>
        <td class="${eetCls}">${eetTxt}</td>
        <td>${r.DLY||'0m'}</td>
        <td>${badgeSTS6(sts6)}</td>
        <td>
          <div class="d-flex align-items-center gap-1">
            <button class="btn btn-sm btn-outline-secondary btn-rmk" type="button" title="RMK"><i class="bi bi-gear"></i></button>
            <div class="small text-muted">${stash.sec!=null? '#'+stash.sec : ''}</div>
          </div>
          ${ (stash.alt || r._ALT) ? `<div class="small text-info">${stash.alt || r._ALT}</div>` : '' }
        </td>`;
      tbody.appendChild(tr);
      tr.querySelector('.btn-rmk').addEventListener('click', ()=> openRMK(r));
    }
    updateStatsCard(rows);
    window._lastRows = rows;
  }

  /* ===== API pública ===== */
  window.refresh = async function(triggerBtn){
    // Si se pasa el botón y está deshabilitado, no hacer nada
    const btnEl = (triggerBtn instanceof HTMLElement) ? triggerBtn : null;
    if(btnEl && btnEl.disabled) return;
    // Evitar múltiples refrescos concurrentes
    if(REFRESHING) return;
    CURRENT_RANGE = computeRangeState();
    updateRangeLabels(CURRENT_RANGE);
    scheduleAutoRefresh();
    REFRESHING = true;
    if(btnEl) setBtnLoading(btnEl);
    try{
      const rows = await loadTimetable();
      window._baseRows = rows;
      renderCurrentView();
    }catch(e){
      tbody.innerHTML = `<tr><td colspan="8" class="text-danger">Error timetable: ${String(e.message||e)}</td></tr>`;
      window._baseRows = [];
      window._lastRows = [];
    }finally{
      REFRESHING = false;
      if(btnEl) clearBtnLoading(btnEl);
    }
  };
  window.toggleSort = function(){
    SORT_MODE = (SORT_MODE==='ETA') ? 'SEC' : 'ETA';
    renderCurrentView();
  };

  /* ===== Listeners ===== */
  document.addEventListener('DOMContentLoaded', ()=>{
    updBtns.forEach(b=> b?.addEventListener('click', ev => {
      const btn = ev.currentTarget;
      if(!btn) return;
      window.refresh(btn);
    }));
    // Cross-bind status filters: replicate changes across menus and refresh
    statusMenus.forEach(menu => {
      menu.querySelectorAll('input[type="checkbox"]').forEach(ch => {
        ch.addEventListener('change', () => {
          const val = String(ch.value || '').toUpperCase();
          const checked = ch.checked;
          statusMenus.forEach(other => {
            other.querySelectorAll('input[type="checkbox"]').forEach(ch2 => {
              if(ch2 !== ch && String(ch2.value || '').toUpperCase() === val) ch2.checked = checked;
            });
          });
          window.refresh();
        });
      });
    });
    // Cross-bind column toggles: replicate changes and apply immediately
      colMenus.forEach(menu => {
        menu.querySelectorAll('input[type="checkbox"]').forEach(ch => {
        ch.addEventListener('change', () => {
          const val = String(ch.value || '').toUpperCase();
          const checked = ch.checked;
          colMenus.forEach(other => {
            other.querySelectorAll('input[type="checkbox"]').forEach(ch2 => {
              if(ch2 !== ch && String(ch2.value || '').toUpperCase() === val) ch2.checked = checked;
            });
          });
          // apply toggles to current grid
          renderGrid(window._lastRows ? window._lastRows.slice() : []);
          applyColumnToggles();
        });
      });
      });
      filterInputs.forEach(input => {
        input.addEventListener('input', ev => {
          FILTER_TEXT = String(ev.target?.value || '').trim();
          filterInputs.forEach(other => {
            if(other && other !== input) other.value = FILTER_TEXT;
          });
          renderCurrentView();
        });
      });
      filterClearBtns.forEach(btn => {
        btn.addEventListener('click', () => {
          FILTER_TEXT = '';
          filterInputs.forEach(inp => { if(inp) inp.value = ''; });
          renderCurrentView();
        });
      });
    clocks.forEach(clock => {
      if(!clock) return;
      clock.addEventListener('click', toggleClockMode);
      clock.addEventListener('keydown', ev => {
        if(ev.key === 'Enter' || ev.key === ' '){
          ev.preventDefault();
          toggleClockMode();
        }
      });
    });
    CURRENT_RANGE = computeRangeState();
    updateRangeLabels(CURRENT_RANGE);
    startClock();
    scheduleAutoRefresh();
    window.refresh();
  });
})();
