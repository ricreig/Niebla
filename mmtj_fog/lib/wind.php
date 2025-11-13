<?php
declare(strict_types=1);
function onshore_component(float $dir_deg, float $spd_kt, array $sector=[220,280]): float {
  [$a,$b] = $sector;
  $dir = fmod(($dir_deg+360.0),360.0);
  $in_sector = ($a <= $b) ? ($dir >= $a && $dir <= $b) : ($dir >= $a || $dir <= $b);
  if(!$in_sector) return 0.0;
  $span = ($b - $a + 360) % 360;
  $center = fmod(($a + $span/2.0), 360.0);
  $angle = abs($dir - $center); if($angle>180) $angle = 360-$angle;
  $proj = $spd_kt * cos(deg2rad($angle));
  return max(0.0, $proj);
}
