// Timetable UI v2
const DEFAULT_STATUS_LIST=['scheduled','active','landed','cancelled','diverted'];

const STATE={
  type:'arrival',
  iata:'TIJ',
  start:null,
  hours:24,
  ttl:5,
  tz:'UTC', // 'UTC' or 'LCL'
  statuses:new Set(DEFAULT_STATUS_LIST),
  data:[]
};

let isLoading=false;
const MODAL={root:null,title:null,info:null,metar:null,taf:null,metarSource:null,tafSource:null,close:null};

function utcNowISO(){const n=new Date();return `${n.getUTCFullYear()}-${String(n.getUTCMonth()+1).padStart(2,'0')}-${String(n.getUTCDate()).padStart(2,'0')}T${String(n.getUTCHours()).padStart(2,'0')}:${String(n.getUTCMinutes()).padStart(2,'0')}Z`;}

function setLoading(flag, activeBtn){
  isLoading=flag;
  const targets=[document.getElementById('run'),document.getElementById('applyStatus')];
  targets.forEach(btn=>{
    if(!btn) return;
    btn.disabled=flag;
    if(flag) btn.setAttribute('aria-busy','true'); else btn.removeAttribute('aria-busy');
    if(btn!==activeBtn){
      btn.classList.toggle('is-loading',flag);
    }else if(!flag){
      btn.classList.remove('is-loading');
    }
  });
}

function setBtnLoading(btnEl) { btnEl.dataset.originalHtml = btnEl.innerHTML; btnEl.disabled = true; btnEl.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Actualizando…'; }
function clearBtnLoading(btnEl) { if (btnEl.dataset.originalHtml) btnEl.innerHTML = btnEl.dataset.originalHtml; btnEl.disabled = false; delete btnEl.dataset.originalHtml; }

function loadSavedStatuses(){
  try{
    const raw=JSON.parse(localStorage.getItem('mmtj_tt_statuses')||'[]');
    if(Array.isArray(raw) && raw.length){
      STATE.statuses=new Set(raw.map(v=>String(v||'').toLowerCase()).filter(Boolean));
    }
  }catch(_){/* ignore */}
}

function saveStatuses(){
  localStorage.setItem('mmtj_tt_statuses', JSON.stringify(Array.from(STATE.statuses)));
}

function syncStatusCheckboxes(){
  const active=STATE.statuses;
  document.querySelectorAll('#statusMenu input[type=checkbox]').forEach(cb=>{
    cb.checked=active.has(cb.value);
  });
}

function reapplyColumnPreferences(){
  const saved=JSON.parse(localStorage.getItem('mmtj_tt_cols')||'{}');
  document.querySelectorAll('#colsMenu input[type=checkbox]').forEach(cb=>{
    const def=['flight','route','est','status','class'].includes(cb.dataset.col);
    const vis=(saved[cb.dataset.col]!==undefined)?!!saved[cb.dataset.col]:def;
    setColVisible(cb.dataset.col,vis);
    cb.checked=vis;
  });
}

function initModal(){
  MODAL.root=document.getElementById('wxModal');
  if(!MODAL.root) return;
  MODAL.title=document.getElementById('wxTitle');
  MODAL.info=document.getElementById('wxInfo');
  MODAL.metar=document.getElementById('wxMetar');
  MODAL.taf=document.getElementById('wxTaf');
  MODAL.metarSource=document.getElementById('wxMetarSource');
  MODAL.tafSource=document.getElementById('wxTafSource');
  MODAL.close=document.getElementById('wxClose');
  MODAL.close?.addEventListener('click',hideWxModal);
  MODAL.root.addEventListener('click',e=>{if(e.target===MODAL.root) hideWxModal();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&MODAL.root&&!MODAL.root.classList.contains('hidden')) hideWxModal();});
  MODAL.root.setAttribute('aria-hidden','true');
}

function hideWxModal(){
  if(!MODAL.root) return;
  MODAL.root.classList.add('hidden');
  MODAL.root.setAttribute('aria-hidden','true');
  document.body.classList.remove('wx-modal-open');
}

function describeSnapshot(tsIso){
  if(!tsIso) return '';
  const d=new Date(tsIso);
  if(!isFinite(d)) return '';
  const utc=d.toISOString().replace('T',' ').replace('Z','Z');
  const local=d.toLocaleString(undefined,{hour12:false,timeZoneName:'short'});
  return `Capturado ${utc} / ${local}`;
}

function showWxModal(row){
  if(!MODAL.root) return;
  const dest=row.dest_iata_actual||row.arr_iata||'';
  const titlePieces=[];
  if(row.flight_iata) titlePieces.push(row.flight_iata);
  if(dest) titlePieces.push(dest);
  if(MODAL.title) MODAL.title.textContent=titlePieces.join(' → ')||'Detalle meteorológico';
  const info=[];
  if(row.status) info.push(`Estado: ${(row.status||'').toUpperCase()}`);
  if(row.wx_metar_src) info.push(`METAR: ${row.wx_metar_src}`);
  if(row.wx_taf_src) info.push(`TAF: ${row.wx_taf_src}`);
  if(row.wx_snapshot_ts) info.push(describeSnapshot(row.wx_snapshot_ts));
  if(MODAL.info) MODAL.info.textContent=info.join(' · ');
  if(MODAL.metar) MODAL.metar.textContent=row.wx_metar_raw||'Sin METAR disponible';
  if(MODAL.metarSource) MODAL.metarSource.textContent=row.wx_metar_src?`Fuente: ${row.wx_metar_src}`:'';
  if(MODAL.taf) MODAL.taf.textContent=row.wx_taf_raw||'Sin TAF disponible';
  if(MODAL.tafSource) MODAL.tafSource.textContent=row.wx_taf_src?`Fuente: ${row.wx_taf_src}`:'';
  MODAL.root.classList.remove('hidden');
  MODAL.root.setAttribute('aria-hidden','false');
  document.body.classList.add('wx-modal-open');
  MODAL.close?.focus();
}

function initForm(){
  loadSavedStatuses();
  initModal();
  const iata=document.getElementById('iata');
  const hours=document.getElementById('hours');
  const ttl=document.getElementById('ttl');
  const start=document.getElementById('start');
  const seg=document.getElementById('typeSeg');

  // default start = hora UTC cerrada
  const now=new Date(); now.setUTCMinutes(0,0,0);
  start.value=new Date(now.getTime()-now.getTimezoneOffset()*60000).toISOString().slice(0,16);
  STATE.start=utcNowISO();

  iata.addEventListener('input',e=>STATE.iata=e.target.value.toUpperCase());
  hours.addEventListener('input',e=>STATE.hours=Math.max(1,Math.min(168,parseInt(e.target.value||'24',10))));
  ttl.addEventListener('input',e=>STATE.ttl=Math.max(1,Math.min(60,parseInt(e.target.value||'5',10))));
  start.addEventListener('change',e=>{const v=e.target.value;STATE.start=(v?v.replace(' ','T'):'')+'Z';});

  seg.addEventListener('click',e=>{
    if(!e.target.classList.contains('seg-btn'))return;
    seg.querySelectorAll('.seg-btn').forEach(b=>b.classList.remove('is-active'));
    e.target.classList.add('is-active');
    STATE.type=e.target.dataset.type;
  });

  // Dropdowns
  setupDropdown('colsDrop','colsBtn','colsMenu');
  setupDropdown('statusDrop','statusBtn','statusMenu');

  // Column filter: restaurar preferencias
  document.querySelectorAll('#colsMenu input[type=checkbox]').forEach(cb=>{
    cb.addEventListener('change',()=>{
      setColVisible(cb.dataset.col, cb.checked);
      const map={}; document.querySelectorAll('#colsMenu input[type=checkbox]').forEach(x=>map[x.dataset.col]=x.checked);
      localStorage.setItem('mmtj_tt_cols', JSON.stringify(map));
    });
  });
  reapplyColumnPreferences();

  // Status filter
  document.getElementById('statusMenu').addEventListener('change',e=>{
    if(e.target && e.target.type==='checkbox'){
      // update the set of active statuses
      if(e.target.checked) STATE.statuses.add(e.target.value);
      else STATE.statuses.delete(e.target.value);
      saveStatuses();
      // re-render the table with the existing data so users see
      // immediate feedback without hitting the API again.  The full
      // refresh will still occur when they click the Apply button.
      render();
    }
  });
  document.getElementById('statusReset').addEventListener('click',()=>{
    STATE.statuses=new Set(DEFAULT_STATUS_LIST);
    syncStatusCheckboxes();
    saveStatuses();
    render();
  });
  document.getElementById('applyStatus').addEventListener('click',e=>{
    const btn=e.currentTarget;
    if(!btn || btn.disabled) return;
    execute(btn);
  });

  syncStatusCheckboxes();

  // Botones
  document.getElementById('run').addEventListener('click',e=>{
    const btn=e.currentTarget;
    if(!btn || btn.disabled) return;
    execute(btn);
  });
  document.getElementById('csvBtn').addEventListener('click',()=>downloadCSV());
  document.getElementById('tzToggle').addEventListener('click',()=>toggleTZ());
  document.getElementById('quickPrev2h').addEventListener('click',()=>quickShift(-2));
  document.getElementById('quickToday').addEventListener('click',()=>quickToday());
  document.getElementById('quickTomorrow').addEventListener('click',()=>quickTomorrow());
  document.getElementById('quickNext2h').addEventListener('click',()=>quickShift(2));

  // Reloj
  startClock();

  render();

  // Perform an initial fetch so the grid is populated on page load without
  // requiring the user to press the refresh button manually.
  execute();
}

function setupDropdown(rootId,btnId,menuId){
  const root=document.getElementById(rootId),btn=document.getElementById(btnId);
  const close=()=>root.classList.remove('open');
  btn.addEventListener('click',e=>{e.stopPropagation();root.classList.toggle('open');});
  document.addEventListener('click',e=>{if(!root.contains(e.target))close();});
  document.addEventListener('scroll',close,true);
}

function setColVisible(col, visible){
  document.querySelectorAll(`th.col-${col}, td.col-${col}`).forEach(el=>{
    if(visible) el.classList.remove('col-hide'); else el.classList.add('col-hide');
  });
}

function buildQuery(){
  // Build the appropriate query depending on the selected type.  For
  // departures we call the dedicated departures API which returns
  // additional weather and en‑route time fields.  For arrivals and
  // mixed we continue to use the fr24 proxy endpoint.
  const p=new URLSearchParams();
  if (STATE.type==='departure') {
    // Use the departures API.  Pass dep_iata (can be a list), start and hours.
    if (STATE.iata) p.set('dep_iata', STATE.iata);
    p.set('start', STATE.start);
    p.set('hours', String(STATE.hours||24));
    return '../api/departures.php?' + p.toString();
  }
  // Arrivals or both use the FR24 proxy
  const sts=Array.from(STATE.statuses).join(',');
  if(STATE.type==='arrival'||STATE.type==='both') p.set('arr_iata',STATE.iata);
  if(STATE.type==='departure'||STATE.type==='both') p.set('dep_iata',STATE.iata);
  p.set('type',STATE.type);
  p.set('start',STATE.start);
  p.set('hours',String(STATE.hours||24));
  p.set('ttl',String(STATE.ttl||5));
  p.set('status',sts);
  return '../api/fr24.php?'+p.toString();
}

async function execute(triggerBtn){
  const btnEl=(triggerBtn instanceof HTMLElement)?triggerBtn:null;
  if(btnEl && btnEl.disabled) return;
  if(isLoading) return;
  const tb=document.querySelector('#grid tbody');
  tb.innerHTML='<tr><td colspan="10">Consultando…</td></tr>';
  if(btnEl) setBtnLoading(btnEl);
  setLoading(true,btnEl);
  try{
    const res=await fetch(buildQuery(),{cache:'no-store'});
    const j=await res.json();
    if(!j.ok){tb.innerHTML=`<tr><td colspan="10">Error: ${j.error||'desconocido'}</td></tr>`;return;}
    const items=Array.isArray(j.rows)?j.rows:[];
    STATE.data=dedup(items);
    render();
  }catch(err){
    tb.innerHTML=`<tr><td colspan="10">Fallo: ${String(err)}</td></tr>`;
  }finally{
    setLoading(false,btnEl);
    if(btnEl) clearBtnLoading(btnEl);
  }
}

function dedup(items){
  const m=new Map();
  for(const r of items){
    const k=[r.flight_iata||'',r.sta_utc||'',r.dep_iata||'',r.arr_iata||''].join('|');
    if(!m.has(k)) m.set(k,r);
  }
  return Array.from(m.values());
}

function render(){
  syncStatusCheckboxes();
  const rows=STATE.data.filter(r=>STATE.statuses.has((r.status||'').toLowerCase()))
    .sort((a,b)=>Date.parse(a.eta_utc||a.sta_utc||'2100-01-01')-Date.parse(b.eta_utc||b.sta_utc||'2100-01-01'));

  renderCounters(rows);

  const tb=document.querySelector('#grid tbody'); tb.innerHTML='';
  for(const r of rows){
    const key=rowKey(r);
    const clsVal=loadClass(key);
    const delay=calcDelay(r);
    const etaTxt=fmtTime(r.eta_utc||r.sta_utc,STATE.tz);
    const etaClass=delay==null?'t-neutral':(delay<0?'t-early':delay>0?'t-late':'t-neutral');
    const statusBadge=badgeClass((r.status||'').toLowerCase());
    // Determine display text for status: for departures mode, use 'ADES OK' for landed, or actual dest IATA when diverted
    let statusText = r.status || '';
    if (STATE.type==='departure') {
      const st = (r.status||'').toLowerCase();
      if (st === 'landed') {
        statusText = 'ADES OK';
      } else if (st === 'diverted') {
        statusText = (r.dest_iata_actual || r.arr_iata || '').toUpperCase();
      }
    }

    const tr=document.createElement('tr');
    // Determine EET or delay depending on mode
    const eet = STATE.type==='departure' ? calcEET(r) : null;
    const delayTxt = STATE.type==='departure' ? '' : (delay==null?'':String(delay));
    const eetTxt = (STATE.type==='departure' && eet!=null) ? String(eet) : '';
    const wxHtml = (STATE.type==='departure') ? buildWx(r) : '';
    tr.innerHTML=`
      <td class="col-flight">${esc(r.flight_iata||'')}</td>
      <td class="col-route">${esc(r.dep_iata||'')}→${esc(r.arr_iata||'')}</td>
      <td class="col-est"><span class="${etaClass}">${etaTxt}</span></td>
      <td class="col-status"><span class="badge ${statusBadge}">${esc(statusText)}</span></td>
      <td class="col-class">${classEditor(key,clsVal)}</td>
      <td class="col-airline">${esc(r.airline_name||'')}</td>
      <td class="col-sched">${fmtTime(r.sta_utc,STATE.tz)}</td>
      <td class="col-act">${fmtTime(r.ata_utc,STATE.tz)}</td>
      <td class="col-delay">${delayTxt}</td>
      <td class="col-gate">${esc(r.terminal||'')}/${esc(r.gate||'')}</td>
      <td class="col-eet">${eetTxt}</td>
      <td class="col-wx">${wxHtml}</td>`;
    tb.appendChild(tr);
    const wxBtn=tr.querySelector('.wx-chip');
    if(wxBtn){
      wxBtn.addEventListener('click',e=>{
        e.stopPropagation();
        showWxModal(r);
      });
    }
  }

  // eventos de Clase
  tb.querySelectorAll('select[data-rowkey], input[data-rowkey]').forEach(el=>{
    el.addEventListener('change',e=>{
      const k=e.target.getAttribute('data-rowkey');
      const sel=tb.querySelector(`select[data-rowkey="${k}"]`);
      const txt=tb.querySelector(`input[data-rowkey="${k}"]`);
      if(sel&&txt) txt.disabled=(sel.value!=='OPDATA');
      const val=(sel&&sel.value==='OPDATA')?(txt.value||''):(sel?sel.value:'');
      saveClass(k,val);
    });
  });
  reapplyColumnPreferences();
}

function rowKey(r){return [r.flight_iata||'',r.sta_utc||'',r.dep_iata||'',r.arr_iata||''].join('|');}
function classEditor(key,current){
  const opts=['OPDATA','Aterrizado','Alterno','Emergencia','Cancelado'];
  const isFixed=opts.includes(current);
  const selVal=isFixed?current:'OPDATA';
  const textVal=isFixed?'':(current||'');
  return `<div class="class-cell">
    <select data-rowkey="${escAttr(key)}" class="inp small">
      ${opts.map(o=>`<option value="${o}" ${o===selVal?'selected':''}>${o}</option>`).join('')}
    </select>
    <input data-rowkey="${escAttr(key)}" class="inp small" placeholder="Texto" value="${escAttr(textVal)}" ${selVal==='OPDATA'?'':'disabled'}>
  </div>`;
}
function saveClass(key,value){const all=JSON.parse(localStorage.getItem('mmtj_tt_class')||'{}');all[key]=value;localStorage.setItem('mmtj_tt_class',JSON.stringify(all));}
function loadClass(key){const all=JSON.parse(localStorage.getItem('mmtj_tt_class')||'{}');return all[key]||'';}

function calcDelay(r){
  if(typeof r.delay_min==='number') return r.delay_min;
  const sta=Date.parse(r.sta_utc||''), eta=Date.parse(r.eta_utc||'');
  if(!isFinite(sta)||!isFinite(eta)) return null;
  return Math.round((eta-sta)/60000);
}

// Compute estimated en‑route time for departures.  Returns minutes or null.
function calcEET(r){
  if(typeof r.eet_min==='number') return r.eet_min;
  return null;
}

// Build HTML for weather icons.  Uses wx_metar_cat/wx_taf_cat and
// corresponding raw fields to create coloured circles with tooltips.
function buildWx(r){
  const mcat = (r.wx_metar_cat || '').toLowerCase();
  const tcat = (r.wx_taf_cat || '').toLowerCase();
  if(!mcat && !tcat) return '';
  const bits=[];
  if(mcat) bits.push(`<span class="wx-circle wx-${mcat}" aria-label="METAR ${escAttr(r.wx_metar_cat||'')}"></span>`);
  if(tcat) bits.push(`<span class="wx-circle wx-${tcat}" aria-label="TAF ${escAttr(r.wx_taf_cat||'')}"></span>`);
  const snap = r.wx_metar_frozen || r.wx_taf_frozen;
  const titleParts=[];
  if(r.wx_metar_src) titleParts.push(`METAR ${r.wx_metar_src}`);
  if(r.wx_taf_src) titleParts.push(`TAF ${r.wx_taf_src}`);
  if(r.wx_snapshot_ts) titleParts.push(describeSnapshot(r.wx_snapshot_ts));
  const title=titleParts.join(' · ');
  return `<button type="button" class="wx-chip${snap?' wx-chip-frozen':''}" title="${escAttr(title)}">${bits.join('')}</button>`;
}
function badgeClass(s){switch(s){case 'scheduled':return 'status-scheduled';case 'active':return 'status-active';case 'landed':return 'status-landed';case 'cancelled':return 'status-cancelled';case 'diverted':return 'status-diverted';default:return '';}}

function fmtTime(s,tz){
  if(!s) return '';
  const d=new Date(s); if(isNaN(d)) return '';
  if(tz==='UTC') return d.toISOString().slice(11,16)+'Z';
  const l=new Date(d.getTime()); return String(l.getHours()).padStart(2,'0')+':'+String(l.getMinutes()).padStart(2,'0')+'L';
}
function esc(s){return String(s||'').replace(/[&<>"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));}
function escAttr(s){return String(s||'')
  .replace(/&/g,'&amp;')
  .replace(/"/g,'&quot;')
  .replace(/</g,'&lt;')
  .replace(/>/g,'&gt;')
  .replace(/\n/g,'&#10;');}

function renderCounters(rows){
  const total=rows.length, by=st=>rows.filter(r=>(r.status||'').toLowerCase()===st).length;
  const dly15=rows.filter(r=>(calcDelay(r)||0)>=15).length;
  const el=document.getElementById('cards');
  if(!el) return;
  const isDefault = STATE.statuses.size===DEFAULT_STATUS_LIST.length && DEFAULT_STATUS_LIST.every(st=>STATE.statuses.has(st));
  const defs=[
    {label:'TOT', count:total, filter:'', active:isDefault, clickable:true},
    {label:'SCHD',count:by('scheduled'),filter:'scheduled', active:STATE.statuses.size===1 && STATE.statuses.has('scheduled'), clickable:true},
    {label:'AIR', count:by('active'),filter:'active', active:STATE.statuses.size===1 && STATE.statuses.has('active'), clickable:true},
    {label:'LND', count:by('landed'),filter:'landed', active:STATE.statuses.size===1 && STATE.statuses.has('landed'), clickable:true},
    {label:'CNL', count:by('cancelled'),filter:'cancelled', active:STATE.statuses.size===1 && STATE.statuses.has('cancelled'), clickable:true},
    {label:'ALT', count:by('diverted'),filter:'diverted', active:STATE.statuses.size===1 && STATE.statuses.has('diverted'), clickable:true},
    {label:'D≥15',count:dly15,filter:null,active:false,clickable:false},
    {label:'RISK',count:0,filter:null,active:false,clickable:false}
  ];
  el.innerHTML=defs.map(def=>{
    const attrs = def.clickable ? `data-filter="${escAttr(def.filter||'')}"` : 'data-filter=""';
    return `<div class="card${def.active?' is-active':''}${def.clickable?' card-clickable':''}" ${attrs}><b>${def.count}</b><br><span>${def.label}</span></div>`;
  }).join('');
  el.querySelectorAll('.card.card-clickable').forEach(card=>{
    card.addEventListener('click',()=>{
      const filter = card.dataset.filter || '';
      if(!filter){
        STATE.statuses=new Set(DEFAULT_STATUS_LIST);
      }else{
        STATE.statuses=new Set([filter]);
      }
      syncStatusCheckboxes();
      saveStatuses();
      render();
    });
  });
}

/* Reloj y TZ */
function startClock(){
  if(window._clk) clearInterval(window._clk);
  const tick=()=>{ const now=new Date(); const lab=document.getElementById('tzLabel'); const clk=document.getElementById('clock');
    if(STATE.tz==='UTC'){lab.textContent='UTC'; clk.textContent=`${String(now.getUTCHours()).padStart(2,'0')}:${String(now.getUTCMinutes()).padStart(2,'0')}:${String(now.getUTCSeconds()).padStart(2,'0')}`; document.getElementById('tzToggle').textContent='Cambiar a LCL';}
    else{lab.textContent='LCL'; clk.textContent=`${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}:${String(now.getSeconds()).padStart(2,'0')}`; document.getElementById('tzToggle').textContent='Cambiar a UTC';}
  };
  tick(); window._clk=setInterval(tick,1000);
}
function toggleTZ(){STATE.tz=(STATE.tz==='UTC')?'LCL':'UTC';startClock();render();}

/* Quick helpers */
function quickShift(h){const ts=Date.parse(STATE.start||utcNowISO()); const d=new Date(ts+h*3600*1000); const iso=d.toISOString().slice(0,16); document.getElementById('start').value=iso; STATE.start=iso+'Z'; execute();}
function quickToday(){const d=new Date(); d.setUTCHours(0,0,0,0); const iso=d.toISOString().slice(0,16); document.getElementById('start').value=iso; STATE.start=iso+'Z'; STATE.hours=24; document.getElementById('hours').value='24'; execute();}
function quickTomorrow(){const d=new Date(); d.setUTCHours(24,0,0,0); const iso=d.toISOString().slice(0,16); document.getElementById('start').value=iso; STATE.start=iso+'Z'; STATE.hours=24; document.getElementById('hours').value='24'; execute();}

/* CSV de lo visible */
function downloadCSV(){
  const rows=[...document.querySelectorAll('#grid tbody tr')].map(tr=>{
    const pick=sel=>{const el=tr.querySelector(sel); return el?el.textContent.trim():'';};
    const sel=tr.querySelector('.col-class select'); const txt=tr.querySelector('.col-class input');
    return {'Vuelo':pick('.col-flight'),'Ruta':pick('.col-route'),'ETA/ETD':pick('.col-est'),
            'Status':pick('.col-status'),'Clase': sel && sel.value==='OPDATA' ? (txt.value||'') : (sel?sel.value:'')};
  });
  const headers=['Vuelo','Ruta','ETA/ETD','Status','Clase'];
  const csv=[headers.join(','), ...rows.map(r=>headers.map(h=>`"${String(r[h]).replace(/"/g,'""')}"`).join(','))].join('\\n');
  const blob=new Blob([csv],{type:'text/csv;charset=utf-8'}); const a=document.createElement('a');
  a.href=URL.createObjectURL(blob); a.download='timetable.csv'; a.click(); URL.revokeObjectURL(a.href);
}

window.addEventListener('DOMContentLoaded',initForm);