<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Config;
use Dnl\Core\Http;
use Dnl\Core\HttpError;
use Dnl\Core\Migrator;
use Dnl\Core\RateLimiter;

final class SystemController
{
    /** Yayın sonrası şema güncellemesi. Yalnızca gizli anahtarla (X-Migrate-Token) çalışır. */
    public static function migrate(): array
    {
        $expected = (string)Config::get('migrate_token', '');
        $given = (string)(Http::header('X-Migrate-Token') ?? '');
        if (strlen($expected) < 32 || !hash_equals($expected, $given)) {
            // Tahmin denemelerini sınırla (tablo henüz yoksa ilk kurulumda sınır atlanır)
            try {
                RateLimiter::enforce('migrate:' . Http::ip(), 10, 3600);
            } catch (\PDOException) {
            }
            \Dnl\Core\SecurityLog::add('migrate_denied');
            throw new HttpError(403, 'Yetkisiz.', 'forbidden');
        }
        return ['applied' => Migrator::run()];
    }

    public static function health(): array
    {
        return ['status' => 'ok', 'pending_migrations' => Migrator::pending()];
    }
}
