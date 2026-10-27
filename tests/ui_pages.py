"""Tanıtım, içerik ve yasal sayfalar: hata, taşma, başlık/meta, yapılandırılmış veri, iç bağlantılar."""
import os, json, re
from playwright.sync_api import sync_playwright
from lib import *

PAGES = ['/', '/playstation-kafe-programi/', '/bilardo-salonu-programi/', '/okey-salonu-programi/', '/oyun-salonu-yonetim-sistemi/',
         '/kvkk/', '/gizlilik/', '/kullanim-sartlari/', '/uye-ol/', '/giris/']
SHOTS = os.path.join(TMP, 'shots')
srv = Server()
try:
    srv.migrate()
    os.makedirs(SHOTS, exist_ok=True)
    with sync_playwright() as p:
        b = p.chromium.launch()
        titles = set(); descs = set()
        for label, vp in [('desktop', {'width': 1440, 'height': 900}), ('mobile', {'width': 390, 'height': 844})]:
            ctx = b.new_context(viewport=vp, is_mobile=label == 'mobile', locale='tr-TR')
            for path in PAGES:
                pg = ctx.new_page(); errs = []
                pg.on('pageerror', lambda e: errs.append(str(e)))
                r = pg.goto(BASE + path); pg.wait_for_timeout(300)
                ok = r.status == 200 and not errs and not pg.evaluate('document.documentElement.scrollWidth > innerWidth + 1')
                check(f'[{label}] {path} yüklenir, hata/taşma yok', ok, (r.status, errs))
                if label == 'desktop':
                    t = pg.title(); d = pg.get_attribute('meta[name=description]', 'content') or ''
                    h1 = pg.locator('h1').count()
                    can = pg.get_attribute('link[rel=canonical]', 'href') or ''
                    ld = [json.loads(x) for x in pg.locator('script[type="application/ld+json"]').all_inner_texts()]
                    check(f'{path} başlık ≤65, açıklama 70-165 karakter, tek H1, canonical', len(t) <= 65 and 70 <= len(d) <= 165 and h1 == 1 and can.endswith(path), (len(t), len(d), h1, can))
                    if path not in ('/uye-ol/', '/giris/'):
                        check(f'{path} yapılandırılmış veri geçerli JSON', len(ld) >= 1)
                    titles.add(t); descs.add(d)
                    if path.endswith('programi/') or path.endswith('sistemi/'):
                        pg.screenshot(path=f'{SHOTS}/page-{path.strip("/")}.png', full_page=False)
                pg.close()
            ctx.close()
        check('Tüm başlık ve açıklamalar benzersiz', len(titles) == len(PAGES) and len(descs) == len(PAGES))
        b.close()
    sm = requests.get(BASE + '/sitemap.xml').text
    check('Site haritası yeni sayfaları içerir', all(('https://dnlstation.com.tr' + x) in sm for x in PAGES if x not in ('/giris/',)))
    rb = requests.get(BASE + '/robots.txt').text
    check('robots.txt panel/yönetim/api taramasını kapatır, site haritasını gösterir', 'Disallow: /panel/' in rb and 'Disallow: /api/' in rb and 'Sitemap:' in rb)
finally:
    srv.stop()
sys.exit(summary())
