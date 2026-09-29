<?php
declare(strict_types=1);

final class Audit
{
    public static function log(
        ?int $actorId,
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValue = null,
        ?array $newValue = null
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $actorId,
            $action,
            $entityType,
            $entityId,
            $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            self::clientIp(),
            self::clientUserAgent(),
        ]);
    }

    private static function clientIp(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    private static function clientUserAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return $ua !== null ? substr($ua, 0, 255) : null;
    }
}
