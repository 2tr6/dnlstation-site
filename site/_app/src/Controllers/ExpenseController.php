<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Http;
use Dnl\Core\HttpError;
use Dnl\Core\Input;
use Dnl\Services\Expenses;
use Dnl\Services\Perm;

/** Giderler */
final class ExpenseController
{
    private static function today(array $b): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone((string)$b['timezone'])))->format('Y-m-d');
    }

    public static function index(): array
    {
        [, $b] = Perm::need('expenses.manage');
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $to = (string)(Http::query('to') ?? self::today($b));
        $from = (string)(Http::query('from') ?? substr($to, 0, 8) . '01');
        if (!preg_match($re, $from) || !preg_match($re, $to) || $from > $to) {
            throw new HttpError(422, 'Tarih aralığı geçersiz.', 'validation');
        }
        $items = Expenses::list((int)$b['id'], $from, $to);
        $by = [];
        foreach ($items as $e) {
            if (!$e['voided']) {
                $by[$e['category']] = ($by[$e['category']] ?? 0) + $e['amount_cents'];
            }
        }
        arsort($by);
        return ['from' => $from, 'to' => $to, 'items' => $items, 'total_cents' => array_sum($by),
            'by_category' => array_map(static fn($k, $v) => ['category' => $k, 'total_cents' => $v], array_keys($by), $by), 'categories' => Expenses::CATEGORIES];
    }

    public static function create(): array
    {
        [$u, $b] = Perm::need('expenses.manage');
        $in = new Input(Http::json());
        $cat = $in->str('category', 2, 40, true, 'Kategori');
        $amount = (int)$in->money('amount', true, 100000000, 'Tutar');
        $method = $in->enum('method', ['cash', 'card', 'other'], 'cash', 'Ödeme şekli');
        $note = $in->str('note', 0, 200, false, 'Not');
        $date = (string)(Http::json()['spent_on'] ?? self::today($b));
        if ($amount <= 0) {
            $in->error('amount', 'Tutar sıfırdan büyük olmalı.');
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date > self::today($b)) {
            $in->error('spent_on', 'Tarih geçersiz.');
        }
        $in->check();
        Expenses::add($b, (int)$u['id'], $cat, $amount, $method, $note, $date);
        return self::index();
    }

    public static function void(array $p): array
    {
        [$u, $b] = Perm::need('expenses.manage');
        Expenses::void($b, (int)$u['id'], $p['id']);
        return self::index();
    }
}
