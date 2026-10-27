-- DNL Station — ana yönetici paneli (MySQL)

ALTER TABLE businesses ADD COLUMN suspended_at DATETIME NULL;
ALTER TABLE businesses ADD COLUMN suspend_reason VARCHAR(200) NOT NULL DEFAULT '';
ALTER TABLE businesses ADD COLUMN admin_note TEXT NULL;

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  totp_secret VARCHAR(255) NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  totp_last_step BIGINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  amount_cents INT NOT NULL,
  plan VARCHAR(20) NOT NULL,
  period VARCHAR(10) NOT NULL,
  months INT NOT NULL,
  method VARCHAR(20) NOT NULL DEFAULT 'havale',
  note VARCHAR(200) NOT NULL DEFAULT '',
  paid_until_before DATETIME NULL,
  paid_until_after DATETIME NOT NULL,
  admin_id INT UNSIGNED NOT NULL,
  voided_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY ix_ap_business (business_id),
  KEY ix_ap_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_audit (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(30) NOT NULL DEFAULT '',
  entity_id INT UNSIGNED NULL,
  meta TEXT NULL,
  ip_hash VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY ix_aa_created (created_at),
  KEY ix_aa_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
