-- DNL Station — SMS gönderim kaydı ve SMS OTP kodları (SQLite)

CREATE TABLE IF NOT EXISTS sms_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  to_phone TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL,
  error TEXT NOT NULL DEFAULT '',
  business_id INTEGER NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_sl_created ON sms_log(created_at);
CREATE INDEX IF NOT EXISTS ix_sl_business ON sms_log(business_id);

CREATE TABLE IF NOT EXISTS otp_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  phone TEXT NOT NULL,
  purpose TEXT NOT NULL,
  code_hash TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  expires_at TEXT NOT NULL,
  used_at TEXT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_otp_lookup ON otp_codes(phone, purpose, created_at);
