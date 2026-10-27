<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * E-posta gönderimi. Önerilen: 'smtp' (noreply hesabıyla kimlik doğrulamalı; Gmail/Outlook kabul eder).
 * 'mail' = PHP mail(), 'file' = testler. Başlık enjeksiyonuna karşı CR/LF temizlenir.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $to = str_replace(["\r", "\n"], '', $to);
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $from = str_replace(["\r", "\n"], '', (string)Config::get('mail.from', 'noreply@dnlstation.com.tr'));
        $fromName = str_replace(["\r", "\n"], '', (string)Config::get('mail.from_name', 'DNL Station'));
        $subjectEnc = '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $subject)) . '?=';
        $headers = [
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
            'Reply-To: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: DNL Station',
        ];
        $body = str_replace("\r\n", "\n", $body);
        $transport = (string)Config::get('mail.transport', 'mail');
        if ($transport === 'file') {
            $dir = DNL_ROOT . '/storage/mail';
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            $file = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
            return (bool)file_put_contents($file, "To: $to\nSubject: $subject\n\n$body");
        }
        if ($transport === 'smtp') {
            return self::smtp((array)Config::get('mail.smtp', []), $from, $fromName, $to, $subjectEnc, $body);
        }
        try {
            return mail($to, $subjectEnc, $body, implode("\r\n", $headers), '-f' . $from);
        } catch (\Throwable $e) {
            Log::error($e, 'mail');
            return false;
        }
    }

    public static string $lastError = '';

    /** RFC 5322 ileti oluşturup SMTP ile gönderir (gövde base64: Türkçe karakterler bozulmaz) */
    public static function smtp(array $cfg, string $from, string $fromName, string $to, string $subjectEnc, string $body): bool
    {
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $msg = implode("\r\n", [
            'Date: ' . date(DATE_RFC2822),
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $subjectEnc,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n")),
        ]);
        try {
            $c = new Smtp($cfg);
            $ok = $c->send($from, $to, $msg);
            self::$lastError = $c->lastError;
            if (!$ok) {
                Log::error(new \RuntimeException('SMTP: ' . $c->lastError), 'mail');
            }
            return $ok;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            Log::error($e, 'mail');
            return false;
        }
    }

    public static function url(string $path): string
    {
        return rtrim((string)Config::get('app_url', ''), '/') . $path;
    }
}
