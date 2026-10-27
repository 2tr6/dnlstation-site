<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\AdminAuth;
use Dnl\Core\Config;
use Dnl\Core\Db;
use Dnl\Core\Http;
use Dnl\Core\HttpError;
use Dnl\Services\Backup;
use Dnl\Core\Input;
use Dnl\Core\Mailer;
use Dnl\Core\Sms;
use Dnl\Core\Migrator;
use Dnl\Services\SystemSettings;

/** Yönetici: sistem durumu, testler, bakım modu, e-posta / güvenlik / hata kayıtları, otomatik uyarılar */
final class AdminSystemController
{
    private static function logFiles(): array
    {
        $dir = (string)Config::get('log_dir', DNL_ROOT . '/storage/logs');
        $files = glob($dir . '/app-*.log') ?: [];
        rsort($files, SORT_STRING);
        return $files;
    }

    /** Son N hata satırı (yeniden eskiye) */
    private static function tailErrors(int $limit): array
    {
        $out = [];
        foreach (self::logFiles() as $f) {
            $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
                if (preg_match('/^\[([^\]]+)\]\s+(\S*)\s+([^:]+):\s(.*)$/u', $lines[$i], $m)) {
                    $out[] = ['at' => $m[1], 'context' => $m[2], 'type' => $m[3], 'message' => mb_substr($m[4], 0, 500)];
                } else {
                    $out[] = ['at' => '', 'context' => '', 'type' => '', 'message' => mb_substr($lines[$i], 0, 500)];
                }
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Panoda gösterilen otomatik kontroller: yöneticinin arka planda bir şeye bakmasına gerek kalmasın */
    public static function alerts(): array
    {
        $a = [];
        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        $now = Db::now();
        $add = static function (string $level, string $title, string $text, string $link = '') use (&$a): void {
            $a[] = ['level' => $level, 'title' => $title, 'text' => $text, 'link' => $link];
        };
        if (Config::get('debug')) {
            $add('bad', 'Hata ayıklama modu açık', 'Ayar dosyasında debug=true; hata ayrıntıları kullanıcılara görünebilir. Kapatın.', '#/sistem');
        }
        if (SystemSettings::get('maintenance')) {
            $add('warn', 'Bakım modu açık', 'İşletmeler şu an panele erişemiyor.', '#/sistem');
        }
        if (Migrator::pending() > 0) {
            $add('bad', 'Bekleyen veritabanı güncellemesi', 'Terminal: bash public_html/_app/kurulum.sh', '#/sistem');
        }
        if (Config::get('mail.transport', 'mail') !== 'smtp') {
            $add('warn', 'E-posta SMTP ile gönderilmiyor', 'Teslim oranı için: bash public_html/_app/eposta.sh', '#/sistem');
        }
        $failedMail = (int)Db::value("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND created_at >= :s", ['s' => $since]);
        if ($failedMail > 0) {
            $add('bad', "$failedMail e-posta gönderilemedi (24 saat)", 'Ayrıntılar E-posta kayıtlarında.', '#/eposta');
        }
        $errors = 0;
        foreach (self::tailErrors(200) as $e) {
            if ($e['at'] !== '' && strtotime($e['at']) >= time() - 86400) {
                $errors++;
            }
        }
        if ($errors > 0) {
            $add('bad', "$errors sistem hatası (24 saat)", 'Ayrıntılar Sistem kayıtlarında.', '#/sistem');
        }
        $fails = (int)Db::value("SELECT COUNT(*) FROM security_events WHERE type IN ('login_failed','admin_login_failed') AND created_at >= :s", ['s' => $since]);
        if ($fails >= 20) {
            $add('warn', "$fails hatalı giriş denemesi (24 saat)", 'Olağan dışı bir artış olabilir; Güvenlik kayıtlarına bakın.', '#/guvenlik');
        }
        $adminFails = (int)Db::value("SELECT COUNT(*) FROM security_events WHERE type IN ('admin_login_failed','admin_locked') AND created_at >= :s", ['s' => $since]);
        if ($adminFails > 0) {
            $add('bad', "Yönetici paneline $adminFails hatalı giriş denemesi", 'Siz değilseniz şifrenizi değiştirin.', '#/guvenlik');
        }
        $locked = (int)Db::value('SELECT COUNT(*) FROM users WHERE locked_until > :n', ['n' => $now]);
        if ($locked > 0) {
            $add('warn', "$locked kullanıcı hesabı kilitli", 'Çok sayıda hatalı giriş nedeniyle geçici kilit.', '#/guvenlik');
        }
        $free = @disk_free_space(DNL_ROOT);
        if ($free !== false && $free < 500 * 1024 * 1024) {
            $add('bad', 'Disk alanı azaldı', 'Boş alan: ' . round($free / 1048576) . ' MB', '#/sistem');
        }
        $expired = (int)Db::value('SELECT COUNT(*) FROM businesses WHERE suspended_at IS NULL AND lifetime = 0 AND (paid_until IS NULL OR paid_until <= :n) AND trial_ends_at <= :n2 AND trial_ends_at >= :s', ['n' => $now, 'n2' => $now, 's' => gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
        if ($expired > 0) {
            $add('info', "$expired işletmenin süresi son 7 günde doldu", 'Ödeme için iletişime geçebilirsiniz.', '#/isletmeler?d=expired');
        }
        return $a;
    }

    public static function sms(): array
    {
        AdminAuth::require();
        $items = Db::all('SELECT id, to_phone, kind, status, error, business_id, created_at FROM sms_log ORDER BY id DESC LIMIT 100');
        $stats = Db::one("SELECT SUM(CASE WHEN status = 'sent' AND created_at >= :d THEN 1 ELSE 0 END) AS sent_24h,
            SUM(CASE WHEN status = 'failed' AND created_at >= :d THEN 1 ELSE 0 END) AS failed_24h FROM sms_log", ['d' => gmdate('Y-m-d H:i:s', time() - 86400)]) ?? [];
        return [
            'provider' => Sms::provider(), 'configured' => Sms::configured(), 'signup_otp' => Config::get('sms.signup_otp', false) === true,
            'stats' => ['sent_24h' => (int)($stats['sent_24h'] ?? 0), 'failed_24h' => (int)($stats['failed_24h'] ?? 0)],
            'items' => array_map(static fn($r) => ['id' => (int)$r['id'], 'to' => $r['to_phone'], 'kind' => $r['kind'], 'status' => $r['status'], 'error' => $r['error'], 'business_id' => $r['business_id'] ? (int)$r['business_id'] : null, 'at' => Db::iso($r['created_at'])], $items),
        ];
    }

    /** Yedek listesi ve tutulan adet */
    public static function backups(): array
    {
        AdminAuth::require();
        return ['items' => Backup::list(), 'keep' => Backup::KEEP];
    }

    /** Yedek alır (manuel) */
    public static function backupRun(): array
    {
        $aid = AdminAuth::id();
        $r = Backup::run();
        AdminAuth::log($aid, 'system.backup', 'system', null, ['file' => $r['name'], 'rows' => $r['rows']]);
        return ['message' => "Yedek alındı: {$r['name']} ({$r['rows']} satır).", 'items' => Backup::list(), 'keep' => Backup::KEEP];
    }

    /** Seçilen yedeği siler (ad JSON gövdesinde; yalnızca bizim biçimimiz kabul edilir) */
    public static function backupDelete(): array
    {
        $aid = AdminAuth::id();
        $in = new Input(Http::json());
        $name = $in->str('name', 10, 60, true, 'Yedek');
        $in->check();
        Backup::delete($name);
        AdminAuth::log($aid, 'system.backup_delete', 'system', null, ['file' => $name]);
        return ['message' => 'Yedek silindi.', 'items' => Backup::list(), 'keep' => Backup::KEEP];
    }

    public static function testSms(): array
    {
        $aid = AdminAuth::id();
        $in = new Input(Http::json());
        $to = $in->phone('to');
        $in->check();
        $ok = Sms::send($to, 'DNL Station SMS testi. Bu mesajı aldıysanız SMS altyapısı çalışıyor.', 'test');
        AdminAuth::log($aid, 'system.test_sms', 'system', null, ['ok' => $ok]);
        if (!$ok) {
            throw new HttpError(502, 'SMS gönderilemedi: ' . (Sms::$lastError ?: 'bilinmeyen hata'), 'sms_failed');
        }
        return ['ok' => true, 'message' => "$to numarasına test SMS'i gönderildi."];
    }

    public static function status(): array
    {
        AdminAuth::require();
        $checks = [];
        $c = static function (string $name, bool $ok, string $detail, string $level = '') use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'level' => $level ?: ($ok ? 'ok' : 'bad'), 'detail' => $detail];
        };
        $t0 = microtime(true);
        Db::value('SELECT 1');
        $ms = round((microtime(true) - $t0) * 1000, 1);
        $c('Veritabanı bağlantısı', true, Db::driver() . " · {$ms} ms");
        $pending = Migrator::pending();
        $c('Veritabanı şeması', $pending === 0, $pending === 0 ? 'Güncel' : "$pending bekleyen güncelleme");
        $c('PHP sürümü', PHP_VERSION_ID >= 80100, PHP_VERSION);
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'json'] as $ext) {
            $ok = extension_loaded($ext) || ($ext === 'pdo_mysql' && Db::driver() === 'sqlite');
            $c("PHP eklentisi: $ext", $ok, $ok ? 'Yüklü' : 'Eksik');
        }
        $c('Şifreleme (Argon2id)', defined('PASSWORD_ARGON2ID'), defined('PASSWORD_ARGON2ID') ? 'Kullanılıyor' : 'bcrypt yedeği kullanılıyor', defined('PASSWORD_ARGON2ID') ? 'ok' : 'warn');
        $c('Hata ayıklama modu', !Config::get('debug'), Config::get('debug') ? 'AÇIK – üretimde kapatın' : 'Kapalı');
        $c('HTTPS', Http::isHttps(), Http::isHttps() ? 'Bağlantı şifreli' : 'Bu istek HTTPS değil', Http::isHttps() ? 'ok' : 'warn');
        $transport = (string)Config::get('mail.transport', 'mail');
        $c('E-posta gönderimi', $transport === 'smtp', $transport === 'smtp' ? 'SMTP · ' . (((array)Config::get('mail.smtp', []))['host'] ?? '') : "Taşıyıcı: $transport", $transport === 'smtp' ? 'ok' : 'warn');
        $smsOn = Sms::configured();
        $c('SMS gönderimi', $smsOn, $smsOn ? 'NetGSM · ' . Sms::provider() : (Sms::provider() === 'off' ? 'Kapalı (sms ayarları girilmedi)' : 'Ayarlar eksik'), $smsOn ? 'ok' : 'warn');
        $logDir = (string)Config::get('log_dir', DNL_ROOT . '/storage/logs');
        $w = is_dir($logDir) ? is_writable($logDir) : is_writable(dirname($logDir));
        $c('Günlük klasörü yazılabilir', $w, $w ? 'Evet' : 'Hayır');
        $free = @disk_free_space(DNL_ROOT);
        $c('Boş disk alanı', $free === false || $free > 500 * 1024 * 1024, $free === false ? 'Okunamadı' : round($free / 1073741824, 2) . ' GB', $free === false ? 'warn' : '');
        $c('Bakım modu', !SystemSettings::get('maintenance'), SystemSettings::get('maintenance') ? 'AÇIK' : 'Kapalı', SystemSettings::get('maintenance') ? 'warn' : 'ok');
        $tables = [];
        foreach (['businesses', 'users', 'play_sessions', 'tickets', 'payments', 'products', 'customers', 'audit_logs', 'email_log', 'sms_log', 'security_events', 'rate_limits', 'auth_tokens'] as $t) {
            $tables[] = ['table' => $t, 'rows' => (int)Db::value("SELECT COUNT(*) FROM $t")];
        }
        return [
            'checks' => $checks, 'tables' => $tables,
            'server' => ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'time_utc' => gmdate('c'), 'timezone' => 'Europe/Istanbul', 'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time'), 'upload_max_filesize' => ini_get('upload_max_filesize')],
            'maintenance' => ['on' => (bool)SystemSettings::get('maintenance'), 'message' => (string)SystemSettings::get('maintenance_message')],
            'errors' => self::tailErrors(100),
        ];
    }

    public static function testEmail(): array
    {
        $aid = AdminAuth::id();
        $in = new Input(Http::json());
        $to = $in->email('to');
        $in->check();
        [$text, $html] = \Dnl\Services\Emails::test($to);
        $ok = Mailer::send($to, 'DNL Station e-posta testi', $text, $html, 'test');
        AdminAuth::log($aid, 'system.test_email', 'system', null, ['to' => $to, 'ok' => $ok]);
        if (!$ok) {
            throw new HttpError(502, 'Gönderilemedi: ' . (Mailer::$lastError ?: 'bilinmeyen hata'), 'mail_failed');
        }
        return ['ok' => true, 'message' => "$to adresine test e-postası gönderildi."];
    }

    public static function gc(): array
    {
        $aid = AdminAuth::id();
        $n = Db::now();
        $r = Db::exec('DELETE FROM rate_limits WHERE reset_at < :n', ['n' => $n]);
        $t = Db::exec('DELETE FROM auth_tokens WHERE expires_at < :n', ['n' => $n]);
        $old = gmdate('Y-m-d H:i:s', time() - 180 * 86400);
        $s = Db::exec('DELETE FROM security_events WHERE created_at < :o', ['o' => $old]);
        $e = Db::exec('DELETE FROM email_log WHERE created_at < :o', ['o' => $old]);
        AdminAuth::log($aid, 'system.gc', 'system', null, ['rate_limits' => $r, 'tokens' => $t, 'security' => $s, 'email' => $e]);
        return ['message' => "Temizlendi: $r sınır kaydı, $t süresi geçmiş anahtar, 180 günden eski $s güvenlik ve $e e-posta kaydı."];
    }

    public static function maintenance(): array
    {
        $aid = AdminAuth::id();
        $in = new Input(Http::json());
        $on = $in->bool('on');
        $msg = $in->str('message', 0, 200, false, 'Mesaj');
        $in->check();
        SystemSettings::save(['maintenance' => $on, 'maintenance_message' => $msg]);
        AdminAuth::log($aid, $on ? 'system.maintenance_on' : 'system.maintenance_off', 'system', null, ['message' => $msg]);
        return ['maintenance' => ['on' => $on, 'message' => $msg]];
    }

    public static function clearErrors(): array
    {
        $aid = AdminAuth::id();
        $n = 0;
        foreach (self::logFiles() as $f) {
            if (@unlink($f)) {
                $n++;
            }
        }
        AdminAuth::log($aid, 'system.logs_cleared', 'system', null, ['files' => $n]);
        return ['message' => "$n günlük dosyası temizlendi."];
    }

    public static function emails(): array
    {
        AdminAuth::require();
        $st = (string)(Http::query('status') ?? '');
        $q = trim(mb_substr((string)(Http::query('q') ?? ''), 0, 80));
        $before = (int)(Http::query('before') ?? 0);
        $w = '1=1';
        $p = [];
        if (in_array($st, ['sent', 'failed'], true)) {
            $w .= ' AND e.status = :s';
            $p['s'] = $st;
        }
        if ($q !== '') {
            $w .= " AND LOWER(e.to_email) LIKE :q ESCAPE '!'";
            $p['q'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)) . '%';
        }
        if ($before > 0) {
            $w .= ' AND e.id < :x';
            $p['x'] = $before;
        }
        $rows = Db::all("SELECT e.*, b.name AS business_name FROM email_log e LEFT JOIN businesses b ON b.id = e.business_id WHERE $w ORDER BY e.id DESC LIMIT 100", $p);
        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        return [
            'stats' => ['sent_24h' => (int)Db::value("SELECT COUNT(*) FROM email_log WHERE status = 'sent' AND created_at >= :s", ['s' => $since]),
                'failed_24h' => (int)Db::value("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND created_at >= :s", ['s' => $since]),
                'total' => (int)Db::value('SELECT COUNT(*) FROM email_log')],
            'items' => array_map(static fn($r) => ['id' => (int)$r['id'], 'to' => $r['to_email'], 'subject' => $r['subject'], 'kind' => $r['kind'], 'status' => $r['status'], 'error' => $r['error'],
                'business_id' => $r['business_id'] !== null ? (int)$r['business_id'] : null, 'business' => $r['business_name'], 'at' => Db::iso($r['created_at'])], $rows),
        ];
    }

    public static function security(): array
    {
        AdminAuth::require();
        $type = (string)(Http::query('type') ?? '');
        $before = (int)(Http::query('before') ?? 0);
        $w = '1=1';
        $p = [];
        if ($type !== '' && preg_match('/^[a-z_]{2,40}$/', $type)) {
            $w .= ' AND s.type = :t';
            $p['t'] = $type;
        }
        if ($before > 0) {
            $w .= ' AND s.id < :x';
            $p['x'] = $before;
        }
        $rows = Db::all("SELECT s.*, b.name AS business_name FROM security_events s LEFT JOIN businesses b ON b.id = s.business_id WHERE $w ORDER BY s.id DESC LIMIT 100", $p);
        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        $byType = Db::all('SELECT type, COUNT(*) AS c FROM security_events WHERE created_at >= :s GROUP BY type ORDER BY c DESC', ['s' => $since]);
        $topIp = Db::all("SELECT ip_hash, COUNT(*) AS c FROM security_events WHERE created_at >= :s AND type NOT IN ('login_ok','admin_login_ok') GROUP BY ip_hash ORDER BY c DESC LIMIT 5", ['s' => $since]);
        $locked = Db::all('SELECT u.id, u.name, u.email, u.locked_until, b.id AS bid, b.name AS business FROM users u JOIN businesses b ON b.id = u.business_id WHERE u.locked_until > :n', ['n' => Db::now()]);
        return [
            'by_type_24h' => array_map(static fn($r) => ['type' => $r['type'], 'count' => (int)$r['c']], $byType),
            'top_sources_24h' => array_map(static fn($r) => ['ip_hash' => $r['ip_hash'], 'count' => (int)$r['c']], $topIp),
            'locked_users' => array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'until' => Db::iso($r['locked_until']), 'business_id' => (int)$r['bid'], 'business' => $r['business']], $locked),
            'items' => array_map(static fn($r) => ['id' => (int)$r['id'], 'type' => $r['type'], 'actor' => $r['actor'], 'ip_hash' => $r['ip_hash'], 'ua' => $r['user_agent'], 'meta' => $r['meta'],
                'business_id' => $r['business_id'] !== null ? (int)$r['business_id'] : null, 'business' => $r['business_name'], 'at' => Db::iso($r['created_at'])], $rows),
        ];
    }

    /** Tüm işletmelerin işlem akışı (son 100) */
    public static function activity(): array
    {
        AdminAuth::require();
        $before = (int)(Http::query('before') ?? 0);
        $rows = Db::all('SELECT a.*, u.name AS user_name, b.name AS business_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN businesses b ON b.id = a.business_id' . ($before > 0 ? ' WHERE a.id < :x' : '') . ' ORDER BY a.id DESC LIMIT 100', $before > 0 ? ['x' => $before] : []);
        return ['items' => array_map(static fn($r) => ['id' => (int)$r['id'], 'action' => $r['action'], 'meta' => json_decode((string)$r['meta'], true), 'user' => $r['user_name'],
            'business_id' => $r['business_id'] !== null ? (int)$r['business_id'] : null, 'business' => $r['business_name'], 'at' => Db::iso($r['created_at'])], $rows)];
    }
}
