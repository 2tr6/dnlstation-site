<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * Ayarlar web kökünün DIŞINDAKİ bir dosyadan okunur:
 *   varsayılan: public_html'in bir üst dizini /dnlstation-config.php
 *   (ör. /home/kullanici/dnlstation-config.php)
 * DNL_CONFIG ortam değişkeni ile farklı yol verilebilir.
 */
final class Config
{
    private static array $data = [];
    private static bool $ready = false;
    private static string $path = '';

    public static function load(): void
    {
        $path = getenv('DNL_CONFIG') ?: dirname(DNL_PUBLIC) . '/dnlstation-config.php';
        self::$path = $path;
        if (!is_file($path) || !is_readable($path)) {
            self::$ready = false;
            return;
        }
        $data = require $path;
        if (!is_array($data)) {
            self::$ready = false;
            return;
        }
        $defaults = [
            'app_url' => 'https://dnlstation.com.tr',
            'app_key' => '',
            'timezone' => 'Europe/Istanbul',
            'db' => ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => '', 'path' => ''],
            'mail' => ['from' => 'noreply@dnlstation.com.tr', 'from_name' => 'DNL Station'],
            'migrate_token' => '',
            // SMS (NetGSM). provider: off | netgsm. signup_otp: true ise kayıtta SMS doğrulaması zorunlu olur.
            'sms' => ['provider' => 'off', 'usercode' => '', 'password' => '', 'header' => '', 'signup_otp' => false],
            'require_email_verification' => true,
            'force_secure_cookies' => true,
            'log_dir' => DNL_ROOT . '/storage/logs',
            'debug' => false,
        ];
        $data = array_replace_recursive($defaults, $data);
        // app_key en az 32 bayt olmalı; değilse sistem güvenli değildir ve çalışmaz.
        $key = (string)$data['app_key'];
        $raw = base64_decode($key, true);
        if ($raw === false || strlen($raw) < 32) {
            self::$ready = false;
            self::$data = $data;
            return;
        }
        $data['_key_raw'] = $raw;
        self::$data = $data;
        self::$ready = true;
    }

    public static function ready(): bool
    {
        return self::$ready;
    }

    public static function path(): string
    {
        return self::$path;
    }

    /** Nokta gösterimiyle okuma: Config::get('db.host') */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cur = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    /** Testler için: ayarları doğrudan ver. */
    public static function override(array $data): void
    {
        self::$data = $data;
        self::$data['_key_raw'] = base64_decode((string)$data['app_key'], true) ?: '';
        self::$ready = strlen(self::$data['_key_raw']) >= 32;
    }

    public static function key(): string
    {
        return (string)(self::$data['_key_raw'] ?? '');
    }
}
