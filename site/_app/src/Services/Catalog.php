<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

use Dnl\Core\Audit;
use Dnl\Core\Db;
use Dnl\Core\HttpError;

/** Masa türleri (tarifeler) ve masalar. */
final class Catalog
{
    public const KINDS = ['playstation', 'bilardo', 'okey', 'langirt', 'masa-tenisi', 'vr', 'diger'];

    /** Hızlı kurulum şablonları (fiyatlar kuruş; kurulum ekranında düzenlenebilir) */
    public const PRESETS = [
        'playstation' => ['name' => 'PlayStation', 'uses_controllers' => 1, 'hour_price' => 10000, 'hour_price_4' => 15000, 'match_price' => 2500, 'match_price_4' => 4000, 'prefix' => 'PS', 'count' => 6],
        'bilardo' => ['name' => 'Bilardo', 'uses_controllers' => 0, 'hour_price' => 15000, 'hour_price_4' => null, 'match_price' => null, 'match_price_4' => null, 'prefix' => 'Bilardo', 'count' => 2],
        'okey' => ['name' => 'Okey', 'uses_controllers' => 0, 'hour_price' => 6000, 'hour_price_4' => null, 'match_price' => null, 'match_price_4' => null, 'prefix' => 'Okey', 'count' => 4],
        'langirt' => ['name' => 'Langırt', 'uses_controllers' => 0, 'hour_price' => 8000, 'hour_price_4' => null, 'match_price' => 2000, 'match_price_4' => null, 'prefix' => 'Langırt', 'count' => 1],
        'masa-tenisi' => ['name' => 'Masa Tenisi', 'uses_controllers' => 0, 'hour_price' => 8000, 'hour_price_4' => null, 'match_price' => null, 'match_price_4' => null, 'prefix' => 'Masa Tenisi', 'count' => 1],
        'vr' => ['name' => 'VR', 'uses_controllers' => 0, 'hour_price' => 15000, 'hour_price_4' => null, 'match_price' => null, 'match_price_4' => null, 'prefix' => 'VR', 'count' => 2],
        'diger' => ['name' => 'Diğer', 'uses_controllers' => 0, 'hour_price' => 5000, 'hour_price_4' => null, 'match_price' => null, 'match_price_4' => null, 'prefix' => 'Masa', 'count' => 1],
    ];

    public static function typeOut(array $t): array
    {
        $i = static fn($v) => $v !== null ? (int)$v : null;
        return [
            'id' => (int)$t['id'], 'name' => $t['name'], 'kind' => $t['kind'], 'uses_controllers' => (int)$t['uses_controllers'] === 1,
            'hour_price' => (int)$t['hour_price'], 'hour_price_4' => $i($t['hour_price_4']),
            'match_price' => $i($t['match_price']), 'match_price_4' => $i($t['match_price_4']),
            'night_enabled' => (int)$t['night_enabled'] === 1, 'night_start' => $t['night_start'], 'night_end' => $t['night_end'],
            'night_hour_price' => $i($t['night_hour_price']), 'night_hour_price_4' => $i($t['night_hour_price_4']),
            'min_minutes' => (int)$t['min_minutes'], 'sort' => (int)$t['sort'], 'is_active' => (int)$t['is_active'] === 1,
        ];
    }

    public static function types(int $bid): array
    {
        return array_map([self::class, 'typeOut'], Db::all('SELECT * FROM table_types WHERE business_id = :b ORDER BY sort, id', ['b' => $bid]));
    }

    public static function tables(int $bid): array
    {
        $rows = Db::all('SELECT * FROM game_tables WHERE business_id = :b ORDER BY sort, id', ['b' => $bid]);
        return array_map(static fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'table_type_id' => (int)$t['table_type_id'], 'sort' => (int)$t['sort'], 'is_active' => (int)$t['is_active'] === 1, 'busy' => $t['active_session_id'] !== null], $rows);
    }

    public static function type(int $bid, int $id): array
    {
        $t = Db::one('SELECT * FROM table_types WHERE id = :id AND business_id = :b', ['id' => $id, 'b' => $bid]);
        if (!$t) {
            throw new HttpError(404, 'Masa türü bulunamadı.', 'not_found');
        }
        return $t;
    }

    public static function saveType(int $bid, int $uid, ?int $id, array $d): int
    {
        if (!$d['uses_controllers']) {
            $d['hour_price_4'] = null;
            $d['match_price_4'] = null;
            $d['night_hour_price_4'] = null;
        }
        if ($d['night_enabled'] && $d['night_hour_price'] === null) {
            throw new HttpError(422, 'Gece tarifesi için saatlik ücret girin.', 'validation', ['night_hour_price' => 'Gece saatlik ücreti zorunludur.']);
        }
        $row = [
            'name' => $d['name'], 'kind' => $d['kind'], 'uses_controllers' => $d['uses_controllers'] ? 1 : 0,
            'hour_price' => $d['hour_price'], 'hour_price_4' => $d['hour_price_4'], 'match_price' => $d['match_price'], 'match_price_4' => $d['match_price_4'],
            'night_enabled' => $d['night_enabled'] ? 1 : 0, 'night_start' => $d['night_start'], 'night_end' => $d['night_end'],
            'night_hour_price' => $d['night_hour_price'], 'night_hour_price_4' => $d['night_hour_price_4'],
            'min_minutes' => $d['min_minutes'], 'sort' => $d['sort'], 'is_active' => $d['is_active'] ? 1 : 0,
        ];
        if ($id === null) {
            if ((int)Db::value('SELECT COUNT(*) FROM table_types WHERE business_id = :b', ['b' => $bid]) >= 30) {
                throw new HttpError(422, 'En fazla 30 masa türü tanımlanabilir.', 'limit');
            }
            $id = Db::insert('table_types', $row + ['business_id' => $bid, 'created_at' => Db::now()]);
            Audit::log($bid, $uid, 'type.create', 'table_type', $id, ['name' => $d['name']]);
        } else {
            self::type($bid, $id);
            Db::update('table_types', $row, ['id' => $id, 'business_id' => $bid]);
            Audit::log($bid, $uid, 'type.update', 'table_type', $id, ['hour' => $d['hour_price'], 'match' => $d['match_price']]);
        }
        return $id;
    }

    public static function deleteType(int $bid, int $uid, int $id): void
    {
        self::type($bid, $id);
        if ((int)Db::value('SELECT COUNT(*) FROM game_tables WHERE table_type_id = :t AND business_id = :b', ['t' => $id, 'b' => $bid]) > 0) {
            throw new HttpError(409, 'Bu türe bağlı masalar var. Önce masaları başka türe taşıyın ya da silin.', 'in_use');
        }
        Db::exec('DELETE FROM table_types WHERE id = :id AND business_id = :b', ['id' => $id, 'b' => $bid]);
        Audit::log($bid, $uid, 'type.delete', 'table_type', $id);
    }

    private static function assertTableLimit(array $biz, int $adding): void
    {
        $max = Subscription::limit($biz, 'max_tables');
        $count = (int)Db::value('SELECT COUNT(*) FROM game_tables WHERE business_id = :b', ['b' => $biz['id']]);
        if ($count + $adding > $max) {
            throw new HttpError(422, "Paketinizde en fazla $max masa tanımlanabilir. Daha fazlası için paketinizi yükseltin.", 'plan_limit');
        }
    }

    public static function addTables(array $biz, int $uid, int $typeId, string $prefix, int $count): array
    {
        $bid = (int)$biz['id'];
        self::type($bid, $typeId);
        self::assertTableLimit($biz, $count);
        $start = (int)Db::value('SELECT COUNT(*) FROM game_tables WHERE business_id = :b AND table_type_id = :t', ['b' => $bid, 't' => $typeId]);
        $sort = (int)Db::value('SELECT COALESCE(MAX(sort), 0) FROM game_tables WHERE business_id = :b', ['b' => $bid]);
        $ids = [];
        Db::tx(static function () use ($bid, $typeId, $prefix, $count, $start, $sort, &$ids) {
            for ($i = 1; $i <= $count; $i++) {
                $ids[] = Db::insert('game_tables', ['business_id' => $bid, 'table_type_id' => $typeId, 'name' => mb_substr($prefix . ' ' . ($start + $i), 0, 40), 'sort' => $sort + $i, 'is_active' => 1, 'active_session_id' => null, 'created_at' => Db::now()]);
            }
        });
        Audit::log($bid, $uid, 'table.create', 'table', null, ['type' => $typeId, 'count' => $count]);
        return $ids;
    }

    public static function updateTable(int $bid, int $uid, int $id, string $name, int $typeId, int $sort, bool $active): void
    {
        $t = Db::one('SELECT * FROM game_tables WHERE id = :id AND business_id = :b', ['id' => $id, 'b' => $bid]);
        if (!$t) {
            throw new HttpError(404, 'Masa bulunamadı.', 'not_found');
        }
        self::type($bid, $typeId);
        if ($t['active_session_id'] !== null && ((int)$t['table_type_id'] !== $typeId || !$active)) {
            throw new HttpError(409, 'Masa doluyken türü değiştirilemez ya da pasife alınamaz.', 'occupied');
        }
        Db::exec('UPDATE game_tables SET name = :n, table_type_id = :t, sort = :s, is_active = :a WHERE id = :id AND business_id = :b', ['n' => $name, 't' => $typeId, 's' => $sort, 'a' => $active ? 1 : 0, 'id' => $id, 'b' => $bid]);
        Audit::log($bid, $uid, 'table.update', 'table', $id, ['name' => $name]);
    }

    public static function deleteTable(int $bid, int $uid, int $id): void
    {
        $t = Db::one('SELECT * FROM game_tables WHERE id = :id AND business_id = :b', ['id' => $id, 'b' => $bid]);
        if (!$t) {
            throw new HttpError(404, 'Masa bulunamadı.', 'not_found');
        }
        if ($t['active_session_id'] !== null) {
            throw new HttpError(409, 'Dolu masa silinemez.', 'occupied');
        }
        // Geçmiş oturum varsa raporlar bozulmasın: pasife al
        $used = (int)Db::value('SELECT COUNT(*) FROM play_sessions WHERE table_id = :t AND business_id = :b', ['t' => $id, 'b' => $bid]);
        if ($used > 0) {
            Db::exec('UPDATE game_tables SET is_active = 0 WHERE id = :id AND business_id = :b AND active_session_id IS NULL', ['id' => $id, 'b' => $bid]);
        } else {
            Db::exec('DELETE FROM game_tables WHERE id = :id AND business_id = :b AND active_session_id IS NULL', ['id' => $id, 'b' => $bid]);
        }
        Audit::log($bid, $uid, 'table.delete', 'table', $id, ['archived' => $used > 0]);
    }

    /**
     * İlk kurulum: seçilen türler için tarife + masalar oluşturur.
     * @param array<int,array{kind:string,count:int,hour_price:int,hour_price_4:?int,match_price:?int}> $items
     */
    public static function quickSetup(array $biz, int $uid, array $items): void
    {
        $bid = (int)$biz['id'];
        if ((int)Db::value('SELECT COUNT(*) FROM table_types WHERE business_id = :b', ['b' => $bid]) > 0) {
            throw new HttpError(409, 'Kurulum zaten yapılmış. Masaları Ayarlar bölümünden düzenleyebilirsiniz.', 'already_setup');
        }
        $total = array_sum(array_column($items, 'count'));
        self::assertTableLimit($biz, $total);
        Db::tx(static function () use ($biz, $bid, $uid, $items) {
            // Eşzamanlı iki kurulum isteğine karşı kilit
            if (Db::exec('UPDATE businesses SET setup_done = 1, updated_at = :n WHERE id = :b AND setup_done = 0', ['n' => Db::now(), 'b' => $bid]) !== 1) {
                throw new HttpError(409, 'Kurulum zaten yapılmış.', 'already_setup');
            }
            $sort = 0;
            foreach ($items as $it) {
                $pre = self::PRESETS[$it['kind']];
                $sort++;
                $typeId = Db::insert('table_types', [
                    'business_id' => $bid, 'name' => $pre['name'], 'kind' => $it['kind'], 'uses_controllers' => $pre['uses_controllers'],
                    'hour_price' => $it['hour_price'], 'hour_price_4' => $pre['uses_controllers'] ? $it['hour_price_4'] : null,
                    'match_price' => $it['match_price'], 'match_price_4' => null,
                    'night_enabled' => 0, 'night_start' => '00:00', 'night_end' => '06:00', 'night_hour_price' => null, 'night_hour_price_4' => null,
                    'min_minutes' => 0, 'sort' => $sort, 'is_active' => 1, 'created_at' => Db::now(),
                ]);
                if ($it['count'] > 0) {
                    self::addTables($biz, $uid, $typeId, $pre['prefix'], $it['count']);
                }
            }
        });
        Audit::log($bid, $uid, 'setup.quick', 'business', $bid, ['types' => array_column($items, 'kind')]);
    }
}
