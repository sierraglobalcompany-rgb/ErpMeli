<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

final class AuditService
{
    public static function record(string $action, string $module, ?string $entityType = null, ?int $entityId = null, ?int $accountId = null, ?array $before = null, ?array $after = null): void
    {
        self::recordForUser(Auth::id(), $action, $module, $entityType, $entityId, $accountId, $before, $after);
    }

    public static function recordForUser(?int $userId, string $action, string $module, ?string $entityType = null, ?int $entityId = null, ?int $accountId = null, ?array $before = null, ?array $after = null): void
    {
        $stmt = Database::connection()->prepare('INSERT INTO audit_logs (user_id,action,module,entity_type,entity_id,meli_account_id,ip_hash,before_json,after_json) VALUES (:user,:action,:module,:type,:entity,:account,:ip,:before,:after)');
        $stmt->execute([
            'user' => $userId,
            'action' => $action,
            'module' => $module,
            'type' => $entityType,
            'entity' => $entityId,
            'account' => $accountId,
            'ip' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli')),
            'before' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after' => $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
