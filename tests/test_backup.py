"""Veritabanı yedekleri: yönetici panelinden alma, listeleme, silme; ad doğrulama; yetki sınırı."""
import os, sys, shutil, gzip
from lib import *
from test_admin_helpers import cli, totp

BACKUP_DIR = os.path.join(ROOT, 'dnlstation-backups')


def d(r):
    try:
        return r.json().get('data') or {}
    except Exception:
        return {}


def err(r):
    try:
        return r.json()['error']['code']
    except Exception:
        return None


shutil.rmtree(BACKUP_DIR, ignore_errors=True)
srv = Server()
try:
    srv.migrate()
    o = Client(); register(o); o.me()
    check('Girişsiz yedek listesi kapalı', Client().get('/admin/backups').status_code == 401)
    check('İşletme sahibi yedek alamaz', o.post('/admin/backups/run', {}).status_code == 401)

    cli('1', 'yagiz@dnlstation.com.tr', 'Yağız Denli', 'Cok.Guclu-Yonetici-77')
    a = Client(); a.refresh_csrf()
    r = a.post('/admin/auth/login', {'email': 'yagiz@dnlstation.com.tr', 'password': 'Cok.Guclu-Yonetici-77'})
    a.csrf = d(r)['csrf']; secret = d(r)['secret']
    a.csrf = d(a.post('/admin/auth/verify', {'code': totp(secret)}))['csrf']

    print('== Yedek alma ==')
    r = a.get('/admin/backups')
    check('Başlangıçta yedek listesi boş', r.status_code == 200 and d(r)['items'] == [] and d(r)['keep'] == 14, r.text[:150])
    a.refresh_csrf()
    r = a.post('/admin/backups/run', {})
    check('Panelden yedek alınır', r.status_code == 200 and len(d(r)['items']) == 1, r.text[:200])
    name = d(r)['items'][0]['name'] if d(r).get('items') else ''
    path = os.path.join(BACKUP_DIR, name)
    check('Yedek dosyası klasörde', name.startswith('dnl-') and os.path.isfile(path))
    check('Yedek izni 0600 (yalnız sahip okur)', os.path.isfile(path) and (os.stat(path).st_mode & 0o777) == 0o600)
    with gzip.open(path, 'rt', encoding='utf-8') as f:
        body = f.read()
    check('Yedek SQL içerir (tablo ve ekleme)', 'CREATE TABLE' in body.upper() and 'INSERT INTO' in body, body[:120])

    print('== Silme güvenliği ==')
    a.refresh_csrf()
    check('Yol geçişi (../) ile silinemez', a.post('/admin/backups/delete', {'name': '../test.db'}).status_code == 422)
    check('Geçersiz biçim silinemez', a.post('/admin/backups/delete', {'name': 'rastgele-dosya.txt'}).status_code == 422)
    check('Var olmayan yedek 404', a.post('/admin/backups/delete', {'name': 'dnl-20000101-000000.sql.gz'}).status_code == 404)
    check('Yedek dosyası silinmedi (geçersiz istekler)', os.path.isfile(path))
    r = a.post('/admin/backups/delete', {'name': name})
    check('Yedek panelden silinir', r.status_code == 200 and not os.path.exists(path) and d(r)['items'] == [], r.text[:150])

    print('== Budama: en yeni 14 tutulur ==')
    os.makedirs(BACKUP_DIR, exist_ok=True)
    for i in range(16):
        open(os.path.join(BACKUP_DIR, f'dnl-2026{i + 1:02d}01-000000.sql.gz'), 'wb').close()
    a.refresh_csrf()
    a.post('/admin/backups/run', {})
    left = [f for f in os.listdir(BACKUP_DIR) if f.endswith('.sql.gz')]
    check('Yeni yedekle birlikte en fazla 14 yedek kalır', len(left) == 14, str(len(left)))
finally:
    srv.stop()
    shutil.rmtree(BACKUP_DIR, ignore_errors=True)
sys.exit(summary())
