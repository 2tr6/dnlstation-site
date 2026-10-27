<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Db;
use Dnl\Core\Http;
use Dnl\Core\HttpError;
use Dnl\Core\Input;
use Dnl\Services\Catalog;
use Dnl\Services\Games;
use Dnl\Services\Perm;
use Dnl\Services\Shifts;

/** Ana ekran (masalar) ve ilk kurulum */
final class PanelController
{
    /** Bugünün (işletme saat diliminde) UTC başlangıcı */
    public static function dayStartUtc(array $biz, int $daysAgo = 0): string
    {
        $tz = new \DateTimeZone((string)$biz['timezone']);
        $d = (new \DateTimeImmutable('now', $tz))->setTime(0, 0)->modify(($daysAgo >= 0 ? '-' : '+') . abs($daysAgo) . ' day');
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function dashboard(): array
    {
        [$u, $b] = Perm::need('tables.operate');
        $bid = (int)$b['id'];
        $now = time();
        $types = [];
        foreach (Catalog::types($bid) as $t) {
            $types[$t['id']] = $t;
        }
        $tables = Db::all('SELECT id, name, table_type_id, active_session_id, is_active FROM game_tables WHERE business_id = :b AND (is_active = 1 OR active_session_id IS NOT NULL) ORDER BY sort, id', ['b' => $bid]);
        $sessions = [];
        foreach (Db::all("SELECT * FROM play_sessions WHERE business_id = :b AND status = 'active'", ['b' => $bid]) as $s) {
            $s['p'] = json_decode((string)$s['pricing'], true) ?: [];
            $sessions[(int)$s['id']] = Games::summary($s, $now);
        }
        $resv = \Dnl\Services\Reservations::upcomingByTable($bid);
        $outTables = [];
        foreach ($tables as $t) {
            $sid = $t['active_session_id'] !== null ? (int)$t['active_session_id'] : null;
            $outTables[] = ['id' => (int)$t['id'], 'name' => $t['name'], 'type_id' => (int)$t['table_type_id'], 'session' => $sid !== null ? ($sessions[$sid] ?? null) : null, 'reservation' => $resv[(int)$t['id']] ?? null];
        }
        $today = self::dayStartUtc($b);
        $rev = (int)Db::value("SELECT COALESCE(SUM(p.amount_cents), 0) FROM payments p WHERE p.business_id = :b AND p.voided_at IS NULL AND p.created_at >= :d", ['b' => $bid, 'd' => $today]);
        $low = (int)Db::value('SELECT COUNT(*) FROM products WHERE business_id = :b AND is_active = 1 AND track_stock = 1 AND stock_qty <= low_stock', ['b' => $bid]);
        $shift = Db::one('SELECT s.id, s.opened_at, u.name AS opened_by FROM businesses b JOIN shifts s ON s.id = b.open_shift_id LEFT JOIN users u ON u.id = s.opened_by WHERE b.id = :b', ['b' => $bid]);
        $live = array_sum(array_map(static fn($s) => $s['subtotal_cents'], $sessions));
        return [
            'server_time' => gmdate('c', $now),
            'types' => array_values($types),
            'tables' => $outTables,
            'kpi' => [
                'revenue_today_cents' => Perm::can($u, 'reports.view', $b) ? $rev : null,
                'active_tables' => count($sessions),
                'total_tables' => count($outTables),
                'open_amount_cents' => $live,
                'low_stock' => $low,
                'reservations_today' => (int)Db::value("SELECT COUNT(*) FROM reservations WHERE business_id = :b AND status = 'booked' AND starts_at >= :f AND starts_at < :t", ['b' => $bid, 'f' => $today, 't' => self::dayStartUtc($b, -1)]),
            ],
            'shift' => $shift ? ['id' => (int)$shift['id'], 'opened_at' => Db::iso($shift['opened_at']), 'opened_by' => $shift['opened_by']] : null,
            'setup_done' => (int)Db::value('SELECT setup_done FROM businesses WHERE id = :b', ['b' => $bid]) === 1,
        ];
    }

    public static function presets(): array
    {
        Perm::need('settings.tables');
        $out = [];
        foreach (Catalog::PRESETS as $k => $p) {
            $out[] = ['kind' => $k, 'name' => $p['name'], 'uses_controllers' => (bool)$p['uses_controllers'], 'hour_price' => $p['hour_price'], 'hour_price_4' => $p['hour_price_4'], 'match_price' => $p['match_price'], 'count' => $p['count']];
        }
        return ['presets' => $out];
    }

    public static function setupQuick(): array
    {
        [$u, $b] = Perm::need('settings.tables');
        $data = Http::json();
        $raw = $data['items'] ?? null;
        if (!is_array($raw) || !$raw || count($raw) > count(Catalog::KINDS)) {
            throw new HttpError(422, 'En az bir oyun türü seçin.', 'validation');
        }
        $items = [];
        $kinds = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                throw new HttpError(422, 'Kurulum bilgisi geçersiz.', 'validation');
            }
            $in = new Input($row);
            $kind = $in->enum('kind', Catalog::KINDS, null, 'Tür');
            $count = $in->int('count', 0, 200, null, 'Masa sayısı');
            $hour = $in->money('hour_price', true, 10000000, 'Saatlik ücret');
            $hour4 = $in->money('hour_price_4', false, 10000000, '4 kol saatlik ücret');
            $match = $in->money('match_price', false, 10000000, 'Maç ücreti');
            $in->check();
            if (isset($kinds[$kind])) {
                throw new HttpError(422, 'Aynı tür iki kez seçilmiş.', 'validation');
            }
            $kinds[$kind] = true;
            $items[] = ['kind' => $kind, 'count' => (int)$count, 'hour_price' => (int)$hour, 'hour_price_4' => $hour4, 'match_price' => $match];
        }
        Catalog::quickSetup($b, (int)$u['id'], $items);
        return ['done' => true];
    }

    /** Kurulumu atla (masaları elle tanımlayacak) */
    public static function setupSkip(): array
    {
        [, $b] = Perm::need('settings.tables');
        Db::exec('UPDATE businesses SET setup_done = 1, updated_at = :n WHERE id = :b', ['n' => Db::now(), 'b' => $b['id']]);
        return ['done' => true];
    }
}
