<?php
/**
 * DNL Station — e-posta (SMTP) kurulum aracı, yalnızca komut satırı.
 * Kullanım: bash public_html/_app/eposta.sh
 * noreply hesabının şifresiyle bağlantı yollarını dener, test e-postası gönderir,
 * çalışan ayarı dnlstation-config.php dosyasına (600 izinle) yazar.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

use Dnl\Core\Config;
use Dnl\Core\Mailer;

$out = static function (string $s): void { fwrite(STDOUT, $s . "\n"); };
$path = Config::path();
if (!Config::ready() || !is_file($path)) {
    $out('HATA: Önce kurulum yapılmalı: bash public_html/_app/kurulum.sh');
    exit(1);
}
$from = (string)Config::get('mail.from', 'noreply@dnlstation.com.tr');
$pass = (string)getenv('MAIL_PW');
$to = trim((string)getenv('MAIL_TO'));
if ($pass === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    $out('HATA: Şifre ve geçerli bir test e-posta adresi gerekli.');
    exit(1);
}
$domain = substr(strrchr($from, '@') ?: '@dnlstation.com.tr', 1);
$tries = [
    ['host' => 'mail.' . $domain, 'port' => 465, 'secure' => 'ssl', 'verify' => true],
    ['host' => 'mail.' . $domain, 'port' => 587, 'secure' => 'tls', 'verify' => true],
    ['host' => gethostname() ?: 'localhost', 'port' => 465, 'secure' => 'ssl', 'verify' => true],
    // Aynı sunucu içi bağlantı: trafik makineden çıkmaz, sertifika adı uymadığı için doğrulama kapalı
    ['host' => 'localhost', 'port' => 465, 'secure' => 'ssl', 'verify' => false],
    ['host' => 'localhost', 'port' => 25, 'secure' => 'none', 'verify' => false],
];
$name = (string)Config::get('mail.from_name', 'DNL Station');
$subject = '=?UTF-8?B?' . base64_encode('DNL Station e-posta testi') . '?=';
[$body, $html] = \Dnl\Services\Emails::test($to);
$working = null;
$cur = (array)Config::get('mail.smtp', []);
if (Config::get('mail.transport') === 'smtp' && !empty($cur['host'])) {
    array_unshift($tries, array_intersect_key($cur, ['host' => 1, 'port' => 1, 'secure' => 1, 'verify' => 1]));
}
foreach ($tries as $t) {
    $cfg = $t + ['user' => $from, 'pass' => $pass];
    $label = "{$t['host']}:{$t['port']} ({$t['secure']})";
    if (Mailer::smtp($cfg, $from, $name, $to, $subject, $body, $html)) {
        $out("OK  Gönderildi: $label");
        $working = $cfg;
        break;
    }
    $out("--  Olmadı: $label → " . Mailer::$lastError);
    if (str_contains(Mailer::$lastError, 'Kimlik doğrulama')) {
        $out('HATA: noreply@ şifresi hatalı görünüyor. cPanel > E-posta Hesapları > Yönet bölümünden şifreyi yenileyip tekrar deneyin.');
        exit(1);
    }
}
if ($working === null) {
    $out('HATA: Hiçbir bağlantı yolu çalışmadı. Mevcut ayar değiştirilmedi.');
    exit(1);
}
$config = require $path;
$config['mail'] = ['transport' => 'smtp', 'from' => $from, 'from_name' => $name, 'smtp' => $working];
$tmp = $path . '.tmp';
$old = umask(0077);
$ok = file_put_contents($tmp, "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX) !== false && rename($tmp, $path);
umask($old);
@chmod($path, 0600);
if (!$ok) {
    $out('HATA: Ayar dosyası güncellenemedi.');
    exit(1);
}
$out('OK  E-posta ayarı kaydedildi. ' . $to . ' adresine test e-postası gönderildi (gelen kutusu ve spam klasörüne bakın).');
