<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

use Dnl\Core\Audit;
use Dnl\Core\Db;
use Dnl\Core\HttpError;

/**
 * Masa oturumları. Her sorgu business_id ile sınırlandırılır (işletme izolasyonu).
 * Eşzamanlılık: masa "active_session_id IS NULL" koşullu güncellemeyle kilitlenir;
 * aynı masa iki cihazdan aynı anda açılamaz, aynı oturum iki kez kapatılamaz.
 */
final class Games
{
    public const MODES = ['open', 'fixed', 'match'];

    public static function table(int $bid, int $tableId): array
    {
        $t = Db::one('SELECT g.*, t.name AS type_name, t.kind, t.uses_controllers, t.hour_price, t.hour_price_4, t.match_price, t.match_price_4, t.night_enabled, t.night_start, t.night_end, t.night_hour_price, t.night_hour_price_4, t.min_minutes
            FROM game_tables g JOIN table_types t ON t.id = g.table_type_id AND t.business_id = g.business_id
            WHERE g.id = :id AND g.business_id = :b', ['id' => $tableId, 'b' => $bid]);
        if (!$t) {
            throw new HttpError(404, 'Masa bulunamadı.', 'not_found');
        }
        return $t;
    }

    public static function session(int $bid, int $sid, bool $activeOnly = true): array
    {
        $s = Db::one('SELECT * FROM play_sessions WHERE id = :id AND business_id = :b', ['id' => $sid, 'b' => $bid]);
        if (!$s) {
            throw new HttpError(404, 'Oturum bulunamadı.', 'not_found');
        }
        if ($activeOnly && $s['status'] !== 'active') {
            throw new HttpError(409, 'Bu masa oturumu artık açık değil. Ekranı yenileyin.', 'not_active');
        }
        $s['p'] = json_decode((string)$s['pricing'], true) ?: [];
        return $s;
    }

    public static function segments(int $sid): array
    {
        return Db::all('SELECT * FROM session_segments WHERE session_id = :s ORDER BY id', ['s' => $sid]);
    }

    public static function open(array $biz, int $uid, int $tableId, string $mode, ?int $controllers, ?int $planned, string $note = ''): int
    {
        $bid = (int)$biz['id'];
        $t = self::table($bid, $tableId);
        if ((int)$t['is_active'] !== 1) {
            throw new HttpError(409, 'Bu masa pasif durumda.', 'inactive');
        }
        if ($t['active_session_id'] !== null) {
            throw new HttpError(409, 'Bu masa zaten dolu.', 'occupied');
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new HttpError(422, 'Geçersiz ücretlendirme.', 'validation');
        }
        $uses = (int)$t['uses_controllers'] === 1;
        if ($uses) {
            $controllers = in_array($controllers, [2, 4], true) ? $controllers : 2;
        } else {
            $controllers = null;
        }
        if ($mode === 'match' && $t['match_price'] === null) {
            throw new HttpError(422, 'Bu masa türü için maç başı ücret tanımlı değil. Ayarlar > Masalar bölümünden ekleyin.', 'no_match_price');
        }
        if ($mode === 'fixed' && ($planned === null || $planned < 5 || $planned > 1440)) {
            throw new HttpError(422, 'Süre 5 dakika ile 24 saat arasında olmalı.', 'validation');
        }
        if ($mode !== 'fixed') {
            $planned = null;
        }
        $now = Db::now();
        $pricing = Pricing::snapshot($t, (string)$biz['timezone']);

        $sid = Db::tx(static function () use ($bid, $uid, $tableId, $mode, $controllers, $planned, $note, $now, $pricing) {
            $ticketId = Db::insert('tickets', [
                'business_id' => $bid, 'type' => 'session', 'status' => 'open', 'game_cents' => 0, 'items_cents' => 0,
                'discount_cents' => 0, 'total_cents' => 0, 'discount_note' => '', 'shift_id' => null,
                'created_by' => $uid, 'closed_by' => null, 'closed_at' => null, 'created_at' => $now,
            ]);
            $sid = Db::insert('play_sessions', [
                'business_id' => $bid, 'table_id' => $tableId, 'ticket_id' => $ticketId, 'mode' => $mode,
                'controllers' => $controllers, 'planned_minutes' => $planned, 'match_count' => 0,
                'pricing' => json_encode($pricing), 'started_at' => $now, 'ended_at' => null, 'status' => 'active',
                'opened_by' => $uid, 'closed_by' => null, 'note' => mb_substr($note, 0, 200), 'created_at' => $now,
            ]);
            Db::insert('session_segments', ['session_id' => $sid, 'controllers' => $controllers, 'started_at' => $now, 'ended_at' => null]);
            $claimed = Db::exec('UPDATE game_tables SET active_session_id = :s WHERE id = :t AND business_id = :b AND active_session_id IS NULL AND is_active = 1', ['s' => $sid, 't' => $tableId, 'b' => $bid]);
            if ($claimed !== 1) {
                throw new HttpError(409, 'Bu masa az önce başka bir cihazdan açıldı.', 'occupied');
            }
            return $sid;
        });
        Audit::log($bid, $uid, 'session.open', 'session', $sid, ['table' => $tableId, 'mode' => $mode, 'controllers' => $controllers, 'planned' => $planned]);
        return $sid;
    }

    /** Sabit süreye dakika ekler; açık süreli oturuma zamanlayıcı kurar. */
    public static function extend(array $biz, int $uid, int $sid, int $minutes): void
    {
        $bid = (int)$biz['id'];
        $s = self::session($bid, $sid);
        if ($s['mode'] === 'match') {
            throw new HttpError(422, 'Maç başı oturumda süre eklenmez.', 'validation');
        }
        if ($s['mode'] === 'open') {
            $elapsedMin = (int)ceil((time() - strtotime($s['started_at'] . ' UTC')) / 60);
            $n = Db::exec("UPDATE play_sessions SET mode = 'fixed', planned_minutes = :p WHERE id = :id AND business_id = :b AND status = 'active'", ['p' => min(2880, $elapsedMin + $minutes), 'id' => $sid, 'b' => $bid]);
        } else {
            $n = Db::exec("UPDATE play_sessions SET planned_minutes = planned_minutes + :m WHERE id = :id AND business_id = :b AND status = 'active' AND planned_minutes + :m2 <= 2880", ['m' => $minutes, 'm2' => $minutes, 'id' => $sid, 'b' => $bid]);
        }
        if ($n !== 1) {
            throw new HttpError(409, 'Süre eklenemedi. Ekranı yenileyin.', 'conflict');
        }
        Audit::log($bid, $uid, 'session.extend', 'session', $sid, ['minutes' => $minutes]);
    }

    public static function setControllers(array $biz, int $uid, int $sid, int $controllers): void
    {
        $bid = (int)$biz['id'];
        $s = self::session($bid, $sid);
        if (!$s['p']['uses_controllers']) {
            throw new HttpError(422, 'Bu masa türünde kol sayısı kullanılmıyor.', 'validation');
        }
        if (!in_array($controllers, [2, 4], true)) {
            throw new HttpError(422, 'Kol sayısı 2 veya 4 olmalı.', 'validation');
        }
        if ((int)$s['controllers'] === $controllers) {
            return;
        }
        $now = Db::now();
        Db::tx(static function () use ($sid, $bid, $controllers, $now) {
            $n = Db::exec("UPDATE play_sessions SET controllers = :c WHERE id = :id AND business_id = :b AND status = 'active'", ['c' => $controllers, 'id' => $sid, 'b' => $bid]);
            if ($n !== 1) {
                throw new HttpError(409, 'Oturum güncellenemedi.', 'conflict');
            }
            Db::exec('UPDATE session_segments SET ended_at = :n WHERE session_id = :s AND ended_at IS NULL', ['n' => $now, 's' => $sid]);
            Db::insert('session_segments', ['session_id' => $sid, 'controllers' => $controllers, 'started_at' => $now, 'ended_at' => null]);
        });
        Audit::log($bid, $uid, 'session.controllers', 'session', $sid, ['controllers' => $controllers]);
    }

    public static function addMatch(array $biz, int $uid, int $sid, int $delta): void
    {
        $bid = (int)$biz['id'];
        $s = self::session($bid, $sid);
        if ($s['mode'] !== 'match') {
            throw new HttpError(422, 'Bu oturum maç başı değil.', 'validation');
        }
        $newCount = max(0, min(999, (int)$s['match_count'] + $delta));
        Db::exec("UPDATE play_sessions SET match_count = :c WHERE id = :id AND business_id = :b AND status = 'active'", ['c' => $newCount, 'id' => $sid, 'b' => $bid]);
        Audit::log($bid, $uid, 'session.match', 'session', $sid, ['delta' => $delta, 'count' => $newCount]);
    }

    public static function move(array $biz, int $uid, int $sid, int $toTable): void
    {
        $bid = (int)$biz['id'];
        $s = self::session($bid, $sid);
        $from = self::table($bid, (int)$s['table_id']);
        $to = self::table($bid, $toTable);
        if ((int)$to['id'] === (int)$from['id']) {
            return;
        }
        if ((int)$to['table_type_id'] !== (int)$from['table_type_id']) {
            throw new HttpError(422, 'Oturum yalnızca aynı türdeki bir masaya taşınabilir.', 'validation');
        }
        Db::tx(static function () use ($bid, $sid, $from, $to) {
            $claimed = Db::exec('UPDATE game_tables SET active_session_id = :s WHERE id = :t AND business_id = :b AND active_session_id IS NULL AND is_active = 1', ['s' => $sid, 't' => $to['id'], 'b' => $bid]);
            if ($claimed !== 1) {
                throw new HttpError(409, 'Hedef masa dolu.', 'occupied');
            }
            Db::exec('UPDATE game_tables SET active_session_id = NULL WHERE id = :t AND business_id = :b AND active_session_id = :s', ['t' => $from['id'], 'b' => $bid, 's' => $sid]);
            Db::exec("UPDATE play_sessions SET table_id = :t WHERE id = :id AND business_id = :b AND status = 'active'", ['t' => $to['id'], 'id' => $sid, 'b' => $bid]);
        });
        Audit::log($bid, $uid, 'session.move', 'session', $sid, ['from' => (int)$from['id'], 'to' => (int)$to['id']]);
    }

    /** Oturumun anlık hesabı (ödeme öncesi) */
    public static function bill(array $s, ?int $now = null): array
    {
        $now ??= time();
        $calc = Pricing::compute($s, self::segments((int)$s['id']), $now);
        $items = (int)Db::value('SELECT COALESCE(SUM(total_cents), 0) FROM ticket_items WHERE ticket_id = :t', ['t' => $s['ticket_id']]);
        return $calc + ['items_cents' => $items, 'subtotal_cents' => $calc['game_cents'] + $items];
    }

    /**
     * Hesabı kapatır. İndirim yetkisi ve ödeme toplamı sunucuda doğrulanır.
     * @param array{type:string,value:int} $discount type: none|percent|amount (amount kuruş)
     * @param array<int,array{method:string,amount:int}> $payments
     */
    public static function close(array $biz, array $user, int $sid, array $discount, array $payments, string $discountNote = ''): array
    {
        $bid = (int)$biz['id'];
        $uid = (int)$user['id'];
        $shiftId = Shifts::requireOpen($biz);
        $s = self::session($bid, $sid);
        $endTs = time();
        $endAt = gmdate('Y-m-d H:i:s', $endTs);
        $s['ended_at'] = $endAt;
        $bill = self::bill($s, $endTs);
        [$discountCents, $total] = self::applyDiscount($biz, $user, $bill['subtotal_cents'], $discount);
        $payments = self::validatePayments($payments, $total);

        Db::tx(static function () use ($bid, $uid, $sid, $s, $endAt, $bill, $discountCents, $discountNote, $total, $payments, $shiftId) {
            $n = Db::exec("UPDATE play_sessions SET status = 'closed', ended_at = :e, closed_by = :u WHERE id = :id AND business_id = :b AND status = 'active'", ['e' => $endAt, 'u' => $uid, 'id' => $sid, 'b' => $bid]);
            if ($n !== 1) {
                throw new HttpError(409, 'Bu hesap az önce başka bir cihazdan kapatıldı.', 'conflict');
            }
            Db::exec('UPDATE session_segments SET ended_at = :e WHERE session_id = :s AND ended_at IS NULL', ['e' => $endAt, 's' => $sid]);
            Db::exec("UPDATE tickets SET status = 'paid', game_cents = :g, items_cents = :i, discount_cents = :d, total_cents = :t, discount_note = :dn, shift_id = :sh, closed_by = :u, closed_at = :c WHERE id = :id AND business_id = :b AND status = 'open'", [
                'g' => $bill['game_cents'], 'i' => $bill['items_cents'], 'd' => $discountCents, 't' => $total, 'dn' => mb_substr($discountNote, 0, 80),
                'sh' => $shiftId, 'u' => $uid, 'c' => $endAt, 'id' => $s['ticket_id'], 'b' => $bid,
            ]);
            foreach ($payments as $p) {
                Db::insert('payments', ['business_id' => $bid, 'ticket_id' => $s['ticket_id'], 'shift_id' => $shiftId, 'method' => $p['method'], 'amount_cents' => $p['amount'], 'created_by' => $uid, 'created_at' => $endAt, 'voided_at' => null]);
            }
            Db::exec('UPDATE game_tables SET active_session_id = NULL WHERE id = :t AND business_id = :b AND active_session_id = :s', ['t' => $s['table_id'], 'b' => $bid, 's' => $sid]);
        });
        Audit::log($bid, $uid, 'session.close', 'session', $sid, ['total' => $total, 'discount' => $discountCents, 'game' => $bill['game_cents'], 'items' => $bill['items_cents']]);
        return ['total_cents' => $total, 'discount_cents' => $discountCents, 'game_cents' => $bill['game_cents'], 'items_cents' => $bill['items_cents'], 'ticket_id' => (int)$s['ticket_id']];
    }

    /** @return array{0:int,1:int} [indirim, toplam] */
    public static function applyDiscount(array $biz, array $user, int $subtotal, array $discount): array
    {
        $type = $discount['type'] ?? 'none';
        $value = (int)($discount['value'] ?? 0);
        $d = 0;
        if ($type === 'percent') {
            if ($value < 0 || $value > 100) {
                throw new HttpError(422, 'İndirim yüzdesi 0 ile 100 arasında olmalı.', 'validation');
            }
            $d = (int)round($subtotal * $value / 100);
        } elseif ($type === 'amount') {
            if ($value < 0 || $value > $subtotal) {
                throw new HttpError(422, 'İndirim tutarı hesap tutarını geçemez.', 'validation');
            }
            $d = $value;
        } elseif ($type !== 'none') {
            throw new HttpError(422, 'Geçersiz indirim.', 'validation');
        }
        if ($d > 0 && !Perm::can($user, 'discount.apply', $biz)) {
            throw new HttpError(403, 'İndirim uygulama yetkiniz yok. Yöneticinizden destek isteyin.', 'forbidden');
        }
        return [$d, $subtotal - $d];
    }

    /**
     * Ödeme kalemlerini doğrular; toplam hesapla birebir eşleşmeli.
     * Bir kalemin tutarı "rest" olabilir: kalan tutar sunucuda hesaplanır
     * (açık süreli masada kapanış anındaki saniye farkı istemciye bırakılmaz).
     */
    public static function validatePayments(array $payments, int $total): array
    {
        $out = [];
        $sum = 0;
        $restIdx = null;
        if (count($payments) > 5) {
            throw new HttpError(422, 'En fazla 5 ödeme kalemi girilebilir.', 'validation');
        }
        foreach (array_values($payments) as $i => $p) {
            $method = is_array($p) ? ($p['method'] ?? '') : '';
            $amount = is_array($p) ? ($p['amount'] ?? null) : null;
            if (!in_array($method, ['cash', 'card', 'other'], true)) {
                throw new HttpError(422, 'Ödeme bilgisi geçersiz.', 'validation');
            }
            if ($amount === 'rest' && $restIdx === null) {
                $restIdx = $i;
                $out[$i] = ['method' => $method, 'amount' => 0];
                continue;
            }
            if (!is_int($amount) || $amount <= 0 || $amount > 100000000) {
                throw new HttpError(422, 'Ödeme bilgisi geçersiz.', 'validation');
            }
            $sum += $amount;
            $out[$i] = ['method' => $method, 'amount' => $amount];
        }
        if ($restIdx !== null) {
            $rest = $total - $sum;
            if ($rest < 0) {
                throw new HttpError(422, 'Girilen ödemeler hesap tutarını aşıyor.', 'payment_mismatch');
            }
            if ($rest === 0) {
                unset($out[$restIdx]);
            } else {
                $out[$restIdx]['amount'] = $rest;
            }
            $sum += $rest;
        }
        if ($sum !== $total) {
            throw new HttpError(422, 'Ödeme toplamı hesap tutarıyla eşleşmiyor.', 'payment_mismatch');
        }
        return array_values($out);
    }

    public static function cancel(array $biz, int $uid, int $sid, string $reason): void
    {
        $bid = (int)$biz['id'];
        $s = self::session($bid, $sid);
        $now = Db::now();
        Db::tx(static function () use ($bid, $uid, $sid, $s, $now) {
            $n = Db::exec("UPDATE play_sessions SET status = 'cancelled', ended_at = :e, closed_by = :u WHERE id = :id AND business_id = :b AND status = 'active'", ['e' => $now, 'u' => $uid, 'id' => $sid, 'b' => $bid]);
            if ($n !== 1) {
                throw new HttpError(409, 'Oturum iptal edilemedi.', 'conflict');
            }
            Db::exec('UPDATE session_segments SET ended_at = :e WHERE session_id = :s AND ended_at IS NULL', ['e' => $now, 's' => $sid]);
            Products::restockTicket($bid, (int)$s['ticket_id'], $uid, 'void');
            Db::exec("UPDATE tickets SET status = 'void', closed_by = :u, closed_at = :c WHERE id = :id AND business_id = :b", ['u' => $uid, 'c' => $now, 'id' => $s['ticket_id'], 'b' => $bid]);
            Db::exec('UPDATE game_tables SET active_session_id = NULL WHERE id = :t AND business_id = :b AND active_session_id = :s', ['t' => $s['table_id'], 'b' => $bid, 's' => $sid]);
        });
        Audit::log($bid, $uid, 'session.cancel', 'session', $sid, ['reason' => mb_substr($reason, 0, 120)]);
    }

    /** Hatalı kapatmayı geri alır: 30 dk içinde, aynı vardiya açıkken ve masa boşsa. */
    public static function reopen(array $biz, int $uid, int $sid): void
    {
        $bid = (int)$biz['id'];
        $s = self::session($bid, $sid, false);
        if ($s['status'] !== 'closed') {
            throw new HttpError(409, 'Yalnızca kapatılmış hesaplar geri alınabilir.', 'conflict');
        }
        if (time() - strtotime($s['ended_at'] . ' UTC') > 1800) {
            throw new HttpError(409, 'Geri alma süresi (30 dakika) doldu.', 'too_late');
        }
        $ticket = Db::one('SELECT * FROM tickets WHERE id = :id AND business_id = :b', ['id' => $s['ticket_id'], 'b' => $bid]);
        if (!$ticket || $ticket['status'] !== 'paid' || (int)$ticket['shift_id'] !== (int)$biz['open_shift_id']) {
            throw new HttpError(409, 'Bu hesap kapanmış bir vardiyaya ait; geri alınamaz.', 'shift_closed');
        }
        $now = Db::now();
        Db::tx(static function () use ($bid, $sid, $s, $now) {
            $claimed = Db::exec('UPDATE game_tables SET active_session_id = :s WHERE id = :t AND business_id = :b AND active_session_id IS NULL', ['s' => $sid, 't' => $s['table_id'], 'b' => $bid]);
            if ($claimed !== 1) {
                throw new HttpError(409, 'Masa şu an dolu; önce o masayı kapatın.', 'occupied');
            }
            $n = Db::exec("UPDATE play_sessions SET status = 'active', ended_at = NULL, closed_by = NULL WHERE id = :id AND business_id = :b AND status = 'closed'", ['id' => $sid, 'b' => $bid]);
            if ($n !== 1) {
                throw new HttpError(409, 'Geri alınamadı.', 'conflict');
            }
            $last = Db::value('SELECT MAX(id) FROM session_segments WHERE session_id = :s', ['s' => $sid]);
            Db::exec('UPDATE session_segments SET ended_at = NULL WHERE id = :id', ['id' => $last]);
            Db::exec('UPDATE payments SET voided_at = :v WHERE ticket_id = :t AND business_id = :b AND voided_at IS NULL', ['v' => $now, 't' => $s['ticket_id'], 'b' => $bid]);
            Db::exec("UPDATE tickets SET status = 'open', shift_id = NULL, closed_by = NULL, closed_at = NULL, discount_cents = 0, total_cents = 0 WHERE id = :id AND business_id = :b", ['id' => $s['ticket_id'], 'b' => $bid]);
        });
        Audit::log($bid, $uid, 'session.reopen', 'session', $sid);
    }

    /** Panelde gösterilecek oturum özeti */
    public static function summary(array $s, int $now): array
    {
        $bill = self::bill($s, $now);
        return [
            'id' => (int)$s['id'],
            'table_id' => (int)$s['table_id'],
            'ticket_id' => (int)$s['ticket_id'],
            'mode' => $s['mode'],
            'controllers' => $s['controllers'] !== null ? (int)$s['controllers'] : null,
            'planned_minutes' => $s['planned_minutes'] !== null ? (int)$s['planned_minutes'] : null,
            'match_count' => (int)$s['match_count'],
            'started_at' => Db::iso($s['started_at']),
            'elapsed' => $bill['elapsed'],
            'remaining' => $bill['remaining'],
            'over' => $bill['over'],
            'game_cents' => $bill['game_cents'],
            'items_cents' => $bill['items_cents'],
            'subtotal_cents' => $bill['subtotal_cents'],
            'rate_per_hour' => $bill['rate_per_hour'],
            'match_rate' => $s['mode'] === 'match' ? Pricing::matchRate($s['p'], $s['controllers'] !== null ? (int)$s['controllers'] : null) : null,
            'uses_controllers' => (bool)$s['p']['uses_controllers'],
            'note' => $s['note'],
        ];
    }
}
