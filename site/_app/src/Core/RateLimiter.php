<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * Sabit pencereli istek sınırlayıcı (veritabanında tutulur).
 * Anahtarlar (IP, e-posta) HMAC ile özetlenerek saklanır; ham kişisel veri tutulmaz.
 */
final class RateLimiter
{
    private static function k(string $key): string
    {
        return substr(hash_hmac('sha256', $key, Config::key() ?: 'dnl'), 0, 64);
    }

    /** İsteği sayar; sınır aşıldıysa false döner. */
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $k = self::k($key);
        $now = Db::now();
        $reset = gmdate('Y-m-d H:i:s', time() + $windowSeconds);
        for ($i = 0; $i < 3; $i++) {
            $n = Db::exec('UPDATE rate_limits SET hits = hits + 1 WHERE k = :k AND reset_at > :now', ['k' => $k, 'now' => $now]);
            if ($n > 0) {
                $hits = (int)Db::value('SELECT hits FROM rate_limits WHERE k = :k', ['k' => $k]);
                return $hits <= $max;
            }
            // Pencere yok ya da süresi dolmuş: sıfırla
            $n = Db::exec('UPDATE rate_limits SET hits = 1, reset_at = :r WHERE k = :k AND reset_at <= :now', ['k' => $k, 'r' => $reset, 'now' => $now]);
            if ($n > 0) {
                return 1 <= $max;
            }
            try {
                Db::insert('rate_limits', ['k' => $k, 'hits' => 1, 'reset_at' => $reset]);
                return 1 <= $max;
            } catch (\PDOException $e) {
                if (!Db::isDuplicate($e)) {
                    throw $e;
                }
                // Eşzamanlı ekleme: tekrar dene
            }
        }
        return false;
    }

    /** Saymadan, sınırın aşılıp aşılmadığını kontrol eder. */
    public static function blocked(string $key, int $max): bool
    {
        $row = Db::one('SELECT hits, reset_at FROM rate_limits WHERE k = :k', ['k' => self::k($key)]);
        return $row !== null && $row['reset_at'] > Db::now() && (int)$row['hits'] >= $max;
    }

    public static function retryAfter(string $key): int
    {
        $r = Db::value('SELECT reset_at FROM rate_limits WHERE k = :k', ['k' => self::k($key)]);
        return $r ? max(1, strtotime($r . ' UTC') - time()) : 0;
    }

    public static function clear(string $key): void
    {
        Db::exec('DELETE FROM rate_limits WHERE k = :k', ['k' => self::k($key)]);
    }

    /** Sınır aşıldığında 429 fırlatır. */
    public static function enforce(string $key, int $max, int $windowSeconds, string $message = 'Çok fazla deneme yaptınız. Lütfen biraz bekleyip tekrar deneyin.'): void
    {
        if (!self::hit($key, $max, $windowSeconds)) {
            $wait = self::retryAfter($key);
            header('Retry-After: ' . $wait);
            throw new HttpError(429, $message, 'rate_limited');
        }
    }
}
