<?php
function dewpoint_from_T_RH($T_C, $RH_pct){
  $a = 17.62; $b = 243.12; // °C
  if ($RH_pct <= 0) $RH_pct = 0.1;
  $gamma = ($a*$T_C)/($b+$T_C) + log($RH_pct/100.0);
  return ($b*$gamma)/($a-$gamma);
}
function metar_lowvis_hint($metar){
  $flag = false; $why = [];
  if (!$metar) return ['flag_low_vis'=>false,'why'=>[]];
  if (isset($metar['visibility_sm']) && $metar['visibility_sm'] !== null && $metar['visibility_sm'] <= 1.0){ $flag = true; $why[]='VIS<=1SM'; }
  if (!empty($metar['wx_string']) && preg_match('~\b(FG|BR|HZ)\b~', $metar['wx_string'])){ $flag = true; $why[]='WX='.$metar['wx_string']; }
  if (isset($metar['ceiling']) && $metar['ceiling'] !== null && $metar['ceiling'] <= 300){ $flag = true; $why[]='VV/CEIL<=300ft'; }
  if (!empty($metar['raw_text']) && preg_match('~\b(FG|BR|HZ)\b~', $metar['raw_text'])){ $flag = true; $why[]='RAW mentions BR/FG/HZ'; }
  return ['flag_low_vis'=>$flag,'why'=>$why];
}
function prob_fog($T_C,$RH_pct,$wind_kn,$hour_local,$metar_hint){
  $Td = dewpoint_from_T_RH($T_C, $RH_pct);
  $spread = $T_C - $Td;
  $p = 0.08;
  if ($spread <= 1.5) $p += 0.42;
  elseif ($spread <= 2.5) $p += 0.25;
  if ($wind_kn <= 3) $p += 0.25;
  elseif ($wind_kn <= 6) $p += 0.12;
  if ($hour_local >= 0 && $hour_local <= 9) $p += 0.18;
  if (!empty($metar_hint) && $metar_hint['flag_low_vis']) $p = max($p, 0.75);
  if ($p < 0) $p = 0; if ($p > 0.98) $p = 0.98;
  return round($p, 3);
}
