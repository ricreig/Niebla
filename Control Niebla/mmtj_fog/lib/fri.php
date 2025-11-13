<?php
declare(strict_types=1);

/* ===== Utilidades internas ===== */
function _ts(mixed $v): int {
  if (is_int($v)) return $v;
  if (is_float($v)) return (int)round($v);
  if (is_string($v) && is_numeric($v)) return (int)round((float)$v);
  if (is_string($v)) { $t = strtotime($v); return $t!==false ? $t : time(); }
  return time();
}
function _fresh(?array $arr, string $key, int $max_age_s): bool {
  if (!$arr || !isset($arr[$key])) return false;
  $t = _ts($arr[$key]); if ($t<=0) return false;
  return (time() - $t) <= $max_age_s;
}
function cosd(float $deg): float { return cos(deg2rad($deg)); }
function sind(float $deg): float { return sin(deg2rad($deg)); }
function angdiff(float $a, float $b): float {
  $d = fmod(($a - $b + 540.0), 360.0) - 180.0; return abs($d);
}
/** Componente onshore aproximada hacia 090° (del mar al valle en TJ) */
function onshore_component(?float $dir_deg, ?float $spd_kt): float {
  if ($dir_deg===null || $spd_kt===null) return 0.0;
  $diff = angdiff($dir_deg, 270.0); // viento sopla DESDE dir_deg; onshore ~ 270→90
  $comp = $spd_kt * max(0.0, cosd($diff)); // solo proyección favorable
  return max(0.0, $comp);
}

/* ===== Núcleo del índice FRI v0.2 ===== */
function fri_score(array $obs_mmtj, array $nwp0, array $sentinels, array $sat=[], array $marine=[]): array {
  // Lecturas base
  $T    = isset($nwp0['T'])  ? (float)$nwp0['T']  : null;
  $Td   = isset($nwp0['Td']) ? (float)$nwp0['Td'] : null;
  $dTTd = ($T!==null && $Td!==null) ? ($T - $Td) : null;

  $wdir = isset($nwp0['wdir']) ? (float)$nwp0['wdir'] : null;
  $wspd = isset($nwp0['wspd']) ? (float)$nwp0['wspd'] : null;

  $lcc = isset($nwp0['low_cloud_cover']) ? (float)$nwp0['low_cloud_cover'] : null; // 0–100 (%)
  $blh = isset($nwp0['boundary_layer_height']) ? (float)$nwp0['boundary_layer_height'] : null; // m

  // Frescura de insumos opcionales
  $sat_fresh    = _fresh($sat, 'ts',    3*3600); // 3 h
  $marine_fresh = _fresh($marine, 'ts', 2*3600); // 2 h

  // Señales sentinela (último ciclo)
  $up_count = 0;
  foreach ($sentinels as $s) {
    $vis = isset($s['vis_sm']) ? (float)$s['vis_sm'] : 10.0;
    $wx  = isset($s['wx']) ? (string)$s['wx'] : '';
    if ($vis <= 3.0 || preg_match('/\b(FG|BR)\b/', $wx)) $up_count++;
  }

  // ===== Componentes normalizados 0..1 =====
  // Radiativo R
  $r1 = ($dTTd===null) ? 0.0 : ( $dTTd <= 1.0 ? 1.0 : ($dTTd <= 2.0 ? 0.6 : ($dTTd <= 3.0 ? 0.3 : 0.0)) );
  $r2 = ($wspd===null) ? 0.0 : ( $wspd <= 3.0 ? 1.0 : ($wspd <= 6.0 ? 0.5 : 0.0) );
  $r3 = ($lcc===null)  ? 0.0 : ( $lcc <= 20.0 ? 1.0 : ($lcc <= 40.0 ? 0.5 : 0.0) );
  $r4 = ($blh===null)  ? 0.0 : ( $blh <= 250.0 ? 1.0 : ($blh <= 400.0 ? 0.5 : 0.0) );
  $R  = max(0.0, min(1.0, 0.35*$r1 + 0.35*$r2 + 0.15*$r3 + 0.15*$r4));

  // Proximidad/Persistencia P
  $onshore = onshore_component($wdir, $wspd);
  $p1 = min(1.0, $up_count / 2.0);                  // ≥2 sentinelas → 1.0
  $p2 = $onshore >= 4.0 ? 1.0 : ($onshore >= 2.0 ? 0.5 : 0.0);
  $P  = max(0.0, min(1.0, 0.7*$p1 + 0.3*$p2));

  // Capa baja/estrato marino C
  $c1 = ($lcc===null) ? 0.0 : ( $lcc >= 70.0 ? 1.0 : ($lcc >= 40.0 ? 0.5 : 0.0) );
  $c2 = ($blh===null) ? 0.0 : ( $blh <= 300.0 ? 1.0 : ($blh <= 500.0 ? 0.4 : 0.0) );
  $C  = max(0.0, min(1.0, 0.6*$c1 + 0.4*$c2));

  // Tendencia local L (usa vis_trend si lo provee obs_mmtj)
  $trend = isset($obs_mmtj['vis_trend']) ? (string)$obs_mmtj['vis_trend'] : '';
  $L = $trend === 'down' ? 1.0 : ($trend === 'up' ? 0.0 : 0.2);

  // FRI combinado
  $FRI = 100.0 * max(0.0, min(1.0, 0.5*$R + 0.3*$P + 0.15*$C + 0.05*$L));

  $estado = ($FRI < 30.0) ? 'VERDE' : ( ($FRI < 60.0) ? 'AMBAR' : ( ($FRI < 80.0) ? 'ROJO' : 'ROJO_CRITICO' ) );

  // Razones operativas
  $razones = [];
  if ($dTTd!==null && $dTTd<=2.0) $razones[] = 'Spread T−Td ≤2°C';
  if ($wspd!==null && $wspd<=3.0) $razones[] = 'Viento ≤3 kt';
  if ($lcc!==null && $lcc>=70.0)  $razones[] = 'Nube baja ≥70%';
  if ($blh!==null && $blh<=300.0) $razones[] = 'BLH ≤300 m';
  if ($onshore>=2.0)              $razones[] = 'Componente onshore ≥2 kt';
  if ($up_count>0)                $razones[] = 'Sentinelas con BR/FG o vis ≤3 SM';

  // Señales mar/sat (solo si frescas)
  if (_fresh($marine, 'ts', 2*3600) && isset($marine['sst'],$marine['tair'])) {
    $dSST = abs((float)$marine['sst'] - (float)$marine['tair']);
    if ($dSST <= 2.0) $razones[] = 'Δ(SST−Tair) ≤2°C';
  }
  if (_fresh($sat, 'ts', 3*3600) && isset($sat['fls_index'])) {
    $fls = (float)$sat['fls_index'];
    if ($fls >= 0.6) $razones[] = 'GOES FLS alto';
  }

  return ['fri'=>round($FRI), 'estado'=>$estado, 'razones'=>$razones];
}
