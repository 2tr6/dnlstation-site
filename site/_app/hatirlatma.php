<?php
/**
 * DNL Station — deneme süresi hatırlatmaları (yalnızca komut satırı, cron ile günde bir kez).
 * cPanel → Cron Jobs örneği (her gün 09:00):  php /home/KULLANICI/public_html/_app/hatirlatma.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

use Dnl\Core\Config;
use Dnl\Services\TrialReminder;

if (!Config::ready()) {
    fwrite(STDERR, "HATA: Ayar dosyası hazır değil. Önce kurulum.sh çalıştırın.\n");
    exit(1);
}
$r = TrialReminder::run();
fwrite(STDOUT, sprintf("Hatırlatma: 2 gün kala %d, bitti %d, atlanan %d\n", $r['soon'], $r['expired'], $r['skipped']));
