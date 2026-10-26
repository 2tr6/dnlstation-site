<?php
declare(strict_types=1);

defined('DNL_APP') || exit;

use Dnl\Controllers as C;
use Dnl\Core\Router;
use Dnl\Services\Perm;

$r = new Router();
$all = ['owner', 'manager', 'staff'];

// Sistem
$r->add('POST', '/system/migrate', fn() => C\SystemController::migrate(), ['csrf' => false, 'session' => false, 'migrations' => false]);
$r->add('GET', '/system/health', fn() => C\SystemController::health(), ['session' => false, 'migrations' => false]);

// Kimlik
$r->add('GET', '/auth/csrf', fn() => C\AuthController::csrf());
$r->add('POST', '/auth/register', fn() => C\AuthController::register());
$r->add('POST', '/auth/login', fn() => C\AuthController::login());
$r->add('POST', '/auth/logout', fn() => C\AuthController::logout());
$r->add('POST', '/auth/forgot', fn() => C\AuthController::forgot());
$r->add('POST', '/auth/reset', fn() => C\AuthController::reset());
$r->add('POST', '/auth/verify', fn() => C\AuthController::verify());
$r->add('POST', '/auth/resend-verification', fn() => C\AuthController::resendVerification(), ['auth' => $all, 'allow_pw_change' => true]);
$r->add('GET', '/me', fn() => C\AuthController::me(), ['auth' => $all, 'allow_pw_change' => true]);
$r->add('POST', '/account/password', fn() => C\AuthController::changePassword(), ['auth' => $all, 'allow_pw_change' => true]);

// İşletme paneli — yetki kontrolleri denetleyicilerde Perm::need ile yapılır.
// 'write' => true: deneme/paket süresi bitince yazma işlemleri engellenir (okuma ve raporlar açık kalır).
$a = ['auth' => $all];
$w = ['auth' => $all, 'write' => true];

$r->add('GET', '/dashboard', fn() => C\PanelController::dashboard(), $a);
$r->add('GET', '/setup/presets', fn() => C\PanelController::presets(), $a);
$r->add('POST', '/setup/quick', fn() => C\PanelController::setupQuick(), $w);
$r->add('POST', '/setup/skip', fn() => C\PanelController::setupSkip(), $w);

// Masa oturumları
$r->add('POST', '/sessions', fn() => C\SessionController::open(), $w);
$r->add('GET', '/sessions/recent', fn() => C\SessionController::recent(), $a);
$r->add('GET', '/sessions/{id}', fn($p) => C\SessionController::detail($p), $a);
$r->add('POST', '/sessions/{id}/extend', fn($p) => C\SessionController::extend($p), $w);
$r->add('POST', '/sessions/{id}/controllers', fn($p) => C\SessionController::controllers($p), $w);
$r->add('POST', '/sessions/{id}/match', fn($p) => C\SessionController::match($p), $w);
$r->add('POST', '/sessions/{id}/move', fn($p) => C\SessionController::move($p), $w);
$r->add('POST', '/sessions/{id}/items', fn($p) => C\SessionController::addItem($p), $w);
$r->add('DELETE', '/sessions/{id}/items/{item}', fn($p) => C\SessionController::removeItem($p), $w);
$r->add('POST', '/sessions/{id}/close', fn($p) => C\SessionController::close($p), $w);
$r->add('POST', '/sessions/{id}/cancel', fn($p) => C\SessionController::cancel($p), $w);
$r->add('POST', '/sessions/{id}/reopen', fn($p) => C\SessionController::reopen($p), $w);

// Masa türleri ve masalar
$r->add('GET', '/catalog', fn() => C\CatalogController::index(), $a);
$r->add('POST', '/catalog/types', fn() => C\CatalogController::createType(), $w);
$r->add('PUT', '/catalog/types/{id}', fn($p) => C\CatalogController::updateType($p), $w);
$r->add('DELETE', '/catalog/types/{id}', fn($p) => C\CatalogController::deleteType($p), $w);
$r->add('POST', '/catalog/tables', fn() => C\CatalogController::createTables(), $w);
$r->add('PUT', '/catalog/tables/{id}', fn($p) => C\CatalogController::updateTable($p), $w);
$r->add('DELETE', '/catalog/tables/{id}', fn($p) => C\CatalogController::deleteTable($p), $w);

// Ürünler, stok, hızlı satış
$r->add('GET', '/menu', fn() => C\ProductController::menu(), $a);
$r->add('GET', '/products', fn() => C\ProductController::index(), $a);
$r->add('POST', '/products', fn() => C\ProductController::create(), $w);
$r->add('PUT', '/products/{id}', fn($p) => C\ProductController::update($p), $w);
$r->add('DELETE', '/products/{id}', fn($p) => C\ProductController::archive($p), $w);
$r->add('POST', '/products/{id}/restock', fn($p) => C\ProductController::restock($p), $w);
$r->add('POST', '/products/{id}/count', fn($p) => C\ProductController::count($p), $w);
$r->add('POST', '/quick-sales', fn() => C\ProductController::quickSale(), $w);
$r->add('GET', '/quick-sales', fn() => C\ProductController::recentQuick(), $a);
$r->add('POST', '/quick-sales/{id}/void', fn($p) => C\ProductController::voidQuick($p), $w);

// Vardiya
$r->add('GET', '/shift', fn() => C\ShiftController::current(), $a);
$r->add('POST', '/shift/open', fn() => C\ShiftController::open(), $w);
// Kapatma, paket süresi bitse de yapılabilmeli (kasa kapanışı engellenmez)
$r->add('POST', '/shift/close', fn() => C\ShiftController::close(), $a);
$r->add('GET', '/shifts', fn() => C\ShiftController::index(), $a);
$r->add('GET', '/shifts/{id}', fn($p) => C\ShiftController::show($p), $a);

// Raporlar
$r->add('GET', '/reports/summary', fn() => C\ReportController::summary(), $a);
$r->add('GET', '/reports/tickets.csv', fn() => C\ReportController::csv(), $a);

// Personel
$r->add('GET', '/staff', fn() => C\StaffController::index(), $a);
$r->add('POST', '/staff', fn() => C\StaffController::create(), $w);
$r->add('PUT', '/staff/{id}', fn($p) => C\StaffController::update($p), $a);
$r->add('POST', '/staff/{id}/reset-password', fn($p) => C\StaffController::resetPassword($p), $a);

// İşletme ayarları, müşteri ekranı, kayıtlar
$r->add('GET', '/settings', fn() => C\SettingsController::show(), $a);
$r->add('PUT', '/settings', fn() => C\SettingsController::update(), $a);
$r->add('POST', '/settings/display/rotate', fn() => C\SettingsController::rotateDisplay(), $w);
$r->add('POST', '/settings/display/disable', fn() => C\SettingsController::disableDisplay(), $a);
$r->add('GET', '/display', fn() => C\SettingsController::display(), ['session' => false]);
$r->add('GET', '/audit', fn() => C\SettingsController::audit(), $a);

return $r;
