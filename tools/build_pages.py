"""İçerik ve yasal sayfaları üretir (tek şablon, tutarlı başlık/altbilgi, yapılandırılmış veri).
Kullanım: python3 tools/build_pages.py  → site/<slug>/index.html dosyalarını yazar."""
import html, json, os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = os.path.join(ROOT, 'site')
BASE = 'https://dnlstation.com.tr'
V = '9'
UPDATED = '27 Ekim 2026'
ARROW = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8A73FF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>'
CHECK = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8A73FF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>'

SEO_LINKS = [
    ('/playstation-kafe-programi/', 'PlayStation kafe programı'),
    ('/bilardo-salonu-programi/', 'Bilardo salonu programı'),
    ('/okey-salonu-programi/', 'Okey salonu programı'),
    ('/oyun-salonu-yonetim-sistemi/', 'Oyun salonu yönetim sistemi'),
]
LEGAL_LINKS = [('/kvkk/', 'KVKK aydınlatma metni'), ('/gizlilik/', 'Gizlilik ve çerez politikası'), ('/kullanim-sartlari/', 'Kullanım şartları')]


def e(s):
    return html.escape(s, quote=True)


def header():
    nav = [('/#panel', 'Panel'), ('/#ps', 'PlayStation'), ('/#ozellikler', 'Özellikler'), ('/#fiyat', 'Paketler'), ('/#sss', 'SSS')]
    links = ''.join(f'<a href="{h}">{t}</a>' for h, t in nav)
    mlinks = ''.join(f'<a href="{h}"><span><small>{i + 1:02d}</small><b>{t}</b></span>{ARROW}</a>' for i, (h, t) in enumerate(nav))
    return f'''<header class="site-header">
  <nav class="nav" aria-label="Ana menü">
    <a class="wordmark" href="/" aria-label="DNL Station ana sayfa">DNL</a>
    <div class="nav-links">{links}</div>
    <div class="nav-actions">
      <a class="btn btn-ghost" href="/giris/">Giriş Yap</a>
      <a class="btn btn-primary" href="/uye-ol/">Üye Ol</a>
      <button type="button" class="hb" aria-label="Menüyü aç" aria-expanded="false" aria-controls="mmenu"><span></span><span></span><span></span></button>
    </div>
  </nav>
  <div class="mmenu" id="mmenu" hidden>
    <nav aria-label="Mobil menü">{mlinks}</nav>
    <div class="mmenu-foot">
      <div class="row"><a class="btn btn-ghost" href="/giris/">Giriş Yap</a><a class="btn btn-primary" href="/uye-ol/">Üye Ol</a></div>
      <p>7 gün ücretsiz · Kart bilgisi istenmez · Taahhüt yok</p>
    </div>
  </div>
</header>'''


def footer():
    seo = ''.join(f'<a href="{h}">{t}</a>' for h, t in SEO_LINKS)
    legal = ''.join(f'<a href="{h}">{t}</a>' for h, t in LEGAL_LINKS)
    return f'''<footer class="site-footer">
  <div class="foot">
    <div>
      <a class="brand" href="/" aria-label="DNL Station ana sayfa"><img src="/assets/img/logo.svg" width="22" height="30" alt="" loading="lazy"><span class="brand-name" style="font-size:17px">DNL Station</span></a>
      <p>PlayStation ve oyun salonları için bulut tabanlı yönetim paneli.</p>
    </div>
    <div class="foot-cols">
      <div><h3>Çözümler</h3>{seo}</div>
      <div><h3>Hesap</h3><a href="/giris/">Giriş Yap</a><a href="/uye-ol/">Üye Ol</a></div>
      <div><h3>Yasal</h3>{legal}</div>
      <div><h3>Destek</h3><a href="https://wa.me/905377620708" target="_blank" rel="noopener">WhatsApp: +90 537 762 07 08</a><a href="mailto:support@dnlstation.com.tr">support@dnlstation.com.tr</a><a href="https://www.instagram.com/dnlstation/" target="_blank" rel="noopener">Instagram: @dnlstation</a><a href="mailto:info@yatagirisim.com">Yazılım ve bilgi: info@yatagirisim.com</a></div>
    </div>
  </div>
  <p class="copy">© 2026 DNL Station · Bir YataGirişim Markasıdır.</p>
</footer>'''


def page(slug, title, desc, body, schema, robots='index, follow, max-image-preview:large'):
    url = f'{BASE}/{slug}/'
    return f'''<!doctype html>
<html lang="tr" class="no-js">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<script>document.documentElement.className=document.documentElement.className.replace('no-js','js')</script>
<title>{e(title)}</title>
<meta name="description" content="{e(desc)}">
<meta name="robots" content="{robots}">
<link rel="canonical" href="{url}">
<meta name="theme-color" content="#09090D">
<meta name="color-scheme" content="dark">
<meta property="og:type" content="article">
<meta property="og:site_name" content="DNL Station">
<meta property="og:locale" content="tr_TR">
<meta property="og:url" content="{url}">
<meta property="og:title" content="{e(title)}">
<meta property="og:description" content="{e(desc)}">
<meta property="og:image" content="{BASE}/assets/img/og-image.png">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Geist:wght@400;500;600&family=Geist+Mono:wght@500;600&display=swap">
<link rel="stylesheet" href="/assets/css/style.css?v={V}">
<script type="application/ld+json">{json.dumps(schema, ensure_ascii=False)}</script>
</head>
<body>
<a class="skip" href="#icerik">İçeriğe geç</a>
{header()}
<main id="icerik">
{body}
</main>
{footer()}
<script src="/assets/js/main.js?v={V}" defer></script>
</body>
</html>
'''


def crumbs(slug, name):
    return {'@type': 'BreadcrumbList', 'itemListElement': [
        {'@type': 'ListItem', 'position': 1, 'name': 'DNL Station', 'item': BASE + '/'},
        {'@type': 'ListItem', 'position': 2, 'name': name, 'item': f'{BASE}/{slug}/'}]}


def seo_page(p):
    secs = ''
    for h2, items in p['sections']:
        inner = ''
        for it in items:
            if isinstance(it, list):
                inner += '<ul class="ticks">' + ''.join(f'<li>{CHECK}<span>{x}</span></li>' for x in it) + '</ul>'
            else:
                inner += f'<p>{it}</p>'
        secs += f'<section><h2>{e(h2)}</h2>{inner}</section>'
    faq = ''.join(f'<details name="sss"{" open" if i == 0 else ""}><summary>{e(q)}<i aria-hidden="true">+</i></summary><p>{e(a)}</p></details>' for i, (q, a) in enumerate(p['faq']))
    rel = ''.join(f'<a class="rel" href="{h}">{t}{ARROW}</a>' for h, t in SEO_LINKS if h != f'/{p["slug"]}/')
    body = f'''<article class="page container">
  <nav class="crumbs" aria-label="Konum"><a href="/">DNL Station</a><span aria-hidden="true">/</span><span>{e(p["crumb"])}</span></nav>
  <header class="page-head">
    <p class="eyebrow">{e(p["eyebrow"]).replace('PlayStation', '<span lang="en">PlayStation</span>')}</p>
    <h1>{e(p["h1"])}</h1>
    <p class="lead">{p["intro"]}</p>
    <div class="hero-ctas"><a class="btn btn-primary btn-lg" href="/uye-ol/">7 gün ücretsiz deneyin</a><a class="btn btn-ghost btn-lg" href="/#panel">Paneli inceleyin</a></div>
    <p class="micro">Kurulum gerektirmez · Kart bilgisi istenmez · Taahhüt yok</p>
  </header>
  <div class="prose">{secs}</div>
  <section class="page-faq"><h2>Sıkça sorulan sorular</h2><div class="faq">{faq}</div></section>
  <section class="cta page-cta"><div class="cta-inner"><div><h2>{e(p["cta"])}</h2><p>7 gün boyunca tüm özellikler açık. Memnun kalmazsanız hiçbir ücret ödemezsiniz.</p>
    <div class="hero-ctas" style="margin-top:28px"><a class="btn btn-primary btn-lg" href="/uye-ol/">Ücretsiz deneyin</a><a class="btn btn-ghost btn-lg" href="/#fiyat">Paketleri görün</a></div></div></div></section>
  <nav class="related" aria-label="İlgili sayfalar"><h2>Diğer salon türleri</h2>{rel}</nav>
</article>'''
    schema = {'@context': 'https://schema.org', '@graph': [
        {'@type': 'WebPage', 'name': p['title'], 'url': f'{BASE}/{p["slug"]}/', 'inLanguage': 'tr-TR', 'description': p['desc'], 'isPartOf': {'@id': BASE + '/#website'}},
        crumbs(p['slug'], p['crumb']),
        {'@type': 'FAQPage', 'mainEntity': [{'@type': 'Question', 'name': q, 'acceptedAnswer': {'@type': 'Answer', 'text': a}} for q, a in p['faq']]}]}
    return page(p['slug'], p['title'], p['desc'], body, schema)


def legal_page(slug, title, h1, desc, sections, note=''):
    secs = ''.join(f'<section><h2>{e(h)}</h2>' + ''.join(f'<p>{x}</p>' if not isinstance(x, list) else '<ul>' + ''.join(f'<li>{y}</li>' for y in x) + '</ul>' for x in items) + '</section>' for h, items in sections)
    body = f'''<article class="page container legal">
  <nav class="crumbs" aria-label="Konum"><a href="/">DNL Station</a><span aria-hidden="true">/</span><span>{e(h1)}</span></nav>
  <header class="page-head"><p class="eyebrow">Yasal</p><h1>{e(h1)}</h1><p class="micro">Son güncelleme: {UPDATED}</p></header>
  <div class="prose">{note}{secs}</div>
</article>'''
    schema = {'@context': 'https://schema.org', '@graph': [{'@type': 'WebPage', 'name': title, 'url': f'{BASE}/{slug}/', 'inLanguage': 'tr-TR'}, crumbs(slug, h1)]}
    return page(slug, title, desc, body, schema)


SEO = [
    {
        'slug': 'playstation-kafe-programi', 'crumb': 'PlayStation kafe programı', 'eyebrow': 'PlayStation kafe programı',
        'title': 'PlayStation Kafe Programı | Süre ve Adisyon – DNL Station',
        'desc': 'PlayStation kafe programı: 2 kol / 4 kol tarife, maç başı ücret, canlı süre takibi, kafeterya adisyonu ve Z raporu. Kurulumsuz, 7 gün ücretsiz deneyin.',
        'h1': 'PlayStation kafe programı: süre, kol ve adisyon tek ekranda',
        'intro': 'DNL Station, PlayStation kafeler için geliştirilmiş bulut tabanlı bir yönetim programıdır. Hangi TV’de kim oynuyor, kaç kol var, ne kadar süre kaldı ve masaya ne yazıldı; hepsini bilgisayardan, tabletten ya da telefondan anlık görürsünüz.',
        'sections': [
            ('PS kafelerin en çok yaşadığı sorunlar', [
                'Kağıda ya da hafızaya dayalı süre takibi yoğun saatlerde hata üretir: süresi dolan masa fark edilmez, oyun ortasında eklenen kol hesaba yansımaz, çay ve tost kalemleri unutulur. Gün sonunda kasa ile defter tutmaz.',
                'DNL Station her masanın süresini ve tutarını saniye saniye hesaplar; kol sayısı değiştiğinde hesap o andan itibaren yeni tarifeyle devam eder.']),
            ('PlayStation salonuna özel ücretlendirme', [[
                '<b>2 kol / 4 kol tarifesi:</b> her kol sayısı için ayrı saatlik ücret. Oyun ortasında kol eklenirse fark otomatik eklenir.',
                '<b>Maç başı ücret:</b> futbol oyunlarında maç sayısına göre ücretlendirme; maç ekleyip çıkarmak tek dokunuş.',
                '<b>Açık ya da sabit süre:</b> “1 saat aç” ya da “müşteri kalkana kadar”. Sabit süreye istediğiniz an ek süre verilir.',
                '<b>Gece tarifesi:</b> belirlediğiniz saatler arasında farklı ücret otomatik uygulanır.',
                '<b>Minimum ücretlendirme:</b> “en az 30 dakika” gibi kurallar tanımlanabilir.']]),
            ('Süre bitmeden haber alırsınız', [
                'Sabit süreli masalarda bitişe birkaç dakika kala sesli uyarı ve ekranda bildirim gelir; süresi dolan masa kırmızıyla öne çıkar. Salon TV’sinde açabileceğiniz <b>müşteri ekranı</b> sayesinde müşteriler kalan süreyi kendileri görür, kasada tartışma yaşanmaz.']),
            ('Kafeterya ve stok aynı hesapta', [
                'İçecek ve yiyecekler doğrudan masanın adisyonuna eklenir; hesap kapanırken oyun ücreti ve ürünler tek kalemde toplanır. Masa açmadan hızlı satış yapabilir, stok takibi açık ürünlerde satışla birlikte stoğun düşmesini sağlarsınız.']),
            ('Kasa, vardiya ve raporlar', [
                'Gün başında kasayı açarsınız; gün sonunda sayılan nakit ile beklenen nakit karşılaştırılır ve fark Z raporuna yazılır. Raporlarda oyun ve kafeterya gelirini, en çok kazandıran masaları, en yoğun saatleri ve personel bazında tahsilatı görürsünüz; tüm hesapları Excel’e aktarabilirsiniz.']),
            ('Personel ve güvenlik', [
                'Her çalışana ayrı hesap açılır. İndirim, iptal ve rapor yetkilerini siz belirlersiniz; paneldeki önemli işlemler kim tarafından ne zaman yapıldığıyla kayıt altına alınır. Yanlışlıkla kapatılan hesap 30 dakika içinde geri alınabilir.']),
        ],
        'faq': [
            ('PlayStation kafe programı için bilgisayar kurulumu gerekir mi?', 'Hayır. DNL Station tarayıcıdan çalışır; kasadaki bilgisayar, tablet ya da telefon yeterlidir. Uygulama mağazasından indirme gerekmez.'),
            ('Konsolları uzaktan kapatıyor mu?', 'Hayır. DNL Station süre, ücret ve adisyon takibi yapar; konsollara bağlanmaz. Süre bitmeden sesli uyarı verir ve müşteri ekranında kalan süreyi gösterir.'),
            ('Oyun ortasında 2 koldan 4 kola geçilirse ne olur?', 'Hesap o andan itibaren 4 kol tarifesiyle işler; önceki süre 2 kol ücretinden hesaplanır. Fark otomatik olarak toplama yansır.'),
            ('Aynı anda kaç cihazdan kullanılabilir?', 'Kasa bilgisayarı, tablet ve telefonlar aynı anda kullanılabilir; değişiklikler birkaç saniye içinde tüm cihazlarda görünür.'),
            ('Ücretsiz deneme sonunda ödeme alınıyor mu?', 'Hayır. Kart bilgisi istenmez ve otomatik çekim yapılmaz. Memnun kalırsanız paketinizi seçip devam edersiniz; kayıtlarınız korunur.'),
        ],
        'cta': 'PlayStation kafenizi bugün dijitale taşıyın.',
    },
    {
        'slug': 'bilardo-salonu-programi', 'crumb': 'Bilardo salonu programı', 'eyebrow': 'Bilardo salonu programı',
        'title': 'Bilardo Salonu Programı | Masa Süre ve Ücret Takibi – DNL Station',
        'desc': 'Bilardo salonu programı: masa başı saatlik ücret, dakika hassasiyetinde hesap, gece tarifesi, kafeterya adisyonu ve gün sonu raporu. 7 gün ücretsiz.',
        'h1': 'Bilardo salonu programı: masa süresi ve hesap otomatik',
        'intro': 'Bilardo masalarınızın hangisinin dolu olduğunu, ne kadar süredir oynandığını ve hesabın ne kadar tuttuğunu tek ekranda görün. DNL Station süreyi dakika hassasiyetinde hesaplar; kafeterya kalemleri masanın hesabına yazılır.',
        'sections': [
            ('Bilardo salonunda süre takibi', [
                'Masa açıldığı anda süre işlemeye başlar, tutar canlı olarak güncellenir. Müşteri kalktığında hesap tek dokunuşla kapanır; nakit, kart ya da bölünmüş ödeme alınabilir, para üstü otomatik hesaplanır.']),
            ('Tarifenizi siz belirleyin', [[
                '<b>Saatlik ücret:</b> her masa türüne ayrı (ör. 3 top, amerikan, snooker).',
                '<b>Gece tarifesi:</b> belirlediğiniz saat aralığında farklı ücret otomatik uygulanır.',
                '<b>Minimum süre:</b> “en az 30 dakika” gibi kurallar.',
                '<b>Sabit süre:</b> “1 saatlik” açılışlar ve süre bitmeden uyarı.']]),
            ('Masa değiştirmek kolay', [
                'Müşteri başka masaya geçmek isterse oturum süresi ve adisyonuyla birlikte boş masaya taşınır; hiçbir şey sıfırlanmaz.']),
            ('Kafeterya, kasa ve raporlar', [
                'Çay, içecek ve atıştırmalıklar masanın hesabına eklenir. Gün başı kasa açılışı, gün sonu sayım ve Z raporu ile kasa farkını anında görürsünüz. Raporlarda en çok kazandıran masaları ve en yoğun saatleri görerek personel planlamanızı yaparsınız.']),
            ('Karma salonlar için tek panel', [
                'Bilardonun yanında PlayStation, okey ya da langırt masalarınız da varsa hepsi aynı ekranda; her türe ayrı tarife, hepsine tek kasa.']),
        ],
        'faq': [
            ('Bilardo masası ücreti nasıl hesaplanır?', 'Masa açık kaldığı süre boyunca belirlediğiniz saatlik ücretin dakikaya düşen payı hesaplanır. Gece tarifesi tanımladıysanız o saatlerde gece ücreti otomatik uygulanır.'),
            ('Farklı masa türlerine farklı ücret verebilir miyim?', 'Evet. Her masa türüne ayrı saatlik ücret, gece ücreti ve minimum süre tanımlayabilirsiniz.'),
            ('Personelin yaptığı indirimleri görebilir miyim?', 'Evet. İndirim yetkisini siz verirsiniz; yapılan indirimler raporlarda ve işlem kaydında görünür.'),
            ('İnternet kesilirse ne olur?', 'Süreler sunucuda tutulduğu için bağlantı geri geldiğinde masalar doğru süre ve tutarla görünür; hesap kaybolmaz.'),
        ],
        'cta': 'Bilardo salonunuzda süre ve kasa takibini otomatiğe alın.',
    },
    {
        'slug': 'okey-salonu-programi', 'crumb': 'Okey salonu programı', 'eyebrow': 'Okey salonu programı',
        'title': 'Okey Salonu Programı | Masa ve Adisyon Takibi – DNL Station',
        'desc': 'Okey salonu programı: masa süresi, saatlik ücret, çay ve servis adisyonu, hızlı satış, vardiya ve Z raporu. Tarayıcıdan çalışır, 7 gün ücretsiz deneyin.',
        'h1': 'Okey salonu programı: masa, çay ve hesap tek yerde',
        'intro': 'Okey salonlarında en çok zaman alan iş, masalara giden çay ve servisleri doğru yazmaktır. DNL Station’da her ürün masanın adisyonuna eklenir; masa kapanırken süre ücreti ve servisler tek hesapta toplanır.',
        'sections': [
            ('Masaya yazılan her şey kayıtlı', [
                'Çay, kahve, tost ya da su; ürüne dokunarak masanın hesabına eklersiniz. Yanlış eklenen kalem tek dokunuşla çıkarılır, stok takibi açık ürünlerde stok otomatik düzelir.']),
            ('İster saatlik ister sadece servis', [[
                '<b>Saatlik masa ücreti:</b> masa açık kaldıkça süre işler.',
                '<b>Ücretsiz masa + servis:</b> masa ücretini sıfır tanımlayıp yalnızca servisleri hesaba yazabilirsiniz.',
                '<b>Hızlı satış:</b> masa açmadan tezgahtan satış.']]),
            ('Kasa ve gün sonu', [
                'Vardiya başında kasayı açar, gün sonunda nakit sayımını girersiniz. Z raporunda nakit, kart, indirim ve iptal toplamları ile kasa farkı yer alır; geçmiş vardiyalar saklanır.']),
            ('Personel hesapları', [
                'Garson ve kasiyerlere ayrı hesap açın. Personel masaları açıp hesabı kapatabilir; indirim ve iptal yetkisini isterseniz yalnızca müdürlere verirsiniz.']),
        ],
        'faq': [
            ('Okey masası için saat ücreti zorunlu mu?', 'Hayır. Saatlik ücreti sıfır yaparak yalnızca masaya yazılan servisleri ücretlendirebilirsiniz.'),
            ('Garsonlar telefondan ürün ekleyebilir mi?', 'Evet. Personel kendi hesabıyla telefondan giriş yapıp masaya ürün ekleyebilir; kasadaki ekran anında güncellenir.'),
            ('Gün sonu raporunu nasıl alırım?', 'Kasa ekranından vardiyayı kapattığınızda Z raporu oluşur; yazdırabilir ya da raporlar bölümünden geçmiş günleri inceleyebilirsiniz.'),
        ],
        'cta': 'Okey salonunuzda adisyon karmaşasına son verin.',
    },
    {
        'slug': 'oyun-salonu-yonetim-sistemi', 'crumb': 'Oyun salonu yönetim sistemi', 'eyebrow': 'Oyun salonu yönetim sistemi',
        'title': 'PlayStation ve Oyun Salonu Yönetim Sistemi | DNL Station',
        'desc': 'PlayStation yönetim sistemi ve oyun salonu otomasyonu: masa ve süre takibi, kafeterya, stok, personel yetkileri, kasa ve raporlar tek panelde. 7 gün ücretsiz.',
        'h1': 'Oyun salonu yönetim sistemi: tüm salonunuz tek panelde',
        'intro': 'PlayStation, bilardo, okey, langırt ya da masa tenisi; masası olan ve süreyle çalışan her oyun salonu için tek bir yönetim sistemi. Masalar, kafeterya, kasa, personel ve raporlar aynı yerde, her cihazdan erişilebilir.',
        'sections': [
            ('Neden bulut tabanlı bir sistem?', [
                'Kurulum, lisans anahtarı ya da teknik servis gerekmez. Tarayıcıyı açıp giriş yaparsınız; kasadaki bilgisayar bozulsa bile verileriniz yerinde durur. Salondan uzaktayken de telefonunuzdan masaları ve günün tahsilatını takip edersiniz.']),
            ('Temel modüller', [[
                '<b>Masa ve süre takibi:</b> açık süre, sabit süre ve maç başı; kol sayısına göre tarife.',
                '<b>Kafeterya ve stok:</b> masaya ürün ekleme, hızlı satış, kritik stok uyarısı.',
                '<b>Kasa ve vardiya:</b> açılış nakdi, gün sonu sayım, Z raporu.',
                '<b>Raporlar:</b> günlük tahsilat, oyun/ürün ayrımı, yoğun saatler, dönem karşılaştırması, Excel çıktısı.',
                '<b>Personel ve yetkiler:</b> sahip, müdür ve personel rolleri; işlem kaydı.',
                '<b>Müşteri ekranı:</b> salon TV’sinde masaların durumu ve kalan süreler.']]),
            ('Güvenlik', [
                'Bağlantılar SSL ile şifrelenir, şifreler geri döndürülemez biçimde saklanır. Her işletmenin verisi diğerlerinden ayrılmıştır; personel yalnızca yetkisi olan işlemleri yapabilir ve önemli işlemler kayıt altına alınır.']),
            ('Paketler', [
                'Başlangıç paketi 8 masaya kadar küçük salonlar için, Pro paketi sınırsız masa ve personel yetkileriyle çoğu salon için, Salon+ ise büyük ve yoğun salonlar için öncelikli destek sunar. Tüm paketlerde 7 gün ücretsiz deneme vardır, kart bilgisi istenmez. <a href="/#fiyat">Paketleri karşılaştırın</a>.']),
        ],
        'faq': [
            ('Oyun salonu yönetim sistemi hangi salonlar için uygun?', 'PlayStation, bilardo, okey, langırt, masa tenisi, dart ve VR gibi masa ya da alan bazlı çalışan salonlar için uygundur. Bilgisayar (internet kafe) salonları için uygun değildir.'),
            ('Birden fazla salon türünü aynı anda yönetebilir miyim?', 'Evet. Her salon türü için ayrı tarife tanımlanır; tüm masalar aynı ekranda ve aynı kasada yönetilir.'),
            ('Verilerimi dışa aktarabilir miyim?', 'Evet. Raporlar bölümünden seçtiğiniz tarih aralığındaki tüm hesapları Excel’de açılabilen CSV dosyası olarak indirebilirsiniz.'),
            ('Destek alabilir miyim?', 'Evet. WhatsApp (+90 537 762 07 08), e-posta (support@dnlstation.com.tr) ve Instagram üzerinden destek verilir; Salon+ paketinde öncelikli destek ve birebir kurulum yardımı bulunur.'),
        ],
        'cta': 'Salonunuzu profesyonel bir sistemle yönetin.',
    },
]

CONTACT = 'E-posta: <a href="mailto:support@dnlstation.com.tr">support@dnlstation.com.tr</a> · WhatsApp: <a href="https://wa.me/905377620708" target="_blank" rel="noopener">+90 537 762 07 08</a> · Instagram: <a href="https://www.instagram.com/dnlstation/" target="_blank" rel="noopener">@dnlstation</a>'

LEGAL = [
    ('kvkk', 'KVKK Aydınlatma Metni – DNL Station', 'KVKK aydınlatma metni', 'DNL Station kişisel verilerin işlenmesine ilişkin 6698 sayılı KVKK kapsamında aydınlatma metni.', [
        ('Veri sorumlusu', [f'Bu aydınlatma metni, 6698 sayılı Kişisel Verilerin Korunması Kanunu (“KVKK”) uyarınca, DNL Station hizmetini sunan veri sorumlusu YataGirişim (DNL Station) tarafından hazırlanmıştır. İletişim: {CONTACT}']),
        ('İşlenen kişisel veriler', [[
            '<b>Kimlik ve iletişim:</b> ad soyad, e-posta adresi, telefon numarası, işletme adı.',
            '<b>Hesap güvenliği:</b> şifrenin geri döndürülemez özeti (şifrenin kendisi saklanmaz), oturum ve giriş kayıtları, IP adresinden türetilmiş özet değerler.',
            '<b>İşlem verileri:</b> panelde oluşturulan masa, adisyon, ödeme, stok ve vardiya kayıtları ile işlem kayıtları.']]),
        ('İşleme amaçları', [[
            'Üyelik oluşturma, kimlik doğrulama ve hesap güvenliğinin sağlanması,',
            'Hizmetin sunulması, işletme panelinin çalıştırılması ve destek verilmesi,',
            'Paket ve ödeme süreçlerinin yürütülmesi,',
            'Hizmetle ilgili zorunlu bildirimlerin (doğrulama, şifre sıfırlama) gönderilmesi,',
            'Hukuki yükümlülüklerin yerine getirilmesi ve olası uyuşmazlıklarda delil olarak kullanılması.']]),
        ('Hukuki sebepler', ['Kişisel verileriniz KVKK m.5/2 kapsamında; bir sözleşmenin kurulması veya ifasıyla doğrudan ilgili olması (c), veri sorumlusunun hukuki yükümlülüğünü yerine getirmesi (ç), bir hakkın tesisi, kullanılması veya korunması (e) ve temel hak ve özgürlüklerinize zarar vermemek kaydıyla meşru menfaat (f) hukuki sebeplerine dayanılarak işlenir.']),
        ('Aktarım', ['Kişisel verileriniz, hizmetin barındırıldığı sunucu (hosting) sağlayıcısı ve e-posta altyapısı gibi hizmetin sunulması için zorunlu tedarikçilerle, yalnızca bu amaçla sınırlı olarak paylaşılır; yetkili kamu kurum ve kuruluşlarına mevzuatın gerektirdiği hallerde aktarılabilir. Verileriniz pazarlama amacıyla üçüncü kişilere satılmaz veya kiralanmaz.']),
        ('Toplama yöntemi', ['Verileriniz üyelik formu, işletme paneli ve hizmet kullanımı sırasında elektronik ortamda otomatik ya da kısmen otomatik yollarla toplanır.']),
        ('Haklarınız', ['KVKK m.11 uyarınca; kişisel verilerinizin işlenip işlenmediğini öğrenme, işlenmişse bilgi talep etme, amacına uygun kullanılıp kullanılmadığını öğrenme, aktarıldığı üçüncü kişileri bilme, eksik veya yanlış işlenmişse düzeltilmesini, KVKK m.7 şartları çerçevesinde silinmesini veya yok edilmesini isteme, bu işlemlerin aktarıldığı kişilere bildirilmesini isteme, münhasıran otomatik sistemlerle analiz sonucu aleyhinize bir sonucun ortaya çıkmasına itiraz etme ve kanuna aykırı işleme nedeniyle zarara uğramanız halinde zararın giderilmesini talep etme haklarına sahipsiniz.', f'Başvurularınızı {CONTACT} üzerinden iletebilirsiniz. Başvurular en geç 30 gün içinde ücretsiz olarak sonuçlandırılır.']),
    ]),
    ('gizlilik', 'Gizlilik ve Çerez Politikası – DNL Station', 'Gizlilik ve çerez politikası', 'DNL Station gizlilik ve çerez politikası: hangi verileri neden işlediğimiz, çerezler ve güvenlik önlemleri.', [
        ('Kısaca', ['DNL Station’ı kullanmak için gereken en az veriyi işleriz. Reklam veya takip çerezi kullanmayız; verilerinizi satmayız. Ayrıntılar için <a href="/kvkk/">KVKK aydınlatma metnine</a> bakın.']),
        ('Çerezler', ['Sitemizde yalnızca hizmetin çalışması için zorunlu çerezler kullanılır:', [
            '<b>DNLSESS:</b> oturum çerezi; giriş yaptığınızı ve form güvenlik anahtarını tutar. Tarayıcı kapanınca ya da hareketsizlik sonrası geçersiz olur.',
            '<b>DNLREM:</b> yalnızca “Beni hatırla” seçildiğinde, 30 gün boyunca yeniden giriş yapmamanız için kullanılır.'],
            'Bunlara ek olarak tarayıcınızın yerel depolamasında yalnızca arayüz tercihleri (ör. kapatılan duyuru, son seçilen masa ayarı) tutulur.',
            'Not: Reklam kampanyaları için ileride ölçüm araçları (ör. Google Ads dönüşüm etiketi) eklenirse bu politika güncellenecek ve gerekli durumlarda onayınız alınacaktır.']),
        ('Güvenlik önlemleri', [[
            'Tüm bağlantılar SSL/TLS ile şifrelenir.',
            'Şifreler geri döndürülemez (Argon2id) biçimde saklanır.',
            'Her işletmenin verisi diğer işletmelerden ayrılmıştır; personel yalnızca yetkisi olan işlemleri yapabilir.',
            'Art arda hatalı girişlerde hesap geçici olarak kilitlenir; önemli işlemler kayıt altına alınır.']]),
        ('Saklama süresi', ['Hesap verileri üyelik devam ettiği sürece saklanır. Üyeliğin sona ermesinden sonra, yasal saklama yükümlülükleri saklı kalmak kaydıyla, talebiniz üzerine silinir veya anonim hale getirilir.']),
        ('İletişim', [CONTACT]),
    ]),
    ('kullanim-sartlari', 'Kullanım Şartları – DNL Station', 'Kullanım şartları', 'DNL Station hizmet kullanım şartları: üyelik, deneme süresi, paketler, ödeme ve sorumluluklar.', [
        ('Taraflar ve kapsam', ['Bu şartlar, YataGirişim markası DNL Station (“Hizmet”) ile Hizmet’e üye olan işletme (“Kullanıcı”) arasındaki kullanım koşullarını düzenler. Üye olarak bu şartları kabul etmiş sayılırsınız.']),
        ('Hizmet', ['DNL Station; oyun salonları için masa ve süre takibi, adisyon, stok, kasa, personel ve raporlama özellikleri sunan, tarayıcı üzerinden çalışan bir yazılım hizmetidir. Hizmet konsollara veya diğer cihazlara bağlanmaz, onları kontrol etmez.']),
        ('Üyelik ve hesap güvenliği', ['Kullanıcı, üyelikte verdiği bilgilerin doğruluğundan ve hesap şifrelerinin gizliliğinden sorumludur. Personel hesapları işletme sahibinin sorumluluğundadır. Hesabın yetkisiz kullanıldığından şüphelenilirse derhal bildirilmelidir.']),
        ('Deneme süresi', ['Yeni üyelere ücretsiz deneme süresi tanınır. Deneme için kart bilgisi istenmez ve süre sonunda otomatik ücret alınmaz. Süre sonunda paket seçilmezse yeni işlem yapılamaz; kayıtlar korunur ve görüntülenebilir.']),
        ('Paketler ve ödeme', ['Paket ücretleri sitede ilan edilir. Ödemeler, panelin “Paketim” sayfasında belirtilen yöntemlerle yapılır ve onaylandığında paket süresi tanımlanır. Ücretli dönem içinde iptal halinde, mevzuattan doğan haklar saklı kalmak kaydıyla kalan süre için iade yapılmaz. Fiyat değişiklikleri mevcut ücretli dönemi etkilemez.']),
        ('Kullanım kuralları', ['Hizmet; hukuka aykırı amaçlarla, başkalarının haklarını ihlal edecek şekilde veya sistemin güvenliğini tehlikeye atacak biçimde (yetkisiz erişim denemesi, aşırı yük oluşturma, tersine mühendislik vb.) kullanılamaz. Bu durumda hesap askıya alınabilir.']),
        ('Veriler', ['Kullanıcının panelde oluşturduğu işletme verileri kullanıcıya aittir ve raporlar bölümünden dışa aktarılabilir. Kişisel verilerin işlenmesine ilişkin ayrıntılar <a href="/kvkk/">KVKK aydınlatma metninde</a> yer alır.']),
        ('Sorumluluk', ['Hizmet’in kesintisiz ve hatasız çalışması için makul özen gösterilir; ancak bakım, altyapı arızası, internet kesintisi gibi durumlarda yaşanabilecek geçici aksaklıklardan ve Kullanıcı’nın hatalı veri girişinden doğan sonuçlardan DNL Station sorumlu tutulamaz. Hizmet’in sağladığı hesaplamalar işletmenin ticari kayıtlarının yerine geçmez; yasal defter ve belge yükümlülükleri Kullanıcı’ya aittir.']),
        ('Değişiklikler ve fesih', ['Bu şartlar gerektiğinde güncellenebilir; önemli değişiklikler panel duyurusu ile bildirilir. Kullanıcı üyeliğini dilediği zaman sonlandırabilir. Şartlara aykırılık halinde hesap askıya alınabilir veya sonlandırılabilir.']),
        ('Uygulanacak hukuk', ['Bu şartlar Türkiye Cumhuriyeti hukukuna tabidir. Uyuşmazlıklarda mevzuatın yetkili kıldığı mahkeme ve icra daireleri yetkilidir.']),
        ('İletişim', [CONTACT]),
    ]),
]

if __name__ == '__main__':
    for p in SEO:
        d = os.path.join(SITE, p['slug'])
        os.makedirs(d, exist_ok=True)
        open(os.path.join(d, 'index.html'), 'w', encoding='utf-8').write(seo_page(p))
    for slug, title, h1, desc, secs in LEGAL:
        d = os.path.join(SITE, slug)
        os.makedirs(d, exist_ok=True)
        open(os.path.join(d, 'index.html'), 'w', encoding='utf-8').write(legal_page(slug, title, h1, desc, secs))
    print('yazıldı:', [p['slug'] for p in SEO] + [x[0] for x in LEGAL])
