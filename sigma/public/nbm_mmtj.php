<?php
// nbm_mmtj.php
// Vista NBH/NBS NBM v4.3 para MMTJ mostrando TODOS los campos de la tarjeta,
// destacando visibilidad y techo (MVV, IFV, LIV, MVC, IFC, LIC).

$STATION  = 'MMTJ';
$BASE_URL = 'https://nomads.ncep.noaa.gov/pub/data/nccf/com/blend/prod';

// Permite deshabilitar el render cuando se importa desde CLI para pruebas.
if (!defined('NBM_MMTJ_RENDER')) {
    define('NBM_MMTJ_RENDER', true);
}

// Modo: NBH (1–24h) o NBS (~72h)
$mode = (isset($_GET['mode']) && $_GET['mode'] === 'NBS') ? 'NBS' : 'NBH';

// ---------------------------------------------------------
// HTTP GET simple con cURL
// ---------------------------------------------------------
function http_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'CTAReig-NBM/1.0 (+https://ctareig.com)'
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = ($data === false) ? curl_error($ch) : null;
    curl_close($ch);

    if ($code >= 200 && $code < 300 && $data !== false) {
        // Algunos endpoints de NOMADS responden comprimidos; lo detectamos por firma gzip.
        if (strncmp($data, "\x1f\x8b", 2) === 0) {
            $decoded = @gzdecode($data);
            if ($decoded !== false) {
                $data = $decoded;
            } else {
                error_log('nbm_mmtj: gzdecode falló para ' . $url);
            }
        }
        return $data;
    }

    error_log('nbm_mmtj: HTTP ' . $code . ' al solicitar ' . $url . ($err ? ' (error: ' . $err . ')' : ''));
    return null;
}

// ---------------------------------------------------------
// Construir URL del archivo NBH/NBS en NOMADS
// ---------------------------------------------------------
function build_nbm_url($base, $bulletin, $dateYmd, $cycleHour) {
    $cycle = str_pad($cycleHour, 2, '0', STR_PAD_LEFT);
    $file  = ($bulletin === 'NBH') ? 'blend_nbhtx' : 'blend_nbstx';
    return sprintf(
        '%s/blend.%s/%s/text/%s.t%sz',
        $base,
        $dateYmd,
        $cycle,
        $file,
        $cycle
    );
}

// ---------------------------------------------------------
// Fetch con fallback: intenta ciclo actual y hasta 3 horas atrás
// ---------------------------------------------------------
function fetch_nbm_text_with_fallback($base, $bulletin) {
    $now  = new DateTime('now', new DateTimeZone('UTC'));
    $date = $now->format('Ymd');
    $hour = (int)$now->format('G');

    for ($offset = 0; $offset <= 3; $offset++) {
        $h = $hour - $offset;
        $d = $date;
        if ($h < 0) {
            $h += 24;
            $prev = clone $now;
            $prev->modify('-1 day');
            $d = $prev->format('Ymd');
        }
        $url  = build_nbm_url($base, $bulletin, $d, $h);
        $text = http_get($url);
        if ($text !== null) {
            return [$text, $d, str_pad($h, 2, '0', STR_PAD_LEFT), $url];
        }
    }
    return [null, null, null, null];
}

// ---------------------------------------------------------
// Extraer bloque de la estación (MMTJ) del texto masivo
// ---------------------------------------------------------
function extract_station_block($text, $station) {
    // El encabezado real es "MMTJ   NBM V4.3 NBH GUIDANCE   11/14/2025  0800 UTC".
    // La versión previa usaba /...$/ sin modificador m, por lo que jamás coincidía con
    // líneas intermedias del archivo gigante. Prueba local:
    // preg_match_all($patternHeader, $text, $matches, PREG_OFFSET_CAPTURE) => 1 coincidencia en offset ~23M.
    $patternHeader = '/^[\x00-\x20]*' . preg_quote($station, '/') .
                     '\s+NBM\s+V[0-9.]+\s+NB[HS]\s+GUIDANCE\s+.*$/mi';

    if (!preg_match($patternHeader, $text, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $startPos   = $m[0][1];
    $headerLine = $m[0][0];
    $block      = substr($text, $startPos);

    // Buscamos el siguiente encabezado de otra estación desde el final de la línea actual.
    $offsetStart = strlen($headerLine);
    $patternNext = '/(?:\r?\n)[\x00-\x20]*[A-Z0-9]{3,4}\s+NBM\s+V[0-9.]+\s+NB[HS]\s+GUIDANCE\s+.*$/mi';

    if (preg_match($patternNext, $block, $m2, PREG_OFFSET_CAPTURE, $offsetStart)) {
        $endPos = $m2[0][1];
        return rtrim(substr($block, 0, $endPos));
    }

    return trim($block);
}

// ---------------------------------------------------------
// Parsear bloque: detectar TODAS las filas de datos
// ---------------------------------------------------------
function nbm_detect_repeated_chunk($numeric) {
    $len = strlen($numeric);
    if ($len <= 1) {
        return null;
    }

    $maxChunk = min(4, (int)floor($len / 2));
    for ($size = 2; $size <= $maxChunk; $size++) {
        if ($len % $size !== 0) {
            continue;
        }
        $chunk = substr($numeric, 0, $size);
        if ($chunk === '') {
            continue;
        }
        if (str_repeat($chunk, (int)($len / $size)) === $numeric) {
            return $chunk;
        }
    }

    return null;
}

function nbm_preferred_chunk_width($key) {
    static $map = [
        'WDR' => 3,
        'TWD' => 3,
        'CIG' => 3,
        'LCB' => 3,
    ];

    if ($key === null) {
        return null;
    }

    return $map[$key] ?? null;
}

function nbm_expand_numeric_parts(array $parts, $expectedCount, $key = null) {
    if ($expectedCount <= 0) {
        return $parts;
    }

    $expanded = [];
    $preferredWidth = nbm_preferred_chunk_width($key);

    foreach ($parts as $part) {
        if (!preg_match('/^-?\d+$/', $part)) {
            $expanded[] = $part;
            continue;
        }

        $sign    = ($part[0] === '-') ? '-' : '';
        $numeric = ($sign === '-') ? substr($part, 1) : $part;
        $len     = strlen($numeric);
        $remainingSlots = $expectedCount - count($expanded);

        if ($remainingSlots <= 1 || $len <= 3) {
            $expanded[] = $sign . $numeric;
            continue;
        }

        $chunks = [];
        $chunkPattern = nbm_detect_repeated_chunk($numeric);
        if ($chunkPattern !== null && strlen($chunkPattern) > 1) {
            $chunkLen = strlen($chunkPattern);
            $repeat   = (int)($len / $chunkLen);
            if ($repeat <= $remainingSlots) {
                foreach (str_split($numeric, $chunkLen) as $chunk) {
                    $chunks[] = $sign . $chunk;
                }
            }
        }

        if (empty($chunks)) {
            $bestChunks = [];
            for ($count3 = 0; $count3 <= intdiv($len, 3); $count3++) {
                $usedLen = $count3 * 3;
                $remainingLen = $len - $usedLen;
                if ($remainingLen < 0) {
                    break;
                }
                if ($remainingLen % 2 !== 0) {
                    continue;
                }
                $count2 = (int)($remainingLen / 2);
                $segments = $count2 + $count3;
                if ($segments <= 1 || $segments > $remainingSlots) {
                    continue;
                }
                $bestChunks[] = [$segments, $count3, $count2];
            }

            if (!empty($bestChunks)) {
                $preferThree = ($preferredWidth === 3);
                $preferTwo   = ($preferredWidth === 2);
                if (!$preferThree && !$preferTwo && $len % 3 === 0) {
                    $preferThree = true;
                }

                usort($bestChunks, function ($a, $b) use ($preferThree, $preferTwo) {
                    if ($preferThree) {
                        if ($a[1] !== $b[1]) {
                            return $b[1] <=> $a[1];
                        }
                        if ($a[0] !== $b[0]) {
                            return $a[0] <=> $b[0];
                        }
                        if ($a[2] !== $b[2]) {
                            return $a[2] <=> $b[2];
                        }
                        return 0;
                    }

                    if ($preferTwo) {
                        if ($a[2] !== $b[2]) {
                            return $b[2] <=> $a[2];
                        }
                        if ($a[0] !== $b[0]) {
                            return $a[0] <=> $b[0];
                        }
                        if ($a[1] !== $b[1]) {
                            return $b[1] <=> $a[1];
                        }
                        return 0;
                    }

                    if ($a[0] !== $b[0]) {
                        return $b[0] <=> $a[0];
                    }
                    if ($a[1] !== $b[1]) {
                        return $b[1] <=> $a[1];
                    }
                    return 0;
                });

                [$segments, $count3, $count2] = $bestChunks[0];
                $sizes = array_merge(array_fill(0, $count3, 3), array_fill(0, $count2, 2));
                $offset = 0;
                foreach ($sizes as $size) {
                    $chunks[] = $sign . substr($numeric, $offset, $size);
                    $offset  += $size;
                }
            }
        }

        if (empty($chunks) && $len > 3) {
            $baseChunks = intdiv($len, 3);
            if ($baseChunks >= 1 && $baseChunks <= $remainingSlots) {
                $chunks = array_map(function ($chunk) use ($sign) {
                    return $sign . $chunk;
                }, str_split(substr($numeric, 0, $baseChunks * 3), 3));

                $remainder = $len % 3;
                if ($remainder > 0) {
                    $lastIndex = count($chunks) - 1;
                    if ($lastIndex >= 0) {
                        $chunks[$lastIndex] = $sign . substr($numeric, $baseChunks * 3 - 3, 3 + $remainder);
                    } else {
                        $chunks[] = $sign . $numeric;
                    }
                }
            }
        }

        if (empty($chunks)) {
            $expanded[] = $sign . $numeric;
        } else {
            foreach ($chunks as $chunk) {
                if (count($expanded) >= $expectedCount) {
                    break;
                }
                $expanded[] = $chunk;
            }
        }
    }

    return $expanded;
}

function parse_nbm_block_all($block) {
    $lines = preg_split('/\R+/', trim($block));
    $times = [];
    $data  = [];
    $order = [];

    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') continue;

        $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) < 2) continue;

        $key = array_shift($parts);

        // Fila de horas
        if ($key === 'UTC') {
            // La primera vez puede ser "0800 UTC", la segunda "UTC 09 10..."
            // Nos quedamos con la que tenga más columnas numéricas.
            $numericCount = 0;
            foreach ($parts as $p) {
                if (preg_match('/^-?\d+$/', $p)) {
                    $numericCount++;
                }
            }
            if ($numericCount >= 2) {
                $times = $parts;
            }
            continue;
        }

        // Filtrar líneas no-datos tipo "NBHUSA", "000", etc.
        if (!preg_match('/^[A-Z0-9]{3,4}$/', $key)) {
            continue;
        }

        // Validar que la mayoría de los campos sean numéricos
        $numericCount = 0;
        foreach ($parts as $p) {
            if (preg_match('/^-?\d+$/', $p)) {
                $numericCount++;
            }
        }
        if ($numericCount < max(1, (int)(0.5 * count($parts)))) {
            continue;
        }

        $expandedParts = nbm_expand_numeric_parts($parts, count($times), $key);

        if (!isset($data[$key])) {
            $order[] = $key;
        }
        $converted = [];
        foreach ($expandedParts as $value) {
            if (!preg_match('/^-?\d+$/', $value)) {
                $converted[] = null;
                continue;
            }
            if (strlen($value) > 9) {
                $converted[] = null;
                continue;
            }
            $converted[] = (int)$value;
        }

        $data[$key] = $converted;
        $data[$key] = array_map('intval', $expandedParts);
    }

    return [$times, $data, $order];
}

// ---------------------------------------------------------
// Derivar categoría FAA probable por hora
// ---------------------------------------------------------
function derive_categories($times, $data) {
    $cats = [];
    $n    = count($times);

    for ($i = 0; $i < $n; $i++) {
        $mvv = isset($data['MVV'][$i]) ? (int)$data['MVV'][$i] : 0;
        $ifv = isset($data['IFV'][$i]) ? (int)$data['IFV'][$i] : 0;
        $liv = isset($data['LIV'][$i]) ? (int)$data['LIV'][$i] : 0;
        $mvc = isset($data['MVC'][$i]) ? (int)$data['MVC'][$i] : 0;
        $ifc = isset($data['IFC'][$i]) ? (int)$data['IFC'][$i] : 0;
        $lic = isset($data['LIC'][$i]) ? (int)$data['LIC'][$i] : 0;

        $cat = 'VFR';
        if ($liv >= 50 || $lic >= 50) {
            $cat = 'LIFR';
        } elseif ($ifv >= 50 || $ifc >= 50) {
            $cat = 'IFR';
        } elseif ($mvv >= 50 || $mvc >= 50) {
            $cat = 'MVFR';
        }
        $cats[] = $cat;
    }

    return $cats;
}

function cat_css_class($cat) {
    switch ($cat) {
        case 'LIFR': return 'cat-lifr';
        case 'IFR':  return 'cat-ifr';
        case 'MVFR': return 'cat-mvfr';
        default:     return 'cat-vfr';
    }
}

// ---------------------------------------------------------
// Ejecutar flujo
// ---------------------------------------------------------
$mockText = $GLOBALS['NBM_MMTJ_MOCK_TEXT'] ?? null;
if (is_string($mockText) && $mockText !== '') {
    $rawText = $mockText;
    $dateYmd = $GLOBALS['NBM_MMTJ_MOCK_DATE'] ?? null;
    $cycle   = $GLOBALS['NBM_MMTJ_MOCK_CYCLE'] ?? null;
    $srcUrl  = $GLOBALS['NBM_MMTJ_MOCK_SRC'] ?? 'mock://local';
} else {
    list($rawText, $dateYmd, $cycle, $srcUrl) = fetch_nbm_text_with_fallback($BASE_URL, $mode);
}

$times = $data = $cats = $order = [];
$error = null;
$block = null;

if ($rawText === null) {
    $error = 'No se pudo descargar el archivo NBM desde NOMADS (NB' . strtolower($mode) . ').';
} else {
    $block = extract_station_block($rawText, $STATION);
    if ($block === null) {
        $error = 'No se encontró el bloque de estación ' . $STATION . ' en el archivo NBM.';
    } else {
        list($times, $data, $order) = parse_nbm_block_all($block);
        if (empty($times) || empty($data) || empty($order)) {
            $error = 'No se pudieron parsear las filas de datos de la tarjeta NBM.';
        } else {
            $cats = derive_categories($times, $data);
        }
    }
}

// Descripciones conocidas (puedes ampliar este catálogo)
$labels = [
    'TMP' => 'Temperatura',
    'TSD' => 'Desv. estándar de temperatura',
    'DPT' => 'Temp. de rocío',
    'DSD' => 'Desv. estándar de rocío',
    'SKY' => 'Cobertura de cielo',
    'SSD' => 'Desv. estándar de SKY',
    'WDR' => 'Dirección del viento (°)',
    'WSP' => 'Velocidad del viento (kt)',
    'WSD' => 'Desv. estándar del viento',
    'GST' => 'Ráfaga (kt)',
    'GSD' => 'Desv. estándar de ráfaga',
    'P01' => 'Prob. precipitación 1h (%)',
    'P03' => 'Prob. precipitación 3h (%)',
    'P06' => 'Prob. precipitación 6h (%)',
    'Q01' => 'Cantidad precipitación 1h',
    'Q06' => 'Cantidad precipitación 6h',
    'T01' => 'Prob. precipitación congelante 1h',
    'I01' => 'Prob. precipitación invernal 1h',
    'CIG' => 'Techo (hundreds ft, -88 => >12000)',
    'LCB' => 'Base de capa más baja (hundreds ft)',
    'VIS' => 'Visibilidad (índice/SM*10 según producto)',
    'MHT' => 'Altura de mezcla',
    'TWD' => 'Dir. viento tope mezcla',
    'TWS' => 'Vel. viento tope mezcla',
    'HID' => 'Hidrometeoro dominante',
    'MVV' => 'Prob. visibilidad MVFR (≤ 5 SM)',
    'IFV' => 'Prob. visibilidad IFR (< 3 SM)',
    'LIV' => 'Prob. visibilidad LIFR (< 1 SM)',
    'MVC' => 'Prob. techo MVFR (≤ 3000 ft)',
    'IFC' => 'Prob. techo IFR (< 1000 ft)',
    'LIC' => 'Prob. techo LIFR (< 500 ft)',
    'PZR' => 'Prob. condicional de lluvia gélida (%)',
    'PSN' => 'Prob. condicional de nieve (%)',
    'PPL' => 'Prob. condicional de aguanieve / hielo (%)',
    'PRA' => 'Prob. condicional de lluvia (%)',
    'S01' => 'Acumulado de nieve 1h (1/10 pulgadas)',
    'SOL' => 'Sol (índice)',
    'SLV' => 'Snow level / variable auxiliar',
];

// Claves a resaltar (visibilidad y techo)
$focusKeys = ['MVV', 'IFV', 'LIV', 'MVC', 'IFC', 'LIC'];
$rowColorClasses = [
    'MVV' => 'row-mvv',
    'IFV' => 'row-ifv',
    'LIV' => 'row-liv',
    'MVC' => 'row-mvc',
    'IFC' => 'row-ifc',
    'LIC' => 'row-lic',
];

if (!NBM_MMTJ_RENDER) {
    return;
}

?>
<!doctype html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <title>NBM v4.3 – NBH/NBS MMTJ (TIJUANA) · Probabilidades y guía completa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Tu paleta -->
    <link href="assets/css/metar.css" rel="stylesheet">
    <style>
        body {
            zoom: 0.7;
        }

        .cat-vfr  { background-color: #198754; color: #fff; }
        .cat-mvfr { background-color: #0d6efd; color: #fff; }
        .cat-ifr  { background-color: #dc3545; color: #fff; }
        .cat-lifr { background-color: #d63384; color: #fff; }

        .nbm-table th, .nbm-table td {
            font-size: 0.78rem;
            text-align: center;
            vertical-align: middle;
            padding: 0.25rem 0.35rem;
            white-space: nowrap;
        }

        .nbm-table th.sticky-col {
            position: sticky;
            left: 0;
            z-index: 5;
            background-color: #1f2329;
            z-index: 3;
            background-color: rgba(33, 37, 41, 0.92);
            color: #f8f9fa;
            white-space: normal;
            width: 11rem;
            max-width: 11rem;
            min-width: 11rem;
            word-break: break-word;
            box-shadow: 4px 0 8px rgba(0, 0, 0, 0.45);
        }

        .nbm-var-name {
            font-weight: 600;
            display: block;
        }

        .nbm-var-desc {
            font-size: 0.58rem;
            display: block;
            line-height: 1.1;
            color: #ced4da;
        }

        .nbm-row-header {
            text-align: left;
        }

        .nbm-row-flag {
            border-left: 0.45rem solid transparent;
        }

        .nbm-row-flag.nbm-row-flag-good {
            border-left: 4px solid transparent;
        }

        .nbm-row-flag.nbm-row-flag-good {
            background-color: rgba(25, 135, 84, 0.33) !important;
            color: #fff !important;
            border-left-color: #198754;
        }

        .nbm-row-flag.nbm-row-flag-warning {
            background-color: rgba(255, 193, 7, 0.33) !important;
            color: #212529 !important;
            border-left-color: #ffc107;
        }

        .nbm-row-flag.nbm-row-flag-critical {
            background-color: rgba(220, 53, 69, 0.33) !important;
            color: #fff !important;
            border-left-color: #dc3545;
        }

        .nbm-cell-warning {
            background-color: rgba(255, 193, 7, 0.35) !important;
            color: #212529 !important;
        }

        .nbm-cell-critical {
            background-color: rgba(220, 53, 69, 0.35) !important;
            color: #fff !important;
        }

        .row-mvv > td { background-color: rgba(13, 110, 253, 0.12); }

        .row-ifv > td { background-color: rgba(111, 66, 193, 0.12); }

        .row-liv > td { background-color: rgba(214, 51, 132, 0.12); }

        .row-mvc > td { background-color: rgba(102, 16, 242, 0.12); }

        .row-ifc > td { background-color: rgba(0, 123, 255, 0.12); }

        .row-mvv > th,
        .row-mvv > td { background-color: rgba(13, 110, 253, 0.12); }

        .row-ifv > th,
        .row-ifv > td { background-color: rgba(111, 66, 193, 0.12); }

        .row-liv > th,
        .row-liv > td { background-color: rgba(214, 51, 132, 0.12); }

        .row-mvc > th,
        .row-mvc > td { background-color: rgba(102, 16, 242, 0.12); }

        .row-ifc > th,
        .row-ifc > td { background-color: rgba(0, 123, 255, 0.12); }

        .row-lic > th,
        .row-lic > td { background-color: rgba(220, 53, 69, 0.12); }

        .nbm-cell-warning,
        .nbm-cell-critical {
            font-weight: 600;
        }

        .nbm-table td {
            transition: background-color 0.2s ease-in-out;
        }

        .card-main {
            width: 100%;
        }

        .legend-box {
            display: inline-block;
            width: 0.9rem;
            height: 0.9rem;
            border-radius: 0.2rem;
            margin-right: 0.3rem;
        }

        .card-header small {
            font-size: 0.75rem;
        }
    </style>
</head>
<body>
<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">NBM v4.3 · MMTJ (TIJUANA_INTL_ARPT)</h1>
            <div class="text-secondary small">
                Guía NBH/NBS por estación desde NBM (NOMADS), mostrando todos los campos de la tarjeta.
                <?php if ($dateYmd && $cycle): ?>
                    <br>Fuente: <span class="text-light">blend.<?= htmlspecialchars($dateYmd) ?>/<?= htmlspecialchars($cycle) ?>Z</span>
                <?php endif; ?>
            </div>
        </div>

        <form method="get" class="ms-auto">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="modeSwitch"
                       name="mode" value="NBS" onchange="this.form.submit()"
                    <?= ($mode === 'NBS') ? 'checked' : '' ?>>
                <label class="form-check-label small" for="modeSwitch">
                    NBH (1–24h)
                    <span class="ms-1 fw-bold">/</span>
                    <span class="ms-1">NBS (≈72h)</span>
                </label>
            </div>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?><br>
            Valida conectividad hacia NOMADS y disponibilidad del ciclo NBM v4.3.
        </div>
    <?php else: ?>

        <div class="row g-3">
            <div class="col-12">
                <div class="card shadow-sm card-main w-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <strong>
                                <?= ($mode === 'NBH') ? 'NBH – Hourly (1–24 h)' : 'NBS – Short Range (≈6–72 h)' ?>
                            </strong>
                            <div class="small text-secondary">
                                Tarjeta completa NBM para MMTJ, con énfasis en MVFR/IFR/LIFR de visibilidad y techo.
                            </div>
                        </div>
                        <div class="text-end small text-secondary">
                            Fila <strong>CAT</strong>: categoría FAA probable (VFR / MVFR / IFR / LIFR),
                            derivada de MVV/IFV/LIV y MVC/IFC/LIC (umbral 50&nbsp;%).
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0 nbm-table align-middle">
                                <thead>
                                <tr>
                                    <th class="sticky-col nbm-row-header">
                                        Variable
                                    </th>
                                    <?php foreach ($times as $t): ?>
                                        <th><?= htmlspecialchars($t) ?>Z</th>
                                    <?php endforeach; ?>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($order as $key): ?>
                                    <?php
                                    $row      = $data[$key] ?? [];
                                    $valCnt   = count($times);
                                    $desc     = $labels[$key] ?? '';
                                    $rowClass = $rowColorClasses[$key] ?? '';
                                    $isFocus  = in_array($key, $focusKeys, true);
                                    $hasWarn  = false;
                                    $hasCrit  = false;
                                    $cellClasses = array_fill(0, $valCnt, '');

                                    if ($isFocus) {
                                        for ($i = 0; $i < $valCnt; $i++) {
                                            $val = $row[$i] ?? null;
                                            if ($val === null) {
                                                continue;
                                            }
                                            if ($val >= 100) {
                                                $cellClasses[$i] = 'nbm-cell-critical';
                                                $hasCrit = true;
                                            } elseif ($val > 75) {
                                                $cellClasses[$i] = 'nbm-cell-warning';
                                                $hasWarn = true;
                                            }
                                        }
                                    }

                                    $thClasses = ['sticky-col', 'nbm-row-header'];
                                    if ($isFocus) {
                                        $thClasses[] = 'nbm-row-flag';
                                        if ($hasCrit) {
                                            $thClasses[] = 'nbm-row-flag-critical';
                                        } elseif ($hasWarn) {
                                            $thClasses[] = 'nbm-row-flag-warning';
                                        } else {
                                            $thClasses[] = 'nbm-row-flag-good';
                                        }
                                    }

                                    $rowClassAttr = ($isFocus && $rowClass !== '')
                                        ? ' class="' . htmlspecialchars($rowClass) . '"'
                                        : '';
                                    ?>
                                    <tr<?= $rowClassAttr ?>>
                                        <th class="<?= implode(' ', $thClasses) ?>">
                                    $hasWarn  = false;
                                    $hasCrit  = false;
                                    $cellClasses = [];

                                    for ($i = 0; $i < $valCnt; $i++) {
                                        $val = $row[$i] ?? null;
                                        $cellClass = '';
                                        if ($val !== null) {
                                            if ($val >= 100) {
                                                $cellClass = 'nbm-cell-critical';
                                                $hasCrit   = true;
                                            } elseif ($val > 75) {
                                                $cellClass = 'nbm-cell-warning';
                                                $hasWarn   = true;
                                            }
                                        }
                                        $cellClasses[$i] = $cellClass;
                                    }

                                    $rowFlagClass = 'nbm-row-flag nbm-row-flag-good';
                                    if ($hasCrit) {
                                        $rowFlagClass = 'nbm-row-flag nbm-row-flag-critical';
                                    } elseif ($hasWarn) {
                                        $rowFlagClass = 'nbm-row-flag nbm-row-flag-warning';
                                    }
                                    $rowClassAttr = $rowClass !== '' ? ' class="' . htmlspecialchars($rowClass) . '"' : '';
                                    ?>
                                    <tr<?= $rowClassAttr ?>>
                                        <th class="sticky-col nbm-row-header <?= $rowFlagClass ?>">
                                            <span class="nbm-var-name"><?= htmlspecialchars($key) ?></span>
                                            <?php if ($desc): ?>
                                                <span class="nbm-var-desc"><?= htmlspecialchars($desc) ?></span>
                                            <?php endif; ?>
                                        </th>
                                        <?php for ($i = 0; $i < $valCnt; $i++): ?>
                                            <?php
                                            $val = $row[$i] ?? null;
                                            $cellClass = $cellClasses[$i] ?? '';
                                            $classAttr = $cellClass ? ' class="' . $cellClass . '"' : '';
                                            ?>
                                            <td<?= $classAttr ?>>
                                                <?= ($val === null) ? '&mdash;' : intval($val) ?>
                                            </td>
                                        <?php endfor; ?>
                                    </tr>
                                <?php endforeach; ?>

                                <tr>
                                    <th class="sticky-col nbm-row-header nbm-row-flag nbm-row-flag-good">
                                        <span class="nbm-var-name">CAT</span>
                                        <span class="nbm-var-desc">Categoría FAA probable (VFR/MVFR/IFR/LIFR)</span>
                                    </th>
                                    <?php for ($i = 0; $i < count($times); $i++):
                                        $cat = $cats[$i] ?? 'VFR';
                                        $cls = cat_css_class($cat);
                                        ?>
                                        <td class="<?= $cls ?>">
                                            <span class="fw-bold small"><?= htmlspecialchars($cat) ?></span>
                                        </td>
                                    <?php endfor; ?>
                                </tr>

                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 small">
                            <span class="legend-box cat-vfr"></span> VFR
                            &nbsp;&nbsp;
                            <span class="legend-box cat-mvfr"></span> MVFR
                            &nbsp;&nbsp;
                            <span class="legend-box cat-ifr"></span> IFR
                            &nbsp;&nbsp;
                            <span class="legend-box cat-lifr"></span> LIFR
                        </div>

                        <?php if ($srcUrl): ?>
                            <div class="mt-2 small text-secondary">
                                Origen bruto NOMADS:
                                <code><?= htmlspecialchars($srcUrl) ?></code>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card border-0 bg-dark-subtle">
                    <div class="card-body small">
                        <h2 class="h6">Lectura operativa de la tarjeta NBM para MMTJ</h2>
                        <ul class="mb-1">
                            <li><strong>MVV</strong>: probabilidad de visibilidad MVFR (≤ 5 SM).</li>
                            <li><strong>IFV</strong>: probabilidad de visibilidad IFR (&lt; 3 SM).</li>
                            <li><strong>LIV</strong>: probabilidad de visibilidad LIFR (&lt; 1 SM).</li>
                            <li><strong>MVC</strong>: probabilidad de techo MVFR (≤ 3000 ft).</li>
                            <li><strong>IFC</strong>: probabilidad de techo IFR (&lt; 1000 ft).</li>
                            <li><strong>LIC</strong>: probabilidad de techo LIFR (&lt; 500 ft).</li>
                        </ul>
                        <p class="mb-1">
                            La fila <strong>CAT</strong> consolida visibilidad y techo con un umbral de 50&nbsp;% para
                            identificar la categoría de operación más probable por hora (VFR / MVFR / IFR / LIFR).
                        </p>
                        <p class="mb-0">
                            El resto de las variables (TMP, DPT, SKY, viento, precipitación, etc.) se presentan completas
                            para que la mesa de operación tenga el respaldo del NBM en un solo panel y lo cruce con METAR,
                            TAF y tus modelos FRI/SIGMA.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>
</body>
</html>
