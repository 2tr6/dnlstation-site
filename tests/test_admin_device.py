"""Yönetici güvenilir cihaz: 'bu cihazda hatırla' ile kod adımı atlanır; sahte/çerezsiz cihaz atlatamaz."""
import sys
from lib import *
from test_admin_helpers import cli, totp


def d(r):
    try:
        return r.json().get('data') or {}
    except Exception:
        return {}


srv = Server()
try:
    srv.migrate()
    cli('1', 'yagiz@dnlstation.com.tr', 'Yağız Denli', 'Cok.Guclu-Yonetici-77')
    PW = 'Cok.Guclu-Yonetici-77'
    EMAIL = 'yagiz@dnlstation.com.tr'

    a = Client(); a.refresh_csrf()
    r = a.post('/admin/auth/login', {'email': EMAIL, 'password': PW})
    a.csrf = d(r)['csrf']; secret = d(r)['secret']
    r = a.post('/admin/auth/verify', {'code': totp(secret), 'trust': True})
    check('Kurulumda "bu cihazda hatırla" ile giriş yapılır', r.status_code == 200, r.text[:150])
    cookie = a.s.cookies.get('dnl_adm_dev')
    check('Güvenilir cihaz çerezi verilir', bool(cookie) and len(cookie) > 60, str(len(cookie or '')))

    print('== Çerezsiz cihaz: kod istenir ==')
    b = Client(); b.refresh_csrf()
    r = b.post('/admin/auth/login', {'email': EMAIL, 'password': PW})
    check('Çerezsiz cihazda kod adımı istenir', d(r).get('next') == 'totp', r.text[:150])

    print('== Güvenilir cihaz: kod atlanır ==')
    c = Client(); c.refresh_csrf()
    c.s.cookies.set('dnl_adm_dev', cookie, domain='127.0.0.1', path='/')
    r = c.post('/admin/auth/login', {'email': EMAIL, 'password': PW})
    check('Güvenilir cihazda kod istenmeden giriş', r.status_code == 200 and d(r).get('trusted') is True, r.text[:150])
    check('Kod atlanınca panel oturumu açılır', c.get('/admin/me').status_code == 200)

    print('== Sahte çerez reddedilir ==')
    e = Client(); e.refresh_csrf()
    e.s.cookies.set('dnl_adm_dev', cookie[:-4] + '0000', domain='127.0.0.1', path='/')
    r = e.post('/admin/auth/login', {'email': EMAIL, 'password': PW})
    check('Değiştirilmiş çerez kod adımını atlatamaz', d(r).get('next') == 'totp', r.text[:150])
    f = Client(); f.refresh_csrf()
    r = f.post('/admin/auth/login', {'email': EMAIL, 'password': 'yanlis-sifre-123'})
    g = Client(); g.refresh_csrf(); g.s.cookies.set('dnl_adm_dev', cookie, domain='127.0.0.1', path='/')
    r = g.post('/admin/auth/login', {'email': EMAIL, 'password': 'yanlis-sifre-123'})
    check('Güvenilir cihaz bile yanlış şifreyle giremez', r.status_code == 401, r.text[:150])
finally:
    srv.stop()
sys.exit(summary())
