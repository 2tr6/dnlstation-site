"""Yeni kolaylıklar: Z raporunda en çok satanlar ve açık masa; ürün seçicide sık satılanlar;
yönetici genel bakışta ödeme bekleyenler."""
import sqlite3, os, sys
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


srv = Server()
try:
    srv.migrate()
    own = Client(); register(own); own.me()
    own.post('/setup/quick', {'items': [{'kind': 'playstation', 'count': 2, 'hour_price': '100', 'hour_price_4': '150'}]})
    own.post('/shift/open', {'opening_cash': '0'})
    kola = d(own.post('/products', {'name': 'Kola', 'category': 'İçecek', 'price': '40'}))['id']
    su = d(own.post('/products', {'name': 'Su', 'category': 'İçecek', 'price': '10'}))['id']
    tost = d(own.post('/products', {'name': 'Tost', 'category': 'Yiyecek', 'price': '60'}))['id']
    cay = d(own.post('/products', {'name': 'Çay', 'category': 'İçecek', 'price': '15'}))['id']

    print('== Sık satılanlar ==')
    m = d(own.get('/menu'))['products']
    check('Menüde satış adedi alanı var (başta 0)', all(p.get('sold_30d') == 0 for p in m), str(m[:1]))
    for pid, qty, price in [(kola, 3, 4000), (su, 2, 1000), (tost, 1, 6000)]:
        r = own.post('/quick-sales', {'lines': [{'product_id': pid, 'qty': qty}], 'payments': [{'method': 'cash', 'amount': qty * price}]})
        check(f'Satış yapılır ({qty} adet)', r.status_code == 200, r.text[:150])
    m = {p['id']: p['sold_30d'] for p in d(own.get('/menu'))['products']}
    check('Satılan adetler doğru sayılır', m[kola] == 3 and m[su] == 2 and m[tost] == 1 and m[cay] == 0, str(m))
    q = d(own.post('/quick-sales', {'lines': [{'product_id': cay, 'qty': 5}], 'payments': [{'method': 'cash', 'amount': 7500}]}))
    own.post(f"/quick-sales/{q['ticket_id']}/void", {})
    m = {p['id']: p['sold_30d'] for p in d(own.get('/menu'))['products']}
    check('İptal edilen satış sayılmaz', m[cay] == 0, str(m))

    print('== Z raporu ==')
    s = d(own.get('/shift'))['shift']
    names = [p['name'] for p in s['top_products']]
    check('En çok satanlar adede göre sıralı', names[:3] == ['Kola', 'Su', 'Tost'], str(s['top_products']))
    check('En çok satan tutarı doğru', s['top_products'][0]['qty'] == 3 and s['top_products'][0]['total_cents'] == 12000)
    check('Açık masa yokken 0', s['open_tables'] == 0, str(s.get('open_tables')))
    tid = d(own.get('/dashboard'))['tables'][0]['id']
    r = own.post('/sessions', {'table_id': tid, 'mode': 'open', 'controllers': 2})
    check('Masa açılır', r.status_code == 200, r.text[:150])
    check('Açık masa sayısı raporda görünür', d(own.get('/shift'))['shift']['open_tables'] == 1)
    r = own.post('/shift/close', {'counted_cash': '0'})
    check('Açık masa varken kasa kapanmaz', r.status_code in (409, 422), r.text[:150])

    print('== Yönetici: ödeme bekleyenler ==')
    cli('1', 'yagiz@dnlstation.com.tr', 'Yağız Denli', 'Cok.Guclu-Yonetici-77')
    a = Client(); a.refresh_csrf()
    r = a.post('/admin/auth/login', {'email': 'yagiz@dnlstation.com.tr', 'password': 'Cok.Guclu-Yonetici-77'})
    a.csrf = d(r)['csrf']; secret = d(r)['secret']
    a.csrf = d(a.post('/admin/auth/verify', {'code': totp(secret)}))['csrf']
    bid = d(a.get('/admin/businesses'))['items'][0]['id']
    ov = d(a.get('/admin/overview'))
    check('Denemedeki işletme ödeme bekleyenlerde değil', all(b['id'] != bid for b in ov['awaiting']))
    sql("UPDATE businesses SET trial_ends_at='2020-01-01 00:00:00' WHERE id=?", (bid,))
    ov = d(a.get('/admin/overview'))
    check('Süresi dolan işletme ödeme bekleyenlerde', any(b['id'] == bid for b in ov['awaiting']), str(ov.get('awaiting'))[:150])
    a.post(f'/admin/businesses/{bid}/payments', {'plan': 'pro', 'period': 'aylik', 'months': 1, 'amount': '1590', 'method': 'havale'})
    ov = d(a.get('/admin/overview'))
    check('Ödeme alınınca listeden çıkar', all(b['id'] != bid for b in ov['awaiting']))
finally:
    srv.stop()
sys.exit(summary())
