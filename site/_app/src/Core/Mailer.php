<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * E-posta gönderimi. Varsayılan: hosting'in PHP mail() fonksiyonu (cPanel'de hazır).
 * 'file' taşıyıcısı testler içindir. Başlık enjeksiyonuna karşı CR/LF temizlenir.
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
        try {
            return mail($to, $subjectEnc, $body, implode("\r\n", $headers), '-f' . $from);
        } catch (\Throwable $e) {
            Log::error($e, 'mail');
            return false;
        }
    }

    public static function url(string $path): string
    {
        return rtrim((string)Config::get('app_url', ''), '/') . $path;
    }
}
