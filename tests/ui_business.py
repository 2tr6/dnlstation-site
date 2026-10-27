"""Tarayıcı testi: müşteri + veresiye ile hesap kapatma, rezervasyon, gider, fiş, sistem sayfaları."""
import os, datetime
from playwright.sync_api import sync_playwright
from lib import *

SHOTS = os.path.join(TMP, 'shots')
srv = Server()
try:
    srv.migrate()
    os.makedirs(SHOTS, exist_ok=True)
    c = Client(); register(c); c.me()
    c.post('/setup/quick', {'items': [{'kind': 'playstation', 'count': 4, 'hour_price': '100', 'match_price': '25'}]})
    c.post('/shift/open', {'opening_cash': '500'})
    with sync_playwright() as p:
        b = p.chromium.launch()
        for label, vp, mobile in [('desktop', {'width': 1440, 'height': 900}, False), ('mobile', {'width': 390, 'height': 844}, True)]:
            print(f'== {label} ==')
            ctx = b.new_context(viewport=vp, is_mobile=mobile, has_touch=mobile, locale='tr-TR', timezone_id='Europe/Istanbul')
            pg = ctx.new_page(); errs = []
            pg.on('pageerror', lambda e: errs.append(str(e)))
            pg.on('dialog', lambda d: d.dismiss())
            pg.goto(BASE + '/giris/'); pg.fill('input[name=email]', 'sahip@arena.test'); pg.fill('input[name=password]', 'Guclu.Sifre-2026')
            pg.click('form button[type=submit]'); pg.wait_for_url('**/panel/**'); pg.wait_for_selector('.tcard')
            # Müşteri ekle
            name = f'Ahmet {label}'
            pg.goto(BASE + '/panel/#/musteriler'); pg.wait_for_selector('button:has-text("Müşteri ekle")')
            pg.click('button:has-text("Müşteri ekle")'); pg.fill('.modal input[name=name]', name); pg.click('.modal button:has-text("Kaydet")')
            pg.wait_for_selector(f'.cust-list b:has-text("{name}")', timeout=8000)
            check(f'[{label}] Müşteri eklenir', True)
            # Masa aç → maç başı 2 maç → veresiye ile kapat
            pg.goto(BASE + '/panel/#/masalar'); pg.wait_for_selector('.tcard.free')
            pg.locator('.tcard.free').first.click(); pg.click('.modal .seg button:has-text("Maç başı")'); pg.click('.modal button:has-text("Masayı aç")')
            pg.wait_for_selector('.tcard.busy .tc-time', timeout=8000)
            pg.locator('.tcard.busy .tc-time').first.click(); pg.wait_for_selector('.ss-hero')
            pg.click('.modal button[aria-label="Maç ekle"]'); pg.wait_for_timeout(500); pg.click('.modal button[aria-label="Maç ekle"]'); pg.wait_for_timeout(500)
            pg.click('.modal button:has-text("Hesap al")'); pg.wait_for_selector('.co-total')
            pg.click('.modal .seg button:has-text("Veresiye")'); pg.wait_for_selector('.cust-pick')
            pg.click(f'.pick-row:has-text("{name}")'); pg.wait_for_selector('.cust-chosen')
            pg.screenshot(path=f'{SHOTS}/biz-{label}-veresiye.png')
            pg.click('.modal button:has-text("Ödemeyi al ve kapat")'); pg.wait_for_selector('.toast.good:has-text("Hesap kapatıldı")', timeout=8000)
            pg.goto(BASE + '/panel/#/musteriler'); pg.wait_for_selector('.cust-list')
            check(f'[{label}] Veresiye müşterinin borcuna yazılır', 'Borç ₺50,00' in pg.locator(f'.cust-list li:has-text("{name}")').inner_text())
            # Son hesaplardan fiş
            pg.goto(BASE + '/panel/#/masalar'); pg.wait_for_selector('.tcard')
            pg.click('button:has-text("Son hesaplar")'); pg.wait_for_selector('.modal button[aria-label="Fiş yazdır"]')
            pg.click('.modal button[aria-label="Fiş yazdır"]'); pg.wait_for_timeout(600)
            check(f'[{label}] Fiş yazdırma hatasız', not errs, errs)
            pg.keyboard.press('Escape')
            # Rezervasyon
            pg.goto(BASE + '/panel/#/rezervasyon'); pg.wait_for_selector('button:has-text("Rezervasyon ekle")')
            pg.locator('.tb-r button:has-text("Rezervasyon ekle")').click(); pg.fill('.modal input[name=customer_name]', 'Mehmet ' + label)
            pg.fill('.modal input[name=time]', '23:30'); pg.click('.modal button:has-text("Kaydet")')
            pg.wait_for_selector(f'.res:has-text("Mehmet {label}")', timeout=8000)
            check(f'[{label}] Rezervasyon eklenir', True)
            pg.screenshot(path=f'{SHOTS}/biz-{label}-rezervasyon.png', full_page=True)
            # Gider
            pg.goto(BASE + '/panel/#/giderler'); pg.wait_for_selector('button:has-text("Gider ekle")')
            pg.click('.tb-r button:has-text("Gider ekle")'); pg.fill('.modal input[name=amount]', '12,5'); pg.click('.modal button:has-text("Kaydet")')
            pg.wait_for_selector('.toast.good:has-text("Gider kaydedildi")', timeout=8000)
            check(f'[{label}] Kasadan gider eklenir', '₺12,50' in pg.locator('main').inner_text())
            for r in ['musteriler', 'giderler', 'rezervasyon', 'raporlar', 'vardiya']:
                pg.goto(BASE + '/panel/#/' + r); pg.wait_for_timeout(600)
                check(f'[{label}] #{r} taşma yok', not pg.evaluate('document.documentElement.scrollWidth > innerWidth + 1'))
            pg.screenshot(path=f'{SHOTS}/biz-{label}-kasa.png', full_page=True)
            check(f'[{label}] Sayfa hatası yok', not errs, errs[:3])
            ctx.close()
        b.close()
finally:
    srv.stop()
sys.exit(summary())
