<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Audit;
use Dnl\Core\Auth;
use Dnl\Core\Db;
use Dnl\Core\Http;
use Dnl\Core\HttpError;
use Dnl\Core\Input;
use Dnl\Services\Perm;
use Dnl\Services\Subscription;

/**
 * Personel yönetimi (yalnızca işletme sahibi).
 * Yeni personele geçici şifre üretilir; ilk girişte değiştirmesi zorunludur.
 */
final class StaffController
{
    private static function out(array $u): array
    {
        return ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'], 'role' => $u['role'],
            'is_active' => (int)$u['is_active'] === 1, 'must_change_password' => (int)$u['must_change_password'] === 1,
            'last_login_at' => $u['last_login_at'] ? Db::iso($u['last_login_at']) : null];
    }

    public static function index(): array
    {
        [, $b] = Perm::need('staff.manage');
        $rows = Db::all("SELECT * FROM users WHERE business_id = :b ORDER BY CASE role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END, name", ['b' => $b['id']]);
        return ['users' => array_map([self::class, 'out'], $rows), 'max_staff' => Subscription::limit($b, 'max_staff')];
    }

    /** Okunması kolay geçici şifre (karışan karakterler yok) */
    private static function tempPassword(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < 12; $i++) {
            $s .= $chars[random_int(0, strlen($chars) - 1)];
            if ($i === 3 || $i === 7) {
                $s .= '-';
            }
        }
        return $s;
    }

    private static function target(int $bid, int $id): array
    {
        $u = Db::one('SELECT * FROM users WHERE id = :id AND business_id = :b', ['id' => $id, 'b' => $bid]);
        if (!$u) {
            throw new HttpError(404, 'Personel bulunamadı.', 'not_found');
        }
        if ($u['role'] === 'owner') {
            throw new HttpError(403, 'İşletme sahibi hesabı buradan değiştirilemez.', 'forbidden');
        }
        return $u;
    }

    private static function assertLimit(array $b): void
    {
        $max = Subscription::limit($b, 'max_staff');
        $n = (int)Db::value("SELECT COUNT(*) FROM users WHERE business_id = :b AND role <> 'owner' AND is_active = 1", ['b' => $b['id']]);
        if ($n >= $max) {
            throw new HttpError(422, "Paketinizde en fazla $max aktif personel olabilir.", 'plan_limit');
        }
    }

    public static function create(): array
    {
        [$owner, $b] = Perm::need('staff.manage');
        $in = new Input(Http::json());
        $name = $in->str('name', 2, 80, true, 'Ad soyad');
        $email = $in->email('email');
        $phone = $in->phone('phone', false);
        $role = $in->enum('role', ['manager', 'staff'], 'staff', 'Rol');
        $in->check();
        self::assertLimit($b);
        $temp = self::tempPassword();
        $now = Db::now();
        try {
            $id = Db::insert('users', [
                'business_id' => (int)$b['id'], 'name' => $name, 'email' => $email, 'phone' => $phone, 'password_hash' => Auth::hash($temp),
                'role' => $role, 'email_verified_at' => null, 'must_change_password' => 1, 'is_active' => 1, 'session_version' => 1,
                'failed_logins' => 0, 'locked_until' => null, 'last_login_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (\PDOException $e) {
            if (Db::isDuplicate($e)) {
                throw new HttpError(422, 'Bu e-posta adresi zaten kullanılıyor.', 'validation', ['email' => 'Bu e-posta adresi zaten kullanılıyor.']);
            }
            throw $e;
        }
        Audit::log((int)$b['id'], (int)$owner['id'], 'staff.create', 'user', $id, ['role' => $role]);
        // Geçici şifre yalnızca bu yanıtta bir kez gösterilir, hiçbir yerde saklanmaz
        return ['temp_password' => $temp, 'user_id' => $id] + self::index();
    }

    public static function update(array $p): array
    {
        [$owner, $b] = Perm::need('staff.manage');
        $t = self::target((int)$b['id'], $p['id']);
        $in = new Input(Http::json());
        $name = $in->str('name', 2, 80, true, 'Ad soyad');
        $phone = $in->phone('phone', false);
        $role = $in->enum('role', ['manager', 'staff'], 'staff', 'Rol');
        $active = $in->bool('is_active', true);
        $in->check();
        if ($active && (int)$t['is_active'] !== 1) {
            self::assertLimit($b);
        }
        Db::exec('UPDATE users SET name = :n, phone = :ph, role = :r, is_active = :a, updated_at = :u WHERE id = :id AND business_id = :b', ['n' => $name, 'ph' => $phone, 'r' => $role, 'a' => $active ? 1 : 0, 'u' => Db::now(), 'id' => $t['id'], 'b' => $b['id']]);
        // Rol düşürüldüyse ya da pasife alındıysa açık oturumları hemen düşür
        if (!$active || $role !== $t['role']) {
            Auth::invalidateAllSessions((int)$t['id']);
        }
        Audit::log((int)$b['id'], (int)$owner['id'], 'staff.update', 'user', (int)$t['id'], ['role' => [$t['role'], $role], 'active' => $active]);
        return self::index();
    }

    public static function resetPassword(array $p): array
    {
        [$owner, $b] = Perm::need('staff.manage');
        $t = self::target((int)$b['id'], $p['id']);
        $temp = self::tempPassword();
        Db::exec('UPDATE users SET password_hash = :h, must_change_password = 1, failed_logins = 0, locked_until = NULL, updated_at = :n WHERE id = :id AND business_id = :b', ['h' => Auth::hash($temp), 'n' => Db::now(), 'id' => $t['id'], 'b' => $b['id']]);
        Auth::invalidateAllSessions((int)$t['id']);
        Audit::log((int)$b['id'], (int)$owner['id'], 'staff.reset_password', 'user', (int)$t['id']);
        return ['temp_password' => $temp] + self::index();
    }
}
