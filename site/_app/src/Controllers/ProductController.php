<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Db;
use Dnl\Core\Http;
use Dnl\Core\Input;
use Dnl\Services\Perm;
use Dnl\Services\Products;

/** Ürünler, stok ve hızlı satış */
final class ProductController
{
    /** Satış ekranı için aktif ürünler (tüm roller) */
    public static function menu(): array
    {
        [, $b] = Perm::need('sales.quick');
        $bid = (int)$b['id'];
        // Son 30 günde satılan adet: ürün seçicide "Sık satılanlar" için
        $sold = [];
        foreach (Db::all("SELECT i.product_id AS pid, SUM(i.qty) AS q FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id
            WHERE t.business_id = :b AND t.status = 'paid' AND t.closed_at >= :f AND i.product_id IS NOT NULL GROUP BY i.product_id", ['b' => $bid, 'f' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]) as $r) {
            $sold[(int)$r['pid']] = (int)$r['q'];
        }
        $products = Products::list($bid, true);
        foreach ($products as &$p) {
            $p['sold_30d'] = $sold[(int)$p['id']] ?? 0;
        }
        unset($p);
        return ['products' => $products];
    }

    public static function index(): array
    {
        [, $b] = Perm::need('products.manage');
        return ['products' => Products::list((int)$b['id'])];
    }

    private static function input(bool $create): array
    {
        $in = new Input(Http::json());
        $d = [
            'category' => $in->str('category', 1, 40, false, 'Kategori') ?: 'Genel',
            'name' => $in->str('name', 1, 60, true, 'Ürün adı'),
            'price_cents' => (int)$in->money('price', true, 10000000, 'Fiyat'),
            'cost_cents' => $in->money('cost', false, 10000000, 'Alış fiyatı'),
            'track_stock' => $in->bool('track_stock'),
            'low_stock' => (int)$in->int('low_stock', 0, 100000, 0, 'Kritik stok'),
            'sort' => (int)$in->int('sort', 0, 9999, 0, 'Sıra'),
            'is_active' => $in->bool('is_active', true),
            'initial_stock' => $create ? (int)$in->int('initial_stock', 0, 100000, 0, 'Başlangıç stoğu') : 0,
        ];
        $in->check();
        return $d;
    }

    public static function create(): array
    {
        [$u, $b] = Perm::need('products.manage');
        $id = Products::save((int)$b['id'], (int)$u['id'], null, self::input(true));
        return ['id' => $id] + self::index();
    }

    public static function update(array $p): array
    {
        [$u, $b] = Perm::need('products.manage');
        Products::save((int)$b['id'], (int)$u['id'], $p['id'], self::input(false));
        return self::index();
    }

    public static function archive(array $p): array
    {
        [$u, $b] = Perm::need('products.manage');
        Products::archive((int)$b['id'], (int)$u['id'], $p['id']);
        return self::index();
    }

    public static function restock(array $p): array
    {
        [$u, $b] = Perm::need('stock.manage');
        $in = new Input(Http::json());
        $qty = $in->int('qty', 1, 100000, null, 'Adet');
        $in->check();
        Products::get((int)$b['id'], $p['id']);
        Db::tx(static fn() => Products::adjust((int)$b['id'], (int)$u['id'], $p['id'], (int)$qty, 'restock', null));
        \Dnl\Core\Audit::log((int)$b['id'], (int)$u['id'], 'stock.restock', 'product', $p['id'], ['qty' => $qty]);
        return self::index();
    }

    public static function count(array $p): array
    {
        [$u, $b] = Perm::need('stock.manage');
        $in = new Input(Http::json());
        $qty = $in->int('qty', 0, 1000000, null, 'Sayılan adet');
        $in->check();
        Products::setCount((int)$b['id'], (int)$u['id'], $p['id'], (int)$qty);
        return self::index();
    }

    public static function quickSale(): array
    {
        [$u, $b] = Perm::need('sales.quick');
        $data = Http::json();
        $in = new Input($data);
        $dtype = $in->enum('discount_type', ['none', 'percent', 'amount'], 'none', 'İndirim');
        $dval = 0;
        if ($dtype === 'percent') {
            $dval = (int)$in->int('discount_value', 0, 100, null, 'İndirim yüzdesi');
        } elseif ($dtype === 'amount') {
            $dval = (int)$in->money('discount_value', true, 100000000, 'İndirim tutarı');
        }
        $in->check();
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        $payments = is_array($data['payments'] ?? null) ? $data['payments'] : [];
        return Products::quickSale($b, $u, $lines, ['type' => $dtype, 'value' => $dval], $payments);
    }

    public static function recentQuick(): array
    {
        [, $b] = Perm::need('sales.quick');
        $bid = (int)$b['id'];
        $rows = Db::all("SELECT t.id, t.status, t.total_cents, t.closed_at, t.shift_id, u.name AS by_name FROM tickets t LEFT JOIN users u ON u.id = t.created_by WHERE t.business_id = :b AND t.type = 'quick' ORDER BY t.id DESC LIMIT 15", ['b' => $bid]);
        $out = [];
        foreach ($rows as $r) {
            $items = Products::items($bid, (int)$r['id']);
            $out[] = ['id' => (int)$r['id'], 'status' => $r['status'], 'total_cents' => (int)$r['total_cents'], 'closed_at' => $r['closed_at'] ? Db::iso($r['closed_at']) : null, 'by' => $r['by_name'],
                'summary' => implode(', ', array_map(static fn($i) => $i['qty'] . '× ' . $i['name'], $items)),
                'can_void' => $r['status'] === 'paid' && $r['shift_id'] !== null && (int)$r['shift_id'] === (int)$b['open_shift_id']];
        }
        return ['items' => $out];
    }

    public static function voidQuick(array $p): array
    {
        [$u, $b] = Perm::need('session.cancel');
        Products::voidQuick($b, (int)$u['id'], $p['id']);
        return self::recentQuick();
    }
}
