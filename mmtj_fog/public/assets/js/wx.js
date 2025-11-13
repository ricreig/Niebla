// public/assets/js/wx.js
(function () {
  const $ = (s) => document.querySelector(s);

  const friBody   = $('#friBody');
  const friSrc    = $('#friSrc');
  const metarEl   = $('#metar');
  const tafEl     = $('#taf');
  const tafSource = $('#tafSource');

  async function jget(url, timeoutMs = 6000) {
    const ctl = new AbortController();
    const t = setTimeout(() => ctl.abort(), timeoutMs);
    try {
      const r = await fetch(url, { cache: 'no-store', signal: ctl.signal });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return await r.json();
    } finally { clearTimeout(t); }
  }

  const FT = 0.3048, M_IN_FT = 1 / FT, SM = 1609.344;
  const fmt = {
    int: (n) => Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 }),
    m  : (n) => n == null ? '—' : `${fmt.int(Math.round(n))} m`,
    ft : (n) => n == null ? '—' : `${fmt.int(Math.round(n))} ft`,
    sm : (n) => n == null ? '—' : `${(Math.round(n * 100) / 100).toString()} SM`,
  };

  /* ====== Parse helpers ====== */
  function colorForSeg(text) {
    const t = String(text || '').toUpperCase();
    const c = t.match(/\b(VV|OVC|BKN)(\d{3})\b/);
    if (c) {
      const h = parseInt(c[2], 10);
      if (h < 5) return 'var(--bs-pink)';
      if (h < 10) return 'var(--bs-danger)';
      if (h <= 30) return '#3399ff';
    }
    if (/\bP6SM\b/.test(t)) return 'var(--bs-body-color)';
    const v1 = t.match(/\b(\d{1,2})\s*SM\b/);
    if (v1) {
      const v = parseInt(v1[1], 10);
      if (v < 1) return 'var(--bs-pink)';
      if (v < 3) return 'var(--bs-danger)';
      if (v <= 5) return '#3399ff';
    }
    if (/\b(\d)\/(\d)\s*SM\b/.test(t)) {
      const m = t.match(/\b(\d)\/(\d)\s*SM\b/);
      const v = parseInt(m[1], 10) / Math.max(1, parseInt(m[2], 10));
      if (v < 1) return 'var(--bs-pink)';
      if (v < 3) return 'var(--bs-danger)';
    }
    if (/\b(FG|BR|TS|CB|\+RA|\+SN|LLWS|WS|VA|SS|DS)\b/.test(t)) return 'var(--bs-danger)';
    return 'var(--bs-body-color)';
  }

function colorizeTAF_multiline(raw) {
  if (!raw) return 'N/D';
  let s = String(raw).trim();

  // 1) Salto de línea antes de segmentos operativos
  s = s.replace(/\s+(?=(FM\d{6}|BECMG|TEMPO|PROB\d{2}\s+\d{4}\/\d{4}))/g, '\n');

  // 2) Asegurar espacio después de FMddhhmm si viene pegado
  s = s.replace(/(FM\d{6})(?=\S)/g, '$1 ');

  // 3) Asegurar espacio después de BECMG y TEMPO si vienen pegados
  s = s.replace(/\b(BECMG|TEMPO)(?=\S)/g, '$1 ');

  // 4) Normalizar PROBxx HHMM/HHMM (dejar un solo espacio)
  s = s.replace(/\bPROB(\d{2})\s*(\d{4}\/\d{4})/g, 'PROB$1 $2');

  const lines = s.split('\n').map(l => l.trim()).filter(Boolean);

  return lines.map(ln => {
    const m = ln.match(/^(FM\d{6}|BECMG|TEMPO|PROB\d{2}\s+\d{4}\/\d{4})\s+(.*)$/);
    if (m) {
      return `<span class="text-secondary">${m[1]}</span> <span style="color:${colorForSeg(m[2])}">${m[2]}</span>`;
    }
    return `<span style="color:${colorForSeg(ln)}">${ln}</span>`;
  }).join('\n');
}

  function parseVisSm(txt) {
    if (!txt) return null;
    if (/\bP6SM\b/i.test(txt)) return 6.01;
    const mf = txt.match(/\b(\d{1,2})\s+(\d)\/(\d)\s*SM\b/i);
    if (mf) return parseInt(mf[1], 10) + (parseInt(mf[2], 10) / Math.max(1, parseInt(mf[3], 10)));
    const f = txt.match(/\b(\d)\/(\d)\s*SM\b/i);
    if (f) return parseInt(f[1], 10) / Math.max(1, parseInt(f[2], 10));
    const m = txt.match(/\b(\d{1,2})\s*SM\b/i);
    return m ? parseFloat(m[1]) : null;
  }
  function parseRVR(txt) {
    const out = {}; if (!txt) return out;
    const re = /\bR(09|27)\/(\d{3,4})(?:V(\d{3,4}))?FT\w?\b/gi;
    let m; while ((m = re.exec(txt))) {
      const rwy = m[1];
      const ft = m[3] ? parseInt(m[3], 10) : parseInt(m[2], 10);
      out[rwy] = { ft, m: ft * FT };
    }
    return out;
  }
  function parseCeiling(txt) {
    if (!txt) return { kind: 'OK', ft: null, tag: 'OK' };
    const vv = txt.match(/\bVV(\d{3})\b/i);
    if (vv) {
      const ft = parseInt(vv[1], 10) * 100;
      return { kind: 'VV', ft, tag: `VV: ${fmt.ft(ft)}` };
    }
    let minFt = null;
    const re = /\b(OVC|BKN)(\d{3})\b/gi;
    let m; while ((m = re.exec(txt))) {
      const v = parseInt(m[2], 10) * 100;
      if (minFt === null || v < minFt) minFt = v;
    }
    if (minFt !== null) return { kind: 'LYR', ft: minFt, tag: `LYR: ${fmt.ft(minFt)}` };
    return { kind: 'OK', ft: null, tag: 'OK' };
  }

  /* ====== FRI: fallback “rápido” si no hay JSON ====== */
  function computeFRIFromMetar(raw){
    const t = String(raw||'').toUpperCase();
    let v = 10;
    const razones = [];

    if (/FG\b/.test(t)) { v += 60; razones.push('FG presente'); }
    if (/\bBR\b/.test(t)) { v += 25; razones.push('BR presente'); }

    const vis = parseVisSm(t);
    if (vis != null){
      if (vis < 0.5) { v += 35; razones.push('VIS < 1/2 SM'); }
      else if (vis < 1) { v += 25; razones.push('VIS < 1 SM'); }
      else if (vis < 3) { v += 10; razones.push('VIS < 3 SM'); }
    }

    const ceil = parseCeiling(t);
    if (ceil.kind==='VV' && ceil.ft<=300) { v += 25; razones.push(`VV ≤ 300 ft`); }
    if (ceil.kind==='LYR' && ceil.ft && ceil.ft<=500) { v += 15; razones.push(`LYR ≤ 500 ft`); }

    v = Math.max(0, Math.min(100, v));
    return { fri_pct: v, razones };
  }

  function friBadge(val) {
    if (val == null) return ['secondary', 'N/D'];
    val = Number(val) || 0;
    if (val <= 30) return ['success', 'BAJO'];
    if (val <= 60) return ['warning', 'MOD'];
    return ['danger', 'ALTO'];
  }
  function renderFRI(obj, srcUrl) {
    const val = (obj && (obj.fri_pct ?? obj.fri?.fri ?? obj.fri)) || 0;
    const razones = (obj && (obj.razones ?? obj.fri?.razones)) || [];
    const [cls, tag] = friBadge(val);
    const pills = razones.map(r => `<div class="small text-muted">• ${r}</div>`).join('');
    if (friBody) friBody.innerHTML = `<span class="badge text-bg-${cls} me-2">${tag}</span> Fog Risk Indicator: ${val}${pills ? '<div class="mt-1">' + pills + '</div>' : ''}`;
    if (friSrc)  friSrc.href = srcUrl || '#';
  }

  /* ====== Runway render (igual que tenías) ====== */
  function renderRunway(minWrap, metarTxt) {
    const min = minWrap?.minimos || minWrap || {};
    const L = (min.arr?.rwy09) || (min.ARR?.RWY09) || {};
    const R = (min.arr?.rwy27) || (min.ARR?.RWY27) || {};
    const rwy = document.querySelector('.rwy'); if (!rwy) return;

    const ceil = parseCeiling(metarTxt);
    const visSM = parseVisSm(metarTxt);
    const visM  = visSM ? visSM * SM : null;
    const rvr   = parseRVR(metarTxt);

    const visBy09_m = rvr['09']?.m ?? visM;
    const visBy27_m = rvr['27']?.m ?? visM;

    if (!rwy.querySelector('.rwy-track')) {
      const track = document.createElement('div'); track.className = 'rwy-track'; rwy.appendChild(track);
    }
    if (!rwy.querySelector('.rwy-id.left'))  { const idL = document.createElement('div'); idL.className='rwy-id left';  idL.textContent='09'; rwy.appendChild(idL); }
    if (!rwy.querySelector('.rwy-id.right')) { const idR = document.createElement('div'); idR.className='rwy-id right'; idR.textContent='27'; rwy.appendChild(idR); }

    const need = [
      '.rwy-badges.top.left','.rwy-badges.top.right',
      '.rwy-badges.mid.left','.rwy-badges.mid.right',
      '.rwy-badges.bottom.left','.rwy-badges.bottom.right'
    ];
    for(const sel of need){
      if(!rwy.querySelector(sel)){
        const d = document.createElement('div');
        d.className = 'rwy-badges ' + sel.split('.').slice(1).join(' ');
        rwy.appendChild(d);
      }
    }

    const topHTML = (ceil.kind === 'VV')
      ? `<span class="badge text-bg-secondary">VV</span><span class="badge text-bg-dark">${fmt.ft(ceil.ft)}</span>`
      : (ceil.kind === 'LYR')
        ? `<span class="badge text-bg-secondary">LYR</span><span class="badge text-bg-dark">${fmt.ft(ceil.ft)}</span>`
        : `<span class="badge text-bg-secondary">LYR</span><span class="badge text-bg-dark">OK</span>`;
    document.querySelector('.rwy-badges.top.left').innerHTML  = topHTML;
    document.querySelector('.rwy-badges.top.right').innerHTML = topHTML;

    function mid(valM, rvrFT){
      if(rvrFT!=null){
        const m = Math.round(rvrFT*FT);
        return `<span class="badge text-bg-secondary">RVR</span><span class="badge text-bg-dark">${fmt.m(m)} / ${fmt.ft(rvrFT)}</span>`;
      }
      if(valM==null) return `<span class="badge text-bg-secondary">VIS</span><span class="badge text-bg-dark">—</span>`;
      return `<span class="badge text-bg-secondary">VIS</span><span class="badge text-bg-dark">${fmt.sm(visSM)} / ${fmt.ft(valM*M_IN_FT)} / ${fmt.m(valM)}</span>`;
    }
    document.querySelector('.rwy-badges.mid.left').innerHTML  = mid(visBy09_m, rvr['09']?.ft ?? null);
    document.querySelector('.rwy-badges.mid.right').innerHTML = mid(visBy27_m, rvr['27']?.ft ?? null);

    const l_b = document.querySelector('.rwy-badges.bottom.left');
    const r_b = document.querySelector('.rwy-badges.bottom.right');
    if(rvr['09']?.ft){ l_b.style.display='inline-flex'; l_b.innerHTML = `<span class="badge text-bg-secondary">RVR</span><span class="badge text-bg-dark">${fmt.ft(rvr['09'].ft)}</span>`; } else { l_b.style.display='none'; }
    if(rvr['27']?.ft){ r_b.style.display='inline-flex'; r_b.innerHTML = `<span class="badge text-bg-secondary">RVR</span><span class="badge text-bg-dark">${fmt.ft(rvr['27'].ft)}</span>`; } else { r_b.style.display='none'; }

    const idL = document.querySelector('.rwy-id.left');
    const idR = document.querySelector('.rwy-id.right');
    const below09 = ((ceil.ft != null && L.vv_ft && ceil.ft < L.vv_ft) || (visBy09_m != null && L.vis_m && visBy09_m < L.vis_m));
    const below27 = ((ceil.ft != null && R.vv_ft && ceil.ft < R.vv_ft) || (visBy27_m != null && R.vis_m && visBy27_m < R.vis_m));
    idL.classList.toggle('danger', !!below09);
    idR.classList.toggle('danger', !!below27);

    let banner = rwy.querySelector('.rwy-banner.landing');
    if (!banner) { banner = document.createElement('div'); banner.className = 'rwy-banner landing'; rwy.appendChild(banner); }
    banner.style.display = (below09 && below27) ? 'block' : 'none';
    banner.textContent = 'BELOW LANDING MINIMUMS';

    let tk = rwy.querySelector('.rwy-tk');
    if (!tk) { tk = document.createElement('div'); tk.className = 'rwy-tk'; rwy.appendChild(tk); }
    const tkM = Math.min((rvr['09']?.m ?? visM) ?? Infinity, (rvr['27']?.m ?? visM) ?? Infinity);
    if (isFinite(tkM)) {
      const cls = tkM < 200 ? 'crit' : (tkM <= 1600 ? 'warn' : 'ok');
      tk.className = `rwy-tk ${cls}`;
      tk.textContent = `TakeOff VIS: ${fmt.m(tkM)}`;
      tk.style.display = 'block';
    } else {
      tk.style.display = 'none';
    }

    let lvp = rwy.querySelector('.rwy-lvp');
    if (!lvp) { lvp = document.createElement('div'); lvp.className = 'rwy-lvp'; rwy.appendChild(lvp); }
    const minVis = Math.min(visBy09_m ?? Infinity, visBy27_m ?? Infinity);
    lvp.style.display = (isFinite(minVis) && minVis <= 400) ? 'block' : 'none';
    lvp.textContent = 'LVP ACTIVE';
  }

  function setMetar(raw) { if(metarEl) metarEl.textContent = raw || 'N/D'; }
  function setTaf(raw, srcHint) {
    if(!tafEl) return;
    tafEl.innerHTML = raw ? colorizeTAF_multiline(raw) : 'N/D';
    if (tafSource) {
      const isCapma = /mmtj_fog/i.test(srcHint || '') || /CAPMA/i.test(raw || '');
      tafSource.textContent = `Fuente: ${isCapma ? 'CAPMA' : 'NOAA'}`;
    }
  }

  async function loadAll() {
    try{
      const friUrl = `${location.origin}/mmtj_fog/public/api/fri.json`;
      const fri = await jget(friUrl, 5000).catch(() => null);
      if (fri) renderFRI(fri, friUrl);

      const metarUrl = (fri?.links?.metar) || `${location.origin}/mmtj_fog/data/metar.json`;
      const tafUrl   = (fri?.links?.taf)   || `${location.origin}/mmtj_fog/data/taf.json`;
      const [metarJ, tafJ] = await Promise.all([
        jget(metarUrl, 5000).catch(() => null),
        jget(tafUrl,   5000).catch(() => null),
      ]);
      const metarRaw = metarJ && (metarJ.raw || metarJ.raw_text || metarJ.metar || metarJ.text) || '';
      const tafRaw   = tafJ   && (tafJ.raw   || tafJ.raw_text   || tafJ.taf   || tafJ.text)   || '';
      setMetar(metarRaw);
      setTaf(tafRaw, tafUrl);

      // FRI fallback si no llegó JSON
      if (!fri && metarRaw) {
        const quick = computeFRIFromMetar(metarRaw);
        renderFRI(quick, '');
      }

      const minUrl = `${location.origin}/sigma/api/minimos.php`;
      const min = await jget(minUrl, 5000).catch(() => null);
      if (min) renderRunway(min, metarRaw);

      if (!fri && !metarRaw && !tafRaw && friBody) friBody.textContent = 'Error cargando FRI/METAR/TAF';
    }catch(e){
      console.error('wx load error:', e);
      if (friBody) friBody.textContent = 'Error cargando FRI/METAR/TAF';
    }
  }

  document.addEventListener('DOMContentLoaded', loadAll);
  window.renderRunwayFromMetar = (metarRaw, minimos) => renderRunway(minimos, metarRaw);
})();