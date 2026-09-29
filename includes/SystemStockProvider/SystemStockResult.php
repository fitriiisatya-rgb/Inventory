<?php
declare(strict_types=1);

/** Value object returned by any SystemStockProviderInterface implementation. */
final class SystemStockResult
{
    public function __construct(
        public readonly float $qty,       // base unit
        public readonly float $unitCost,  // per base unit
        public readonly string $asOf,     // datetime string, when this figure was last set
        public readonly string $source    // 'IMPORT' | 'API' | 'MANUAL'
    ) {
    }
}
