#!/bin/bash
# HTTP-level RBAC / IDOR checks (design review point 26). Confirms the
# COUNTER-vs-SUPERADMIN split is enforced server-side, not just hidden in
# the UI: every sensitive endpoint must refuse a COUNTER account, and the
# COUNTER item-listing response must never contain the forbidden fields
# at all (not merely omit them from what the UI renders).
cd "$(dirname "$0")/.."

BASE=http://127.0.0.1:8098
jar_admin=/tmp/sec_jar_admin.txt
jar_counter=/tmp/sec_jar_counter.txt
rm -f $jar_admin $jar_counter

PASS=0; FAIL=0
check() {
  local desc="$1" expected="$2" actual="$3"
  if [ "$expected" == "$actual" ]; then
    echo "[PASS] $desc (HTTP $actual)"
    PASS=$((PASS+1))
  else
    echo "[FAIL] $desc (expected HTTP $expected, got $actual)"
    FAIL=$((FAIL+1))
  fi
}

RESP=$(curl -s -c $jar_admin -X POST $BASE/api/auth/login.php -H "Content-Type: application/json" -d '{"username":"superadmin","password":"ChangeMe123"}')
CSRF_A=$(echo "$RESP" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["csrf_token"] ?? "";')

curl -s -b $jar_admin -X POST $BASE/api/users.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d '{"username":"sec_counter","password":"password123","full_name":"Sec Counter","role":"COUNTER","team":"P1"}' > /dev/null
curl -s -b $jar_admin -X POST $BASE/api/users.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d '{"username":"sec_counter_p2","password":"password123","full_name":"Sec Counter P2","role":"COUNTER","team":"P2"}' > /dev/null

RESP2=$(curl -s -c $jar_counter -X POST $BASE/api/auth/login.php -H "Content-Type: application/json" -d '{"username":"sec_counter","password":"password123"}')
CSRF_C=$(echo "$RESP2" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["csrf_token"] ?? "";')

# Self-contained fixture so the IDOR body-check below doesn't depend on
# state left over from other test scripts.
jget() { echo "$1" | php -r "\$d=json_decode(file_get_contents('php://stdin'),true); echo \$d$2;"; }
CAT=$(curl -s -b $jar_admin -X POST $BASE/api/categories.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d '{"code":"SEC-CAT","name":"Security Test"}')
CAT_ID=$(jget "$CAT" "['data']['id']")
LOC=$(curl -s -b $jar_admin -X POST $BASE/api/locations.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d '{"code":"SEC-LOC","name":"Security Location"}')
LOC_ID=$(jget "$LOC" "['data']['id']")
curl -s -b $jar_admin -X POST $BASE/api/items.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"sku\":\"SEC-ITEM\",\"name\":\"Security Item\",\"category_id\":$CAT_ID,\"buy_unit\":\"Pcs\",\"buy_content\":1,\"base_unit\":\"Pcs\",\"last_buy_price\":100}" > /dev/null
printf 'sku,system_qty_base,unit_cost\nSEC-ITEM,999,100\n' > /tmp/sec_import.csv
PREVIEW=$(curl -s -b $jar_admin -X POST $BASE/api/stock_import/preview.php -H "X-CSRF-Token: $CSRF_A" -F "location_id=$LOC_ID" -F "file=@/tmp/sec_import.csv;type=text/csv")
BATCH_ID=$(jget "$PREVIEW" "['batch_id']")
curl -s -b $jar_admin -X POST $BASE/api/stock_import/commit.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"batch_id\":$BATCH_ID}" > /dev/null
SEC_COUNTER_ID=$(curl -s -b $jar_admin $BASE/api/users.php | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $u) if($u["username"]=="sec_counter") echo $u["id"];')
SEC_COUNTER_P2_ID=$(curl -s -b $jar_admin $BASE/api/users.php | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $u) if($u["username"]=="sec_counter_p2") echo $u["id"];')
SESSION=$(curl -s -b $jar_admin -X POST $BASE/api/sessions.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"name\":\"Sec Test\",\"location_id\":$LOC_ID,\"scope_type\":\"CATEGORY\",\"category_id\":$CAT_ID}")
SEC_SESSION_ID=$(jget "$SESSION" "['data']['id']")
curl -s -b $jar_admin -X POST $BASE/api/sessions/assign.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SEC_SESSION_ID,\"user_id\":$SEC_COUNTER_ID,\"team\":\"P1\"}" > /dev/null
curl -s -b $jar_admin -X POST $BASE/api/sessions/assign.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SEC_SESSION_ID,\"user_id\":$SEC_COUNTER_P2_ID,\"team\":\"P2\"}" > /dev/null
curl -s -b $jar_admin -X POST $BASE/api/sessions/start.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SEC_SESSION_ID}" > /dev/null

echo "=== Unauthenticated access ==="
code=$(curl -s -o /dev/null -w "%{http_code}" $BASE/api/items.php)
check "GET /api/items.php with no session" "401" "$code"

echo ""
echo "=== COUNTER hitting SUPERADMIN-only endpoints (must be 403, not silently filtered) ==="
code=$(curl -s -o /dev/null -w "%{http_code}" -b $jar_counter "$BASE/api/review/items.php?session_id=1")
check "COUNTER -> GET /api/review/items.php" "403" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -b $jar_counter "$BASE/api/review/progress.php?session_id=1")
check "COUNTER -> GET /api/review/progress.php" "403" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -b $jar_counter -X POST $BASE/api/review/recount.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_C" -d '{"session_item_id":1,"reason":"x"}')
check "COUNTER -> POST /api/review/recount.php" "403" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -b $jar_counter -X POST $BASE/api/review/not_countable.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_C" -d '{"session_item_id":1,"reason":"x"}')
check "COUNTER -> POST /api/review/not_countable.php" "403" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -b $jar_counter -X POST $BASE/api/sessions.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_C" -d '{"name":"x","location_id":1}')
check "COUNTER -> POST /api/sessions.php (create)" "403" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -b $jar_counter -X POST $BASE/api/sessions/start.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_C" -d '{"session_id":1}')
check "COUNTER -> POST /api/sessions/start.php" "403" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -b $jar_counter -X POST $BASE/api/categories.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_C" -d '{"code":"X","name":"X"}')
check "COUNTER -> POST /api/categories.php (master data write)" "403" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -b $jar_counter -X POST $BASE/api/stock_import/preview.php -H "X-CSRF-Token: $CSRF_C" -F "location_id=1" -F "file=@/dev/null;filename=x.csv")
check "COUNTER -> POST /api/stock_import/preview.php" "403" "$code"

echo ""
echo "=== IDOR: does the COUNTER item-list response body contain forbidden fields? ==="
RESP_P2=$(curl -s -c /tmp/sec_jar_p2.txt -X POST $BASE/api/auth/login.php -H "Content-Type: application/json" -d '{"username":"sec_counter_p2","password":"password123"}')
CSRF_P2=$(echo "$RESP_P2" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["csrf_token"] ?? "";')
ITEMS_P1=$(curl -s -b $jar_counter "$BASE/api/counter/items.php?session_id=$SEC_SESSION_ID")
SEC_ITEM_ID=$(jget "$ITEMS_P1" "['data'][0]['session_item_id']")
curl -s -b $jar_counter -X POST $BASE/api/counter/lock.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_C" -d "{\"session_item_id\":$SEC_ITEM_ID}" > /dev/null
curl -s -b $jar_counter -X POST $BASE/api/counter/count.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_C" \
  -d "{\"session_item_id\":$SEC_ITEM_ID,\"good_base_input_qty\":777,\"damaged_qty\":0,\"expired_qty\":0,\"deadstock_qty\":0}" > /dev/null
curl -s -b /tmp/sec_jar_p2.txt -X POST $BASE/api/counter/lock.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P2" -d "{\"session_item_id\":$SEC_ITEM_ID}" > /dev/null
curl -s -b /tmp/sec_jar_p2.txt -X POST $BASE/api/counter/count.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P2" \
  -d "{\"session_item_id\":$SEC_ITEM_ID,\"good_base_input_qty\":424242,\"damaged_qty\":0,\"expired_qty\":0,\"deadstock_qty\":0}" > /dev/null

ITEMS_BODY=$(curl -s -b $jar_counter "$BASE/api/counter/items.php?session_id=$SEC_SESSION_ID")
echo "P1's view after both teams have counted (P1=777, P2=424242, a real MISMATCH server-side): $ITEMS_BODY"

# P1's OWN value (777) legitimately appears — only P2's distinct value
# (424242) and the reconciliation-only fields must never leak.
FORBIDDEN_KEYS="system_qty_snapshot unit_cost_snapshot variance_p1 variance_p2 variance_qty variance_value MISMATCH CONDITION_MISMATCH 424242"
FOUND_LEAK=0
for key in $FORBIDDEN_KEYS; do
  if echo "$ITEMS_BODY" | grep -q "$key"; then
    echo "[FAIL] Forbidden content '$key' found in P1's COUNTER response body"
    FOUND_LEAK=1
    FAIL=$((FAIL+1))
  fi
done
if [ "$FOUND_LEAK" -eq 0 ]; then
  echo "[PASS] P1's response contains none of: system_qty, unit_cost, variance, MISMATCH label, or P2's own qty value (424242) — even though a real mismatch exists server-side"
  PASS=$((PASS+1))
fi

REVIEW_CHECK=$(curl -s -b $jar_admin "$BASE/api/review/items.php?session_id=$SEC_SESSION_ID")
if echo "$REVIEW_CHECK" | grep -q "MISMATCH"; then
  echo "[PASS] Meanwhile SUPERADMIN's review endpoint DOES correctly show MISMATCH for the same item"
  PASS=$((PASS+1))
else
  echo "[FAIL] SUPERADMIN review endpoint did not report the expected MISMATCH"
  FAIL=$((FAIL+1))
fi

mysql -uroot stok_opname -e "
DELETE l FROM stock_opname_item_locks l JOIN stock_opname_session_items si ON si.id=l.session_item_id JOIN stock_opname_sessions s ON s.id=si.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='SEC-LOC';
DELETE c FROM stock_opname_counts c JOIN stock_opname_session_items si ON si.id=c.session_item_id JOIN stock_opname_sessions s ON s.id=si.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='SEC-LOC';
DELETE cr FROM stock_opname_count_revisions cr LEFT JOIN stock_opname_counts c ON c.id=cr.count_id WHERE c.id IS NULL;
DELETE si FROM stock_opname_session_items si JOIN stock_opname_sessions s ON s.id=si.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='SEC-LOC';
DELETE sc FROM stock_opname_session_counters sc JOIN stock_opname_sessions s ON s.id=sc.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='SEC-LOC';
DELETE al FROM audit_logs al WHERE al.actor_id IN (SELECT id FROM users WHERE username LIKE 'sec_%');
DELETE s FROM stock_opname_sessions s JOIN locations loc ON loc.id=s.location_id WHERE loc.code='SEC-LOC';
DELETE a FROM item_stock_adjustments a JOIN item_stock s2 ON s2.id=a.item_stock_id JOIN items i ON i.id=s2.item_id WHERE i.sku='SEC-ITEM';
DELETE s2 FROM item_stock s2 JOIN items i ON i.id=s2.item_id WHERE i.sku='SEC-ITEM';
DELETE r FROM stock_import_rows r JOIN stock_import_batches b ON b.id=r.batch_id JOIN locations l ON l.id=b.location_id WHERE l.code='SEC-LOC';
DELETE b FROM stock_import_batches b JOIN locations l ON l.id=b.location_id WHERE l.code='SEC-LOC';
DELETE FROM items WHERE sku='SEC-ITEM';
DELETE FROM categories WHERE code='SEC-CAT';
DELETE FROM locations WHERE code='SEC-LOC';
DELETE FROM users WHERE username LIKE 'sec_%';
" > /dev/null 2>&1

echo ""
echo "==================================="
echo "PASS: $PASS  FAIL: $FAIL"
