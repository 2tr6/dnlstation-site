<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Http;
use Dnl\Core\Input;
use Dnl\Services\Catalog;
use Dnl\Services\Perm;
use Dnl\Services\Subscription;

/** Ayarlar > Masalar ve tarifeler */
final class CatalogController
{
    public static function index(): array
    {
        [, $b] = Perm::need('settings.tables');
        return ['types' => Catalog::types((int)$b['id']), 'tables' => Catalog::tables((int)$b['id']), 'max_tables' => Subscription::limit($b, 'max_tables'), 'kinds' => Catalog::KINDS];
    }

    private static function typeInput(): array
    {
        $in = new Input(Http::json());
        $d = [
            'name' => $in->str('name', 2, 40, true, 'Tür adı'),
            'kind' => $in->enum('kind', Catalog::KINDS, 'diger', 'Tür'),
            'uses_controllers' => $in->bool('uses_controllers'),
            'hour_price' => (int)$in->money('hour_price', true, 10000000, 'Saatlik ücret'),
            'hour_price_4' => $in->money('hour_price_4', false, 10000000, '4 kol saatlik ücret'),
            'match_price' => $in->money('match_price', false, 10000000, 'Maç ücreti'),
            'match_price_4' => $in->money('match_price_4', false, 10000000, '4 kol maç ücreti'),
            'night_enabled' => $in->bool('night_enabled'),
            'night_start' => $in->time('night_start', '00:00'),
            'night_end' => $in->time('night_end', '06:00'),
            'night_hour_price' => $in->money('night_hour_price', false, 10000000, 'Gece ücreti'),
            'night_hour_price_4' => $in->money('night_hour_price_4', false, 10000000, 'Gece 4 kol ücreti'),
            'min_minutes' => (int)$in->int('min_minutes', 0, 240, 0, 'Minimum süre'),
            'sort' => (int)$in->int('sort', 0, 9999, 0, 'Sıra'),
            'is_active' => $in->bool('is_active', true),
        ];
        if ($d['night_enabled'] && $d['night_start'] === $d['night_end']) {
            $in->error('night_end', 'Gece başlangıç ve bitiş saati aynı olamaz.');
        }
        $in->check();
        return $d;
    }

    public static function createType(): array
    {
        [$u, $b] = Perm::need('settings.tables');
        $id = Catalog::saveType((int)$b['id'], (int)$u['id'], null, self::typeInput());
        return ['id' => $id] + self::index();
    }

    public static function updateType(array $p): array
    {
        [$u, $b] = Perm::need('settings.tables');
        Catalog::saveType((int)$b['id'], (int)$u['id'], $p['id'], self::typeInput());
        return self::index();
    }

    public static function deleteType(array $p): array
    {
        [$u, $b] = Perm::need('settings.tables');
        Catalog::deleteType((int)$b['id'], (int)$u['id'], $p['id']);
        return self::index();
    }

    public static function createTables(): array
    {
        [$u, $b] = Perm::need('settings.tables');
        $in = new Input(Http::json());
        $type = $in->int('table_type_id', 1, PHP_INT_MAX, null, 'Masa türü');
        $prefix = $in->str('prefix', 1, 30, true, 'Masa adı');
        $count = $in->int('count', 1, 50, 1, 'Adet');
        $in->check();
        Catalog::addTables($b, (int)$u['id'], (int)$type, $prefix, (int)$count);
        return self::index();
    }

    public static function updateTable(array $p): array
    {
        [$u, $b] = Perm::need('settings.tables');
        $in = new Input(Http::json());
        $name = $in->str('name', 1, 40, true, 'Masa adı');
        $type = $in->int('table_type_id', 1, PHP_INT_MAX, null, 'Masa türü');
        $sort = $in->int('sort', 0, 9999, 0, 'Sıra');
        $active = $in->bool('is_active', true);
        $in->check();
        Catalog::updateTable((int)$b['id'], (int)$u['id'], $p['id'], $name, (int)$type, (int)$sort, $active);
        return self::index();
    }

    public static function deleteTable(array $p): array
    {
        [$u, $b] = Perm::need('settings.tables');
        Catalog::deleteTable((int)$b['id'], (int)$u['id'], $p['id']);
        return self::index();
    }
}
