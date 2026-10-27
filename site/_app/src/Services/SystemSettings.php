<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

use Dnl\Core\Db;

/** Ana yöneticinin panelden değiştirdiği genel ayarlar (deneme süresi, ödeme bilgileri, iletişim). */
final class SystemSettings
{
    public const DEFAULTS = [
        'trial_days' => 7,
        'payment_info' => '',
        'whatsapp' => '',
        'support_email' => '',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $v = null;
            try {
                $v = Db::value("SELECT v FROM system_settings WHERE k = 'general'");
            } catch (\PDOException) {
            }
            $data = $v ? (json_decode((string)$v, true) ?: []) : [];
            self::$cache = array_replace(self::DEFAULTS, array_intersect_key($data, self::DEFAULTS));
        }
        return self::$cache;
    }

    public static function get(string $k): mixed
    {
        return self::all()[$k] ?? null;
    }

    public static function save(array $vals): array
    {
        $data = array_replace(self::all(), array_intersect_key($vals, self::DEFAULTS));
        Db::tx(static function () use ($data) {
            Db::exec("DELETE FROM system_settings WHERE k = 'general'");
            Db::insert('system_settings', ['k' => 'general', 'v' => json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at' => Db::now()]);
        });
        self::$cache = $data;
        return $data;
    }

    public static function trialDays(): int
    {
        return max(1, min(60, (int)self::get('trial_days')));
    }
}
