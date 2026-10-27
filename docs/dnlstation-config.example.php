<?php
/**
 * DNL Station — sunucu ayar dosyası ÖRNEĞİ
 *
 * 1) Bu dosyayı public_html'in BİR ÜST dizinine "dnlstation-config.php" adıyla kaydedin:
 *      /home/dnlstati/dnlstation-config.php      (public_html'in içine DEĞİL!)
 * 2) Aşağıdaki DEGISTIR yazan değerleri doldurun.
 * 3) Dosya izinlerini 600 ya da 640 yapın (cPanel > Dosya Yöneticisi > İzinler).
 *
 * Bu dosyayı asla GitHub'a yüklemeyin, kimseyle paylaşmayın, sohbetlere yapıştırmayın.
 */
return [
    // Sitenin tam adresi (sonunda / olmadan). .com'a geçince güncelleyin.
    'app_url' => 'https://dnlstation.com.tr',

    // 32 baytlık rastgele anahtarın base64 hali (oturum/oran sınırı imzaları için).
    // Üretmek için KURULUM.md'deki komutu kullanın. Değiştirilirse tüm oturumlar kapanır.
    'app_key' => 'DEGISTIR',

    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'dnlstati_DEGISTIR',   // cPanel > MySQL Veritabanları'nda oluşturduğunuz veritabanı
        'user' => 'dnlstati_DEGISTIR',   // aynı ekranda oluşturduğunuz kullanıcı
        'pass' => 'DEGISTIR',            // o kullanıcının şifresi
    ],

    'mail' => [
        'from' => 'noreply@dnlstation.com.tr', // cPanel > E-posta Hesapları'nda bu adresi oluşturun
        'from_name' => 'DNL Station',
    ],

    // Yayından sonra şema güncellemesi için gizli anahtar (en az 32 karakter).
    // Aynı değeri GitHub > Settings > Secrets > Actions altında MIGRATE_TOKEN olarak da kaydedin.
    'migrate_token' => 'DEGISTIR',

    // Üretimde mutlaka false kalmalı (true olursa hata ayrıntıları API yanıtlarında görünür).
    'debug' => false,
];
