"""Son kontrol: statik denetimler + tarayıcı denetimleri"""
import os, re, sys, json, subprocess, time
from html.parser import HTMLParser
from playwright.sync_api import sync_playwright

ROOT = os.path.abspath(sys.argv[1]); SHOTS = sys.argv[2]; os.makedirs(SHOTS, exist_ok=True)
PAGES = ['index.html', 'giris/index.html', 'uye-ol/index.html', '404.html']
issues = []

class P(HTMLParser):
    def __init__(s):
        super().__init__(); s.ids = []; s.refs = []; s.imgs = []; s.heads = []; s.anchors = []; s._h = None
    def handle_starttag(s, t, a):
        a = dict(a)
        if 'id' in a: s.ids.append(a['id'])
        for k in ('href', 'src'):
            v = a.get(k)
            if v and t != 'a' or (t == 'a' and v): s.refs.append((t, k, v))
        if t == 'img': s.imgs.append(a)
        if re.fullmatch(r'h[1-6]', t): s.heads.append(int(t[1]))
        if t == 'a' and a.get('href', '').startswith('#') and len(a['href']) > 1: s.anchors.append(a['href'][1:])

for page in PAGES:
    html = open(os.path.join(ROOT, page), encoding='utf-8').read()
    p = P(); p.feed(html)
    dup = {i for i in p.ids if p.ids.count(i) > 1}
    if dup: issues.append(f'{page}: tekrar eden id {dup}')
    for a in set(p.anchors):
        if a not in p.ids: issues.append(f'{page}: #{a} hedefi yok')
    for t, k, v in p.refs:
        if v.startswith(('http', 'mailto:', '#', 'data:')) or v is None: continue
        path = v.split('?')[0].split('#')[0]
        if not path: continue
        fs = os.path.join(ROOT, path.lstrip('/'))
        if path.endswith('/'): fs = os.path.join(fs, 'index.html')
        if not os.path.exists(fs): issues.append(f'{page}: kırık yerel bağlantı {v}')
    for im in p.imgs:
        if 'alt' not in im: issues.append(f'{page}: alt eksik {im.get("src")}')
        if not im.get('width') or not im.get('height'): issues.append(f'{page}: boyut eksik {im.get("src")}')
    if p.heads.count(1) != 1: issues.append(f'{page}: h1 sayısı {p.heads.count(1)}')
    for m in re.finditer(r'<script type="application/ld\+json">(.*?)</script>', html, re.S):
        try: json.loads(m.group(1))
        except Exception as e: issues.append(f'{page}: JSON-LD hatalı {e}')
    title = re.search(r'<title>(.*?)</title>', html).group(1)
    desc = re.search(r'name="description" content="(.*?)"', html)
    if len(title) > 65: issues.append(f'{page}: title uzun ({len(title)})')
    if desc and not (70 <= len(desc.group(1)) <= 165): issues.append(f'{page}: description uzunluğu {len(desc.group(1))}')
    if re.search(r'\b(dene|incele|yaz|takip et)\b[.!]?\s*<', html): issues.append(f'{page}: sen dili kalıntısı olabilir')

# contrast of key text tokens on bg
def lum(h):
    h = h.lstrip('#'); c = [int(h[i:i+2], 16) / 255 for i in (0, 2, 4)]
    c = [x / 12.92 if x <= .03928 else ((x + .055) / 1.055) ** 2.4 for x in c]
    return .2126 * c[0] + .7152 * c[1] + .0722 * c[2]
def cr(a, b): la, lb = sorted([lum(a), lum(b)], reverse=True); return round((la + .05) / (lb + .05), 2)
contrast = {k: cr(v, '#09090D') for k, v in {'muted': '#9A97AB', 'muted-2': '#858298', 'muted-3': '#827F96', 'lilac': '#A796FF'}.items()}
contrast['btn-white-on-violet'] = cr('#FFFFFF', '#6F52F2')
for k, v in contrast.items():
    if v < 4.5: issues.append(f'kontrast düşük: {k} {v}')

# sitemap vs files
sm = open(os.path.join(ROOT, 'sitemap.xml'), encoding='utf-8').read()
for loc in re.findall(r'<loc>https://dnlstation\.com\.tr(.*?)</loc>', sm):
    if not os.path.exists(os.path.join(ROOT, loc.lstrip('/'), 'index.html')): issues.append(f'sitemap: dosya yok {loc}')

srv = subprocess.Popen([sys.executable, '-m', 'http.server', '8768', '--directory', ROOT], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL); time.sleep(1)
runtime = {}
try:
    with sync_playwright() as pw:
        br = pw.chromium.launch()
        for name, vp, mob in [('desktop', (1440, 900), False), ('mobile', (390, 844), True)]:
            for page in ['/', '/giris/', '/uye-ol/', '/404.html']:
                ctx = br.new_context(viewport={'width': vp[0], 'height': vp[1]}, is_mobile=mob, has_touch=mob)
                ctx.route('**/fonts.g*/**', lambda r: r.abort())
                pg = ctx.new_page(); errs = []; bad = []
                pg.on('pageerror', lambda e: errs.append(str(e)))
                pg.on('response', lambda r: r.status >= 400 and bad.append(f'{r.status} {r.url}'))
                pg.goto('http://127.0.0.1:8768' + page, wait_until='networkidle'); pg.wait_for_timeout(500)
                pg.evaluate('document.querySelectorAll("img[loading=lazy]").forEach(i=>i.loading="eager")'); pg.wait_for_timeout(700)
                broken = pg.evaluate('[...document.images].filter(i=>i.complete && i.naturalWidth===0).map(i=>i.src)')
                ov = pg.evaluate('document.documentElement.scrollWidth > window.innerWidth')
                unnamed = pg.evaluate('[...document.querySelectorAll("a,button")].filter(e=>e.offsetParent!==null && !(e.innerText.trim()||e.getAttribute("aria-label"))).length')
                small = pg.evaluate('[...document.querySelectorAll("a.btn,button,.hb,.wordmark")].filter(e=>e.offsetParent!==null&&e.getBoundingClientRect().height<40).map(e=>e.outerHTML.slice(0,60))')
                if errs or bad or broken or ov or unnamed or small:
                    runtime[f'{name}{page}'] = {'js': errs, 'http': [b for b in bad if 'yok-boyle' not in b], 'broken_img': broken, 'overflow': ov, 'unnamed_controls': unnamed, 'small_targets': small}
                if page == '/':
                    pg.screenshot(path=f'{SHOTS}/final-{name}-top.png')
                ctx.close()
        # JS kapalıyken ana sayfa içeriği görünür mü
        ctx = br.new_context(java_script_enabled=False, viewport={'width': 1440, 'height': 900}); pg = ctx.new_page()
        pg.goto('http://127.0.0.1:8768/'); runtime['nojs_pricing_visible'] = pg.is_visible('#fiyat .plan')
        br.close()
finally:
    srv.terminate()
print(json.dumps({'static_issues': issues, 'contrast': contrast, 'runtime': runtime}, ensure_ascii=False, indent=1))
