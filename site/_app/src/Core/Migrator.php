<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/** migrations/<driver>/NNN_ad.sql dosyalarını sırayla ve bir kez çalıştırır. */
final class Migrator
{
    /** @return string[] uygulanan sürümler */
    public static function run(): array
    {
        $driver = Db::driver() === 'sqlite' ? 'sqlite' : 'mysql';
        if ($driver === 'sqlite') {
            Db::pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        } else {
            Db::pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        $done = array_column(Db::all('SELECT version FROM schema_migrations'), 'version');
        $files = glob(DNL_ROOT . '/migrations/' . $driver . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $done, true)) {
                continue;
            }
            $sql = (string)file_get_contents($file);
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
            foreach (preg_split('/;\s*(\r?\n|$)/', $sql) ?: [] as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '') {
                    Db::pdo()->exec($stmt);
                }
            }
            Db::insert('schema_migrations', ['version' => $version, 'applied_at' => Db::now()]);
            $applied[] = $version;
        }
        return $applied;
    }

    public static function pending(): int
    {
        try {
            $done = array_column(Db::all('SELECT version FROM schema_migrations'), 'version');
        } catch (\PDOException) {
            return 1;
        }
        $driver = Db::driver() === 'sqlite' ? 'sqlite' : 'mysql';
        $files = array_map(static fn($f) => basename($f, '.sql'), glob(DNL_ROOT . '/migrations/' . $driver . '/*.sql') ?: []);
        return count(array_diff($files, $done));
    }
}
