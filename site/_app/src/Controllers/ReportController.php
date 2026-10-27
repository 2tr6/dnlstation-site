<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Db;
use Dnl\Core\Http;
use Dnl\Core\HttpError;
use Dnl\Services\Perm;

/** Raporlar ve CSV dışa aktarma */
final class ReportController
{
    /** @return array{0:string,1:string,2:\DateTimeZone,3:string,4:string} [utcFrom, utcTo, tz, from, to] */
    private static function range(array $b): array
    {
        $tz = new \DateTimeZone((string)$b['timezone']);
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $from = Http::query('from', $today);
        $to = Http::query('to', $today);
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($re, (string)$from) || !preg_match($re, (string)$to)) {
            throw new HttpError(422, 'Tarih biçimi geçersiz.', 'validation');
        }
        $f = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        $t = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
        if (!$f || !$t || $f->format('Y-m-d') !== $from || $t->format('Y-m-d') !== $to || $t < $f) {
            throw new HttpError(422, 'Tarih aralığı geçersiz.', 'validation');
        }
        if ($f->diff($t)->days > 366) {
            throw new HttpError(422, 'En fazla 1 yıllık aralık seçebilirsiniz.', 'validation');
        }
        $utc = new \DateTimeZone('UTC');
        return [$f->setTimezone($utc)->format('Y-m-d H:i:s'), $t->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'), $tz, $from, $to];
    }

    public static function summary(): array
    {
        [, $b] = Perm::need('reports.view');
        $bid = (int)$b['id'];
        [$uf, $ut, $tz, $from, $to] = self::range($b);
        $p = ['b' => $bid, 'f' => $uf, 't' => $ut];

        $tickets = Db::all("SELECT id, type, game_cents, items_cents, discount_cents, total_cents, closed_at, closed_by FROM tickets WHERE business_id = :b AND status = 'paid' AND closed_at >= :f AND closed_at < :t", $p);
        $days = [];
        $tot = ['revenue' => 0, 'game' => 0, 'items' => 0, 'discount' => 0, 'tickets' => count($tickets), 'sessions' => 0, 'quick' => 0];
        $byStaff = [];
        foreach ($tickets as $r) {
            $day = (new \DateTimeImmutable($r['closed_at'], new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
            $days[$day] = ($days[$day] ?? 0) + (int)$r['total_cents'];
            $tot['revenue'] += (int)$r['total_cents'];
            $tot['game'] += (int)$r['game_cents'];
            $tot['items'] += (int)$r['items_cents'];
            $tot['discount'] += (int)$r['discount_cents'];
            $tot[$r['type'] === 'quick' ? 'quick' : 'sessions']++;
            $k = (int)$r['closed_by'];
            $byStaff[$k] = ($byStaff[$k] ?? 0) + (int)$r['total_cents'];
        }
        // Gün serisi (boş günler 0)
        $series = [];
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
        while ($d <= $end) {
            $key = $d->format('Y-m-d');
            $series[] = ['date' => $key, 'total_cents' => $days[$key] ?? 0];
            $d = $d->modify('+1 day');
        }
        $methods = ['cash' => 0, 'card' => 0, 'other' => 0, 'credit' => 0];
        foreach (Db::all("SELECT p.method, SUM(p.amount_cents) AS s FROM payments p JOIN tickets t ON t.id = p.ticket_id WHERE p.business_id = :b AND p.voided_at IS NULL AND t.status = 'paid' AND t.closed_at >= :f AND t.closed_at < :t GROUP BY p.method", $p) as $r) {
            $methods[$r['method']] = (int)$r['s'];
        }
        $byType = Db::all("SELECT tt.name, COUNT(*) AS cnt, SUM(t.game_cents) AS game, SUM(t.items_cents) AS items
            FROM play_sessions s JOIN tickets t ON t.id = s.ticket_id JOIN game_tables g ON g.id = s.table_id JOIN table_types tt ON tt.id = g.table_type_id
            WHERE s.business_id = :b AND t.status = 'paid' AND t.closed_at >= :f AND t.closed_at < :t GROUP BY tt.id, tt.name ORDER BY game DESC", $p);
        $byTable = Db::all("SELECT g.name, COUNT(*) AS cnt, SUM(t.game_cents) AS game
            FROM play_sessions s JOIN tickets t ON t.id = s.ticket_id JOIN game_tables g ON g.id = s.table_id
            WHERE s.business_id = :b AND t.status = 'paid' AND t.closed_at >= :f AND t.closed_at < :t GROUP BY g.id, g.name ORDER BY game DESC LIMIT 30", $p);
        $top = Db::all("SELECT i.name, SUM(i.qty) AS qty, SUM(i.total_cents) AS total FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id
            WHERE i.business_id = :b AND t.status = 'paid' AND t.closed_at >= :f AND t.closed_at < :t GROUP BY i.name ORDER BY total DESC LIMIT 15", $p);
        $names = [];
        foreach (Db::all('SELECT id, name FROM users WHERE business_id = :b', ['b' => $bid]) as $u) {
            $names[(int)$u['id']] = $u['name'];
        }
        arsort($byStaff);
        $voids = (int)Db::value("SELECT COUNT(*) FROM tickets WHERE business_id = :b AND status = 'void' AND closed_at >= :f AND closed_at < :t", $p);

        // Gider, maliyet, veresiye tahsilatı
        $expense = (int)Db::value('SELECT COALESCE(SUM(amount_cents),0) FROM expenses WHERE business_id = :b AND voided_at IS NULL AND spent_on >= :f AND spent_on <= :t', ['b' => $bid, 'f' => $from, 't' => $to]);
        $cost = Db::one("SELECT COALESCE(SUM(i.cost_cents),0) AS c, COALESCE(SUM(CASE WHEN i.cost_cents IS NOT NULL THEN i.total_cents ELSE 0 END),0) AS rev FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id
            WHERE i.business_id = :b AND t.status = 'paid' AND t.closed_at >= :f AND t.closed_at < :t", $p);
        $collections = (int)Db::value("SELECT COALESCE(SUM(-amount_cents),0) FROM customer_moves WHERE business_id = :b AND kind = 'collection' AND created_at >= :f AND created_at < :t", $p);
        // Yoğunluk: haftanın günü × saat (yerel saat), başlayan masa oturumlarına göre
        $heat = array_fill(0, 7, array_fill(0, 24, 0));
        foreach (Db::all("SELECT started_at FROM play_sessions WHERE business_id = :b AND status = 'closed' AND started_at >= :f AND started_at < :t", $p) as $r) {
            $dt = (new \DateTimeImmutable($r['started_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
            $heat[(int)$dt->format('N') - 1][(int)$dt->format('G')]++;
        }
        // Önceki dönem: aynı uzunlukta, hemen öncesi
        $len = strtotime($ut . ' UTC') - strtotime($uf . ' UTC');
        $pp = ['b' => $bid, 'f' => gmdate('Y-m-d H:i:s', strtotime($uf . ' UTC') - $len), 't' => $uf];
        $prev = Db::one("SELECT COUNT(*) AS cnt, COALESCE(SUM(total_cents),0) AS total FROM tickets WHERE business_id = :b AND status = 'paid' AND closed_at >= :f AND closed_at < :t", $pp);

        return [
            'from' => $from, 'to' => $to,
            'heatmap' => $heat,
            'previous' => ['revenue' => (int)$prev['total'], 'tickets' => (int)$prev['cnt']],
            'finance' => [
                'expense_cents' => $expense,
                'product_cost_cents' => (int)$cost['c'],
                'product_revenue_with_cost_cents' => (int)$cost['rev'],
                'credit_sales_cents' => $methods['credit'],
                'collections_cents' => $collections,
                'net_cents' => $tot['revenue'] - (int)$cost['c'] - $expense,
            ],
            'totals' => $tot + ['voids' => $voids, 'avg_ticket' => $tot['tickets'] ? (int)round($tot['revenue'] / $tot['tickets']) : 0],
            'methods' => $methods,
            'series' => $series,
            'by_type' => array_map(static fn($r) => ['name' => $r['name'], 'count' => (int)$r['cnt'], 'game_cents' => (int)$r['game'], 'items_cents' => (int)$r['items']], $byType),
            'by_table' => array_map(static fn($r) => ['name' => $r['name'], 'count' => (int)$r['cnt'], 'game_cents' => (int)$r['game']], $byTable),
            'top_products' => array_map(static fn($r) => ['name' => $r['name'], 'qty' => (int)$r['qty'], 'total_cents' => (int)$r['total']], $top),
            'by_staff' => array_map(static fn($id, $v) => ['name' => $names[$id] ?? '—', 'total_cents' => $v], array_keys($byStaff), array_values($byStaff)),
        ];
    }

    /** Hesap dökümü CSV (Excel uyumlu: UTF-8 BOM, ; ayırıcı) */
    public static function csv(): never
    {
        [, $b] = Perm::need('reports.view');
        $bid = (int)$b['id'];
        [$uf, $ut, $tz, $from, $to] = self::range($b);
        $rows = Db::all("SELECT t.id, t.type, t.status, t.game_cents, t.items_cents, t.discount_cents, t.total_cents, t.closed_at, t.discount_note, u.name AS staff, g.name AS table_name
            FROM tickets t LEFT JOIN users u ON u.id = t.closed_by LEFT JOIN play_sessions s ON s.ticket_id = t.id LEFT JOIN game_tables g ON g.id = s.table_id
            WHERE t.business_id = :b AND t.status IN ('paid','void') AND t.closed_at >= :f AND t.closed_at < :t ORDER BY t.closed_at", ['b' => $bid, 'f' => $uf, 't' => $ut]);
        $pay = [];
        foreach (Db::all("SELECT p.ticket_id, p.method, SUM(p.amount_cents) AS s FROM payments p JOIN tickets t ON t.id = p.ticket_id WHERE p.business_id = :b AND p.voided_at IS NULL AND t.closed_at >= :f AND t.closed_at < :t GROUP BY p.ticket_id, p.method", ['b' => $bid, 'f' => $uf, 't' => $ut]) as $r) {
            $pay[(int)$r['ticket_id']][$r['method']] = (int)$r['s'];
        }
        $m = static fn(int $c) => number_format($c / 100, 2, ',', '');
        // CSV/Excel formül enjeksiyonuna karşı metin hücrelerini koru
        $txt = static fn(?string $s) => preg_match('/^[=+\-@\t\r]/', (string)$s) ? "'" . $s : (string)$s;
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dnlstation-hesaplar-' . $from . '_' . $to . '.csv"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['No', 'Tarih', 'Tür', 'Durum', 'Masa', 'Oyun', 'Ürün', 'İndirim', 'Toplam', 'Nakit', 'Kart', 'Diğer', 'Personel', 'Not'], ';');
        foreach ($rows as $r) {
            $local = (new \DateTimeImmutable($r['closed_at'], new \DateTimeZone('UTC')))->setTimezone($tz)->format('d.m.Y H:i');
            $pp = $pay[(int)$r['id']] ?? [];
            fputcsv($out, [
                $r['id'], $local, $r['type'] === 'quick' ? 'Hızlı satış' : 'Masa', $r['status'] === 'paid' ? 'Ödendi' : 'İptal', $txt($r['table_name']),
                $m((int)$r['game_cents']), $m((int)$r['items_cents']), $m((int)$r['discount_cents']), $m((int)$r['total_cents']),
                $m($pp['cash'] ?? 0), $m($pp['card'] ?? 0), $m($pp['other'] ?? 0), $txt($r['staff']), $txt($r['discount_note']),
            ], ';');
        }
        fclose($out);
        exit;
    }
}
