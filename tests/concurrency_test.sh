#!/usr/bin/env bash
# PHASE F2 concurrency test — genuine two-process, two-connection race:
# stock=100, two independent processes each attempt OUT 70 at the same
# time. Expected: exactly one succeeds, one is rejected STOCK_INSUFFICIENT,
# final stock=30 (never -40). Requires a configured .env (see
# tests/mysql_smoke_test.php for the same convention).
#
# Usage: bash tests/concurrency_test.sh [runs]
set -euo pipefail
cd "$(dirname "$0")/.."

RUNS="${1:-5}"
PASS=0

for i in $(seq 1 "$RUNS"); do
    SETUP=$(php tests/concurrency_setup.php)
    read -r ITEM_ID WAREHOUSE_ID USER_ID <<< "$SETUP"

    OUT_A=$(mktemp)
    OUT_B=$(mktemp)
    php tests/concurrency_worker.php "$ITEM_ID" "$WAREHOUSE_ID" 70 "$USER_ID" > "$OUT_A" 2>&1 &
    PID_A=$!
    php tests/concurrency_worker.php "$ITEM_ID" "$WAREHOUSE_ID" 70 "$USER_ID" > "$OUT_B" 2>&1 &
    PID_B=$!
    wait "$PID_A" "$PID_B"

    FINAL=$(php -r '
        require __DIR__ . "/services/Database.php";
        require __DIR__ . "/services/MigrationNegativeStockService.php";
        require __DIR__ . "/services/InventoryService.php";
        use App\Services\Database; use App\Services\InventoryService;
        $pdo = Database::connection();
        $s = InventoryService::currentStock($pdo, (int)$argv[1], (int)$argv[2]);
        echo $s["qty_base"];
    ' "$ITEM_ID" "$WAREHOUSE_ID")

    SUCCESSES=$(grep -c SUCCESS "$OUT_A" "$OUT_B" | awk -F: '{s+=$2} END{print s}')
    REJECTED=$(grep -c REJECTED "$OUT_A" "$OUT_B" | awk -F: '{s+=$2} END{print s}')

    if [ "$FINAL" = "30" ] && [ "$SUCCESSES" = "1" ] && [ "$REJECTED" = "1" ]; then
        echo "PASS - run ${i}: final_stock=${FINAL} successes=${SUCCESSES} rejected=${REJECTED}"
        PASS=$((PASS + 1))
    else
        echo "FAIL - run ${i}: final_stock=${FINAL} successes=${SUCCESSES} rejected=${REJECTED}"
        echo "  worker A: $(cat "$OUT_A")"
        echo "  worker B: $(cat "$OUT_B")"
    fi
    rm -f "$OUT_A" "$OUT_B"
done

echo "=============================="
echo "TOTAL: ${RUNS}  PASSED: ${PASS}  FAILED: $((RUNS - PASS))"
[ "$PASS" -eq "$RUNS" ]
