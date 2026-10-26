<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * Kimlik doğrulama:
 * - Şifreler Argon2id (yoksa bcrypt) ile hash'lenir.
 * - Girişte oturum kimliği yenilenir (session fixation koruması).
 * - "Beni hatırla": seçici + doğrulayıcı; doğrulayıcının yalnızca SHA-256 özeti saklanır, her kullanımda döndürülür.
 * - Şifre değişince session_version artar; diğer tüm oturumlar ve hatırlama çerezleri geçersizleşir.
 */
final class Auth
{
    public const ROLES = ['owner', 'manager', 'staff'];
    private const REMEMBER_COOKIE = 'DNLREM';
    private const REMEMBER_DAYS = 30;

    private static ?array $user = null;
    private static ?array $business = null;
    private static bool $resolved = false;

    public static function hash(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    public static function needsRehash(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_needs_rehash($hash, $algo);
    }

    /** Zayıf şifre kontrolü: uzunluk + yaygın şifreler + e-posta/ad içermeme */
    public static function passwordProblem(string $pw, string $email = '', string $name = ''): ?string
    {
        if (mb_strlen($pw) < 8) {
            return 'Şifre en az 8 karakter olmalı.';
        }
        if (strlen($pw) > 128) {
            return 'Şifre en fazla 128 karakter olabilir.';
        }
        $lower = mb_strtolower($pw);
        $common = ['12345678', '123456789', '1234567890', 'password', 'password1', 'qwerty123', 'qwertyui', '11111111', '00000000', 'abcd1234', 'sifre123', 'şifre123', 'parola123', 'asdfghjk', 'iloveyou', '87654321', '12341234', 'playstation', 'galatasaray', 'fenerbahce', 'besiktas', 'trabzonspor'];
        if (in_array($lower, $common, true) || preg_match('/^(.)\1{7,}$/u', $pw)) {
            return 'Bu şifre çok yaygın; daha güçlü bir şifre seçin.';
        }
        $local = $email !== '' ? mb_strtolower(strstr($email, '@', true) ?: '') : '';
        if ($local !== '' && mb_strlen($local) >= 4 && str_contains($lower, $local)) {
            return 'Şifre e-posta adresinizi içermemeli.';
        }
        return null;
    }

    public static function user(): ?array
    {
        self::resolve();
        return self::$user;
    }

    public static function business(): ?array
    {
        self::resolve();
        return self::$business;
    }

    public static function require(array $roles = self::ROLES): array
    {
        $u = self::user();
        if ($u === null) {
            throw new HttpError(401, 'Oturumunuz sona erdi. Lütfen tekrar giriş yapın.', 'unauthenticated');
        }
        if (!in_array($u['role'], $roles, true)) {
            throw new HttpError(403, 'Bu işlem için yetkiniz yok.', 'forbidden');
        }
        return $u;
    }

    public static function bid(): int
    {
        $b = self::business();
        if ($b === null) {
            throw new HttpError(401, 'Oturumunuz sona erdi.', 'unauthenticated');
        }
        return (int)$b['id'];
    }

    public static function uid(): int
    {
        return (int)self::require()['id'];
    }

    private static function resolve(): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;
        Session::start();
        $uid = (int)($_SESSION['uid'] ?? 0);
        if ($uid > 0) {
            $u = Db::one('SELECT * FROM users WHERE id = :id', ['id' => $uid]);
            if ($u && (int)$u['is_active'] === 1 && (int)$u['session_version'] === (int)($_SESSION['sv'] ?? -1)) {
                self::setCurrent($u);
                return;
            }
            // Kullanıcı pasifleşti / şifre değişti: oturumu temizle
            $_SESSION = [];
        }
        self::tryRemember();
    }

    private static function setCurrent(array $u): void
    {
        $b = Db::one('SELECT * FROM businesses WHERE id = :id', ['id' => $u['business_id']]);
        if (!$b) {
            self::$user = null;
            self::$business = null;
            return;
        }
        self::$user = $u;
        self::$business = $b;
    }

    public static function login(array $user, bool $remember): void
    {
        Session::start();
        Session::regenerate();
        $_SESSION = ['uid' => (int)$user['id'], 'sv' => (int)$user['session_version'], '_last' => time()];
        Session::rotateCsrf();
        $now = Db::now();
        $data = ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => $now, 'updated_at' => $now];
        Db::update('users', $data, ['id' => (int)$user['id']]);
        if ($remember) {
            self::issueRemember((int)$user['id']);
        }
        self::$resolved = true;
        self::setCurrent(Db::one('SELECT * FROM users WHERE id = :id', ['id' => $user['id']]) ?? $user);
    }

    public static function logout(): void
    {
        $c = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (is_string($c) && str_contains($c, ':')) {
            [$sel] = explode(':', $c, 2);
            Db::exec("DELETE FROM auth_tokens WHERE selector = :s AND type = 'remember'", ['s' => $sel]);
        }
        self::clearRememberCookie();
        Session::destroy();
        self::$user = null;
        self::$business = null;
    }

    /** Şifre değişimi/sıfırlama sonrası tüm oturumları düşürür */
    public static function invalidateAllSessions(int $userId): void
    {
        Db::exec('UPDATE users SET session_version = session_version + 1, updated_at = :n WHERE id = :id', ['n' => Db::now(), 'id' => $userId]);
        Db::exec("DELETE FROM auth_tokens WHERE user_id = :id AND type = 'remember'", ['id' => $userId]);
    }

    private static function issueRemember(int $userId): void
    {
        [$selector, $validator] = Tokens::create($userId, 'remember', self::REMEMBER_DAYS * 86400);
        setcookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires' => time() + self::REMEMBER_DAYS * 86400,
            'path' => '/',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(): void
    {
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            setcookie(self::REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => Http::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        }
    }

    private static function tryRemember(): void
    {
        $c = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (!is_string($c) || !preg_match('/^[a-f0-9]{24}:[A-Za-z0-9_-]{43}$/', $c)) {
            return;
        }
        [$sel, $val] = explode(':', $c, 2);
        $row = Tokens::consume($sel, $val, 'remember');
        if ($row === null) {
            self::clearRememberCookie();
            return;
        }
        $u = Db::one('SELECT * FROM users WHERE id = :id AND is_active = 1', ['id' => $row['user_id']]);
        if (!$u) {
            self::clearRememberCookie();
            return;
        }
        // Kullanılan hatırlama anahtarı silindi; yenisi verilir (döndürme)
        self::login($u, true);
    }
}
