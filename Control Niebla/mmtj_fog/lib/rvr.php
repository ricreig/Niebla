<?php
declare(strict_types=1);
/** Extrae RVR por pista desde el METAR raw */
function parse_rvr(string $raw): array {
  $out = [];
  if (preg_match_all('/\bR(09|27)\/(\d{4})FT[UDN]?/', $raw, $m, PREG_SET_ORDER)) {
    foreach ($m as $mm) { $out[$mm[1]] = (int)$mm[2]; }
  }
  return $out;
}
