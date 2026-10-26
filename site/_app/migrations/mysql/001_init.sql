-- DNL Station — ilk şema (MySQL / MariaDB)
-- Tüm zamanlar UTC, tüm para tutarları kuruş (tam sayı) olarak saklanır.

CREATE TABLE IF NOT EXISTS rate_limits (
  k CHAR(64) NOT NULL PRIMARY KEY,
  hits INT NOT NULL DEFAULT 0,
  reset_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS businesses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL DEFAULT '',
  plan VARCHAR(20) NOT NULL DEFAULT 'pro',
  billing_period VARCHAR(10) NOT NULL DEFAULT 'yillik',
  trial_ends_at DATETIME NOT NULL,
  paid_until DATETIME NULL,
  timezone VARCHAR(40) NOT NULL DEFAULT 'Europe/Istanbul',
  settings TEXT NULL,
  display_token_hash CHAR(64) NULL,
  open_shift_id INT UNSIGNED NULL,
  setup_done TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_display_token (display_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(20) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(10) NOT NULL DEFAULT 'staff',
  email_verified_at DATETIME NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_business (business_id),
  CONSTRAINT fk_users_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(20) NOT NULL,
  selector CHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_tokens_selector (selector),
  KEY ix_tokens_user_type (user_id, type),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS table_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'other',
  uses_controllers TINYINT(1) NOT NULL DEFAULT 0,
  hour_price INT NOT NULL DEFAULT 0,
  hour_price_4 INT NULL,
  match_price INT NULL,
  match_price_4 INT NULL,
  night_enabled TINYINT(1) NOT NULL DEFAULT 0,
  night_start CHAR(5) NOT NULL DEFAULT '23:00',
  night_end CHAR(5) NOT NULL DEFAULT '06:00',
  night_hour_price INT NULL,
  night_hour_price_4 INT NULL,
  min_minutes INT NOT NULL DEFAULT 0,
  sort INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  KEY ix_tt_business (business_id),
  CONSTRAINT fk_tt_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_tables (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  table_type_id INT UNSIGNED NOT NULL,
  name VARCHAR(40) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  active_session_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY ix_gt_business (business_id),
  CONSTRAINT fk_gt_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_gt_type FOREIGN KEY (table_type_id) REFERENCES table_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shifts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  opened_by INT UNSIGNED NOT NULL,
  opened_at DATETIME NOT NULL,
  opening_cash_cents INT NOT NULL DEFAULT 0,
  closed_by INT UNSIGNED NULL,
  closed_at DATETIME NULL,
  counted_cash_cents INT NULL,
  expected_cash_cents INT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  KEY ix_shifts_business (business_id, opened_at),
  CONSTRAINT fk_shifts_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  type VARCHAR(10) NOT NULL DEFAULT 'session',
  status VARCHAR(10) NOT NULL DEFAULT 'open',
  game_cents INT NOT NULL DEFAULT 0,
  items_cents INT NOT NULL DEFAULT 0,
  discount_cents INT NOT NULL DEFAULT 0,
  total_cents INT NOT NULL DEFAULT 0,
  discount_note VARCHAR(80) NOT NULL DEFAULT '',
  shift_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  closed_by INT UNSIGNED NULL,
  closed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY ix_tickets_business (business_id, status, closed_at),
  KEY ix_tickets_shift (shift_id),
  CONSTRAINT fk_tickets_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS play_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  table_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NOT NULL,
  mode VARCHAR(10) NOT NULL DEFAULT 'open',
  controllers TINYINT NULL,
  planned_minutes INT NULL,
  match_count INT NOT NULL DEFAULT 0,
  pricing TEXT NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'active',
  opened_by INT UNSIGNED NOT NULL,
  closed_by INT UNSIGNED NULL,
  note VARCHAR(200) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY ix_ps_business (business_id, status, started_at),
  KEY ix_ps_table (table_id),
  CONSTRAINT fk_ps_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_segments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  controllers TINYINT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  KEY ix_seg_session (session_id),
  CONSTRAINT fk_seg_session FOREIGN KEY (session_id) REFERENCES play_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'Genel',
  name VARCHAR(80) NOT NULL,
  price_cents INT NOT NULL DEFAULT 0,
  track_stock TINYINT(1) NOT NULL DEFAULT 0,
  stock_qty INT NOT NULL DEFAULT 0,
  low_stock INT NOT NULL DEFAULT 0,
  sort INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_products_business (business_id),
  CONSTRAINT fk_products_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  name VARCHAR(80) NOT NULL,
  unit_cents INT NOT NULL,
  qty INT NOT NULL,
  total_cents INT NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  KEY ix_items_ticket (ticket_id),
  KEY ix_items_business (business_id, created_at),
  CONSTRAINT fk_items_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NOT NULL,
  shift_id INT UNSIGNED NOT NULL,
  method VARCHAR(10) NOT NULL,
  amount_cents INT NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  voided_at DATETIME NULL,
  KEY ix_pay_business (business_id, created_at),
  KEY ix_pay_shift (shift_id),
  KEY ix_pay_ticket (ticket_id),
  CONSTRAINT fk_pay_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_moves (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  qty_change INT NOT NULL,
  reason VARCHAR(20) NOT NULL,
  ref_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  KEY ix_sm_product (product_id),
  CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  business_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  entity VARCHAR(30) NOT NULL DEFAULT '',
  entity_id INT UNSIGNED NULL,
  meta TEXT NULL,
  ip_hash CHAR(16) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY ix_audit_business (business_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
