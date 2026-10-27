<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

use Dnl\Core\Audit;
use Dnl\Core\Db;
use Dnl\Core\HttpError;

/** Giderler (kira, fatura, ürün alımı...). Kasadan nakit ödenen gider açık vardiyanın beklenen nakdinden düşer. */
final class Expenses
{
    public const CATEGORIES = ['Ürün alımı', 'Kira', 'Elektrik / su / doğalgaz', 'İnternet / abonelik', 'Personel', 'Bakım / onarım', 'Ekipman', 'Temizlik', 'Vergi / harç', 'Diğer'];

    public static function out(array $r): array
    {
        return ['id' => (int)$r['id'], 'category' => $r['category'], 'amount_cents' => (int)$r['amount_cents'], 'method' => $r['method'], 'note' => $r['note'],
            'spent_on' => $r['spent_on'], 'shift_id' => $r['shift_id'] !== null ? (int)$r['shift_id'] : null, 'by' => $r['by_name'] ?? null,
            'voided' => $r['voided_at'] !== null, 'created_at' => Db::iso($r['created_at'])];
    }

    public static function add(array $biz, int $uid, string $category, int $amount, string $method, string $note, string $spentOn): int
    {
        $bid = (int)$biz['id'];
        // Kasadan nakit çıkışı yalnızca açık vardiyada ve vardiyaya bağlı yapılır
        $shiftId = null;
        if ($method === 'cash') {
            $shiftId = Shifts::requireOpen($biz);
            $shift = Db::one('SELECT * FROM shifts WHERE id = :id AND business_id = :b', ['id' => $shiftId, 'b' => $bid]);
            $expected = (int)Shifts::totals($bid, $shift)['expected_cash_cents'];
            if ($amount > $expected) {
                throw new HttpError(409, 'Kasada bu kadar nakit görünmüyor (beklenen ' . number_format($expected / 100, 2, ',', '.') . ' ₺). Ödemeyi “Banka / diğer” olarak girin ya da açılış kasasını kontrol edin.', 'insufficient_cash');
            }
        }
        $id = Db::insert('expenses', ['business_id' => $bid, 'shift_id' => $shiftId, 'category' => $category, 'amount_cents' => $amount, 'method' => $method,
            'note' => mb_substr($note, 0, 200), 'spent_on' => $spentOn, 'created_by' => $uid, 'voided_at' => null, 'created_at' => Db::now()]);
        Audit::log($bid, $uid, 'expense.add', 'expense', $id, ['amount' => $amount, 'category' => $category, 'method' => $method]);
        return $id;
    }

    public static function void(array $biz, int $uid, int $id): void
    {
        $bid = (int)$biz['id'];
        $e = Db::one('SELECT * FROM expenses WHERE id = :id AND business_id = :b', ['id' => $id, 'b' => $bid]);
        if (!$e || $e['voided_at'] !== null) {
            throw new HttpError(404, 'Gider bulunamadı.', 'not_found');
        }
        if ($e['shift_id'] !== null && (int)$e['shift_id'] !== (int)Db::value('SELECT open_shift_id FROM businesses WHERE id = :b', ['b' => $bid])) {
            throw new HttpError(409, 'Kapanmış vardiyaya ait kasa gideri iptal edilemez.', 'shift_closed');
        }
        Db::exec('UPDATE expenses SET voided_at = :n WHERE id = :id AND business_id = :b AND voided_at IS NULL', ['n' => Db::now(), 'id' => $id, 'b' => $bid]);
        Audit::log($bid, $uid, 'expense.void', 'expense', $id, ['amount' => (int)$e['amount_cents']]);
    }

    public static function list(int $bid, string $from, string $to): array
    {
        $rows = Db::all('SELECT e.*, u.name AS by_name FROM expenses e LEFT JOIN users u ON u.id = e.created_by WHERE e.business_id = :b AND e.spent_on >= :f AND e.spent_on <= :t ORDER BY e.spent_on DESC, e.id DESC LIMIT 500', ['b' => $bid, 'f' => $from, 't' => $to]);
        return array_map([self::class, 'out'], $rows);
    }
}
