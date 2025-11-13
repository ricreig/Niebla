// public/simulator/assets/js/tuner.js
(function () {
  const $ = (s) => document.querySelector(s);
  const rwy = document.querySelector('.rwy');
  if (!rwy) return;

  // ===== Defaults que me pediste (persisten en el bloque de copia) =====
  const DEFAULTS = {
    '--rwy-id-size': '45px',
    '--rwy-id-offset-y': '7px',
    '--id-left-x': '41px',
    '--id-right-x': '41px',
    '--id-left-y': '0px',
    '--id-right-y': '0px',
    '--rwy-pill-top': '-60px',
    '--rwy-pill-mid': '44px',
    '--track-y': '-1px',
    '--track-th': '3px',
    '--track-dash': '31px',
    '--track-gap': '20px',
    '--p-rot': '180deg',
    '--p-width': '5px',
    '--p-row-gap': '14px',
    '--p-len': '31px',
    '--p-bar': '6px',
    '--p-off-y': '1px',
    '--p-left-x': '15px',
    '--p-right-x': '15px',
    '--tk-font': '12px',
    '--tk-px': '6px',
    '--tk-py': '2px',
    '--tk-off-x': '0px',
    '--tk-off-y': '21px',
    '--lvp-top': '-20px',
    '--lvp-right': '-14px',
    '--lvp-font': '10px',
    '--lvp-px': '6px',
    '--lvp-py': '2px',
    '--ban-top': '10px',
    '--ban-left': '0px',
    '--ban-font': '12px',
    '--pill-font': '20px', // 1.25rem aprox
    '--pill-px': '14px',
    '--pill-py': '5px'
  };

  // Límites por control (para precisión táctil)
  const LIMITS = {
    rangeSize: [24, 64, 1],
    rangeOff: [-20, 20, 1],
    rangeIdLX: [0, 80, 1], rangeIdRX: [0, 80, 1],
    rangeIdLY: [-20, 20, 1], rangeIdRY: [-20, 20, 1],
    rangeTop: [-60, 60, 1], rangeMid: [-60, 60, 1],
    rangeTrackY: [-30, 30, 1],
    rangeTrackTh: [1, 6, 1],
    rangeTrackDash: [6, 40, 1],
    rangeTrackGap: [6, 40, 1],
    rangePRot: [0, 180, 1],
    rangePWidth: [2, 24, 1],
    rangePLen: [24, 96, 1],
    rangePBar: [2, 14, 1],
    rangePGap: [2, 18, 1],
    rangePOffY: [-30, 30, 1],
    rangePLX: [8, 80, 1], rangePRX: [8, 80, 1],
    rangeTkFont: [10, 22, 1],
    rangeTkPX: [4, 20, 1], rangeTkPY: [2, 14, 1],
    rangeTkOX: [-80, 80, 1], rangeTkOY: [-40, 40, 1],
    rangePillFont: [10, 22, 1],
    rangePillPX: [2, 14, 1], rangePillPY: [2, 10, 1],
    rangeLvpTop: [-60, 60, 1],
    rangeLvpRight: [-60, 60, 1],
    rangeLvpFont: [8, 18, 1],
    rangeLvpPX: [4, 16, 1], rangeLvpPY: [2, 12, 1],
    rangeBanTop: [0, 40, 1], rangeBanLeft: [-80, 80, 1], rangeBanFont: [10, 20, 1],
  };

  // Helpers CSS
  const stripUnit = (v) => parseFloat(String(v));
  const withUnit = (val, unit) => (unit === 'deg' ? `${val}deg` : `${val}px`);
  function cur(k) {
    const inline = rwy.style.getPropertyValue(k)?.trim();
    if (inline) return inline;
    const c = getComputedStyle(rwy).getPropertyValue(k).trim();
    return c || DEFAULTS[k] || '';
  }
  const setVar = (k, v) => { rwy.style.setProperty(k, v); refreshCSSOut(); };

  // Construye range+number según data-map, e.g. "rangeSize,numSize:--rwy-id-size:px"
  function buildControl(ctrlEl) {
    const spec = ctrlEl.getAttribute('data-map');
    if (!spec) return;
    const [ids, varName, unitRaw] = spec.split(':');
    const [rangeId, numId] = ids.split(',');
    const unit = unitRaw || 'px';

    // range
    let range = document.getElementById(rangeId);
    if (!range) {
      range = document.createElement('input');
      range.type = 'range';
      range.id = rangeId;
      range.className = 'form-range';
      const lim = LIMITS[rangeId] || [0, 100, 1];
      range.min = lim[0]; range.max = lim[1]; range.step = lim[2];
      ctrlEl.appendChild(range);
    }

    // number
    let num = document.getElementById(numId);
    if (!num) {
      num = document.createElement('input');
      num.type = 'number';
      num.id = numId;
      num.inputMode = 'decimal';
      num.className = 'num';
      num.min = range.min; num.max = range.max; num.step = range.step;
      ctrlEl.appendChild(num);
    }

    // valor inicial desde CSS o defaults
    const cssVal = cur(varName);
    const n = stripUnit(cssVal);
    if (!Number.isNaN(n)) { range.value = n; num.value = n; }

    // sync bidireccional
    const apply = (val) => {
      const v = val === '' ? range.value : val;
      setVar(varName, withUnit(v, unit));
      range.value = v; num.value = v;
    };
    range.addEventListener('input', () => apply(range.value));
    num.addEventListener('input', () => apply(num.value));
  }

  function buildAll() {
    document.querySelectorAll('.ctrl[data-map]').forEach(buildControl);
  }

  // Bloque CSS para copiar
  function buildCSS() {
    return `.rwy {\n` + Object.keys(DEFAULTS)
      .map(k => `  ${k}: ${cur(k) || DEFAULTS[k]};`).join('\n') + `\n}`;
  }
  function refreshCSSOut() {
    const out = $('#cssOut'); if (out) out.textContent = buildCSS();
  }

  // Reset a defaults
  function applyDefaults() {
    Object.entries(DEFAULTS).forEach(([k, v]) => rwy.style.setProperty(k, v));
    // vuelve a pintar inputs con los defaults
    document.querySelectorAll('.ctrl[data-map]').forEach(ctrl => {
      const spec = ctrl.getAttribute('data-map');
      const [, varName, unitRaw] = spec.split(':');
      const unit = unitRaw || 'px';
      const v = DEFAULTS[varName];
      if (!v) return;
      const [rangeId, numId] = spec.split(':')[0].split(',');
      const range = document.getElementById(rangeId);
      const num = document.getElementById(numId);
      const n = stripUnit(v);
      if (range) range.value = n;
      if (num) num.value = n;
      setVar(varName, withUnit(n, unit));
    });
    refreshCSSOut();
  }

  function init() {
    buildAll();
    $('#btnReset')?.addEventListener('click', applyDefaults);
    $('#btnCopy')?.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(buildCSS()); } catch (_e) {}
      refreshCSSOut();
    });
    refreshCSSOut();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();