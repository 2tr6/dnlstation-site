<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

use Dnl\Core\Audit;
use Dnl\Core\Db;
use Dnl\Core\HttpError;

/**
 * Vardiya (kasa) yönetimi. Bir işletmede aynı anda tek açık vardiya olur;
 * businesses.open_shift_id koşullu güncellemeyle kilitlenir.
 */
final class Shifts
{
    /** Açık vardiya kimliğini veritabanından taze okur; yoksa 409 */
    public static function requireOpen(array $biz): int
    {
        $id = Db::value('SELECT open_shift_id FROM businesses WHERE id = :b', ['b' => $biz['id']]);
        if ($id === null) {
            throw new HttpError(409, 'Hesap almak için önce vardiya (kasa) açın.', 'no_shift');
        }
        return (int)$id;
    }

    public static function open(array $biz, int $uid, int $openingCash): int
    {
        $bid = (int)$biz['id'];
        $now = Db::now();
        $id = Db::tx(static function () use ($bid, $uid, $openingCash, $now) {
            $id = Db::insert('shifts', ['business_id' => $bid, 'opened_by' => $uid, 'opened_at' => $now, 'opening_cash_cents' => $openingCash, 'closed_by' => null, 'closed_at' => null, 'counted_cash_cents' => null, 'expected_cash_cents' => null, 'note' => '']);
            $n = Db::exec('UPDATE businesses SET open_shift_id = :s WHERE id = :b AND open_shift_id IS NULL', ['s' => $id, 'b' => $bid]);
            if ($n !== 1) {
                throw new HttpError(409, 'Zaten açık bir vardiya var.', 'shift_open');
            }
            return $id;
        });
        Audit::log($bid, $uid, 'shift.open', 'shift', $id, ['opening' => $openingCash]);
        return $id;
    }

    /** Vardiya özeti: ödeme yöntemlerine göre toplamlar, beklenen nakit */
    public static function totals(int $bid, array $shift): array
    {
        $rows = Db::all('SELECT method, COALESCE(SUM(amount_cents), 0) AS total, COUNT(*) AS cnt FROM payments WHERE business_id = :b AND shift_id = :s AND voided_at IS NULL GROUP BY method', ['b' => $bid, 's' => $shift['id']]);
        $by = ['cash' => 0, 'card' => 0, 'other' => 0, 'credit' => 0];
        foreach ($rows as $r) {
            $by[$r['method']] = (int)$r['total'];
        }
        // Veresiye tahsilatları (kasaya giren) ve kasadan ödenen giderler
        $col = ['cash' => 0, 'card' => 0, 'other' => 0];
        foreach (Db::all("SELECT method, COALESCE(SUM(-amount_cents),0) AS s FROM customer_moves WHERE business_id = :b AND shift_id = :s AND kind = 'collection' GROUP BY method", ['b' => $bid, 's' => $shift['id']]) as $r) {
            $col[$r['method']] = (int)$r['s'];
        }
        $exp = ['cash' => 0, 'card' => 0, 'other' => 0];
        foreach (Db::all('SELECT method, COALESCE(SUM(amount_cents),0) AS s FROM expenses WHERE business_id = :b AND shift_id = :s AND voided_at IS NULL GROUP BY method', ['b' => $bid, 's' => $shift['id']]) as $r) {
            $exp[$r['method']] = (int)$r['s'];
        }
        $t = Db::one("SELECT COUNT(*) AS cnt, COALESCE(SUM(total_cents),0) AS total, COALESCE(SUM(discount_cents),0) AS disc, COALESCE(SUM(game_cents),0) AS game, COALESCE(SUM(items_cents),0) AS items FROM tickets WHERE business_id = :b AND shift_id = :s AND status = 'paid'", ['b' => $bid, 's' => $shift['id']]);
        $voids = (int)Db::value("SELECT COUNT(*) FROM tickets WHERE business_id = :b AND status = 'void' AND closed_at >= :o AND closed_at <= :c", ['b' => $bid, 'o' => $shift['opened_at'], 'c' => $shift['closed_at'] ?? '9999-12-31 23:59:59']);
        return [
            'id' => (int)$shift['id'],
            'opened_at' => Db::iso($shift['opened_at']),
            'closed_at' => $shift['closed_at'] !== null ? Db::iso($shift['closed_at']) : null,
            'opened_by' => Db::value('SELECT name FROM users WHERE id = :u', ['u' => $shift['opened_by']]),
            'closed_by' => $shift['closed_by'] !== null ? Db::value('SELECT name FROM users WHERE id = :u', ['u' => $shift['closed_by']]) : null,
            'opening_cash_cents' => (int)$shift['opening_cash_cents'],
            'cash_cents' => $by['cash'],
            'card_cents' => $by['card'],
            'other_cents' => $by['other'],
            'credit_cents' => $by['credit'],
            'collections_cents' => array_sum($col),
            'collections_cash_cents' => $col['cash'],
            'expense_cents' => array_sum($exp),
            'expense_cash_cents' => $exp['cash'],
            'revenue_cents' => $by['cash'] + $by['card'] + $by['other'],
            'tickets' => (int)$t['cnt'],
            'game_cents' => (int)$t['game'],
            'items_cents' => (int)$t['items'],
            'discount_cents' => (int)$t['disc'],
            'voids' => $voids,
            'expected_cash_cents' => (int)$shift['opening_cash_cents'] + $by['cash'] + $col['cash'] - $exp['cash'],
            'counted_cash_cents' => $shift['counted_cash_cents'] !== null ? (int)$shift['counted_cash_cents'] : null,
            'note' => $shift['note'],
        ];
    }

    public static function current(int $bid): ?array
    {
        $id = Db::value('SELECT open_shift_id FROM businesses WHERE id = :b', ['b' => $bid]);
        if ($id === null) {
            return null;
        }
        $s = Db::one('SELECT * FROM shifts WHERE id = :id AND business_id = :b', ['id' => $id, 'b' => $bid]);
        return $s ? self::totals($bid, $s) : null;
    }

    /** Vardiyayı kapatır (Z raporu). Açık masa varken kapatılamaz. */
    public static function close(array $biz, int $uid, int $counted, string $note): array
    {
        $bid = (int)$biz['id'];
        $sid = self::requireOpen($biz);
        $active = (int)Db::value("SELECT COUNT(*) FROM play_sessions WHERE business_id = :b AND status = 'active'", ['b' => $bid]);
        if ($active > 0) {
            throw new HttpError(409, "Açık $active masa var. Vardiyayı kapatmadan önce hesapları kapatın.", 'active_sessions');
        }
        $shift = Db::one('SELECT * FROM shifts WHERE id = :id AND business_id = :b', ['id' => $sid, 'b' => $bid]);
        $tot = self::totals($bid, $shift);
        $now = Db::now();
        Db::tx(static function () use ($bid, $sid, $uid, $counted, $note, $tot, $now) {
            $n = Db::exec('UPDATE businesses SET open_shift_id = NULL WHERE id = :b AND open_shift_id = :s', ['b' => $bid, 's' => $sid]);
            if ($n !== 1) {
                throw new HttpError(409, 'Vardiya az önce başka bir cihazdan kapatıldı.', 'conflict');
            }
            Db::exec('UPDATE shifts SET closed_by = :u, closed_at = :c, counted_cash_cents = :cc, expected_cash_cents = :e, note = :n WHERE id = :id AND business_id = :b', [
                'u' => $uid, 'c' => $now, 'cc' => $counted, 'e' => $tot['expected_cash_cents'], 'n' => mb_substr($note, 0, 200), 'id' => $sid, 'b' => $bid,
            ]);
        });
        Audit::log($bid, $uid, 'shift.close', 'shift', $sid, ['expected' => $tot['expected_cash_cents'], 'counted' => $counted]);
        $shift = Db::one('SELECT * FROM shifts WHERE id = :id', ['id' => $sid]);
        $z = self::totals($bid, $shift);
        // İşletme sahibine Z raporu e-postası (ayardan kapatılabilir; gönderim hatası kapanışı engellemez)
        $settings = json_decode((string)($biz['settings'] ?? ''), true) ?: [];
        if (($settings['email_z_report'] ?? true) !== false) {
            $owner = Db::one("SELECT name, email FROM users WHERE business_id = :b AND role = 'owner' AND is_active = 1 LIMIT 1", ['b' => $bid]);
            if ($owner) {
                try {
                    Emails::zReport($owner['email'], $owner['name'], (string)$biz['name'], $z, $bid);
                } catch (\Throwable $e) {
                    \Dnl\Core\Log::error($e, 'z-mail');
                }
            }
        }
        return $z;
    }

    public static function list(int $bid, int $limit = 30): array
    {
        $rows = Db::all('SELECT * FROM shifts WHERE business_id = :b ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)), ['b' => $bid]);
        return array_map(static fn($s) => self::totals($bid, $s), $rows);
    }

    public static function get(int $bid, int $id): array
    {
        $s = Db::one('SELECT * FROM shifts WHERE id = :id AND business_id = :b', ['id' => $id, 'b' => $bid]);
        if (!$s) {
            throw new HttpError(404, 'Vardiya bulunamadı.', 'not_found');
        }
        return self::totals($bid, $s);
    }
}
