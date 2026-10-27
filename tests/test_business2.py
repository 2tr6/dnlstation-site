"""İşletme ek modülleri: veresiye, giderler, rezervasyon, fiş, maliyet/kâr, Z raporu e-postası, güvenlik kaydı."""
import sqlite3, os
from lib import *

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
    o.post('/setup/quick', {'items': [{'kind': 'playstation', 'count': 3, 'hour_price': '100', 'hour_price_4': '150', 'match_price': '25'}]})
    tables = d(o.get('/dashboard'))['tables']
    o.post('/shift/open', {'opening_cash': '100'})
    kola = d(o.post('/products', {'name': 'Kola', 'price': '40', 'cost': '15'}))['id']

    print('== Veresiye ==')
    r = o.post('/customers', {'name': 'Ahmet Usta', 'phone': '05321112233'})
    check('Müşteri eklenir', r.status_code == 200, r.text)
    cid = d(r)['id']
    o.put(f'/customers/{cid}', {'name': 'Ahmet Usta', 'phone': '05321112233', 'credit_limit': '100', 'is_active': True})
    q = o.post('/quick-sales', {'lines': [{'product_id': kola, 'qty': 2}], 'payments': [{'method': 'credit', 'amount': 'rest', 'customer_id': cid}]})
    check('Hızlı satış veresiyeye yazılır', q.status_code == 200, q.text)
    check('Müşteri bakiyesi 80 TL', d(o.get(f'/customers/{cid}'))['customer']['balance_cents'] == 8000)
    r = o.post('/quick-sales', {'lines': [{'product_id': kola, 'qty': 1}], 'payments': [{'method': 'credit', 'amount': 'rest', 'customer_id': cid}]})
    check('Veresiye limiti aşılamaz', r.status_code == 409 and err(r) == 'credit_limit', r.text)
    r = o.post('/quick-sales', {'lines': [{'product_id': kola, 'qty': 1}], 'payments': [{'method': 'credit', 'amount': 'rest'}]})
    check('Müşterisiz veresiye reddedilir', r.status_code == 422, r.text)
    other = Client(); register(other, email='x@y.test', business='Başka'); other.me()
    ocid = d(other.post('/customers', {'name': 'Yabancı Müşteri'}))['id']
    r = o.post('/quick-sales', {'lines': [{'product_id': kola, 'qty': 1}], 'payments': [{'method': 'credit', 'amount': 'rest', 'customer_id': ocid}]})
    check('Başka işletmenin müşterisine veresiye yazılamaz', r.status_code == 404, r.text)
    check('Başka işletmenin müşterisi görüntülenemez', other.get(f'/customers/{cid}').status_code == 404)
    r = o.post(f'/customers/{cid}/collect', {'amount': '90', 'method': 'cash'})
    check('Borçtan fazla tahsilat reddedilir', r.status_code == 422, r.text)
    r = o.post(f'/customers/{cid}/collect', {'amount': '30', 'method': 'cash'})
    check('Tahsilat bakiyeyi düşürür', r.status_code == 200 and d(r)['customer']['balance_cents'] == 5000, r.text)
    o.post(f'/quick-sales/{d(q)["ticket_id"]}/void', {})
    check('Veresiyeli satış iptal edilince borç geri alınır', d(o.get(f'/customers/{cid}'))['customer']['balance_cents'] == -3000)

    print('== Masa hesabı veresiye + geri alma ==')
    sid = d(o.post('/sessions', {'table_id': tables[0]['id'], 'mode': 'match'}))['id']
    o.post(f'/sessions/{sid}/match', {'delta': 2})
    r = o.post(f'/sessions/{sid}/close', {'payments': [{'method': 'cash', 'amount': 2000}, {'method': 'credit', 'amount': 'rest', 'customer_id': cid}]})
    check('Bölünmüş nakit + veresiye kapanış', r.status_code == 200, r.text)
    check('Bakiye +30', d(o.get(f'/customers/{cid}'))['customer']['balance_cents'] == 0)
    o.post(f'/sessions/{sid}/reopen', {})
    check('Hesap geri alınınca veresiye de geri alınır', d(o.get(f'/customers/{cid}'))['customer']['balance_cents'] == -3000)
    o.post(f'/sessions/{sid}/cancel', {'reason': 'test için'})

    print('== Giderler ==')
    r = o.post('/expenses', {'category': 'Ürün alımı', 'amount': '25', 'method': 'cash', 'note': 'buz'})
    check('Kasadan gider eklenir', r.status_code == 200 and d(r)['total_cents'] == 2500, r.text)
    eid = d(r)['items'][0]['id']
    r = o.post('/expenses', {'category': 'Kira', 'amount': '5000', 'method': 'other'})
    check('Kasa dışı gider eklenir', d(r)['total_cents'] == 502500, r.text)
    r = o.post('/expenses', {'category': 'Kira', 'amount': '1000', 'method': 'cash'})
    check('Kasadaki nakitten büyük kasa gideri engellenir', r.status_code == 409 and err(r) == 'insufficient_cash', r.text)
    r = o.post('/expenses', {'category': 'Kira', 'amount': '-5'})
    check('Negatif gider reddedilir', r.status_code == 422)
    sh = d(o.get('/shift'))['shift']
    check('Beklenen nakit = açılış + nakit tahsilat + veresiye tahsilatı − kasa gideri', sh['expected_cash_cents'] == 10000 + 3000 - 2500 and sh['collections_cash_cents'] == 3000 and sh['expense_cash_cents'] == 2500, sh)
    o.post(f'/expenses/{eid}/void', {})
    check('Gider iptal edilince kasa düzelir', d(o.get('/shift'))['shift']['expected_cash_cents'] == 13000)

    print('== Rezervasyon ==')
    import datetime
    tom = (datetime.date.today() + datetime.timedelta(days=1)).isoformat()
    r = o.post('/reservations', {'customer_name': 'Mehmet', 'phone': '05329998877', 'table_id': tables[1]['id'], 'date': tom, 'time': '20:00', 'duration_minutes': 120, 'people': 4})
    check('Rezervasyon eklenir', r.status_code == 200, r.text)
    rid = d(r)['id']
    r = o.post('/reservations', {'customer_name': 'Ali', 'table_id': tables[1]['id'], 'date': tom, 'time': '21:00', 'duration_minutes': 60})
    check('Aynı masaya çakışan rezervasyon engellenir', r.status_code == 409 and err(r) == 'overlap', r.text)
    r = o.post('/reservations', {'customer_name': 'Ali', 'table_id': tables[1]['id'], 'date': tom, 'time': '22:00', 'duration_minutes': 60})
    check('Bitişten sonraki saat serbest', r.status_code == 200, r.text)
    lst = d(o.get(f'/reservations?date={tom}'))['items']
    check('Günün rezervasyonları listelenir', len(lst) == 2 and lst[0]['customer_name'] == 'Mehmet', lst)
    check('Rezervasyon durumu değişir', o.post(f'/reservations/{rid}/status', {'status': 'cancelled'}).status_code == 200)
    r = o.post('/reservations', {'customer_name': 'Hack', 'table_id': d(other.get('/dashboard')).get('tables', [{'id': 999999}])[0]['id'] if d(other.get('/dashboard')).get('tables') else 999999, 'date': tom, 'time': '10:00'})
    check('Başka işletmenin masasına rezervasyon yapılamaz', r.status_code == 404, r.text)
    check('Başka işletme rezervasyonu değiştiremez', other.post(f'/reservations/{rid}/status', {'status': 'arrived'}).status_code == 404)

    print('== Fiş, maliyet, rapor ==')
    q2 = d(o.post('/quick-sales', {'lines': [{'product_id': kola, 'qty': 3}], 'payments': [{'method': 'card', 'amount': 'rest'}]}))
    t = d(o.get(f'/tickets/{q2["ticket_id"]}'))
    check('Fiş detayı', t.get('total_cents') == 12000 and t['items'][0]['qty'] == 3 and t['payments'][0]['method'] == 'card', t)
    check('Başka işletme fişi göremez', other.get(f'/tickets/{q2["ticket_id"]}').status_code == 404)
    rep = d(o.get('/reports/summary'))
    f = rep['finance']
    check('Rapor: ürün maliyeti ve net kâr', f['product_cost_cents'] == 4500 and f['expense_cents'] == 500000 and f['net_cents'] == rep['totals']['revenue'] - 4500 - 500000, f)

    print('== Z raporu e-postası ve güvenlik kaydı ==')
    r = o.post('/shift/close', {'counted_cash': '130'})
    check('Vardiya kapanır', r.status_code == 200, r.text)
    check('Z raporu e-postası gönderildi ve kaydedildi', sql("SELECT kind, status FROM email_log WHERE kind='z_report'") == [('z_report', 'sent')])
    check('Hoş geldin e-postası kaydedildi', sql("SELECT COUNT(*) FROM email_log WHERE kind='welcome'")[0][0] == 2)
    bad = Client(); bad.refresh_csrf()
    bad.post('/auth/login', {'email': 'sahip@arena.test', 'password': 'yanlis-sifre-1'})
    bad.post('/auth/register', {'name': 'x'}, csrf=False)
    types = {x[0] for x in sql('SELECT type FROM security_events')}
    check('Güvenlik kaydı: hatalı giriş ve CSRF', {'login_failed', 'csrf_failed'} <= types, types)
finally:
    srv.stop()
sys.exit(summary())
