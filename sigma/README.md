# SIGMA-LV · Ops Risk (MMTJ)
Dashboard para riesgo operacional por vuelo ante niebla/visibilidad. Integra:
- `mmtj_fog` (FRI y SIGMA-LV)
- `mmtj_timetable` (arribos)
- Sábanas de dispersión por aerolínea
- Cálculo de % de riesgo por vuelo y exportes CSV/PDF

## Requisitos
- PHP 8.1+ con extensiones `mysqli` y `json`
- MySQL 8.x
- Permisos de lectura hacia: 
  - FRI JSON de `mmtj_fog` (por URL o ruta local)
  - API proxy de `mmtj_timetable` (por URL)

## Instalación
1) Cree la base de datos y ejecute `sql/schema.sql`.
2) Copie este directorio al servidor, por ejemplo `public_html/sigma_lv_ops_risk`.
3) Ajuste `api/config.php` con credenciales MySQL y rutas a FRI/Timetable.
4) Abra `/public/index.html` en el navegador.

## Endpoints principales
- `GET api/flights.php?from=YYYY-MM-DDTHH:MMZ&to=...` — llegadas normalizadas.
- `GET api/wx.php` — último METAR/TAF + FRI y estados de pista.
- `GET api/risk.php?from=...&to=...` — riesgo por vuelo con desglose.
- `POST api/alt.php` — crear/actualizar sábana por vuelo.
- `GET api/alt.php?action=summary` — conteo por alterno.
- `POST api/import_sabanas.php` — importar CSV de sábanas.
- `GET api/export_csv.php?from=...&to=...` — CSV de riesgo.
- `GET api/export_pdf.php?from=...&to=...` — PDF ejecutivo.
- `docs/DATA_PIPELINE.md` — parámetros y flujo completo (AviationStack, FlightSchedule, FR24, METAR/TAF, cron).

## Notas
- Los cálculos de riesgo se basan en FRI (0–100) + señal TAF + observación METAR + centinelas + demora crítica local.
- Unidades: visibilidad en SM, techo/VV en ft, viento en kt.
- Colorimetría: Verde ≤30, Ámbar 31–60, Rojo 61–80, Magenta >80.
- `sigma/cron/update_schedule.sh` ejecuta `api/update_schedule.php --days=2` para traer el día actual y el previo. Agregue la ruta a crontab (p.ej. cada 5 minutos) para mantener la tabla `flights` siempre poblada.
- La UI de operaciones ya no expone selects de fecha: siempre consulta `[hoy-1, hoy]` y refresca cada `TIMETABLE_REFRESH_MINUTES` sin interacción.
- Pruebas rápidas de deduplicación: `php sigma/tests/run.php`.
