<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\AdminAuth;
use Dnl\Core\Auth;
use Dnl\Core\Crypto;
use Dnl\Core\Db;
use Dnl\Core\Http;
use Dnl\Core\HttpError;
use Dnl\Core\Input;
use Dnl\Core\RateLimiter;
use Dnl\Core\SecurityLog;
use Dnl\Core\Session;
use Dnl\Core\Totp;

/** Ana yönetici girişi: şifre → Authenticator kodu (ilk girişte kurulum). */
final class AdminAuthController
{
    private const GENERIC = 'E-posta, şifre ya da kod hatalı.';

    private static function failAccount(array $a): void
    {
        $fails = (int)$a['failed_logins'] + 1;
        $lock = $fails >= AdminAuth::MAX_FAILS ? gmdate('Y-m-d H:i:s', time() + AdminAuth::LOCK_MINUTES * 60) : null;
        Db::exec('UPDATE admins SET failed_logins = :f, locked_until = :l, updated_at = :n WHERE id = :id', ['f' => $lock ? 0 : $fails, 'l' => $lock, 'n' => Db::now(), 'id' => $a['id']]);
        AdminAuth::log((int)$a['id'], $lock ? 'admin.locked' : 'admin.login_failed', 'admin', (int)$a['id']);
        SecurityLog::add($lock ? 'admin_locked' : 'admin_login_failed', (string)$a['email']);
    }

    public static function login(): array
    {
        RateLimiter::enforce('adm-login:ip:' . Http::ip(), 10, 900, 'Çok fazla deneme. Lütfen 15 dakika sonra tekrar deneyin.');
        $in = new Input(Http::json());
        $email = $in->email('email');
        $password = $in->raw('password', 1, 128, 'Şifre');
        $in->check();
        RateLimiter::enforce('adm-login:email:' . $email, 10, 3600, 'Çok fazla deneme. Lütfen daha sonra tekrar deneyin.');
        $a = Db::one('SELECT * FROM admins WHERE email = :e', ['e' => $email]);
        if ($a && $a['locked_until'] !== null && $a['locked_until'] > Db::now()) {
            throw new HttpError(429, 'Hesap geçici olarak kilitlendi. ' . AdminAuth::LOCK_MINUTES . ' dakika sonra tekrar deneyin.', 'locked');
        }
        $dummy = defined('PASSWORD_ARGON2ID')
            ? '$argon2id$v=19$m=65536,t=4,p=1$Y3ZEaHJHOGoxZVVFdVRzag$a1tz5reUMz8l+R4M9dVL/VDei7caKTbMWz6o0FqcVyc'
            : '$2y$10$NrvZT78CPPrEuPXxIfxvH.Im644.2viE6gTXlpHIM48bGQMKF0/ii';
        $ok = password_verify($password, $a['password_hash'] ?? $dummy);
        if (!$a || !$ok || (int)$a['is_active'] !== 1) {
            if ($a) {
                self::failAccount($a);
            } else {
                SecurityLog::add('admin_login_failed', $email, null, 'kayıtsız e-posta');
            }
            throw new HttpError(401, self::GENERIC, 'invalid_credentials');
        }
        if (Auth::needsRehash($a['password_hash'])) {
            Db::exec('UPDATE admins SET password_hash = :h WHERE id = :id', ['h' => Auth::hash($password), 'id' => $a['id']]);
        }
        if ((int)$a['totp_enabled'] === 1) {
            if (AdminAuth::trustedDevice((int)$a['id'])) {
                AdminAuth::complete($a);
                AdminAuth::log((int)$a['id'], 'admin.login_trusted', 'admin', (int)$a['id']);
                SecurityLog::add('admin_login_ok', (string)$a['email'], null, 'güvenilir cihaz');
                return ['ok' => true, 'trusted' => true, 'csrf' => Session::csrf()];
            }
            AdminAuth::startPending($a, false);
            return ['next' => 'totp', 'csrf' => Session::csrf()];
        }
        // İlk giriş: Authenticator kurulumu. Sır yalnızca kod doğrulanana kadar bekler.
        $secret = AdminAuth::secret($a);
        if ($secret === null) {
            $secret = Totp::newSecret();
            Db::exec('UPDATE admins SET totp_secret = :s, updated_at = :n WHERE id = :id', ['s' => Crypto::encrypt($secret, 'admin-totp'), 'n' => Db::now(), 'id' => $a['id']]);
        }
        AdminAuth::startPending($a, true);
        return ['next' => 'setup', 'secret' => $secret, 'uri' => Totp::uri($secret, $a['email']), 'csrf' => Session::csrf()];
    }

    /** Kod adımı: kurulum onayı ya da normal giriş */
    public static function verify(): array
    {
        RateLimiter::enforce('adm-totp:ip:' . Http::ip(), 20, 900, 'Çok fazla deneme. Lütfen daha sonra tekrar deneyin.');
        $p = AdminAuth::pending();
        if ($p === null) {
            throw new HttpError(401, 'Giriş adımının süresi doldu. Lütfen baştan giriş yapın.', 'pending_expired');
        }
        $in = new Input(Http::json());
        $code = $in->str('code', 6, 10, true, 'Kod');
        $trust = $in->bool('trust');
        $in->check();
        $a = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $p['id']]);
        if (!$a || (int)$a['is_active'] !== 1 || ($a['locked_until'] !== null && $a['locked_until'] > Db::now())) {
            throw new HttpError(401, self::GENERIC, 'invalid_credentials');
        }
        $secret = AdminAuth::secret($a);
        $step = $secret ? Totp::verify($secret, $code, (int)$a['totp_last_step']) : null;
        if ($step === null) {
            AdminAuth::failPending();
            self::failAccount($a);
            throw new HttpError(401, 'Kod hatalı ya da süresi geçmiş. Uygulamadaki güncel kodu girin.', 'bad_code');
        }
        // Eşzamanlı aynı kodla iki giriş olmasın: adım koşullu güncellenir
        $n = Db::exec('UPDATE admins SET totp_last_step = :s, totp_enabled = 1, failed_logins = 0, locked_until = NULL, last_login_at = :n, updated_at = :n2 WHERE id = :id AND totp_last_step < :s2', [
            's' => $step, 'n' => Db::now(), 'n2' => Db::now(), 'id' => $a['id'], 's2' => $step,
        ]);
        if ($n !== 1) {
            throw new HttpError(401, 'Bu kod zaten kullanıldı. Bir sonraki kodu bekleyin.', 'bad_code');
        }
        $a = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $a['id']]);
        AdminAuth::complete($a);
        if ($trust) {
            AdminAuth::rememberDevice((int)$a['id']);
        }
        AdminAuth::log((int)$a['id'], $p['setup'] ? 'admin.2fa_enabled' : 'admin.login', 'admin', (int)$a['id']);
        SecurityLog::add('admin_login_ok', (string)$a['email']);
        return ['ok' => true, 'csrf' => Session::csrf()];
    }

    public static function logout(): array
    {
        $a = AdminAuth::admin();
        if ($a) {
            AdminAuth::log((int)$a['id'], 'admin.logout', 'admin', (int)$a['id']);
        }
        AdminAuth::logout();
        return ['ok' => true, 'csrf' => Session::csrf()];
    }

    public static function me(): array
    {
        $a = AdminAuth::require();
        return ['admin' => ['id' => (int)$a['id'], 'name' => $a['name'], 'email' => $a['email'], 'last_login_at' => Db::iso($a['last_login_at'])], 'csrf' => Session::csrf(), 'server_time' => gmdate('c')];
    }

    /** Şifre değişimi: mevcut şifre + güncel Authenticator kodu gerekir; diğer oturumlar kapanır. */
    public static function changePassword(): array
    {
        $a = AdminAuth::require();
        RateLimiter::enforce('adm-pw:' . $a['id'], 5, 3600);
        $in = new Input(Http::json());
        $cur = $in->raw('current_password', 1, 128, 'Mevcut şifre');
        $pw = $in->raw('password', 1, 128, 'Yeni şifre');
        $pw2 = $in->raw('password_confirm', 1, 128, 'Şifre tekrarı');
        $code = $in->str('code', 6, 10, true, 'Kod');
        $in->check();
        $e = new Input([]);
        if (!password_verify($cur, $a['password_hash'])) {
            $e->error('current_password', 'Mevcut şifre hatalı.');
        }
        if (strlen($pw) < 12) {
            $e->error('password', 'Yönetici şifresi en az 12 karakter olmalı.');
        } elseif ($p = Auth::passwordProblem($pw, $a['email'], $a['name'])) {
            $e->error('password', $p);
        }
        if (!hash_equals($pw, $pw2)) {
            $e->error('password_confirm', 'Şifreler eşleşmiyor.');
        }
        $secret = AdminAuth::secret($a);
        $step = $secret ? Totp::verify($secret, $code, (int)$a['totp_last_step']) : null;
        if ($step === null) {
            $e->error('code', 'Kod hatalı.');
        }
        $e->check();
        Db::exec('UPDATE admins SET password_hash = :h, session_version = session_version + 1, totp_last_step = :s, updated_at = :n WHERE id = :id', ['h' => Auth::hash($pw), 's' => $step, 'n' => Db::now(), 'id' => $a['id']]);
        $a = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $a['id']]);
        AdminAuth::revokeDevices((int)$a['id']);
        AdminAuth::complete($a);
        AdminAuth::log((int)$a['id'], 'admin.password_changed', 'admin', (int)$a['id']);
        return ['ok' => true, 'csrf' => Session::csrf()];
    }
}
