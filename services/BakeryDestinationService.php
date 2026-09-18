<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2 3e — Master Bakery Tujuan CRUD. `bakery_destinations` is a
 * distribution endpoint for OUT transactions — deliberately separate from
 * `warehouses` (internal stock location) and `divisions` (internal
 * production cost-center); see docs/PHASE_V2_TECHNICAL_DESIGN.md Section 5.
 *
 * A destination referenced by any transaction can never be hard-deleted —
 * only deactivated (is_active=0).
 */
final class BakeryDestinationService
{
    public static function create(PDO $pdo, array $p): array
    {
        assert_required_fields($p, ['code', 'name']);

        $code = trim((string) $p['code']);
        $name = trim((string) $p['name']);
        if ($code === '' || $name === '') {
            throw new ValidationException(['code and name cannot be blank']);
        }

        $existing = $pdo->prepare('SELECT id FROM bakery_destinations WHERE code = :c');
        $existing->execute(['c' => $code]);
        if ($existing->fetchColumn() !== false) {
            throw new ValidationException(["bakery destination code '{$code}' already exists"]);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO bakery_destinations (code, name, address, city_area, pic_name, phone, route_cluster, notes, is_active)
             VALUES (:code, :name, :address, :city_area, :pic_name, :phone, :route_cluster, :notes, 1)'
        );
        $stmt->execute([
            'code' => $code, 'name' => $name,
            'address' => self::nullableTrim($p['address'] ?? null),
            'city_area' => self::nullableTrim($p['city_area'] ?? null),
            'pic_name' => self::nullableTrim($p['pic_name'] ?? null),
            'phone' => self::nullableTrim($p['phone'] ?? null),
            'route_cluster' => self::nullableTrim($p['route_cluster'] ?? null),
            'notes' => self::nullableTrim($p['notes'] ?? null),
        ]);
        $destinationId = (int) $pdo->lastInsertId();

        AuditService::log($pdo, $p['created_by'] ?? null, $p['username'] ?? 'system', 'BAKERY_DESTINATION_CREATE', 'bakery_destinations', $destinationId, null, ['code' => $code, 'name' => $name], null);

        return ['success' => true, 'bakery_destination_id' => $destinationId];
    }

    public static function update(PDO $pdo, int $destinationId, array $p): array
    {
        $existing = $pdo->prepare('SELECT * FROM bakery_destinations WHERE id = :id');
        $existing->execute(['id' => $destinationId]);
        $before = $existing->fetch();
        if ($before === false) {
            throw new NotFoundException("bakery destination {$destinationId}");
        }

        if (isset($p['code'])) {
            $newCode = trim((string) $p['code']);
            if ($newCode === '') {
                throw new ValidationException(['code cannot be blank']);
            }
            if ($newCode !== $before['code']) {
                $dupe = $pdo->prepare('SELECT id FROM bakery_destinations WHERE code = :c AND id <> :id');
                $dupe->execute(['c' => $newCode, 'id' => $destinationId]);
                if ($dupe->fetchColumn() !== false) {
                    throw new ValidationException(["bakery destination code '{$newCode}' already exists"]);
                }
            }
        }
        if (isset($p['name']) && trim((string) $p['name']) === '') {
            throw new ValidationException(['name cannot be blank']);
        }

        $fields = [
            'code' => isset($p['code']) ? trim((string) $p['code']) : $before['code'],
            'name' => isset($p['name']) ? trim((string) $p['name']) : $before['name'],
            'address' => array_key_exists('address', $p) ? self::nullableTrim($p['address']) : $before['address'],
            'city_area' => array_key_exists('city_area', $p) ? self::nullableTrim($p['city_area']) : $before['city_area'],
            'pic_name' => array_key_exists('pic_name', $p) ? self::nullableTrim($p['pic_name']) : $before['pic_name'],
            'phone' => array_key_exists('phone', $p) ? self::nullableTrim($p['phone']) : $before['phone'],
            'route_cluster' => array_key_exists('route_cluster', $p) ? self::nullableTrim($p['route_cluster']) : $before['route_cluster'],
            'notes' => array_key_exists('notes', $p) ? self::nullableTrim($p['notes']) : $before['notes'],
            'is_active' => array_key_exists('is_active', $p) ? (int) (bool) $p['is_active'] : (int) $before['is_active'],
        ];

        $pdo->prepare(
            'UPDATE bakery_destinations SET code=:code, name=:name, address=:address, city_area=:city_area,
             pic_name=:pic_name, phone=:phone, route_cluster=:route_cluster, notes=:notes, is_active=:is_active WHERE id=:id'
        )->execute($fields + ['id' => $destinationId]);

        AuditService::log(
            $pdo, $p['updated_by'] ?? null, $p['username'] ?? 'system', 'BAKERY_DESTINATION_UPDATE', 'bakery_destinations', $destinationId,
            ['code' => $before['code'], 'name' => $before['name'], 'is_active' => (int) $before['is_active']],
            ['code' => $fields['code'], 'name' => $fields['name'], 'is_active' => $fields['is_active']],
            null
        );

        return ['success' => true, 'bakery_destination_id' => $destinationId];
    }

    private static function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
