<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Http;
use Dnl\Core\Input;
use Dnl\Services\Customers;
use Dnl\Services\Perm;

/** Müşteriler ve veresiye (cari) */
final class CustomerController
{
    public static function index(): array
    {
        [, $b] = Perm::need('customers.use');
        $list = Customers::list((int)$b['id']);
        return ['customers' => $list, 'total_debt_cents' => array_sum(array_map(static fn($c) => max(0, $c['balance_cents']), $list))];
    }

    public static function show(array $p): array
    {
        [, $b] = Perm::need('customers.use');
        return ['customer' => Customers::out(Customers::get((int)$b['id'], $p['id'])), 'moves' => Customers::moves((int)$b['id'], $p['id'])];
    }

    private static function input(bool $manage): array
    {
        $in = new Input(Http::json());
        $d = [
            'name' => $in->str('name', 2, 80, true, 'Ad soyad'),
            'phone' => $in->phone('phone', false),
            'note' => $in->str('note', 0, 200, false, 'Not'),
            'credit_limit_cents' => $manage ? $in->money('credit_limit', false, 100000000, 'Veresiye limiti') : null,
            'is_active' => $manage ? $in->bool('is_active', true) : true,
        ];
        $in->check();
        return $d;
    }

    public static function create(): array
    {
        [$u, $b] = Perm::need('customers.use');
        $id = Customers::save((int)$b['id'], (int)$u['id'], null, self::input(Perm::can($u, 'customers.manage', $b)));
        return ['id' => $id] + self::index();
    }

    public static function update(array $p): array
    {
        [$u, $b] = Perm::need('customers.manage');
        Customers::save((int)$b['id'], (int)$u['id'], $p['id'], self::input(true));
        return self::show($p);
    }

    public static function collect(array $p): array
    {
        [$u, $b] = Perm::need('customers.use');
        $in = new Input(Http::json());
        $amount = (int)$in->money('amount', true, 100000000, 'Tutar');
        $method = $in->enum('method', ['cash', 'card', 'other'], 'cash', 'Ödeme yöntemi');
        $note = $in->str('note', 0, 200, false, 'Not');
        if ($amount <= 0) {
            $in->error('amount', 'Tutar sıfırdan büyük olmalı.');
        }
        $in->check();
        Customers::collect($b, (int)$u['id'], $p['id'], $amount, $method, $note);
        return self::show($p);
    }
}
