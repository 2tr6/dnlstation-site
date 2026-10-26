-- DNL Station — ilk şema (SQLite; yerel test için, MySQL şemasıyla birebir aynı yapı)

CREATE TABLE IF NOT EXISTS rate_limits (
  k TEXT NOT NULL PRIMARY KEY,
  hits INTEGER NOT NULL DEFAULT 0,
  reset_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS businesses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  phone TEXT NOT NULL DEFAULT '',
  plan TEXT NOT NULL DEFAULT 'pro',
  billing_period TEXT NOT NULL DEFAULT 'yillik',
  trial_ends_at TEXT NOT NULL,
  paid_until TEXT NULL,
  timezone TEXT NOT NULL DEFAULT 'Europe/Istanbul',
  settings TEXT NULL,
  display_token_hash TEXT NULL UNIQUE,
  open_shift_id INTEGER NULL,
  setup_done INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  phone TEXT NOT NULL DEFAULT '',
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'staff',
  email_verified_at TEXT NULL,
  must_change_password INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  session_version INTEGER NOT NULL DEFAULT 1,
  failed_logins INTEGER NOT NULL DEFAULT 0,
  locked_until TEXT NULL,
  last_login_at TEXT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_users_business ON users(business_id);

CREATE TABLE IF NOT EXISTS auth_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  type TEXT NOT NULL,
  selector TEXT NOT NULL UNIQUE,
  token_hash TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  used_at TEXT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_tokens_user_type ON auth_tokens(user_id, type);

CREATE TABLE IF NOT EXISTS table_types (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'other',
  uses_controllers INTEGER NOT NULL DEFAULT 0,
  hour_price INTEGER NOT NULL DEFAULT 0,
  hour_price_4 INTEGER NULL,
  match_price INTEGER NULL,
  match_price_4 INTEGER NULL,
  night_enabled INTEGER NOT NULL DEFAULT 0,
  night_start TEXT NOT NULL DEFAULT '23:00',
  night_end TEXT NOT NULL DEFAULT '06:00',
  night_hour_price INTEGER NULL,
  night_hour_price_4 INTEGER NULL,
  min_minutes INTEGER NOT NULL DEFAULT 0,
  sort INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_tt_business ON table_types(business_id);

CREATE TABLE IF NOT EXISTS game_tables (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
  table_type_id INTEGER NOT NULL REFERENCES table_types(id),
  name TEXT NOT NULL,
  sort INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  active_session_id INTEGER NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_gt_business ON game_tables(business_id);

CREATE TABLE IF NOT EXISTS shifts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
  opened_by INTEGER NOT NULL,
  opened_at TEXT NOT NULL,
  opening_cash_cents INTEGER NOT NULL DEFAULT 0,
  closed_by INTEGER NULL,
  closed_at TEXT NULL,
  counted_cash_cents INTEGER NULL,
  expected_cash_cents INTEGER NULL,
  note TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS ix_shifts_business ON shifts(business_id, opened_at);

CREATE TABLE IF NOT EXISTS tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
  type TEXT NOT NULL DEFAULT 'session',
  status TEXT NOT NULL DEFAULT 'open',
  game_cents INTEGER NOT NULL DEFAULT 0,
  items_cents INTEGER NOT NULL DEFAULT 0,
  discount_cents INTEGER NOT NULL DEFAULT 0,
  total_cents INTEGER NOT NULL DEFAULT 0,
  discount_note TEXT NOT NULL DEFAULT '',
  shift_id INTEGER NULL,
  created_by INTEGER NOT NULL,
  closed_by INTEGER NULL,
  closed_at TEXT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_tickets_business ON tickets(business_id, status, closed_at);
CREATE INDEX IF NOT EXISTS ix_tickets_shift ON tickets(shift_id);

CREATE TABLE IF NOT EXISTS play_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
  table_id INTEGER NOT NULL,
  ticket_id INTEGER NOT NULL REFERENCES tickets(id),
  mode TEXT NOT NULL DEFAULT 'open',
  controllers INTEGER NULL,
  planned_minutes INTEGER NULL,
  match_count INTEGER NOT NULL DEFAULT 0,
  pricing TEXT NOT NULL,
  started_at TEXT NOT NULL,
  ended_at TEXT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  opened_by INTEGER NOT NULL,
  closed_by INTEGER NULL,
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_ps_business ON play_sessions(business_id, status, started_at);
CREATE INDEX IF NOT EXISTS ix_ps_table ON play_sessions(table_id);

CREATE TABLE IF NOT EXISTS session_segments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id INTEGER NOT NULL REFERENCES play_sessions(id) ON DELETE CASCADE,
  controllers INTEGER NULL,
  started_at TEXT NOT NULL,
  ended_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS ix_seg_session ON session_segments(session_id);

CREATE TABLE IF NOT EXISTS products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
  category TEXT NOT NULL DEFAULT 'Genel',
  name TEXT NOT NULL,
  price_cents INTEGER NOT NULL DEFAULT 0,
  track_stock INTEGER NOT NULL DEFAULT 0,
  stock_qty INTEGER NOT NULL DEFAULT 0,
  low_stock INTEGER NOT NULL DEFAULT 0,
  sort INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_products_business ON products(business_id);

CREATE TABLE IF NOT EXISTS ticket_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
  product_id INTEGER NULL,
  name TEXT NOT NULL,
  unit_cents INTEGER NOT NULL,
  qty INTEGER NOT NULL,
  total_cents INTEGER NOT NULL,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_items_ticket ON ticket_items(ticket_id);
CREATE INDEX IF NOT EXISTS ix_items_business ON ticket_items(business_id, created_at);

CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
  shift_id INTEGER NOT NULL,
  method TEXT NOT NULL,
  amount_cents INTEGER NOT NULL,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  voided_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS ix_pay_business ON payments(business_id, created_at);
CREATE INDEX IF NOT EXISTS ix_pay_shift ON payments(shift_id);
CREATE INDEX IF NOT EXISTS ix_pay_ticket ON payments(ticket_id);

CREATE TABLE IF NOT EXISTS stock_moves (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  qty_change INTEGER NOT NULL,
  reason TEXT NOT NULL,
  ref_id INTEGER NULL,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_sm_product ON stock_moves(product_id);

CREATE TABLE IF NOT EXISTS audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NULL,
  user_id INTEGER NULL,
  action TEXT NOT NULL,
  entity TEXT NOT NULL DEFAULT '',
  entity_id INTEGER NULL,
  meta TEXT NULL,
  ip_hash TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_audit_business ON audit_logs(business_id, created_at);
