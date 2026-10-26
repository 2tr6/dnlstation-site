<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/** Denetim kaydı: kim, ne zaman, neyi yaptı. IP yalnızca özet olarak tutulur. */
final class Audit
{
    public static function log(?int $businessId, ?int $userId, string $action, string $entity = '', ?int $entityId = null, array $meta = []): void
    {
        try {
            Db::insert('audit_logs', [
                'business_id' => $businessId,
                'user_id' => $userId,
                'action' => substr($action, 0, 40),
                'entity' => substr($entity, 0, 30),
                'entity_id' => $entityId,
                'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'ip_hash' => substr(hash_hmac('sha256', Http::ip(), Config::key() ?: 'dnl'), 0, 16),
                'created_at' => Db::now(),
            ]);
        } catch (\Throwable $e) {
            Log::error($e, 'audit');
        }
    }
}
