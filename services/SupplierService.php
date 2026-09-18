<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2 3e — Master Vendor/Supplier CRUD. Audited first per the owner's
 * instruction: `suppliers` already had code/name/contact_name/phone/notes/
 * is_active — only address+email were genuinely missing (added in the V2
 * schema migration). This service does not duplicate that table; it is the
 * first CRUD write-path onto it (previously import-CSV-only).
 *
 * A supplier referenced by any transaction/item can never be hard-deleted
 * — only deactivated (is_active=0) — matching the project's standing
 * "never hard-delete referenced master data" rule.
 */
final class SupplierService
{
    public static function create(PDO $pdo, array $p): array
    {
        assert_required_fields($p, ['code', 'name']);

        $code = trim((string) $p['code']);
        $name = trim((string) $p['name']);
        if ($code === '' || $name === '') {
            throw new ValidationException(['code and name cannot be blank']);
        }

        $existing = $pdo->prepare('SELECT id FROM suppliers WHERE code = :c');
        $existing->execute(['c' => $code]);
        if ($existing->fetchColumn() !== false) {
            throw new ValidationException(["supplier code '{$code}' already exists"]);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO suppliers (code, name, contact_name, address, phone, email, notes, is_active)
             VALUES (:code, :name, :contact_name, :address, :phone, :email, :notes, 1)'
        );
        $stmt->execute([
            'code' => $code, 'name' => $name,
            'contact_name' => self::nullableTrim($p['contact_name'] ?? null),
            'address' => self::nullableTrim($p['address'] ?? null),
            'phone' => self::nullableTrim($p['phone'] ?? null),
            'email' => self::nullableTrim($p['email'] ?? null),
            'notes' => self::nullableTrim($p['notes'] ?? null),
        ]);
        $supplierId = (int) $pdo->lastInsertId();

        AuditService::log($pdo, $p['created_by'] ?? null, $p['username'] ?? 'system', 'SUPPLIER_CREATE', 'suppliers', $supplierId, null, ['code' => $code, 'name' => $name], null);

        return ['success' => true, 'supplier_id' => $supplierId];
    }

    public static function update(PDO $pdo, int $supplierId, array $p): array
    {
        $existing = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id');
        $existing->execute(['id' => $supplierId]);
        $before = $existing->fetch();
        if ($before === false) {
            throw new NotFoundException("supplier {$supplierId}");
        }

        if (isset($p['code'])) {
            $newCode = trim((string) $p['code']);
            if ($newCode === '') {
                throw new ValidationException(['code cannot be blank']);
            }
            if ($newCode !== $before['code']) {
                $dupe = $pdo->prepare('SELECT id FROM suppliers WHERE code = :c AND id <> :id');
                $dupe->execute(['c' => $newCode, 'id' => $supplierId]);
                if ($dupe->fetchColumn() !== false) {
                    throw new ValidationException(["supplier code '{$newCode}' already exists"]);
                }
            }
        }
        if (isset($p['name']) && trim((string) $p['name']) === '') {
            throw new ValidationException(['name cannot be blank']);
        }

        $fields = [
            'code' => isset($p['code']) ? trim((string) $p['code']) : $before['code'],
            'name' => isset($p['name']) ? trim((string) $p['name']) : $before['name'],
            'contact_name' => array_key_exists('contact_name', $p) ? self::nullableTrim($p['contact_name']) : $before['contact_name'],
            'address' => array_key_exists('address', $p) ? self::nullableTrim($p['address']) : $before['address'],
            'phone' => array_key_exists('phone', $p) ? self::nullableTrim($p['phone']) : $before['phone'],
            'email' => array_key_exists('email', $p) ? self::nullableTrim($p['email']) : $before['email'],
            'notes' => array_key_exists('notes', $p) ? self::nullableTrim($p['notes']) : $before['notes'],
            'is_active' => array_key_exists('is_active', $p) ? (int) (bool) $p['is_active'] : (int) $before['is_active'],
        ];

        $pdo->prepare(
            'UPDATE suppliers SET code=:code, name=:name, contact_name=:contact_name, address=:address,
             phone=:phone, email=:email, notes=:notes, is_active=:is_active WHERE id=:id'
        )->execute($fields + ['id' => $supplierId]);

        AuditService::log(
            $pdo, $p['updated_by'] ?? null, $p['username'] ?? 'system', 'SUPPLIER_UPDATE', 'suppliers', $supplierId,
            ['code' => $before['code'], 'name' => $before['name'], 'is_active' => (int) $before['is_active']],
            ['code' => $fields['code'], 'name' => $fields['name'], 'is_active' => $fields['is_active']],
            null
        );

        return ['success' => true, 'supplier_id' => $supplierId];
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
