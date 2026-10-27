<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * API çekirdeği. Her rota için güvenlik seçenekleri:
 *   auth  => rol listesi (boşsa giriş gerekmez)
 *   csrf  => durum değiştiren isteklerde CSRF zorunlu (varsayılan: true)
 *   write => aboneliği/denemesi bitmiş işletmede engellenir
 *   session => oturum başlatılsın mı (varsayılan: true)
 */
final class Router
{
    /** @var array<int,array{0:string,1:string,2:callable,3:array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $opts = []): void
    {
        $this->routes[] = [$method, $pattern, $handler, $opts];
    }

    public function dispatch(string $method, string $path): void
    {
        try {
            if (!Config::ready()) {
                throw new HttpError(503, 'Sistem henüz yapılandırılmadı. Kısa süre içinde hizmete açılacak.', 'not_configured');
            }
            try {
                Db::pdo();
            } catch (\PDOException $e) {
                Log::error($e, 'db-connect');
                throw new HttpError(503, 'Hizmete geçici olarak ulaşılamıyor. Lütfen biraz sonra tekrar deneyin.', 'db_unavailable');
            }

            $matched = null;
            $params = [];
            $allowed = [];
            foreach ($this->routes as $r) {
                $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[0-9]+)', $r[1]) . '$#';
                if (preg_match($regex, $path, $m)) {
                    if ($r[0] !== $method && !($method === 'HEAD' && $r[0] === 'GET')) {
                        $allowed[] = $r[0];
                        continue;
                    }
                    $matched = $r;
                    foreach ($m as $k => $v) {
                        if (is_string($k)) {
                            $params[$k] = (int)$v;
                        }
                    }
                    break;
                }
            }
            if ($matched === null) {
                if ($allowed) {
                    header('Allow: ' . implode(', ', array_unique($allowed)));
                    throw new HttpError(405, 'Yöntem desteklenmiyor.', 'method_not_allowed');
                }
                throw new HttpError(404, 'Bulunamadı.', 'not_found');
            }
            [$rm, , $handler, $opts] = $matched;

            if (($opts['migrations'] ?? true) && Migrator::pending() > 0) {
                throw new HttpError(503, 'Sistem güncelleniyor. Lütfen birkaç dakika sonra tekrar deneyin.', 'maintenance');
            }

            // Ara sıra süresi geçmiş sınır/anahtar kayıtlarını temizle (tablolar şişmesin)
            if (random_int(1, 200) === 1) {
                try {
                    Db::exec('DELETE FROM rate_limits WHERE reset_at < :n', ['n' => Db::now()]);
                    Db::exec('DELETE FROM auth_tokens WHERE expires_at < :n', ['n' => Db::now()]);
                } catch (\Throwable $e) {
                    Log::error($e, 'gc');
                }
            }

            // Bakım modu: yönetici ve sistem uçları dışındaki tüm istekler beklemeye alınır
            if (!str_starts_with($path, '/admin') && !str_starts_with($path, '/system') && \Dnl\Services\SystemSettings::get('maintenance')) {
                $msg = trim((string)\Dnl\Services\SystemSettings::get('maintenance_message'));
                throw new HttpError(503, $msg !== '' ? $msg : 'Kısa bir bakım çalışması yapıyoruz. Lütfen birkaç dakika sonra tekrar deneyin.', 'maintenance');
            }

            if ($opts['session'] ?? true) {
                Session::start();
            }

            $mutating = !in_array($rm, ['GET', 'HEAD', 'OPTIONS'], true);
            if ($mutating) {
                Http::assertSameOrigin();
                if ($opts['csrf'] ?? true) {
                    Session::checkCsrf(Http::header('X-CSRF-Token'));
                }
            }

            if (!empty($opts['admin'])) {
                $adm = AdminAuth::require();
                if ($mutating) {
                    RateLimiter::enforce('aw:' . $adm['id'], 300, 60, 'Çok hızlı işlem yapılıyor.');
                }
            }

            if (isset($opts['auth'])) {
                Auth::require($opts['auth']);
                $u = Auth::user();
                // Zorunlu şifre değişikliği bekleyen kullanıcı yalnızca izinli uçlara erişebilir
                if ((int)$u['must_change_password'] === 1 && empty($opts['allow_pw_change'])) {
                    throw new HttpError(403, 'Devam etmeden önce şifrenizi değiştirmeniz gerekiyor.', 'must_change_password');
                }
                // Dondurulan işletme: oturumlar kapatılır, yalnızca durum bilgisi (/me, çıkış) alınabilir
                $biz = Auth::business();
                if ($biz !== null && ($biz['suspended_at'] ?? null) !== null && empty($opts['allow_suspended'])) {
                    throw new HttpError(403, 'İşletme hesabınız askıya alındı. Destek için bizimle iletişime geçin.', 'suspended');
                }
                // Hesap başına yazma hızı sınırı (otomasyon/kötüye kullanım koruması)
                if ($mutating) {
                    RateLimiter::enforce('w:' . $u['id'], 600, 60, 'Çok hızlı işlem yapılıyor. Lütfen birkaç saniye bekleyin.');
                }
                if (($opts['write'] ?? false) && !\Dnl\Services\Subscription::active(Auth::business())) {
                    throw new HttpError(402, 'Deneme süreniz ya da paketiniz sona erdi. Devam etmek için paketinizi yenileyin.', 'subscription_expired');
                }
            }

            $result = $handler($params);
            Http::sendJson(200, ['ok' => true, 'data' => $result]);
        } catch (HttpError $e) {
            Http::sendJson($e->status, ['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'fields' => (object)$e->fields]]);
        } catch (\Throwable $e) {
            Log::error($e, $method . ' ' . $path);
            $msg = Config::get('debug') ? $e->getMessage() : 'Beklenmeyen bir hata oluştu. Lütfen tekrar deneyin.';
            Http::sendJson(500, ['ok' => false, 'error' => ['code' => 'server_error', 'message' => $msg, 'fields' => (object)[]]]);
        }
    }
}
