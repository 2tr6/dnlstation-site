<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * Markalı e-posta şablonu: HTML (tablo tabanlı, satır içi stil; Gmail/Outlook/Apple Mail uyumlu) + düz metin.
 * Tüm değişken içerik kaçışlanır; kullanıcı adı gibi veriler HTML olarak yorumlanmaz.
 *
 * $m: title, intro (paragraf dizisi), button [label, url], note (küçük yazı), after (paragraf dizisi), preheader
 * @return array{0:string,1:string} [düz metin, html]
 */
final class MailTemplate
{
    public static function render(array $m): array
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = (string)($m['title'] ?? 'DNL Station');
        $intro = (array)($m['intro'] ?? []);
        $after = (array)($m['after'] ?? []);
        $btn = $m['button'] ?? null;
        $note = (string)($m['note'] ?? '');
        $pre = (string)($m['preheader'] ?? '');
        $site = rtrim((string)Config::get('app_url', 'https://dnlstation.com.tr'), '/');

        // Düz metin
        $text = $title . "\n\n" . implode("\n\n", $intro);
        if ($btn) {
            $text .= "\n\n" . $btn[0] . ":\n" . $btn[1];
        }
        if ($after) {
            $text .= "\n\n" . implode("\n\n", $after);
        }
        if ($note) {
            $text .= "\n\n" . $note;
        }
        $text .= "\n\n—\nDNL Station · PlayStation ve oyun salonları için yönetim paneli\n$site\nDestek: WhatsApp +90 537 762 07 08 · support@dnlstation.com.tr";

        $p = static fn(string $s): string => '<p style="margin:0 0 16px;font:400 16px/1.65 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#C9C6D8;">' . $e($s) . '</p>';
        $button = '';
        if ($btn) {
            $u = $e((string)$btn[1]);
            $button = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;"><tr><td bgcolor="#6F52F2" style="border-radius:12px;">'
                . '<a href="' . $u . '" target="_blank" style="display:inline-block;padding:15px 28px;font:600 16px/1 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#FFFFFF;text-decoration:none;border-radius:12px;">' . $e((string)$btn[0]) . ' &rarr;</a>'
                . '</td></tr></table>'
                . '<p style="margin:0 0 20px;font:400 13px/1.6 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#858298;">Düğme çalışmazsa bu bağlantıyı tarayıcınıza yapıştırın:<br><a href="' . $u . '" style="color:#A796FF;word-break:break-all;">' . $u . '</a></p>';
        }
        $noteHtml = $note ? '<p style="margin:8px 0 0;padding:14px 16px;border-radius:12px;background:#1B1B24;font:400 13.5px/1.6 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#9A97AB;">' . $e($note) . '</p>' : '';
        $html = '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="dark light"><meta name="supported-color-schemes" content="dark light"><title>' . $e($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#09090D;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $e($pre) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#09090D" style="background:#09090D;"><tr><td align="center" style="padding:32px 16px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">'
            // Üst: marka
            . '<tr><td style="padding:0 4px 20px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td style="font:700 24px/1 \'Space Grotesk\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;letter-spacing:3px;color:#F6F5FA;">DNL</td>'
            . '<td align="right" style="font:500 11px/1 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;letter-spacing:2px;color:#858298;">STATION</td>'
            . '</tr></table></td></tr>'
            // Kart
            . '<tr><td bgcolor="#111117" style="background:#111117;border:1px solid #24242E;border-radius:20px;overflow:hidden;">'
            . '<div style="height:4px;line-height:4px;font-size:0;background:#6F52F2;background-image:linear-gradient(90deg,#6F52F2,#2ED3E6);border-radius:20px 20px 0 0;">&nbsp;</div>'
            . '<div style="padding:36px 32px 32px;">'
            . '<h1 style="margin:0 0 20px;font:700 26px/1.25 \'Space Grotesk\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;letter-spacing:-0.5px;color:#F6F5FA;">' . $e($title) . '</h1>'
            . implode('', array_map($p, $intro)) . $button . implode('', array_map($p, $after)) . $noteHtml
            . '</div></td></tr>'
            // Alt bilgi
            . '<tr><td align="center" style="padding:24px 12px 0;font:400 12.5px/1.7 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;color:#6E6B80;">'
            . 'DNL Station · PlayStation ve oyun salonları için yönetim paneli<br>'
            . '<a href="' . $e($site) . '" style="color:#9A97AB;text-decoration:none;">' . $e(preg_replace('#^https?://#', '', $site) ?? $site) . '</a> · <a href="https://www.instagram.com/dnlstation/" style="color:#9A97AB;text-decoration:none;">@dnlstation</a><br>'
            . 'Destek: <a href="https://wa.me/905377620708" style="color:#9A97AB;text-decoration:none;">WhatsApp +90 537 762 07 08</a> · <a href="mailto:support@dnlstation.com.tr" style="color:#9A97AB;text-decoration:none;">support@dnlstation.com.tr</a><br>'
            . '<span style="color:#55525F;">Bu e-posta hesabınızla ilgili bir işlem nedeniyle gönderildi. © 2026 DNL Station · Bir YataGirişim Markasıdır.</span>'
            . '</td></tr></table></td></tr></table></body></html>';
        return [$text, $html];
    }
}
