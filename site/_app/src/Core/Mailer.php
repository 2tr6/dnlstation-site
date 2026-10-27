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
    /** Markalı HTML + düz metin e-posta (bkz. MailTemplate) */
    public static function sendTemplate(string $to, string $subject, array $m): bool
    {
        [$text, $html] = MailTemplate::render($m);
        return self::send($to, $subject, $text, $html);
    }

    /** MIME gövdesi: yalnız metin ya da metin+HTML (multipart/alternative), base64 */
    private static function mime(string $text, ?string $html): array
    {
        $b64 = static fn(string $s): string => rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\n"], ["\n", "\r\n"], $s)), 76, "\r\n"));
        if ($html === null) {
            return ['Content-Type: text/plain; charset=UTF-8', 'base64', $b64($text)];
        }
        $bd = 'dnl_' . bin2hex(random_bytes(12));
        $body = "--$bd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($text)
            . "\r\n--$bd\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html)
            . "\r\n--$bd--";
        return ['Content-Type: multipart/alternative; boundary="' . $bd . '"', null, $body];
    }

    public static function send(string $to, string $subject, string $body, ?string $html = null): bool
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
            'X-Mailer: DNL Station',
        ];
        [$ctype, $cte, $mimeBody] = self::mime($body, $html);
        $headers[] = $ctype;
        if ($cte !== null) {
            $headers[] = 'Content-Transfer-Encoding: ' . $cte;
        }
        $body = str_replace("\r\n", "\n", $body);
        $transport = (string)Config::get('mail.transport', 'mail');
        if ($transport === 'file') {
            $dir = DNL_ROOT . '/storage/mail';
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            $file = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
            return (bool)file_put_contents($file, "To: $to\nSubject: $subject\n\n$body" . ($html !== null ? "\n\n--- HTML ---\n$html" : ''));
        }
        if ($transport === 'smtp') {
            return self::smtp((array)Config::get('mail.smtp', []), $from, $fromName, $to, $subjectEnc, $body, $html);
        }
        try {
            return mail($to, $subjectEnc, $mimeBody, implode("\r\n", $headers), '-f' . $from);
        } catch (\Throwable $e) {
            Log::error($e, 'mail');
            return false;
        }
    }

    public static string $lastError = '';

    /** RFC 5322 ileti oluşturup SMTP ile gönderir (gövde base64: Türkçe karakterler bozulmaz) */
    public static function smtp(array $cfg, string $from, string $fromName, string $to, string $subjectEnc, string $body, ?string $html = null): bool
    {
        [$ctype, $cte, $mimeBody] = self::mime($body, $html);
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $msg = implode("\r\n", [
            'Date: ' . date(DATE_RFC2822),
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $subjectEnc,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            $ctype,
            ...($cte !== null ? ['Content-Transfer-Encoding: ' . $cte] : []),
            '',
            $mimeBody,
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
