<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Audit;
use Dnl\Core\Db;
use Dnl\Core\Http;
use Dnl\Core\HttpError;
use Dnl\Core\Input;
use Dnl\Core\RateLimiter;
use Dnl\Services\Games;
use Dnl\Services\Perm;

/** İşletme ayarları, müşteri ekranı ve işlem kayıtları */
final class SettingsController
{
    private static function settings(array $b): array
    {
        return (json_decode((string)($b['settings'] ?? ''), true) ?: []) + ['show_prices_on_display' => true, 'show_free_on_display' => true, 'warn_minutes' => 5, 'staff_can_discount' => false, 'email_z_report' => true, 'types' => []];
    }

    public static function show(): array
    {
        [, $b] = Perm::need('settings.business');
        $s = self::settings($b);
        return [
            'name' => $b['name'], 'phone' => $b['phone'],
            'warn_minutes' => (int)$s['warn_minutes'], 'staff_can_discount' => (bool)$s['staff_can_discount'],
            'show_prices_on_display' => (bool)$s['show_prices_on_display'], 'show_free_on_display' => (bool)$s['show_free_on_display'],
            'email_z_report' => (bool)$s['email_z_report'],
            'display_enabled' => $b['display_token_hash'] !== null,
        ];
    }

    public static function update(): array
    {
        [$u, $b] = Perm::need('settings.business');
        $in = new Input(Http::json());
        $name = $in->str('name', 2, 80, true, 'İşletme adı');
        $phone = $in->phone('phone', false);
        $warn = $in->int('warn_minutes', 0, 60, 5, 'Uyarı süresi');
        $s = self::settings($b);
        $s['staff_can_discount'] = $in->bool('staff_can_discount');
        $s['show_prices_on_display'] = $in->bool('show_prices_on_display', true);
        $s['show_free_on_display'] = $in->bool('show_free_on_display', true);
        $s['email_z_report'] = $in->bool('email_z_report', true);
        $s['warn_minutes'] = (int)$warn;
        $in->check();
        Db::exec('UPDATE businesses SET name = :n, phone = :p, settings = :s, updated_at = :u WHERE id = :b', ['n' => $name, 'p' => $phone, 's' => json_encode($s, JSON_UNESCAPED_UNICODE), 'u' => Db::now(), 'b' => $b['id']]);
        Audit::log((int)$b['id'], (int)$u['id'], 'business.update', 'business', (int)$b['id']);
        $b = Db::one('SELECT * FROM businesses WHERE id = :b', ['b' => $b['id']]);
        $s2 = self::settings($b);
        return ['name' => $b['name'], 'phone' => $b['phone'], 'warn_minutes' => (int)$s2['warn_minutes'], 'staff_can_discount' => (bool)$s2['staff_can_discount'],
            'show_prices_on_display' => (bool)$s2['show_prices_on_display'], 'show_free_on_display' => (bool)$s2['show_free_on_display'], 'email_z_report' => (bool)$s2['email_z_report'], 'display_enabled' => $b['display_token_hash'] !== null];
    }

    /** Müşteri ekranı bağlantısını yeniler (eski bağlantı geçersiz olur). Anahtar yalnızca bir kez döner. */
    public static function rotateDisplay(): array
    {
        [$u, $b] = Perm::need('display.manage');
        $key = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        Db::exec('UPDATE businesses SET display_token_hash = :h, updated_at = :u WHERE id = :b', ['h' => hash('sha256', $key), 'u' => Db::now(), 'b' => $b['id']]);
        Audit::log((int)$b['id'], (int)$u['id'], 'display.rotate', 'business', (int)$b['id']);
        return ['key' => $key];
    }

    public static function disableDisplay(): array
    {
        [$u, $b] = Perm::need('display.manage');
        Db::exec('UPDATE businesses SET display_token_hash = NULL, updated_at = :u WHERE id = :b', ['u' => Db::now(), 'b' => $b['id']]);
        Audit::log((int)$b['id'], (int)$u['id'], 'display.disable', 'business', (int)$b['id']);
        return ['disabled' => true];
    }

    /**
     * Müşteri ekranı verisi (oturumsuz). Anahtar başlıkta gelir, URL'de/kayıtlarda görünmez.
     * Yalnızca masa adı, durum ve kalan süre döner; tutar ayara bağlı, kişisel veri yok.
     */
    public static function display(): array
    {
        $key = (string)Http::header('X-Display-Key');
        RateLimiter::enforce('display:' . Http::ip(), 240, 60, 'Çok fazla istek.');
        if (!preg_match('/^[A-Za-z0-9_-]{32}$/', $key)) {
            throw new HttpError(401, 'Ekran bağlantısı geçersiz.', 'invalid_display');
        }
        $b = Db::one('SELECT * FROM businesses WHERE display_token_hash = :h', ['h' => hash('sha256', $key)]);
        if (!$b) {
            RateLimiter::hit('display-bad:' . Http::ip(), 30, 600);
            \Dnl\Core\SecurityLog::add('display_bad_key');
            if (RateLimiter::blocked('display-bad:' . Http::ip(), 30)) {
                throw new HttpError(429, 'Çok fazla deneme.', 'rate_limited');
            }
            throw new HttpError(401, 'Ekran bağlantısı geçersiz ya da yenilenmiş.', 'invalid_display');
        }
        if (($b['suspended_at'] ?? null) !== null) {
            throw new HttpError(401, 'Ekran bağlantısı geçersiz ya da yenilenmiş.', 'invalid_display');
        }
        $s = self::settings($b);
        $bid = (int)$b['id'];
        $now = time();
        $sessions = [];
        foreach (Db::all("SELECT * FROM play_sessions WHERE business_id = :b AND status = 'active'", ['b' => $bid]) as $row) {
            $row['p'] = json_decode((string)$row['pricing'], true) ?: [];
            $sessions[(int)$row['id']] = Games::summary($row, $now);
        }
        $tables = [];
        foreach (Db::all('SELECT g.id, g.name, g.active_session_id, t.name AS type_name FROM game_tables g JOIN table_types t ON t.id = g.table_type_id WHERE g.business_id = :b AND g.is_active = 1 ORDER BY g.sort, g.id', ['b' => $bid]) as $t) {
            $sess = $t['active_session_id'] !== null ? ($sessions[(int)$t['active_session_id']] ?? null) : null;
            if ($sess === null && !$s['show_free_on_display']) {
                continue;
            }
            $tables[] = [
                'name' => $t['name'], 'type' => $t['type_name'], 'busy' => $sess !== null,
                'mode' => $sess['mode'] ?? null, 'elapsed' => $sess['elapsed'] ?? null, 'remaining' => $sess['remaining'] ?? null,
                'match_count' => $sess['match_count'] ?? null,
                'amount_cents' => $sess !== null && $s['show_prices_on_display'] ? $sess['subtotal_cents'] : null,
                'rate_per_hour' => $sess !== null && $s['show_prices_on_display'] ? $sess['rate_per_hour'] : 0,
            ];
        }
        return ['business' => $b['name'], 'server_time' => gmdate('c', $now), 'tables' => $tables];
    }

    public static function audit(): array
    {
        [, $b] = Perm::need('audit.view');
        $before = (int)(Http::query('before') ?? 0);
        $rows = Db::all('SELECT a.id, a.action, a.entity, a.entity_id, a.meta, a.created_at, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
            WHERE a.business_id = :b' . ($before > 0 ? ' AND a.id < :before' : '') . ' ORDER BY a.id DESC LIMIT 100', $before > 0 ? ['b' => $b['id'], 'before' => $before] : ['b' => $b['id']]);
        return ['items' => array_map(static fn($r) => ['id' => (int)$r['id'], 'action' => $r['action'], 'entity' => $r['entity'], 'entity_id' => $r['entity_id'] !== null ? (int)$r['entity_id'] : null,
            'meta' => json_decode((string)$r['meta'], true), 'user' => $r['user_name'], 'at' => Db::iso($r['created_at'])], $rows)];
    }
}
