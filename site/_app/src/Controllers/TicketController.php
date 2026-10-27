<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Db;
use Dnl\Core\HttpError;
use Dnl\Services\Perm;
use Dnl\Services\Products;

/** Adisyon detayı (fiş yazdırma) */
final class TicketController
{
    public static function show(array $p): array
    {
        [, $b] = Perm::need('tables.operate');
        $bid = (int)$b['id'];
        $t = Db::one('SELECT * FROM tickets WHERE id = :id AND business_id = :b', ['id' => $p['id'], 'b' => $bid]);
        if (!$t) {
            throw new HttpError(404, 'Adisyon bulunamadı.', 'not_found');
        }
        $s = Db::one('SELECT s.*, g.name AS table_name FROM play_sessions s LEFT JOIN game_tables g ON g.id = s.table_id WHERE s.ticket_id = :t AND s.business_id = :b', ['t' => $t['id'], 'b' => $bid]);
        $pays = Db::all('SELECT p.method, p.amount_cents, c.name AS customer FROM payments p LEFT JOIN customers c ON c.id = p.customer_id AND c.business_id = p.business_id WHERE p.ticket_id = :t AND p.business_id = :b AND p.voided_at IS NULL', ['t' => $t['id'], 'b' => $bid]);
        return [
            'id' => (int)$t['id'], 'type' => $t['type'], 'status' => $t['status'], 'business' => $b['name'],
            'table_name' => $s['table_name'] ?? null, 'started_at' => $s ? Db::iso($s['started_at']) : null, 'ended_at' => $s ? Db::iso($s['ended_at']) : null,
            'mode' => $s['mode'] ?? null, 'game_cents' => (int)$t['game_cents'], 'items_cents' => (int)$t['items_cents'], 'discount_cents' => (int)$t['discount_cents'],
            'total_cents' => (int)$t['total_cents'], 'closed_at' => Db::iso($t['closed_at']), 'items' => Products::items($bid, (int)$t['id']),
            'payments' => array_map(static fn($x) => ['method' => $x['method'], 'amount_cents' => (int)$x['amount_cents'], 'customer' => $x['customer']], $pays),
            'closed_by' => $t['closed_by'] ? Db::value('SELECT name FROM users WHERE id = :u', ['u' => $t['closed_by']]) : null,
        ];
    }
}
