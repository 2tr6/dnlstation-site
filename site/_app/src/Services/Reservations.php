<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

use Dnl\Core\Audit;
use Dnl\Core\Db;
use Dnl\Core\HttpError;

/** Masa rezervasyonları. Saatler UTC saklanır; arayüz işletme saat dilimini kullanır. */
final class Reservations
{
    public const STATUSES = ['booked', 'arrived', 'cancelled', 'noshow'];

    public static function out(array $r): array
    {
        return ['id' => (int)$r['id'], 'table_id' => $r['table_id'] !== null ? (int)$r['table_id'] : null, 'table_name' => $r['table_name'] ?? null,
            'customer_name' => $r['customer_name'], 'phone' => $r['phone'], 'starts_at' => Db::iso($r['starts_at']), 'duration_minutes' => (int)$r['duration_minutes'],
            'people' => $r['people'] !== null ? (int)$r['people'] : null, 'note' => $r['note'], 'status' => $r['status'], 'by' => $r['by_name'] ?? null];
    }

    /** Yerel "YYYY-MM-DD" + "SS:DD" → UTC */
    public static function toUtc(string $date, string $time, string $tz): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", new \DateTimeZone($tz));
        if (!$d || $d->format('Y-m-d H:i') !== "$date $time") {
            throw new HttpError(422, 'Tarih ya da saat geçersiz.', 'validation', ['date' => 'Tarih ya da saat geçersiz.']);
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function list(int $bid, string $fromUtc, string $toUtc): array
    {
        $rows = Db::all('SELECT r.*, g.name AS table_name, u.name AS by_name FROM reservations r LEFT JOIN game_tables g ON g.id = r.table_id AND g.business_id = r.business_id LEFT JOIN users u ON u.id = r.created_by
            WHERE r.business_id = :b AND r.starts_at >= :f AND r.starts_at < :t ORDER BY r.starts_at', ['b' => $bid, 'f' => $fromUtc, 't' => $toUtc]);
        return array_map([self::class, 'out'], $rows);
    }

    /** Masa için çakışan aktif rezervasyon var mı? */
    private static function assertFree(int $bid, ?int $tableId, string $startUtc, int $minutes, ?int $exceptId): void
    {
        if ($tableId === null) {
            return;
        }
        $end = gmdate('Y-m-d H:i:s', strtotime($startUtc . ' UTC') + $minutes * 60);
        $rows = Db::all("SELECT id, starts_at, duration_minutes FROM reservations WHERE business_id = :b AND table_id = :t AND status = 'booked' AND starts_at < :e AND starts_at > :s0", [
            'b' => $bid, 't' => $tableId, 'e' => $end, 's0' => gmdate('Y-m-d H:i:s', strtotime($startUtc . ' UTC') - 86400),
        ]);
        foreach ($rows as $r) {
            if ($exceptId !== null && (int)$r['id'] === $exceptId) {
                continue;
            }
            $rEnd = strtotime($r['starts_at'] . ' UTC') + (int)$r['duration_minutes'] * 60;
            if ($rEnd > strtotime($startUtc . ' UTC')) {
                throw new HttpError(409, 'Bu masa o saatte başka bir rezervasyona ayrılmış.', 'overlap');
            }
        }
    }

    public static function save(int $bid, int $uid, ?int $id, array $d): int
    {
        if ($d['table_id'] !== null && !Db::value('SELECT id FROM game_tables WHERE id = :t AND business_id = :b', ['t' => $d['table_id'], 'b' => $bid])) {
            throw new HttpError(404, 'Masa bulunamadı.', 'not_found');
        }
        self::assertFree($bid, $d['table_id'], $d['starts_at'], $d['duration_minutes'], $id);
        $now = Db::now();
        $row = ['table_id' => $d['table_id'], 'customer_name' => $d['customer_name'], 'phone' => $d['phone'], 'starts_at' => $d['starts_at'],
            'duration_minutes' => $d['duration_minutes'], 'people' => $d['people'], 'note' => $d['note'], 'updated_at' => $now];
        if ($id === null) {
            $id = Db::insert('reservations', $row + ['business_id' => $bid, 'status' => 'booked', 'created_by' => $uid, 'created_at' => $now]);
            Audit::log($bid, $uid, 'reservation.create', 'reservation', $id, ['name' => $d['customer_name']]);
        } else {
            if (Db::update('reservations', $row, ['id' => $id, 'business_id' => $bid]) !== 1) {
                throw new HttpError(404, 'Rezervasyon bulunamadı.', 'not_found');
            }
            Audit::log($bid, $uid, 'reservation.update', 'reservation', $id);
        }
        return $id;
    }

    public static function setStatus(int $bid, int $uid, int $id, string $status): void
    {
        $n = Db::exec('UPDATE reservations SET status = :s, updated_at = :n WHERE id = :id AND business_id = :b', ['s' => $status, 'n' => Db::now(), 'id' => $id, 'b' => $bid]);
        if ($n !== 1) {
            throw new HttpError(404, 'Rezervasyon bulunamadı.', 'not_found');
        }
        Audit::log($bid, $uid, 'reservation.' . $status, 'reservation', $id);
    }

    /** Panel kartlarında gösterilecek: önümüzdeki 3 saat içindeki aktif rezervasyonlar (masaya göre) */
    public static function upcomingByTable(int $bid): array
    {
        $rows = Db::all("SELECT table_id, customer_name, starts_at FROM reservations WHERE business_id = :b AND status = 'booked' AND table_id IS NOT NULL AND starts_at >= :f AND starts_at <= :t ORDER BY starts_at", [
            'b' => $bid, 'f' => gmdate('Y-m-d H:i:s', time() - 1800), 't' => gmdate('Y-m-d H:i:s', time() + 3 * 3600),
        ]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['table_id']] ??= ['name' => $r['customer_name'], 'at' => Db::iso($r['starts_at'])];
        }
        return $out;
    }
}
