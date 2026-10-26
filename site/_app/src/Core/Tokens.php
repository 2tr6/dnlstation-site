<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * Tek kullanımlık anahtarlar (e-posta doğrulama, şifre sıfırlama, beni hatırla).
 * Veritabanında yalnızca doğrulayıcının SHA-256 özeti saklanır; sızıntıda anahtarlar kullanılamaz.
 */
final class Tokens
{
    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** @return array{0:string,1:string} [selector, validator] */
    public static function create(int $userId, string $type, int $ttlSeconds): array
    {
        $selector = bin2hex(random_bytes(12));       // 24 hex
        $validator = self::b64url(random_bytes(32));  // 43 karakter
        Db::insert('auth_tokens', [
            'user_id' => $userId,
            'type' => $type,
            'selector' => $selector,
            'token_hash' => hash('sha256', $validator),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
            'used_at' => null,
            'created_at' => Db::now(),
        ]);
        return [$selector, $validator];
    }

    /** Anahtarı doğrular ve kullanılmış sayar (silmeden işaretler; remember türü silinir). */
    public static function consume(string $selector, string $validator, string $type): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $validator)) {
            return null;
        }
        $row = Db::one('SELECT * FROM auth_tokens WHERE selector = :s AND type = :t', ['s' => $selector, 't' => $type]);
        if (!$row || $row['used_at'] !== null || $row['expires_at'] <= Db::now()) {
            return null;
        }
        if (!hash_equals($row['token_hash'], hash('sha256', $validator))) {
            return null;
        }
        if ($type === 'remember') {
            $n = Db::exec('DELETE FROM auth_tokens WHERE id = :id', ['id' => $row['id']]);
        } else {
            $n = Db::exec('UPDATE auth_tokens SET used_at = :u WHERE id = :id AND used_at IS NULL', ['u' => Db::now(), 'id' => $row['id']]);
        }
        return $n === 1 ? $row : null; // yarış durumunda yalnızca biri kazanır
    }

    /** Aynı türdeki eski anahtarları geçersiz kılar */
    public static function revokeAll(int $userId, string $type): void
    {
        Db::exec('DELETE FROM auth_tokens WHERE user_id = :u AND type = :t', ['u' => $userId, 't' => $type]);
    }

    /** "selector.validator" biçimindeki bağlantı anahtarını ayırır */
    public static function split(string $token): ?array
    {
        if (!preg_match('/^([a-f0-9]{24})\.([A-Za-z0-9_-]{43})$/', $token, $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }
}
