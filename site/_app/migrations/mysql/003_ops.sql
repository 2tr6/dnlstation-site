-- DNL Station — loglar, ömür boyu üyelik, veresiye, giderler, rezervasyonlar, maliyet (MySQL)

ALTER TABLE businesses ADD COLUMN lifetime TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE businesses ADD COLUMN max_tables_override INT NULL;
ALTER TABLE businesses ADD COLUMN max_staff_override INT NULL;
ALTER TABLE products ADD COLUMN cost_cents INT NULL;
ALTER TABLE ticket_items ADD COLUMN cost_cents INT NULL;
ALTER TABLE payments ADD COLUMN customer_id INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS email_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  to_email VARCHAR(190) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  kind VARCHAR(40) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL,
  error VARCHAR(300) NOT NULL DEFAULT '',
  business_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY ix_el_created (created_at),
  KEY ix_el_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(40) NOT NULL,
  actor VARCHAR(190) NOT NULL DEFAULT '',
  ip_hash VARCHAR(64) NOT NULL DEFAULT '',
  user_agent VARCHAR(160) NOT NULL DEFAULT '',
  business_id INT UNSIGNED NULL,
  meta VARCHAR(300) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY ix_se_created (created_at),
  KEY ix_se_type (type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  phone VARCHAR(20) NOT NULL DEFAULT '',
  note VARCHAR(200) NOT NULL DEFAULT '',
  balance_cents INT NOT NULL DEFAULT 0,
  credit_limit_cents INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_cu_business (business_id),
  CONSTRAINT fk_cu_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_moves (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  amount_cents INT NOT NULL,
  kind VARCHAR(20) NOT NULL,
  method VARCHAR(10) NULL,
  ticket_id INT UNSIGNED NULL,
  shift_id INT UNSIGNED NULL,
  note VARCHAR(200) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  KEY ix_cm_customer (customer_id),
  KEY ix_cm_shift (shift_id),
  CONSTRAINT fk_cm_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  shift_id INT UNSIGNED NULL,
  category VARCHAR(40) NOT NULL,
  amount_cents INT NOT NULL,
  method VARCHAR(10) NOT NULL,
  note VARCHAR(200) NOT NULL DEFAULT '',
  spent_on DATE NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  voided_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY ix_ex_business (business_id, spent_on),
  KEY ix_ex_shift (shift_id),
  CONSTRAINT fk_ex_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  table_id INT UNSIGNED NULL,
  customer_name VARCHAR(80) NOT NULL,
  phone VARCHAR(20) NOT NULL DEFAULT '',
  starts_at DATETIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 60,
  people INT NULL,
  note VARCHAR(200) NOT NULL DEFAULT '',
  status VARCHAR(12) NOT NULL DEFAULT 'booked',
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_rs_business (business_id, starts_at),
  CONSTRAINT fk_rs_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
