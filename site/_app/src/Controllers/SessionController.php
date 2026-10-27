<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Audit;
use Dnl\Core\Db;
use Dnl\Core\Http;
use Dnl\Core\HttpError;
use Dnl\Core\Input;
use Dnl\Services\Games;
use Dnl\Services\Perm;
use Dnl\Services\Products;

/** Masa oturumu işlemleri */
final class SessionController
{
    public static function open(): array
    {
        [$u, $b] = Perm::need('tables.operate');
        $in = new Input(Http::json());
        $table = $in->int('table_id', 1, PHP_INT_MAX, null, 'Masa');
        $mode = $in->enum('mode', Games::MODES, 'open', 'Ücretlendirme');
        $ctrl = $in->intOrNull('controllers', 2, 4, 'Kol sayısı');
        $planned = $in->intOrNull('planned_minutes', 5, 1440, 'Süre');
        $note = $in->str('note', 0, 200, false, 'Not');
        $in->check();
        $sid = Games::open($b, (int)$u['id'], (int)$table, $mode, $ctrl, $planned, $note);
        return self::detailOf($b, $sid);
    }

    public static function detail(array $p): array
    {
        [, $b] = Perm::need('tables.operate');
        return self::detailOf($b, $p['id']);
    }

    public static function detailOf(array $b, int $sid): array
    {
        $bid = (int)$b['id'];
        $s = Games::session($bid, $sid, false);
        $out = Games::summary($s, $s['ended_at'] !== null ? strtotime($s['ended_at'] . ' UTC') : time());
        $t = Db::one('SELECT g.name, t.name AS type_name FROM game_tables g JOIN table_types t ON t.id = g.table_type_id WHERE g.id = :id AND g.business_id = :b', ['id' => $s['table_id'], 'b' => $bid]);
        $out['status'] = $s['status'];
        $out['table_name'] = $t['name'] ?? '';
        $out['type_name'] = $t['type_name'] ?? '';
        $out['items'] = Products::items($bid, (int)$s['ticket_id']);
        $out['opened_by'] = Db::value('SELECT name FROM users WHERE id = :u AND business_id = :b', ['u' => $s['opened_by'], 'b' => $bid]);
        $out['server_time'] = gmdate('c');
        return $out;
    }

    public static function extend(array $p): array
    {
        [$u, $b] = Perm::need('tables.operate');
        $in = new Input(Http::json());
        $m = $in->int('minutes', 5, 720, null, 'Süre');
        $in->check();
        Games::extend($b, (int)$u['id'], $p['id'], (int)$m);
        return self::detailOf($b, $p['id']);
    }

    public static function controllers(array $p): array
    {
        [$u, $b] = Perm::need('tables.operate');
        $in = new Input(Http::json());
        $c = $in->int('controllers', 2, 4, null, 'Kol sayısı');
        $in->check();
        Games::setControllers($b, (int)$u['id'], $p['id'], (int)$c);
        return self::detailOf($b, $p['id']);
    }

    public static function match(array $p): array
    {
        [$u, $b] = Perm::need('tables.operate');
        $in = new Input(Http::json());
        $d = $in->int('delta', -10, 10, null, 'Maç');
        $in->check();
        Games::addMatch($b, (int)$u['id'], $p['id'], (int)$d);
        return self::detailOf($b, $p['id']);
    }

    public static function move(array $p): array
    {
        [$u, $b] = Perm::need('tables.operate');
        $in = new Input(Http::json());
        $to = $in->int('table_id', 1, PHP_INT_MAX, null, 'Masa');
        $in->check();
        Games::move($b, (int)$u['id'], $p['id'], (int)$to);
        return self::detailOf($b, $p['id']);
    }

    public static function addItem(array $p): array
    {
        [$u, $b] = Perm::need('tables.operate');
        $in = new Input(Http::json());
        $pid = $in->int('product_id', 1, PHP_INT_MAX, null, 'Ürün');
        $qty = $in->int('qty', 1, 99, 1, 'Adet');
        $in->check();
        $s = Games::session((int)$b['id'], $p['id']);
        Products::addItem((int)$b['id'], (int)$u['id'], (int)$s['ticket_id'], (int)$pid, (int)$qty);
        return self::detailOf($b, $p['id']);
    }

    public static function removeItem(array $p): array
    {
        [$u, $b] = Perm::need('tables.operate');
        $s = Games::session((int)$b['id'], $p['id']);
        Products::removeItem((int)$b['id'], (int)$u['id'], (int)$s['ticket_id'], $p['item']);
        return self::detailOf($b, $p['id']);
    }

    public static function close(array $p): array
    {
        [$u, $b] = Perm::need('tables.operate');
        $data = Http::json();
        $in = new Input($data);
        $dtype = $in->enum('discount_type', ['none', 'percent', 'amount'], 'none', 'İndirim');
        $dval = 0;
        if ($dtype === 'percent') {
            $dval = (int)$in->int('discount_value', 0, 100, null, 'İndirim yüzdesi');
        } elseif ($dtype === 'amount') {
            $dval = (int)$in->money('discount_value', true, 100000000, 'İndirim tutarı');
        }
        $dnote = $in->str('discount_note', 0, 80, false, 'Not');
        $in->check();
        $payments = is_array($data['payments'] ?? null) ? $data['payments'] : [];
        $r = Games::close($b, $u, $p['id'], ['type' => $dtype, 'value' => $dval], $payments, $dnote);
        return $r;
    }

    public static function cancel(array $p): array
    {
        [$u, $b] = Perm::need('session.cancel');
        $in = new Input(Http::json());
        $reason = $in->str('reason', 3, 120, true, 'İptal nedeni');
        $in->check();
        Games::cancel($b, (int)$u['id'], $p['id'], $reason);
        return ['cancelled' => true];
    }

    public static function reopen(array $p): array
    {
        [$u, $b] = Perm::need('session.reopen');
        Games::reopen($b, (int)$u['id'], $p['id']);
        return self::detailOf($b, $p['id']);
    }

    /** Son kapatılan hesaplar (geri alma için) */
    public static function recent(): array
    {
        [, $b] = Perm::need('tables.operate');
        $bid = (int)$b['id'];
        $rows = Db::all("SELECT s.id, s.ticket_id, s.status, s.ended_at, g.name AS table_name, t.total_cents, t.discount_cents, t.type, t.shift_id, u.name AS closed_by
            FROM play_sessions s JOIN tickets t ON t.id = s.ticket_id JOIN game_tables g ON g.id = s.table_id LEFT JOIN users u ON u.id = s.closed_by
            WHERE s.business_id = :b AND s.status IN ('closed','cancelled') ORDER BY s.ended_at DESC LIMIT 15", ['b' => $bid]);
        return ['items' => array_map(static fn($r) => [
            'id' => (int)$r['id'], 'ticket_id' => (int)$r['ticket_id'], 'status' => $r['status'], 'ended_at' => Db::iso($r['ended_at']), 'table_name' => $r['table_name'],
            'total_cents' => (int)$r['total_cents'], 'discount_cents' => (int)$r['discount_cents'], 'closed_by' => $r['closed_by'],
            'can_reopen' => $r['status'] === 'closed' && $r['shift_id'] !== null && (int)$r['shift_id'] === (int)$b['open_shift_id'] && time() - strtotime($r['ended_at'] . ' UTC') <= 1800,
        ], $rows)];
    }
}
