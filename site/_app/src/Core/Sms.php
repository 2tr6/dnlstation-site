<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * SMS gönderimi (NetGSM XML API). Sağlayıcı config'te yoksa 'off' davranır ve kayıt "skipped" olarak düşer.
 * provider: 'off' | 'netgsm' | 'file' (yalnızca testler/geliştirme: storage/sms altına yazar).
 * Anahtarlar config dosyasında tutulur, veritabanında değil:
 *   'sms' => ['provider' => 'netgsm', 'usercode' => '...', 'password' => '...', 'header' => 'DNLSTATION']
 */
final class Sms
{
    public static string $lastError = '';

    public static function provider(): string
    {
        return (string)Config::get('sms.provider', 'off');
    }

    /** Gönderime hazır mı (sağlayıcı seçili ve gerekli alanlar dolu mu) */
    public static function configured(): bool
    {
        $p = self::provider();
        if ($p === 'file') {
            return true;
        }
        if ($p !== 'netgsm') {
            return false;
        }
        return (string)Config::get('sms.usercode', '') !== ''
            && (string)Config::get('sms.password', '') !== ''
            && (string)Config::get('sms.header', '') !== '';
    }

    /** 05XX XXX XX XX → 905XXXXXXXXX. Geçersizse null. */
    public static function normalize(string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($d, '90') && strlen($d) === 12) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11 && $d[0] === '0') {
            $d = substr($d, 1);
        }
        return preg_match('/^5\d{9}$/', $d) ? '90' . $d : null;
    }

    public static function send(string $phone, string $text, string $kind = '', ?int $businessId = null): bool
    {
        self::$lastError = '';
        $to = self::normalize($phone);
        if ($to === null) {
            self::$lastError = 'Geçersiz telefon numarası.';
            return self::record($phone, $kind, false, $businessId);
        }
        if (!self::configured()) {
            self::$lastError = 'SMS servisi yapılandırılmamış.';
            return self::record($to, $kind, false, $businessId, 'skipped');
        }
        $text = trim(mb_substr($text, 0, 480));
        $ok = self::provider() === 'file' ? self::toFile($to, $text) : self::netgsm($to, $text);
        return self::record($to, $kind, $ok, $businessId);
    }

    private static function record(string $to, string $kind, bool $ok, ?int $bid, string $statusOverride = ''): bool
    {
        try {
            Db::insert('sms_log', [
                'to_phone' => mb_substr($to, 0, 20), 'kind' => mb_substr($kind, 0, 40),
                'status' => $statusOverride !== '' ? $statusOverride : ($ok ? 'sent' : 'failed'),
                'error' => mb_substr(self::$lastError, 0, 300), 'business_id' => $bid, 'created_at' => Db::now(),
            ]);
        } catch (\Throwable $e) {
            // Tablo yoksa (kurulum öncesi) kayıt atlanır; gönderim hatası yine de döner
        }
        return $ok;
    }

    private static function toFile(string $to, string $text): bool
    {
        $dir = DNL_ROOT . '/storage/sms';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            self::$lastError = 'Dosya yazılamadı.';
            return false;
        }
        return file_put_contents($dir . '/outbox.txt', $to . "\t" . $text . "\n", FILE_APPEND | LOCK_EX) !== false;
    }

    /** NetGSM: XML POST, yanıt "00 <jobid>" ile başlarsa başarılıdır. Şifre loglara yazılmaz. */
    private static function netgsm(string $to, string $text): bool
    {
        if (!function_exists('curl_init')) {
            self::$lastError = 'Sunucuda cURL eklentisi yok.';
            return false;
        }
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><mainbody>'
            . '<header><company dil="TR">Netgsm</company>'
            . '<usercode>' . $esc((string)Config::get('sms.usercode')) . '</usercode>'
            . '<password>' . $esc((string)Config::get('sms.password')) . '</password>'
            . '<type>1:n</type><msgheader>' . $esc((string)Config::get('sms.header')) . '</msgheader></header>'
            . '<body><msg><![CDATA[' . str_replace(']]>', ']] >', $text) . ']]></msg><no>' . $to . '</no></body></mainbody>';
        $ch = curl_init('https://api.netgsm.com.tr/sms/send/xml');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $xml, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=UTF-8'],
            CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            self::$lastError = 'Bağlantı hatası: ' . mb_substr($err, 0, 200);
            return false;
        }
        $code = trim((string)$resp);
        if (preg_match('/<code>\s*(\d+)\s*<\/code>/', $code, $m)) {
            $code = $m[1];
        }
        if (str_starts_with($code, '00')) {
            return true;
        }
        $code = substr($code, 0, 3);
        self::$lastError = 'NetGSM kodu ' . $code . ': ' . (self::NETGSM_CODES[$code] ?? 'bilinmeyen yanıt');
        return false;
    }

    private const NETGSM_CODES = [
        '20' => 'Mesaj metni hatalı ya da çok uzun', '30' => 'Kullanıcı adı/şifre hatalı veya API erişim yetkisi yok',
        '40' => 'Mesaj başlığı (header) tanımlı değil', '50' => 'Abone bilgisi hatalı', '51' => 'Mesaj başlığı abone hesabında tanımlı değil',
        '70' => 'Parametre hatası', '85' => 'Mükerrer gönderim sınırı', '80' => 'Gönderim sınırı aşıldı',
    ];

    /** Tek bir işletme sahibine bilgilendirme SMS'i (telefonu olan sahip varsa). */
    public static function toBusinessOwner(int $businessId, string $text, string $kind): bool
    {
        $phone = (string)Db::value("SELECT phone FROM businesses WHERE id = :b", ['b' => $businessId]);
        if ($phone === '') {
            return false;
        }
        return self::send($phone, $text, $kind, $businessId);
    }
}
