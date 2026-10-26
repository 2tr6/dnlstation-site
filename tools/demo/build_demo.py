"""Builds demo panel screens (internal) and screenshots them into site/assets/img/demo/*.webp"""
import os, sys, io
from playwright.sync_api import sync_playwright
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
SITE = os.path.abspath(sys.argv[1])
OUT = os.path.join(SITE, 'assets', 'img', 'demo')
os.makedirs(OUT, exist_ok=True)
LOGO = os.path.join(SITE, 'assets', 'img', 'logo.svg')

I = {
 'grid': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
 'cup': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/></svg>',
 'chart': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15v2"/><path d="M11 11v6"/><path d="M15 7v10"/><path d="M19 12v5"/></svg>',
 'receipt': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6"/><path d="M16 12h-6"/></svg>',
 'users': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>',
 'tv': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="13" rx="2"/><path d="m17 2-5 5-5-5"/></svg>',
 'box': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8v8l9 5 9-5Z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/></svg>',
 'cog': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>',
 'plus': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
 'search': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
 'cash': '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg>',
 'card': '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
 'split': '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 3h5v5M8 3H3v5M21 3l-7 7M3 3l7 7M12 22v-8"/></svg>',
}

def side(active):
    items = [('grid', 'Masalar', '12'), ('cup', 'Kafeterya', ''), ('receipt', 'Vardiya & Kasa', ''), ('chart', 'Raporlar', ''), ('box', 'Stok', ''), ('users', 'Personel', ''), ('tv', 'Müşteri ekranı', '')]
    nav = ''
    for k, label, cnt in items:
        on = ' class="on"' if label == active else ''
        c = f'<span class="cnt">{cnt}</span>' if cnt else ''
        nav += f'<a{on}>{I[k]}{label}{c}</a>'
    return f'''<aside class="side">
<div class="logo"><img src="file://{LOGO}"><b>DNL Station</b></div>
<div class="venue"><b>Arena Game Center</b><span>Eskişehir · Pro paket</span></div>
<nav class="nav"><h6>İşletme</h6>{nav}<h6>Hesap</h6><a>{I['cog']}Ayarlar</a></nav>
<div class="shift"><span class="av">E</span><span><b>Emre</b><span>Gece vardiyası · açık</span></span></div>
</aside>'''

def top(title, sub, right):
    return f'<header class="top"><div><h1>{title}</h1><p>{sub}</p></div><div class="tools">{right}</div></header>'

def page(body, w=1280, h=800, extra_css=''):
    return f'''<!doctype html><html lang="tr"><head><meta charset="utf-8"><link rel="stylesheet" href="file://{HERE}/panel.css"><style>{extra_css}</style></head><body style="width:{w}px;height:{h}px">{body}</body></html>'''

TOP_RIGHT = f'''<div class="search">{I['search']}Masa, ürün ara…</div>
<div class="pillk"><small>Bugünkü ciro</small><b class="num">₺6.840</b></div>
<div class="pillk"><small>Dolu masa</small><b class="num">12/17</b></div>
<span class="btn c">{I['plus']}Hızlı satış</span>'''

def tcard(name, kind, tag, time, left, right, pct=None, sel=False):
    cls = 't ' + kind + (' sel' if sel else '')
    bar = f'<div class="bar"><i style="width:{pct}%"></i></div>' if pct is not None else ''
    return f'<div class="{cls}"><div class="h"><b>{name}</b><span class="tag">{tag}</span></div><div><div class="time num">{time}</div>{bar}</div><div class="sub"><span>{left}</span><b class="num">{right}</b></div></div>'

TABLES = ''.join([
  tcard('PS5 · 1', '', '4 kol', '1:24:12', 'Açık süre', '₺390', sel=True),
  tcard('PS5 · 2', '', '2 kol', '0:32:40', 'Açık süre', '₺54'),
  tcard('PS5 · 3', 'warn', 'Bitiyor', '-04:12', '2 saat paket', '₺200', pct=96),
  tcard('PS5 · 4', 'free', 'Boş', 'Hazır', 'Son: 21:58', '—'),
  tcard('PS5 · 5', '', 'Maç 3/5', '0:41:05', 'Maç başı', '₺135', pct=60),
  tcard('PS5 · 6', 'res', 'Rezerve', '23:00 · Kaan', '4 kişi', '—'),
  tcard('PS4 · 1', '', '2 kol', '1:02:18', 'Açık süre', '₺83'),
  tcard('PS4 · 2', 'free', 'Boş', 'Hazır', 'Son: 22:10', '—'),
  tcard('Bilardo · 1', '', 'Saatlik', '0:48:30', '₺120/sa', '₺97'),
  tcard('Bilardo · 2', '', 'Saatlik', '1:15:02', '₺120/sa', '₺150'),
  tcard('Okey · 1', '', 'Saatlik', '2:05:44', '+ 6 çay', '₺215'),
  tcard('Okey · 2', '', 'Saatlik', '0:36:10', '+ 4 çay', '₺96'),
  tcard('Dart', '', 'Oyun başı', '0:22:40', '3 oyun', '₺90'),
  tcard('VR · 1', '', '30 dk paket', '0:18:05', 'Sabit süre', '₺150', pct=60),
  tcard('Langırt', 'free', 'Boş', 'Hazır', 'Son: 20:40', '—'),
  tcard('Masa tenisi', 'free', 'Boş', 'Hazır', 'Son: 21:05', '—'),
])

TABS = '<div class="tabs"><span class="on">Tümü<i>17</i></span><span>PlayStation<i>8</i></span><span>Bilardo<i>2</i></span><span>Okey<i>3</i></span><span>Diğer<i>4</i></span><span class="legend"><span><b style="background:#8B6CFF"></b>Dolu</span><span><b style="background:#22D3EE"></b>Bitiyor</span><span><b style="background:#FBBF24"></b>Rezerve</span><span><b style="background:#3A3550"></b>Boş</span></span></div>'

DETAIL = f'''<aside class="detail">
<div class="dh"><div><h2>PS5 · 1</h2><p>Başlangıç 21:17 · Açık süre</p></div><span style="padding:4px 10px;border-radius:99px;background:rgba(139,108,255,.22);font-size:11px;font-weight:600;color:#D6CCFF">4 kol</span></div>
<div class="big"><small>Geçen süre</small><div class="time num">1:24:12</div></div>
<div class="rows"><div>Tarife<b>4 kol · ₺160/sa</b></div><div>Oyun ücreti<b class="num">₺225</b></div><div>Gece tarifesi<b>23:00'te başlar</b></div></div>
<div class="sec">Kafeterya</div>
<div class="items">
<div class="item"><span><b>2×</b>Çay</span><b class="num">₺30</b></div>
<div class="item"><span><b>1×</b>Kaşarlı tost</span><b class="num">₺60</b></div>
<div class="item"><span><b>1×</b>Enerji içeceği</span><b class="num">₺75</b></div>
</div>
<div class="tot"><span>Toplam</span><b class="num">₺390</b></div>
<div class="acts"><span class="btn">+ Ek süre</span><span class="btn">+ Kol ekle</span><span class="btn">Masa taşı</span><span class="btn">{I['cup'].replace('<svg','<svg width="15" height="15"')}Ürün ekle</span><span class="btn p full">Hesabı kapat</span></div>
</aside>'''

def masalar(dim=False, modal=''):
    main = f'<main class="main">{top("Masalar", "Cumartesi, 24 Ekim · 22:41", TOP_RIGHT)}<div class="body"><section class="content">{TABS}<div class="grid">{TABLES}</div></section>{DETAIL}</div></main>'
    cls = 'app dim' if dim else 'app'
    return page(f'<div style="position:relative;width:1280px;height:800px"><div class="{cls}">{side("Masalar")}{main}</div>{modal}</div>')

MODAL = f'''<div class="modal">
<div class="dh"><div><h2 style="font-size:20px">Hesabı kapat</h2><p>PS5 · 1 · 21:17 – 22:41 · 1 sa 24 dk</p></div><span class="btn" style="height:32px">Esc</span></div>
<div class="items" style="margin-top:16px">
<div class="item"><span><b>Oyun</b>4 kol · 1 sa 24 dk</span><b class="num">₺225</b></div>
<div class="item"><span><b>2×</b>Çay</span><b class="num">₺30</b></div>
<div class="item"><span><b>1×</b>Kaşarlı tost</span><b class="num">₺60</b></div>
<div class="item"><span><b>1×</b>Enerji içeceği</span><b class="num">₺75</b></div>
</div>
<div class="sec" style="margin-top:16px">İndirim</div>
<div class="chips" style="margin-top:8px"><span>Yok</span><span class="on">%10 Öğrenci</span><span>Yuvarla</span><span>Özel tutar</span></div>
<div class="rows" style="margin-top:14px"><div>Ara toplam<b class="num">₺390</b></div><div>İndirim (%10)<b class="num" style="color:#6EE7B7">−₺39</b></div></div>
<div class="sec" style="margin-top:16px">Ödeme yöntemi</div>
<div class="seg" style="margin-top:8px"><span class="on">{I['cash']}Nakit</span><span>{I['card']}Kart</span><span>{I['split']}Bölünmüş</span></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px">
<div class="big" style="padding:12px"><small>Alınan</small><div class="num" style="font-size:20px;font-weight:600;margin-top:2px">₺400</div></div>
<div class="big" style="padding:12px;border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.07)"><small>Para üstü</small><div class="num" style="font-size:20px;font-weight:600;margin-top:2px;color:#6EE7B7">₺49</div></div>
</div>
<div class="tot" style="margin-top:16px"><span>Tahsil edilecek</span><b class="num">₺351</b></div>
<span class="btn p" style="width:100%;height:48px;font-size:14.5px;margin-top:14px">₺351 tahsil et ve masayı boşalt</span>
</div>'''

def product(name, price, stock, low=False, cnt=0):
    s = f'<span style="font-size:11px;color:{"#FCA5A5" if low else "#77728F"}">{"Az kaldı · " if low else "Stok "}{stock}</span>'
    badge = f'<span style="position:absolute;top:10px;right:10px;width:22px;height:22px;border-radius:7px;background:#6A45F5;color:#fff;font-size:11.5px;font-weight:700;display:flex;align-items:center;justify-content:center">{cnt}</span>' if cnt else ''
    sel = 'border-color:rgba(139,108,255,.5);background:rgba(106,69,245,.10)' if cnt else ''
    return f'<div class="panel" style="position:relative;padding:14px;{sel}">{badge}<div style="height:58px;border-radius:10px;background:linear-gradient(135deg,rgba(139,108,255,.18),rgba(34,211,238,.06));display:flex;align-items:center;justify-content:center;color:#B8A6FF">{I["cup"].replace("<svg","<svg width=\"24\" height=\"24\"")}</div><b style="display:block;margin-top:10px;font-size:13px">{name}</b><div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px"><b class="num" style="font-size:14px">₺{price}</b>{s}</div></div>'

def kafeterya():
    prods = ''.join([product('Çay', 15, 240, cnt=2), product('Türk kahvesi', 45, 80), product('Filtre kahve', 55, 64), product('Kaşarlı tost', 60, 32, cnt=1),
                     product('Enerji içeceği', 75, 6, low=True, cnt=1), product('Kola', 40, 48), product('Ayran', 25, 30), product('Su', 10, 120),
                     product('Cips', 35, 22), product('Çikolata', 30, 4, low=True), product('Sucuklu tost', 75, 18), product('Limonata', 50, 26)])
    cats = '<div class="tabs"><span class="on">Tümü<i>32</i></span><span>Sıcak içecek<i>8</i></span><span>Soğuk içecek<i>11</i></span><span>Yiyecek<i>7</i></span><span>Atıştırmalık<i>6</i></span></div>'
    cart = f'''<aside class="detail">
<div class="dh"><div><h2>Sepet</h2><p>Hesaba ekle: <b style="color:#fff">PS5 · 2</b></p></div><span class="btn" style="height:32px">Değiştir</span></div>
<div class="chips"><span>Masa açmadan sat</span><span class="on">PS5 · 2</span><span>Okey · 1</span></div>
<div class="items">
<div class="item"><span><b>Çay</b>₺15</span><span class="num" style="display:flex;gap:8px;align-items:center"><span class="btn" style="height:26px;padding:0 8px">−</span><b style="color:#fff">2</b><span class="btn" style="height:26px;padding:0 8px">+</span></span></div>
<div class="item"><span><b>Kaşarlı tost</b>₺60</span><span class="num" style="display:flex;gap:8px;align-items:center"><span class="btn" style="height:26px;padding:0 8px">−</span><b style="color:#fff">1</b><span class="btn" style="height:26px;padding:0 8px">+</span></span></div>
<div class="item"><span><b>Enerji içeceği</b>₺75</span><span class="num" style="display:flex;gap:8px;align-items:center"><span class="btn" style="height:26px;padding:0 8px">−</span><b style="color:#fff">1</b><span class="btn" style="height:26px;padding:0 8px">+</span></span></div>
</div>
<div class="panel" style="padding:12px;border-color:rgba(248,113,113,.3);background:rgba(248,113,113,.06);font-size:12px;color:#FCA5A5">Enerji içeceği stokta 6 adet kaldı. Sipariş listesine eklensin mi?</div>
<div class="tot" style="margin-top:auto"><span>Toplam</span><b class="num">₺165</b></div>
<span class="btn p" style="height:46px;font-size:14px">PS5 · 2 hesabına ekle</span>
</aside>'''
    main = f'<main class="main">{top("Kafeterya", "Hızlı satış ve masa hesabına ekleme", TOP_RIGHT)}<div class="body"><section class="content">{cats}<div class="grid">{prods}</div></section>{cart}</div></main>'
    return page(f'<div class="app">{side("Kafeterya")}{main}</div>')

def raporlar():
    days = [38, 42, 35, 47, 72, 96, 64, 40, 45, 39, 52, 78, 100, 70]
    caf = [30, 28, 32, 30, 34, 30, 33, 29, 31, 30, 33, 35, 31, 30]
    labels = ['11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23', '24']
    bars = ''
    for i, (d, c) in enumerate(zip(days, caf)):
        last = i == len(days) - 1
        g = '#22D3EE' if last else '#6A45F5'
        bars += f'<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end"><div style="width:100%;height:{d}%;display:flex;flex-direction:column;border-radius:6px;overflow:hidden"><span style="flex:{100 - c};background:{g}"></span><span style="flex:{c};background:{"#67E3F5" if last else "#3D3290"};opacity:.9"></span></div><span style="font-size:10.5px;color:#77728F">{labels[i]}</span></div>'
    import random
    random.seed(4)
    hours = ['14', '16', '18', '20', '22', '00', '02']
    daysn = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz']
    heat = '<div style="display:grid;grid-template-columns:30px repeat(12,1fr);gap:4px;align-items:center">'
    for di, dn in enumerate(daysn):
        heat += f'<span style="font-size:10.5px;color:#77728F">{dn}</span>'
        for h in range(12):
            base = (0.15 + 0.07 * h if h < 9 else 0.8 - 0.15 * (h - 9)) * (1.35 if di in (4, 5) else 1)
            v = max(0.05, min(1, base + random.uniform(-.1, .1)))
            heat += f'<span style="height:20px;border-radius:5px;background:rgba(139,108,255,{v:.2f})"></span>'
    heat += '<span></span>' + ''.join(f'<span style="font-size:10px;color:#77728F;grid-column:span 2">{h}:00</span>' for h in ['14', '16', '18', '20', '22', '00']) + '</div>'
    top_tables = ''.join(f'<div style="display:grid;grid-template-columns:90px 1fr 60px;gap:10px;align-items:center;font-size:12.5px"><span>{n}</span><span style="height:8px;border-radius:5px;background:rgba(255,255,255,.06)"><i style="display:block;height:100%;width:{p}%;border-radius:5px;background:linear-gradient(90deg,#6A45F5,#8B6CFF)"></i></span><b class="num" style="text-align:right">₺{v}</b></div>' for n, p, v in [('PS5 · 1', 100, '9.420'), ('PS5 · 5', 86, '8.110'), ('PS5 · 2', 74, '6.980'), ('Bilardo · 2', 58, '5.460'), ('Okey · 1', 44, '4.150')])
    top_prod = ''.join(f'<div class="item"><span><b>{n}</b>{q} adet</span><b class="num">₺{v}</b></div>' for n, q, v in [('Çay', 1840, '27.600'), ('Enerji içeceği', 212, '15.900'), ('Kaşarlı tost', 236, '14.160')])
    kpis = '''<div class="kpis">
<div class="kpi"><small>Toplam ciro</small><b class="num">₺148.320</b><div class="d">▲ %18 geçen aya göre</div></div>
<div class="kpi"><small>Oyun geliri</small><b class="num">₺102.640</b><div class="d">▲ %21</div></div>
<div class="kpi"><small>Kafeterya geliri</small><b class="num">₺45.680</b><div class="d">▲ %11</div></div>
<div class="kpi"><small>Ort. oturum süresi</small><b class="num">1 sa 38 dk</b><div class="d n">▼ 6 dk</div></div>
</div>'''
    right = '<div class="tabs" style="margin:0"><span>Bugün</span><span>Hafta</span><span class="on">Ay</span><span>Özel</span></div><span class="btn">Excel’e aktar</span>'
    content = f'''{kpis}
<div style="display:grid;grid-template-columns:1.55fr 1fr;gap:10px">
<div class="panel"><h3>Günlük ciro<span><b style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#6A45F5;margin-right:5px"></b>Oyun <b style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#3D3290;margin:0 5px 0 10px"></b>Kafeterya</span></h3><div style="height:250px;display:flex;align-items:flex-end;gap:8px">{bars}</div></div>
<div class="panel"><h3>En çok kazandıran masalar<span>Ekim</span></h3><div style="display:grid;gap:13px">{top_tables}</div></div>
<div class="panel"><h3>Yoğunluk haritası<span>Saat × gün</span></h3>{heat}</div>
<div class="panel"><h3>En çok satan ürünler<span>Ekim</span></h3><div class="items">{top_prod}</div></div>
</div>'''
    main = f'<main class="main">{top("Raporlar", "1 – 24 Ekim · önceki dönemle karşılaştırmalı", right)}<div class="body"><section class="content">{content}</section></div></main>'
    return page(f'<div class="app">{side("Raporlar")}{main}</div>')

def tv():
    def tile(n, state, big, sub, pct=None):
        col = {'busy': ('rgba(106,69,245,.14)', 'rgba(139,108,255,.4)', '#fff', '#D6CCFF', 'Dolu'),
               'warn': ('rgba(34,211,238,.12)', 'rgba(34,211,238,.6)', '#CFF8FD', '#7EE8F7', 'Bitiyor'),
               'free': ('rgba(255,255,255,.025)', 'rgba(255,255,255,.08)', '#4F4A68', '#9C97B5', 'Boş')}[state]
        bar = f'<div style="height:6px;border-radius:4px;background:rgba(255,255,255,.08);margin-top:14px;overflow:hidden"><i style="display:block;height:100%;width:{pct}%;background:{"#22D3EE" if state=="warn" else "#8B6CFF"}"></i></div>' if pct is not None else ''
        glow = 'box-shadow:0 0 50px -10px rgba(34,211,238,.7);' if state == 'warn' else ''
        return f'<div style="padding:22px;border-radius:22px;background:{col[0]};border:1.5px solid {col[1]};{glow}"><div style="display:flex;justify-content:space-between;align-items:center"><b style="font-size:26px">{n}</b><span style="font-size:15px;font-weight:600;padding:4px 12px;border-radius:99px;background:rgba(255,255,255,.07);color:{col[3]}">{col[4]}</span></div><div class="num" style="font-size:52px;font-weight:700;letter-spacing:-.03em;margin-top:14px;color:{col[2]}">{big}</div><div style="font-size:16px;color:#9C97B5;margin-top:4px">{sub}</div>{bar}</div>'
    tiles = ''.join([tile('PS5 · 1', 'busy', '1:24', '4 kol · ₺225'), tile('PS5 · 2', 'busy', '0:32', '2 kol · ₺54'), tile('PS5 · 3', 'warn', '04:12', 'kalan süre', 96), tile('PS5 · 4', 'free', 'Boş', 'Hemen başla'),
                     tile('PS5 · 5', 'busy', 'Maç 3/5', 'Maç başı · ₺135', 60), tile('PS4 · 1', 'busy', '1:02', '2 kol · ₺83'), tile('Bilardo · 1', 'busy', '0:48', 'Saatlik · ₺97'), tile('Bilardo · 2', 'busy', '1:15', 'Saatlik · ₺150')])
    body = f'''<div style="width:1600px;height:900px;padding:36px 44px;display:flex;flex-direction:column;gap:26px;background:radial-gradient(100% 120% at 80% 0%,#1E1740 0%,#0B0A12 55%)">
<div style="display:flex;justify-content:space-between;align-items:center"><div style="display:flex;align-items:center;gap:16px"><img src="file://{LOGO}" style="width:30px;height:40px"><div><b style="font-size:30px;letter-spacing:-.02em">Arena Game Center</b><div style="font-size:16px;color:#9C97B5">Canlı masa durumu</div></div></div><div style="text-align:right"><div class="num" style="font-size:46px;font-weight:700;letter-spacing:-.03em">22:41</div><div style="font-size:16px;color:#9C97B5">Cumartesi, 24 Ekim</div></div></div>
<div style="flex:1;display:grid;grid-template-columns:repeat(4,1fr);gap:18px">{tiles}</div>
<div style="display:flex;gap:28px;align-items:center;padding:16px 22px;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);font-size:18px;color:#C9C4DE"><b style="color:#67E3F5">Saatlik ücretler</b><span>PS5 2 kol ₺100</span><span>PS5 4 kol ₺160</span><span>Maç başı ₺30</span><span>Bilardo ₺120</span><span style="margin-left:auto;color:#FCD58A">23:00 sonrası gece tarifesi</span></div>
</div>'''
    return page(body, 1600, 900)

def mobil():
    def row(n, tag, time, amt, kind=''):
        c = {'': ('rgba(106,69,245,.12)', 'rgba(139,108,255,.3)', '#fff'), 'warn': ('rgba(34,211,238,.10)', 'rgba(34,211,238,.5)', '#CFF8FD'), 'free': ('rgba(255,255,255,.02)', 'rgba(255,255,255,.07)', '#5E5978')}[kind]
        return f'<div style="display:flex;align-items:center;justify-content:space-between;padding:13px 14px;border-radius:16px;background:{c[0]};border:1px solid {c[1]}"><div><b style="font-size:15px">{n}</b><div style="font-size:12px;color:#9C97B5;margin-top:2px">{tag}</div></div><div style="text-align:right"><div class="num" style="font-size:18px;font-weight:600;color:{c[2]}">{time}</div><div class="num" style="font-size:12px;color:#9C97B5">{amt}</div></div></div>'
    rows = row('PS5 · 1', '4 kol · açık süre', '1:24:12', '₺390') + row('PS5 · 3', '2 saat paket', '-04:12', 'kalan', 'warn') + row('PS5 · 2', '2 kol · açık süre', '0:32:40', '₺54') + row('Bilardo · 2', 'Saatlik', '1:15:02', '₺150') + row('Okey · 1', 'Saatlik · 6 çay', '2:05:44', '₺215') + row('PS5 · 4', 'Hazır', 'Boş', '—', 'free')
    tab = lambda k, l, on=False: f'<div style="display:flex;flex-direction:column;align-items:center;gap:4px;font-size:10.5px;color:{"#fff" if on else "#77728F"}"><span style="width:22px;height:22px;color:{"#B8A6FF" if on else "#77728F"}">{I[k]}</span>{l}</div>'
    body = f'''<div style="width:390px;height:844px;display:flex;flex-direction:column;background:linear-gradient(180deg,#151127,#0B0A12 40%)">
<div style="padding:54px 18px 0;flex:1;display:flex;flex-direction:column;gap:14px">
<div style="display:flex;justify-content:space-between;align-items:center"><div><div style="font-size:12px;color:#9C97B5">Arena Game Center</div><b style="font-size:24px;letter-spacing:-.02em">Masalar</b></div><span class="av" style="width:38px;height:38px;border-radius:12px">E</span></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><div class="kpi" style="padding:12px"><small>Bugünkü ciro</small><b class="num" style="font-size:20px">₺6.840</b></div><div class="kpi" style="padding:12px"><small>Dolu masa</small><b class="num" style="font-size:20px">12/17</b></div></div>
<div style="display:flex;gap:10px;align-items:center;padding:12px 14px;border-radius:16px;background:rgba(34,211,238,.10);border:1px solid rgba(34,211,238,.4)"><span style="width:34px;height:34px;border-radius:10px;background:rgba(34,211,238,.18);display:flex;align-items:center;justify-content:center;color:#67E3F5;flex:none"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg></span><div style="flex:1"><b style="font-size:13.5px">PS5 · 3 süresi doluyor</b><div style="font-size:12px;color:#A9E9F3">4 dk kaldı · Ek süre sor</div></div><span class="btn c" style="height:32px">+30 dk</span></div>
<div class="tabs" style="margin:0"><span class="on">Tümü</span><span>PS</span><span>Bilardo</span><span>Okey</span></div>
<div style="display:grid;gap:9px">{rows}</div>
</div>
<div style="display:flex;justify-content:space-around;padding:12px 10px 30px;border-top:1px solid rgba(255,255,255,.07);background:#0E0C17">{tab('grid','Masalar',True)}{tab('cup','Kafeterya')}{tab('receipt','Kasa')}{tab('chart','Raporlar')}</div>
</div>'''
    return page(body, 390, 844)

SCREENS = {
  'masalar': (masalar(), 1280, 800),
  'hesap-kapat': (masalar(dim=True, modal=MODAL), 1280, 800),
  'kafeterya': (kafeterya(), 1280, 800),
  'raporlar': (raporlar(), 1280, 800),
  'musteri-ekrani': (tv(), 1600, 900),
  'mobil': (mobil(), 390, 844),
}

with sync_playwright() as p:
    br = p.chromium.launch()
    for name, (html, w, h) in SCREENS.items():
        path = os.path.join(HERE, name + '.html')
        open(path, 'w', encoding='utf-8').write(html)
        scale = 3 if name == 'mobil' else 1.5
        ctx = br.new_context(viewport={'width': w, 'height': h}, device_scale_factor=scale)
        pg = ctx.new_page(); pg.goto('file://' + path); pg.wait_for_timeout(300)
        png = pg.screenshot(full_page=False)
        im = Image.open(io.BytesIO(png)).convert('RGB')
        if name == 'mobil': im = im.resize((780, 1688), Image.LANCZOS)
        elif name == 'musteri-ekrani': im = im.resize((1600, 900), Image.LANCZOS)
        else: im = im.resize((1600, 1000), Image.LANCZOS)
        im.save(os.path.join(OUT, name + '.webp'), 'WEBP', quality=84, method=6)
        ctx.close()
    br.close()
for f in sorted(os.listdir(OUT)):
    print(f, os.path.getsize(os.path.join(OUT, f)) // 1024, 'KB', Image.open(os.path.join(OUT, f)).size)
