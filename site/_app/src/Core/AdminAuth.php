<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * Ana yönetici kimliği. İşletme kullanıcılarından tamamen ayrı tablo ve oturum anahtarları.
 * Giriş iki adımlıdır: şifre + TOTP (Authenticator) kodu. Yönetici hesabı web'den oluşturulamaz.
 */
final class AdminAuth
{
    public const IDLE = 1800;        // 30 dk hareketsizlikte çıkış
    public const ABSOLUTE = 43200;   // 12 saat sonra her durumda yeniden giriş
    public const PENDING_TTL = 300;  // şifre sonrası kod adımı için 5 dk
    public const MAX_FAILS = 5;
    public const LOCK_MINUTES = 30;

    private static ?array $admin = null;
    private static bool $resolved = false;

    public static function admin(): ?array
    {
        if (self::$resolved) {
            return self::$admin;
        }
        self::$resolved = true;
        Session::start();
        $s = $_SESSION['adm'] ?? null;
        if (!is_array($s) || !isset($s['id'], $s['sv'], $s['at'], $s['last'])) {
            return null;
        }
        $now = time();
        if ($now - (int)$s['last'] > self::IDLE || $now - (int)$s['at'] > self::ABSOLUTE) {
            unset($_SESSION['adm']);
            return null;
        }
        $a = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => (int)$s['id']]);
        if (!$a || (int)$a['is_active'] !== 1 || (int)$a['totp_enabled'] !== 1 || (int)$a['session_version'] !== (int)$s['sv']) {
            unset($_SESSION['adm']);
            return null;
        }
        $_SESSION['adm']['last'] = $now;
        return self::$admin = $a;
    }

    public static function require(): array
    {
        $a = self::admin();
        if ($a === null) {
            throw new HttpError(401, 'Yönetici oturumu yok ya da süresi doldu.', 'admin_unauthenticated');
        }
        return $a;
    }

    public static function id(): int
    {
        return (int)self::require()['id'];
    }

    public static function startPending(array $a, bool $setup): void
    {
        Session::regenerate();
        unset($_SESSION['adm']);
        $_SESSION['adm_pending'] = ['id' => (int)$a['id'], 'at' => time(), 'setup' => $setup, 'tries' => 0];
    }

    /** Şifre adımı geçilmiş, kod bekleyen yönetici */
    public static function pending(): ?array
    {
        Session::start();
        $p = $_SESSION['adm_pending'] ?? null;
        if (!is_array($p) || time() - (int)$p['at'] > self::PENDING_TTL || (int)$p['tries'] >= 5) {
            unset($_SESSION['adm_pending']);
            return null;
        }
        return $p;
    }

    public static function failPending(): void
    {
        if (isset($_SESSION['adm_pending'])) {
            $_SESSION['adm_pending']['tries']++;
        }
    }

    public static function complete(array $a): void
    {
        unset($_SESSION['adm_pending']);
        Session::regenerate();
        $now = time();
        $_SESSION['adm'] = ['id' => (int)$a['id'], 'sv' => (int)$a['session_version'], 'at' => $now, 'last' => $now];
        Session::rotateCsrf();
        self::$resolved = false;
        self::$admin = null;
    }

    public static function logout(): void
    {
        Session::start();
        unset($_SESSION['adm'], $_SESSION['adm_pending']);
        Session::regenerate();
        Session::rotateCsrf();
        self::$admin = null;
    }

    public static function secret(array $a): ?string
    {
        return $a['totp_secret'] ? Crypto::decrypt((string)$a['totp_secret'], 'admin-totp') : null;
    }

    public static function log(?int $adminId, string $action, string $entity = '', ?int $entityId = null, array $meta = []): void
    {
        try {
            Db::insert('admin_audit', [
                'admin_id' => $adminId, 'action' => $action, 'entity' => $entity, 'entity_id' => $entityId,
                'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'ip_hash' => substr(hash_hmac('sha256', Http::ip(), Config::key() ?: 'dnl'), 0, 16),
                'created_at' => Db::now(),
            ]);
        } catch (\Throwable $e) {
            Log::error($e, 'admin-audit');
        }
    }
}
