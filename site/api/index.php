<?php
declare(strict_types=1);

// DNL Station API giriş noktası. Tüm /api/* istekleri buraya yönlendirilir.
require dirname(__DIR__) . '/_app/bootstrap.php';

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = preg_replace('#^/api#', '', $path) ?: '/';
$path = '/' . trim($path, '/');

/** @var \Dnl\Core\Router $router */
$router = require DNL_ROOT . '/routes.php';
$router->dispatch(\Dnl\Core\Http::method(), $path);
