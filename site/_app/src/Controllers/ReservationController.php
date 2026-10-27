<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Http;
use Dnl\Core\HttpError;
use Dnl\Core\Input;
use Dnl\Services\Perm;
use Dnl\Services\Reservations;

/** Rezervasyonlar */
final class ReservationController
{
    public static function index(): array
    {
        [, $b] = Perm::need('reservations.use');
        $tz = new \DateTimeZone((string)$b['timezone']);
        $date = (string)(Http::query('date') ?? (new \DateTimeImmutable('now', $tz))->format('Y-m-d'));
        $days = max(1, min(31, (int)(Http::query('days') ?? 1)));
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new HttpError(422, 'Tarih geçersiz.', 'validation');
        }
        $utc = new \DateTimeZone('UTC');
        return ['date' => $date, 'items' => Reservations::list((int)$b['id'], $d->setTimezone($utc)->format('Y-m-d H:i:s'), $d->modify("+$days day")->setTimezone($utc)->format('Y-m-d H:i:s'))];
    }

    private static function input(array $b): array
    {
        $data = Http::json();
        $in = new Input($data);
        $d = [
            'customer_name' => $in->str('customer_name', 2, 80, true, 'Ad soyad'),
            'phone' => $in->phone('phone', false),
            'table_id' => $in->intOrNull('table_id', 1, PHP_INT_MAX, 'Masa'),
            'duration_minutes' => (int)$in->int('duration_minutes', 15, 720, 60, 'Süre'),
            'people' => $in->intOrNull('people', 1, 100, 'Kişi'),
            'note' => $in->str('note', 0, 200, false, 'Not'),
        ];
        $date = (string)($data['date'] ?? '');
        $time = $in->time('time', '00:00');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $in->error('date', 'Tarih seçin.');
        }
        $in->check();
        $d['starts_at'] = Reservations::toUtc($date, $time, (string)$b['timezone']);
        return $d;
    }

    public static function create(): array
    {
        [$u, $b] = Perm::need('reservations.use');
        $id = Reservations::save((int)$b['id'], (int)$u['id'], null, self::input($b));
        return ['id' => $id];
    }

    public static function update(array $p): array
    {
        [$u, $b] = Perm::need('reservations.use');
        Reservations::save((int)$b['id'], (int)$u['id'], $p['id'], self::input($b));
        return ['id' => $p['id']];
    }

    public static function status(array $p): array
    {
        [$u, $b] = Perm::need('reservations.use');
        $in = new Input(Http::json());
        $st = $in->enum('status', Reservations::STATUSES, null, 'Durum');
        $in->check();
        Reservations::setStatus((int)$b['id'], (int)$u['id'], $p['id'], $st);
        return ['ok' => true];
    }
}
