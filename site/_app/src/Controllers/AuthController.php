<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Audit;
use Dnl\Core\Auth;
use Dnl\Core\Config;
use Dnl\Core\Db;
use Dnl\Core\Http;
use Dnl\Core\HttpError;
use Dnl\Core\Input;
use Dnl\Core\Mailer;
use Dnl\Core\RateLimiter;
use Dnl\Core\Session;
use Dnl\Core\Tokens;
use Dnl\Services\Perm;
use Dnl\Services\Subscription;

final class AuthController
{
    private const GENERIC_LOGIN_ERROR = 'E-posta veya şifre hatalı.';
    private const VERIFY_TTL = 172800;  // 48 saat
    private const RESET_TTL = 3600;     // 1 saat
    private const MAX_FAILS = 5;
    private const LOCK_MINUTES = 15;

    public static function csrf(): array
    {
        return ['csrf' => Session::csrf(), 'authenticated' => Auth::user() !== null];
    }

    public static function register(): array
    {
        RateLimiter::enforce('register:ip:' . Http::ip(), 5, 3600, 'Kısa sürede çok fazla kayıt denemesi yapıldı. Lütfen daha sonra tekrar deneyin.');
        $in = new Input(Http::json());
        $name = $in->str('name', 2, 80, true, 'Ad soyad');
        $business = $in->str('business', 2, 120, true, 'İşletme adı');
        $phone = $in->phone('phone');
        $email = $in->email('email');
        $password = $in->raw('password', 1, 128, 'Şifre');
        $confirm = $in->raw('password_confirm', 1, 128, 'Şifre tekrarı');
        $plan = $in->enum('plan', array_keys(Subscription::PLANS), 'pro', 'Paket');
        $period = $in->enum('period', ['aylik', 'yillik'], 'yillik', 'Ödeme dönemi');
        $types = array_values(array_intersect(['playstation', 'bilardo', 'okey', 'langirt', 'masa-tenisi', 'diger'], array_filter($in->arr('types'), 'is_string')));
        if (!$in->bool('accept')) {
            $in->error('accept', 'Devam etmek için kullanım şartlarını onaylamanız gerekiyor.');
        }
        if ($password !== '' && ($p = Auth::passwordProblem($password, $email, $name))) {
            $in->error('password', $p);
        }
        if ($password !== '' && $confirm !== '' && !hash_equals($password, $confirm)) {
            $in->error('password_confirm', 'Şifreler eşleşmiyor.');
        }
        $in->check();

        if (Db::value('SELECT id FROM users WHERE email = :e', ['e' => $email]) !== null) {
            throw new HttpError(422, 'Lütfen işaretli alanları kontrol edin.', 'validation', [
                'email' => 'Bu e-posta adresiyle yeni hesap oluşturulamıyor. Hesabınız varsa giriş yapın ya da şifrenizi sıfırlayın.',
            ]);
        }

        $now = Db::now();
        $trialDays = (int)Config::get('trial_days', 14);
        $hash = Auth::hash($password);
        try {
            [$bid, $uid] = Db::tx(static function () use ($business, $phone, $plan, $period, $types, $now, $trialDays, $name, $email, $hash) {
                $bid = Db::insert('businesses', [
                    'name' => $business,
                    'phone' => $phone,
                    'plan' => $plan,
                    'billing_period' => $period,
                    'trial_ends_at' => gmdate('Y-m-d H:i:s', time() + $trialDays * 86400),
                    'paid_until' => null,
                    'timezone' => 'Europe/Istanbul',
                    'settings' => json_encode(['types' => $types, 'show_prices_on_display' => true, 'show_free_on_display' => true, 'warn_minutes' => 5, 'staff_can_discount' => false], JSON_UNESCAPED_UNICODE),
                    'display_token_hash' => null,
                    'open_shift_id' => null,
                    'setup_done' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $uid = Db::insert('users', [
                    'business_id' => $bid,
                    'name' => $name,
                    'email' => $email,
                    'phone' => '',
                    'password_hash' => $hash,
                    'role' => 'owner',
                    'email_verified_at' => null,
                    'must_change_password' => 0,
                    'is_active' => 1,
                    'session_version' => 1,
                    'failed_logins' => 0,
                    'locked_until' => null,
                    'last_login_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return [$bid, $uid];
            });
        } catch (\PDOException $e) {
            if (Db::isDuplicate($e)) {
                throw new HttpError(422, 'Lütfen işaretli alanları kontrol edin.', 'validation', ['email' => 'Bu e-posta adresiyle yeni hesap oluşturulamıyor.']);
            }
            throw $e;
        }

        self::sendVerification($uid, $email, $name);
        $user = Db::one('SELECT * FROM users WHERE id = :id', ['id' => $uid]);
        Auth::login($user, false);
        Audit::log($bid, $uid, 'auth.register', 'business', $bid);
        return ['redirect' => '/panel/'];
    }

    public static function login(): array
    {
        RateLimiter::enforce('login:ip:' . Http::ip(), 30, 900);
        $in = new Input(Http::json());
        $email = $in->email('email');
        $password = $in->raw('password', 1, 128, 'Şifre');
        $remember = $in->bool('remember');
        $in->check();

        $user = Db::one('SELECT * FROM users WHERE email = :e', ['e' => $email]);
        $now = Db::now();
        if ($user && $user['locked_until'] !== null && $user['locked_until'] > $now) {
            throw new HttpError(429, 'Çok fazla hatalı deneme yapıldı. Lütfen ' . self::LOCK_MINUTES . ' dakika sonra tekrar deneyin ya da şifrenizi sıfırlayın.', 'locked');
        }
        // Kullanıcı yoksa da hash doğrulaması yapılır: yanıt süresinden hesap varlığı anlaşılmasın
        $dummy = defined('PASSWORD_ARGON2ID')
            ? '$argon2id$v=19$m=65536,t=4,p=1$Y3ZEaHJHOGoxZVVFdVRzag$a1tz5reUMz8l+R4M9dVL/VDei7caKTbMWz6o0FqcVyc'
            : '$2y$10$NrvZT78CPPrEuPXxIfxvH.Im644.2viE6gTXlpHIM48bGQMKF0/ii';
        $hash = $user['password_hash'] ?? $dummy;
        $okPw = password_verify($password, $hash);
        if (!$user || !$okPw || (int)$user['is_active'] !== 1) {
            if ($user) {
                $fails = (int)$user['failed_logins'] + 1;
                $lock = $fails >= self::MAX_FAILS ? gmdate('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
                Db::update('users', ['failed_logins' => $lock ? 0 : $fails, 'locked_until' => $lock, 'updated_at' => $now], ['id' => (int)$user['id']]);
                if ($lock) {
                    Audit::log((int)$user['business_id'], (int)$user['id'], 'auth.locked', 'user', (int)$user['id']);
                }
            }
            throw new HttpError(401, self::GENERIC_LOGIN_ERROR, 'invalid_credentials');
        }
        if (Config::get('require_email_verification') && $user['email_verified_at'] === null) {
            throw new HttpError(403, 'Giriş yapmadan önce e-posta adresinizi doğrulayın. Doğrulama bağlantısı e-postanıza gönderildi.', 'email_unverified');
        }
        if (Auth::needsRehash($user['password_hash'])) {
            Db::update('users', ['password_hash' => Auth::hash($password)], ['id' => (int)$user['id']]);
        }
        Auth::login($user, $remember);
        Audit::log((int)$user['business_id'], (int)$user['id'], 'auth.login', 'user', (int)$user['id']);
        return ['redirect' => (int)$user['must_change_password'] === 1 ? '/panel/#/hesap' : '/panel/'];
    }

    public static function logout(): array
    {
        $u = Auth::user();
        if ($u) {
            Audit::log((int)$u['business_id'], (int)$u['id'], 'auth.logout', 'user', (int)$u['id']);
        }
        Auth::logout();
        return ['redirect' => '/giris/'];
    }

    public static function forgot(): array
    {
        RateLimiter::enforce('forgot:ip:' . Http::ip(), 10, 3600);
        $in = new Input(Http::json());
        $email = $in->email('email');
        $in->check();
        // E-posta başına sessiz sınır: aşılırsa e-posta gönderilmez ama yanıt aynı kalır
        if (RateLimiter::hit('forgot:email:' . $email, 3, 3600)) {
            $user = Db::one('SELECT id, name, email, is_active, business_id FROM users WHERE email = :e', ['e' => $email]);
            if ($user && (int)$user['is_active'] === 1) {
                Tokens::revokeAll((int)$user['id'], 'reset');
                [$sel, $val] = Tokens::create((int)$user['id'], 'reset', self::RESET_TTL);
                $link = Mailer::url('/sifre-yenile/#t=' . $sel . '.' . $val);
                Mailer::send($user['email'], 'DNL Station şifre sıfırlama', "Merhaba {$user['name']},\n\nŞifrenizi sıfırlamak için aşağıdaki bağlantıyı kullanın. Bağlantı 1 saat geçerlidir ve yalnızca bir kez kullanılabilir.\n\n$link\n\nBu isteği siz yapmadıysanız bu e-postayı yok sayabilirsiniz; şifreniz değişmez.\n\nDNL Station");
                Audit::log((int)$user['business_id'], (int)$user['id'], 'auth.reset_requested', 'user', (int)$user['id']);
            }
        }
        return ['message' => 'Bu e-posta adresi kayıtlıysa, şifre sıfırlama bağlantısı birkaç dakika içinde gönderilecek.'];
    }

    public static function reset(): array
    {
        RateLimiter::enforce('reset:ip:' . Http::ip(), 20, 3600);
        $in = new Input(Http::json());
        $token = $in->raw('token', 1, 200, 'Bağlantı');
        $password = $in->raw('password', 1, 128, 'Şifre');
        $confirm = $in->raw('password_confirm', 1, 128, 'Şifre tekrarı');
        $in->check();
        $parts = Tokens::split($token);
        if ($parts === null) {
            throw new HttpError(400, 'Bağlantı geçersiz ya da süresi dolmuş. Yeni bir sıfırlama bağlantısı isteyin.', 'bad_token');
        }
        // Önce anahtarı harcamadan sahibini bul; şifre kurallarını kontrol et, sonra harca
        $row = Db::one("SELECT t.user_id, u.email, u.name FROM auth_tokens t JOIN users u ON u.id = t.user_id WHERE t.selector = :s AND t.type = 'reset' AND t.used_at IS NULL AND t.expires_at > :n", ['s' => $parts[0], 'n' => Db::now()]);
        if (!$row) {
            throw new HttpError(400, 'Bağlantı geçersiz ya da süresi dolmuş. Yeni bir sıfırlama bağlantısı isteyin.', 'bad_token');
        }
        $in2 = new Input([]);
        if ($p = Auth::passwordProblem($password, $row['email'], $row['name'])) {
            $in2->error('password', $p);
        }
        if (!hash_equals($password, $confirm)) {
            $in2->error('password_confirm', 'Şifreler eşleşmiyor.');
        }
        $in2->check();
        $tok = Tokens::consume($parts[0], $parts[1], 'reset');
        if ($tok === null) {
            throw new HttpError(400, 'Bağlantı geçersiz ya da süresi dolmuş. Yeni bir sıfırlama bağlantısı isteyin.', 'bad_token');
        }
        $uid = (int)$tok['user_id'];
        $now = Db::now();
        Db::exec('UPDATE users SET password_hash = :h, must_change_password = 0, failed_logins = 0, locked_until = NULL, email_verified_at = COALESCE(email_verified_at, :n), updated_at = :n WHERE id = :id', ['h' => Auth::hash($password), 'n' => $now, 'id' => $uid]);
        Auth::invalidateAllSessions($uid);
        Tokens::revokeAll($uid, 'reset');
        $bid = (int)Db::value('SELECT business_id FROM users WHERE id = :id', ['id' => $uid]);
        Audit::log($bid, $uid, 'auth.password_reset', 'user', $uid);
        return ['redirect' => '/giris/?sifre=yenilendi'];
    }

    public static function verify(): array
    {
        RateLimiter::enforce('verify:ip:' . Http::ip(), 30, 3600);
        $in = new Input(Http::json());
        $token = $in->raw('token', 1, 200, 'Bağlantı');
        $in->check();
        $parts = Tokens::split($token);
        $tok = $parts ? Tokens::consume($parts[0], $parts[1], 'verify') : null;
        if ($tok === null) {
            throw new HttpError(400, 'Doğrulama bağlantısı geçersiz ya da süresi dolmuş. Panelden yeni bağlantı isteyebilirsiniz.', 'bad_token');
        }
        Db::exec('UPDATE users SET email_verified_at = COALESCE(email_verified_at, :n), updated_at = :n WHERE id = :id', ['n' => Db::now(), 'id' => (int)$tok['user_id']]);
        Tokens::revokeAll((int)$tok['user_id'], 'verify');
        return ['message' => 'E-posta adresiniz doğrulandı.'];
    }

    public static function resendVerification(): array
    {
        $u = Auth::require();
        RateLimiter::enforce('verify-resend:' . $u['id'], 3, 3600, 'Kısa süre önce doğrulama e-postası gönderildi. Lütfen gelen kutunuzu ve spam klasörünü kontrol edin.');
        if ($u['email_verified_at'] === null) {
            self::sendVerification((int)$u['id'], $u['email'], $u['name']);
        }
        return ['message' => 'Doğrulama e-postası gönderildi.'];
    }

    public static function me(): array
    {
        $u = Auth::require();
        $b = Auth::business();
        $settings = json_decode((string)($b['settings'] ?? ''), true) ?: [];
        return [
            'user' => [
                'id' => (int)$u['id'],
                'name' => $u['name'],
                'email' => $u['email'],
                'role' => $u['role'],
                'verified' => $u['email_verified_at'] !== null,
                'must_change_password' => (int)$u['must_change_password'] === 1,
            ],
            'business' => [
                'id' => (int)$b['id'],
                'name' => $b['name'],
                'phone' => $b['phone'],
                'setup_done' => (int)$b['setup_done'] === 1,
                'open_shift_id' => $b['open_shift_id'] !== null ? (int)$b['open_shift_id'] : null,
                'types' => $settings['types'] ?? [],
                'warn_minutes' => (int)($settings['warn_minutes'] ?? 5),
            ],
            'subscription' => Subscription::status($b),
            'permissions' => Perm::list($u, $b),
            'csrf' => Session::csrf(),
            'server_time' => gmdate('c'),
        ];
    }

    public static function changePassword(): array
    {
        $u = Auth::require();
        RateLimiter::enforce('pwchange:' . $u['id'], 10, 3600);
        $in = new Input(Http::json());
        $current = $in->raw('current_password', 1, 128, 'Mevcut şifre');
        $password = $in->raw('password', 1, 128, 'Yeni şifre');
        $confirm = $in->raw('password_confirm', 1, 128, 'Şifre tekrarı');
        $in->check();
        $in2 = new Input([]);
        if (!password_verify($current, $u['password_hash'])) {
            $in2->error('current_password', 'Mevcut şifre hatalı.');
        }
        if ($p = Auth::passwordProblem($password, $u['email'], $u['name'])) {
            $in2->error('password', $p);
        } elseif (password_verify($password, $u['password_hash'])) {
            $in2->error('password', 'Yeni şifre eskisiyle aynı olamaz.');
        }
        if (!hash_equals($password, $confirm)) {
            $in2->error('password_confirm', 'Şifreler eşleşmiyor.');
        }
        $in2->check();
        Db::update('users', ['password_hash' => Auth::hash($password), 'must_change_password' => 0, 'updated_at' => Db::now()], ['id' => (int)$u['id']]);
        Auth::invalidateAllSessions((int)$u['id']);
        // Mevcut cihazda oturumu yeni sürümle sürdür
        $fresh = Db::one('SELECT * FROM users WHERE id = :id', ['id' => $u['id']]);
        Auth::login($fresh, false);
        Audit::log((int)$u['business_id'], (int)$u['id'], 'auth.password_changed', 'user', (int)$u['id']);
        return ['message' => 'Şifreniz güncellendi. Diğer cihazlardaki oturumlar kapatıldı.', 'csrf' => Session::csrf()];
    }

    private static function sendVerification(int $uid, string $email, string $name): void
    {
        Tokens::revokeAll($uid, 'verify');
        [$sel, $val] = Tokens::create($uid, 'verify', self::VERIFY_TTL);
        $link = Mailer::url('/dogrula/#t=' . $sel . '.' . $val);
        Mailer::send($email, 'DNL Station e-posta doğrulama', "Merhaba $name,\n\nDNL Station hesabınızı oluşturduğunuz için teşekkürler. E-posta adresinizi doğrulamak için aşağıdaki bağlantıyı açın (48 saat geçerlidir):\n\n$link\n\nBu hesabı siz oluşturmadıysanız bu e-postayı yok sayabilirsiniz.\n\nDNL Station");
    }
}
