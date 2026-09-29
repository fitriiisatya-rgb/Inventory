#!/bin/bash
# Golden Path E2E: the full go-live flow over real HTTP against a running
# dev server — login -> master data -> stock import -> session create/
# assign/start -> P1+P2 counting (one MATCH item, one MISMATCH item) ->
# review -> ACTIVE->REVIEW -> bulk-finalize-MATCH -> manual-finalize-
# MISMATCH -> REVIEW->FINISHED -> Excel export, with real assertions at
# every step (not just "no HTTP error").
#
# Usage: BASE_URL=http://127.0.0.1:8098 tests/golden_path_e2e.sh
set -uo pipefail
cd "$(dirname "$0")/.."

BASE_URL="${BASE_URL:-http://127.0.0.1:8098}"
PASS=0
FAIL=0
FAILURES=()

jar_admin=/tmp/gp_jar_admin.txt; jar_p1=/tmp/gp_jar_p1.txt; jar_p2=/tmp/gp_jar_p2.txt
rm -f "$jar_admin" "$jar_p1" "$jar_p2"

field() { echo "$1" | php -r "\$d=json_decode(file_get_contents('php://stdin'),true); \$v=\$d['$2'] ?? null; echo is_array(\$v)?json_encode(\$v):\$v;"; }
login() {
  local jar=$1 user=$2 pass=$3
  curl -s -c "$jar" -X POST "$BASE_URL/api/auth/login.php" -H "Content-Type: application/json" -d "{\"username\":\"$user\",\"password\":\"$pass\"}"
}
assert_eq() {
  local desc=$1 expected=$2 actual=$3
  if [ "$expected" == "$actual" ]; then
    PASS=$((PASS+1)); echo "  [PASS] $desc"
  else
    FAIL=$((FAIL+1)); FAILURES+=("$desc — expected '$expected', got '$actual'"); echo "  [FAIL] $desc — expected '$expected', got '$actual'"
  fi
}
assert_true() {
  local desc=$1 cond=$2
  if [ "$cond" == "1" ]; then
    PASS=$((PASS+1)); echo "  [PASS] $desc"
  else
    FAIL=$((FAIL+1)); FAILURES+=("$desc"); echo "  [FAIL] $desc"
  fi
}

echo "=== Golden Path E2E ($BASE_URL) ==="

echo "--- 0. Clean slate ---"
php bin/clear_test_data.php --confirm --yes > /dev/null
mysql -uroot stok_opname -e "DELETE FROM items WHERE sku LIKE 'GP-%'; DELETE FROM categories WHERE code='GP-CAT'; DELETE FROM locations WHERE code='GP-LOC'; DELETE FROM users WHERE username LIKE 'gp_%';" 2>/dev/null

echo "--- 1. Login superadmin ---"
RESP=$(login "$jar_admin" superadmin ChangeMe123)
CSRF_A=$(field "$RESP" csrf_token)
assert_true "Superadmin login returns a CSRF token" "$([ -n "$CSRF_A" ] && echo 1 || echo 0)"

echo "--- 2. Master data: category, location, 2 items ---"
CAT=$(curl -s -b "$jar_admin" -X POST "$BASE_URL/api/categories.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d '{"code":"GP-CAT","name":"Golden Path Cat"}')
CAT_ID=$(field "$CAT" data | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["id"];')
LOC=$(curl -s -b "$jar_admin" -X POST "$BASE_URL/api/locations.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d '{"code":"GP-LOC","name":"Golden Path Loc"}')
LOC_ID=$(field "$LOC" data | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["id"];')
assert_true "Category created" "$([ -n "$CAT_ID" ] && echo 1 || echo 0)"
assert_true "Location created" "$([ -n "$LOC_ID" ] && echo 1 || echo 0)"

ITEM_A=$(curl -s -b "$jar_admin" -X POST "$BASE_URL/api/items.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"sku\":\"GP-A\",\"name\":\"Golden Path Item A\",\"category_id\":$CAT_ID,\"buy_unit\":\"Pcs\",\"buy_content\":1,\"base_unit\":\"Pcs\",\"last_buy_price\":1000}")
ITEM_B=$(curl -s -b "$jar_admin" -X POST "$BASE_URL/api/items.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"sku\":\"GP-B\",\"name\":\"Golden Path Item B\",\"category_id\":$CAT_ID,\"buy_unit\":\"Karton\",\"buy_content\":20000,\"mid_unit\":\"Kg\",\"mid_content\":20,\"base_unit\":\"Gr\",\"last_buy_price\":500}")
assert_true "Item A (1-level) created" "$([ -n "$(field "$ITEM_A" data)" ] && echo 1 || echo 0)"
assert_true "Item B (3-level) created" "$([ -n "$(field "$ITEM_B" data)" ] && echo 1 || echo 0)"

echo "--- 3. Import system stock ---"
cat > /tmp/gp_import.csv <<CSV
sku,system_qty_base,unit_cost
GP-A,100,1000
GP-B,200000,500
CSV
PREVIEW=$(curl -s -b "$jar_admin" -X POST "$BASE_URL/api/stock_import/preview.php" -H "X-CSRF-Token: $CSRF_A" -F "location_id=$LOC_ID" -F "file=@/tmp/gp_import.csv;type=text/csv")
BATCH_ID=$(field "$PREVIEW" batch_id)
assert_true "Stock import preview succeeded" "$([ -n "$BATCH_ID" ] && echo 1 || echo 0)"
COMMIT=$(curl -s -b "$jar_admin" -X POST "$BASE_URL/api/stock_import/commit.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"batch_id\":$BATCH_ID}")
assert_eq "Stock import committed (2 rows)" "2" "$(field "$COMMIT" committed)"

echo "--- 4. Counter users ---"
curl -s -b "$jar_admin" -X POST "$BASE_URL/api/users.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d '{"username":"gp_p1","password":"password123","full_name":"GP P1","role":"COUNTER","team":"P1"}' > /dev/null
curl -s -b "$jar_admin" -X POST "$BASE_URL/api/users.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d '{"username":"gp_p2","password":"password123","full_name":"GP P2","role":"COUNTER","team":"P2"}' > /dev/null
USERS=$(curl -s -b "$jar_admin" "$BASE_URL/api/users.php")
P1_ID=$(echo "$USERS" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $u) if($u["username"]=="gp_p1") echo $u["id"];')
P2_ID=$(echo "$USERS" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $u) if($u["username"]=="gp_p2") echo $u["id"];')
assert_true "P1 user created" "$([ -n "$P1_ID" ] && echo 1 || echo 0)"
assert_true "P2 user created" "$([ -n "$P2_ID" ] && echo 1 || echo 0)"

echo "--- 5. Session: create, preflight, assign, start ---"
SESSION=$(curl -s -b "$jar_admin" -X POST "$BASE_URL/api/sessions.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"name\":\"Golden Path Session\",\"location_id\":$LOC_ID,\"scope_type\":\"CATEGORY\",\"category_id\":$CAT_ID}")
SESSION_ID=$(field "$SESSION" data | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["id"];')
assert_true "Session created (DRAFT)" "$([ -n "$SESSION_ID" ] && echo 1 || echo 0)"

PRE1=$(curl -s -b "$jar_admin" "$BASE_URL/api/sessions/preflight.php?session_id=$SESSION_ID")
BLOCKERS1=$(field "$PRE1" blockers)
assert_true "Preflight blocked before assignment" "$([ "$BLOCKERS1" != "[]" ] && echo 1 || echo 0)"

curl -s -b "$jar_admin" -X POST "$BASE_URL/api/sessions/assign.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID,\"user_id\":$P1_ID,\"team\":\"P1\"}" > /dev/null
curl -s -b "$jar_admin" -X POST "$BASE_URL/api/sessions/assign.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID,\"user_id\":$P2_ID,\"team\":\"P2\"}" > /dev/null

PRE2=$(curl -s -b "$jar_admin" "$BASE_URL/api/sessions/preflight.php?session_id=$SESSION_ID")
assert_eq "Preflight clean after assignment" "[]" "$(field "$PRE2" blockers)"

START=$(curl -s -b "$jar_admin" -X POST "$BASE_URL/api/sessions/start.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID}")
assert_eq "Session started -> ACTIVE" "ACTIVE" "$(field "$START" data | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["status"];')"

echo "--- 6. P1 counts GP-A=100 (will MATCH), GP-B=190000 (will MISMATCH) ---"
RESP_P1=$(login "$jar_p1" gp_p1 password123)
CSRF_P1=$(field "$RESP_P1" csrf_token)
ITEMS_P1=$(curl -s -b "$jar_p1" "$BASE_URL/api/counter/items.php?session_id=$SESSION_ID")
SI_A=$(echo "$ITEMS_P1" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $r) if($r["sku"]=="GP-A") echo $r["session_item_id"];')
SI_B=$(echo "$ITEMS_P1" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $r) if($r["sku"]=="GP-B") echo $r["session_item_id"];')
assert_true "P1 can see GP-A session_item_id" "$([ -n "$SI_A" ] && echo 1 || echo 0)"
assert_true "P1 can see GP-B session_item_id" "$([ -n "$SI_B" ] && echo 1 || echo 0)"

curl -s -b "$jar_p1" -X POST "$BASE_URL/api/counter/lock.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P1" -d "{\"session_item_id\":$SI_A}" > /dev/null
CNT_A_P1=$(curl -s -b "$jar_p1" -X POST "$BASE_URL/api/counter/count.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P1" \
  -d "{\"session_item_id\":$SI_A,\"good_buy_qty\":0,\"good_mid_qty\":0,\"good_base_input_qty\":100,\"damaged_qty\":0,\"expired_qty\":0,\"deadstock_qty\":0}")
assert_eq "P1 GP-A count status COMPLETE" "COMPLETE" "$(field "$CNT_A_P1" data | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["evidence_status"];')"

curl -s -b "$jar_p1" -X POST "$BASE_URL/api/counter/lock.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P1" -d "{\"session_item_id\":$SI_B}" > /dev/null
CNT_B_P1=$(curl -s -b "$jar_p1" -X POST "$BASE_URL/api/counter/count.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P1" \
  -d "{\"session_item_id\":$SI_B,\"good_buy_qty\":0,\"good_mid_qty\":0,\"good_base_input_qty\":190000,\"damaged_qty\":0,\"expired_qty\":0,\"deadstock_qty\":0}")
assert_eq "P1 GP-B count status COMPLETE" "COMPLETE" "$(field "$CNT_B_P1" data | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["evidence_status"];')"

echo "--- 7. P2 counts GP-A=100 (MATCH), GP-B=195000 (MISMATCH) ---"
RESP_P2=$(login "$jar_p2" gp_p2 password123)
CSRF_P2=$(field "$RESP_P2" csrf_token)
curl -s -b "$jar_p2" -X POST "$BASE_URL/api/counter/lock.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P2" -d "{\"session_item_id\":$SI_A}" > /dev/null
curl -s -b "$jar_p2" -X POST "$BASE_URL/api/counter/count.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P2" \
  -d "{\"session_item_id\":$SI_A,\"good_buy_qty\":0,\"good_mid_qty\":0,\"good_base_input_qty\":100,\"damaged_qty\":0,\"expired_qty\":0,\"deadstock_qty\":0}" > /dev/null
curl -s -b "$jar_p2" -X POST "$BASE_URL/api/counter/lock.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P2" -d "{\"session_item_id\":$SI_B}" > /dev/null
curl -s -b "$jar_p2" -X POST "$BASE_URL/api/counter/count.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P2" \
  -d "{\"session_item_id\":$SI_B,\"good_buy_qty\":0,\"good_mid_qty\":0,\"good_base_input_qty\":195000,\"damaged_qty\":0,\"expired_qty\":0,\"deadstock_qty\":0}" > /dev/null

echo "--- 8. Superadmin review: GP-A MATCH, GP-B MISMATCH ---"
REVIEW=$(curl -s -b "$jar_admin" "$BASE_URL/api/review/items.php?session_id=$SESSION_ID")
STATUS_A=$(echo "$REVIEW" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $r) if($r["sku"]=="GP-A") echo $r["status"];')
STATUS_B=$(echo "$REVIEW" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $r) if($r["sku"]=="GP-B") echo $r["status"];')
assert_eq "GP-A reconciliation status" "MATCH" "$STATUS_A"
assert_eq "GP-B reconciliation status" "MISMATCH" "$STATUS_B"

# Security boundary sanity check: P1's own item listing must NEVER contain
# system_qty/unit_cost/MATCH-MISMATCH fields, even mid-flow.
LEAK_CHECK=$(echo "$ITEMS_P1" | grep -o "system_qty_snapshot\|unit_cost_snapshot\|MISMATCH" || true)
assert_eq "COUNTER item listing carries no system-stock/variance leakage" "" "$LEAK_CHECK"

echo "--- 9. ACTIVE -> REVIEW ---"
TOREVIEW=$(curl -s -b "$jar_admin" -X POST "$BASE_URL/api/sessions/transition_review.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID}")
assert_eq "Session -> REVIEW" "REVIEW" "$(field "$TOREVIEW" data | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["status"] ?? "";')"

echo "--- 10. Bulk finalize MATCH (GP-A) ---"
BULK=$(curl -s -b "$jar_admin" -X POST "$BASE_URL/api/review/bulk_finalize.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID}")
assert_eq "Bulk finalize: 1 item auto-finalized" "1" "$(field "$BULK" data | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["finalized"];')"

echo "--- 11. Manual finalize MISMATCH (GP-B) ---"
SETFINAL=$(curl -s -b "$jar_admin" -X POST "$BASE_URL/api/review/set_final.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"session_item_id\":$SI_B,\"good\":192500,\"damaged\":0,\"expired\":0,\"deadstock\":0,\"reason\":\"Golden path E2E manual resolution\"}")
assert_eq "GP-B final good qty" "192500" "$(field "$SETFINAL" data | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo (int)$d["final_good_base_qty"];')"

echo "--- 12. REVIEW -> FINISHED ---"
FINISH=$(curl -s -b "$jar_admin" -X POST "$BASE_URL/api/sessions/finish.php" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID}")
assert_eq "Session -> FINISHED" "FINISHED" "$(field "$FINISH" data | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["status"] ?? "";')"

echo "--- 13. Excel export ---"
curl -s -b "$jar_admin" "$BASE_URL/api/sessions/export_excel.php?session_id=$SESSION_ID" -o /tmp/gp_export.xlsx
XLSX_SIZE=$(stat -c%s /tmp/gp_export.xlsx 2>/dev/null || echo 0)
assert_true "Excel export produced a non-trivial file (>1KB)" "$([ "$XLSX_SIZE" -gt 1000 ] && echo 1 || echo 0)"
MAGIC=$(head -c2 /tmp/gp_export.xlsx | od -An -tx1 | tr -d ' \n')
assert_eq "Excel export starts with ZIP magic bytes (PK)" "504b" "$MAGIC"

XLSX_CHECK=$(python3 -c "
import openpyxl
try:
    wb = openpyxl.load_workbook('/tmp/gp_export.xlsx')
    names = wb.sheetnames
    assert names == ['Ringkasan','Detail SO','Rusak','Expired','Deadstock','Petugas'], names
    detail = wb['Detail SO']
    rows = list(detail.iter_rows(values_only=True))
    skus = [r[0] for r in rows[1:]]
    assert 'GP-A' in skus and 'GP-B' in skus, skus
    print('OK')
except Exception as e:
    print('FAIL: ' + str(e))
" 2>&1)
assert_eq "Excel export opens with openpyxl and has correct sheets/rows" "OK" "$XLSX_CHECK"

echo ""
echo "==================================="
echo "PASS: $PASS / $((PASS+FAIL))"
if [ $FAIL -gt 0 ]; then
  echo "FAILURES:"
  for f in "${FAILURES[@]}"; do echo "  - $f"; done
  exit 1
fi
echo "ALL GOLDEN PATH ASSERTIONS PASSED"
