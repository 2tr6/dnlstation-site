"""SMS altyapısı: OTP üretimi/doğrulaması, kanıt, deneme sınırı, yönetici SMS uçları (dosya sağlayıcısı ile)."""
import os, re, sys
from lib import *
from test_admin_helpers import cli, totp

OUTBOX = os.path.join(ROOT, 'site', '_app', 'storage', 'sms', 'outbox.txt')


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


def last_code():
    lines = open(OUTBOX, encoding='utf-8').read().strip().splitlines()
    to, text = lines[-1].split('\t', 1)
    return to, re.search(r'kodunuz: (\d{6})', text).group(1)


shutil.rmtree(os.path.dirname(OUTBOX), ignore_errors=True)
srv = Server()
try:
    srv.migrate()
    c = Client(); c.refresh_csrf()

    print('== OTP gönderimi ==')
    r = c.post('/auth/otp/send', {'phone': '123', 'purpose': 'signup'})
    check('Geçersiz telefon reddedilir', r.status_code == 422, r.text)
    r = c.post('/auth/otp/send', {'phone': '0537 762 07 08', 'purpose': 'hack'})
    check('Geçersiz amaç reddedilir', r.status_code == 422, r.text)
    r = c.post('/auth/otp/send', {'phone': '0537 762 07 08', 'purpose': 'signup'})
    check('OTP gönderilir', r.status_code == 200 and d(r).get('expires_in') == 300, r.text)
    to, code = last_code()
    check('SMS numarası 90 ile normalize edilir', to == '905377620708', to)
    check('Kod 6 haneli', len(code) == 6)

    print('== Doğrulama ==')
    r = c.post('/auth/otp/verify', {'phone': '0537 762 07 08', 'purpose': 'signup', 'code': '000000' if code != '000000' else '111111'})
    check('Yanlış kod reddedilir', r.status_code == 422 and err(r) == 'otp_invalid', r.text)
    r = c.post('/auth/otp/verify', {'phone': '0537 762 07 08', 'purpose': 'signup', 'code': code})
    proof = d(r).get('proof', '')
    check('Doğru kod kanıt üretir', r.status_code == 200 and '.' in proof, r.text)
    r = c.post('/auth/otp/verify', {'phone': '0537 762 07 08', 'purpose': 'signup', 'code': code})
    check('Kod tek kullanımlık', r.status_code == 422, r.text)
    c.post('/auth/otp/send', {'phone': '0537 762 07 08', 'purpose': 'signup'})
    _, code2 = last_code()
    for _ in range(5):
        c.post('/auth/otp/verify', {'phone': '0537 762 07 08', 'purpose': 'signup', 'code': '999998' if code2 != '999998' else '999997'})
    r = c.post('/auth/otp/verify', {'phone': '0537 762 07 08', 'purpose': 'signup', 'code': code2})
    check('5 hatalı denemeden sonra kod kilitlenir', r.status_code in (422, 429), r.text)
    r = c.post('/auth/otp/verify', {'phone': '0537 762 07 08', 'purpose': 'phone_verify', 'code': code2})
    check('Başka amaç için kod geçmez', r.status_code == 422, r.text)


    print('== Yönetici SMS uçları ==')
    cli('1', 'yagiz@dnlstation.com.tr', 'Yağız Denli', 'Cok.Guclu-Yonetici-77')
    a = Client(); a.refresh_csrf()
    rr = a.post('/admin/auth/login', {'email': 'yagiz@dnlstation.com.tr', 'password': 'Cok.Guclu-Yonetici-77'})
    a.csrf = d(rr)['csrf']; secret = d(rr)['secret']
    a.csrf = d(a.post('/admin/auth/verify', {'code': totp(secret)}))['csrf']
    r = a.post('/admin/system/test-sms', {'to': '0537 762 07 08'})
    check('Yönetici test SMS gönderir', r.status_code == 200, r.text)
    sm = d(a.get('/admin/sms'))
    check('SMS kayıtları listelenir (tür ve durum)', any(x['kind'] == 'test' and x['status'] == 'sent' for x in sm['items']) and sm['provider'] == 'file', sm)
    check('SMS kaydında kod/metin yok (yalnız tür)', all('doğrulama kodunuz' not in json.dumps(x) for x in sm['items']))
    st = d(a.get('/admin/system/status'))
    names = {x['name']: x for x in st['checks']}
    check('Sistem durumunda SMS kontrolü', 'SMS gönderimi' in names, list(names))
    check('Yönetici olmayan SMS listesini göremez', c.get('/admin/sms').status_code in (401, 403, 404))
    check('Test SMS yalnız yönetici', c.post('/admin/system/test-sms', {'to': '0537 762 07 08'}).status_code in (401, 403, 404))
finally:
    srv.stop()
    shutil.rmtree(os.path.dirname(OUTBOX), ignore_errors=True)
sys.exit(summary())
