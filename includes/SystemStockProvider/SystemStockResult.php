<?php
declare(strict_types=1);

/** Value object returned by any SystemStockProviderInterface implementation. */
final class SystemStockResult
{
    public function __construct(
        public readonly float $qty,           // base unit — always known; missing qty blocks session start entirely
        public readonly ?float $unitCost,     // per base unit; NULL means genuinely unknown, never Rp0
        public readonly string $costSource,   // 'IMPORT' | 'MASTER_LAST_BUY_PRICE' | 'NONE'
        public readonly string $asOf,         // datetime string, when this figure was last set
        public readonly string $source        // qty source: 'IMPORT' | 'API' | 'MANUAL'
    ) {
    }
}
