(function(){
  const $ = (s)=>document.querySelector(s);
  const FT = 0.3048, SM = 1609.344;

  const fmt = {
    int:(n)=>Number(n).toLocaleString('en-US',{maximumFractionDigits:0}),
    m  :(n)=> n==null?'—':`${fmt.int(Math.round(n))} m`,
    ft :(n)=> n==null?'—':`${fmt.int(Math.round(n))} ft`,
    sm :(n)=> n==null?'—':`${(Math.round(n*100)/100).toString()} SM`,
  };

  function parseVisSm(txt){
    if(!txt) return null;
    if(/\bP6SM\b/i.test(txt)) return 6.01;
    const mf = txt.match(/\b(\d{1,2})\s+(\d)\/(\d)\s*SM\b/i);
    if(mf) return parseInt(mf[1],10) + (parseInt(mf[2],10)/Math.max(1,parseInt(mf[3],10)));
    const f = txt.match(/\b(\d)\/(\d)\s*SM\b/i);
    if(f) return parseInt(f[1],10) / Math.max(1, parseInt(f[2],10));
    const m = txt.match(/\b(\d{1,2})\s*SM\b/i);
    return m ? parseFloat(m[1]) : null;
  }
  function parseRVR(txt){
    const out={}; if(!txt) return out;
    const re=/\bR(09|27)\/(\d{3,4})(?:V(\d{3,4}))?FT\w?\b/gi;
    let m; while((m=re.exec(txt))){
      const rwy=m[1]; const ft = m[3]?parseInt(m[3],10):parseInt(m[2],10);
      out[rwy] = { ft, m: ft*FT };
    }
    return out;
  }
  function parseCeiling(txt){
    if(!txt) return {kind:'OK', ft:null, tag:'OK'};
    const vv = txt.match(/\bVV(\d{3})\b/i);
    if(vv){ const ft=parseInt(vv[1],10)*100; return {kind:'VV', ft, tag:`VV ${fmt.ft(ft)}`}; }
    let minFt=null, m; const re=/\b(OVC|BKN)(\d{3})\b/gi;
    while((m=re.exec(txt))){ const v=parseInt(m[2],10)*100; if(minFt===null||v<minFt) minFt=v; }
    if(minFt!==null) return {kind:'LYR', ft:minFt, tag:`LYR ${fmt.ft(minFt)}`};
    return {kind:'OK', ft:null, tag:'OK'};
  }

  // ===== render principal =====
  window.renderRunwayFromMetar = function(minWrap, metarTxt){
    const min = minWrap?.minimos || minWrap || {};
    const L = (min.arr?.rwy09) || (min.ARR?.RWY09) || {};
    const R = (min.arr?.rwy27) || (min.ARR?.RWY27) || {};

    const rwy = $('.rwy'); if(!rwy) return;

    const ceil = parseCeiling(metarTxt);
    const visSM = parseVisSm(metarTxt);
    const visM  = visSM ? visSM*SM : null;
    const rvr   = parseRVR(metarTxt);

    const visBy09_m = rvr['09']?.m ?? visM;
    const visBy27_m = rvr['27']?.m ?? visM;

    // IFR base
    const isIFR = ( (visSM!=null && visSM < 3) || (ceil.ft!=null && ceil.ft < 1500) );

    // Estado vs mínimos por cabecera
    const below09 = ((ceil.ft!=null && L.vv_ft && ceil.ft < L.vv_ft) || (visBy09_m!=null && L.vis_m && visBy09_m < L.vis_m));
    const below27 = ((ceil.ft!=null && R.vv_ft && ceil.ft < R.vv_ft) || (visBy27_m!=null && R.vis_m && visBy27_m < R.vis_m));

    // ===== top pills (techo) izquierda/derecha
    function pillTop(){
      let cls = isIFR ? 'ifr' : 'ok';
      if(ceil.kind!=='OK' && ((ceil.ft!=null && (below09||below27)))) cls='danger';
      if(ceil.kind==='VV')   return `<span class="badge ${cls}">VV</span><span class="badge ${cls}">${fmt.ft(ceil.ft)}</span>`;
      if(ceil.kind==='LYR')  return `<span class="badge ${cls}">LYR</span><span class="badge ${cls}">${fmt.ft(ceil.ft)}</span>`;
      return `<span class="badge ${cls}">LYR</span><span class="badge ${cls}">OK</span>`;
    }
    const topHTML = pillTop();
    const topL=$('.rwy-badges.top.left'), topR=$('.rwy-badges.top.right');
    if(topL) topL.innerHTML = topHTML;
    if(topR) topR.innerHTML = topHTML;

    // ===== mid pills por cabecera (VIS o RVR)
    function mid(valM, rvrFT){
      const baseCls = isIFR ? 'ifr' : 'ok';
      if(rvrFT!=null){
        const cls = (valM!=null && ((valM < (L.vis_m||R.vis_m||Infinity)))) ? 'danger' : baseCls;
        return `<span class="badge ${cls}">RVR</span><span class="badge ${cls}">${fmt.m(rvrFT*FT)} / ${fmt.ft(rvrFT)}</span>`;
      }
      if(valM==null) return `<span class="badge ${baseCls}">VIS</span><span class="badge ${baseCls}">—</span>`;
      // muestra SM + ft + m
      const cls = ( (valM < (L.vis_m||Infinity)) || (valM < (R.vis_m||Infinity)) ) ? 'danger' : baseCls;
      return `<span class="badge ${cls}">VIS</span><span class="badge ${cls}">${fmt.sm(visSM)} / ${fmt.ft(valM/0.3048)} / ${fmt.m(valM)}</span>`;
    }
    const midL=$('.rwy-badges.mid.left'), midR=$('.rwy-badges.mid.right');
    if(midL) midL.innerHTML = mid(visBy09_m, rvr['09']?.ft ?? null);
    if(midR) midR.innerHTML = mid(visBy27_m, rvr['27']?.ft ?? null);

    // ===== color designadores según mínimos de aterrizaje
    const idL=$('.rwy-id.left'), idR=$('.rwy-id.right');
    if(idL) idL.classList.toggle('danger', !!below09);
    if(idR) idR.classList.toggle('danger', !!below27);

    // ===== banner magenta (aterrizaje y/o despegue)
    const tkM = Math.min((rvr['09']?.m ?? visM) ?? Infinity, (rvr['27']?.m ?? visM) ?? Infinity);
    const tkBelow = isFinite(tkM) && tkM < 200;
    let text = '';
    if(below09 || below27){
      text = tkBelow ? '🔴 BLW ALL IFR MINMS' : '⚠️ BLW ARR MINIMUMS';
    } else if(tkBelow){
      text = '⚠️ BLW DEP MINIMUMS';
    }
    let banner = rwy.querySelector('.rwy-banner.landing');
    if(!banner){ banner=document.createElement('div'); banner.className='rwy-banner landing'; rwy.appendChild(banner); }
    banner.style.display = text ? 'block' : 'none';
    banner.textContent = text;

    // ===== Takeoff VIS
    let tk = rwy.querySelector('.rwy-tk');
    if(!tk){ tk = document.createElement('div'); tk.className='rwy-tk'; rwy.appendChild(tk); }
    if(isFinite(tkM)){
      const cls = tkM < 200 ? 'crit' : (tkM <= 1600 ? 'warn' : 'ok');
      tk.className = `rwy-tk ${cls}`;
      tk.textContent = `DEP VIS: ${fmt.m(tkM)}`;
      tk.style.display='block';
    } else {
      tk.style.display='none';
    }

    // ===== LVP ACTIVE si min(vis) ≤ 400 m
    let lvp = rwy.querySelector('.rwy-lvp');
    const minVis = Math.min(visBy09_m ?? Infinity, visBy27_m ?? Infinity);
    if(!lvp){ lvp = document.createElement('div'); lvp.className='rwy-lvp'; rwy.appendChild(lvp); }
    lvp.style.display = (isFinite(minVis) && minVis <= 400) ? 'block' : 'none';
    lvp.textContent = 'LVP ACTIVE';
  };

  // ====== Simulador: escenarios demo ======
  const minimos = {
    minimos:{
      arr:{ rwy09:{ vv_ft:250, vis_m:800,  rvr_ft:2625 },
            rwy27:{ vv_ft:513, vis_m:1600, rvr_ft:5250 } },
      dep:{ rwy09:{ vis_m:200, rvr_ft:657 },
            rwy27:{ vis_m:200, rvr_ft:657 } }
    }
  };

  const SCN = {
    VFR:{ tag:'VFR',
      metar:'METAR MMTJ 072347Z 27010KT 10SM SKC 20/13 A2992 RMK HZY',
      taf:'TAF MMTJ 072317Z 0800/0900 27008KT P6SM SKC TX22/0821Z TN14/0812Z FM080600 24005KT P6SM SCT020 BECMG 0809/0810 4SM BR SCT015 TEMPO 0811/0814 2SM BR BKN005 FM081700 30005KT 5SM HZ SKC FM082100 27008KT P6SM SKC='
    },
    LYR:{ tag:'LYR',
      metar:'METAR MMTJ 080130Z 27006KT 5SM BR BKN008 18/16 A2990',
      taf:'TAF MMTJ 072317Z 0800/0900 26008KT P6SM SCT020 BECMG 0808/0810 5SM BR BKN008'
    },
    RVR:{ tag:'RVR',
      metar:'METAR MMTJ 080900Z 00000KT 1/2SM FG VV003 16/16 A2988 R09/0600FT R27/0800FT',
      taf:'TAF MMTJ 072317Z 0800/0900 00000KT 1/2SM FG VV003 TEMPO 0809/0812 1/4SM FG VV002'
    },
    LVP:{ tag:'LVP',
      metar:'METAR MMTJ 081030Z 00000KT 1/8SM FG VV002 15/15 A2986 R09/0300FTD R27/0300FTN',
      taf:'TAF MMTJ 072317Z 0800/0900 00000KT 1/4SM FG VV002 TEMPO 0810/0813 1/8SM FG VV001'
    },
    ONLY27_LOW:{ tag:'27 LOW',
      metar:'METAR MMTJ 081100Z 00000KT 3/4SM BR VV010 16/15 A2988',
      taf:  'TAF MMTJ 072317Z 0800/0900 00000KT 3/4SM BR VV010'
    },
    ONLY09_LOW:{ tag:'09 LOW',
      metar:'METAR MMTJ 081200Z 00000KT 3SM BR VV020 16/15 A2988 R09/0600FT R27/6000FT',
      taf:'TAF MMTJ 072317Z 0800/0900 00000KT 3SM BR VV020'
    },
    BOTH_LDG_TK_LOW:{ tag:'ALL LOW',
      metar:'METAR MMTJ 081300Z 00000KT 1/8SM FG VV002 15/15 A2986 R09/0300FT R27/0300FT',
      taf:'TAF MMTJ 072317Z 0800/0900 00000KT 1/8SM FG VV002'
    }
  };

  function setMetar(raw){ $('#metar').textContent = raw || 'N/D'; }
  function setTaf(raw){
    if(!raw){ $('#taf').textContent='N/D'; return; }
    $('#taf').textContent = raw.replace(/\s+(?=(FM\d{6}|BECMG|TEMPO|PROB\d{2}\s+\d{4}\/\d{4}))/g, '\n').trim();
  }

  function apply(name){
    const s = SCN[name] || SCN.VFR;
    setMetar(s.metar); setTaf(s.taf);
    window.renderRunwayFromMetar(minimos, s.metar);
    $('#esc').textContent = s.tag;
  }



// This application handles runway rendering directly via renderRunwayFromMetar.  The
// previous listener for the custom 'sigma:wx' event, which attempted to
// delegate to an undefined renderRunwayWidget function, has been removed.
})();