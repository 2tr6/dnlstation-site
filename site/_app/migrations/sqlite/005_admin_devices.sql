-- DNL Station — yönetici güvenilir cihazları (SQLite). Token'ın yalnızca hash'i tutulur.

CREATE TABLE IF NOT EXISTS admin_devices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id INTEGER NOT NULL,
  selector TEXT NOT NULL UNIQUE,
  validator_hash TEXT NOT NULL,
  created_at TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  last_used_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS ix_ad_admin ON admin_devices(admin_id);
