"""M2–M9: işletme paneli API testleri (işlev + güvenlik + işletme izolasyonu)."""
import sqlite3, os, re
from lib import *

DB = os.path.join(TMP, 'test.db')


def sql(q, args=()):
    con = sqlite3.connect(DB)
    try:
        cur = con.execute(q, args)
        con.commit()
        return cur.fetchall()
    finally:
        con.close()


def d(r):
    try:
        return r.json().get('data') or {}
    except Exception:
        return {}


def err(r):
    try:
        return (r.json().get('error') or {}).get('code')
    except Exception:
        return None


def shift_back(sid, minutes):
    """Oturumu geçmişe kaydırır (süre geçmiş gibi)."""
    for col in ('started_at',):
        sql(f"UPDATE play_sessions SET {col} = datetime({col}, ?) WHERE id = ?", (f'-{minutes} minutes', sid))
    sql("UPDATE session_segments SET started_at = datetime(started_at, ?) WHERE session_id = ?", (f'-{minutes} minutes', sid))
    sql("UPDATE session_segments SET ended_at = datetime(ended_at, ?) WHERE session_id = ? AND ended_at IS NOT NULL", (f'-{minutes} minutes', sid))


srv = Server()
try:
    srv.migrate()
    own = Client()
    check('Sahip kaydı', register(own).status_code == 200)
    own.me()

    print('== Güvenlik temelleri ==')
    anon = Client()
    check('Girişsiz dashboard 401', anon.get('/dashboard').status_code == 401)
    r = own.post('/sessions', {'table_id': 1}, csrf=False)
    check('CSRF olmadan masa açılamaz (419)', r.status_code == 419, r.text)

    print('== Kurulum ==')
    r = own.get('/setup/presets')
    check('Hazır şablonlar', r.status_code == 200 and len(d(r)['presets']) == 6, r.text)
    check('Kurulum yapılmadı', d(own.get('/dashboard'))['setup_done'] is False)
    r = own.post('/setup/quick', {'items': [
        {'kind': 'playstation', 'count': 4, 'hour_price': '100', 'hour_price_4': '150', 'match_price': '25'},
        {'kind': 'bilardo', 'count': 2, 'hour_price': '120,50'}]})
    check('Hızlı kurulum', r.status_code == 200, r.text)
    r = own.post('/setup/quick', {'items': [{'kind': 'okey', 'count': 1, 'hour_price': '50'}]})
    check('İkinci kurulum engellenir', r.status_code == 409, r.text)
    dash = d(own.get('/dashboard'))
    check('Dashboard 6 masa', len(dash['tables']) == 6 and dash['setup_done'] is True, dash)
    types = {t['kind']: t for t in dash['types']}
    check('Fiyat kuruş olarak saklanır', types['bilardo']['hour_price'] == 12050 and types['playstation']['hour_price_4'] == 15000, types)
    ps = [t for t in dash['tables'] if t['type_id'] == types['playstation']['id']]
    bil = [t for t in dash['tables'] if t['type_id'] == types['bilardo']['id']]

    print('== Masa oturumu ==')
    r = own.post('/sessions', {'table_id': ps[0]['id'], 'mode': 'open', 'controllers': 2})
    check('Masa açılır', r.status_code == 200 and d(r)['mode'] == 'open' and d(r)['controllers'] == 2, r.text)
    s1 = d(r)['id']
    r = own.post('/sessions', {'table_id': ps[0]['id'], 'mode': 'open'})
    check('Dolu masa ikinci kez açılamaz (409)', r.status_code == 409 and err(r) == 'occupied', r.text)
    shift_back(s1, 90)
    r = own.get(f'/sessions/{s1}')
    check('90 dk açık süre = 150 TL (2 kol)', abs(d(r)['game_cents'] - 15000) <= 5, d(r))
    check('Canlı saatlik ücret döner', d(r)['rate_per_hour'] == 10000)
    r = own.post(f'/sessions/{s1}/controllers', {'controllers': 4})
    check('Kol sayısı değişir', r.status_code == 200 and d(r)['controllers'] == 4 and d(r)['rate_per_hour'] == 15000, r.text)
    sql("UPDATE session_segments SET started_at = datetime(started_at, '-30 minutes') WHERE session_id = ? AND ended_at IS NULL", (s1,))
    sql("UPDATE session_segments SET ended_at = datetime(ended_at, '-30 minutes'), started_at = datetime(started_at, '-30 minutes') WHERE session_id = ? AND ended_at IS NOT NULL", (s1,))
    sql("UPDATE play_sessions SET started_at = datetime(started_at, '-30 minutes') WHERE id = ?", (s1,))
    r = own.get(f'/sessions/{s1}')
    check('Dilimli hesap: 90dk×100 + 30dk×150 = 225 TL', abs(d(r)['game_cents'] - 22500) <= 10, d(r))

    r = own.post('/sessions', {'table_id': ps[1]['id'], 'mode': 'fixed', 'planned_minutes': 60, 'controllers': 2})
    s2 = d(r)['id']
    check('Sabit süre peşin ücretlenir (60 dk = 100 TL)', r.status_code == 200 and abs(d(r)['game_cents'] - 10000) <= 5 and d(r)['remaining'] > 3500, r.text)
    r = own.post(f'/sessions/{s2}/extend', {'minutes': 30})
    check('Süre eklenir (90 dk = 150 TL)', abs(d(r)['game_cents'] - 15000) <= 5 and d(r)['planned_minutes'] == 90, r.text)
    shift_back(s2, 100)
    r = own.get(f'/sessions/{s2}')
    check('Süre aşımı işaretlenir ve aşan süre ücretlenir', d(r)['over'] is True and abs(d(r)['game_cents'] - 16667) <= 10, d(r))

    r = own.post('/sessions', {'table_id': ps[2]['id'], 'mode': 'match', 'controllers': 2})
    s3 = d(r)['id']
    for _ in range(3):
        r = own.post(f'/sessions/{s3}/match', {'delta': 1})
    check('Maç başı: 3 maç = 75 TL', d(r)['match_count'] == 3 and d(r)['game_cents'] == 7500, r.text)
    r = own.post('/sessions', {'table_id': bil[0]['id'], 'mode': 'match'})
    check('Maç ücreti tanımsız türde maç başı açılamaz', r.status_code == 422 and err(r) == 'no_match_price', r.text)
    r = own.post('/sessions', {'table_id': bil[0]['id'], 'mode': 'open', 'controllers': 4})
    sb = d(r)['id']
    check('Kolsuz türde kol sayısı yok sayılır', d(r)['controllers'] is None, r.text)
    r = own.post(f'/sessions/{s3}/move', {'table_id': bil[1]['id']})
    check('Farklı türe taşıma engellenir', r.status_code == 422, r.text)
    r = own.post(f'/sessions/{s3}/move', {'table_id': ps[3]['id']})
    check('Aynı türde boş masaya taşınır', r.status_code == 200 and d(r)['table_id'] == ps[3]['id'], r.text)
    dash = d(own.get('/dashboard'))
    tmap = {t['id']: t for t in dash['tables']}
    check('Eski masa boşaldı, yenisi dolu', tmap[ps[2]['id']]['session'] is None and tmap[ps[3]['id']]['session']['id'] == s3)

    print('== Ürün ve stok ==')
    r = own.post('/products', {'name': 'Kola', 'category': 'İçecek', 'price': '40', 'track_stock': True, 'initial_stock': 2, 'low_stock': 1})
    check('Ürün eklenir', r.status_code == 200, r.text)
    kola = d(r)['id']
    r = own.post('/products', {'name': 'Tost', 'price': '75,5'})
    tost = d(r)['id']
    r = own.post(f'/sessions/{s1}/items', {'product_id': kola, 'qty': 2})
    check('Masaya ürün eklenir', r.status_code == 200 and d(r)['items_cents'] == 8000, r.text)
    item_id = d(r)['items'][0]['id']
    r = own.post(f'/sessions/{s1}/items', {'product_id': kola, 'qty': 1})
    check('Yetersiz stokta satış engellenir', r.status_code == 409 and err(r) == 'stock', r.text)
    check('Stok düştü', sql('SELECT stock_qty FROM products WHERE id=?', (kola,))[0][0] == 0)
    r = own.delete(f'/sessions/{s1}/items/{item_id}')
    check('Kalem silinir, stok iade edilir', r.status_code == 200 and d(r)['items_cents'] == 0 and sql('SELECT stock_qty FROM products WHERE id=?', (kola,))[0][0] == 2, r.text)
    own.post(f'/sessions/{s1}/items', {'product_id': tost, 'qty': 2})
    r = own.post(f'/products/{kola}/restock', {'qty': 10})
    check('Stok girişi', r.status_code == 200 and [p for p in d(r)['products'] if p['id'] == kola][0]['stock_qty'] == 12, r.text)
    r = own.post(f'/products/{kola}/count', {'qty': 7})
    check('Sayım ile stok eşitlenir', [p for p in d(r)['products'] if p['id'] == kola][0]['stock_qty'] == 7, r.text)

    print('== Vardiya ve hesap kapama ==')
    r = own.post(f'/sessions/{s1}/close', {'payments': [{'method': 'cash', 'amount': 1}]})
    check('Vardiya açılmadan hesap kapanmaz', r.status_code == 409 and err(r) == 'no_shift', r.text)
    r = own.post('/shift/open', {'opening_cash': '500'})
    check('Vardiya açılır', r.status_code == 200 and d(r)['shift']['opening_cash_cents'] == 50000, r.text)
    r = own.post('/shift/open', {'opening_cash': '0'})
    check('İkinci vardiya açılamaz', r.status_code == 409, r.text)
    det = d(own.get(f'/sessions/{s1}'))
    r = own.post(f'/sessions/{s1}/close', {'payments': [{'method': 'cash', 'amount': 100}]})
    check('Eksik ödeme reddedilir', r.status_code == 422 and err(r) == 'payment_mismatch', r.text)
    r = own.post(f'/sessions/{s1}/close', {'payments': [{'method': 'cash', 'amount': -5}]})
    check('Negatif ödeme reddedilir', r.status_code == 422, r.text)
    # Tutar kapanış anında sunucuda yeniden hesaplanır; birkaç saniyelik farkı tolere etmek için önce indirimle sabitle
    r = own.post(f'/sessions/{s1}/close', {'discount_type': 'percent', 'discount_value': 100, 'payments': []})
    check('%100 indirimle ödemesiz kapanır (sahip)', r.status_code == 200 and d(r)['total_cents'] == 0, r.text)
    r = own.post(f'/sessions/{s1}/close', {'payments': []})
    check('Aynı hesap iki kez kapatılamaz', r.status_code == 409, r.text)
    r = own.post(f'/sessions/{s1}/reopen', {})
    check('Kapatılan hesap geri alınır', r.status_code == 200 and d(r)['status'] == 'active', r.text)
    sub = d(r)['subtotal_cents']
    r = own.post(f'/sessions/{s1}/close', {'discount_type': 'amount', 'discount_value': str(sub / 100 + 5)})
    check('İndirim tutarı hesabı aşamaz', r.status_code == 422, r.text)
    det = d(own.get(f'/sessions/{s1}'))
    total = det['subtotal_cents'] - 1000
    r = own.post(f'/sessions/{s1}/close', {'discount_type': 'amount', 'discount_value': '10', 'payments': [{'method': 'cash', 'amount': total - 5000}, {'method': 'card', 'amount': 5000}]})
    if r.status_code == 422 and err(r) == 'payment_mismatch':  # saniye sınırı geçtiyse bir kez daha
        det = d(own.get(f'/sessions/{s1}')); total = det['subtotal_cents'] - 1000
        r = own.post(f'/sessions/{s1}/close', {'discount_type': 'amount', 'discount_value': '10', 'payments': [{'method': 'cash', 'amount': total - 5000}, {'method': 'card', 'amount': 5000}]})
    check('Bölünmüş ödeme + tutar indirimi ile kapanır', r.status_code == 200 and d(r)['discount_cents'] == 1000, r.text)
    paid_total = d(r).get('total_cents', 0)
    check('Masa boşaldı', d(own.get('/dashboard'))['tables'][0]['session'] is None)

    print('== Hızlı satış ==')
    r = own.post('/quick-sales', {'lines': [{'product_id': kola, 'qty': 2}], 'payments': [{'method': 'cash', 'amount': 8000}]})
    check('Hızlı satış', r.status_code == 200 and d(r)['total_cents'] == 8000, r.text)
    q1 = d(r)['ticket_id']
    check('Hızlı satış stoğu düşer', sql('SELECT stock_qty FROM products WHERE id=?', (kola,))[0][0] == 5)
    r = own.post('/quick-sales', {'lines': [{'product_id': kola, 'qty': 1}], 'payments': [{'method': 'cash', 'amount': 1}]})
    check('Hızlı satışta fiyat sunucuda hesaplanır (manipüle tutar reddedilir)', r.status_code == 422, r.text)
    r = own.post('/quick-sales', {'lines': [{'product_id': tost, 'qty': 1}], 'payments': [{'method': 'cash', 'amount': 10000}, {'method': 'card', 'amount': 'rest'}]})
    check('Kalan tutar (rest) hesabı aşan ödemeyi reddeder', r.status_code == 422 and err(r) == 'payment_mismatch', r.text)
    r = own.post('/quick-sales', {'lines': [{'product_id': tost, 'qty': 1}], 'payments': [{'method': 'cash', 'amount': 5000}, {'method': 'card', 'amount': 'rest'}]})
    qrest = d(r).get('ticket_id')
    check('Nakit + kalan kart ödemesi', r.status_code == 200 and sql('SELECT method, amount_cents FROM payments WHERE ticket_id=? ORDER BY id', (qrest,)) == [('cash', 5000), ('card', 2550)], r.text)
    own.post(f'/quick-sales/{qrest}/void', {})
    r = own.post(f'/quick-sales/{q1}/void', {})
    check('Hızlı satış iptal + stok iade', r.status_code == 200 and sql('SELECT stock_qty FROM products WHERE id=?', (kola,))[0][0] == 7, r.text)
    r = own.post(f'/quick-sales/{q1}/void', {})
    check('İptal iki kez yapılamaz', r.status_code == 409, r.text)

    print('== Personel ve roller ==')
    r = own.post('/staff', {'name': 'Ali Personel', 'email': 'ali@arena.test', 'role': 'staff'})
    check('Personel eklenir, geçici şifre bir kez döner', r.status_code == 200 and len(d(r)['temp_password']) == 14, r.text)
    temp = d(r)['temp_password']; ali_id = d(r)['user_id']
    r = own.post('/staff', {'name': 'Ali 2', 'email': 'ali@arena.test', 'role': 'staff'})
    check('Aynı e-posta ile ikinci personel eklenemez', r.status_code == 422, r.text)
    owner_id = [u for u in d(own.get('/staff'))['users'] if u['role'] == 'owner'][0]['id']
    r = own.put(f'/staff/{owner_id}', {'name': 'X', 'role': 'staff'})
    check('Sahip kendi rolünü düşüremez', r.status_code == 403, r.text)
    st = Client(); st.refresh_csrf()
    r = st.post('/auth/login', {'email': 'ali@arena.test', 'password': temp})
    check('Personel geçici şifreyle girer', r.status_code == 200, r.text)
    st.me()
    r = st.get('/dashboard')
    check('Şifre değiştirmeden panel kapalı', r.status_code == 403 and err(r) == 'must_change_password', r.text)
    r = st.post('/account/password', {'current_password': temp, 'password': 'Personel.Sifre-77', 'password_confirm': 'Personel.Sifre-77'})
    check('Personel şifresini değiştirir', r.status_code == 200, r.text)
    st.csrf = d(r)['csrf']
    check('Personel masaları görür', st.get('/dashboard').status_code == 200)
    check('Personel ciroyu görmez', d(st.get('/dashboard'))['kpi']['revenue_today_cents'] is None)
    for path in ['/reports/summary', '/staff', '/settings', '/audit', '/products', '/catalog', '/shifts']:
        check(f'Personel {path} erişemez (403)', st.get(path).status_code == 403)
    r = st.post(f'/sessions/{s2}/cancel', {'reason': 'deneme'})
    check('Personel masa iptal edemez', r.status_code == 403, r.text)
    det = d(st.get(f'/sessions/{s2}'))
    r = st.post(f'/sessions/{s2}/close', {'discount_type': 'percent', 'discount_value': 50, 'payments': [{'method': 'cash', 'amount': det['subtotal_cents'] // 2}]})
    check('Personel indirim yapamaz (varsayılan)', r.status_code == 403, r.text)
    sh = d(st.get('/shift'))
    check('Personel kasada beklenen nakdi görmez (kör sayım)', 'expected_cash_cents' not in sh['shift'], sh)
    r = st.post('/products', {'name': 'X', 'price': '1'})
    check('Personel ürün ekleyemez', r.status_code == 403, r.text)
    r = st.post('/catalog/tables', {'table_type_id': types['playstation']['id'], 'prefix': 'PS', 'count': 1})
    check('Personel masa ekleyemez', r.status_code == 403, r.text)
    r = own.put('/settings', {'name': 'Arena Game Center', 'phone': '', 'warn_minutes': 5, 'staff_can_discount': True, 'show_prices_on_display': True, 'show_free_on_display': True})
    check('Sahip ayarları günceller', r.status_code == 200 and d(r)['staff_can_discount'] is True, r.text)
    det = d(st.get(f'/sessions/{s2}'))
    half = det['subtotal_cents'] - round(det['subtotal_cents'] * 50 / 100)
    r = st.post(f'/sessions/{s2}/close', {'discount_type': 'percent', 'discount_value': 50, 'payments': [{'method': 'card', 'amount': 'rest'}]})
    check('Ayar açılınca personel indirim yapabilir', r.status_code == 200 and d(r)['discount_cents'] > 0, r.text)
    half = d(r).get('total_cents', 0)
    r = own.put(f'/staff/{ali_id}', {'name': 'Ali Personel', 'role': 'staff', 'is_active': False})
    check('Personel pasife alınır', r.status_code == 200)
    check('Pasif personelin oturumu anında düşer', st.get('/dashboard').status_code == 401)

    print('== İşletme izolasyonu ==')
    other = Client()
    register(other, email='rakip@salon.test', business='Rakip Salon')
    other.me()
    for path in [f'/sessions/{s3}', f'/shifts/1']:
        r = other.get(path)
        check(f'Başka işletme {path} göremez', r.status_code in (403, 404), r.text)
    r = other.post(f'/sessions/{s3}/match', {'delta': 1})
    check('Başka işletmenin oturumunu değiştiremez', r.status_code == 404, r.text)
    r = other.post('/sessions', {'table_id': ps[1]['id'], 'mode': 'open'})
    check('Başka işletmenin masasını açamaz', r.status_code == 404, r.text)
    r = other.put(f'/catalog/tables/{ps[1]["id"]}', {'name': 'hack', 'table_type_id': types['playstation']['id']})
    check('Başka işletmenin masasını düzenleyemez', r.status_code == 404, r.text)
    r = other.put(f'/products/{kola}', {'name': 'hack', 'price': '1'})
    check('Başka işletmenin ürününü düzenleyemez', r.status_code == 404, r.text)
    r = other.put(f'/staff/{ali_id}', {'name': 'hack', 'role': 'manager'})
    check('Başka işletmenin personelini düzenleyemez', r.status_code == 404, r.text)
    other.post('/setup/quick', {'items': [{'kind': 'playstation', 'count': 1, 'hour_price': '50'}]})
    other.post('/shift/open', {'opening_cash': '0'})
    otab = d(other.get('/dashboard'))['tables'][0]['id']
    osid = d(other.post('/sessions', {'table_id': otab}))['id']
    r = other.post(f'/sessions/{osid}/items', {'product_id': kola, 'qty': 1})
    check('Başka işletmenin ürününü adisyona ekleyemez', r.status_code == 404, r.text)
    r = other.post(f'/sessions/{osid}/move', {'table_id': ps[1]['id']})
    check('Oturumu başka işletmenin masasına taşıyamaz', r.status_code == 404, r.text)
    check('Raporlar işletmeye özel', d(other.get('/reports/summary'))['totals']['tickets'] == 0)

    print('== İptal, vardiya kapanışı, raporlar ==')
    r = own.post(f'/sessions/{sb}/cancel', {'reason': 'Müşteri vazgeçti'})
    check('Yönetici masayı iptal eder', r.status_code == 200, r.text)
    r = own.post('/shift/close', {'counted_cash': '0'})
    check('Açık masa varken vardiya kapanmaz', r.status_code == 409 and err(r) == 'active_sessions', r.text)
    own.post(f'/sessions/{s3}/cancel', {'reason': 'test iptal'})
    cur = d(own.get('/shift'))['shift']
    r = own.post('/shift/close', {'counted_cash': str(cur['expected_cash_cents'] / 100)})
    z = d(r).get('report', {})
    check('Vardiya kapanır, Z raporu döner', r.status_code == 200 and z['counted_cash_cents'] == z['expected_cash_cents'], r.text)
    check('Z raporu: nakit + kart = ciro', z['cash_cents'] + z['card_cents'] + z['other_cents'] == z['revenue_cents'] and z['revenue_cents'] == paid_total + half, z)
    check('Z raporu iptalleri sayar', z['voids'] >= 3, z)
    r = own.post(f'/sessions/{s1}/reopen', {})
    check('Kapanan vardiyanın hesabı geri alınamaz', r.status_code == 409, r.text)
    rep = d(own.get('/reports/summary'))
    check('Rapor özeti', rep['totals']['tickets'] == 2 and rep['totals']['revenue'] == paid_total + half and len(rep['series']) == 1, rep.get('totals'))
    check('Rapor tür kırılımı', any(t['name'] == 'PlayStation' for t in rep['by_type']), rep['by_type'])
    r = own.get('/reports/summary?from=2026-13-01&to=2026-01-01')
    check('Geçersiz tarih reddedilir', r.status_code == 422)
    r = own.get('/reports/tickets.csv')
    check('CSV indirme (BOM + ; ayırıcı)', r.status_code == 200 and r.content.startswith(b'\xef\xbb\xbf') and b';' in r.content and 'attachment' in r.headers.get('Content-Disposition', ''), r.text[:200])
    check('CSV iptalleri içerir', 'İptal' in r.content.decode('utf-8'))

    print('== Müşteri ekranı ==')
    r = requests.get(BASE + '/api/display', headers={'X-Display-Key': 'x' * 32})
    check('Geçersiz ekran anahtarı 401', r.status_code == 401)
    r = own.post('/settings/display/rotate', {})
    key = d(r)['key']
    check('Ekran anahtarı üretilir', r.status_code == 200 and len(key) == 32)
    check('Anahtar veritabanında hash olarak saklanır', sql('SELECT display_token_hash FROM businesses WHERE id=1')[0][0] != key)
    sid = d(own.post('/sessions', {'table_id': ps[0]['id']}))['id']
    r = requests.get(BASE + '/api/display', headers={'X-Display-Key': key})
    dd = r.json()['data']
    check('Ekran verisi döner', r.status_code == 200 and dd['business'] == 'Arena Game Center' and any(t['busy'] for t in dd['tables']), r.text)
    check('Ekranda kişisel veri / kimlik yok', 'id' not in dd['tables'][0] and 'opened_by' not in r.text and 'ali@' not in r.text)
    own.put('/settings', {'name': 'Arena Game Center', 'warn_minutes': 5, 'show_prices_on_display': False, 'show_free_on_display': False})
    dd = requests.get(BASE + '/api/display', headers={'X-Display-Key': key}).json()['data']
    check('Fiyat gizleme + boş masa gizleme ayarı', all(t['amount_cents'] is None for t in dd['tables']) and all(t['busy'] for t in dd['tables']), dd)
    key2 = d(own.post('/settings/display/rotate', {}))['key']
    check('Yenilenen anahtar eskisini geçersiz kılar', requests.get(BASE + '/api/display', headers={'X-Display-Key': key}).status_code == 401)
    check('Yeni anahtar çalışır', requests.get(BASE + '/api/display', headers={'X-Display-Key': key2}).status_code == 200)

    print('== Paket sınırları ve abonelik ==')
    r = own.get('/audit')
    check('İşlem kayıtları listelenir', r.status_code == 200 and len(d(r)['items']) > 10)
    sql("UPDATE businesses SET plan='baslangic' WHERE id=1")
    r = own.post('/catalog/tables', {'table_type_id': types['playstation']['id'], 'prefix': 'PS', 'count': 3})
    check('Başlangıç paketinde 8 masa sınırı', r.status_code == 422 and err(r) == 'plan_limit', r.text)
    r = own.post('/catalog/tables', {'table_type_id': types['playstation']['id'], 'prefix': 'PS', 'count': 2})
    check('Sınır içinde masa eklenir', r.status_code == 200 and len(d(r)['tables']) == 8, r.text)
    r = own.delete(f'/catalog/types/{types["playstation"]["id"]}')
    check('Masası olan tür silinemez', r.status_code == 409, r.text)
    r = own.delete(f'/catalog/tables/{ps[0]["id"]}')
    check('Dolu masa silinemez', r.status_code == 409, r.text)
    sql("UPDATE businesses SET trial_ends_at='2020-01-01 00:00:00' WHERE id=1")
    r = own.post('/products', {'name': 'Su', 'price': '10'})
    check('Deneme bitince yazma işlemleri 402', r.status_code == 402 and err(r) == 'subscription_expired', r.text)
    check('Deneme bitince raporlar okunabilir', own.get('/reports/summary').status_code == 200)
    sql("UPDATE businesses SET trial_ends_at='2099-01-01 00:00:00' WHERE id=1")

    print('== Girdi güvenliği ==')
    r = own.post('/products', {'name': '<script>alert(1)</script>', 'price': '10'})
    check('HTML içeren ad olduğu gibi saklanır (çıktıda kaçışlanır), hata yok', r.status_code == 200)
    r = own.post('/products', {'name': 'X', 'price': '-5'})
    check('Negatif fiyat reddedilir', r.status_code == 422)
    r = own.post('/sessions', {'table_id': "1 OR 1=1"})
    check('SQL enjeksiyon denemesi doğrulamada reddedilir', r.status_code == 422, r.text)
    r = own.s.post(BASE + '/api/products', data='{bozuk', headers={'Content-Type': 'application/json', 'Origin': BASE, 'X-CSRF-Token': own.csrf})
    check('Bozuk JSON 400', r.status_code == 400, r.text)
finally:
    srv.stop()

sys.exit(summary())
