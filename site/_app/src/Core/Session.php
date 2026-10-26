<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/** Güvenli PHP oturumu: yalnızca çerez, katı mod, HttpOnly, SameSite=Lax, HTTPS'te Secure. */
final class Session
{
    public const IDLE_SECONDS = 43200; // 12 saat hareketsizlikte oturum düşer

    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string)self::IDLE_SECONDS);
        session_name('DNLSESS');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;

        $now = time();
        $last = (int)($_SESSION['_last'] ?? $now);
        if (isset($_SESSION['uid']) && ($now - $last) > self::IDLE_SECONDS) {
            // Hareketsizlik süresi aşıldı: oturumdaki kimliği temizle (beni hatırla varsa yeniden doğrulanır)
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last'] = $now;
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
        self::$started = false;
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function rotateCsrf(): string
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): void
    {
        $real = $_SESSION['csrf'] ?? '';
        if (!is_string($token) || $token === '' || !is_string($real) || $real === '' || !hash_equals($real, $token)) {
            throw new HttpError(419, 'Oturum güvenlik anahtarı geçersiz. Sayfayı yenileyip tekrar deneyin.', 'csrf');
        }
    }
}
