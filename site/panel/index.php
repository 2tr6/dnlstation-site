<?php
declare(strict_types=1);

// DNL Station — işletme paneli kabuğu. Oturum yoksa giriş sayfasına yönlendirir.
require dirname(__DIR__) . '/_app/bootstrap.php';

use Dnl\Core\Auth;
use Dnl\Core\Config;
use Dnl\Core\Db;
use Dnl\Core\Migrator;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$fail = static function (string $title, string $text): never {
    http_response_code(503);
    header('Retry-After: 300');
    echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>DNL Station</title>'
        . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#09090D;color:#EDEBF5;font:16px/1.6 system-ui,sans-serif;text-align:center;padding:24px">'
        . '<div><p style="font:700 24px system-ui;letter-spacing:.06em;margin:0 0 18px">DNL</p><h1 style="font-size:22px;margin:0 0 8px">' . htmlspecialchars($title) . '</h1><p style="color:#9A97AB;margin:0">' . htmlspecialchars($text) . '</p></div></body></html>';
    exit;
};

if (!Config::ready()) {
    $fail('Sistem henüz hazır değil', 'Panel kısa süre içinde hizmete açılacak.');
}
try {
    Db::pdo();
    if (Migrator::pending() > 0) {
        $fail('Sistem güncelleniyor', 'Lütfen birkaç dakika sonra tekrar deneyin.');
    }
    $user = Auth::user();
} catch (\Throwable $e) {
    \Dnl\Core\Log::error($e, 'panel-shell');
    $fail('Hizmete ulaşılamıyor', 'Lütfen biraz sonra tekrar deneyin.');
}
if ($user === null) {
    header('Location: /giris/?oturum=bitti', true, 302);
    exit;
}
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; media-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'");
$v = '1';
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Panel | DNL Station</title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#09090D">
<meta name="color-scheme" content="dark">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Geist:wght@400;500;600&family=Geist+Mono:wght@500;600&display=swap">
<link rel="stylesheet" href="/panel/assets/panel.css?v=<?= $v ?>">
<script type="module" src="/panel/assets/app.js?v=<?= $v ?>"></script>
</head>
<body>
<div id="app" class="boot" aria-busy="true">
  <div class="boot-mark">DNL</div>
  <noscript><p>Panel için JavaScript gereklidir.</p></noscript>
</div>
</body>
</html>
