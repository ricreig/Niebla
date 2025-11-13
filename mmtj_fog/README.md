# MMTJ Fog Model — Paquete de implementación

Fecha: 2025-11-04T18:26:05.737276Z

## Estructura

```
mmtj_fog/
  cron_ext.php
  lib/
    metar_multi.php
    openmeteo_ext.php
    fri.php
    rvr.php
  data/
    obs/         # se autocrea
    nwp/         # se autocrea
    sat/         # opcional
    marine/      # opcional
  public/
    index.php
    api/
      health.php
      fri.json   # lo publica el cron
    js/
      fri-card.js
```

## Despliegue

1. Copia todo el contenido en tu servidor como `mmtj_fog/` (no mezclar con versiones previas sin respaldo).
2. Asegura permisos de escritura para `mmtj_fog/data` y subcarpetas.
3. Ejecuta manualmente: `php mmtj_fog/cron_ext.php` y valida:
   - `data/obs/MMTJ.json`
   - `public/api/fri.json`
   - `public/api/health.php` devuelve JSON con `exists_*: true`
4. Abre `public/index.php` y verifica la tarjeta FRI en verde/ámbar/rojo.

## CRON

Cada 2 minutos:
```
*/2 * * * * /usr/bin/php /ruta/absoluta/mmtj_fog/cron_ext.php >/dev/null 2>&1
```

## Notas

- No se usa `.json` con PHP embebido. El `cron_ext.php` publica un **JSON estático** en `public/api/fri.json`.
- La tarjeta FRI detecta staleness >10 min y marca "Desactualizado".
- Open‑Meteo se mapea a llaves internas homogéneas.
- La API de AWC maneja `obsTime` como epoch/ISO con `parse_awc_time()` robusto.
