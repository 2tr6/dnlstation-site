<?php
declare(strict_types=1);

// DNL Station — ana yönetici paneli kabuğu. Kimlik doğrulama API üzerinden (şifre + Authenticator).
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'");
$v = '1';
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Yönetim | DNL Station</title>
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#09090D">
<meta name="color-scheme" content="dark">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Geist:wght@400;500;600&family=Geist+Mono:wght@500;600&display=swap">
<link rel="stylesheet" href="/panel/assets/panel.css?v=<?= $v ?>">
<link rel="stylesheet" href="/yonetim/admin.css?v=<?= $v ?>">
<script type="module" src="/yonetim/admin.js?v=<?= $v ?>"></script>
</head>
<body class="adm">
<div id="app" class="boot" aria-busy="true"><div class="boot-mark">DNL</div></div>
</body>
</html>
