"""Mobil panel demo ekranları -> site/assets/img/demo/*-m.webp (780x1688)"""
import os, sys, io
from playwright.sync_api import sync_playwright
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
SITE = os.path.abspath(sys.argv[1])
OUT = os.path.join(SITE, 'assets', 'img', 'demo')
TV_IMG = os.path.join(OUT, 'musteri-ekrani.webp')

I = {
 'grid': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
 'cup': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/></svg>',
 'chart': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15v2"/><path d="M11 11v6"/><path d="M15 7v10"/><path d="M19 12v5"/></svg>',
 'receipt': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6"/><path d="M16 12h-6"/></svg>',
 'more': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg>',
 'back': '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>',
 'cash': '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg>',
 'card': '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
}

def page(body):
    return f'<!doctype html><html lang="tr"><head><meta charset="utf-8"><link rel="stylesheet" href="file://{HERE}/panel.css"><style>body{{width:390px;height:844px;overflow:hidden}} .ph{{width:390px;height:844px;display:flex;flex-direction:column;background:linear-gradient(180deg,#141224,#0B0A12 38%)}} .pc{{flex:1;padding:52px 16px 0;display:flex;flex-direction:column;gap:12px;min-height:0;overflow:hidden}} .hdr{{display:flex;justify-content:space-between;align-items:center}} .hdr small{{font-size:12px;color:#9C97B5}} .hdr b{{display:block;font-size:23px;letter-spacing:-.02em}} .ibtn{{width:38px;height:38px;border-radius:11px;background:#14121E;border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:#C9C4DE}} .ibtn svg{{width:18px;height:18px}}</style></head><body>{body}</body></html>'

def tabbar(active):
    items = [('grid', 'Masalar'), ('cup', 'Kafeterya'), ('receipt', 'Kasa'), ('chart', 'Raporlar'), ('more', 'Diğer')]
    out = ''
    for k, l in items:
        on = l == active
        out += f'<div style="display:flex;flex-direction:column;align-items:center;gap:4px;font-size:10.5px;color:{"#fff" if on else "#77728F"}"><span style="width:22px;height:22px;color:{"#B8A6FF" if on else "#77728F"}">{I[k]}</span>{l}</div>'
    return f'<div style="display:flex;justify-content:space-around;padding:10px 6px 28px;border-top:1px solid rgba(255,255,255,.07);background:#0E0C17">{out}</div>'

def mcard(n, kind, tag, time, sub, amt, pct=None):
    c = {'': ('rgba(106,69,245,.11)', 'rgba(139,108,255,.28)', '#fff', 'rgba(139,108,255,.22)', '#D6CCFF'),
         'warn': ('rgba(34,211,238,.09)', 'rgba(34,211,238,.45)', '#CFF8FD', 'rgba(34,211,238,.16)', '#7EE8F7'),
         'free': ('rgba(255,255,255,.02)', 'rgba(255,255,255,.07)', '#4F4A68', 'rgba(255,255,255,.06)', '#9C97B5'),
         'res': ('rgba(251,191,36,.06)', 'rgba(251,191,36,.28)', '#FCD58A', 'rgba(251,191,36,.14)', '#FCD58A')}[kind]
    bar = f'<div style="height:3px;border-radius:2px;background:rgba(255,255,255,.07);margin-top:6px"><i style="display:block;height:100%;width:{pct}%;border-radius:2px;background:{"#22D3EE" if kind=="warn" else "#8B6CFF"}"></i></div>' if pct is not None else ''
    fs = '18px' if kind in ('', 'warn') else '14px'
    return f'<div style="padding:11px;border-radius:13px;background:{c[0]};border:1px solid {c[1]};display:flex;flex-direction:column;justify-content:space-between;gap:8px;min-height:104px"><div style="display:flex;justify-content:space-between;align-items:center"><b style="font-size:13.5px">{n}</b><span style="font-size:10px;font-weight:600;padding:2px 6px;border-radius:99px;background:{c[3]};color:{c[4]}">{tag}</span></div><div><div class="num" style="font-size:{fs};font-weight:600;color:{c[2]}">{time}</div>{bar}</div><div style="display:flex;justify-content:space-between;font-size:11px;color:#9C97B5"><span>{sub}</span><b class="num" style="color:#fff;font-weight:600">{amt}</b></div></div>'

def masalar_m():
    cards = ''.join([
        mcard('PS5 · 1', '', '4 kol', '1:24:12', 'Açık süre', '₺390'),
        mcard('PS5 · 2', '', '2 kol', '0:32:40', 'Açık süre', '₺54'),
        mcard('PS5 · 3', 'warn', 'Bitiyor', '-04:12', '2 sa paket', '₺200', 96),
        mcard('PS5 · 4', 'free', 'Boş', 'Hazır', 'Son 21:58', '—'),
        mcard('PS5 · 5', '', 'Maç 3/5', '0:41:05', 'Maç başı', '₺135', 60),
        mcard('PS5 · 6', 'res', 'Rezerve', '23:00', 'Kaan · 4 kişi', '—'),
        mcard('Bilardo · 1', '', 'Saatlik', '0:48:30', '₺120/sa', '₺97'),
        mcard('Okey · 1', '', 'Saatlik', '2:05:44', '+ 6 çay', '₺215'),
    ])
    body = f'''<div class="ph"><div class="pc">
<div class="hdr"><div><small>Arena Game Center</small><b>Masalar</b></div><span class="ibtn">{I['more']}</span></div>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px"><div class="kpi" style="padding:10px"><small>Ciro</small><b class="num" style="font-size:16px">₺6.840</b></div><div class="kpi" style="padding:10px"><small>Dolu</small><b class="num" style="font-size:16px">12/17</b></div><div class="kpi" style="padding:10px"><small>Kafeterya</small><b class="num" style="font-size:16px">₺1.920</b></div></div>
<div class="tabs" style="margin:0"><span class="on">Tümü</span><span>PS</span><span>Bilardo</span><span>Okey</span><span>Diğer</span></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">{cards}</div>
</div>{tabbar('Masalar')}</div>'''
    return page(body)

def hesap_m():
    bg = masalar_m().split('<body>')[1].split('</body>')[0]
    sheet = f'''<div style="position:absolute;left:0;right:0;bottom:0;padding:10px 16px 30px;border-radius:24px 24px 0 0;background:#15121F;border-top:1px solid rgba(139,108,255,.35);box-shadow:0 -30px 80px rgba(0,0,0,.7)">
<div style="width:40px;height:4px;border-radius:2px;background:rgba(255,255,255,.18);margin:0 auto 14px"></div>
<div style="display:flex;justify-content:space-between;align-items:flex-start"><div><b style="font-size:19px">Hesabı kapat</b><div style="font-size:12px;color:#9C97B5;margin-top:2px">PS5 · 1 · 21:17 – 22:41 · 1 sa 24 dk</div></div><span style="font-size:11px;font-weight:600;padding:3px 8px;border-radius:99px;background:rgba(139,108,255,.22);color:#D6CCFF">4 kol</span></div>
<div class="items" style="margin-top:12px">
<div class="item"><span><b>Oyun</b>1 sa 24 dk</span><b class="num">₺225</b></div>
<div class="item"><span><b>2×</b>Çay</span><b class="num">₺30</b></div>
<div class="item"><span><b>1×</b>Kaşarlı tost</span><b class="num">₺60</b></div>
<div class="item"><span><b>1×</b>Enerji içeceği</span><b class="num">₺75</b></div>
</div>
<div class="chips" style="margin-top:12px"><span>İndirim yok</span><span class="on">%10 Öğrenci</span><span>Yuvarla</span></div>
<div class="seg" style="margin-top:12px"><span class="on">{I['cash']}Nakit</span><span>{I['card']}Kart</span><span>Bölünmüş</span></div>
<div class="tot" style="margin-top:14px"><span>Tahsil edilecek</span><b class="num">₺351</b></div>
<span class="btn p" style="width:100%;height:48px;font-size:14.5px;margin-top:12px">₺351 tahsil et</span>
</div>'''
    return page(f'<div style="position:relative;width:390px;height:844px">{bg}<div style="position:absolute;inset:0;background:rgba(5,4,10,.62)"></div>{sheet}</div>')

def kafeterya_m():
    def prod(n, p, s, cnt=0, low=False):
        badge = f'<span style="position:absolute;top:8px;right:8px;width:20px;height:20px;border-radius:6px;background:#6A45F5;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center">{cnt}</span>' if cnt else ''
        sel = 'border-color:rgba(139,108,255,.5);background:rgba(106,69,245,.10);' if cnt else ''
        return f'<div class="panel" style="position:relative;padding:11px;{sel}">{badge}<div style="height:44px;border-radius:9px;background:linear-gradient(135deg,rgba(139,108,255,.18),rgba(34,211,238,.06));display:flex;align-items:center;justify-content:center;color:#B8A6FF"><span style="width:20px;height:20px">{I["cup"]}</span></div><b style="display:block;margin-top:8px;font-size:12.5px">{n}</b><div style="display:flex;justify-content:space-between;align-items:center;margin-top:2px"><b class="num" style="font-size:13px">₺{p}</b><span style="font-size:10.5px;color:{"#FCA5A5" if low else "#77728F"}">{"Az · " if low else ""}{s}</span></div></div>'
    prods = ''.join([prod('Çay', 15, 240, 2), prod('Türk kahvesi', 45, 80), prod('Kaşarlı tost', 60, 32, 1), prod('Enerji içeceği', 75, 6, 1, True), prod('Kola', 40, 48), prod('Ayran', 25, 30), prod('Cips', 35, 22), prod('Çikolata', 30, 4, 0, True)])
    body = f'''<div class="ph"><div class="pc">
<div class="hdr"><div><small>Hesaba ekle: PS5 · 2</small><b>Kafeterya</b></div><span class="ibtn">{I['more']}</span></div>
<div class="search" style="width:100%">Ürün ara…</div>
<div class="tabs" style="margin:0"><span class="on">Tümü</span><span>Sıcak</span><span>Soğuk</span><span>Yiyecek</span></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">{prods}</div>
</div>
<div style="margin:0 12px 10px;padding:12px 14px;border-radius:16px;background:#6A45F5;display:flex;justify-content:space-between;align-items:center;box-shadow:0 12px 30px -10px rgba(106,69,245,1)"><div><div style="font-size:11.5px;color:#E2DBFF">4 ürün · PS5 · 2</div><b class="num" style="font-size:18px">₺165</b></div><b style="font-size:14px">Hesaba ekle →</b></div>
{tabbar('Kafeterya')}</div>'''
    return page(body)

def raporlar_m():
    days = [38, 42, 35, 47, 72, 96, 64]
    lab = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz']
    bars = ''.join(f'<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;height:100%;justify-content:flex-end"><span style="width:100%;height:{d}%;border-radius:5px;background:{"#22D3EE" if i==5 else "#6A45F5"}"></span><span style="font-size:10px;color:#77728F">{lab[i]}</span></div>' for i, d in enumerate(days))
    tops = ''.join(f'<div style="display:grid;grid-template-columns:78px 1fr 58px;gap:8px;align-items:center;font-size:12px"><span>{n}</span><span style="height:7px;border-radius:4px;background:rgba(255,255,255,.06)"><i style="display:block;height:100%;width:{p}%;border-radius:4px;background:linear-gradient(90deg,#6A45F5,#8B6CFF)"></i></span><b class="num" style="text-align:right">₺{v}</b></div>' for n, p, v in [('PS5 · 1', 100, '9.420'), ('PS5 · 5', 86, '8.110'), ('PS5 · 2', 74, '6.980'), ('Bilardo · 2', 58, '5.460')])
    body = f'''<div class="ph"><div class="pc">
<div class="hdr"><div><small>1 – 24 Ekim</small><b>Raporlar</b></div><span class="ibtn">{I['more']}</span></div>
<div class="tabs" style="margin:0"><span>Bugün</span><span>Hafta</span><span class="on">Ay</span><span>Özel</span></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
<div class="kpi" style="padding:12px"><small>Toplam ciro</small><b class="num" style="font-size:19px">₺148.320</b><div class="d">▲ %18</div></div>
<div class="kpi" style="padding:12px"><small>Oyun geliri</small><b class="num" style="font-size:19px">₺102.640</b><div class="d">▲ %21</div></div>
<div class="kpi" style="padding:12px"><small>Kafeterya</small><b class="num" style="font-size:19px">₺45.680</b><div class="d">▲ %11</div></div>
<div class="kpi" style="padding:12px"><small>Ort. oturum</small><b class="num" style="font-size:19px">1 sa 38 dk</b><div class="d n">▼ 6 dk</div></div>
</div>
<div class="panel" style="padding:14px"><h3 style="margin:0 0 10px">Haftalık ciro<span>Bu hafta</span></h3><div style="height:120px;display:flex;align-items:flex-end;gap:7px">{bars}</div></div>
<div class="panel" style="padding:14px"><h3 style="margin:0 0 12px">En çok kazandıran masalar</h3><div style="display:grid;gap:11px">{tops}</div></div>
</div>{tabbar('Raporlar')}</div>'''
    return page(body)

def tv_m():
    def tog(label, sub, on=True):
        return f'<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-radius:13px;background:#14121E;border:1px solid rgba(255,255,255,.07)"><div><b style="font-size:13.5px">{label}</b><div style="font-size:11.5px;color:#9C97B5;margin-top:2px">{sub}</div></div><span style="width:42px;height:24px;border-radius:99px;background:{"#6A45F5" if on else "#2A2738"};position:relative;flex:none"><i style="position:absolute;top:3px;{"right:3px" if on else "left:3px"};width:18px;height:18px;border-radius:50%;background:#fff"></i></span></div>'
    body = f'''<div class="ph"><div class="pc">
<div class="hdr"><div style="display:flex;align-items:center;gap:10px"><span class="ibtn">{I['back']}</span><div><small>Ayarlar</small><b>Müşteri ekranı</b></div></div></div>
<div style="padding:10px;border-radius:16px;background:#14121E;border:1px solid rgba(255,255,255,.08)">
<img src="file://{TV_IMG}" style="display:block;width:100%;border-radius:10px">
<div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px"><div><b style="font-size:13.5px">Salon TV · Ana salon</b><div style="font-size:11.5px;color:#9C97B5;margin-top:2px">8 masa gösteriliyor</div></div><span style="display:flex;align-items:center;gap:6px;font-size:12px;color:#6EE7B7;font-weight:600"><i style="width:7px;height:7px;border-radius:50%;background:#34D399"></i>Yayında</span></div>
</div>
{tog('Ücretleri göster', 'Saatlik tarifeler alt bantta görünür')}
{tog('Kalan süre uyarısı', 'Son 10 dakikada masa vurgulanır')}
{tog('Boş masaları göster', 'Müşteriler boş masaları görür')}
{tog('Gece tarifesi duyurusu', '23:00 sonrası bantta gösterilir', False)}
<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-radius:13px;border:1px dashed rgba(255,255,255,.14)"><div><b style="font-size:13px">Ekran bağlantı kodu</b><div style="font-size:11.5px;color:#9C97B5;margin-top:2px">TV tarayıcısında girin</div></div><b class="num" style="font-size:16px;letter-spacing:.08em;color:#B8A6FF">A7K2-9Q</b></div>
</div>{tabbar('Diğer')}</div>'''
    return page(body)

SCREENS = {'masalar-m': masalar_m(), 'hesap-kapat-m': hesap_m(), 'kafeterya-m': kafeterya_m(), 'raporlar-m': raporlar_m(), 'musteri-ekrani-m': tv_m()}
with sync_playwright() as p:
    br = p.chromium.launch()
    for name, html in SCREENS.items():
        path = os.path.join(HERE, name + '.html'); open(path, 'w', encoding='utf-8').write(html)
        ctx = br.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=3)
        pg = ctx.new_page(); pg.goto('file://' + path); pg.wait_for_timeout(400)
        im = Image.open(io.BytesIO(pg.screenshot())).convert('RGB').resize((780, 1688), Image.LANCZOS)
        im.save(os.path.join(OUT, name + '.webp'), 'WEBP', quality=84, method=6)
        ctx.close()
    br.close()
for n in SCREENS:
    f = os.path.join(OUT, n + '.webp'); print(n, os.path.getsize(f) // 1024, 'KB')
