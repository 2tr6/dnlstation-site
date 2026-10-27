<?php
declare(strict_types=1);

namespace Dnl\Services;

defined('DNL_APP') || exit;

use Dnl\Core\Db;

/**
 * Deneme süresi hatırlatmaları. Her işletme için her aşama yalnızca bir kez gönderilir
 * (e-posta kaydındaki tür ile kontrol edilir). Ücretli, ömür boyu ve askıdaki işletmeler atlanır.
 */
final class TrialReminder
{
    /** @return array{soon:int, expired:int, skipped:int} */
    public static function run(): array
    {
        $now = Db::now();
        $in2 = gmdate('Y-m-d H:i:s', time() + 2 * 86400);
        $ago2 = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
        $out = ['soon' => 0, 'expired' => 0, 'skipped' => 0];

        // 2 gün içinde bitecek denemeler
        $soon = Db::all(
            "SELECT b.id, b.name, u.name AS owner_name, u.email FROM businesses b
             JOIN users u ON u.business_id = b.id AND u.role = 'owner'
             WHERE b.lifetime = 0 AND b.suspended_at IS NULL AND (b.paid_until IS NULL OR b.paid_until <= :n)
               AND b.trial_ends_at > :n AND b.trial_ends_at <= :in2 AND u.email_verified_at IS NOT NULL",
            ['n' => $now, 'in2' => $in2]);
        foreach ($soon as $r) {
            $out[self::send($r, 'soon') ? 'soon' : 'skipped']++;
        }

        // Son 2 gün içinde bitmiş ve ücretsiz kalmış denemeler
        $expired = Db::all(
            "SELECT b.id, b.name, u.name AS owner_name, u.email FROM businesses b
             JOIN users u ON u.business_id = b.id AND u.role = 'owner'
             WHERE b.lifetime = 0 AND b.suspended_at IS NULL AND (b.paid_until IS NULL OR b.paid_until <= :n)
               AND b.trial_ends_at <= :n AND b.trial_ends_at > :ago2 AND u.email_verified_at IS NOT NULL",
            ['n' => $now, 'ago2' => $ago2]);
        foreach ($expired as $r) {
            $out[self::send($r, 'expired') ? 'expired' : 'skipped']++;
        }
        return $out;
    }

    private static function send(array $r, string $mode): bool
    {
        $kind = $mode === 'expired' ? 'trial_expired' : 'trial_soon';
        $done = Db::value('SELECT id FROM email_log WHERE business_id = :b AND kind = :k AND status = :s LIMIT 1',
            ['b' => (int)$r['id'], 'k' => $kind, 's' => 'sent']);
        if ($done !== null) {
            return false; // bu aşama zaten gönderildi
        }
        return Emails::trialNotice((string)$r['email'], (string)$r['owner_name'], (string)$r['name'], $mode, (int)$r['id']);
    }
}
