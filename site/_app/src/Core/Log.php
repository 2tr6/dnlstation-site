<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/** Hata günlüğü. Hassas veri (şifre, token) asla yazılmaz. */
final class Log
{
    public static function error(\Throwable $e, string $context = ''): void
    {
        $dir = (string)Config::get('log_dir', DNL_ROOT . '/storage/logs');
        $line = sprintf("[%s] %s %s: %s @ %s:%d\n", gmdate('c'), $context, get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine());
        if (is_dir($dir) || @mkdir($dir, 0750, true)) {
            @file_put_contents($dir . '/app-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
        } else {
            error_log($line);
        }
    }
}
