<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

use Dnl\Core\Audit;
use Dnl\Core\Db;
use Dnl\Core\HttpError;

/**
 * Kafeterya ürünleri, stok ve adisyon kalemleri.
 * Stok düşümü koşullu UPDATE ile yapılır (eşzamanlı satışta eksi stoğa düşmez).
 */
final class Products
{
    public static function list(int $bid, bool $onlyActive = false): array
    {
        $rows = Db::all('SELECT * FROM products WHERE business_id = :b' . ($onlyActive ? ' AND is_active = 1' : '') . ' ORDER BY category, sort, name', ['b' => $bid]);
        return array_map([self::class, 'out'], $rows);
    }

    public static function out(array $p): array
    {
        return [
            'id' => (int)$p['id'], 'category' => $p['category'], 'name' => $p['name'], 'price_cents' => (int)$p['price_cents'],
            'track_stock' => (int)$p['track_stock'] === 1, 'stock_qty' => (int)$p['stock_qty'], 'low_stock' => (int)$p['low_stock'],
            'sort' => (int)$p['sort'], 'is_active' => (int)$p['is_active'] === 1,
            'cost_cents' => isset($p['cost_cents']) && $p['cost_cents'] !== null ? (int)$p['cost_cents'] : null,
        ];
    }

    public static function get(int $bid, int $id): array
    {
        $p = Db::one('SELECT * FROM products WHERE id = :id AND business_id = :b', ['id' => $id, 'b' => $bid]);
        if (!$p) {
            throw new HttpError(404, 'Ürün bulunamadı.', 'not_found');
        }
        return $p;
    }

    public static function save(int $bid, int $uid, ?int $id, array $d): int
    {
        $now = Db::now();
        $row = [
            'category' => $d['category'], 'name' => $d['name'], 'price_cents' => $d['price_cents'],
            'track_stock' => $d['track_stock'] ? 1 : 0, 'low_stock' => $d['low_stock'], 'sort' => $d['sort'], 'cost_cents' => $d['cost_cents'] ?? null,
            'is_active' => $d['is_active'] ? 1 : 0, 'updated_at' => $now,
        ];
        if ($id === null) {
            $count = (int)Db::value('SELECT COUNT(*) FROM products WHERE business_id = :b', ['b' => $bid]);
            if ($count >= 500) {
                throw new HttpError(422, 'En fazla 500 ürün tanımlanabilir.', 'limit');
            }
            $id = Db::insert('products', $row + ['business_id' => $bid, 'stock_qty' => 0, 'created_at' => $now]);
            if ($d['track_stock'] && $d['initial_stock'] > 0) {
                self::adjust($bid, $uid, $id, $d['initial_stock'], 'restock', null);
            }
            Audit::log($bid, $uid, 'product.create', 'product', $id, ['name' => $d['name'], 'price' => $d['price_cents']]);
        } else {
            $old = self::get($bid, $id);
            Db::update('products', $row, ['id' => $id, 'business_id' => $bid]);
            Audit::log($bid, $uid, 'product.update', 'product', $id, ['price' => [(int)$old['price_cents'], $d['price_cents']]]);
        }
        return $id;
    }

    /** Ürün kalıcı silinmez (geçmiş adisyonlar bozulmasın); pasife alınır. */
    public static function archive(int $bid, int $uid, int $id): void
    {
        self::get($bid, $id);
        Db::exec('UPDATE products SET is_active = 0, updated_at = :n WHERE id = :id AND business_id = :b', ['n' => Db::now(), 'id' => $id, 'b' => $bid]);
        Audit::log($bid, $uid, 'product.archive', 'product', $id);
    }

    /**
     * Stok değişimi. $delta negatifse yeterli stok yoksa 409.
     * reason: restock | sale | return | void | count
     */
    public static function adjust(int $bid, int $uid, int $productId, int $delta, string $reason, ?int $ref): void
    {
        if ($delta === 0) {
            return;
        }
        if ($delta < 0) {
            $n = Db::exec('UPDATE products SET stock_qty = stock_qty + :d WHERE id = :id AND business_id = :b AND stock_qty + :d2 >= 0', ['d' => $delta, 'd2' => $delta, 'id' => $productId, 'b' => $bid]);
        } else {
            $n = Db::exec('UPDATE products SET stock_qty = stock_qty + :d WHERE id = :id AND business_id = :b AND stock_qty + :d2 <= 1000000', ['d' => $delta, 'd2' => $delta, 'id' => $productId, 'b' => $bid]);
        }
        if ($n !== 1) {
            $name = (string)Db::value('SELECT name FROM products WHERE id = :id AND business_id = :b', ['id' => $productId, 'b' => $bid]);
            throw new HttpError(409, $delta < 0 ? "“{$name}” için yeterli stok yok. Stok ekleyin ya da sayımı güncelleyin." : 'Stok miktarı geçersiz.', 'stock');
        }
        Db::insert('stock_moves', ['business_id' => $bid, 'product_id' => $productId, 'qty_change' => $delta, 'reason' => $reason, 'ref_id' => $ref, 'created_by' => $uid, 'created_at' => Db::now()]);
    }

    /** Sayım: stok miktarını verilen değere eşitler */
    public static function setCount(int $bid, int $uid, int $productId, int $qty): void
    {
        $p = self::get($bid, $productId);
        Db::tx(static function () use ($bid, $uid, $productId, $qty, $p) {
            $delta = $qty - (int)$p['stock_qty'];
            if ($delta !== 0) {
                $n = Db::exec('UPDATE products SET stock_qty = :q WHERE id = :id AND business_id = :b AND stock_qty = :old', ['q' => $qty, 'id' => $productId, 'b' => $bid, 'old' => $p['stock_qty']]);
                if ($n !== 1) {
                    throw new HttpError(409, 'Stok bu sırada değişti. Tekrar deneyin.', 'conflict');
                }
                Db::insert('stock_moves', ['business_id' => $bid, 'product_id' => $productId, 'qty_change' => $delta, 'reason' => 'count', 'ref_id' => null, 'created_by' => $uid, 'created_at' => Db::now()]);
            }
        });
        Audit::log($bid, $uid, 'stock.count', 'product', $productId, ['from' => (int)$p['stock_qty'], 'to' => $qty]);
    }

    /** Açık adisyona ürün ekler (fiyat sunucudaki üründen alınır). */
    public static function addItem(int $bid, int $uid, int $ticketId, int $productId, int $qty): int
    {
        $p = self::get($bid, $productId);
        if ((int)$p['is_active'] !== 1) {
            throw new HttpError(409, 'Bu ürün satışta değil.', 'inactive');
        }
        return Db::tx(static function () use ($bid, $uid, $ticketId, $p, $qty) {
            $open = Db::value("SELECT id FROM tickets WHERE id = :t AND business_id = :b AND status = 'open'", ['t' => $ticketId, 'b' => $bid]);
            if (!$open) {
                throw new HttpError(409, 'Adisyon kapalı.', 'not_active');
            }
            if ((int)$p['track_stock'] === 1) {
                self::adjust($bid, $uid, (int)$p['id'], -$qty, 'sale', $ticketId);
            }
            $unit = (int)$p['price_cents'];
            $itemId = Db::insert('ticket_items', ['business_id' => $bid, 'ticket_id' => $ticketId, 'product_id' => (int)$p['id'], 'name' => $p['name'], 'unit_cents' => $unit, 'qty' => $qty, 'total_cents' => $unit * $qty, 'cost_cents' => isset($p['cost_cents']) && $p['cost_cents'] !== null ? (int)$p['cost_cents'] * $qty : null, 'created_by' => $uid, 'created_at' => Db::now()]);
            self::recalcItems($bid, $ticketId);
            return $itemId;
        });
    }

    /** Açık adisyondan kalemi siler, stoğu iade eder. */
    public static function removeItem(int $bid, int $uid, int $ticketId, int $itemId): void
    {
        Db::tx(static function () use ($bid, $uid, $ticketId, $itemId) {
            $it = Db::one("SELECT i.* FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id WHERE i.id = :i AND i.ticket_id = :t AND i.business_id = :b AND t.status = 'open'", ['i' => $itemId, 't' => $ticketId, 'b' => $bid]);
            if (!$it) {
                throw new HttpError(404, 'Kalem bulunamadı.', 'not_found');
            }
            Db::exec('DELETE FROM ticket_items WHERE id = :i AND business_id = :b', ['i' => $itemId, 'b' => $bid]);
            if ($it['product_id'] !== null) {
                $track = Db::value('SELECT track_stock FROM products WHERE id = :p AND business_id = :b', ['p' => $it['product_id'], 'b' => $bid]);
                if ((int)$track === 1) {
                    self::adjust($bid, $uid, (int)$it['product_id'], (int)$it['qty'], 'return', $ticketId);
                }
            }
            self::recalcItems($bid, $ticketId);
            Audit::log($bid, $uid, 'item.remove', 'ticket', $ticketId, ['name' => $it['name'], 'qty' => (int)$it['qty']]);
        });
    }

    /** İptal edilen adisyonun stok takipli kalemlerini geri ekler */
    public static function restockTicket(int $bid, int $ticketId, int $uid, string $reason): void
    {
        $items = Db::all('SELECT i.product_id, i.qty FROM ticket_items i JOIN products p ON p.id = i.product_id AND p.business_id = i.business_id WHERE i.ticket_id = :t AND i.business_id = :b AND p.track_stock = 1', ['t' => $ticketId, 'b' => $bid]);
        foreach ($items as $it) {
            self::adjust($bid, $uid, (int)$it['product_id'], (int)$it['qty'], $reason, $ticketId);
        }
    }

    public static function recalcItems(int $bid, int $ticketId): void
    {
        Db::exec('UPDATE tickets SET items_cents = (SELECT COALESCE(SUM(total_cents), 0) FROM ticket_items WHERE ticket_id = :t) WHERE id = :t2 AND business_id = :b', ['t' => $ticketId, 't2' => $ticketId, 'b' => $bid]);
    }

    public static function items(int $bid, int $ticketId): array
    {
        $rows = Db::all('SELECT id, product_id, name, unit_cents, qty, total_cents, created_at FROM ticket_items WHERE ticket_id = :t AND business_id = :b ORDER BY id', ['t' => $ticketId, 'b' => $bid]);
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'product_id' => $r['product_id'] !== null ? (int)$r['product_id'] : null, 'name' => $r['name'], 'unit_cents' => (int)$r['unit_cents'], 'qty' => (int)$r['qty'], 'total_cents' => (int)$r['total_cents'], 'created_at' => Db::iso($r['created_at'])], $rows);
    }

    /**
     * Masasız hızlı satış: tek işlemde adisyon + kalemler + ödeme.
     * @param array<int,array{product_id:int,qty:int}> $lines
     */
    public static function quickSale(array $biz, array $user, array $lines, array $discount, array $payments): array
    {
        $bid = (int)$biz['id'];
        $uid = (int)$user['id'];
        $shiftId = Shifts::requireOpen($biz);
        if (!$lines || count($lines) > 50) {
            throw new HttpError(422, 'Sepet boş.', 'validation');
        }
        // Ürünleri ve tutarı sunucuda hesapla
        $merged = [];
        foreach ($lines as $l) {
            $pid = $l['product_id'] ?? null;
            $q = $l['qty'] ?? null;
            if (!is_int($pid) || !is_int($q) || $q < 1 || $q > 99) {
                throw new HttpError(422, 'Sepet geçersiz.', 'validation');
            }
            $merged[$pid] = ($merged[$pid] ?? 0) + $q;
        }
        $subtotal = 0;
        $prods = [];
        foreach ($merged as $pid => $q) {
            $p = self::get($bid, (int)$pid);
            if ((int)$p['is_active'] !== 1) {
                throw new HttpError(409, "“{$p['name']}” satışta değil.", 'inactive');
            }
            $prods[$pid] = $p;
            $subtotal += (int)$p['price_cents'] * $q;
        }
        [$disc, $total] = Games::applyDiscount($biz, $user, $subtotal, $discount);
        $payments = $total > 0 ? Games::validatePayments($payments, $total) : [];
        $now = Db::now();
        $ticketId = Db::tx(static function () use ($bid, $uid, $merged, $prods, $subtotal, $disc, $total, $payments, $shiftId, $now) {
            $tid = Db::insert('tickets', ['business_id' => $bid, 'type' => 'quick', 'status' => 'open', 'game_cents' => 0, 'items_cents' => 0, 'discount_cents' => 0, 'total_cents' => 0, 'discount_note' => '', 'shift_id' => null, 'created_by' => $uid, 'closed_by' => null, 'closed_at' => null, 'created_at' => $now]);
            foreach ($merged as $pid => $q) {
                $p = $prods[$pid];
                if ((int)$p['track_stock'] === 1) {
                    self::adjust($bid, $uid, (int)$pid, -$q, 'sale', $tid);
                }
                Db::insert('ticket_items', ['business_id' => $bid, 'ticket_id' => $tid, 'product_id' => (int)$pid, 'name' => $p['name'], 'unit_cents' => (int)$p['price_cents'], 'qty' => $q, 'total_cents' => (int)$p['price_cents'] * $q, 'cost_cents' => $p['cost_cents'] !== null ? (int)$p['cost_cents'] * $q : null, 'created_by' => $uid, 'created_at' => $now]);
            }
            Db::exec("UPDATE tickets SET status = 'paid', items_cents = :i, discount_cents = :d, total_cents = :t, shift_id = :s, closed_by = :u, closed_at = :c WHERE id = :id AND business_id = :b", ['i' => $subtotal, 'd' => $disc, 't' => $total, 's' => $shiftId, 'u' => $uid, 'c' => $now, 'id' => $tid, 'b' => $bid]);
            foreach ($payments as $pm) {
                Db::insert('payments', ['business_id' => $bid, 'ticket_id' => $tid, 'shift_id' => $shiftId, 'method' => $pm['method'], 'amount_cents' => $pm['amount'], 'customer_id' => $pm['customer_id'] ?? null, 'created_by' => $uid, 'created_at' => $now, 'voided_at' => null]);
            }
            Customers::chargePayments($bid, $uid, $tid, $shiftId, $payments);
            return $tid;
        });
        Audit::log($bid, $uid, 'quick.sale', 'ticket', $ticketId, ['total' => $total, 'discount' => $disc]);
        return ['ticket_id' => $ticketId, 'subtotal_cents' => $subtotal, 'discount_cents' => $disc, 'total_cents' => $total];
    }

    /** Hızlı satışı iptal eder (yalnızca açık vardiyada; ödemeler ve stok geri alınır). */
    public static function voidQuick(array $biz, int $uid, int $ticketId): void
    {
        $bid = (int)$biz['id'];
        $shiftId = Shifts::requireOpen($biz);
        Db::tx(static function () use ($bid, $uid, $ticketId, $shiftId) {
            $n = Db::exec("UPDATE tickets SET status = 'void', closed_by = :u, closed_at = :c WHERE id = :id AND business_id = :b AND type = 'quick' AND status = 'paid' AND shift_id = :s", ['u' => $uid, 'c' => Db::now(), 'id' => $ticketId, 'b' => $bid, 's' => $shiftId]);
            if ($n !== 1) {
                throw new HttpError(409, 'Bu satış iptal edilemez (kapanmış vardiya ya da zaten iptal).', 'conflict');
            }
            Customers::reverseTicket($bid, $uid, $ticketId);
            Db::exec('UPDATE payments SET voided_at = :v WHERE ticket_id = :t AND business_id = :b AND voided_at IS NULL', ['v' => Db::now(), 't' => $ticketId, 'b' => $bid]);
            self::restockTicket($bid, $ticketId, $uid, 'void');
        });
        Audit::log($bid, $uid, 'quick.void', 'ticket', $ticketId);
    }
}
