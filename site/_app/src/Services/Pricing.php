<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

/**
 * Oyun ücreti hesaplama. Tüm hesaplar sunucuda yapılır; istemci yalnızca gösterir.
 *  - open : geçen süre × saatlik ücret (kol sayısı değişimleri ayrı dilimler, gece tarifesi ayrı)
 *  - fixed: planlanan süre peşin; süre aşılırsa aşan kısım da eklenir
 *  - match: maç sayısı × maç ücreti
 * Fiyatlar oturum açılırken "pricing" anlık görüntüsü olarak saklanır; sonradan tarife değişse de açık oturum etkilenmez.
 */
final class Pricing
{
    public static function snapshot(array $type, string $tz): array
    {
        return [
            'uses_controllers' => (int)$type['uses_controllers'] === 1,
            'hour' => (int)$type['hour_price'],
            'hour4' => $type['hour_price_4'] !== null ? (int)$type['hour_price_4'] : null,
            'match' => $type['match_price'] !== null ? (int)$type['match_price'] : null,
            'match4' => $type['match_price_4'] !== null ? (int)$type['match_price_4'] : null,
            'night' => (int)$type['night_enabled'] === 1,
            'night_start' => (string)$type['night_start'],
            'night_end' => (string)$type['night_end'],
            'night_hour' => $type['night_hour_price'] !== null ? (int)$type['night_hour_price'] : null,
            'night_hour4' => $type['night_hour_price_4'] !== null ? (int)$type['night_hour_price_4'] : null,
            'min_minutes' => (int)$type['min_minutes'],
            'tz' => $tz,
        ];
    }

    /** Saatlik ücret (kuruş) — kol sayısı ve gece durumuna göre */
    public static function hourRate(array $p, ?int $controllers, bool $night): int
    {
        $four = $p['uses_controllers'] && $controllers === 4;
        if ($night && $p['night']) {
            $n = $four ? ($p['night_hour4'] ?? $p['night_hour']) : $p['night_hour'];
            if ($n !== null) {
                return (int)$n;
            }
        }
        return (int)($four && $p['hour4'] !== null ? $p['hour4'] : $p['hour']);
    }

    public static function matchRate(array $p, ?int $controllers): int
    {
        $four = $p['uses_controllers'] && $controllers === 4;
        return (int)($four && $p['match4'] !== null ? $p['match4'] : ($p['match'] ?? 0));
    }

    public static function isNight(array $p, int $ts): bool
    {
        if (!$p['night']) {
            return false;
        }
        $local = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone($p['tz']))->format('H:i');
        $s = $p['night_start'];
        $e = $p['night_end'];
        if ($s === $e) {
            return false;
        }
        return $s < $e ? ($local >= $s && $local < $e) : ($local >= $s || $local < $e);
    }

    /** $ts'den sonraki ilk gece başlangıcı/bitişi anı */
    private static function nextBoundary(array $p, int $ts): int
    {
        $tz = new \DateTimeZone($p['tz']);
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $best = PHP_INT_MAX;
        foreach ([$p['night_start'], $p['night_end']] as $hm) {
            [$h, $m] = array_map('intval', explode(':', $hm));
            for ($add = 0; $add <= 1; $add++) {
                $c = $d->setTime($h, $m, 0)->modify("+$add day")->getTimestamp();
                if ($c > $ts && $c < $best) {
                    $best = $c;
                }
            }
        }
        return $best;
    }

    /** Bir zaman aralığının ücreti (kesirli kuruş) */
    private static function rangeCost(array $p, ?int $controllers, int $from, int $to): float
    {
        $cost = 0.0;
        $t = $from;
        $guard = 0;
        while ($t < $to && $guard++ < 1000) {
            $night = self::isNight($p, $t);
            $next = $p['night'] ? min($to, self::nextBoundary($p, $t)) : $to;
            $cost += ($next - $t) * self::hourRate($p, $controllers, $night) / 3600;
            $t = $next;
        }
        return $cost;
    }

    /**
     * @param array $s play_sessions satırı (pricing JSON çözülmüş olarak $s['p'])
     * @param array $segments session_segments satırları
     * @return array{game_cents:int, elapsed:int, remaining:?int, rate_per_hour:int, over:bool}
     */
    public static function compute(array $s, array $segments, int $now): array
    {
        $p = $s['p'];
        $start = strtotime($s['started_at'] . ' UTC');
        $end = $s['ended_at'] !== null ? strtotime($s['ended_at'] . ' UTC') : $now;
        $elapsed = max(0, $end - $start);
        $controllers = $s['controllers'] !== null ? (int)$s['controllers'] : null;
        $running = $s['ended_at'] === null;

        if ($s['mode'] === 'match') {
            return ['game_cents' => self::matchRate($p, $controllers) * (int)$s['match_count'], 'elapsed' => $elapsed, 'remaining' => null, 'rate_per_hour' => 0, 'over' => false];
        }

        $cost = 0.0;
        $lastCtrl = $controllers; // oturumdaki güncel kol sayısı
        foreach ($segments as $seg) {
            $a = strtotime($seg['started_at'] . ' UTC');
            $b = $seg['ended_at'] !== null ? strtotime($seg['ended_at'] . ' UTC') : $end;
            $b = min($b, $end);
            if ($b > $a) {
                $c = $seg['controllers'] !== null ? (int)$seg['controllers'] : null;
                $cost += self::rangeCost($p, $c, $a, $b);
            }
        }
        $rateNow = self::hourRate($p, $lastCtrl, self::isNight($p, $end));

        if ($s['mode'] === 'fixed') {
            $planned = (int)$s['planned_minutes'] * 60;
            $remaining = $planned - $elapsed;
            if ($remaining > 0) {
                // Kalan planlı süre de peşin ücretlendirilir (o anki tarifeden)
                $cost += $remaining * $rateNow / 3600;
                return ['game_cents' => (int)round($cost), 'elapsed' => $elapsed, 'remaining' => $remaining, 'rate_per_hour' => 0, 'over' => false];
            }
            return ['game_cents' => (int)round($cost), 'elapsed' => $elapsed, 'remaining' => $remaining, 'rate_per_hour' => $running ? $rateNow : 0, 'over' => true];
        }

        // open
        $minSec = (int)$p['min_minutes'] * 60;
        if ($minSec > 0 && $elapsed < $minSec) {
            $cost += ($minSec - $elapsed) * $rateNow / 3600;
            return ['game_cents' => (int)round($cost), 'elapsed' => $elapsed, 'remaining' => null, 'rate_per_hour' => 0, 'over' => false];
        }
        return ['game_cents' => (int)round($cost), 'elapsed' => $elapsed, 'remaining' => null, 'rate_per_hour' => $running ? $rateNow : 0, 'over' => false];
    }
}
