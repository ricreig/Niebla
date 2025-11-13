// public/assets/js/app_ops.js
(function(){
  const $  = (s)=>document.querySelector(s);
  const $$ = (s)=>Array.from(document.querySelectorAll(s));

  /* ===== DOM (compat xs/md) ===== */
  const tbody = $('#grid tbody');

  // reloj y toggle en desktop + móvil
  const clocks = ['#utcClock','#utcClock_m'].map(q=>$(q)).filter(Boolean);
  const tzBtns = ['#btnTZ','#btnTZ_m'].map(q=>$(q)).filter(Boolean);

  // fechas en xs y md
  const fromEls = ['#dtFrom','#dtFrom_md'].map(q=>$(q)).filter(Boolean);
  const toEls   = ['#dtTo','#dtTo_md'].map(q=>$(q)).filter(Boolean);
  const updBtns = ['#btnApply','#btnApply_md'].map(q=>$(q)).filter(Boolean);

  let REFRESHING = false;

  // menús de estado y columnas en xs y md
  const statusMenus = ['#statusFilters','#statusFilters_md'].map(q=>$(q)).filter(Boolean);
  const colMenus    = ['#colToggles','#colToggles_md'].map(q=>$(q)).filter(Boolean);

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
  const RMK_STORE = new Map(); // key -> {sec,alt,note,stsOverride}
  window._lastRows = [];

  /* ===== Utils ===== */
  function jget(url, timeoutMs = 10000){
    const ctl = new AbortController();
    const t = setTimeout(()=>ctl.abort(), timeoutMs);
    return fetch(url, {cache:'no-store', signal:ctl.signal})
      .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
      .finally(()=>clearTimeout(t));
  }
  const pad2 = (n)=> String(Math.abs(n)).padStart(2,'0');

  function firstValue(elArr){ for(const el of elArr){ if(el && el.value) return el.value; } return ''; }
  function setAllText(els, txt){ els.forEach(el=>{ if(el) el.textContent = txt; }); }

  // === UTC helpers para inputs datetime-local ===
  function utcNowInputValue(){
    const d = new Date();
    return `${d.getUTCFullYear()}-${pad2(d.getUTCMonth()+1)}-${pad2(d.getUTCDate())}T${pad2(d.getUTCHours())}:${pad2(d.getUTCMinutes())}`;
  }
  function parseInputAsUTC(v){
    if(!v) return null;
    const d = new Date(v + 'Z'); // fuerza UTC
    return isFinite(d) ? d : null;
  }
  function padDateUTC(d){ return `${d.getUTCFullYear()}-${pad2(d.getUTCMonth()+1)}-${pad2(d.getUTCDate())}`; }

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
    if (t==='landed') return 'LANDED';
    if (t==='scheduled') return 'SCHEDL';
    if (t==='diverted') return 'ALTERN';
    if (t==='canceled' || t==='cancelled' || t==='cncl' || t==='cancld') return 'CANCLD';
    if (t==='delayed' || t==='delay') return 'DELAYED';
    if (t==='unknown') return 'UNKNW';
    return 'UNKNW';
  }
  function effectiveSTS6(row){
    const over = getRMK(row).stsOverride;
    return over ? over : rawToSTS6(row.RAW_STS);
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
  function rowKey(r){ return `${r.ID}__${(r.ETA||'').slice(0,16)}__${r.ADEP||''}`; }
  function getRMK(r){ return RMK_STORE.get(rowKey(r)) || {}; }

  /* ===== Normalización de fila ===== */
  // Mantengo r.RAW_STS (AVS); otros campos para UI.
  function normRow({ eta, sta=null, id, adep, fri=null, dly='0m', raw_sts='unknown' }){
    return { ETA: eta, STA: sta, ID: id, ADEP: adep, FRI: fri ?? 'N/D', DLY: dly, RAW_STS: raw_sts };
  }

  /* ===== Fuente AVS por día ===== */
  const pickT = (o)=> [o?.estimatedTime,o?.estimated,o?.scheduledTime,o?.scheduled,o?.actualTime,o?.actual].find(Boolean) || null;

  function icaoFromPieces(airline, flight){
    const a = airline || {}, f = flight || {};
    const icao = a.icaoCode || a.icao || a.code_icao || a.codeIcao || a.iataCode || '';
    const num  = (f.icaoNumber && String(f.icaoNumber).replace(/^[A-Z]+/,'')) || f.number || f.iataNumber || '';
    return (icao && num) ? `${icao}${num}` : (f.icaoNumber || f.iataNumber || f.number || '—');
  }

  async function loadAVSForDate(yyyy_mm_dd){
    const url = `${API_BASE}avs_timetable.php?type=arrival&iata=${encodeURIComponent(IATA_AIRPORT)}&date=${encodeURIComponent(yyyy_mm_dd)}&ttl=60`;
    const j   = await jget(url);

    // backend normalizado -> {rows:[...]} o {ok:true,data:[...]}
    if (Array.isArray(j?.rows)) {
      return j.rows.map(r => normRow({
        eta: r.eta_utc || null,
        sta: r.sta_utc || null,
        id : r.flight_icao || r.flight || '—',
        adep: r.dep_iata || r.dep_icao || '—',
        fri: r.fri ?? null,
        dly: fmtDelay(r.delay_min),
        raw_sts: r.status || 'unknown'
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
      const id  = icaoFromPieces(al, fl);
      const adep= dep.iataCode || dep.iata || dep.icaoCode || dep.icao || '—';
      const dly = fmtDelay(parseInt(arr.delay ?? '0',10) || 0);
      const ata = arr?.actualTime || arr?.actual || null;
      const raw = x.status || x.flight_status || 'unknown';

      const row = normRow({ eta: etaISO, sta: staISO, id, adep, dly, raw_sts: raw });
      if(ata) row._ATA = ata;
      return row;
    });
    return rows;
  }

  function eachDateInclusive(fromDate, toDate){
    const out = [];
    let d = new Date(Date.UTC(fromDate.getUTCFullYear(), fromDate.getUTCMonth(), fromDate.getUTCDate()));
    const end = new Date(Date.UTC(toDate.getUTCFullYear(), toDate.getUTCMonth(), toDate.getUTCDate()));
    while(d <= end){ out.push(padDateUTC(d)); d.setUTCDate(d.getUTCDate()+1); }
    return out;
  }

  function readDatesUTC(){
    // usa cualquiera que tenga valor (xs/md) y parsea en UTC
    const vFrom = firstValue(fromEls);
    const vTo   = firstValue(toEls);
    const f = parseInputAsUTC(vFrom);
    const t = parseInputAsUTC(vTo || vFrom);
    if(!(f && t)) return [];
    return eachDateInclusive(f, t);
  }

  /* ===== Filtros ===== */
  function statusWhitelist(){
    const chosen = new Set();
    statusMenus.forEach(menu=>{
      menu.querySelectorAll('input[type=checkbox]').forEach(ch=>{
        if(ch.checked) chosen.add(ch.value.toUpperCase()); // valores ya en 6 letras
      });
    });
    // por default ocultamos LANDED
    return chosen.size ? chosen : new Set(['ENROUTE','SCHEDL','DELAYED','ALTERN','CANCLD','UNKNW']);
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

  function setLoading(flag){
    updBtns.forEach(btn=>{
      if(!btn) return;
      btn.disabled = flag;
      btn.classList.toggle('is-loading', flag);
      if(flag) btn.setAttribute('aria-busy','true'); else btn.removeAttribute('aria-busy');
    });
  }

  /* ===== Orquestador ===== */
  async function loadTimetable(){
    // 1) fechas UTC
    let dates = readDatesUTC();
    if(dates.length===0){
      const now = new Date();
      dates = [ padDateUTC(new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()))) ];
    }

    // 2) fetch por día
    const chunks = await Promise.all(dates.map(loadAVSForDate));
    let rows = chunks.flat();

    // 3) filtro horario por FROM/TO en UTC
    const fUTC = parseInputAsUTC(firstValue(fromEls));
    const tUTC = parseInputAsUTC(firstValue(toEls));
    if(fUTC){
      const fromMs = fUTC.getTime();
      rows = rows.filter(r => r.ETA && Date.parse(r.ETA) >= fromMs);
    }
    if(tUTC){
      const toMs = tUTC.getTime();
      rows = rows.filter(r => r.ETA && Date.parse(r.ETA) <= toMs);
    }

    // 4) FRI
    try{ assignFRI(rows, await fetchFRIMap()); }catch(_){}

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
    filtered.sort((a,b)=>{
      if(SORT_MODE==='SEC'){
        const A = a._SEC ?? Infinity, B = b._SEC ?? Infinity;
        if(A!==B) return A-B;
      }
      const A = a.ETA ? Date.parse(a.ETA) : 0;
      const B = b.ETA ? Date.parse(b.ETA) : 0;
      return A - B;
    });

    return filtered;
  }

  /* ===== Stats ===== */
function updateStatsCard(rows){
  const el = document.getElementById('stats'); if(!el) return;
  const c = {ENROUTE:0,SCHEDL:0,LANDED:0,CANCLD:0,ALTERN:0,UNKNW:0};
  rows.forEach(r=>{ const k = (r._OVR_STS6 || r.STS || r.RAW_STS); const kk = (k||'').toString().toUpperCase();
    if(kk.includes('ENROUTE')) c.ENROUTE++;
    else if(kk.includes('SCHEDL') || kk.includes('SCHEDULED')) c.SCHEDL++;
    else if(kk.includes('LANDED')) c.LANDED++;
    else if(kk.includes('CANCLD') || kk.includes('CANCELED') || kk.includes('CANCELLED')) c.CANCLD++;
    else if(kk.includes('ALTERN') || kk.includes('DIVERTED')) c.ALTERN++;
    else c.UNKNW++;
  });
  const total = c.ENROUTE + c.SCHEDL + c.LANDED + c.CANCLD + c.ALTERN + c.UNKNW;

  el.innerHTML = [
    `<div class="item"><span class="label">En-Route:</span><span class="val">${c.ENROUTE}</span></div>`,
    `<div class="item"><span class="label">Scheduled:</span><span class="val">${c.SCHEDL}</span></div>`,
    `<div class="item"><span class="label">Landed:</span><span class="val">${c.LANDED}</span></div>`,
    `<div class="item"><span class="label">Canceled:</span><span class="val">${c.CANCLD}</span></div>`,
    `<div class="item"><span class="label">Diverted:</span><span class="val">${c.ALTERN}</span></div>`,
    `<div class="item"><span class="label">Unknown:</span><span class="val">${c.UNKNW}</span></div>`,
    `<div class="my-1"></div>`,
    `<div class="item fw-bold"><span class="label">Total Flights</span><span class="val">${total}</span></div>`
  ].join('');
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
            ${['ENROUTE','SCHEDL','DELAYED','ALTERN','CANCLD','LANDED','UNKNW'].map(s=>`<button type="button" class="btn btn-outline-light btn-sts" data-sts="${s}">${s}</button>`).join('')}
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
    const secV = mEl.querySelector('#secVal');
    const altV = mEl.querySelector('#altVal');
    const txt  = mEl.querySelector('#rmkTxt');
    const btns = mEl.querySelectorAll('.btn-sts');
    const reset= mEl.querySelector('#stsReset');
    const save = mEl.querySelector('#rmkSave');

    hdr.textContent = `ETA ${new Date(row.ETA).toISOString().slice(11,16)}Z · ${row.ID} · ADEP ${row.ADEP} · RAW ${row.RAW_STS}`;

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

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="cell-eta-wrap">${fmtETA(r.ETA)}</td>
        <td class="cell-id">${r.ID}</td>
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
  window.refresh = async function(){
    if(REFRESHING) return;
    REFRESHING = true;
    setLoading(true);
    try{
      const rows = await loadTimetable();
      renderGrid(rows);
      applyColumnToggles();
    }catch(e){
      tbody.innerHTML = `<tr><td colspan="8" class="text-danger">Error timetable: ${String(e.message||e)}</td></tr>`;
    }finally{
      REFRESHING = false;
      setLoading(false);
    }
  };
  window.toggleSort = function(){
    SORT_MODE = (SORT_MODE==='ETA') ? 'SEC' : 'ETA';
    const rows = (window._lastRows||[]).slice();
    rows.sort((a,b)=>{
      if(SORT_MODE==='SEC'){
        const A = a._SEC ?? Infinity, B = b._SEC ?? Infinity;
        if(A!==B) return A-B;
      }
      const A = a.ETA ? Date.parse(a.ETA) : 0;
      const B = b.ETA ? Date.parse(b.ETA) : 0;
      return A - B;
    });
    renderGrid(rows);
    applyColumnToggles();
  };

  /* ===== Listeners ===== */
  document.addEventListener('DOMContentLoaded', ()=>{
    // prefill FROM con hora actual UTC si está vacío
    const nowUTC = utcNowInputValue();
    fromEls.forEach(el=>{ if(el && !el.value) el.value = nowUTC; });

    updBtns.forEach(b=> b?.addEventListener('click', window.refresh));
    statusMenus.forEach(m=> m.addEventListener('change', window.refresh));
    colMenus.forEach(m=> m.addEventListener('change', applyColumnToggles));
    tzBtns.forEach(b=> b?.addEventListener('click', ()=>{
      USE_LOCAL_TIME = !USE_LOCAL_TIME;
      tzBtns.forEach(x=> x.textContent = USE_LOCAL_TIME ? 'LCL→UTC' : 'UTC→LCL');
      startClock();
      renderGrid(window._lastRows || []);
      applyColumnToggles();
    }));
    startClock();
    window.refresh();
  });
})();
