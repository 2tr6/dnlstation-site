import sys, json, subprocess, time
from playwright.sync_api import sync_playwright
root, shots = sys.argv[1], sys.argv[2]
srv = subprocess.Popen([sys.executable, '-m', 'http.server', '8769', '--directory', root], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL); time.sleep(1)
res = {}
try:
    with sync_playwright() as p:
        br = p.chromium.launch()
        for name, vp, mob in [('desktop', (1440, 900), False), ('mobile', (390, 844), True)]:
            ctx = br.new_context(viewport={'width': vp[0], 'height': vp[1]}, is_mobile=mob, has_touch=mob)
            ctx.route('**/fonts.g*/**', lambda r: r.abort())
            pg = ctx.new_page(); errs = []
            pg.on('pageerror', lambda e: errs.append(str(e)))
            pg.goto('http://127.0.0.1:8769/', wait_until='load')
            pg.evaluate('document.querySelectorAll(".rv").forEach(e=>e.classList.add("in")); document.querySelectorAll("img[loading=lazy]").forEach(i=>i.loading="eager")')
            pg.locator('#panel .shot').scroll_into_view_if_needed(); pg.wait_for_timeout(1200)
            out = []
            for tab in ['#tab-masalar', '#tab-hesap', '#tab-kafe', '#tab-rapor', '#tab-tv']:
                pg.click(tab); pg.wait_for_timeout(650)
                out.append(pg.evaluate('(()=>{const i=document.querySelector(".shot-view img.on");return {src:i.currentSrc.split("/").pop(),ok:i.naturalWidth>0}})()'))
                if tab == '#tab-tv' or tab == '#tab-hesap':
                    pg.locator('#panel .shot').scroll_into_view_if_needed(); pg.wait_for_timeout(300)
                    pg.screenshot(path=f'{shots}/gal-{name}-{tab[5:]}.png')
            res[name] = {'shots': out, 'cap_tv': pg.inner_text('.shot-cap')[:60], 'errors': errs,
                         'overflow': pg.evaluate('document.documentElement.scrollWidth > window.innerWidth')}
            ctx.close()
        br.close()
finally:
    srv.terminate()
print(json.dumps(res, ensure_ascii=False, indent=1))
