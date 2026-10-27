<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\AdminAuth;
use Dnl\Core\Auth;
use Dnl\Core\Db;
use Dnl\Core\Http;
use Dnl\Core\HttpError;
use Dnl\Core\Input;
use Dnl\Core\Mailer;
use Dnl\Core\Tokens;
use Dnl\Services\Subscription;
use Dnl\Services\SystemSettings;

/**
 * Ana yönetici: işletmeler, paket/süre, ödemeler, dondurma, kullanıcı desteği, duyuru, kayıtlar.
 * Her değişiklik admin_audit tablosuna yazılır. İşletmelerin şifre/oturum bilgileri hiçbir zaman döndürülmez.
 */
final class AdminController
{
    private const STATES = ['all', 'trial', 'active', 'expired', 'suspended', 'expiring', 'lifetime'];

    private static function stateSql(string $state, array &$p): string
    {
        $p['now'] = Db::now();
        $notPaid = '(b.paid_until IS NULL OR b.paid_until <= :now)';
        switch ($state) {
            case 'trial': return " AND b.suspended_at IS NULL AND b.lifetime = 0 AND $notPaid AND b.trial_ends_at > :now";
            case 'active': return ' AND b.suspended_at IS NULL AND (b.paid_until > :now OR b.lifetime = 1)';
            case 'expired': return " AND b.suspended_at IS NULL AND b.lifetime = 0 AND $notPaid AND b.trial_ends_at <= :now";
            case 'lifetime': unset($p['now']); return ' AND b.lifetime = 1';
            case 'suspended': unset($p['now']); return ' AND b.suspended_at IS NOT NULL';
            case 'expiring':
                $p['soon'] = gmdate('Y-m-d H:i:s', time() + 7 * 86400);
                return " AND b.suspended_at IS NULL AND b.lifetime = 0 AND ((b.paid_until > :now AND b.paid_until <= :soon) OR ($notPaid AND b.trial_ends_at > :now AND b.trial_ends_at <= :soon))";
            default: unset($p['now']); return '';
        }
    }

    private static function bizOut(array $b): array
    {
        $s = Subscription::status($b);
        return [
            'id' => (int)$b['id'], 'name' => $b['name'], 'phone' => $b['phone'],
            'owner_name' => $b['owner_name'] ?? null, 'owner_email' => $b['owner_email'] ?? null, 'owner_phone' => $b['owner_phone'] ?? null,
            'plan' => $b['plan'], 'plan_name' => $s['plan_name'], 'period' => $b['billing_period'],
            'state' => $b['suspended_at'] !== null ? 'suspended' : $s['state'], 'days_left' => $s['days_left'], 'until' => Db::iso($s['until']),
            'trial_ends_at' => Db::iso($b['trial_ends_at']), 'paid_until' => Db::iso($b['paid_until']),
            'suspended_at' => Db::iso($b['suspended_at']), 'suspend_reason' => $b['suspend_reason'],
            'created_at' => Db::iso($b['created_at']), 'last_login_at' => Db::iso($b['last_login'] ?? null),
            'tables' => isset($b['tables']) ? (int)$b['tables'] : null,
            'lifetime' => (int)($b['lifetime'] ?? 0) === 1,
            'max_tables_override' => isset($b['max_tables_override']) && $b['max_tables_override'] !== null ? (int)$b['max_tables_override'] : null,
            'max_staff_override' => isset($b['max_staff_override']) && $b['max_staff_override'] !== null ? (int)$b['max_staff_override'] : null,
            'max_tables' => Subscription::limit($b, 'max_tables'),
            'max_staff' => Subscription::limit($b, 'max_staff'),
        ];
    }

    private static function business(int $id): array
    {
        $b = Db::one("SELECT * FROM businesses WHERE id = :id", ['id' => $id]);
        if (!$b) {
            throw new HttpError(404, 'İşletme bulunamadı.', 'not_found');
        }
        return $b;
    }

    public static function overview(): array
    {
        AdminAuth::require();
        $c = static function (string $state): int {
            $p = [];
            $w = self::stateSql($state, $p);
            return (int)Db::value('SELECT COUNT(*) FROM businesses b WHERE 1=1' . $w, $p);
        };
        $d30 = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $d7 = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $rev30 = (int)Db::value('SELECT COALESCE(SUM(amount_cents),0) FROM admin_payments WHERE voided_at IS NULL AND created_at >= :d', ['d' => $d30]);
        $revAll = (int)Db::value('SELECT COALESCE(SUM(amount_cents),0) FROM admin_payments WHERE voided_at IS NULL');
        $recent = Db::all("SELECT b.*, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone
            FROM businesses b LEFT JOIN users u ON u.business_id = b.id AND u.role = 'owner' ORDER BY b.id DESC LIMIT 8");
        $p = [];
        $w = self::stateSql('expiring', $p);
        $expiring = Db::all("SELECT b.*, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone
            FROM businesses b LEFT JOIN users u ON u.business_id = b.id AND u.role = 'owner' WHERE 1=1 $w ORDER BY b.id DESC LIMIT 20", $p);
        $activeToday = (int)Db::value('SELECT COUNT(DISTINCT business_id) FROM users WHERE last_login_at >= :d', ['d' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        return [
            'counts' => ['all' => $c('all'), 'trial' => $c('trial'), 'active' => $c('active'), 'expired' => $c('expired'), 'suspended' => $c('suspended'), 'expiring' => $c('expiring'), 'lifetime' => $c('lifetime')],
            'signups' => self::signupSeries(30),
            'alerts' => AdminSystemController::alerts(),
            'new_7d' => (int)Db::value('SELECT COUNT(*) FROM businesses WHERE created_at >= :d', ['d' => $d7]),
            'new_30d' => (int)Db::value('SELECT COUNT(*) FROM businesses WHERE created_at >= :d', ['d' => $d30]),
            'active_24h' => $activeToday,
            'revenue_30d_cents' => $rev30, 'revenue_all_cents' => $revAll,
            'recent' => array_map([self::class, 'bizOut'], $recent),
            'expiring' => array_map([self::class, 'bizOut'], $expiring),
            'plans' => array_map(static fn($k, $v) => ['key' => $k, 'name' => $v['name'], 'monthly' => $v['monthly'], 'yearly' => $v['yearly']], array_keys(Subscription::PLANS), Subscription::PLANS),
        ];
    }

    public static function businesses(): array
    {
        AdminAuth::require();
        $state = (string)(Http::query('state') ?? 'all');
        if (!in_array($state, self::STATES, true)) {
            $state = 'all';
        }
        $q = trim(mb_substr((string)(Http::query('q') ?? ''), 0, 80));
        $page = max(1, min(1000, (int)(Http::query('page') ?? 1)));
        $p = [];
        $w = self::stateSql($state, $p);
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)) . '%';
            $w .= " AND (LOWER(b.name) LIKE :q ESCAPE '!' OR LOWER(u.email) LIKE :q2 ESCAPE '!' OR u.phone LIKE :q3 ESCAPE '!' OR b.phone LIKE :q4 ESCAPE '!' OR LOWER(u.name) LIKE :q5 ESCAPE '!')";
            $p += ['q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
        }
        $base = "FROM businesses b LEFT JOIN users u ON u.business_id = b.id AND u.role = 'owner' WHERE 1=1 $w";
        $total = (int)Db::value("SELECT COUNT(*) $base", $p);
        $rows = Db::all("SELECT b.*, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone,
            (SELECT MAX(x.last_login_at) FROM users x WHERE x.business_id = b.id) AS last_login,
            (SELECT COUNT(*) FROM game_tables g WHERE g.business_id = b.id AND g.is_active = 1) AS tables
            $base ORDER BY b.id DESC LIMIT 25 OFFSET " . (($page - 1) * 25), $p);
        return ['total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / 25)), 'items' => array_map([self::class, 'bizOut'], $rows)];
    }

    public static function business_detail(array $prm): array
    {
        AdminAuth::require();
        $id = $prm['id'];
        $b = self::business($id);
        $owner = Db::one("SELECT name, email, phone FROM users WHERE business_id = :b AND role = 'owner' LIMIT 1", ['b' => $id]);
        $b['owner_name'] = $owner['name'] ?? null;
        $b['owner_email'] = $owner['email'] ?? null;
        $b['owner_phone'] = $owner['phone'] ?? null;
        $b['last_login'] = Db::value('SELECT MAX(last_login_at) FROM users WHERE business_id = :b', ['b' => $id]);
        $b['tables'] = Db::value('SELECT COUNT(*) FROM game_tables WHERE business_id = :b AND is_active = 1', ['b' => $id]);
        $now = Db::now();
        $users = Db::all("SELECT id, name, email, phone, role, is_active, email_verified_at, must_change_password, locked_until, last_login_at, created_at FROM users WHERE business_id = :b ORDER BY CASE role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END, id", ['b' => $id]);
        $d30 = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $settings = json_decode((string)($b['settings'] ?? ''), true) ?: [];
        return [
            'business' => self::bizOut($b) + ['admin_note' => (string)($b['admin_note'] ?? ''), 'types' => $settings['types'] ?? [], 'setup_done' => (int)$b['setup_done'] === 1, 'display_enabled' => $b['display_token_hash'] !== null],
            'users' => array_map(static fn($u) => [
                'id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'], 'role' => $u['role'],
                'is_active' => (int)$u['is_active'] === 1, 'verified' => $u['email_verified_at'] !== null, 'must_change_password' => (int)$u['must_change_password'] === 1,
                'locked' => $u['locked_until'] !== null && $u['locked_until'] > $now, 'last_login_at' => Db::iso($u['last_login_at']), 'created_at' => Db::iso($u['created_at']),
            ], $users),
            'usage' => [
                'tables' => (int)$b['tables'],
                'products' => (int)Db::value('SELECT COUNT(*) FROM products WHERE business_id = :b AND is_active = 1', ['b' => $id]),
                'sessions_30d' => (int)Db::value("SELECT COUNT(*) FROM play_sessions WHERE business_id = :b AND started_at >= :d", ['b' => $id, 'd' => $d30]),
                'tickets_30d' => (int)Db::value("SELECT COUNT(*) FROM tickets WHERE business_id = :b AND status = 'paid' AND closed_at >= :d", ['b' => $id, 'd' => $d30]),
                'revenue_30d_cents' => (int)Db::value('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE business_id = :b AND voided_at IS NULL AND created_at >= :d', ['b' => $id, 'd' => $d30]),
                'last_activity' => Db::iso(Db::value('SELECT MAX(created_at) FROM audit_logs WHERE business_id = :b', ['b' => $id])),
                'customers' => (int)Db::value('SELECT COUNT(*) FROM customers WHERE business_id = :b', ['b' => $id]),
                'staff' => (int)Db::value("SELECT COUNT(*) FROM users WHERE business_id = :b AND role <> 'owner' AND is_active = 1", ['b' => $id]),
                'revenue_all_cents' => (int)Db::value('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE business_id = :b AND voided_at IS NULL', ['b' => $id]),
                'open_sessions' => (int)Db::value("SELECT COUNT(*) FROM play_sessions WHERE business_id = :b AND status = 'active'", ['b' => $id]),
            ],
            'payments' => self::paymentRows('WHERE p.business_id = :b', ['b' => $id], 100),
            'emails' => array_map(static fn($r) => ['to' => $r['to_email'], 'subject' => $r['subject'], 'kind' => $r['kind'], 'status' => $r['status'], 'error' => $r['error'], 'at' => Db::iso($r['created_at'])],
                Db::all('SELECT * FROM email_log WHERE business_id = :b ORDER BY id DESC LIMIT 30', ['b' => $id])),
            'security' => array_map(static fn($r) => ['type' => $r['type'], 'actor' => $r['actor'], 'meta' => $r['meta'], 'at' => Db::iso($r['created_at'])],
                Db::all('SELECT * FROM security_events WHERE business_id = :b ORDER BY id DESC LIMIT 30', ['b' => $id])),
            'log' => self::auditRows('WHERE (a.entity = \'business\' AND a.entity_id = :b) OR (a.entity = \'user\' AND a.entity_id IN (SELECT id FROM users WHERE business_id = :b2))', ['b' => $id, 'b2' => $id], 50),
        ];
    }

    private static function paymentRows(string $where, array $p, int $limit): array
    {
        $rows = Db::all("SELECT p.*, b.name AS business_name, a.name AS admin_name FROM admin_payments p LEFT JOIN businesses b ON b.id = p.business_id LEFT JOIN admins a ON a.id = p.admin_id $where ORDER BY p.id DESC LIMIT " . $limit, $p);
        return array_map(static fn($r) => [
            'id' => (int)$r['id'], 'business_id' => (int)$r['business_id'], 'business_name' => $r['business_name'], 'amount_cents' => (int)$r['amount_cents'],
            'plan' => $r['plan'], 'period' => $r['period'], 'months' => (int)$r['months'], 'method' => $r['method'], 'note' => $r['note'],
            'paid_until_before' => Db::iso($r['paid_until_before']), 'paid_until_after' => Db::iso($r['paid_until_after']),
            'admin' => $r['admin_name'], 'voided' => $r['voided_at'] !== null, 'created_at' => Db::iso($r['created_at']),
        ], $rows);
    }

    private static function auditRows(string $where, array $p, int $limit): array
    {
        $rows = Db::all("SELECT a.*, ad.name AS admin_name FROM admin_audit a LEFT JOIN admins ad ON ad.id = a.admin_id $where ORDER BY a.id DESC LIMIT " . $limit, $p);
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'action' => $r['action'], 'entity' => $r['entity'], 'entity_id' => $r['entity_id'] !== null ? (int)$r['entity_id'] : null,
            'meta' => json_decode((string)$r['meta'], true), 'admin' => $r['admin_name'], 'at' => Db::iso($r['created_at'])], $rows);
    }

    /** Paket ve dönem değişikliği (süreye dokunmaz) */
    public static function setPlan(array $prm): array
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        $in = new Input(Http::json());
        $plan = $in->enum('plan', array_keys(Subscription::PLANS), null, 'Paket');
        $period = $in->enum('period', ['aylik', 'yillik'], null, 'Dönem');
        $in->check();
        Db::exec('UPDATE businesses SET plan = :p, billing_period = :d, updated_at = :n WHERE id = :id', ['p' => $plan, 'd' => $period, 'n' => Db::now(), 'id' => $b['id']]);
        AdminAuth::log($aid, 'business.plan', 'business', (int)$b['id'], ['from' => $b['plan'] . '/' . $b['billing_period'], 'to' => "$plan/$period"]);
        return self::business_detail($prm);
    }

    /** Deneme süresini uzatır (bugünden ya da mevcut bitişten itibaren) */
    public static function extendTrial(array $prm): array
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        $in = new Input(Http::json());
        $days = (int)$in->int('days', 1, 90, null, 'Gün');
        $in->check();
        $from = max(time(), strtotime($b['trial_ends_at'] . ' UTC'));
        $new = gmdate('Y-m-d H:i:s', $from + $days * 86400);
        Db::exec('UPDATE businesses SET trial_ends_at = :t, updated_at = :n WHERE id = :id', ['t' => $new, 'n' => Db::now(), 'id' => $b['id']]);
        AdminAuth::log($aid, 'business.trial', 'business', (int)$b['id'], ['days' => $days, 'until' => $new]);
        return self::business_detail($prm);
    }

    /** Ödeme kaydı: paket süresini ay olarak uzatır (bitmemişse kaldığı yerden) */
    public static function addPayment(array $prm): array
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        $in = new Input(Http::json());
        $plan = $in->enum('plan', array_keys(Subscription::PLANS), null, 'Paket');
        $period = $in->enum('period', ['aylik', 'yillik'], null, 'Dönem');
        $months = (int)$in->int('months', 1, 36, $period === 'yillik' ? 12 : 1, 'Ay');
        $amount = $in->money('amount', true, 100000000, 'Tutar');
        $method = $in->enum('method', ['havale', 'nakit', 'kart', 'diger'], 'havale', 'Ödeme yöntemi');
        $note = $in->str('note', 0, 200, false, 'Not');
        $in->check();
        $id = Db::tx(static function () use ($aid, $b, $plan, $period, $months, $amount, $method, $note) {
            // Eşzamanlı iki kayıtta süre iki kez hesaplanmasın: mevcut değer koşul olarak kullanılır
            $cur = Db::value('SELECT paid_until FROM businesses WHERE id = :id', ['id' => $b['id']]);
            $base = new \DateTimeImmutable($cur !== null && strtotime($cur . ' UTC') > time() ? $cur : 'now', new \DateTimeZone('UTC'));
            $after = $base->modify("+$months month")->format('Y-m-d H:i:s');
            $n = $cur === null
                ? Db::exec('UPDATE businesses SET paid_until = :a, plan = :p, billing_period = :d, updated_at = :n WHERE id = :id AND paid_until IS NULL', ['a' => $after, 'p' => $plan, 'd' => $period, 'n' => Db::now(), 'id' => $b['id']])
                : Db::exec('UPDATE businesses SET paid_until = :a, plan = :p, billing_period = :d, updated_at = :n WHERE id = :id AND paid_until = :c', ['a' => $after, 'p' => $plan, 'd' => $period, 'n' => Db::now(), 'id' => $b['id'], 'c' => $cur]);
            if ($n !== 1) {
                throw new HttpError(409, 'Kayıt bu sırada değişti. Sayfayı yenileyip tekrar deneyin.', 'conflict');
            }
            return Db::insert('admin_payments', [
                'business_id' => (int)$b['id'], 'amount_cents' => (int)$amount, 'plan' => $plan, 'period' => $period, 'months' => $months,
                'method' => $method, 'note' => $note, 'paid_until_before' => $cur, 'paid_until_after' => $after, 'admin_id' => $aid, 'voided_at' => null, 'created_at' => Db::now(),
            ]);
        });
        AdminAuth::log($aid, 'business.payment', 'business', (int)$b['id'], ['payment' => $id, 'amount' => $amount, 'months' => $months, 'plan' => $plan]);
        return self::business_detail($prm);
    }

    /** Hatalı ödeme kaydını iptal eder; yalnızca en son kayıt ve süre değişmemişse önceki bitişe döner */
    public static function voidPayment(array $prm): array
    {
        $aid = AdminAuth::id();
        $pay = Db::one('SELECT * FROM admin_payments WHERE id = :id', ['id' => $prm['id']]);
        if (!$pay || $pay['voided_at'] !== null) {
            throw new HttpError(404, 'Ödeme kaydı bulunamadı.', 'not_found');
        }
        $last = (int)Db::value('SELECT MAX(id) FROM admin_payments WHERE business_id = :b AND voided_at IS NULL', ['b' => $pay['business_id']]);
        if ($last !== (int)$pay['id']) {
            throw new HttpError(409, 'Yalnızca en son ödeme kaydı iptal edilebilir.', 'not_last');
        }
        Db::tx(static function () use ($pay) {
            $n = Db::exec('UPDATE businesses SET paid_until = :b, updated_at = :n WHERE id = :id AND paid_until = :a', ['b' => $pay['paid_until_before'], 'n' => Db::now(), 'id' => $pay['business_id'], 'a' => $pay['paid_until_after']]);
            if ($n !== 1) {
                throw new HttpError(409, 'Süre bu kayıttan sonra elle değiştirilmiş; iptal yerine süreyi düzeltin.', 'conflict');
            }
            Db::exec('UPDATE admin_payments SET voided_at = :n WHERE id = :id AND voided_at IS NULL', ['n' => Db::now(), 'id' => $pay['id']]);
        });
        AdminAuth::log($aid, 'business.payment_void', 'business', (int)$pay['business_id'], ['payment' => (int)$pay['id'], 'amount' => (int)$pay['amount_cents']]);
        return self::business_detail(['id' => (int)$pay['business_id']]);
    }

    /** Paket bitiş tarihini elle düzeltir (boş = ücretli dönem yok) */
    public static function setPaidUntil(array $prm): array
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        $data = Http::json();
        $in = new Input($data);
        $reason = $in->str('reason', 3, 200, true, 'Neden');
        $in->check();
        $date = $data['date'] ?? null;
        $val = null;
        if ($date !== null && $date !== '') {
            $d = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Istanbul')) : false;
            if (!$d || $d->format('Y-m-d') !== $date || $d->format('Y') < 2020 || $d->format('Y') > 2100) {
                throw new HttpError(422, 'Tarih geçersiz.', 'validation', ['date' => 'Tarih geçersiz.']);
            }
            $val = $d->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        Db::exec('UPDATE businesses SET paid_until = :v, updated_at = :n WHERE id = :id', ['v' => $val, 'n' => Db::now(), 'id' => $b['id']]);
        AdminAuth::log($aid, 'business.paid_until', 'business', (int)$b['id'], ['from' => $b['paid_until'], 'to' => $val, 'reason' => $reason]);
        return self::business_detail($prm);
    }

    public static function suspend(array $prm): array
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        $in = new Input(Http::json());
        $reason = $in->str('reason', 3, 200, true, 'Neden');
        $in->check();
        Db::exec('UPDATE businesses SET suspended_at = :n, suspend_reason = :r, updated_at = :n2 WHERE id = :id', ['n' => Db::now(), 'r' => $reason, 'n2' => Db::now(), 'id' => $b['id']]);
        // Tüm kullanıcıların açık oturumları ve "beni hatırla" anahtarları düşürülür
        foreach (Db::all('SELECT id FROM users WHERE business_id = :b', ['b' => $b['id']]) as $u) {
            Auth::invalidateAllSessions((int)$u['id']);
        }
        AdminAuth::log($aid, 'business.suspend', 'business', (int)$b['id'], ['reason' => $reason]);
        return self::business_detail($prm);
    }

    public static function unsuspend(array $prm): array
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        Db::exec("UPDATE businesses SET suspended_at = NULL, suspend_reason = '', updated_at = :n WHERE id = :id", ['n' => Db::now(), 'id' => $b['id']]);
        AdminAuth::log($aid, 'business.unsuspend', 'business', (int)$b['id']);
        return self::business_detail($prm);
    }

    public static function note(array $prm): array
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        $in = new Input(Http::json());
        $note = $in->str('note', 0, 2000, false, 'Not');
        $in->check();
        Db::exec('UPDATE businesses SET admin_note = :t WHERE id = :id', ['t' => $note, 'id' => $b['id']]);
        AdminAuth::log($aid, 'business.note', 'business', (int)$b['id']);
        return ['ok' => true];
    }

    private static function user(int $id): array
    {
        $u = Db::one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        if (!$u) {
            throw new HttpError(404, 'Kullanıcı bulunamadı.', 'not_found');
        }
        return $u;
    }

    /** Kullanıcı desteği: kilit açma, tüm oturumları kapatma, aktif/pasif, şifre sıfırlama bağlantısı */
    public static function userAction(array $prm): array
    {
        $aid = AdminAuth::id();
        $u = self::user($prm['id']);
        $in = new Input(Http::json());
        $action = $in->enum('action', ['unlock', 'logout_all', 'deactivate', 'activate', 'send_reset', 'resend_verify'], null, 'İşlem');
        $in->check();
        $id = (int)$u['id'];
        switch ($action) {
            case 'unlock':
                Db::exec('UPDATE users SET failed_logins = 0, locked_until = NULL, updated_at = :n WHERE id = :id', ['n' => Db::now(), 'id' => $id]);
                break;
            case 'logout_all':
                Auth::invalidateAllSessions($id);
                break;
            case 'deactivate':
                if ($u['role'] === 'owner') {
                    throw new HttpError(409, 'İşletme sahibi pasife alınamaz; gerekirse işletmeyi dondurun.', 'owner');
                }
                Db::exec('UPDATE users SET is_active = 0, updated_at = :n WHERE id = :id', ['n' => Db::now(), 'id' => $id]);
                Auth::invalidateAllSessions($id);
                break;
            case 'activate':
                Db::exec('UPDATE users SET is_active = 1, updated_at = :n WHERE id = :id', ['n' => Db::now(), 'id' => $id]);
                break;
            case 'send_reset':
                Tokens::revokeAll($id, 'reset');
                [$sel, $val] = Tokens::create($id, 'reset', 3600);
                if (!\Dnl\Services\Emails::reset($u['email'], $u['name'], Mailer::url('/sifre-yenile/#t=' . $sel . '.' . $val), true, (int)$u['business_id'])) {
                    throw new HttpError(502, 'Şifre sıfırlama e-postası gönderilemedi: ' . (Mailer::$lastError ?: 'bilinmeyen hata'), 'mail_failed');
                }
                break;
            case 'resend_verify':
                if ($u['email_verified_at'] !== null) {
                    throw new HttpError(409, 'E-posta zaten doğrulanmış.', 'verified');
                }
                Tokens::revokeAll($id, 'verify');
                [$sel, $val] = Tokens::create($id, 'verify', 172800);
                if (!\Dnl\Services\Emails::verify($u['email'], $u['name'], Mailer::url('/dogrula/#t=' . $sel . '.' . $val), (int)$u['business_id'])) {
                    throw new HttpError(502, 'Doğrulama e-postası gönderilemedi: ' . (Mailer::$lastError ?: 'bilinmeyen hata'), 'mail_failed');
                }
                break;
        }
        AdminAuth::log($aid, 'user.' . $action, 'user', $id, ['business' => (int)$u['business_id']]);
        return self::business_detail(['id' => (int)$u['business_id']]);
    }

    public static function payments(): array
    {
        AdminAuth::require();
        $items = self::paymentRows('', [], 300);
        $months = [];
        foreach ($items as $p) {
            if ($p['voided']) {
                continue;
            }
            $m = (new \DateTimeImmutable($p['created_at']))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m');
            $months[$m] = ($months[$m] ?? 0) + $p['amount_cents'];
        }
        krsort($months);
        return ['items' => $items, 'by_month' => array_map(static fn($k, $v) => ['month' => $k, 'total_cents' => $v], array_keys($months), $months)];
    }

    public static function audit(): array
    {
        AdminAuth::require();
        $before = (int)(Http::query('before') ?? 0);
        return ['items' => $before > 0 ? self::auditRows('WHERE a.id < :x', ['x' => $before], 100) : self::auditRows('', [], 100)];
    }

    /** Tüm işletme panellerinde görünen duyuru */
    public static function announcement(): array
    {
        AdminAuth::require();
        return ['announcement' => self::currentAnnouncement(true)];
    }

    public static function currentAnnouncement(bool $all = false): ?array
    {
        $v = Db::value("SELECT v FROM system_settings WHERE k = 'announcement'");
        $a = $v ? json_decode((string)$v, true) : null;
        if (!is_array($a) || (!$all && empty($a['active']))) {
            return null;
        }
        return $a;
    }

    public static function setAnnouncement(): array
    {
        $aid = AdminAuth::id();
        $in = new Input(Http::json());
        $text = $in->str('text', 0, 300, false, 'Duyuru');
        $level = $in->enum('level', ['info', 'warn'], 'info', 'Tür');
        $active = $in->bool('active');
        if ($active && $text === '') {
            $in->error('text', 'Duyuru metni boş olamaz.');
        }
        $in->check();
        $val = json_encode(['text' => $text, 'level' => $level, 'active' => $active, 'id' => bin2hex(random_bytes(4))], JSON_UNESCAPED_UNICODE);
        Db::tx(static function () use ($val) {
            Db::exec("DELETE FROM system_settings WHERE k = 'announcement'");
            Db::insert('system_settings', ['k' => 'announcement', 'v' => $val, 'updated_at' => Db::now()]);
        });
        AdminAuth::log($aid, 'system.announcement', 'system', null, ['active' => $active, 'text' => mb_substr($text, 0, 80)]);
        return ['announcement' => self::currentAnnouncement(true)];
    }

    public static function settings(): array
    {
        AdminAuth::require();
        return ['settings' => SystemSettings::all()];
    }

    public static function saveSettings(): array
    {
        $aid = AdminAuth::id();
        $in = new Input(Http::json());
        $vals = [
            'trial_days' => (int)$in->int('trial_days', 1, 60, null, 'Deneme süresi'),
            'payment_info' => (string)($in->has('payment_info') ? self::multiline((string)(Http::json()['payment_info'] ?? ''), 600) : ''),
            'whatsapp' => $in->phone('whatsapp', false),
            'support_email' => $in->has('support_email') ? $in->email('support_email') : '',
        ];
        $in->check();
        $before = SystemSettings::all();
        $after = SystemSettings::save($vals);
        AdminAuth::log($aid, 'system.settings', 'system', null, ['trial_days' => [$before['trial_days'], $after['trial_days']]]);
        return ['settings' => $after];
    }

    /** Çok satırlı metin: kontrol karakterleri temizlenir, satır sonları korunur */
    private static function multiline(string $v, int $max): string
    {
        $v = str_replace("\r\n", "\n", $v);
        $v = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $v) ?? '';
        return mb_substr(trim($v), 0, $max);
    }

    private static function signupSeries(int $days): array
    {
        $tz = new \DateTimeZone('Europe/Istanbul');
        $from = (new \DateTimeImmutable('now', $tz))->setTime(0, 0)->modify('-' . ($days - 1) . ' day');
        $cnt = [];
        foreach (Db::all('SELECT created_at FROM businesses WHERE created_at >= :f', ['f' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]) as $r) {
            $k = (new \DateTimeImmutable($r['created_at'], new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
            $cnt[$k] = ($cnt[$k] ?? 0) + 1;
        }
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $k = $from->modify("+$i day")->format('Y-m-d');
            $out[] = ['date' => $k, 'count' => $cnt[$k] ?? 0];
        }
        return $out;
    }

    /** İşletmenin panel işlem kaydı (destek için) */
    public static function activity(array $prm): array
    {
        AdminAuth::require();
        $b = self::business($prm['id']);
        $before = (int)(Http::query('before') ?? 0);
        $rows = Db::all('SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.business_id = :b' . ($before > 0 ? ' AND a.id < :x' : '') . ' ORDER BY a.id DESC LIMIT 100', $before > 0 ? ['b' => $b['id'], 'x' => $before] : ['b' => $b['id']]);
        return ['items' => array_map(static fn($r) => ['id' => (int)$r['id'], 'action' => $r['action'], 'entity' => $r['entity'], 'meta' => json_decode((string)$r['meta'], true), 'user' => $r['user_name'], 'at' => Db::iso($r['created_at'])], $rows)];
    }

    /** Ömür boyu üyelik aç/kapat */
    public static function setLifetime(array $prm): array
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        $in = new Input(Http::json());
        $on = $in->bool('on');
        $reason = $in->str('reason', 3, 200, true, 'Neden');
        $in->check();
        Db::exec('UPDATE businesses SET lifetime = :l, updated_at = :n WHERE id = :id', ['l' => $on ? 1 : 0, 'n' => Db::now(), 'id' => $b['id']]);
        AdminAuth::log($aid, $on ? 'business.lifetime_on' : 'business.lifetime_off', 'business', (int)$b['id'], ['reason' => $reason]);
        return self::business_detail($prm);
    }

    /** İşletmeye özel masa/personel sınırı (boş = paketin sınırı) */
    public static function setLimits(array $prm): array
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        $in = new Input(Http::json());
        $t = $in->intOrNull('max_tables', 1, 1000, 'Masa sınırı');
        $st = $in->intOrNull('max_staff', 0, 500, 'Personel sınırı');
        $in->check();
        Db::exec('UPDATE businesses SET max_tables_override = :t, max_staff_override = :s, updated_at = :n WHERE id = :id', ['t' => $t, 's' => $st, 'n' => Db::now(), 'id' => $b['id']]);
        AdminAuth::log($aid, 'business.limits', 'business', (int)$b['id'], ['tables' => $t, 'staff' => $st]);
        return self::business_detail($prm);
    }

    public static function updateBusiness(array $prm): array
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        $in = new Input(Http::json());
        $name = $in->str('name', 2, 80, true, 'İşletme adı');
        $phone = $in->phone('phone', false);
        $in->check();
        Db::exec('UPDATE businesses SET name = :nm, phone = :p, updated_at = :n WHERE id = :id', ['nm' => $name, 'p' => $phone, 'n' => Db::now(), 'id' => $b['id']]);
        AdminAuth::log($aid, 'business.info', 'business', (int)$b['id'], ['name' => [$b['name'], $name]]);
        return self::business_detail($prm);
    }

    /** Kullanıcının giriş e-postasını değiştirir (oturumları kapatır, yeni adrese doğrulama gönderir) */
    public static function changeUserEmail(array $prm): array
    {
        $aid = AdminAuth::id();
        $u = self::user($prm['id']);
        $in = new Input(Http::json());
        $email = $in->email('email');
        $in->check();
        try {
            Db::exec('UPDATE users SET email = :e, email_verified_at = NULL, updated_at = :n WHERE id = :id', ['e' => $email, 'n' => Db::now(), 'id' => $u['id']]);
        } catch (\PDOException $e) {
            if (Db::isDuplicate($e)) {
                throw new HttpError(422, 'Bu e-posta başka bir hesapta kullanılıyor.', 'validation', ['email' => 'Bu e-posta başka bir hesapta kullanılıyor.']);
            }
            throw $e;
        }
        Auth::invalidateAllSessions((int)$u['id']);
        Tokens::revokeAll((int)$u['id'], 'verify');
        [$sel, $val] = Tokens::create((int)$u['id'], 'verify', 172800);
        \Dnl\Services\Emails::verify($email, $u['name'], Mailer::url('/dogrula/#t=' . $sel . '.' . $val), (int)$u['business_id']);
        AdminAuth::log($aid, 'user.email_changed', 'user', (int)$u['id'], ['business' => (int)$u['business_id'], 'from' => $u['email'], 'to' => $email]);
        return self::business_detail(['id' => (int)$u['business_id']]);
    }

    /** İşletme sahipliğini başka bir kullanıcıya devreder (eski sahip müdür olur) */
    public static function makeOwner(array $prm): array
    {
        $aid = AdminAuth::id();
        $u = self::user($prm['id']);
        if ($u['role'] === 'owner') {
            throw new HttpError(409, 'Bu kullanıcı zaten işletme sahibi.', 'conflict');
        }
        if ((int)$u['is_active'] !== 1) {
            throw new HttpError(409, 'Pasif kullanıcı sahip yapılamaz.', 'inactive');
        }
        $bid = (int)$u['business_id'];
        $old = Db::one("SELECT id FROM users WHERE business_id = :b AND role = 'owner' LIMIT 1", ['b' => $bid]);
        Db::tx(static function () use ($u, $old) {
            if ($old) {
                Db::exec("UPDATE users SET role = 'manager', session_version = session_version + 1, updated_at = :n WHERE id = :id", ['n' => Db::now(), 'id' => $old['id']]);
            }
            Db::exec("UPDATE users SET role = 'owner', session_version = session_version + 1, updated_at = :n WHERE id = :id", ['n' => Db::now(), 'id' => $u['id']]);
        });
        AdminAuth::log($aid, 'business.owner_transfer', 'business', $bid, ['to' => (int)$u['id'], 'from' => $old ? (int)$old['id'] : null]);
        return self::business_detail(['id' => $bid]);
    }

    /** İşletme sahibine panelden e-posta */
    public static function emailBusiness(array $prm): array
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        $in = new Input(Http::json());
        $subject = $in->str('subject', 3, 120, true, 'Konu');
        $msg = (string)(Http::json()['message'] ?? '');
        $msg = mb_substr(trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $msg)) ?? ''), 0, 4000);
        if (mb_strlen($msg) < 5) {
            $in->error('message', 'Mesaj en az 5 karakter olmalı.');
        }
        $in->check();
        $owner = Db::one("SELECT name, email FROM users WHERE business_id = :b AND role = 'owner' LIMIT 1", ['b' => $b['id']]);
        if (!$owner) {
            throw new HttpError(404, 'İşletme sahibi bulunamadı.', 'not_found');
        }
        $ok = \Dnl\Services\Emails::adminMessage($owner['email'], $owner['name'], $subject, $msg, (int)$b['id']);
        AdminAuth::log($aid, 'business.email', 'business', (int)$b['id'], ['subject' => $subject, 'sent' => $ok]);
        if (!$ok) {
            throw new HttpError(502, 'E-posta gönderilemedi: ' . (Mailer::$lastError ?: 'bilinmeyen hata'), 'mail_failed');
        }
        return ['ok' => true];
    }

    /** KVKK / yedek: işletmenin tüm verisi JSON (şifre ve anahtarlar hariç) */
    public static function export(array $prm): never
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        $bid = (int)$b['id'];
        unset($b['display_token_hash']);
        $q = static fn(string $t, string $cols = '*') => Db::all("SELECT $cols FROM $t WHERE business_id = :b ORDER BY id", ['b' => $bid]);
        $data = [
            'exported_at' => gmdate('c'), 'business' => $b,
            'users' => $q('users', 'id, name, email, phone, role, is_active, email_verified_at, last_login_at, created_at'),
            'table_types' => $q('table_types'), 'tables' => $q('game_tables'), 'products' => $q('products'), 'customers' => $q('customers'),
            'customer_moves' => $q('customer_moves'), 'shifts' => $q('shifts'), 'tickets' => $q('tickets'), 'ticket_items' => $q('ticket_items'),
            'payments' => $q('payments'), 'sessions' => $q('play_sessions'), 'expenses' => $q('expenses'), 'reservations' => $q('reservations'), 'stock_moves' => $q('stock_moves'),
            'audit_logs' => $q('audit_logs', 'id, user_id, action, entity, entity_id, meta, created_at'),
        ];
        AdminAuth::log($aid, 'business.export', 'business', $bid);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="dnlstation-isletme-' . $bid . '-' . gmdate('Ymd') . '.json"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /** İşletmeyi tüm verileriyle kalıcı siler. Onay için işletme adı birebir yazılmalı ve hesap önceden dondurulmuş olmalı. */
    public static function deleteBusiness(array $prm): array
    {
        $aid = AdminAuth::id();
        $b = self::business($prm['id']);
        $in = new Input(Http::json());
        $confirm = $in->str('confirm_name', 1, 80, true, 'Onay');
        $in->check();
        if ($b['suspended_at'] === null) {
            throw new HttpError(409, 'Silmeden önce işletmeyi dondurun (yanlışlıkla silmeye karşı güvenlik adımı).', 'not_suspended');
        }
        if ($confirm !== $b['name']) {
            throw new HttpError(422, 'Onay için işletme adını birebir yazın.', 'validation', ['confirm_name' => 'İşletme adı eşleşmiyor.']);
        }
        $bid = (int)$b['id'];
        Db::tx(static function () use ($bid) {
            $uids = array_map('intval', array_column(Db::all('SELECT id FROM users WHERE business_id = :b', ['b' => $bid]), 'id'));
            foreach ($uids as $u) {
                Db::exec('DELETE FROM auth_tokens WHERE user_id = :u', ['u' => $u]);
            }
            Db::exec('DELETE FROM session_segments WHERE session_id IN (SELECT id FROM play_sessions WHERE business_id = :b)', ['b' => $bid]);
            foreach (['play_sessions', 'payments', 'ticket_items', 'customer_moves', 'customers', 'expenses', 'reservations', 'stock_moves', 'tickets', 'products', 'shifts', 'game_tables', 'table_types', 'audit_logs', 'users'] as $t) {
                Db::exec("DELETE FROM $t WHERE business_id = :b", ['b' => $bid]);
            }
            Db::exec('UPDATE email_log SET business_id = NULL WHERE business_id = :b', ['b' => $bid]);
            Db::exec('UPDATE security_events SET business_id = NULL WHERE business_id = :b', ['b' => $bid]);
            Db::exec('DELETE FROM businesses WHERE id = :b', ['b' => $bid]);
        });
        AdminAuth::log($aid, 'business.deleted', 'business', $bid, ['name' => $b['name']]);
        return ['deleted' => true];
    }
}
