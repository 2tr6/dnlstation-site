# DNL Station — Web Sitesi

PlayStation ve oyun salonları için yönetim paneli **DNL Station**'ın tanıtım sitesi.
Canlı adres (geliştirme): https://dnlstation.com.tr

## Yapı

```
site/            Hosting'e yüklenen dosyalar (public_html'in birebir karşılığı)
  index.html     Ana sayfa
  giris/         Giriş sayfası
  uye-ol/        Kayıt sayfası
  404.html       Hata sayfası
  assets/        CSS, JS, görseller (panel demo ekranları dahil)
  .htaccess      HTTPS yönlendirme, önbellek, güvenlik başlıkları
tools/           Görsel üretimi ve test betikleri (yüklenmez)
.github/         Otomatik yayın ayarı
```

## Yayın

`main` dalına `site/` altında bir değişiklik gönderildiğinde GitHub Actions
siteyi FTP ile `public_html`'e yükler. Sadece değişen dosyalar gönderilir.

Gerekli ayarlar (Settings → Secrets and variables → Actions):

| Tür | Ad | Açıklama |
|---|---|---|
| Secret | `FTP_SERVER` | FTP sunucusu (ör. `ftp.dnlstation.com.tr`) |
| Secret | `FTP_USERNAME` | Sadece public_html'e erişen FTP hesabı |
| Secret | `FTP_PASSWORD` | O hesabın şifresi |
| Variable (isteğe bağlı) | `FTP_PROTOCOL` | `ftps` (varsayılan) ya da `ftp` |
| Variable (isteğe bağlı) | `FTP_SERVER_DIR` | `./` (varsayılan) ya da `public_html/` |

Elle yayın: Actions → "Siteyi yayınla" → Run workflow.

## Önbellek

CSS/JS değiştiğinde HTML dosyalarındaki `?v=` numarası artırılır; aksi halde
ziyaretçiler tarayıcı önbelleğindeki eski dosyaları görebilir.

© 2026 DNL Station · Bir YataGirişim Markasıdır.
