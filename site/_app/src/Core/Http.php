<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

final class Http
{
    private static ?array $json = null;

    public static function method(): string
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function ip(): string
    {
        // Proxy başlıklarına (X-Forwarded-For) güvenilmez; taklit edilebilirler.
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $_SERVER[$key] ?? null;
        return $v === null ? null : (string)$v;
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    /** JSON gövde (en fazla 512 KB) */
    public static function json(): array
    {
        if (self::$json !== null) {
            return self::$json;
        }
        $raw = file_get_contents('php://input', false, null, 0, 524289) ?: '';
        if (strlen($raw) > 524288) {
            throw new HttpError(413, 'İstek çok büyük.', 'too_large');
        }
        if ($raw === '') {
            return self::$json = [];
        }
        $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($ct, 'application/json') === false) {
            throw new HttpError(415, 'Desteklenmeyen içerik türü.', 'bad_content_type');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new HttpError(400, 'Geçersiz istek.', 'bad_json');
        }
        return self::$json = $data;
    }

    public static function setJsonForTests(?array $data): void
    {
        self::$json = $data;
    }

    public static function sendJson(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Aynı kökten gelmeyen durum değiştiren istekleri reddeder (CSRF'e ek katman). */
    public static function assertSameOrigin(): void
    {
        $origin = self::header('Origin');
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($origin !== null && $origin !== 'null') {
            $oh = parse_url($origin, PHP_URL_HOST);
            $op = parse_url($origin, PHP_URL_PORT);
            $originHost = $oh . ($op ? ':' . $op : '');
            if (strcasecmp($originHost, $host) !== 0) {
                SecurityLog::add('bad_origin', '', null, mb_substr((string)$origin, 0, 120));
                throw new HttpError(403, 'İzin verilmeyen kaynak.', 'bad_origin');
            }
        }
    }
}
