# Auditoría de Control Niebla (Timetable · SIGMA · MMTJ-Fog)

## Resumen ejecutivo
- Se detectaron credenciales de producción (tokens FR24, claves AviationStack y accesos MySQL) embebidos en el repositorio. La exposición compromete la integridad de los servicios conectados y viola las políticas de secreto compartido.
- Los flujos de consulta por fecha y hora no cumplen el comportamiento esperado: la interfaz genera marcas `Z` sin convertir correctamente desde la zona local y los endpoints SQL ignoran el rango horario solicitado, devolviendo todo el día.
- Las integraciones meteorológicas no siguen el orden requerido (CAPMA → NOAA) ni aplican control de cuota; además no se conservan “snapshots” METAR/TAF al momento del despegue.
- La UX de operación carece de estados de carga, filtros interactivos y vínculos con las tarjetas resumen, lo que aumenta la posibilidad de acciones duplicadas y confusiones en cabina.

## Hallazgos críticos
1. **Credenciales sensibles en código fuente**  
   - `AVS_ACCESS_KEY`, `FR24_API_TOKEN` y parámetros de la base de datos se definen con valores productivos por omisión en `timetable/api/config.php`, exponiendo el token operativo (`019a797b-…|whPw4X…`). 【F:Control Niebla/timetable/api/config.php†L3-L56】
   - `sigma/api/config.php` replica las credenciales de Hostinger y la clave AviationStack dentro del array de configuración. 【F:Control Niebla/sigma/api/config.php†L4-L39】
   > **Impacto:** riesgo inmediato de abuso de cuentas, revocación de llaves y compromiso de datos históricos.

2. **Rango horario inconsistente entre UI y back-end**  
   - El input `datetime-local` en Timetable solo concatena `Z` al valor local sin ajustar a UTC (`STATE.start = … + 'Z'`), generando desplazamientos cuando el navegador opera fuera de UTC. 【F:Control Niebla/timetable/public/app.js†L22-L31】
   - El endpoint de salidas reduce el filtro SQL a `DATE(std_utc) = ?`, por lo que ignora `hours` y devuelve todas las salidas del día aunque se solicite una ventana corta. 【F:Control Niebla/timetable/api/departures.php†L239-L248】
   - En modo llegadas (`fr24.php`) ocurre el mismo patrón al emplear solo la fecha para `DATE(sta_utc)` / `DATE(std_utc)` (no se incluye el fragmento horario). 【F:Control Niebla/timetable/api/fr24.php†L74-L113】
   > **Impacto:** las consultas pierden precisión operativa y pueden ocultar vuelos relevantes fuera del rango deseado.

3. **Integración meteorológica incompleta respecto a CAPMA**  
   - Solo el módulo MMTJ-Fog consulta CAPMA y después recurre a NOAA/ADDS; Timetable/SIGMA obtienen METAR vía `fetch_awc_multi()` y TAF con `adds_taf()` sin probar CAPMA ni priorizar estaciones mexicanas. 【F:Control Niebla/mmtj_fog/public/index.php†L10-L122】【F:Control Niebla/timetable/api/departures.php†L462-L501】
   > **Impacto:** los reportes pueden perder la fuente oficial nacional y depender de datos desactualizados o incompletos.

4. **Sin control de cuota ni backoff frente a AWC**  
   - `fetch_awc_multi()` hace llamadas directas a la API (hasta con listas largas) sin cache TTL, sin jitter ni límite de frecuencia, incumpliendo la recomendación de ≤100 peticiones por minuto. 【F:Control Niebla/mmtj_fog/lib/metar_multi.php†L47-L92】
   - `departures.php` invoca `adds_taf()` de forma secuencial para cada ICAO, multiplicando la carga si hay muchos destinos. 【F:Control Niebla/timetable/api/departures.php†L475-L488】
   > **Impacto:** riesgo de bloqueo por parte de AWC y tiempos de respuesta elevados.

5. **Persistencia parcial del estado de vuelos**  
   - Los cron jobs `update_schedule.php` y `update_departures.php` guardan únicamente los estados “scheduled/cancelled” al normalizar la respuesta de AviationStack, perdiendo eventos como desvíos, demoras o llegadas. 【F:Control Niebla/sigma/api/update_schedule.php†L92-L108】【F:Control Niebla/sigma/api/update_departures.php†L88-L115】
   > **Impacto:** SIGMA no puede reconstruir con precisión el historial ni alimentar reportes de desviaciones.

## Hallazgos de experiencia de usuario / operatividad
1. **Botones de actualización sin estado de carga**  
   - Timetable reemplaza la tabla por “Consultando…” pero no bloquea el botón ni muestra spinner; múltiples clics lanzan peticiones simultáneas. 【F:Control Niebla/timetable/public/app.js†L74-L141】
   - SIGMA (`app_ops.js`) reutiliza `window.refresh()` sin desactivar `#btnApply` o mostrar progreso. 【F:Control Niebla/sigma/public/assets/js/app_ops.js†L514-L556】

2. **Filtros y tarjetas resumen estáticas**  
   - Las tarjetas de estado en Timetable (`renderCounters`) se renderizan como `<div class="card">` sin manejador `click`, por lo que no activan filtros como indica el requerimiento. 【F:Control Niebla/timetable/public/app.js†L289-L296】
   - Los menús STS/COL en SIGMA se ejecutan, pero no persisten en `localStorage`; tras recargar se pierden las preferencias. 【F:Control Niebla/sigma/public/assets/js/app_ops.js†L180-L207】【F:Control Niebla/sigma/public/assets/js/app_ops.js†L520-L556】

3. **Reaplicación de columnas ineficiente**  
   - `applySavedCols()` se ejecuta dentro del bucle principal de filas en Timetable, releyendo `localStorage` para cada render y afectando el rendimiento. 【F:Control Niebla/timetable/public/app.js†L177-L191】

4. **Modal meteorológico mínimo y sin snapshot**  
   - El modo salidas abre un `alert()` con METAR/TAF actual en lugar de un modal persistente; además `departures.php` no guarda registros congelados al despegue. 【F:Control Niebla/timetable/public/app.js†L213-L221】【F:Control Niebla/timetable/api/departures.php†L439-L457】

5. **Sin diagnóstico integral de servicios externos**  
   - Existe `timetable/api/diag.php`, pero no verifica token FR24, ni la cuota AWC ni la conexión hacia CAPMA; tampoco hay equivalente centralizado para SIGMA. 【F:Control Niebla/timetable/api/diag.php†L1-L120】

## Recomendaciones prioritarias
1. Externalizar todas las credenciales a variables de entorno/secretos y revocar las llaves expuestas.
2. Ajustar la conversión de fechas en la UI (convertir del huso local a UTC antes de agregar `Z`) y aplicar filtros `BETWEEN` en SQL basados en `start`/`hours`.
3. Implementar un helper meteorológico compartido que consulte CAPMA en primer lugar y conserve snapshots (tabla o JSON) con sello horario.
4. Añadir caché, retrasos exponenciales y agrupación de destinos para las consultas AWC/ADDS.
5. Registrar estados detallados en los cron jobs y ampliar la tabla `flights` con campos de seguimiento (desvío, demora, alterno).
6. Mejorar la UX con spinners, bloqueo temporal de botones, tarjetas filtrables y modales completos para la información meteorológica.
7. Crear un script de health check que valide tokens FR24, cuota de AviationStack, conectividad CAPMA, base de datos y estado de cron.

