"""Yönetim paneli tarayıcı testi: 2FA kurulum, işletme yönetimi, duyurunun işletme panelinde görünmesi."""
import os, re
from playwright.sync_api import sync_playwright
from lib import *
from test_admin_helpers import cli, totp

SHOTS = os.path.join(TMP, 'shots')
srv = Server()
try:
    srv.migrate()
    os.makedirs(SHOTS, exist_ok=True)
    c = Client(); register(c)
    rc, out = cli('1', 'yagiz@dnlstation.com.tr', 'Yağız Denli', 'Cok.Guclu-Yonetici-77')
    check('CLI yönetici', rc == 0, out)
    with sync_playwright() as p:
        b = p.chromium.launch()
        for label, vp, mobile in [('desktop', {'width': 1440, 'height': 900}, False), ('mobile', {'width': 390, 'height': 844}, True)]:
            print(f'== {label} ==')
            ctx = b.new_context(viewport=vp, is_mobile=mobile, has_touch=mobile, locale='tr-TR', timezone_id='Europe/Istanbul')
            page = ctx.new_page(); errors = []
            page.on('pageerror', lambda e: errors.append(str(e)))
            page.on('console', lambda m: errors.append(m.text) if m.type == 'error' and 'ERR_TUNNEL' not in m.text and 'status of 401' not in m.text and 'ERR_CONNECTION_REFUSED' not in m.text else None)
            page.goto(BASE + '/yonetim/')
            page.fill('input[name=email]', 'yagiz@dnlstation.com.tr'); page.fill('input[name=password]', 'Cok.Guclu-Yonetici-77')
            page.click('button:has-text("Devam")')
            page.wait_for_selector('input[name=code]')
            if page.locator('code.secret').count():
                secret = page.locator('code.secret').inner_text().replace(' ', '')
                os.environ['ADM_SECRET'] = secret
                page.screenshot(path=f'{SHOTS}/adm-{label}-setup.png', full_page=True)
                check(f'[{label}] Kurulum ekranı: anahtar + Authenticator bağlantısı', page.locator('a[href^="otpauth://"]').count() == 1)
            else:
                secret = os.environ['ADM_SECRET']
                import time; time.sleep(30 - time.time() % 30 + 0.5)  # aynı kod iki kez kullanılamaz
            page.fill('input[name=code]', totp(secret))
            page.wait_for_selector('.kpis', timeout=10000)
            check(f'[{label}] 2 adımlı giriş → genel bakış', page.locator('.kpi').count() >= 6)
            page.screenshot(path=f'{SHOTS}/adm-{label}-genel.png', full_page=True)
            page.goto(BASE + '/yonetim/#/isletmeler'); page.wait_for_selector('.biz-row')
            page.locator('.biz-row').first.click(); page.wait_for_selector('text=Abonelik')
            page.click('button:has-text("Ödeme al")'); page.wait_for_selector('.modal input[name=amount]')
            page.click('.modal button:has-text("Ödemeyi kaydet")'); page.wait_for_selector('.toast.good', timeout=8000)
            page.wait_for_timeout(300)
            check(f'[{label}] Ödeme kaydedilir, işletme aktif olur', page.locator('.card .tag.ok', has_text='Aktif').count() >= 1)
            page.screenshot(path=f'{SHOTS}/adm-{label}-detay.png', full_page=True)
            over = page.evaluate('document.documentElement.scrollWidth > window.innerWidth + 1')
            check(f'[{label}] Detay sayfasında yatay taşma yok', not over)
            for r in ['odemeler', 'duyuru', 'kayitlar', 'hesap', 'isletmeler']:
                page.goto(BASE + '/yonetim/#/' + r); page.wait_for_timeout(600)
                check(f'[{label}] #{r} taşma yok', not page.evaluate('document.documentElement.scrollWidth > window.innerWidth + 1'))
            check(f'[{label}] Sayfa/konsol hatası yok', not errors, errors[:4])
            if not mobile:
                page.goto(BASE + '/yonetim/#/duyuru'); page.wait_for_selector('textarea[name=text]')
                page.fill('textarea[name=text]', 'Yeni özellik: hızlı satış ekranı yenilendi.')
                page.check('input[name=active]', force=True); page.click('button:has-text("Kaydet")'); page.wait_for_selector('.toast.good')
            ctx.close()
        # Duyuru işletme panelinde
        ctx = b.new_context(viewport={'width': 1280, 'height': 800})
        pg = ctx.new_page()
        pg.goto(BASE + '/giris/'); pg.fill('input[name=email]', 'sahip@arena.test'); pg.fill('input[name=password]', 'Guclu.Sifre-2026')
        pg.click('form button[type=submit]'); pg.wait_for_url('**/panel/**'); pg.wait_for_selector('.banner.info', timeout=8000)
        check('Duyuru işletme panelinde görünür', 'hızlı satış' in pg.locator('.banner.info').inner_text())
        pg.click('.banner.info button'); pg.reload(); pg.wait_for_selector('.top')
        pg.wait_for_timeout(500)
        check('Kapatılan duyuru tekrar gösterilmez', pg.locator('.banner.info').count() == 0)
        ctx.close(); b.close()
finally:
    srv.stop()
sys.exit(summary())
