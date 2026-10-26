<?php
/**
 * DNL Station — uygulama önyükleyici.
 * Bu dizin (_app) web'den erişime kapalıdır (.htaccess + aşağıdaki koruma).
 */
declare(strict_types=1);

define('DNL_APP', true);
define('DNL_ROOT', __DIR__);
define('DNL_PUBLIC', dirname(__DIR__));

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'DNL Station PHP 8.1 veya üzeri gerektirir.';
    exit;
}

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Dnl\\', 4) !== 0) {
        return;
    }
    $path = DNL_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// Hata ayrıntıları asla ekrana basılmaz; günlüğe yazılır.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

\Dnl\Core\Config::load();
date_default_timezone_set('UTC');
