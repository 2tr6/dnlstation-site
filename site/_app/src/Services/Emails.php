<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

use Dnl\Core\Mailer;

/** Sistemin gönderdiği tüm e-postalar tek yerde (metin + markalı HTML). */
final class Emails
{
    private static function first(string $name): string
    {
        $n = trim(explode(' ', trim($name))[0] ?? '');
        return $n !== '' ? $n : 'Merhaba';
    }

    public static function welcomeVerify(string $to, string $name, string $business, string $link, ?int $bid = null): bool
    {
        $days = SystemSettings::trialDays();
        return Mailer::sendTemplate($to, 'DNL Station\'a hoş geldiniz – e-postanızı doğrulayın', [
            'preheader' => "$business hesabınız hazır. $days gün boyunca tüm özellikler açık.",
            'title' => 'Hoş geldiniz, ' . self::first($name) . '!',
            'intro' => [
                "$business için DNL Station hesabınız hazır. $days gün boyunca tüm özellikleri ücretsiz kullanabilirsiniz; kart bilgisi istenmez.",
                'Hesabınızın güvenliği için e-posta adresinizi doğrulayın:',
            ],
            'button' => ['E-postamı doğrula', $link],
            'after' => [
                'Başlamak için: panelde kurulum sihirbazından masalarınızı ve ücretlerinizi girin, Kasa sekmesinden kasayı açın ve ilk masanızı başlatın.',
            ],
            'note' => 'Doğrulama bağlantısı 48 saat geçerlidir. Bu hesabı siz oluşturmadıysanız bu e-postayı yok sayabilirsiniz.',
        ], 'welcome', $bid);
    }

    public static function verify(string $to, string $name, string $link, ?int $bid = null): bool
    {
        return Mailer::sendTemplate($to, 'DNL Station e-posta doğrulama', [
            'preheader' => 'E-posta adresinizi tek dokunuşla doğrulayın.',
            'title' => 'E-posta adresinizi doğrulayın',
            'intro' => ['Merhaba ' . self::first($name) . ', DNL Station hesabınızdaki e-posta adresini doğrulamak için aşağıdaki düğmeye dokunun.'],
            'button' => ['E-postamı doğrula', $link],
            'note' => 'Bağlantı 48 saat geçerlidir. Bu isteği siz yapmadıysanız bu e-postayı yok sayabilirsiniz.',
        ], 'verify', $bid);
    }

    public static function reset(string $to, string $name, string $link, bool $bySupport = false, ?int $bid = null): bool
    {
        return Mailer::sendTemplate($to, 'DNL Station şifre sıfırlama', [
            'preheader' => 'Yeni şifrenizi belirlemek için bağlantınız hazır.',
            'title' => 'Şifrenizi sıfırlayın',
            'intro' => ['Merhaba ' . self::first($name) . ', ' . ($bySupport ? 'destek talebiniz üzerine' : 'isteğiniz üzerine') . ' şifre sıfırlama bağlantınız oluşturuldu. Yeni şifrenizi belirlemek için aşağıdaki düğmeye dokunun.'],
            'button' => ['Yeni şifre belirle', $link],
            'after' => ['Şifrenizi değiştirdiğinizde güvenliğiniz için tüm cihazlardaki oturumlar kapatılır.'],
            'note' => 'Bağlantı 1 saat geçerlidir ve yalnızca bir kez kullanılabilir. Bu isteği siz yapmadıysanız bu e-postayı yok sayın; şifreniz değişmez.',
        ], 'reset', $bid);
    }

    /** Deneme süresi hatırlatması: $mode = 'soon' (bitmek üzere) veya 'expired' (bitti) */
    public static function trialNotice(string $to, string $name, string $business, string $mode, ?int $bid = null): bool
    {
        $link = Mailer::url('/panel/#/paketim');
        if ($mode === 'expired') {
            return Mailer::sendTemplate($to, 'DNL Station deneme süreniz sona erdi', [
                'preheader' => "$business için deneme süreniz doldu. Kayıtlarınız güvende.",
                'title' => 'Deneme süreniz sona erdi',
                'intro' => [self::first($name) . ', ' . $business . ' için 7 günlük deneme süreniz doldu. Kayıtlarınız silinmedi; bir paket seçtiğinizde panel kaldığı yerden açılır.'],
                'button' => ['Paket seçin', $link],
                'note' => 'Sorularınız için WhatsApp (+90 537 762 07 08) ya da support@dnlstation.com.tr adresinden bize ulaşabilirsiniz.',
            ], 'trial_expired', $bid);
        }
        return Mailer::sendTemplate($to, 'DNL Station deneme süreniz 2 gün içinde bitiyor', [
            'preheader' => "$business için deneme süreniz 2 gün içinde bitiyor.",
            'title' => 'Deneme süreniz 2 gün içinde bitiyor',
            'intro' => [self::first($name) . ', ' . $business . ' için deneme süreniz yakında doluyor. Verileriniz korunur; kesintisiz devam etmek için bir paket seçebilirsiniz.'],
            'button' => ['Paketleri görüntüle', $link],
            'after' => ['Ödeme açıklamasına işletme kodunuzu yazmanız yeterli; ödemeniz onaylanınca paketiniz aynı gün aktif edilir.'],
            'note' => 'Otomatik çekim yapılmaz. Bu e-postayı deneme süreniz bittikten sonra da almaya devam etmeyeceksiniz.',
        ], 'trial_soon', $bid);
    }

    public static function test(string $to): array
    {
        return \Dnl\Core\MailTemplate::render([
            'preheader' => 'E-posta altyapınız çalışıyor.',
            'title' => 'E-posta ayarı tamam ✓',
            'intro' => ['Bu, DNL Station e-posta ayarının test mesajıdır. Bu mesajı aldıysanız doğrulama ve şifre sıfırlama e-postaları müşterilerinize bu tasarımla ulaşacak.'],
            'button' => ['Paneli aç', rtrim((string)\Dnl\Core\Config::get('app_url'), '/') . '/panel/'],
        ]);
    }

    /** Yöneticiden işletme sahibine serbest mesaj */
    public static function adminMessage(string $to, string $name, string $subject, string $message, ?int $bid): bool
    {
        $paras = array_values(array_filter(array_map('trim', preg_split('/\n{2,}/', str_replace("\r", '', $message)) ?: []), 'strlen'));
        return Mailer::sendTemplate($to, $subject, [
            'preheader' => mb_substr($paras[0] ?? '', 0, 90),
            'title' => $subject,
            'intro' => array_merge(['Merhaba ' . self::first($name) . ','], $paras),
            'button' => ['Panele git', rtrim((string)\Dnl\Core\Config::get('app_url'), '/') . '/panel/'],
        ], 'admin_message', $bid);
    }

    /** Vardiya kapanınca işletme sahibine Z raporu özeti */
    public static function zReport(string $to, string $name, string $business, array $z, ?int $bid): bool
    {
        $m = static fn(int $c): string => '₺' . number_format($c / 100, 2, ',', '.');
        $diff = $z['counted_cash_cents'] !== null ? $z['counted_cash_cents'] - $z['expected_cash_cents'] : 0;
        return Mailer::sendTemplate($to, "$business – Z raporu (" . $m((int)$z['revenue_cents']) . ')', [
            'preheader' => 'Toplam tahsilat ' . $m((int)$z['revenue_cents']) . ', kasa farkı ' . $m($diff),
            'title' => 'Vardiya kapandı',
            'intro' => [
                "Merhaba " . self::first($name) . ", $business için vardiya #{$z['id']} kapatıldı" . ($z['closed_by'] ? " ({$z['closed_by']})" : '') . '.',
                'Toplam tahsilat: ' . $m((int)$z['revenue_cents']) . ' · Nakit: ' . $m((int)$z['cash_cents']) . ' · Kart: ' . $m((int)$z['card_cents']) . ($z['credit_cents'] ?? 0 ? ' · Veresiye: ' . $m((int)$z['credit_cents']) : ''),
                'Oyun: ' . $m((int)$z['game_cents']) . ' · Ürün: ' . $m((int)$z['items_cents']) . ' · İndirim: ' . $m((int)$z['discount_cents']) . ' · Gider: ' . $m((int)($z['expense_cents'] ?? 0)),
                'Beklenen nakit: ' . $m((int)$z['expected_cash_cents']) . ' · Sayılan: ' . $m((int)$z['counted_cash_cents']) . ' · Fark: ' . ($diff > 0 ? '+' : '') . $m($diff),
            ],
            'button' => ['Raporları aç', rtrim((string)\Dnl\Core\Config::get('app_url'), '/') . '/panel/#/raporlar'],
            'note' => 'Bu e-postayı kapatmak için panelde Ayarlar > İşletme bölümündeki “Z raporunu e-postayla gönder” seçeneğini kapatın.',
        ], 'z_report', $bid);
    }
}
