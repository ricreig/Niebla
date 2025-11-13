<?php
declare(strict_types=1);

if (!function_exists('capma_get_metar')) {
  function capma_get_metar(string $icao): string {
    $icao = strtoupper(trim($icao));
    if ($icao === '') return '';
    $url = "http://capma.mx/reportemetar/buscar_samx.php?id={$icao}";
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 2,
      CURLOPT_TIMEOUT => 4,
      CURLOPT_USERAGENT => 'MMTJ-FogApp/1.0',
    ]);
    $html = curl_exec($ch);
    if ($html === false) {
      curl_close($ch);
      return '';
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 500 || $html === '') return '';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    foreach ($xp->query('//p[@id="tam_let_5"]') as $p) {
      $metar = strtoupper(trim($p->nodeValue ?? ''));
      if ($metar === '') continue;
      if (!preg_match('/\\b' . preg_quote($icao, '/') . '\\b\\s+\\d{6}Z/', $metar)) continue;
      $clean = preg_replace('/=\\s*\\d{6}$/', '', $metar);
      $clean = preg_replace('/\\s+/', ' ', $clean ?? '');
      $clean = preg_replace('/SM\\s+/', 'SM ', $clean ?? '');
      return trim((string)$clean);
    }
    return '';
  }
}

if (!function_exists('capma_get_single_taf_from_url')) {
  function capma_get_single_taf_from_url(string $url, string $icao): string {
    $icao = strtoupper(trim($icao));
    if ($icao === '') return '';
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 2,
      CURLOPT_TIMEOUT => 4,
      CURLOPT_USERAGENT => 'MMTJ-FogApp/1.0',
    ]);
    $html = curl_exec($ch);
    if ($html === false) {
      curl_close($ch);
      return '';
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 500 || $html === '') return '';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    foreach ($dom->getElementsByTagName('pre') as $pre) {
      $tafText = trim($pre->nodeValue ?? '');
      if ($tafText === '') continue;
      if (!preg_match('/^TAF(?:\\s+(?:AMD|COR|RTD))?\\s+' . preg_quote($icao, '/') . '\\b/i', $tafText)) continue;
      $lines = explode("\n", $tafText);
      $clean = array_map(static fn($ln) => preg_replace('/\\s+/', ' ', ltrim((string)$ln)), $lines);
      return trim(implode("\n", $clean));
    }
    return '';
  }
}

if (!function_exists('capma_get_taf')) {
  function capma_get_taf(string $icao): string {
    return capma_get_single_taf_from_url('http://capma.mx/pronosticos/buscar_ftmx.php?id=ftmx53', $icao) ?: '';
  }
}

if (!function_exists('capma_parse_metar_metrics')) {
  function capma_parse_metar_metrics(string $raw): array {
    $raw = strtoupper(trim($raw));
    if ($raw === '') {
      return ['vis_sm' => null, 'ceil_ft' => null, 'vv_ft' => null];
    }
    $vis = null;
    if (preg_match('/\\bP6SM\\b/', $raw)) {
      $vis = 6.0;
    } elseif (preg_match('/\\b(\\d{1,2})\\s+(\\d)\\/(\\d)\\s*SM\\b/', $raw, $m)) {
      $vis = (int)$m[1] + ((int)$m[2] / max(1, (int)$m[3]));
    } elseif (preg_match('/\\b(\\d)\\/(\\d)\\s*SM\\b/', $raw, $m)) {
      $vis = (int)$m[1] / max(1, (int)$m[2]);
    } elseif (preg_match('/\\b(\\d{1,2})\\s*SM\\b/', $raw, $m)) {
      $vis = (float)$m[1];
    }

    $ceil = null;
    if (preg_match_all('/\\b(OVC|BKN)(\\d{3})\\b/', $raw, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $mm) {
        $ft = (int)$mm[2] * 100;
        if ($ceil === null || $ft < $ceil) {
          $ceil = $ft;
        }
      }
    }
    $vv = null;
    if (preg_match('/\\bVV(\\d{3})\\b/', $raw, $mm)) {
      $vv = (int)$mm[1] * 100;
      if ($ceil === null || $vv < $ceil) {
        $ceil = $vv;
      }
    }

    return [
      'vis_sm' => $vis,
      'ceil_ft' => $ceil,
      'vv_ft' => $vv,
    ];
  }
}
