<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Http;
use Dnl\Core\Input;
use Dnl\Services\Perm;
use Dnl\Services\Shifts;

/** Vardiya / kasa */
final class ShiftController
{
    public static function current(): array
    {
        [$u, $b] = Perm::need('shift.operate');
        $cur = Shifts::current((int)$b['id']);
        // Personel beklenen nakdi görmez (kör sayım); yöneticiler görür
        if ($cur !== null && !Perm::can($u, 'reports.view', $b)) {
            $cur = ['id' => $cur['id'], 'opened_at' => $cur['opened_at'], 'opened_by' => $cur['opened_by'], 'tickets' => $cur['tickets']];
        }
        return ['shift' => $cur, 'can_see_totals' => Perm::can($u, 'reports.view', $b)];
    }

    public static function open(): array
    {
        [$u, $b] = Perm::need('shift.operate');
        $in = new Input(Http::json());
        $cash = (int)$in->money('opening_cash', false, 100000000, 'Açılış kasası');
        $in->check();
        Shifts::open($b, (int)$u['id'], $cash);
        return self::current();
    }

    public static function close(): array
    {
        [$u, $b] = Perm::need('shift.operate');
        $in = new Input(Http::json());
        $counted = (int)$in->money('counted_cash', true, 100000000, 'Sayılan nakit');
        $note = $in->str('note', 0, 200, false, 'Not');
        $in->check();
        $z = Shifts::close($b, (int)$u['id'], $counted, $note);
        return ['report' => $z];
    }

    public static function index(): array
    {
        [, $b] = Perm::need('reports.view');
        return ['shifts' => Shifts::list((int)$b['id'], 60)];
    }

    public static function show(array $p): array
    {
        [, $b] = Perm::need('reports.view');
        return ['shift' => Shifts::get((int)$b['id'], $p['id'])];
    }
}
