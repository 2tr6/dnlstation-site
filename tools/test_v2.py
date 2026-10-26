import sys, json, subprocess, time, os
from playwright.sync_api import sync_playwright

root, shots = sys.argv[1], sys.argv[2]
os.makedirs(shots, exist_ok=True)
srv = subprocess.Popen([sys.executable, '-m', 'http.server', '8766', '--directory', root], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1)
B = 'http://127.0.0.1:8766'
res = {}
try:
    with sync_playwright() as p:
        br = p.chromium.launch()
        def page(w, h, mobile=False):
            ctx = br.new_context(viewport={'width': w, 'height': h}, is_mobile=mobile, has_touch=mobile)
            ctx.route('**/fonts.g*/**', lambda r: r.abort())
            pg = ctx.new_page(); errs = []
            pg.on('pageerror', lambda e: errs.append(str(e)))
            return pg, errs

        # --- Mobile: scroll lock ---
        pg, errs = page(390, 844, True)
        pg.goto(B + '/', wait_until='load'); pg.wait_for_timeout(800)
        pg.evaluate('window.scrollTo(0, 1500)'); pg.wait_for_timeout(600)
        y0 = pg.evaluate('window.scrollY')
        pg.click('.hb'); pg.wait_for_timeout(500)
        hdr_top = pg.evaluate('document.querySelector(".site-header").getBoundingClientRect().top')
        pg.mouse.wheel(0, 900); pg.wait_for_timeout(400)
        # try to scroll the document programmatically like a touch-scroll would
        pg.evaluate('document.scrollingElement.scrollTop += 700'); pg.wait_for_timeout(300)
        res['scroll_while_open'] = pg.evaluate('window.scrollY')
        res['header_visible_top'] = hdr_top
        pg.click('.hb'); pg.wait_for_timeout(500)
        res['restored_y'] = [y0, pg.evaluate('window.scrollY')]
        # menu link navigation
        pg.click('.hb'); pg.wait_for_timeout(400)
        pg.click('#mmenu a[href="#fiyat"]'); pg.wait_for_timeout(1500)
        res['fiyat_top_after_link'] = round(pg.evaluate('document.getElementById("fiyat").getBoundingClientRect().top'))
        res['menu_hidden'] = pg.evaluate('document.getElementById("mmenu").hidden')
        res['html_unlocked'] = not pg.evaluate('document.documentElement.classList.contains("menu-open")')
        # gallery on mobile
        pg.evaluate('document.querySelectorAll(".rv").forEach(e=>e.classList.add("in"))')
        pg.locator('#panel').scroll_into_view_if_needed(); pg.wait_for_timeout(800)
        pg.screenshot(path=f'{shots}/m-panel.png')
        pg.locator('#mobil').scroll_into_view_if_needed(); pg.wait_for_timeout(800)
        pg.screenshot(path=f'{shots}/m-mobil.png')
        res['overflow_x_mobile'] = pg.evaluate('document.documentElement.scrollWidth > window.innerWidth')
        res['errors_mobile'] = errs

        # --- Desktop: gallery ---
        pg, errs = page(1440, 900)
        pg.goto(B + '/', wait_until='load'); pg.wait_for_timeout(600)
        pg.evaluate('document.querySelectorAll(".rv").forEach(e=>e.classList.add("in"))')
        pg.locator('#panel').scroll_into_view_if_needed(); pg.wait_for_timeout(1200)
        pg.click('#tab-rapor'); pg.wait_for_timeout(900)
        res['gallery_active_img'] = pg.evaluate('document.querySelector(".shot-view img.on").getAttribute("src")')
        res['gallery_cap'] = pg.inner_text('.shot-cap')[:30]
        res['imgs_loaded'] = pg.evaluate('[...document.querySelectorAll(".shot-view img")].map(i=>i.complete && i.naturalWidth>0)')
        pg.evaluate('window.scrollBy(0,-60)'); pg.wait_for_timeout(300)
        pg.screenshot(path=f'{shots}/d-panel.png')
        pg.locator('#mobil').scroll_into_view_if_needed(); pg.wait_for_timeout(900)
        pg.screenshot(path=f'{shots}/d-mobil.png')
        res['overflow_x_desktop'] = pg.evaluate('document.documentElement.scrollWidth > window.innerWidth')
        res['errors_desktop'] = errs

        # signup input font size (iOS zoom guard)
        pg, errs = page(390, 844, True)
        pg.goto(B + '/uye-ol/', wait_until='load')
        res['input_font_px'] = pg.evaluate('getComputedStyle(document.querySelector(".inp")).fontSize')
        res['errors_signup'] = errs
        br.close()
finally:
    srv.terminate()
print(json.dumps(res, ensure_ascii=False, indent=1))
