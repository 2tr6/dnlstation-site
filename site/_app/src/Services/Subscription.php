<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

use Dnl\Core\Db;

/** Deneme / paket durumu ve paket sınırları. */
final class Subscription
{
    public const PLANS = [
        'baslangic' => ['name' => 'Başlangıç', 'monthly' => 15900, 'yearly' => 159000, 'max_tables' => 8, 'max_staff' => 2],
        'pro' => ['name' => 'Pro', 'monthly' => 25900, 'yearly' => 259000, 'max_tables' => 1000, 'max_staff' => 50],
        'salon' => ['name' => 'Salon+', 'monthly' => 44900, 'yearly' => 449000, 'max_tables' => 1000, 'max_staff' => 200],
    ];

    public static function active(?array $b): bool
    {
        if ($b === null) {
            return false;
        }
        $now = Db::now();
        return $b['trial_ends_at'] > $now || ($b['paid_until'] !== null && $b['paid_until'] > $now);
    }

    public static function status(array $b): array
    {
        $now = Db::now();
        $paid = $b['paid_until'] !== null && $b['paid_until'] > $now;
        $trial = !$paid && $b['trial_ends_at'] > $now;
        $until = $paid ? $b['paid_until'] : $b['trial_ends_at'];
        $days = (int)max(0, ceil((strtotime($until . ' UTC') - time()) / 86400));
        return [
            'plan' => $b['plan'],
            'plan_name' => self::PLANS[$b['plan']]['name'] ?? $b['plan'],
            'period' => $b['billing_period'],
            'state' => $paid ? 'active' : ($trial ? 'trial' : 'expired'),
            'until' => $until,
            'days_left' => $days,
        ];
    }

    public static function limit(array $b, string $key): int
    {
        return (int)(self::PLANS[$b['plan']][$key] ?? self::PLANS['pro'][$key]);
    }
}
