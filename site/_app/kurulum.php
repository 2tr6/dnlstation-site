<?php
/**
 * DNL Station — sunucu kurulum aracı (yalnızca komut satırı).
 * Kullanım (cPanel Terminal):  bash public_html/_app/kurulum.sh
 *  - Ayar dosyası yoksa: veritabanı bağlantısını dener, anahtarları üretir,
 *    dnlstation-config.php dosyasını public_html'in bir üstüne 600 izniyle yazar.
 *  - Ardından veritabanı tablolarını kurar/günceller. Tekrar çalıştırmak güvenlidir.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

use Dnl\Core\Config;
use Dnl\Core\Db;
use Dnl\Core\Migrator;

$out = static function (string $s): void { fwrite(STDOUT, $s . "\n"); };
$path = Config::path();

if (!is_file($path)) {
    $name = getenv('DBN') ?: 'dnlstati_dnlstation';
    $user = getenv('DBU') ?: $name;
    $pass = (string)getenv('DBP');
    if ($pass === '') {
        $out('HATA: Veritabanı şifresi boş.');
        exit(1);
    }
    try {
        new PDO('mysql:host=localhost;dbname=' . $name . ';charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        $out('HATA: Veritabanına bağlanılamadı. Şifreyi ve kullanıcının veritabanına "Tüm Ayrıcalıklar" ile eklendiğini kontrol edin.');
        $out('Ayrıntı: ' . $e->getMessage());
        exit(1);
    }
    $config = [
        'app_url' => 'https://dnlstation.com.tr',
        'app_key' => base64_encode(random_bytes(32)),
        'db' => ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'name' => $name, 'user' => $user, 'pass' => $pass],
        'mail' => ['from' => 'noreply@dnlstation.com.tr', 'from_name' => 'DNL Station'],
        'migrate_token' => bin2hex(random_bytes(32)),
        'trial_days' => 14,
        'debug' => false,
    ];
    $old = umask(0077);
    $ok = file_put_contents($path, "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX);
    umask($old);
    if ($ok === false) {
        $out('HATA: Ayar dosyası yazılamadı: ' . $path);
        exit(1);
    }
    @chmod($path, 0600);
    $out('OK  Ayar dosyası oluşturuldu: ' . $path);
    Config::load();
    Db::reset();
} else {
    $out('OK  Ayar dosyası zaten var, dokunulmadı: ' . $path);
}

if (!Config::ready()) {
    $out('HATA: Ayar dosyası geçersiz (app_key hatalı olabilir).');
    exit(1);
}
try {
    $applied = Migrator::run();
} catch (Throwable $e) {
    $out('HATA: Tablolar kurulamadı: ' . $e->getMessage());
    exit(1);
}
$out('OK  Veritabanı tabloları ' . ($applied ? 'kuruldu: ' . implode(', ', $applied) : 'zaten güncel'));
$out('OK  Kurulum tamam. https://dnlstation.com.tr/uye-ol/ adresinden kayıt olabilirsiniz.');
