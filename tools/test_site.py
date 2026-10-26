import sys, json, subprocess, time, os
from playwright.sync_api import sync_playwright

root, shots = sys.argv[1], sys.argv[2]
os.makedirs(shots, exist_ok=True)
srv = subprocess.Popen([sys.executable, '-m', 'http.server', '8765', '--directory', root], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1)
B = 'http://127.0.0.1:8765'
res = {}
try:
    with sync_playwright() as p:
        br = p.chromium.launch()
        def page(w, h, mobile=False):
            ctx = br.new_context(viewport={'width': w, 'height': h}, is_mobile=mobile, has_touch=mobile, device_scale_factor=1)
            ctx.route('**/fonts.googleapis.com/**', lambda r: r.abort())
            ctx.route('**/fonts.gstatic.com/**', lambda r: r.abort())
            pg = ctx.new_page(); errs = []
            pg.on('pageerror', lambda e: errs.append(str(e)))
            pg.on('console', lambda m: m.type == 'error' and 'fonts' not in m.text and 'ERR_FAILED' not in m.text and errs.append(m.text))
            return pg, errs

        # Desktop home
        pg, errs = page(1440, 900)
        pg.goto(B + '/', wait_until='load'); pg.wait_for_timeout(2500)
        pg.screenshot(path=f'{shots}/home-desktop-hero.png')
        res['revenue_ticks'] = pg.inner_text('#revenue')
        pg.click('[data-mode="match"]'); pg.click('[data-kol="2"]')
        res['tariff_match_2kol'] = pg.inner_text('#total') + ' | ' + pg.inner_text('#breakdown')
        pg.click('[data-bill="m"]')
        res['pro_monthly'] = pg.inner_text('.plan.ring .price')
        pg.evaluate('document.querySelectorAll(".rv").forEach(e=>e.classList.add("in"))')
        pg.locator('#fiyat').scroll_into_view_if_needed(); pg.wait_for_timeout(900)
        pg.screenshot(path=f'{shots}/home-desktop-pricing.png')
        res['h1_count'] = pg.locator('h1').count()
        res['overflow_x_desktop'] = pg.evaluate('document.documentElement.scrollWidth > window.innerWidth')
        res['errors_desktop'] = errs

        # Mobile home + menu
        pg, errs = page(390, 844, True)
        pg.goto(B + '/', wait_until='load'); pg.wait_for_timeout(1500)
        pg.screenshot(path=f'{shots}/home-mobile-hero.png')
        res['hb_visible'] = pg.is_visible('.hb')
        pg.click('.hb'); pg.wait_for_timeout(700)
        res['menu_open'] = pg.is_visible('#mmenu')
        pg.screenshot(path=f'{shots}/home-mobile-menu.png')
        pg.click('#mmenu a[href="#fiyat"]'); pg.wait_for_timeout(600)
        res['menu_closed_after_link'] = not pg.is_visible('#mmenu')
        res['overflow_x_mobile'] = pg.evaluate('document.documentElement.scrollWidth > window.innerWidth')
        pg.evaluate('document.querySelectorAll(".rv").forEach(e=>e.classList.add("in"))'); pg.wait_for_timeout(900)
        pg.screenshot(path=f'{shots}/home-mobile-pricing.png')
        res['errors_mobile'] = errs

        # Signup
        pg, errs = page(1440, 1000)
        pg.goto(B + '/uye-ol/?paket=salon', wait_until='load'); pg.wait_for_timeout(1200)
        res['preselected_plan'] = pg.evaluate('document.querySelector("input[name=paket]:checked").value')
        pg.fill('#u-pw', 'Deneme123!'); pg.fill('#u-pw2', 'Deneme12')
        pg.click('button[type=submit]'); pg.wait_for_timeout(300)
        res['mismatch_hint'] = pg.inner_text('#pw2-hint')
        res['strength'] = pg.inner_text('#pw-meter output')
        pg.fill('#u-pw2', 'Deneme123!')
        res['match_hint'] = pg.inner_text('#pw2-hint')
        for sel, v in [('#u-name', 'Test Kişi'), ('#u-biz', 'Arena'), ('#u-tel', '05000000000'), ('#u-mail', 'a@b.com')]: pg.fill(sel, v)
        pg.check('input[name=onay]', force=True)
        pg.click('button[type=submit]'); pg.wait_for_timeout(400)
        res['signup_notice'] = pg.inner_text('#notice')[:60]
        pg.screenshot(path=f'{shots}/signup-desktop.png', full_page=True)
        res['errors_signup'] = errs

        pg, errs = page(390, 844, True)
        pg.goto(B + '/uye-ol/', wait_until='load'); pg.wait_for_timeout(1000)
        res['overflow_x_signup_mobile'] = pg.evaluate('document.documentElement.scrollWidth > window.innerWidth')
        pg.screenshot(path=f'{shots}/signup-mobile.png', full_page=True)

        pg, errs = page(1440, 900)
        pg.goto(B + '/giris/', wait_until='load'); pg.wait_for_timeout(1000)
        pg.screenshot(path=f'{shots}/login-desktop.png')
        res['errors_login'] = errs
        pg.goto(B + '/yok-boyle-sayfa', wait_until='load')
        br.close()
finally:
    srv.terminate()
print(json.dumps(res, ensure_ascii=False, indent=1))
