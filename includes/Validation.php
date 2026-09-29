<?php
declare(strict_types=1);

final class Validation
{
    public static function requireFields(array $data, array $fields): array
    {
        $missing = [];
        foreach ($fields as $f) {
            if (!array_key_exists($f, $data) || $data[$f] === '' || $data[$f] === null) {
                $missing[] = $f;
            }
        }
        return $missing;
    }

    public static function isNumeric($value): bool
    {
        return is_numeric($value);
    }

    public static function sameUnit(?string $a, ?string $b): bool
    {
        return strcasecmp(trim((string) $a), trim((string) $b)) === 0;
    }
}
