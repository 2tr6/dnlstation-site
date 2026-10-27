-- DNL Station — yönetici güvenilir cihazları (MySQL). Token'ın yalnızca hash'i tutulur.

CREATE TABLE IF NOT EXISTS admin_devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NOT NULL,
  selector CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  UNIQUE KEY ux_ad_selector (selector),
  KEY ix_ad_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
