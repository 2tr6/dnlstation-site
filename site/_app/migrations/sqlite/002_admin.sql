-- DNL Station — ana yönetici paneli (SQLite)

ALTER TABLE businesses ADD COLUMN suspended_at TEXT NULL;
ALTER TABLE businesses ADD COLUMN suspend_reason TEXT NOT NULL DEFAULT '';
ALTER TABLE businesses ADD COLUMN admin_note TEXT NULL;

CREATE TABLE IF NOT EXISTS admins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  totp_secret TEXT NULL,
  totp_enabled INTEGER NOT NULL DEFAULT 0,
  totp_last_step INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  session_version INTEGER NOT NULL DEFAULT 1,
  failed_logins INTEGER NOT NULL DEFAULT 0,
  locked_until TEXT NULL,
  last_login_at TEXT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS admin_payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  amount_cents INTEGER NOT NULL,
  plan TEXT NOT NULL,
  period TEXT NOT NULL,
  months INTEGER NOT NULL,
  method TEXT NOT NULL DEFAULT 'havale',
  note TEXT NOT NULL DEFAULT '',
  paid_until_before TEXT NULL,
  paid_until_after TEXT NOT NULL,
  admin_id INTEGER NOT NULL,
  voided_at TEXT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_ap_business ON admin_payments(business_id);
CREATE INDEX IF NOT EXISTS ix_ap_created ON admin_payments(created_at);

CREATE TABLE IF NOT EXISTS admin_audit (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id INTEGER NULL,
  action TEXT NOT NULL,
  entity TEXT NOT NULL DEFAULT '',
  entity_id INTEGER NULL,
  meta TEXT NULL,
  ip_hash TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_aa_created ON admin_audit(created_at);
CREATE INDEX IF NOT EXISTS ix_aa_entity ON admin_audit(entity, entity_id);

CREATE TABLE IF NOT EXISTS system_settings (
  k TEXT NOT NULL PRIMARY KEY,
  v TEXT NULL,
  updated_at TEXT NOT NULL
);
