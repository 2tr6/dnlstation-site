<?php
/**
 * DNL Station — veritabanı yedeği (yalnızca komut satırı).
 * Yedekler public_html DIŞINA, ~/dnlstation-backups klasörüne yazılır; en yeni 14 yedek tutulur.
 * cPanel → Cron Jobs örneği (her hafta Pazar 05:00):  php /home/KULLANICI/public_html/_app/backup.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

use Dnl\Core\Config;
use Dnl\Core\HttpError;
use Dnl\Services\Backup;

if (!Config::ready()) {
    fwrite(STDERR, "HATA: Ayar dosyası hazır değil.\n");
    exit(1);
}
try {
    $r = Backup::run();
} catch (HttpError $e) {
    fwrite(STDERR, "HATA: " . $e->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, sprintf("Yedek tamam: %s (%d tablo, %d satır, %d yedek tutuluyor)\n", $r['name'], $r['tables'], $r['rows'], $r['kept']));
