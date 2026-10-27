<?php
declare(strict_types=1);

namespace Dnl\Services;

use Dnl\Core\Db;
use Dnl\Core\HttpError;

defined('DNL_APP') || exit;

/**
 * Veritabanı yedeği: gzip'li SQL, public_html DIŞINDA (…/dnlstation-backups), izin 0600.
 * Yalnızca alma, listeleme ve silme. Geri yükleme bilerek yok (ayrı ve onaylı bir adım olmalı).
 */
final class Backup
{
    public const KEEP = 14;
    private const CHUNK = 500;
    private const NAME = '/^dnl-\d{8}-\d{6}\.sql\.gz$/';

    public static function dir(): string
    {
        return dirname(DNL_ROOT, 2) . '/dnlstation-backups';
    }

    /** Yedek alır, eski yedekleri budar. Hata olursa HttpError fırlatır. */
    public static function run(): array
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new HttpError(500, 'Yedek klasörü oluşturulamadı.', 'backup_dir');
        }
        @chmod($dir, 0700);
        $pdo = Db::pdo();
        $isSqlite = Db::driver() === 'sqlite';
        $name = 'dnl-' . gmdate('Ymd-His') . '.sql.gz';
        $file = $dir . '/' . $name;
        $gz = gzopen($file, 'wb9');
        if ($gz === false) {
            throw new HttpError(500, 'Yedek dosyası yazılamadı.', 'backup_write');
        }
        $tables = 0;
        $rows = 0;
        try {
            gzwrite($gz, "-- DNL Station yedeği " . gmdate('Y-m-d H:i:s') . " UTC\nSET FOREIGN_KEY_CHECKS=0;\n");
            $names = $isSqlite
                ? $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN)
                : $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($names as $t) {
                $t = (string)$t;
                if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                    continue;
                }
                $tables++;
                $create = $isSqlite
                    ? $pdo->query('SELECT sql FROM sqlite_master WHERE name = ' . $pdo->quote($t))->fetchColumn()
                    : ($pdo->query("SHOW CREATE TABLE `$t`")->fetch(\PDO::FETCH_NUM)[1] ?? '');
                gzwrite($gz, "\nDROP TABLE IF EXISTS `$t`;\n" . $create . ";\n");
                $offset = 0;
                while (true) {
                    $chunk = $pdo->query("SELECT * FROM `$t` LIMIT " . self::CHUNK . " OFFSET $offset")->fetchAll(\PDO::FETCH_ASSOC);
                    if (!$chunk) {
                        break;
                    }
                    $cols = '`' . implode('`,`', array_keys($chunk[0])) . '`';
                    $vals = [];
                    foreach ($chunk as $r) {
                        $vals[] = '(' . implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
                    }
                    gzwrite($gz, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $vals) . ";\n");
                    $rows += count($chunk);
                    $offset += self::CHUNK;
                }
            }
            gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
            gzclose($gz);
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($file);
            \Dnl\Core\Log::error($e, 'backup');
            throw new HttpError(500, 'Yedek alınamadı. Ayrıntı hata kaydında.', 'backup_failed');
        }
        @chmod($file, 0600);
        self::prune();
        return ['name' => $name, 'tables' => $tables, 'rows' => $rows, 'kept' => min(count(self::files()), self::KEEP)];
    }

    /** Mevcut yedekler, en yeniden eskiye */
    public static function list(): array
    {
        return array_map(static function (string $f): array {
            return ['name' => basename($f), 'size' => (int)@filesize($f), 'at' => gmdate('c', (int)@filemtime($f))];
        }, self::files());
    }

    /** Yedeği siler. Ad, yalnızca bizim biçimimize uyuyorsa ve klasör içindeyse silinir. */
    public static function delete(string $name): void
    {
        if (!preg_match(self::NAME, $name)) {
            throw new HttpError(422, 'Geçersiz yedek adı.', 'invalid_name');
        }
        $path = self::dir() . '/' . $name;
        if (!is_file($path)) {
            throw new HttpError(404, 'Yedek bulunamadı.', 'not_found');
        }
        if (!@unlink($path)) {
            throw new HttpError(500, 'Yedek silinemedi.', 'delete_failed');
        }
    }

    private static function files(): array
    {
        $all = glob(self::dir() . '/dnl-*.sql.gz') ?: [];
        rsort($all);
        return $all;
    }

    private static function prune(): void
    {
        foreach (array_slice(self::files(), self::KEEP) as $old) {
            @unlink($old);
        }
    }
}
