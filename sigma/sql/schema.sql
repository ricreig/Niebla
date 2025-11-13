
-- SIGMA-LV · Ops Risk — esquema mínimo
CREATE TABLE IF NOT EXISTS airports(
  id INT AUTO_INCREMENT PRIMARY KEY,
  icao VARCHAR(4) UNIQUE,
  iata VARCHAR(3),
  nombre VARCHAR(80),
  aar_ref INT DEFAULT NULL,
  tz VARCHAR(40) DEFAULT 'America/Tijuana'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS flights(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  flight_number VARCHAR(10),
  callsign VARCHAR(10),
  airline VARCHAR(40),
  ac_reg VARCHAR(12),
  ac_type VARCHAR(12),
  dep_icao VARCHAR(4),
  dst_icao VARCHAR(4),
  std_utc DATETIME,
  sta_utc DATETIME,
  delay_min INT DEFAULT 0,
  status VARCHAR(20) DEFAULT 'scheduled',
  UNIQUE KEY uniq_fn_sta (flight_number, sta_utc)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alt_assign(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  flight_id BIGINT NOT NULL,
  alt_plan_icao VARCHAR(4),
  alt_conf_icao VARCHAR(4),
  aprobacion VARCHAR(40),
  extension VARCHAR(40),
  prognosis_sd ENUM('green','yellow','red') DEFAULT NULL,
  prognosis_voiti ENUM('green','yellow','red') DEFAULT NULL,
  prognosis_seneam ENUM('green','yellow','red') DEFAULT NULL,
  notas TEXT,
  assigned_utc TIMESTAMP NULL,
  updated_utc TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS metar_snap(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  icao VARCHAR(4) NOT NULL,
  ts_utc TIMESTAMP NOT NULL,
  raw TEXT,
  vis_sm DECIMAL(4,1),
  rvr_ft INT,
  vv_ft INT,
  cig_ft INT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS taf_snap(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  icao VARCHAR(4) NOT NULL,
  ts_utc TIMESTAMP NOT NULL,
  raw TEXT,
  parsed_json JSON
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fri_snap(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ts_utc TIMESTAMP NOT NULL,
  fri INT,
  estado VARCHAR(12),
  razones_json JSON
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS minima(
  id INT AUTO_INCREMENT PRIMARY KEY,
  pista VARCHAR(8),
  procedimiento VARCHAR(32),
  vis_sm_min DECIMAL(4,2),
  rvr_ft_min INT,
  cig_ft_min INT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS risk_score(
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  flight_id BIGINT NOT NULL,
  ts_calc TIMESTAMP NOT NULL,
  eta_eval TIMESTAMP NOT NULL,
  riesgo_pct TINYINT,
  bucket ENUM('verde','ambar','rojo','magenta'),
  features_json JSON,
  FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Vista de resumen de alternos
CREATE OR REPLACE VIEW alternos_resumen AS
SELECT COALESCE(alt_conf_icao, alt_plan_icao) AS alt_icao, COUNT(*) AS total
FROM alt_assign
GROUP BY COALESCE(alt_conf_icao, alt_plan_icao);
