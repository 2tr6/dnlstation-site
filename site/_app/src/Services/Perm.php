<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

/** Rol bazlı yetkiler. owner: işletme sahibi, manager: müdür, staff: personel. */
final class Perm
{
    public const MATRIX = [
        'tables.operate'   => ['owner', 'manager', 'staff'],  // masa aç/kapat, süre, ürün ekle, hesap al
        'sales.quick'      => ['owner', 'manager', 'staff'],
        'shift.operate'    => ['owner', 'manager', 'staff'],
        'discount.apply'   => ['owner', 'manager'],           // personel için ayarla açılabilir
        'session.cancel'   => ['owner', 'manager'],
        'session.reopen'   => ['owner', 'manager'],
        'products.manage'  => ['owner', 'manager'],
        'stock.manage'     => ['owner', 'manager'],
        'reports.view'     => ['owner', 'manager'],
        'settings.tables'  => ['owner', 'manager'],
        'settings.business'=> ['owner'],
        'staff.manage'     => ['owner'],
        'display.manage'   => ['owner', 'manager'],
        'audit.view'       => ['owner'],
    ];

    public static function roles(string $perm): array
    {
        return self::MATRIX[$perm] ?? ['owner'];
    }

    public static function can(array $user, string $perm, ?array $business = null): bool
    {
        if (in_array($user['role'], self::roles($perm), true)) {
            return true;
        }
        if ($perm === 'discount.apply' && $user['role'] === 'staff' && $business !== null) {
            $s = json_decode((string)($business['settings'] ?? ''), true) ?: [];
            return !empty($s['staff_can_discount']);
        }
        return false;
    }

    /**
     * Oturumdaki kullanıcının yetkisini doğrular.
     * @return array{0:array,1:array} [kullanıcı, işletme]
     */
    public static function need(string $perm): array
    {
        $u = \Dnl\Core\Auth::require();
        $b = \Dnl\Core\Auth::business();
        if ($b === null || !self::can($u, $perm, $b)) {
            throw new \Dnl\Core\HttpError(403, 'Bu işlem için yetkiniz yok.', 'forbidden');
        }
        return [$u, $b];
    }

    /** @return string[] kullanıcının sahip olduğu yetkiler (arayüz için) */
    public static function list(array $user, array $business): array
    {
        $out = [];
        foreach (array_keys(self::MATRIX) as $p) {
            if (self::can($user, $p, $business)) {
                $out[] = $p;
            }
        }
        return $out;
    }
}
