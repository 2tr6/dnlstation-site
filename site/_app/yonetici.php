<?php
/**
 * DNL Station — ana yönetici hesabı aracı (yalnızca komut satırı).
 * Kullanım (cPanel Terminal):  bash public_html/_app/yonetici.sh
 * Yönetici hesabı web üzerinden oluşturulamaz; bu araç sunucuya erişimi olan kişiye özeldir.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

use Dnl\Core\AdminAuth;
use Dnl\Core\Auth;
use Dnl\Core\Config;
use Dnl\Core\Db;
use Dnl\Core\Migrator;

$out = static function (string $s): void { fwrite(STDOUT, $s . "\n"); };
if (!Config::ready()) {
    $out('HATA: Önce kurulum yapılmalı: bash public_html/_app/kurulum.sh');
    exit(1);
}
$applied = Migrator::run();
if ($applied) {
    $out('OK  Veritabanı güncellendi: ' . implode(', ', $applied));
}

$op = trim((string)getenv('ADM_OP'));
$email = mb_strtolower(trim((string)getenv('ADM_EMAIL')));
$name = trim((string)getenv('ADM_NAME'));
$pw = (string)getenv('ADM_PW');
$pw2 = (string)getenv('ADM_PW2');

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $out('HATA: Geçerli bir e-posta girin.');
    exit(1);
}
$checkPw = static function () use ($pw, $pw2, $email, $name, $out): void {
    if ($pw !== $pw2) {
        $out('HATA: Şifreler eşleşmiyor.');
        exit(1);
    }
    if (strlen($pw) < 12) {
        $out('HATA: Yönetici şifresi en az 12 karakter olmalı.');
        exit(1);
    }
    if ($p = Auth::passwordProblem($pw, $email, $name)) {
        $out('HATA: ' . $p);
        exit(1);
    }
};
$now = Db::now();
$a = Db::one('SELECT * FROM admins WHERE email = :e', ['e' => $email]);

switch ($op) {
    case '1':
        if ($a) {
            $out('HATA: Bu e-postayla yönetici zaten var. Şifre sıfırlamak için 2 seçin.');
            exit(1);
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $out('HATA: Ad soyad 2-80 karakter olmalı.');
            exit(1);
        }
        $checkPw();
        $id = Db::insert('admins', [
            'email' => $email, 'name' => $name, 'password_hash' => Auth::hash($pw), 'totp_secret' => null, 'totp_enabled' => 0, 'totp_last_step' => 0,
            'is_active' => 1, 'session_version' => 1, 'failed_logins' => 0, 'locked_until' => null, 'last_login_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        AdminAuth::log(null, 'cli.admin_created', 'admin', $id);
        $out('OK  Yönetici oluşturuldu.');
        $out('    Şimdi https://dnlstation.com.tr/yonetim/ adresinden giriş yapın;');
        $out('    ilk girişte Authenticator (2 adımlı doğrulama) kurulumu istenecek.');
        break;
    case '2':
        if (!$a) {
            $out('HATA: Bu e-postayla yönetici yok.');
            exit(1);
        }
        $checkPw();
        Db::exec('UPDATE admins SET password_hash = :h, session_version = session_version + 1, failed_logins = 0, locked_until = NULL, updated_at = :n WHERE id = :id', ['h' => Auth::hash($pw), 'n' => $now, 'id' => $a['id']]);
        AdminAuth::log(null, 'cli.password_reset', 'admin', (int)$a['id']);
        $out('OK  Şifre değişti, açık yönetici oturumları kapatıldı.');
        break;
    case '3':
        if (!$a) {
            $out('HATA: Bu e-postayla yönetici yok.');
            exit(1);
        }
        Db::exec('UPDATE admins SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0, session_version = session_version + 1, failed_logins = 0, locked_until = NULL, updated_at = :n WHERE id = :id', ['n' => $now, 'id' => $a['id']]);
        AdminAuth::log(null, 'cli.2fa_reset', 'admin', (int)$a['id']);
        $out('OK  Authenticator sıfırlandı. Bir sonraki girişte yeniden kurulum istenecek.');
        $out('    Telefondaki eski "DNL Station" kaydını silebilirsiniz.');
        break;
    default:
        $out('HATA: Geçersiz seçim.');
        exit(1);
}
