<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Auth;
use Dnl\Core\HttpError;
use Dnl\Services\Subscription;
use Dnl\Services\SystemSettings;

/** İşletme sahibi için paket durumu ve ödeme talimatı */
final class BillingController
{
    public static function show(): array
    {
        $u = Auth::require();
        $b = Auth::business();
        if ($u['role'] !== 'owner') {
            throw new HttpError(403, 'Paket bilgilerini yalnızca işletme sahibi görebilir.', 'forbidden');
        }
        $s = SystemSettings::all();
        return [
            'subscription' => Subscription::status($b),
            'reference' => 'DNL-' . str_pad((string)$b['id'], 5, '0', STR_PAD_LEFT),
            'plans' => array_map(static fn($k, $v) => ['key' => $k, 'name' => $v['name'], 'monthly' => $v['monthly'], 'yearly' => $v['yearly'], 'max_tables' => $v['max_tables'], 'max_staff' => $v['max_staff']], array_keys(Subscription::PLANS), Subscription::PLANS),
            'lifetime' => ['name' => Subscription::LIFETIME['name'], 'price' => Subscription::LIFETIME['price'], 'owned' => (int)($b['lifetime'] ?? 0) === 1],
            'payment_info' => $s['payment_info'],
            'whatsapp' => $s['whatsapp'],
            'support_email' => $s['support_email'],
        ];
    }
}
