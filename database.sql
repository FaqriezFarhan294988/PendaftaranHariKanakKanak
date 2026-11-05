CREATE DATABASE IF NOT EXISTS candidate_registration
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE candidate_registration;

CREATE TABLE IF NOT EXISTS teams (
  id VARCHAR(16) PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quotas (
  team_id VARCHAR(16) PRIMARY KEY,
  overall_quota INT UNSIGNED NOT NULL DEFAULT 0,
  quota_4_6 INT UNSIGNED NOT NULL DEFAULT 0,
  quota_7_12 INT UNSIGNED NOT NULL DEFAULT 0,
  quota_13_15 INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_quotas_team FOREIGN KEY (team_id) REFERENCES teams(id),
  CONSTRAINT chk_quotas_total CHECK (quota_4_6 + quota_7_12 + quota_13_15 <= overall_quota)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS registrations (
  id CHAR(36) PRIMARY KEY,
  team_id VARCHAR(16) NOT NULL,
  representative_name VARCHAR(120) NOT NULL,
  representative_phone VARCHAR(30) NOT NULL,
  representative_position VARCHAR(120) NOT NULL,
  representative_age TINYINT UNSIGNED NOT NULL,
  representative_attendance ENUM('HADIR', 'TIDAK HADIR') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_registrations_team FOREIGN KEY (team_id) REFERENCES teams(id),
  INDEX idx_registrations_team_date (team_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS candidates (
  id CHAR(36) PRIMARY KEY,
  registration_id CHAR(36) NOT NULL,
  team_id VARCHAR(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  ic_number CHAR(12) NOT NULL UNIQUE,
  age TINYINT UNSIGNED NOT NULL,
  category ENUM('CAT-1', 'CAT-2', 'CAT-3') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_candidates_registration FOREIGN KEY (registration_id) REFERENCES registrations(id),
  CONSTRAINT fk_candidates_team FOREIGN KEY (team_id) REFERENCES teams(id),
  INDEX idx_candidates_team_category (team_id, category),
  INDEX idx_candidates_name (name),
  CONSTRAINT chk_candidates_age CHECK (age BETWEEN 4 AND 15)
) ENGINE=InnoDB;

ALTER TABLE candidates MODIFY category VARCHAR(20) NOT NULL;
UPDATE candidates
SET category = CASE
  WHEN age BETWEEN 4 AND 6 THEN 'CAT-1'
  WHEN age BETWEEN 7 AND 12 THEN 'CAT-2'
  WHEN age BETWEEN 13 AND 15 THEN 'CAT-3'
END
WHERE category NOT IN ('CAT-1', 'CAT-2', 'CAT-3');
ALTER TABLE candidates MODIFY category ENUM('CAT-1', 'CAT-2', 'CAT-3') NOT NULL;

CREATE TABLE IF NOT EXISTS activity_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(40) NOT NULL,
  candidate_id CHAR(36) NULL,
  team_id VARCHAR(16) NULL,
  description TEXT NOT NULL,
  actor VARCHAR(20) NOT NULL DEFAULT 'ADMIN',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_created (created_at),
  CONSTRAINT fk_logs_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO teams (id, name) VALUES
  ('TEAM-01', 'MK 11 Bgd/Kem'),
  ('TEAM-02', 'Rej 502 AW'),
  ('TEAM-03', 'HAT MIZAN'),
  ('TEAM-04', '11 SSB'),
  ('TEAM-05', '4 RAMD'),
  ('TEAM-06', '8 RAMD'),
  ('TEAM-07', '25 RAMD'),
  ('TEAM-08', 'TNB'),
  ('TEAM-09', '4 DIV'),
  ('TEAM-10', '2 BGD'),
  ('TEAM-11', '12 BGD'),
  ('TEAM-12', 'TAHFIZ/ANAK YATIM'),
  ('TEAM-13', 'PDRM'),
  ('TEAM-14', 'JKR'),
  ('TEAM-15', 'YAPEIM'),
  ('TEAM-16', 'LTAT');

INSERT IGNORE INTO quotas (team_id, overall_quota, quota_4_6, quota_7_12, quota_13_15)
SELECT id, 0, 0, 0, 0 FROM teams;

CREATE TABLE IF NOT EXISTS admin_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO admin_settings (setting_key, setting_value) VALUES
  ('event_name', 'Pendaftaran Calon'),
  ('system_status', 'OPEN');
