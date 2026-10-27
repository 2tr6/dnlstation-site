<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

use Dnl\Core\Audit;
use Dnl\Core\Db;
use Dnl\Core\HttpError;

/**
 * Müşteri cari hesapları (veresiye). balance_cents > 0: müşterinin işletmeye borcu.
 * Bakiye yalnızca koşullu UPDATE ile değişir ve her değişiklik customer_moves tablosuna yazılır.
 */
final class Customers
{
    public static function out(array $c): array
    {
        return ['id' => (int)$c['id'], 'name' => $c['name'], 'phone' => $c['phone'], 'note' => $c['note'], 'balance_cents' => (int)$c['balance_cents'],
            'credit_limit_cents' => $c['credit_limit_cents'] !== null ? (int)$c['credit_limit_cents'] : null, 'is_active' => (int)$c['is_active'] === 1];
    }

    public static function get(int $bid, int $id): array
    {
        $c = Db::one('SELECT * FROM customers WHERE id = :id AND business_id = :b', ['id' => $id, 'b' => $bid]);
        if (!$c) {
            throw new HttpError(404, 'Müşteri bulunamadı.', 'not_found');
        }
        return $c;
    }

    public static function list(int $bid): array
    {
        return array_map([self::class, 'out'], Db::all('SELECT * FROM customers WHERE business_id = :b ORDER BY is_active DESC, balance_cents DESC, name', ['b' => $bid]));
    }

    public static function save(int $bid, int $uid, ?int $id, array $d): int
    {
        $now = Db::now();
        $row = ['name' => $d['name'], 'phone' => $d['phone'], 'note' => $d['note'], 'credit_limit_cents' => $d['credit_limit_cents'], 'is_active' => $d['is_active'] ? 1 : 0, 'updated_at' => $now];
        if ($id === null) {
            if ((int)Db::value('SELECT COUNT(*) FROM customers WHERE business_id = :b', ['b' => $bid]) >= 5000) {
                throw new HttpError(422, 'Müşteri sınırına ulaşıldı.', 'limit');
            }
            $id = Db::insert('customers', $row + ['business_id' => $bid, 'balance_cents' => 0, 'created_at' => $now]);
            Audit::log($bid, $uid, 'customer.create', 'customer', $id, ['name' => $d['name']]);
        } else {
            self::get($bid, $id);
            Db::update('customers', $row, ['id' => $id, 'business_id' => $bid]);
            Audit::log($bid, $uid, 'customer.update', 'customer', $id);
        }
        return $id;
    }

    /** Bakiyeyi değiştirir ve hareket yazar. Borç limiti aşılırsa 409. */
    public static function move(int $bid, int $uid, int $customerId, int $amount, string $kind, ?string $method, ?int $ticketId, ?int $shiftId, string $note = ''): void
    {
        $c = self::get($bid, $customerId);
        if ($amount > 0) {
            if ((int)$c['is_active'] !== 1) {
                throw new HttpError(409, "“{$c['name']}” pasif durumda; veresiye yazılamaz.", 'inactive');
            }
            $limit = $c['credit_limit_cents'] !== null ? (int)$c['credit_limit_cents'] : null;
            $n = $limit === null
                ? Db::exec('UPDATE customers SET balance_cents = balance_cents + :a, updated_at = :n WHERE id = :id AND business_id = :b', ['a' => $amount, 'n' => Db::now(), 'id' => $customerId, 'b' => $bid])
                : Db::exec('UPDATE customers SET balance_cents = balance_cents + :a, updated_at = :n WHERE id = :id AND business_id = :b AND balance_cents + :a2 <= :l', ['a' => $amount, 'a2' => $amount, 'l' => $limit, 'n' => Db::now(), 'id' => $customerId, 'b' => $bid]);
            if ($n !== 1) {
                throw new HttpError(409, "“{$c['name']}” için veresiye limiti aşılıyor.", 'credit_limit');
            }
        } else {
            Db::exec('UPDATE customers SET balance_cents = balance_cents + :a, updated_at = :n WHERE id = :id AND business_id = :b', ['a' => $amount, 'n' => Db::now(), 'id' => $customerId, 'b' => $bid]);
        }
        Db::insert('customer_moves', ['business_id' => $bid, 'customer_id' => $customerId, 'amount_cents' => $amount, 'kind' => $kind, 'method' => $method,
            'ticket_id' => $ticketId, 'shift_id' => $shiftId, 'note' => mb_substr($note, 0, 200), 'created_by' => $uid, 'created_at' => Db::now()]);
    }

    /** Hesap kapanışında veresiye ödemelerini müşteriye borç yazar */
    public static function chargePayments(int $bid, int $uid, int $ticketId, int $shiftId, array $payments): void
    {
        foreach ($payments as $p) {
            if ($p['method'] === 'credit') {
                self::move($bid, $uid, (int)$p['customer_id'], (int)$p['amount'], 'sale', null, $ticketId, $shiftId, 'Adisyon #' . $ticketId);
            }
        }
    }

    /** İptal/geri alınan adisyonun veresiye borcunu geri alır */
    public static function reverseTicket(int $bid, int $uid, int $ticketId): void
    {
        $rows = Db::all("SELECT customer_id, amount_cents FROM payments WHERE ticket_id = :t AND business_id = :b AND method = 'credit' AND customer_id IS NOT NULL AND voided_at IS NULL", ['t' => $ticketId, 'b' => $bid]);
        foreach ($rows as $r) {
            self::move($bid, $uid, (int)$r['customer_id'], -(int)$r['amount_cents'], 'reversal', null, $ticketId, null, 'Adisyon #' . $ticketId . ' iptal/geri alma');
        }
    }

    /** Müşteriden tahsilat (kasaya girer) */
    public static function collect(array $biz, int $uid, int $customerId, int $amount, string $method, string $note): void
    {
        $bid = (int)$biz['id'];
        $shiftId = Shifts::requireOpen($biz);
        $c = self::get($bid, $customerId);
        if ($amount > (int)$c['balance_cents']) {
            throw new HttpError(422, 'Tahsilat tutarı müşterinin borcundan fazla olamaz.', 'validation', ['amount' => 'Borç: ' . number_format((int)$c['balance_cents'] / 100, 2, ',', '.') . ' ₺']);
        }
        Db::tx(static function () use ($bid, $uid, $customerId, $amount, $method, $shiftId, $note) {
            $n = Db::exec('UPDATE customers SET balance_cents = balance_cents - :a, updated_at = :n WHERE id = :id AND business_id = :b AND balance_cents >= :a2', ['a' => $amount, 'a2' => $amount, 'n' => Db::now(), 'id' => $customerId, 'b' => $bid]);
            if ($n !== 1) {
                throw new HttpError(409, 'Bakiye bu sırada değişti. Tekrar deneyin.', 'conflict');
            }
            Db::insert('customer_moves', ['business_id' => $bid, 'customer_id' => $customerId, 'amount_cents' => -$amount, 'kind' => 'collection', 'method' => $method,
                'ticket_id' => null, 'shift_id' => $shiftId, 'note' => mb_substr($note, 0, 200), 'created_by' => $uid, 'created_at' => Db::now()]);
        });
        Audit::log($bid, $uid, 'customer.collect', 'customer', $customerId, ['amount' => $amount, 'method' => $method]);
    }

    public static function moves(int $bid, int $customerId): array
    {
        $rows = Db::all('SELECT m.*, u.name AS by_name FROM customer_moves m LEFT JOIN users u ON u.id = m.created_by WHERE m.customer_id = :c AND m.business_id = :b ORDER BY m.id DESC LIMIT 200', ['c' => $customerId, 'b' => $bid]);
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'amount_cents' => (int)$r['amount_cents'], 'kind' => $r['kind'], 'method' => $r['method'], 'ticket_id' => $r['ticket_id'] !== null ? (int)$r['ticket_id'] : null,
            'note' => $r['note'], 'by' => $r['by_name'], 'at' => Db::iso($r['created_at'])], $rows);
    }
}
