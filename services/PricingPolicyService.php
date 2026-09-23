<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.11B — SCM -> Bakery selling-price policy (company/category/SKU
 * hierarchy) and the pure calculation that turns a reference purchase
 * price into a selling price.
 *
 * Resolution order (Part 9): SKU override, then CATEGORY, then COMPANY
 * default. A missing policy at any level simply falls through to the
 * next — never invented, never defaulted to AT_COST silently (see
 * resolve()'s null return).
 *
 * PRICE-SOURCE NOTE: this class never touches item_price_history itself —
 * the reference purchase price it multiplies/adds against always comes
 * from the caller (DistributionInvoiceService), which gets it from the
 * existing, unmodified ItemPriceService::resolveReferencePrice() (the
 * exact same source V2.10's Stock IN auto-fill uses). This class is pure
 * policy resolution + arithmetic, nothing else.
 */
final class PricingPolicyService
{
    private const MONEY_SCALE = 4;

    /**
     * @return array{policy_id:int, scope:string, pricing_method:string, margin_value:float}|null
     *   null means no policy resolved at any level — the caller must
     *   treat this as "cannot price this line", never silently AT_COST.
     */
    public static function resolve(PDO $pdo, int $itemId, ?int $categoryId): ?array
    {
        $sku = $pdo->prepare(
            "SELECT id, pricing_method, margin_value FROM distribution_pricing_policies
             WHERE scope = 'SKU' AND item_id = :item_id AND is_active = 1 LIMIT 1"
        );
        $sku->execute(['item_id' => $itemId]);
        if ($row = $sku->fetch()) {
            return self::shape($row, 'SKU');
        }

        if ($categoryId !== null) {
            $category = $pdo->prepare(
                "SELECT id, pricing_method, margin_value FROM distribution_pricing_policies
                 WHERE scope = 'CATEGORY' AND category_id = :category_id AND is_active = 1 LIMIT 1"
            );
            $category->execute(['category_id' => $categoryId]);
            if ($row = $category->fetch()) {
                return self::shape($row, 'CATEGORY');
            }
        }

        $company = $pdo->query(
            "SELECT id, pricing_method, margin_value FROM distribution_pricing_policies
             WHERE scope = 'COMPANY' AND is_active = 1 LIMIT 1"
        )->fetch();
        if ($company) {
            return self::shape($company, 'COMPANY');
        }

        return null;
    }

    /** Pure arithmetic — never rounds intermediate values twice, matches PurchaseCostingService's MONEY_SCALE convention. */
    public static function calculateSellingPrice(float $referencePrice, string $pricingMethod, float $marginValue): float
    {
        return match ($pricingMethod) {
            'AT_COST' => round($referencePrice, self::MONEY_SCALE),
            'COST_PLUS_PERCENT' => round($referencePrice * (1 + $marginValue / 100), self::MONEY_SCALE),
            'COST_PLUS_AMOUNT' => round($referencePrice + $marginValue, self::MONEY_SCALE),
            default => throw new ValidationException(["unknown pricing_method: {$pricingMethod}"]),
        };
    }

    /** @param array{scope:string, category_id?:?int, item_id?:?int, pricing_method:string, margin_value:float, effective_from?:?string, notes?:?string, created_by?:?int, username?:string} $p */
    public static function upsert(PDO $pdo, array $p): array
    {
        assert_required_fields($p, ['scope', 'pricing_method']);
        self::assertValidScope($p['scope']);
        self::assertValidMethod($p['pricing_method']);

        $categoryId = null;
        $itemId = null;
        if ($p['scope'] === 'CATEGORY') {
            if (empty($p['category_id'])) {
                throw new ValidationException(['category_id is required for a CATEGORY-scope policy']);
            }
            $categoryId = (int) $p['category_id'];
            self::assertCategoryExists($pdo, $categoryId);
        } elseif ($p['scope'] === 'SKU') {
            if (empty($p['item_id'])) {
                throw new ValidationException(['item_id is required for a SKU-scope policy']);
            }
            $itemId = (int) $p['item_id'];
            self::assertItemExists($pdo, $itemId);
        }

        $marginValue = (float) ($p['margin_value'] ?? 0);
        if ($p['pricing_method'] === 'COST_PLUS_PERCENT' && $marginValue < 0) {
            throw new ValidationException(['margin_value (percent) cannot be negative']);
        }
        if ($p['pricing_method'] === 'COST_PLUS_AMOUNT' && $marginValue < 0) {
            throw new ValidationException(['margin_value (amount) cannot be negative']);
        }

        // Deactivate whatever is currently active at this exact scope
        // target first — the generated-column UNIQUE constraint would
        // otherwise reject the new INSERT outright. This is a deliberate
        // "replace the active policy" upsert, not a history-versioned
        // table: an already-issued Invoice keeps its own pricing snapshot
        // regardless (Section 12), so retiring the old row here never
        // rewrites anything already invoiced.
        $deactivateSql = match ($p['scope']) {
            'COMPANY' => "UPDATE distribution_pricing_policies SET is_active = 0 WHERE scope = 'COMPANY' AND is_active = 1",
            'CATEGORY' => "UPDATE distribution_pricing_policies SET is_active = 0 WHERE scope = 'CATEGORY' AND category_id = :target AND is_active = 1",
            'SKU' => "UPDATE distribution_pricing_policies SET is_active = 0 WHERE scope = 'SKU' AND item_id = :target AND is_active = 1",
        };
        $deactivateStmt = $pdo->prepare($deactivateSql);
        $deactivateStmt->execute($p['scope'] === 'COMPANY' ? [] : ['target' => $p['scope'] === 'CATEGORY' ? $categoryId : $itemId]);

        $insert = $pdo->prepare(
            'INSERT INTO distribution_pricing_policies
                (scope, category_id, item_id, pricing_method, margin_value, is_active, effective_from, notes, created_by, updated_by)
             VALUES (:scope, :category_id, :item_id, :method, :margin, 1, :effective_from, :notes, :created_by, :updated_by)'
        );
        $insert->execute([
            'scope' => $p['scope'], 'category_id' => $categoryId, 'item_id' => $itemId,
            'method' => $p['pricing_method'], 'margin' => $marginValue,
            'effective_from' => $p['effective_from'] ?? null, 'notes' => $p['notes'] ?? null,
            'created_by' => $p['created_by'] ?? null, 'updated_by' => $p['created_by'] ?? null,
        ]);
        $policyId = (int) $pdo->lastInsertId();

        AuditService::log(
            $pdo, $p['created_by'] ?? null, $p['username'] ?? 'system', 'DISTRIBUTION_PRICING_POLICY_SET',
            'distribution_pricing_policies', $policyId, null,
            ['scope' => $p['scope'], 'category_id' => $categoryId, 'item_id' => $itemId, 'pricing_method' => $p['pricing_method'], 'margin_value' => $marginValue],
            null
        );

        return ['success' => true, 'policy_id' => $policyId];
    }

    public static function deactivate(PDO $pdo, int $policyId, array $p): array
    {
        $existing = $pdo->prepare('SELECT * FROM distribution_pricing_policies WHERE id = :id');
        $existing->execute(['id' => $policyId]);
        $before = $existing->fetch();
        if ($before === false) {
            throw new NotFoundException("pricing policy {$policyId}");
        }

        $pdo->prepare('UPDATE distribution_pricing_policies SET is_active = 0, updated_by = :by WHERE id = :id')
            ->execute(['by' => $p['created_by'] ?? null, 'id' => $policyId]);

        AuditService::log($pdo, $p['created_by'] ?? null, $p['username'] ?? 'system', 'DISTRIBUTION_PRICING_POLICY_DEACTIVATE', 'distribution_pricing_policies', $policyId, ['is_active' => 1], ['is_active' => 0], null);

        return ['success' => true, 'policy_id' => $policyId];
    }

    public static function listAll(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT p.*, c.name AS category_name, i.sku AS item_sku, i.name AS item_name
             FROM distribution_pricing_policies p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN items i ON i.id = p.item_id
             ORDER BY FIELD(p.scope, \'SKU\', \'CATEGORY\', \'COMPANY\'), p.is_active DESC, p.created_at DESC'
        )->fetchAll();
    }

    private static function shape(array $row, string $source): array
    {
        return [
            'policy_id' => (int) $row['id'], 'scope' => $source,
            'pricing_method' => $row['pricing_method'], 'margin_value' => (float) $row['margin_value'],
        ];
    }

    private static function assertValidScope(string $scope): void
    {
        if (!in_array($scope, ['COMPANY', 'CATEGORY', 'SKU'], true)) {
            throw new ValidationException(["invalid scope: {$scope}"]);
        }
    }

    private static function assertValidMethod(string $method): void
    {
        if (!in_array($method, ['AT_COST', 'COST_PLUS_PERCENT', 'COST_PLUS_AMOUNT'], true)) {
            throw new ValidationException(["invalid pricing_method: {$method}"]);
        }
    }

    private static function assertCategoryExists(PDO $pdo, int $categoryId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = :id');
        $stmt->execute(['id' => $categoryId]);
        if ($stmt->fetchColumn() === false) {
            throw new NotFoundException("category {$categoryId}");
        }
    }

    private static function assertItemExists(PDO $pdo, int $itemId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        if ($stmt->fetchColumn() === false) {
            throw new NotFoundException("item {$itemId}");
        }
    }
}
