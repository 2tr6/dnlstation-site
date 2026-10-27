-- DNL Station — SMS gönderim kaydı ve SMS OTP kodları (MySQL)

CREATE TABLE IF NOT EXISTS sms_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  to_phone VARCHAR(20) NOT NULL,
  kind VARCHAR(40) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL,
  error VARCHAR(300) NOT NULL DEFAULT '',
  business_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY ix_sl_created (created_at),
  KEY ix_sl_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  purpose VARCHAR(30) NOT NULL,
  code_hash VARCHAR(64) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY ix_otp_lookup (phone, purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
