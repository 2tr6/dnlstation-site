# DNL Station — Sunucu kurulumu (bir kerelik)

Panel kodları GitHub'a her gönderimde otomatik olarak `public_html`'e yüklenir. Sistemin çalışması için aşağıdaki adımları **bir kez** yapmanız yeterli. Şifreleri hiçbir yere (sohbet, e-posta, GitHub dosyası) yazmayın; yalnızca sunucudaki ayar dosyasına ve GitHub Secrets'a girin.

## Kısa yol (telefondan da olur)
1. Veritabanı + kullanıcıyı oluşturup kullanıcıyı **Tüm Ayrıcalıklar** ile ekleyin (aşağıda 1. adım).
2. cPanel → **Terminal** → `bash public_html/_app/kurulum.sh` yazın, veritabanı şifresini girin.
   Ayar dosyası ve anahtarlar otomatik oluşturulur, tablolar kurulur. Tekrar çalıştırmak güvenlidir
   (ileride şema güncellemesi gerekirse aynı komut yeterli).

## E-posta (SMTP)
cPanel → Terminal → `bash public_html/_app/eposta.sh`
- noreply@ hesabının şifresini ve test için kendi e-posta adresinizi girin.
- Araç bağlantı yollarını dener, test e-postası gönderir, çalışan ayarı ayar dosyasına yazar.
- Gmail'de spam'e düşmemesi için: cPanel → **E-posta Teslim Edilebilirliği** → dnlstation.com.tr için SPF/DKIM "Geçerli" olmalı (değilse **Onar**).

## SMS (NetGSM) — isteğe bağlı
- Ayar dosyasında `sms` bölümünü doldurun: `provider` → `netgsm`, `usercode`, `password` (NetGSM API şifresi), `header` (onaylı başlık).
- Şu an `provider` = `off`; bu durumda SMS gönderilmez, kayıtlar "skipped" olarak görünür. Sistem bozulmaz.
- Kayıt sırasında SMS doğrulaması için `signup_otp` = `true` yapın. Bu, ön yüzdeki OTP adımı eklenene kadar kayıtları durduracağı için son adımda açın.
- Yönetici panelinde **Sistem** sayfasında "SMS gönderimi" kontrolü durumu gösterir.

## Deneme hatırlatmaları (cron)
- cPanel → **Cron Jobs** → Ortak (Common Settings): **Her gün** (Once Per Day), saat **09:00**.
- Komut: `php /home/KULLANICI/public_html/_app/hatirlatma.php` (KULLANICI yerine cPanel kullanıcı adınızı yazın).
- Her işletme için her aşama yalnızca bir kez gönderilir: 2 gün kala ve süre dolunca.

## Ana yönetici hesabı
cPanel → Terminal → `bash public_html/_app/yonetici.sh`
- **1** yeni yönetici, **2** şifre sıfırlama, **3** Authenticator sıfırlama (telefon kaybolursa).
- Araç önce bekleyen veritabanı güncellemelerini de uygular.
- Giriş: `https://dnlstation.com.tr/yonetim/` → şifre → ilk girişte Authenticator kurulumu → 6 haneli kod.
- Yönetici hesabı web'den oluşturulamaz; yalnızca sunucuya erişimi olan kişi oluşturabilir.

Aşağıdaki adımlar elle kurulum içindir.

## 1. MySQL veritabanı
cPanel → **MySQL® Veritabanları**
1. Yeni veritabanı: `dnlstati_station` (örnek)
2. Yeni kullanıcı: `dnlstati_station` + **güçlü şifre** (Şifre Oluşturucu'yu kullanın)
3. Kullanıcıyı veritabanına ekleyin → **TÜM AYRICALIKLAR**

## 2. E-posta hesabı
cPanel → **E-posta Hesapları** → `noreply@dnlstation.com.tr` oluşturun. (Doğrulama ve şifre sıfırlama e-postaları bu adresten gider.)

## 3. Gizli anahtarları üretin (kendi bilgisayarınızda)
Windows'ta **PowerShell** açıp şunu yapıştırın; iki satır çıktı verir:

```powershell
$r=[Security.Cryptography.RandomNumberGenerator]::Create(); $a=New-Object byte[] 32; $r.GetBytes($a); "app_key: " + [Convert]::ToBase64String($a); $b=New-Object byte[] 32; $r.GetBytes($b); "migrate_token: " + (-join ($b|%{$_.ToString('x2')}))
```

(cPanel'de **Terminal** varsa alternatif: `php -r 'echo base64_encode(random_bytes(32)),"\n",bin2hex(random_bytes(32)),"\n";'`)

## 4. Ayar dosyası
1. cPanel → **Dosya Yöneticisi** → `/home/dnlstati/` (yani `public_html`'in **bir üstü**)
2. Yeni dosya: `dnlstation-config.php`
3. İçine `docs/dnlstation-config.example.php` içeriğini yapıştırın, `DEGISTIR` yazan yerleri doldurun.
4. Dosyaya sağ tık → **İzinleri Değiştir** → `600`

## 5. PHP sürümü
cPanel → **MultiPHP Yöneticisi** → `dnlstation.com.tr` için **PHP 8.1 veya üstü** (8.2/8.3 önerilir). `pdo_mysql`, `mbstring`, `openssl` eklentileri açık olmalı (genelde varsayılan olarak açıktır).

## 6. GitHub Secret (otomatik şema güncellemesi)
GitHub → depo → **Settings → Secrets and variables → Actions → New repository secret**
- Ad: `MIGRATE_TOKEN` — Değer: 3. adımdaki `migrate_token`

## 7. İlk kurulum
GitHub → **Actions → Siteyi yayınla → Run workflow**. İş bitince "Veritabanı şemasını güncelle" adımında `HTTP 200` ve `"applied":["001_init"]` görmelisiniz.

Kontrol: `https://dnlstation.com.tr/api/system/health` → `{"ok":true,"data":{"status":"ok","pending_migrations":0}}`

## 8. Deneme
1. `https://dnlstation.com.tr/uye-ol/` → hesap açın
2. Kurulum sihirbazı → masalar → **Kasa** → kasayı açın → masa açıp kapatın
3. E-postanıza doğrulama bağlantısı geldi mi kontrol edin (gelmezse spam klasörüne bakın)

## Sorun giderme
| Görülen | Anlamı |
|---|---|
| "Sistem henüz hazır değil" | Ayar dosyası bulunamadı ya da `app_key` hatalı |
| "Hizmete ulaşılamıyor" | Veritabanı bilgileri hatalı |
| "Sistem güncelleniyor" | Şema güncellemesi çalışmadı → 7. adım |
| Hata ayrıntısı | `public_html/_app/storage/logs/` altındaki günlük dosyası (web'den erişilemez, Dosya Yöneticisi ile açın) |

## Güvenlik notları
- `debug` ayarı üretimde **false** kalmalı.
- SSL aktif olduktan sonra `public_html/.htaccess` içindeki `Strict-Transport-Security` satırının başındaki `#` işaretini kaldırın.
- `app_key` değiştirilirse tüm oturumlar kapanır (sızıntı şüphesinde yapılacak ilk iş budur).
