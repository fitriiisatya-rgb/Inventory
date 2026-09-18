<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE G2.1: canonical unit list + spelling/case normalization for import.
 *
 * The old system accumulated variants of the same unit (gr/gram/Gram/GRAM)
 * as if they were different things. This service maps any recognized
 * variant down to exactly one canonical `units.code` BEFORE it ever reaches
 * validation or the database — never invents a new unit row for an unknown
 * spelling, and never guesses a conversion factor (e.g. "PACK probably
 * means 1 PACK = 12 PCS") — that would be exactly the kind of silent fix
 * Section G11 forbids. An unrecognized unit string normalizes to null,
 * which importers must treat as a hard validation ERROR ("invalid unit"),
 * not an auto-created new unit.
 *
 * The canonical list itself is whatever already exists in `units` at
 * runtime (seeded once in database/schema.sql: GR, KG, ML, LTR, PCS, BOX,
 * KARTON, KARUNG, LUSIN, PACK, ROLL) — this class only supplies the ALIAS
 * map, so adding a genuinely new canonical unit later is a schema seed
 * change, not a code change here.
 */
final class UnitNormalizationService
{
    /**
     * Alias (lowercased, trimmed) => canonical unit code. Deliberately
     * conservative: only spelling/case/language variants of the SAME unit,
     * never a different-sized unit (e.g. "carton"/"ctn" fold into the
     * existing KARTON code rather than becoming a second, redundant
     * "carton" unit — but "dozen" is not folded into PCS, since 1 dozen
     * pieces is not 1 piece).
     */
    private const ALIASES = [
        // GR
        'gr' => 'GR', 'gram' => 'GR', 'grams' => 'GR', 'g' => 'GR',
        // KG
        'kg' => 'KG', 'kilogram' => 'KG', 'kilograms' => 'KG', 'kilo' => 'KG',
        // ML
        'ml' => 'ML', 'mililiter' => 'ML', 'milliliter' => 'ML', 'mililiters' => 'ML',
        // LTR
        'ltr' => 'LTR', 'liter' => 'LTR', 'litre' => 'LTR', 'liters' => 'LTR', 'l' => 'LTR',
        // PCS
        'pcs' => 'PCS', 'piece' => 'PCS', 'pieces' => 'PCS', 'pc' => 'PCS', 'buah' => 'PCS', 'biji' => 'PCS', 'unit' => 'PCS',
        // BOX
        'box' => 'BOX', 'kotak' => 'BOX',
        // KARTON (canonical for "carton"/"ctn" — see class docblock)
        'karton' => 'KARTON', 'carton' => 'KARTON', 'ctn' => 'KARTON', 'cartons' => 'KARTON',
        // KARUNG (canonical for "sack"/"sak")
        'karung' => 'KARUNG', 'sack' => 'KARUNG', 'sak' => 'KARUNG', 'sacks' => 'KARUNG',
        // LUSIN
        'lusin' => 'LUSIN', 'dozen' => 'LUSIN', 'dzn' => 'LUSIN',
        // PACK
        'pack' => 'PACK', 'pak' => 'PACK', 'bungkus' => 'PACK', 'packs' => 'PACK',
        // ROLL
        'roll' => 'ROLL', 'gulung' => 'ROLL', 'rolls' => 'ROLL',
        // PAIL / JAR / SET / SHEET / METER / BATANG (schema.sql Section: PHASE
        // G-DATA additions) — Indonesian synonyms found in the real catalog
        // (e.g. "Lembar" as a purchase unit), same conservative same-unit-
        // only rule as every alias above.
        'pail' => 'PAIL',
        'jar' => 'JAR', 'toples' => 'JAR',
        'set' => 'SET',
        'sheet' => 'SHEET', 'lembar' => 'SHEET', 'sheets' => 'SHEET',
        'meter' => 'METER', 'mtr' => 'METER', 'm' => 'METER', 'meters' => 'METER',
        'batang' => 'BATANG',
    ];

    /**
     * Normalizes a raw unit string typed/uploaded by a user to a canonical
     * `units.code`, or null if it cannot be recognized at all (caller must
     * treat null as a validation ERROR, never fall back to creating a new
     * unit or guessing). Matching is case/whitespace-insensitive only —
     * no conversion factor is ever inferred here.
     */
    public static function normalize(PDO $pdo, string $raw): ?string
    {
        $key = strtolower(trim($raw));
        if ($key === '') {
            return null;
        }

        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }

        // Already-canonical (exact code match, case-insensitive) is valid
        // even if not listed as an alias of itself.
        $stmt = $pdo->prepare('SELECT code FROM units WHERE UPPER(code) = :code');
        $stmt->execute(['code' => strtoupper($key)]);
        $code = $stmt->fetchColumn();
        return $code !== false ? (string) $code : null;
    }

    /** Resolves a normalized unit code to its `units.id`, or null if unrecognized. */
    public static function resolveUnitId(PDO $pdo, string $raw): ?int
    {
        $canonical = self::normalize($pdo, $raw);
        if ($canonical === null) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id FROM units WHERE code = :code');
        $stmt->execute(['code' => $canonical]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** @return string[] every canonical code currently seeded, for building a dropdown/reference list. */
    public static function canonicalList(PDO $pdo): array
    {
        return array_column($pdo->query('SELECT code FROM units ORDER BY code')->fetchAll(), 'code');
    }
}
