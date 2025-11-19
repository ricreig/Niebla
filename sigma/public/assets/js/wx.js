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
      return `<div class="taf-line"><span class="taf-token">${m[1]}</span><span class="taf-body" style="color:${colorForSeg(m[2])}">${m[2]}</span></div>`;
    }
    return `<div class="taf-line"><span class="taf-body" style="color:${colorForSeg(ln)}">${ln}</span></div>`;
  }).join('');
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

  /* ====== Runway render (deprecated here) ======
   * The legacy runway rendering logic has been removed from this file.  This
   * application now uses the implementation in `runway.js` exclusively.  When
   * new METAR or minimums data is loaded, wx.js delegates the rendering
   * to the global `window.renderRunwayFromMetar` defined in `runway.js`.
   */

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
      // If both minimums and a METAR are available, delegate rendering to
      // the global implementation from runway.js.  This avoids using the
      // deprecated runway logic previously embedded in wx.js.
      if (min && typeof window.renderRunwayFromMetar === 'function') {
        window.renderRunwayFromMetar(min, metarRaw);
      }

      if (!fri && !metarRaw && !tafRaw && friBody) friBody.textContent = 'Error cargando FRI/METAR/TAF';
    }catch(e){
      console.error('wx load error:', e);
      if (friBody) friBody.textContent = 'Error cargando FRI/METAR/TAF';
    }
  }

  document.addEventListener('DOMContentLoaded', loadAll);
  // If runway.js has not yet defined renderRunwayFromMetar, provide a
  // harmless no-op to avoid errors.  The real implementation lives
  // in runway.js and will overwrite this stub on load.
  if (typeof window.renderRunwayFromMetar !== 'function') {
    window.renderRunwayFromMetar = function() {};
  }
})();