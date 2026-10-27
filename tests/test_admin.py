"""Ana yönetici paneli testleri: CLI hesap, 2 adımlı giriş, yetki sınırları, işletme yönetimi, dondurma."""
import base64, hmac, hashlib, struct, subprocess, sqlite3, time, os
from lib import *
from test_admin_helpers import cli, totp

DB = os.path.join(TMP, 'test.db')


def sql(q, a=()):
    con = sqlite3.connect(DB)
    try:
        cur = con.execute(q, a); con.commit(); return cur.fetchall()
    finally:
        con.close()


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


def wait_next_step():
    time.sleep(30 - time.time() % 30 + 0.5)


srv = Server()
try:
    srv.migrate()
    print('== CLI yönetici aracı ==')
    rc, out = cli('1', 'yagiz@dnlstation.com.tr', 'Yağız Denli', 'kisa')
    check('Kısa yönetici şifresi reddedilir', rc != 0 and '12 karakter' in out, out)
    rc, out = cli('1', 'yagiz@dnlstation.com.tr', 'Yağız Denli', 'Cok.Guclu-Yonetici-77', 'farkli')
    check('Eşleşmeyen şifre reddedilir', rc != 0, out)
    rc, out = cli('1', 'yagiz@dnlstation.com.tr', 'Yağız Denli', 'Cok.Guclu-Yonetici-77')
    check('Yönetici oluşturulur', rc == 0 and 'oluşturuldu' in out, out)
    rc, out = cli('1', 'yagiz@dnlstation.com.tr', 'X Y', 'Cok.Guclu-Yonetici-77')
    check('Aynı e-postayla ikinci yönetici oluşturulamaz', rc != 0, out)
    r = requests.get(BASE + '/_app/yonetici.php')
    check('Yönetici aracı web’den çalışmaz', r.status_code in (403, 404), r.status_code)

    print('== Web üzerinden yönetici oluşturma/erişim yok ==')
    anon = Client()
    for path in ['/admin/me', '/admin/overview', '/admin/businesses', '/admin/payments', '/admin/audit']:
        check(f'Girişsiz {path} 401', anon.get(path).status_code == 401)
    owner = Client(); register(owner); owner.me()
    check('İşletme sahibi oturumu yönetici API’sine erişemez', owner.get('/admin/overview').status_code == 401)
    r = owner.post('/admin/businesses/1/trial', {'days': 30})
    check('İşletme sahibi kendi süresini uzatamaz', r.status_code == 401, r.text)

    print('== Giriş: şifre + Authenticator ==')
    a = Client(); a.refresh_csrf()
    r = a.post('/admin/auth/login', {'email': 'yagiz@dnlstation.com.tr', 'password': 'yanlis-sifre-123'})
    check('Yanlış şifre genel hata', r.status_code == 401 and err(r) == 'invalid_credentials', r.text)
    r = a.post('/admin/auth/login', {'email': 'yok@dnlstation.com.tr', 'password': 'Cok.Guclu-Yonetici-77'})
    check('Olmayan hesap aynı hata', r.status_code == 401 and err(r) == 'invalid_credentials', r.text)
    r = a.post('/admin/auth/login', {'email': 'yagiz@dnlstation.com.tr', 'password': 'Cok.Guclu-Yonetici-77'}, csrf=False)
    check('CSRF olmadan giriş reddedilir', r.status_code == 419, r.text)
    r = a.post('/admin/auth/login', {'email': 'yagiz@dnlstation.com.tr', 'password': 'Cok.Guclu-Yonetici-77'})
    check('İlk girişte Authenticator kurulumu istenir', r.status_code == 200 and d(r)['next'] == 'setup' and len(d(r)['secret']) == 32 and d(r)['uri'].startswith('otpauth://totp/'), r.text)
    secret = d(r)['secret']; a.csrf = d(r)['csrf']
    check('Sır veritabanında şifreli saklanır', sql('SELECT totp_secret FROM admins')[0][0].startswith('v1.') and secret not in sql('SELECT totp_secret FROM admins')[0][0])
    check('Kod girilmeden yönetici API kapalı', a.get('/admin/overview').status_code == 401)
    r = a.post('/admin/auth/verify', {'code': '000000'})
    check('Yanlış kod reddedilir', r.status_code == 401 and err(r) == 'bad_code', r.text)
    r = a.post('/admin/auth/verify', {'code': totp(secret)})
    check('Doğru kodla kurulum tamamlanır ve giriş yapılır', r.status_code == 200, r.text)
    a.csrf = d(r)['csrf']
    check('Yönetici /me', a.get('/admin/me').status_code == 200 and d(a.get('/admin/me'))['admin']['email'] == 'yagiz@dnlstation.com.tr')
    used = totp(secret)
    b2 = Client(); b2.refresh_csrf()
    r = b2.post('/admin/auth/login', {'email': 'yagiz@dnlstation.com.tr', 'password': 'Cok.Guclu-Yonetici-77'})
    check('İkinci girişte kurulum değil kod istenir (sır tekrar gösterilmez)', d(r).get('next') == 'totp' and 'secret' not in d(r), r.text)
    b2.csrf = d(r)['csrf']
    r = b2.post('/admin/auth/verify', {'code': used})
    check('Aynı kod ikinci kez kullanılamaz (tekrar saldırısı)', r.status_code == 401, r.text)
    wait_next_step()
    r = b2.post('/admin/auth/verify', {'code': totp(secret)})
    check('Yeni kodla ikinci cihaz girer', r.status_code == 200, r.text)
    b2.csrf = d(r)['csrf']
    r = b2.post('/admin/auth/logout', {})
    check('Yönetici çıkışı', r.status_code == 200 and b2.get('/admin/me').status_code == 401, r.text)

    print('== İşletme yönetimi ==')
    ov = d(a.get('/admin/overview'))
    check('Genel bakış', ov['counts']['all'] == 1 and ov['counts']['trial'] == 1, ov.get('counts'))
    bl = d(a.get('/admin/businesses?q=arena'))
    check('İşletme arama', bl['total'] == 1 and bl['items'][0]['owner_email'] == 'sahip@arena.test', bl)
    check('LIKE joker karakteri güvenli', d(a.get('/admin/businesses?q=%25'))['total'] == 0)
    bid = bl['items'][0]['id']
    det = d(a.get(f'/admin/businesses/{bid}'))
    check('İşletme detayı (şifre/oturum verisi yok)', det['business']['name'] == 'Arena Game Center' and 'password_hash' not in str(det) and 'display_token_hash' not in str(det), list(det.keys()))
    r = a.post(f'/admin/businesses/{bid}/trial', {'days': 10})
    check('Deneme süresi uzatılır', r.status_code == 200 and d(r)['business']['days_left'] >= 23, r.text)
    r = a.post(f'/admin/businesses/{bid}/trial', {'days': 500})
    check('Aşırı uzatma reddedilir', r.status_code == 422, r.text)
    r = a.post(f'/admin/businesses/{bid}/payments', {'plan': 'pro', 'period': 'yillik', 'amount': '2590', 'method': 'havale', 'note': 'EFT'})
    check('Ödeme kaydı süreyi 12 ay uzatır', r.status_code == 200 and d(r)['business']['state'] == 'active' and d(r)['business']['days_left'] >= 364, r.text)
    pay1 = d(r)['payments'][0]['id']
    r = a.post(f'/admin/businesses/{bid}/payments', {'plan': 'pro', 'period': 'aylik', 'amount': '259'})
    check('İkinci ödeme kalan süreye eklenir', d(r)['business']['days_left'] >= 393, d(r).get('business'))
    pay2 = d(r)['payments'][0]['id']
    r = a.post(f'/admin/payments/{pay1}/void', {})
    check('Eski ödeme kaydı iptal edilemez (yalnızca sonuncusu)', r.status_code == 409, r.text)
    r = a.post(f'/admin/payments/{pay2}/void', {})
    check('Son ödeme iptal edilir, süre geri alınır', r.status_code == 200 and 362 <= d(r)['business']['days_left'] <= 367, d(r).get('business'))
    r = a.post(f'/admin/businesses/{bid}/plan', {'plan': 'salon', 'period': 'yillik'})
    check('Paket değişir', d(r)['business']['plan'] == 'salon', r.text)
    r = a.post(f'/admin/businesses/{bid}/plan', {'plan': 'hack', 'period': 'yillik'})
    check('Geçersiz paket reddedilir', r.status_code == 422, r.text)
    r = a.post(f'/admin/businesses/{bid}/paid-until', {'date': '2027-13-45', 'reason': 'deneme'})
    check('Geçersiz tarih reddedilir', r.status_code == 422, r.text)
    r = a.post(f'/admin/businesses/{bid}/note', {'note': '<script>alert(1)</script> arandı'})
    check('Yönetici notu kaydedilir', r.status_code == 200)
    pays = d(a.get('/admin/payments'))
    check('Ödeme listesi ve aylık toplam', len(pays['items']) == 2 and pays['by_month'][0]['total_cents'] == 259000, pays.get('by_month'))

    print('== Kullanıcı desteği ==')
    uid = det['users'][0]['id']
    sql("UPDATE users SET locked_until = datetime('now', '+20 minutes') WHERE id = ?", (uid,))
    r = a.post(f'/admin/users/{uid}/action', {'action': 'unlock'})
    check('Hesap kilidi açılır', r.status_code == 200 and d(r)['users'][0]['locked'] is False, r.text)
    r = a.post(f'/admin/users/{uid}/action', {'action': 'deactivate'})
    check('İşletme sahibi pasife alınamaz', r.status_code == 409, r.text)
    r = a.post(f'/admin/users/{uid}/action', {'action': 'send_reset'})
    check('Şifre sıfırlama bağlantısı gönderilir', r.status_code == 200 and last_mail_link('sifre-yenile') is not None, r.text)
    r = a.post(f'/admin/users/{uid}/action', {'action': 'logout_all'})
    check('Tüm oturumlar kapatılır', r.status_code == 200 and owner.get('/me').status_code == 401, r.text)

    print('== Dondurma ==')
    owner = Client(); owner.refresh_csrf()
    owner.post('/auth/login', {'email': 'sahip@arena.test', 'password': 'Guclu.Sifre-2026'}); owner.me()
    check('Sahip yeniden giriş yapar', owner.get('/dashboard').status_code == 200)
    key = d(owner.post('/settings/display/rotate', {}))['key']
    r = a.post(f'/admin/businesses/{bid}/suspend', {'reason': 'Ödeme yapılmadı'})
    check('İşletme dondurulur', r.status_code == 200 and d(r)['business']['state'] == 'suspended', r.text)
    check('Dondurulan işletmenin oturumu düşer', owner.get('/dashboard').status_code == 401)
    o2 = Client(); o2.refresh_csrf()
    r = o2.post('/auth/login', {'email': 'sahip@arena.test', 'password': 'Guclu.Sifre-2026'})
    check('Dondurulan işletme giriş yapamaz', r.status_code == 403 and err(r) == 'suspended', r.text)
    check('Dondurulan işletmenin müşteri ekranı kapanır', requests.get(BASE + '/api/display', headers={'X-Display-Key': key}).status_code == 401)
    r = a.post(f'/admin/businesses/{bid}/unsuspend', {})
    check('Dondurma kaldırılır', d(r)['business']['state'] == 'active', r.text)
    r = o2.post('/auth/login', {'email': 'sahip@arena.test', 'password': 'Guclu.Sifre-2026'})
    check('Sonra giriş yapılabilir', r.status_code == 200, r.text)

    print('== Duyuru ==')
    r = a.put('/admin/announcement', {'text': 'Bakım: 03:00-03:30', 'level': 'warn', 'active': True})
    check('Duyuru yayınlanır', r.status_code == 200, r.text)
    o2.me()
    check('Duyuru işletme panelinde görünür', d(o2.get('/me'))['announcement']['text'] == 'Bakım: 03:00-03:30')
    a.put('/admin/announcement', {'text': '', 'level': 'info', 'active': False})
    check('Duyuru kaldırılır', d(o2.get('/me'))['announcement'] is None)

    print('== Kayıt, kilit, oturum süresi ==')
    au = d(a.get('/admin/audit'))['items']
    check('Yönetici işlemleri kayda geçer', {'business.payment', 'business.suspend', 'user.unlock', 'admin.2fa_enabled'} <= {x['action'] for x in au}, [x['action'] for x in au])
    c3 = Client(); c3.refresh_csrf()
    for _ in range(5):
        c3.post('/admin/auth/login', {'email': 'yagiz@dnlstation.com.tr', 'password': 'yanlis-sifre-123'})
    r = c3.post('/admin/auth/login', {'email': 'yagiz@dnlstation.com.tr', 'password': 'Cok.Guclu-Yonetici-77'})
    check('5 hatalı denemeden sonra hesap kilitlenir', r.status_code == 429, r.text)
    rc, out = cli('2', 'yagiz@dnlstation.com.tr', '', 'Yeni.Guclu-Yonetici-88')
    check('CLI ile şifre sıfırlanır ve kilit açılır', rc == 0, out)
    check('Şifre sıfırlanınca açık yönetici oturumu düşer', a.get('/admin/me').status_code == 401)
    rc, out = cli('3', 'yagiz@dnlstation.com.tr')
    check('CLI ile 2FA sıfırlanır', rc == 0, out)
    sql('DELETE FROM rate_limits')  # IP sınırı (10/15dk) testte bilinçli olarak aşıldı
    c4 = Client(); c4.refresh_csrf()
    r = c4.post('/admin/auth/login', {'email': 'yagiz@dnlstation.com.tr', 'password': 'Yeni.Guclu-Yonetici-88'})
    check('2FA sıfırlanınca yeni sır ile kurulum istenir', d(r).get('next') == 'setup' and d(r)['secret'] != secret, r.text)
finally:
    srv.stop()
sys.exit(summary())
