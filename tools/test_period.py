import sys, json, subprocess, time
from playwright.sync_api import sync_playwright
root, shots = sys.argv[1], sys.argv[2]
srv = subprocess.Popen([sys.executable, '-m', 'http.server', '8770', '--directory', root], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL); time.sleep(1)
res = {}
try:
    with sync_playwright() as p:
        br = p.chromium.launch()
        ctx = br.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True)
        ctx.route('**/fonts.g*/**', lambda r: r.abort())
        pg = ctx.new_page(); errs = []; pg.on('pageerror', lambda e: errs.append(str(e)))
        # ana sayfa: aylık seç -> Başlangıç linki
        pg.goto('http://127.0.0.1:8770/', wait_until='load')
        pg.evaluate('document.querySelectorAll(".rv").forEach(e=>e.classList.add("in"))')
        pg.click('[data-bill="m"]')
        res['home_link'] = pg.get_attribute('.plan[data-m="159"] a.btn', 'href')
        pg.click('.plan[data-m="159"] a.btn'); pg.wait_for_load_state('load'); pg.wait_for_timeout(500)
        res['url'] = pg.url.split('8770')[1]
        res['form_period'] = pg.evaluate('document.querySelector("input[name=donem]:checked").value')
        res['form_plan'] = pg.evaluate('document.querySelector("input[name=paket]:checked").value')
        res['prices_monthly'] = pg.evaluate('[...document.querySelectorAll(".plan-pick small")].map(s=>s.textContent)')
        res['sum_monthly'] = pg.inner_text('#plan-sum')
        pg.click('.period-pick label:nth-child(2)'); pg.wait_for_timeout(200)
        res['prices_yearly'] = pg.evaluate('[...document.querySelectorAll(".plan-pick small")].map(s=>s.textContent)')
        pg.click('.plan-pick label:nth-child(2)'); pg.wait_for_timeout(200)
        res['sum_yearly_pro'] = pg.inner_text('#plan-sum')
        pg.locator('.period-pick').scroll_into_view_if_needed(); pg.evaluate('window.scrollBy(0,-80)'); pg.wait_for_timeout(300)
        pg.screenshot(path=f'{shots}/period-mobile.png')
        res['overflow'] = pg.evaluate('document.documentElement.scrollWidth > window.innerWidth')
        res['errors'] = errs
        br.close()
finally:
    srv.terminate()
print(json.dumps(res, ensure_ascii=False, indent=1))
