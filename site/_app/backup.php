<?php
/**
 * DNL Station — günlük veritabanı yedeği (yalnızca komut satırı).
 * Yedekler public_html DIŞINA, ~/dnlstation-backups klasörüne yazılır (dosya izni 0600, web'den erişilemez).
 * Son 14 yedek tutulur, eskileri silinir.
 * cPanel → Cron Jobs örneği (her gün 05:00):  php /home/KULLANICI/public_html/_app/backup.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

use Dnl\Core\Config;
use Dnl\Core\Db;

const KEEP = 14;
const CHUNK = 500;

if (!Config::ready()) {
    fwrite(STDERR, "HATA: Ayar dosyası hazır değil.\n");
    exit(1);
}

$dir = dirname(__DIR__, 2) . '/dnlstation-backups';
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    fwrite(STDERR, "HATA: Yedek klasörü oluşturulamadı: $dir\n");
    exit(1);
}
@chmod($dir, 0700);

$pdo = Db::pdo();
$isSqlite = Db::driver() === 'sqlite';
$file = $dir . '/dnl-' . gmdate('Ymd-His') . '.sql.gz';
$gz = gzopen($file, 'wb9');
if ($gz === false) {
    fwrite(STDERR, "HATA: Yedek dosyası yazılamadı.\n");
    exit(1);
}

try {
    gzwrite($gz, "-- DNL Station yedeği " . gmdate('Y-m-d H:i:s') . " UTC\nSET FOREIGN_KEY_CHECKS=0;\n");
    $tables = [];
    if ($isSqlite) {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    }
    $total = 0;
    foreach ($tables as $t) {
        $t = (string)$t;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) {
            continue; // güvenlik: yalnızca basit tablo adları
        }
        if ($isSqlite) {
            $create = $pdo->query("SELECT sql FROM sqlite_master WHERE name = " . $pdo->quote($t))->fetchColumn();
        } else {
            $row = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
            $create = $row[1];
        }
        gzwrite($gz, "\nDROP TABLE IF EXISTS `$t`;\n" . $create . ";\n");
        $offset = 0;
        while (true) {
            $rows = $pdo->query("SELECT * FROM `$t` LIMIT " . CHUNK . " OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            $cols = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $vals = [];
            foreach ($rows as $r) {
                $vals[] = '(' . implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
            }
            gzwrite($gz, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $vals) . ";\n");
            $total += count($rows);
            $offset += CHUNK;
        }
    }
    gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
} catch (Throwable $e) {
    gzclose($gz);
    @unlink($file);
    fwrite(STDERR, "HATA: Yedek alınamadı: " . $e->getMessage() . "\n");
    exit(1);
}
@chmod($file, 0600);

// Eski yedekleri temizle (en yeni KEEP adet kalır)
$all = glob($dir . '/dnl-*.sql.gz') ?: [];
rsort($all);
foreach (array_slice($all, KEEP) as $old) {
    @unlink($old);
}

fwrite(STDOUT, sprintf("Yedek tamam: %s (%d tablo, %d satır, %d yedek tutuluyor)\n", basename($file), count($tables), $total, min(count($all), KEEP)));
