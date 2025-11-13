(function(){
  const $ = (s)=>document.querySelector(s);
  const SM = 1609.344, FT = 0.3048;

  // ===== Helpers METAR/TAF
  function setMetar(raw){ const n=$('#metar'); if(n) n.textContent = raw || 'N/D'; }
  function setTaf(raw){
    const n=$('#taf'); if(!n) return;
    if(!raw){ n.textContent = 'N/D'; return; }
    n.textContent = raw.replace(/\s+(?=(FM\d{6}|BECMG|TEMPO|PROB\d{2}\s+\d{4}\/\d{4}))/g, '\n').trim();
  }
  const fmt = {
    int:n=>Number(n).toLocaleString('en-US',{maximumFractionDigits:0}),
    m:n=>n==null?'—':`${fmt.int(Math.round(n))} m`,
    ft:n=>n==null?'—':`${fmt.int(Math.round(n))} ft`,
    sm:n=>n==null?'—':`${(Math.round(n*100)/100).toString()} SM`,
  };

  // ===== Parseos
  function parseVisSm(txt){
    if(!txt) return null;
    if(/\bP6SM\b/i.test(txt)) return 6.01;

    // Mixta: "10 3/16SM", "1 1/2SM"
    let m = txt.match(/\b(\d{1,2})\s+(\d{1,2})\/(\d{1,2})\s*SM\b/i);
    if(m) return parseInt(m[1],10) + (parseInt(m[2],10) / Math.max(1, parseInt(m[3],10)));

    // Solo fracción: "3/16SM", "1/4SM", etc.
    m = txt.match(/\b(\d{1,2})\/(\d{1,2})\s*SM\b/i);
    if(m) return parseInt(m[1],10) / Math.max(1, parseInt(m[2],10));

    // Entero: "2SM", "10SM"
    m = txt.match(/\b(\d{1,2})\s*SM\b/i);
    return m ? parseFloat(m[1]) : null;
  }
  function parseRVR(txt){
    const out={}; if(!txt) return out;
    const re=/\bR(09|27)\/(\d{3,4})(?:V(\d{3,4}))?FT\w?\b/gi;
    let m; while((m=re.exec(txt))){
      const rwy=m[1];
      const ft = m[3] ? parseInt(m[3],10) : parseInt(m[2],10);
      out[rwy] = { ft, m: ft*FT };
    }
    return out;
  }
  function parseCeiling(txt){
    if(!txt) return {kind:'OK', ft:null};
    const vv = txt.match(/\bVV(\d{3})\b/i);
    if(vv){ const ft=parseInt(vv[1],10)*100; return {kind:'VV', ft}; }
    let minFt=null, mm; const re=/\b(OVC|BKN)(\d{3})\b/gi;
    while((mm=re.exec(txt))){ const v=parseInt(mm[2],10)*100; if(minFt===null||v<minFt) minFt=v; }
    if(minFt!==null) return {kind:'LYR', ft:minFt};
    return {kind:'OK', ft:null};
  }

  // ===== Mínimos por defecto (MMTJ demo) si no vienen de fuera
  const MIN_DEFAULT = {
    arr:{ rwy09:{ vv_ft:250, vis_m:800,  rvr_ft:2625 },
          rwy27:{ vv_ft:513, vis_m:1600, rvr_ft:5250 } },
    dep:{ rwy09:{ vis_m:200, rvr_ft:657 },
          rwy27:{ vis_m:200, rvr_ft:657 } }
  };
  function normalizeMin(minWrap){
    const min = minWrap?.minimos || minWrap || MIN_DEFAULT;
    // Soporta llaves en mayúsculas del feed viejo
    const L = (min.arr?.rwy09) || (min.ARR?.RWY09) || MIN_DEFAULT.arr.rwy09;
    const R = (min.arr?.rwy27) || (min.ARR?.RWY27) || MIN_DEFAULT.arr.rwy27;
    const DL = (min.dep?.rwy09) || (min.DEP?.RWY09) || MIN_DEFAULT.dep.rwy09;
    const DR = (min.dep?.rwy27) || (min.DEP?.RWY27) || MIN_DEFAULT.dep.rwy27;
    return { L, R, DL, DR };
  }

  // ===== Clases por estado
  function clsBy(sideLow, isIFR){
    if(sideLow) return 'badge--low';
    return isIFR ? 'badge--ifr' : 'badge--ok';
  }

  // ===== Render principal (export)
  window.renderRunwayFromMetar = function(minWrap, metarTxt){
    const { L, R, DL, DR } = normalizeMin(minWrap);
    const rwy = $('.rwy'); if(!rwy) return;

    const ceil = parseCeiling(metarTxt);
    const visSM = parseVisSm(metarTxt);
    const visM  = visSM!=null ? visSM*SM : null;
    const rvr   = parseRVR(metarTxt);

    // VIS efectiva por cabecera (usa RVR si está presente)
    const visBy09_m = (rvr['09']?.m != null) ? rvr['09'].m : visM;
    const visBy27_m = (rvr['27']?.m != null) ? rvr['27'].m : visM;

    // IFR global
    const isIFR = ((visSM!=null && visSM < 3) || (ceil.ft!=null && ceil.ft < 1500));

    // ===== Evaluación ARR por cabecera
    const belowArr09 =
      ((ceil.ft!=null && L.vv_ft && ceil.ft < L.vv_ft) ||
       (visBy09_m!=null && L.vis_m && visBy09_m < L.vis_m) ||
       (rvr['09']?.ft!=null && L.rvr_ft && rvr['09'].ft < L.rvr_ft));

    const belowArr27 =
      ((ceil.ft!=null && R.vv_ft && ceil.ft < R.vv_ft) ||
       (visBy27_m!=null && R.vis_m && visBy27_m < R.vis_m) ||
       (rvr['27']?.ft!=null && R.rvr_ft && rvr['27'].ft < R.rvr_ft));

    // ===== Evaluación DEP por cabecera
    const tk09_m = (rvr['09']?.m != null) ? rvr['09'].m : visM;
    const tk27_m = (rvr['27']?.m != null) ? rvr['27'].m : visM;

    const belowDep09 =
      ( (tk09_m!=null && DL.vis_m && tk09_m < DL.vis_m) ||
        (rvr['09']?.ft!=null && DL.rvr_ft && rvr['09'].ft < DL.rvr_ft) );

    const belowDep27 =
      ( (tk27_m!=null && DR.vis_m && tk27_m < DR.vis_m) ||
        (rvr['27']?.ft!=null && DR.rvr_ft && rvr['27'].ft < DR.rvr_ft) );

    const belowDepAny = !!(belowDep09 || belowDep27);

    // ===== Top pills por lado (techo)
    function topPill(side){ // side: 'L' o 'R'
      const low = (side==='L') ? (ceil.ft!=null && L.vv_ft && ceil.ft < L.vv_ft)
                               : (ceil.ft!=null && R.vv_ft && ceil.ft < R.vv_ft);
      const cls = clsBy(low, isIFR);
      if(ceil.kind==='VV')  return `<span class="badge ${cls}">VV</span><span class="badge ${cls}">${fmt.ft(ceil.ft)}</span>`;
      if(ceil.kind==='LYR') return `<span class="badge ${cls}">LYR</span><span class="badge ${cls}">${fmt.ft(ceil.ft)}</span>`;
      return `<span class="badge badge--ok">LYR</span><span class="badge badge--ok">OK</span>`;
    }
    const topL=$('.rwy-badges.top.left'), topR=$('.rwy-badges.top.right');
    if(topL) topL.innerHTML = topPill('L');
    if(topR) topR.innerHTML = topPill('R');

    // ===== Mid pills por lado (VIS/RVR con mínimos por cabecera)
    function midPill(valM, rvrFt, side){
      const arrMin = (side==='L') ? L : R;
      // RVR presente
      if(rvrFt!=null){
        const low = (arrMin.rvr_ft && rvrFt < arrMin.rvr_ft);
        const cls = clsBy(low, isIFR);
        return `<span class="badge ${cls}">RVR</span><span class="badge ${cls}">${fmt.m(rvrFt*FT)} / ${fmt.ft(rvrFt)}</span>`;
      }
      // Solo VIS
      if(valM==null) return `<span class="badge badge--ok">VIS</span><span class="badge badge--ok">—</span>`;
      const low = (arrMin.vis_m && valM < arrMin.vis_m);
      const cls = clsBy(low, isIFR);
      return `<span class="badge ${cls}">VIS</span><span class="badge ${cls}">${fmt.sm(visSM)} / ${fmt.ft(valM/FT)} / ${fmt.m(valM)}</span>`;
    }
    const midL=$('.rwy-badges.mid.left'), midR=$('.rwy-badges.mid.right');
    if(midL) midL.innerHTML = midPill(visBy09_m, rvr['09']?.ft ?? null, 'L');
    if(midR) midR.innerHTML = midPill(visBy27_m, rvr['27']?.ft ?? null, 'R');

    // ===== Designadores en rojo si por debajo de mínimos ARR por lado
    const idL=$('.rwy-id.left'), idR=$('.rwy-id.right');
    if(idL) idL.classList.toggle('danger', !!belowArr09);
    if(idR) idR.classList.toggle('danger', !!belowArr27);

    // ===== Banner (texto correcto por lado)
    const tkM = Math.min(tk09_m ?? Infinity, tk27_m ?? Infinity);
    let text = '';
    if(belowArr09 && belowArr27){
      text = '🔴 BLW ALL IFR MINIMUMS';
    } else if(belowArr09){
      text = '⚠️ BLW R09 ARR MINIMUMS';
    } else if(belowArr27){
      text = '⚠️ BLW R27 ARR MINIMUMS';
    } else if(belowDepAny){
      text = '⚠️ BLW DEP MINIMUMS';
    }
    let banner = rwy.querySelector('.rwy-banner');
    if(!banner){ banner=document.createElement('div'); banner.className='rwy-banner'; rwy.appendChild(banner); }
    banner.style.display = text ? 'block' : 'none';
    banner.textContent = text;

    // ===== Takeoff VIS (muestra peor caso; color de severidad visual)
    let tk = rwy.querySelector('.rwy-tk');
    if(!tk){ tk = document.createElement('div'); tk.className='rwy-tk'; rwy.appendChild(tk); }
    if(isFinite(tkM)){
      // Heurística visual; los mínimos duros se evalúan arriba en banner
      const cls = tkM < 200 ? 'crit' : (tkM <= 1600 ? 'warn' : 'ok');
      tk.className = `rwy-tk ${cls}`;
      tk.textContent = `DEP VIS: ${fmt.m(tkM)}`;
      tk.style.display='block';
    } else {
      tk.style.display='none';
    }

    // ===== LVP ACTIVE si min VIS ≤ 400 m (por cualquiera de las cabeceras)
    let lvp = rwy.querySelector('.rwy-lvp');
    if(!lvp){ lvp = document.createElement('div'); lvp.className='rwy-lvp'; rwy.appendChild(lvp); }
    const minVis = Math.min(visBy09_m ?? Infinity, visBy27_m ?? Infinity);
    lvp.style.display = (isFinite(minVis) && minVis <= 400) ? 'block' : 'none';
    lvp.textContent = 'LVP ACTIVE';
  };

  // Wrapper de prueba local
  window.renderRunway = function(metarTxt){
    window.renderRunwayFromMetar(MIN_DEFAULT, metarTxt);
  };

  // ===== Escenarios demo
  const SCN = {
    VFR:{ tag:'VFR', metar:'METAR MMTJ 072347Z 27010KT 10SM SKC 20/13 A2992 RMK HZY',
      taf:'TAF MMTJ 072317Z 0800/0900 27008KT P6SM SKC TX22/0821Z TN14/0812Z FM080600 24005KT P6SM SCT020 BECMG 0809/0810 4SM BR SCT015 TEMPO 0811/0814 2SM BR BKN005 FM081700 30005KT 5SM HZ SKC FM082100 27008KT P6SM SKC='},
    LYR:{ tag:'LYR', metar:'METAR MMTJ 080130Z 27006KT 5SM BR BKN008 18/16 A2990', taf:'TAF MMTJ 072317Z 0800/0900 26008KT P6SM SCT020 BECMG 0808/0810 5SM BR BKN008'},
    RVR:{ tag:'RVR', metar:'METAR MMTJ 080900Z 00000KT 1/2SM FG VV003 16/16 A2988 R09/0600FT R27/0800FT', taf:'TAF MMTJ 072317Z 0800/0900 00000KT 1/2SM FG VV003 TEMPO 0809/0812 1/4SM FG VV002'},
    LVP:{ tag:'LVP', metar:'METAR MMTJ 081030Z 00000KT 1/8SM FG VV002 15/15 A2986 R09/0300FTD R27/0300FTN', taf:'TAF MMTJ 072317Z 0800/0900 00000KT 1/4SM FG VV002 TEMPO 0810/0813 1/8SM FG VV001' },
    ONLY27_LOW:{ tag:'27 LOW', metar:'METAR MMTJ 081100Z 00000KT 3/4SM BR VV010 16/15 A2988', taf:'TAF MMTJ 072317Z 0800/0900 00000KT 3/4SM BR VV010' },
    ONLY09_LOW:{ tag:'09 LOW', metar:'METAR MMTJ 081200Z 00000KT 3SM BR VV020 16/15 A2988 R09/0600FT R27/6000FT', taf:'TAF MMTJ 072317Z 0800/0900 00000KT 3SM BR VV020' },
    BOTH_LDG_TK_LOW:{ tag:'ALL LOW', metar:'METAR MMTJ 081300Z 00000KT 1/16SM FG VV002 15/15 A2986 R09/0300FT R27/0300FT', taf:'TAF MMTJ 072317Z 0800/0900 00000KT 1/8SM FG VV002' }
  };

  function apply(tag){
    const s = SCN[tag] || SCN.VFR;
    setMetar(s.metar); setTaf(s.taf);
    window.renderRunwayFromMetar(MIN_DEFAULT, s.metar);
    const esc=$('#esc'); if(esc) esc.textContent = s.tag;
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    document.querySelectorAll('[data-scn]').forEach(b=>b.addEventListener('click', ()=>apply(b.getAttribute('data-scn'))));
    apply('VFR');
  });

  // Integración con SIGMA (evento interno)
  document.addEventListener('sigma:wx', (e) => {
    const { metar, taf, minimos } = e.detail || {};
    setMetar(metar); setTaf(taf);
    window.renderRunwayFromMetar(minimos || MIN_DEFAULT, metar || '');
  });
})();