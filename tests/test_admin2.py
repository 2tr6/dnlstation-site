"""Yönetici ek modülleri: ömür boyu üyelik, özel sınır, bilgi/e-posta değişimi, sahiplik devri, e-posta, dışa aktarma,
silme, bakım modu, sistem durumu, e-posta/güvenlik/işlem kayıtları, uyarılar."""
import sqlite3, os, time, json
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


srv = Server()
try:
    srv.migrate()
    o = Client(); register(o); o.me()
    cli('1', 'yagiz@dnlstation.com.tr', 'Yağız Denli', 'Cok.Guclu-Yonetici-77')
    a = Client(); a.refresh_csrf()
    r = a.post('/admin/auth/login', {'email': 'yagiz@dnlstation.com.tr', 'password': 'Cok.Guclu-Yonetici-77'})
    a.csrf = d(r)['csrf']; secret = d(r)['secret']
    a.csrf = d(a.post('/admin/auth/verify', {'code': totp(secret)}))['csrf']
    bid = d(a.get('/admin/businesses'))['items'][0]['id']

    print('== Ömür boyu ve sınırlar ==')
    sql("UPDATE businesses SET trial_ends_at='2020-01-01 00:00:00' WHERE id=?", (bid,))
    check('Süresi biten işletme yazamaz', o.post('/products', {'name': 'Su', 'price': '5'}).status_code == 402)
    r = a.post(f'/admin/businesses/{bid}/lifetime', {'on': True, 'reason': 'Kurucu müşteri'})
    check('Ömür boyu üyelik açılır', r.status_code == 200 and d(r)['business']['lifetime'] is True and d(r)['business']['state'] == 'lifetime', r.text)
    check('Ömür boyu işletme yazabilir', o.post('/products', {'name': 'Su', 'price': '5'}).status_code == 200)
    check('Panelde süre: ömür boyu', d(o.get('/me'))['subscription']['state'] == 'lifetime')
    check('Ömür boyu filtresi', d(a.get('/admin/businesses?state=lifetime'))['total'] == 1 and d(a.get('/admin/businesses?state=expired'))['total'] == 0)
    sql("UPDATE businesses SET plan='baslangic' WHERE id=?", (bid,))
    r = a.post(f'/admin/businesses/{bid}/limits', {'max_tables': 2, 'max_staff': 1})
    check('Özel sınır tanımlanır', d(r)['business']['max_tables'] == 2, r.text)
    o.post('/catalog/types', {'name': 'PS', 'kind': 'playstation', 'uses_controllers': True, 'hour_price': '100'})
    tid = d(o.get('/catalog'))['types'][0]['id']
    r = o.post('/catalog/tables', {'table_type_id': tid, 'prefix': 'PS', 'count': 3})
    check('Özel masa sınırı uygulanır', r.status_code == 422 and err(r) == 'plan_limit', r.text)
    a.post(f'/admin/businesses/{bid}/limits', {'max_tables': None, 'max_staff': None})
    a.post(f'/admin/businesses/{bid}/lifetime', {'on': False, 'reason': 'deneme'})
    check('Sınır kaldırılınca paket sınırına döner', d(a.get(f'/admin/businesses/{bid}'))['business']['max_tables'] == 8)
    before = sql("SELECT COUNT(*) FROM admin_payments WHERE business_id=?", (bid,))[0][0]
    r = a.post(f'/admin/businesses/{bid}/lifetime', {'on': True, 'reason': 'Havale satışı', 'amount': '9990', 'method': 'havale'})
    last = sql("SELECT amount_cents, period, method FROM admin_payments WHERE business_id=? ORDER BY id DESC LIMIT 1", (bid,))
    check('Ömür boyu satışı gelir kaydına yazılır (9990 TL = 999000 kuruş)', r.status_code == 200 and sql("SELECT COUNT(*) FROM admin_payments WHERE business_id=?", (bid,))[0][0] == before + 1 and last[0] == (999000, 'omur', 'havale'), r.text[:200] + ' ' + str(last))
    r = a.post(f'/admin/businesses/{bid}/lifetime', {'on': False, 'reason': 'deneme2'})
    a.post(f'/admin/businesses/{bid}/lifetime', {'on': True, 'reason': 'tutarsız', 'amount': 'abc'})
    check('Geçersiz tutar kayıt açmaz (yetki de değişmez)', d(a.get(f'/admin/businesses/{bid}'))['business']['lifetime'] is False and sql("SELECT COUNT(*) FROM admin_payments WHERE business_id=?", (bid,))[0][0] == before + 1)
    a.post(f'/admin/businesses/{bid}/lifetime', {'on': False, 'reason': 'deneme3'})
    sql("UPDATE businesses SET trial_ends_at='2099-01-01 00:00:00' WHERE id=?", (bid,))

    print('== Bilgi, e-posta, sahiplik ==')
    r = a.put(f'/admin/businesses/{bid}', {'name': 'Arena Yeni', 'phone': '05551112233'})
    check('İşletme bilgisi düzenlenir', d(r)['business']['name'] == 'Arena Yeni', r.text)
    st = d(o.post('/staff', {'name': 'Müdür Ali', 'email': 'ali@arena.test', 'role': 'manager'}))
    ali = st['user_id']
    owner_id = [u for u in d(a.get(f'/admin/businesses/{bid}'))['users'] if u['role'] == 'owner'][0]['id']
    r = a.post(f'/admin/users/{ali}/email', {'email': 'sahip@arena.test'})
    check('Kullanılan e-postaya değiştirilemez', r.status_code == 422, r.text)
    r = a.post(f'/admin/users/{ali}/email', {'email': 'ali.yeni@arena.test'})
    check('Kullanıcı e-postası değişir, doğrulama gider', r.status_code == 200 and any(u['email'] == 'ali.yeni@arena.test' and not u['verified'] for u in d(r)['users']), r.text)
    r = a.post(f'/admin/users/{ali}/make-owner', {})
    roles = {u['id']: u['role'] for u in d(r)['users']}
    check('Sahiplik devredilir, eski sahip müdür olur', roles.get(ali) == 'owner' and roles.get(owner_id) == 'manager', roles)
    check('Eski sahibin oturumu düşer', o.get('/me').status_code == 401)
    a.post(f'/admin/users/{owner_id}/make-owner', {})

    print('== E-posta, dışa aktarım ==')
    r = a.post(f'/admin/businesses/{bid}/email', {'subject': 'Hoş geldiniz', 'message': 'Merhaba,\n\nKurulum için yardım ister misiniz?'})
    check('İşletmeye e-posta gönderilir', r.status_code == 200, r.text)
    r = a.post(f'/admin/businesses/{bid}/email', {'subject': 'x', 'message': ''})
    check('Boş e-posta reddedilir', r.status_code == 422)
    em = d(a.get('/admin/emails'))
    check('E-posta kayıtları: tür ve durum', em['stats']['sent_24h'] >= 3 and any(x['kind'] == 'admin_message' for x in em['items']), em['stats'])
    check('E-posta kaydı filtre', all(x['status'] == 'sent' for x in d(a.get('/admin/emails?status=sent'))['items']))
    r = a.get(f'/admin/businesses/{bid}/export')
    j = r.json() if r.status_code == 200 else {}
    check('Dışa aktarma JSON (şifre/anahtar yok)', r.status_code == 200 and 'attachment' in r.headers.get('Content-Disposition', '') and 'password_hash' not in r.text and 'display_token_hash' not in r.text and j['business']['id'] == bid, r.status_code)
    check('İşletme oturumu dışa aktarım yapamaz', o.get(f'/admin/businesses/{bid}/export').status_code == 401)

    print('== Bakım modu ve sistem ==')
    r = a.post('/admin/system/maintenance', {'on': True, 'message': 'Güncelleme yapılıyor'})
    check('Bakım modu açılır', r.status_code == 200)
    o2 = Client(); o2.refresh_csrf() if False else None
    rr = requests.get(BASE + '/api/auth/csrf')
    check('Bakımda işletme API 503 + mesaj', rr.status_code == 503 and 'Güncelleme' in rr.text, rr.text)
    check('Bakımda yönetici paneli çalışır', a.get('/admin/overview').status_code == 200)
    a.post('/admin/system/maintenance', {'on': False})
    check('Bakım kapanır', requests.get(BASE + '/api/auth/csrf').status_code == 200)
    stt = d(a.get('/admin/system/status'))
    names = {c['name']: c for c in stt['checks']}
    check('Sistem durumu kontrolleri', names['Veritabanı bağlantısı']['ok'] and names['Veritabanı şeması']['ok'] and len(stt['tables']) >= 10, list(names))
    r = a.post('/admin/system/test-email', {'to': 'test@dnlstation.com.tr'})
    check('Test e-postası', r.status_code == 200, r.text)
    check('Temizlik (GC)', a.post('/admin/system/gc', {}).status_code == 200)

    print('== Güvenlik ve işlem kayıtları, uyarılar ==')
    bad = Client(); bad.refresh_csrf()
    for _ in range(3):
        bad.post('/auth/login', {'email': 'sahip@arena.test', 'password': 'yanlis-123456'})
    sec = d(a.get('/admin/security'))
    check('Güvenlik kaydı: hatalı girişler', any(x['type'] == 'login_failed' for x in sec['items']) and any(t['type'] == 'login_failed' for t in sec['by_type_24h']), sec['by_type_24h'])
    check('Güvenlik kaydında ham IP yok', all(len(x['ip_hash']) == 16 and '127.0.0.1' not in json.dumps(x) for x in sec['items']))
    check('Tür filtresi', all(x['type'] == 'login_failed' for x in d(a.get('/admin/security?type=login_failed'))['items']))
    act = d(a.get('/admin/activity'))['items']
    check('Tüm işletmelerin işlem akışı', any(x['action'] == 'product.create' for x in act), [x['action'] for x in act][:5])
    check('İşletme işlem kaydı', len(d(a.get(f'/admin/businesses/{bid}/activity'))['items']) > 3)
    ov = d(a.get('/admin/overview'))
    check('Genel bakış: kayıt grafiği ve uyarılar', len(ov['signups']) == 30 and isinstance(ov['alerts'], list), list(ov.keys()))
    check('Uyarı: SMTP değil', any('SMTP' in x['title'] for x in ov['alerts']), ov['alerts'])

    print('== Silme ==')
    r = a.post(f'/admin/businesses/{bid}/delete', {'confirm_name': 'Arena Yeni'})
    check('Dondurulmadan silinemez', r.status_code == 409 and err(r) == 'not_suspended', r.text)
    a.post(f'/admin/businesses/{bid}/suspend', {'reason': 'Silinecek'})
    r = a.post(f'/admin/businesses/{bid}/delete', {'confirm_name': 'yanlış ad'})
    check('Yanlış onay adıyla silinemez', r.status_code == 422, r.text)
    r = a.post(f'/admin/businesses/{bid}/delete', {'confirm_name': 'Arena Yeni'})
    check('İşletme ve tüm verisi silinir', r.status_code == 200 and not sql('SELECT id FROM businesses WHERE id=?', (bid,)) and not sql('SELECT id FROM users WHERE business_id=?', (bid,)) and not sql('SELECT id FROM products WHERE business_id=?', (bid,)), r.text)
    check('Silme kayda geçer', any(x['action'] == 'business.deleted' for x in d(a.get('/admin/audit'))['items']))
finally:
    srv.stop()
sys.exit(summary())
