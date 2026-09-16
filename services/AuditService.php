<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class AuditService
{
    public static function log(
        PDO $pdo,
        ?int $userId,
        string $usernameSnapshot,
        string $actionCode,
        string $entityType,
        ?int $entityId,
        ?array $before,
        ?array $after,
        ?string $reason = null,
        ?string $ipAddress = null
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs
                (user_id, username_snapshot, action_code, entity_type, entity_id, ip_address, before_data, after_data, reason, created_at)
             VALUES (:user_id, :username, :action, :entity_type, :entity_id, :ip, :before, :after, :reason, :created_at)'
        );
        $stmt->execute([
            'user_id'     => $userId,
            'username'    => $usernameSnapshot,
            'action'      => $actionCode,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'ip'          => $ipAddress,
            'before'      => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after'       => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            'reason'      => $reason,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
