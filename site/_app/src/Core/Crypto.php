<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/** Uygulama anahtarıyla (app_key) kimlik doğrulamalı şifreleme — AES-256-GCM. */
final class Crypto
{
    private static function key(string $purpose): string
    {
        $k = Config::key();
        if ($k === '') {
            throw new \RuntimeException('app_key yok');
        }
        return hash_hmac('sha256', 'dnl-crypto:' . $purpose, $k, true);
    }

    public static function encrypt(string $plain, string $purpose): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key($purpose), OPENSSL_RAW_DATA, $iv, $tag, $purpose);
        if ($ct === false) {
            throw new \RuntimeException('şifreleme başarısız');
        }
        return 'v1.' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $blob, string $purpose): ?string
    {
        if (!str_starts_with($blob, 'v1.')) {
            return null;
        }
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($purpose), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $purpose);
        return $pt === false ? null : $pt;
    }
}
