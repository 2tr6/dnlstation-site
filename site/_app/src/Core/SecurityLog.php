<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * Güvenlik olay kaydı (hatalı giriş, kilit, oran sınırı, CSRF, bot tuzağı...).
 * Ham IP tutulmaz (HMAC özeti). Aynı IP/tür için kayıt hızı sınırlıdır; saldırı kayıtları şişiremez.
 */
final class SecurityLog
{
    public static function add(string $type, string $actor = '', ?int $businessId = null, string $meta = ''): void
    {
        try {
            $ip = Http::ip();
            if (!RateLimiter::hit('seclog:' . $type . ':' . $ip, 30, 600)) {
                return;
            }
            Db::insert('security_events', [
                'type' => substr($type, 0, 40),
                'actor' => mb_substr($actor, 0, 190),
                'ip_hash' => substr(hash_hmac('sha256', $ip, Config::key() ?: 'dnl'), 0, 16),
                'user_agent' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '', 0, 160),
                'business_id' => $businessId,
                'meta' => mb_substr($meta, 0, 300),
                'created_at' => Db::now(),
            ]);
        } catch (\Throwable $e) {
            // Kayıt tablosu henüz yoksa (kurulum öncesi) sessizce geç
        }
    }
}
