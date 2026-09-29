#!/bin/bash
# Real concurrency tests against a live PHP dev server (multi-worker, so
# requests genuinely overlap rather than being serialized by a
# single-threaded built-in server). Exercises design review point 25.
cd "$(dirname "$0")/.."

jar_admin=/tmp/conc_jar_admin.txt
jar_andi=/tmp/conc_jar_andi.txt
jar_budi=/tmp/conc_jar_budi.txt
jar_rian=/tmp/conc_jar_rian.txt
rm -f $jar_admin $jar_andi $jar_budi $jar_rian

BASE=http://127.0.0.1:8098

login() { curl -s -c "$1" -X POST $BASE/api/auth/login.php -H "Content-Type: application/json" -d "{\"username\":\"$2\",\"password\":\"$3\"}"; }
csrf_of() { echo "$1" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["csrf_token"];'; }
jget() { echo "$1" | php -r "\$d=json_decode(file_get_contents('php://stdin'),true); echo \$d$2;"; }

echo "=== SETUP ==="
RESP=$(login $jar_admin superadmin ChangeMe123)
CSRF_A=$(csrf_of "$RESP")

CAT=$(curl -s -b $jar_admin -X POST $BASE/api/categories.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d '{"code":"CONC-CAT","name":"Concurrency Test"}')
CAT_ID=$(jget "$CAT" "['data']['id']")
LOC=$(curl -s -b $jar_admin -X POST $BASE/api/locations.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d '{"code":"CONC-LOC","name":"Concurrency Location"}')
LOC_ID=$(jget "$LOC" "['data']['id']")

ITEM=$(curl -s -b $jar_admin -X POST $BASE/api/items.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"sku\":\"CONC-ITEM\",\"name\":\"Concurrency Item\",\"category_id\":$CAT_ID,\"buy_unit\":\"Pcs\",\"buy_content\":1,\"base_unit\":\"Pcs\",\"last_buy_price\":100}")
echo "$ITEM"

cat > /tmp/conc_import.csv <<CSV
sku,system_qty_base,unit_cost
CONC-ITEM,50,100
CSV
PREVIEW=$(curl -s -b $jar_admin -X POST $BASE/api/stock_import/preview.php -H "X-CSRF-Token: $CSRF_A" -F "location_id=$LOC_ID" -F "file=@/tmp/conc_import.csv;type=text/csv")
BATCH_ID=$(jget "$PREVIEW" "['batch_id']")
curl -s -b $jar_admin -X POST $BASE/api/stock_import/commit.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"batch_id\":$BATCH_ID}" > /dev/null

for spec in "conc_andi:P1" "conc_budi:P1" "conc_rian:P2"; do
  uname="${spec%%:*}"; team="${spec##*:}"
  curl -s -b $jar_admin -X POST $BASE/api/users.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
    -d "{\"username\":\"$uname\",\"password\":\"password123\",\"full_name\":\"$uname\",\"role\":\"COUNTER\",\"team\":\"$team\"}" > /dev/null
done
ANDI_ID=$(curl -s -b $jar_admin $BASE/api/users.php | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $u) if($u["username"]=="conc_andi") echo $u["id"];')
BUDI_ID=$(curl -s -b $jar_admin $BASE/api/users.php | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $u) if($u["username"]=="conc_budi") echo $u["id"];')
RIAN_ID=$(curl -s -b $jar_admin $BASE/api/users.php | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $u) if($u["username"]=="conc_rian") echo $u["id"];')
echo "ANDI=$ANDI_ID BUDI=$BUDI_ID RIAN=$RIAN_ID"

SESSION=$(curl -s -b $jar_admin -X POST $BASE/api/sessions.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"name\":\"Concurrency Session\",\"location_id\":$LOC_ID,\"scope_type\":\"CATEGORY\",\"category_id\":$CAT_ID}")
SESSION_ID=$(jget "$SESSION" "['data']['id']")
echo "SESSION_ID=$SESSION_ID"

curl -s -b $jar_admin -X POST $BASE/api/sessions/assign.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID,\"user_id\":$ANDI_ID,\"team\":\"P1\"}" > /dev/null
curl -s -b $jar_admin -X POST $BASE/api/sessions/assign.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID,\"user_id\":$BUDI_ID,\"team\":\"P1\"}" > /dev/null
curl -s -b $jar_admin -X POST $BASE/api/sessions/assign.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID,\"user_id\":$RIAN_ID,\"team\":\"P2\"}" > /dev/null

curl -s -b $jar_admin -X POST $BASE/api/sessions/start.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID}" > /dev/null

RESP_ANDI=$(login $jar_andi conc_andi password123)
CSRF_ANDI=$(csrf_of "$RESP_ANDI")
RESP_BUDI=$(login $jar_budi conc_budi password123)
CSRF_BUDI=$(csrf_of "$RESP_BUDI")
RESP_RIAN=$(login $jar_rian conc_rian password123)
CSRF_RIAN=$(csrf_of "$RESP_RIAN")

ITEMS=$(curl -s -b $jar_andi "$BASE/api/counter/items.php?session_id=$SESSION_ID")
SESSION_ITEM_ID=$(jget "$ITEMS" "['data'][0]['session_item_id']")
echo "SESSION_ITEM_ID=$SESSION_ITEM_ID"

echo ""
echo "=== TEST 1: SAME TEAM — Andi(P1) and Budi(P1) acquire simultaneously ==="
curl -s -w "\nHTTP:%{http_code}\n" -b $jar_andi -X POST $BASE/api/counter/lock.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_ANDI" -d "{\"session_item_id\":$SESSION_ITEM_ID}" > /tmp/conc_r_andi.txt &
PID1=$!
curl -s -w "\nHTTP:%{http_code}\n" -b $jar_budi -X POST $BASE/api/counter/lock.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_BUDI" -d "{\"session_item_id\":$SESSION_ITEM_ID}" > /tmp/conc_r_budi.txt &
PID2=$!
wait $PID1 $PID2

echo "--- Andi result ---"; cat /tmp/conc_r_andi.txt
echo "--- Budi result ---"; cat /tmp/conc_r_budi.txt

ANDI_OK=$(grep -c '"ok":true' /tmp/conc_r_andi.txt || true)
BUDI_OK=$(grep -c '"ok":true' /tmp/conc_r_budi.txt || true)
TOTAL_OK=$((ANDI_OK + BUDI_OK))
echo "RESULT: total successes among same-team pair = $TOTAL_OK (expected exactly 1)"
if [ "$TOTAL_OK" -eq 1 ]; then echo "PASS: exactly one same-team acquirer won"; else echo "FAIL: expected exactly 1, got $TOTAL_OK"; fi

if [ "$ANDI_OK" -eq 1 ]; then WINNER_JAR=$jar_andi; WINNER_CSRF=$CSRF_ANDI; WINNER_NAME=andi; LOSER_JAR=$jar_budi; LOSER_CSRF=$CSRF_BUDI; LOSER_NAME=budi;
else WINNER_JAR=$jar_budi; WINNER_CSRF=$CSRF_BUDI; WINNER_NAME=budi; LOSER_JAR=$jar_andi; LOSER_CSRF=$CSRF_ANDI; LOSER_NAME=andi; fi
echo "Winner: $WINNER_NAME, Loser: $LOSER_NAME"

echo ""
echo "=== TEST 2: DIFFERENT TEAM — Rian(P2) acquires the SAME item while $WINNER_NAME(P1) still holds it ==="
curl -s -w "\nHTTP:%{http_code}\n" -b $jar_rian -X POST $BASE/api/counter/lock.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_RIAN" -d "{\"session_item_id\":$SESSION_ITEM_ID}"
echo "(expected: ok:true — different team, independent lock slot)"

echo ""
echo "=== TEST 3: WRONG OWNER SAVE — $LOSER_NAME (P1, does not hold the lock) tries to save a count ==="
curl -s -w "\nHTTP:%{http_code}\n" -b $LOSER_JAR -X POST $BASE/api/counter/count.php -H "Content-Type: application/json" -H "X-CSRF-Token: $LOSER_CSRF" \
  -d "{\"session_item_id\":$SESSION_ITEM_ID,\"good_base_input_qty\":999,\"damaged_qty\":0,\"expired_qty\":0,\"deadstock_qty\":0}"
echo "(expected: HTTP 409, CountLockException)"

echo ""
echo "=== TEST 4: EXPIRED LOCK TAKEOVER — force-expire $WINNER_NAME's lock, then $LOSER_NAME acquires ==="
mysql -uroot stok_opname -e "UPDATE stock_opname_item_locks SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE session_item_id=$SESSION_ITEM_ID AND team='P1'"
curl -s -w "\nHTTP:%{http_code}\n" -b $LOSER_JAR -X POST $BASE/api/counter/lock.php -H "Content-Type: application/json" -H "X-CSRF-Token: $LOSER_CSRF" -d "{\"session_item_id\":$SESSION_ITEM_ID}"
echo "(expected: ok:true — expired lock reaped, new acquirer wins)"

echo ""
echo "=== TEST 5: WINNER (whose lock just got taken over) now tries to save — should ALSO be rejected ==="
curl -s -w "\nHTTP:%{http_code}\n" -b $WINNER_JAR -X POST $BASE/api/counter/count.php -H "Content-Type: application/json" -H "X-CSRF-Token: $WINNER_CSRF" \
  -d "{\"session_item_id\":$SESSION_ITEM_ID,\"good_base_input_qty\":1,\"damaged_qty\":0,\"expired_qty\":0,\"deadstock_qty\":0}"
echo "(expected: HTTP 409 — their lock is gone, taken over by $LOSER_NAME)"

echo ""
echo "=== CLEANUP ==="
mysql -uroot stok_opname -e "
DELETE l FROM stock_opname_item_locks l JOIN stock_opname_session_items si ON si.id=l.session_item_id JOIN stock_opname_sessions s ON s.id=si.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='CONC-LOC';
DELETE c FROM stock_opname_counts c JOIN stock_opname_session_items si ON si.id=c.session_item_id JOIN stock_opname_sessions s ON s.id=si.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='CONC-LOC';
DELETE cr FROM stock_opname_count_revisions cr LEFT JOIN stock_opname_counts c ON c.id=cr.count_id WHERE c.id IS NULL;
DELETE si FROM stock_opname_session_items si JOIN stock_opname_sessions s ON s.id=si.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='CONC-LOC';
DELETE sc FROM stock_opname_session_counters sc JOIN stock_opname_sessions s ON s.id=sc.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='CONC-LOC';
DELETE al FROM audit_logs al WHERE al.actor_id IN (SELECT id FROM users WHERE username LIKE 'conc_%' OR username='superadmin_placeholder_never_matches');
DELETE s FROM stock_opname_sessions s JOIN locations loc ON loc.id=s.location_id WHERE loc.code='CONC-LOC';
DELETE a FROM item_stock_adjustments a JOIN item_stock s2 ON s2.id=a.item_stock_id JOIN items i ON i.id=s2.item_id WHERE i.sku='CONC-ITEM';
DELETE s2 FROM item_stock s2 JOIN items i ON i.id=s2.item_id WHERE i.sku='CONC-ITEM';
DELETE r FROM stock_import_rows r JOIN stock_import_batches b ON b.id=r.batch_id JOIN locations l ON l.id=b.location_id WHERE l.code='CONC-LOC';
DELETE b FROM stock_import_batches b JOIN locations l ON l.id=b.location_id WHERE l.code='CONC-LOC';
DELETE FROM items WHERE sku='CONC-ITEM';
DELETE FROM categories WHERE code='CONC-CAT';
DELETE FROM locations WHERE code='CONC-LOC';
DELETE FROM users WHERE username LIKE 'conc_%';
" 2>&1
echo "done"
