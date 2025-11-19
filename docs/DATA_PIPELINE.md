# Timetable & Weather Data Pipeline

## AviationStack (Base Schedule)
- Endpoint: `https://api.aviationstack.com/v1/timetable` for the current local day and `https://api.aviationstack.com/v1/flights` for the previous day.
- Required parameters:
  - `arr_iata=TIJ` and `arr_icao=MMTJ` (arrivals only).
  - `type=arrival` (timetable endpoint only).
  - `flight_status=scheduled,active,landed,diverted,cancelled` to persist every lifecycle state.
  - `date` (timetable) or `flight_date` (flights) expressed in local date `YYYY-MM-DD`.
  - `date_from` / `date_to` are sent in UTC (`YYYY-MM-DDTHH:MM:SSZ`) to keep the backend fully in UTC.
- Pagination: every request is paged with `limit=100` and an incrementing `offset` until AviationStack stops returning rows.
- Script: `php sigma/api/update_schedule.php --days=2` fetches `[today-1, today]` and writes into the `flights` table, keeping landed/cancelled/diverted rows even after completion.
- Automation: add the helper `sigma/cron/update_schedule.sh` to crontab (e.g. `*/5 * * * * /srv/sigma/cron/update_schedule.sh >> /var/log/sigma_timetable.log 2>&1`).

## FlightSchedule API (Optional)
- Configuration keys: `FLIGHTSCHEDULE_BASE`, `FLIGHTSCHEDULE_TOKEN`, `FLIGHTSCHEDULE_AIRLINE` (in `timetable/api/config.php` and `sigma/api/config.php`).
- Query parameters: `arr_iata`, `date_from`, `date_to`, `airline` (when provided).
- Responses are normalised to the AviationStack schema and merged without overwriting populated fields (status only replaces `scheduled`).

## Flightradar24 (Live/Enrichment)
- Live positions: `https://fr24api.flightradar24.com/api/live/flight-positions/full?airports=inbound:TIJ` with `Authorization: Bearer <token>` and `Accept-Version: v1`.
- Flight summary (historical/diversions): `https://fr24api.flightradar24.com/api/flight-summary/full` with `flight_datetime_from` / `flight_datetime_to` in UTC ISO8601.
- The merge pipeline deduplicates marketing codeshares, rewrites ICAO IDs to the operating carrier, and keeps taxi entries unique by callsign/STA/registration.

## Weather (METAR/TAF/Fog)
- Source endpoints live under `mmtj_fog`: METAR `.../data/metar.json`, TAF `.../data/taf.json`, and FRI `.../public/api/fri.json`.
- All timestamps are consumed in UTC; conversions to local happen only on the frontend clock toggle.
- TAF rendering splits the string by segments (`FM`, `BECMG`, `TEMPO`, `PROBxx`) and wraps each block so long forecasts stay readable on every breakpoint.

## Frontend Auto-Range & Refresh
- `TIMETABLE_REFRESH_MINUTES` (config) controls the worker cadence; default is 5 minutes.
- The UI always requests `today-1` through `today` automatically. Filters for From/To were removed to avoid manual mistakes.
- Clicking the clock badge toggles UTC/LCL without affecting backend requests. Every API call is still made in UTC.
