"""Üye ol: 'sinirsiz' seçimi ücretsiz denemede Salon+ olarak açılır; ömür boyu ödeme onayı olmadan verilmez."""
import sqlite3, os, sys
from lib import *

DB = os.path.join(TMP, 'test.db')
srv = Server()
try:
    srv.migrate()
    c = Client()
    r = c.post('/auth/register', {'name': 'Ali Veli', 'business': 'Sınır Salon', 'phone': '05321112233', 'email': 'sinirsiz@salon.test',
                                  'password': 'Guclu.Sifre-2026', 'password_confirm': 'Guclu.Sifre-2026', 'plan': 'sinirsiz', 'period': 'yillik', 'accept': True, 'types': ['playstation']})
    check('Sınırsız seçimiyle kayıt açılır', r.status_code == 200, r.text[:200])
    con = sqlite3.connect(DB)
    plan, life, state = con.execute("SELECT plan, lifetime, trial_ends_at FROM businesses WHERE name = 'Sınır Salon'").fetchone() or (None, None, None)
    check('Kayıtta plan Salon+ olur (sinirsiz plan adı veritabanına girmez)', plan == 'salon', plan)
    check('Kayıtta ömür boyu verilmez', life == 0, life)
    check('Kayıtta ücretsiz deneme başlar', state is not None)
    c.refresh_csrf()
    rr = c.post('/auth/register', {'name': 'Ali', 'business': 'Bozuk', 'phone': '05321112244', 'email': 'bozuk@salon.test',
                                   'password': 'Guclu.Sifre-2026', 'password_confirm': 'Guclu.Sifre-2026', 'plan': 'lifetime-hack', 'period': 'yillik', 'accept': True, 'types': ['playstation']})
    check('Tanımsız paket kaydı reddedilir', rr.status_code == 422, rr.text[:200])
    con.close()
finally:
    srv.stop()
sys.exit(summary())
