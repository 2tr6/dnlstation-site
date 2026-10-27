-- DNL Station — loglar, ömür boyu üyelik, veresiye, giderler, rezervasyonlar, maliyet (SQLite)

ALTER TABLE businesses ADD COLUMN lifetime INTEGER NOT NULL DEFAULT 0;
ALTER TABLE businesses ADD COLUMN max_tables_override INTEGER NULL;
ALTER TABLE businesses ADD COLUMN max_staff_override INTEGER NULL;
ALTER TABLE products ADD COLUMN cost_cents INTEGER NULL;
ALTER TABLE ticket_items ADD COLUMN cost_cents INTEGER NULL;
ALTER TABLE payments ADD COLUMN customer_id INTEGER NULL;

CREATE TABLE IF NOT EXISTS email_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  to_email TEXT NOT NULL,
  subject TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL,
  error TEXT NOT NULL DEFAULT '',
  business_id INTEGER NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_el_created ON email_log(created_at);
CREATE INDEX IF NOT EXISTS ix_el_business ON email_log(business_id);

CREATE TABLE IF NOT EXISTS security_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL,
  actor TEXT NOT NULL DEFAULT '',
  ip_hash TEXT NOT NULL DEFAULT '',
  user_agent TEXT NOT NULL DEFAULT '',
  business_id INTEGER NULL,
  meta TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_se_created ON security_events(created_at);
CREATE INDEX IF NOT EXISTS ix_se_type ON security_events(type, created_at);

CREATE TABLE IF NOT EXISTS customers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  phone TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  balance_cents INTEGER NOT NULL DEFAULT 0,
  credit_limit_cents INTEGER NULL,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_cu_business ON customers(business_id);

CREATE TABLE IF NOT EXISTS customer_moves (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
  customer_id INTEGER NOT NULL,
  amount_cents INTEGER NOT NULL,
  kind TEXT NOT NULL,
  method TEXT NULL,
  ticket_id INTEGER NULL,
  shift_id INTEGER NULL,
  note TEXT NOT NULL DEFAULT '',
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_cm_customer ON customer_moves(customer_id);
CREATE INDEX IF NOT EXISTS ix_cm_shift ON customer_moves(shift_id);

CREATE TABLE IF NOT EXISTS expenses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
  shift_id INTEGER NULL,
  category TEXT NOT NULL,
  amount_cents INTEGER NOT NULL,
  method TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  spent_on TEXT NOT NULL,
  created_by INTEGER NOT NULL,
  voided_at TEXT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_ex_business ON expenses(business_id, spent_on);
CREATE INDEX IF NOT EXISTS ix_ex_shift ON expenses(shift_id);

CREATE TABLE IF NOT EXISTS reservations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
  table_id INTEGER NULL,
  customer_name TEXT NOT NULL,
  phone TEXT NOT NULL DEFAULT '',
  starts_at TEXT NOT NULL,
  duration_minutes INTEGER NOT NULL DEFAULT 60,
  people INTEGER NULL,
  note TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'booked',
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_rs_business ON reservations(business_id, starts_at);
