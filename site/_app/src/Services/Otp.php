<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

use Dnl\Core\Config;
use Dnl\Core\Db;
use Dnl\Core\HttpError;
use Dnl\Core\RateLimiter;
use Dnl\Core\Sms;

/**
 * SMS ile tek kullanımlık doğrulama kodu (OTP).
 * - Kod sunucuda yalnızca HMAC özeti olarak tutulur; 5 dakika geçerli, en fazla 5 deneme.
 * - Doğrulama başarılıysa kısa ömürlü, imzalı bir "kanıt" (proof) döner. Kayıt gibi işlemler bu kanıtı ister.
 * - Kanıt, telefon + amaç ile bağlıdır; başka numara veya amaç için kullanılamaz.
 */
final class Otp
{
    public const PURPOSES = ['signup', 'phone_verify'];
    private const TTL = 300;
    private const PROOF_TTL = 900;
    private const MAX_ATTEMPTS = 5;

    public static function request(string $phone, string $purpose, string $ip): array
    {
        self::assertPurpose($purpose);
        $to = Sms::normalize($phone);
        if ($to === null) {
            throw new HttpError(422, 'Geçerli bir telefon numarası girin.', 'validation');
        }
        if (!Sms::configured()) {
            throw new HttpError(503, 'SMS doğrulama şu an kullanılamıyor. Lütfen daha sonra tekrar deneyin.', 'sms_disabled');
        }
        RateLimiter::enforce('otp:phone:' . $to, 3, 600, 'Bu numaraya çok fazla kod gönderildi. Lütfen 10 dakika sonra tekrar deneyin.');
        RateLimiter::enforce('otp:ip:' . $ip, 10, 3600, 'Kısa sürede çok fazla kod istediniz. Lütfen daha sonra tekrar deneyin.');
        // SMS maliyeti ve kötüye kullanım (SMS pumping) için site genelinde günlük üst sınır
        RateLimiter::enforce('otp:global', 500, 86400, 'Şu an doğrulama kodu gönderilemiyor. Lütfen daha sonra tekrar deneyin.');

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = time();
        Db::exec('UPDATE otp_codes SET used_at = :n WHERE phone = :p AND purpose = :u AND used_at IS NULL',
            ['n' => Db::now(), 'p' => $to, 'u' => $purpose]);
        Db::insert('otp_codes', [
            'phone' => $to, 'purpose' => $purpose, 'code_hash' => self::hash($to, $purpose, $code), 'attempts' => 0,
            'expires_at' => gmdate('Y-m-d H:i:s', $now + self::TTL), 'created_at' => Db::now(),
        ]);
        $text = "DNL Station doğrulama kodunuz: $code. 5 dakika geçerlidir. Bu kodu kimseyle paylaşmayın.";
        if (!Sms::send($to, $text, 'otp')) {
            throw new HttpError(502, 'Kod gönderilemedi. Lütfen tekrar deneyin.', 'sms_failed');
        }
        return ['ok' => true, 'message' => 'Doğrulama kodu telefonunuza gönderildi.', 'expires_in' => self::TTL];
    }

    /** Kod doğruysa imzalı kanıt döner. Yanlışsa deneme sayılır. */
    public static function verify(string $phone, string $purpose, string $code): array
    {
        self::assertPurpose($purpose);
        $to = Sms::normalize($phone);
        if ($to === null || !preg_match('/^\d{6}$/', $code)) {
            throw new HttpError(422, 'Kod hatalı veya süresi dolmuş.', 'otp_invalid');
        }
        $row = Db::one('SELECT * FROM otp_codes WHERE phone = :p AND purpose = :u AND used_at IS NULL ORDER BY id DESC LIMIT 1',
            ['p' => $to, 'u' => $purpose]);
        if ($row === null || $row['expires_at'] < Db::now()) {
            throw new HttpError(422, 'Kod hatalı veya süresi dolmuş. Yeni kod isteyin.', 'otp_invalid');
        }
        if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) {
            Db::exec('UPDATE otp_codes SET used_at = :n WHERE id = :i', ['n' => Db::now(), 'i' => $row['id']]);
            throw new HttpError(429, 'Çok fazla hatalı deneme yaptınız. Yeni kod isteyin.', 'otp_locked');
        }
        Db::exec('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = :i', ['i' => $row['id']]);
        if (!hash_equals((string)$row['code_hash'], self::hash($to, $purpose, $code))) {
            throw new HttpError(422, 'Kod hatalı.', 'otp_invalid');
        }
        Db::exec('UPDATE otp_codes SET used_at = :n WHERE id = :i', ['n' => Db::now(), 'i' => $row['id']]);
        return ['ok' => true, 'proof' => self::makeProof($to, $purpose), 'expires_in' => self::PROOF_TTL];
    }

    /** Kayıt gibi bir işlemde kanıtı doğrular. Geçersizse HttpError fırlatır. */
    public static function assertProof(string $phone, string $purpose, string $proof): void
    {
        $to = Sms::normalize($phone);
        $parts = explode('.', $proof);
        if ($to === null || count($parts) !== 2) {
            throw new HttpError(422, 'Telefon numaranız doğrulanmamış.', 'phone_not_verified');
        }
        [$body, $sig] = $parts;
        $raw = base64_decode(strtr($body, '-_', '+/'), true);
        $ok = $raw !== false && hash_equals(hash_hmac('sha256', $body, Config::key()), $sig);
        if ($ok) {
            [$p, $u, $exp] = array_pad(explode('|', $raw), 3, '');
            $ok = $p === $to && $u === $purpose && (int)$exp >= time();
        }
        if (!$ok) {
            throw new HttpError(422, 'Telefon numaranız doğrulanmamış ya da doğrulamanın süresi doldu.', 'phone_not_verified');
        }
    }

    private static function makeProof(string $to, string $purpose): string
    {
        $body = rtrim(strtr(base64_encode($to . '|' . $purpose . '|' . (time() + self::PROOF_TTL)), '+/', '-_'), '=');
        return $body . '.' . hash_hmac('sha256', $body, Config::key());
    }

    private static function hash(string $to, string $purpose, string $code): string
    {
        return hash_hmac('sha256', $to . '|' . $purpose . '|' . $code, Config::key());
    }

    private static function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new HttpError(422, 'Geçersiz doğrulama amacı.', 'validation');
        }
    }
}
