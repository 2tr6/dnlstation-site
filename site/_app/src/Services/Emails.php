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

    public static function welcomeVerify(string $to, string $name, string $business, string $link): bool
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
        ]);
    }

    public static function verify(string $to, string $name, string $link): bool
    {
        return Mailer::sendTemplate($to, 'DNL Station e-posta doğrulama', [
            'preheader' => 'E-posta adresinizi tek dokunuşla doğrulayın.',
            'title' => 'E-posta adresinizi doğrulayın',
            'intro' => ['Merhaba ' . self::first($name) . ', DNL Station hesabınızdaki e-posta adresini doğrulamak için aşağıdaki düğmeye dokunun.'],
            'button' => ['E-postamı doğrula', $link],
            'note' => 'Bağlantı 48 saat geçerlidir. Bu isteği siz yapmadıysanız bu e-postayı yok sayabilirsiniz.',
        ]);
    }

    public static function reset(string $to, string $name, string $link, bool $bySupport = false): bool
    {
        return Mailer::sendTemplate($to, 'DNL Station şifre sıfırlama', [
            'preheader' => 'Yeni şifrenizi belirlemek için bağlantınız hazır.',
            'title' => 'Şifrenizi sıfırlayın',
            'intro' => ['Merhaba ' . self::first($name) . ', ' . ($bySupport ? 'destek talebiniz üzerine' : 'isteğiniz üzerine') . ' şifre sıfırlama bağlantınız oluşturuldu. Yeni şifrenizi belirlemek için aşağıdaki düğmeye dokunun.'],
            'button' => ['Yeni şifre belirle', $link],
            'after' => ['Şifrenizi değiştirdiğinizde güvenliğiniz için tüm cihazlardaki oturumlar kapatılır.'],
            'note' => 'Bağlantı 1 saat geçerlidir ve yalnızca bir kez kullanılabilir. Bu isteği siz yapmadıysanız bu e-postayı yok sayın; şifreniz değişmez.',
        ]);
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
}
