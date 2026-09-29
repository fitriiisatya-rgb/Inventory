<?php
declare(strict_types=1);

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static array $failures = [];
    public static string $currentSection = '';

    public static function section(string $name): void
    {
        self::$currentSection = $name;
        echo "\n== {$name} ==\n";
    }

    public static function assertEquals($expected, $actual, string $msg): void
    {
        if ($expected == $actual) {
            self::$pass++;
            echo "  [PASS] {$msg}\n";
        } else {
            self::$fail++;
            $line = '[' . self::$currentSection . "] {$msg} — expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
            self::$failures[] = $line;
            echo "  [FAIL] {$msg} — expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
        }
    }

    public static function assertTrue($cond, string $msg): void
    {
        if ($cond) {
            self::$pass++;
            echo "  [PASS] {$msg}\n";
        } else {
            self::$fail++;
            self::$failures[] = '[' . self::$currentSection . "] {$msg}";
            echo "  [FAIL] {$msg}\n";
        }
    }

    public static function assertFalse($cond, string $msg): void
    {
        self::assertTrue(!$cond, $msg);
    }

    public static function summary(): int
    {
        echo "\n===================================\n";
        echo 'PASS: ' . self::$pass . ' / ' . (self::$pass + self::$fail) . "\n";
        if (self::$fail > 0) {
            echo "FAILURES:\n";
            foreach (self::$failures as $f) {
                echo "  - {$f}\n";
            }
            return 1;
        }
        echo "ALL TESTS PASSED\n";
        return 0;
    }
}
