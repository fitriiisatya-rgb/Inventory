#!/bin/bash
set -e
cd /home/user/Inventory

jar_admin=/tmp/jar_admin.txt; jar_andi=/tmp/jar_andi.txt; jar_rian=/tmp/jar_rian.txt
rm -f $jar_admin $jar_andi $jar_rian

login() {
  local jar=$1 user=$2 pass=$3
  curl -s -c $jar -X POST http://127.0.0.1:8099/api/auth/login.php -H "Content-Type: application/json" -d "{\"username\":\"$user\",\"password\":\"$pass\"}"
}
csrf_of() {
  echo "$1" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["csrf_token"];'
}
field() {
  echo "$1" | php -r "\$d=json_decode(file_get_contents('php://stdin'),true); echo \$d['$2'];"
}

echo "--- login superadmin ---"
RESP=$(login $jar_admin superadmin ChangeMe123)
CSRF_A=$(csrf_of "$RESP")
echo "csrf=$CSRF_A"

echo "--- create category & location ---"
CAT=$(curl -s -b $jar_admin -X POST http://127.0.0.1:8099/api/categories.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d '{"code":"BB","name":"Bahan Baku"}')
echo "$CAT"
CAT_ID=$(echo "$CAT" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["data"]["id"];')
LOC=$(curl -s -b $jar_admin -X POST http://127.0.0.1:8099/api/locations.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d '{"code":"GUT","name":"Gudang Utama"}')
echo "$LOC"
LOC_ID=$(echo "$LOC" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["data"]["id"];')
echo "CAT_ID=$CAT_ID LOC_ID=$LOC_ID"

echo "--- create items: Keju (3-level), Minyak (2-level) ---"
ITEM_KEJU=$(curl -s -b $jar_admin -X POST http://127.0.0.1:8099/api/items.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"sku\":\"BRG-KEJU\",\"name\":\"Keju Cheddar\",\"category_id\":$CAT_ID,\"buy_unit\":\"Karton\",\"buy_content\":20000,\"mid_unit\":\"Kg\",\"mid_content\":20,\"base_unit\":\"Gr\",\"last_buy_price\":16.65}")
echo "$ITEM_KEJU"
ITEM_MINYAK=$(curl -s -b $jar_admin -X POST http://127.0.0.1:8099/api/items.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"sku\":\"BRG-MINYAK\",\"name\":\"Minyak Goreng\",\"category_id\":$CAT_ID,\"buy_unit\":\"Karton\",\"buy_content\":12,\"base_unit\":\"Pcs\",\"last_buy_price\":5000}")
echo "$ITEM_MINYAK"

echo "--- import system stock CSV ---"
cat > /tmp/import_e2e.csv <<CSV
sku,system_qty_base,unit_cost
BRG-KEJU,52200,16.65
BRG-MINYAK,120,5000
CSV
PREVIEW=$(curl -s -b $jar_admin -X POST http://127.0.0.1:8099/api/stock_import/preview.php -H "X-CSRF-Token: $CSRF_A" -F "location_id=$LOC_ID" -F "file=@/tmp/import_e2e.csv;type=text/csv")
echo "$PREVIEW"
BATCH_ID=$(field "$PREVIEW" batch_id)
COMMIT=$(curl -s -b $jar_admin -X POST http://127.0.0.1:8099/api/stock_import/commit.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"batch_id\":$BATCH_ID}")
echo "$COMMIT"

echo "--- create counter users andi(P1)/rian(P2) ---"
for u in andi:P1 rian:P2; do
  uname="${u%%:*}"; team="${u##*:}"
  curl -s -b $jar_admin -X POST http://127.0.0.1:8099/api/users.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
    -d "{\"username\":\"$uname\",\"password\":\"password123\",\"full_name\":\"$uname\",\"role\":\"COUNTER\",\"team\":\"$team\"}" > /dev/null
done
ANDI_ID=$(curl -s -b $jar_admin http://127.0.0.1:8099/api/users.php | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $u) if($u["username"]=="andi") echo $u["id"];')
RIAN_ID=$(curl -s -b $jar_admin http://127.0.0.1:8099/api/users.php | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $u) if($u["username"]=="rian") echo $u["id"];')
echo "ANDI_ID=$ANDI_ID RIAN_ID=$RIAN_ID"

echo "--- create SO session ---"
SESSION=$(curl -s -b $jar_admin -X POST http://127.0.0.1:8099/api/sessions.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"name\":\"SO Test\",\"location_id\":$LOC_ID,\"scope_type\":\"ALL\"}")
echo "$SESSION"
SESSION_ID=$(echo "$SESSION" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["data"]["id"];')
echo "SESSION_ID=$SESSION_ID"

echo "--- preflight BEFORE assignment (should have blockers) ---"
curl -s -b $jar_admin "http://127.0.0.1:8099/api/sessions/preflight.php?session_id=$SESSION_ID"
echo

echo "--- assign andi P1, rian P2 ---"
curl -s -b $jar_admin -X POST http://127.0.0.1:8099/api/sessions/assign.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID,\"user_id\":$ANDI_ID,\"team\":\"P1\"}"
echo
curl -s -b $jar_admin -X POST http://127.0.0.1:8099/api/sessions/assign.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID,\"user_id\":$RIAN_ID,\"team\":\"P2\"}"
echo

echo "--- preflight AFTER assignment (should be clean) ---"
curl -s -b $jar_admin "http://127.0.0.1:8099/api/sessions/preflight.php?session_id=$SESSION_ID"
echo

echo "--- start session ---"
curl -s -b $jar_admin -X POST http://127.0.0.1:8099/api/sessions/start.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID}"
echo

echo "--- andi (P1) logs in, lists items ---"
RESP_ANDI=$(login $jar_andi andi password123)
CSRF_ANDI=$(csrf_of "$RESP_ANDI")
ITEMS_ANDI=$(curl -s -b $jar_andi "http://127.0.0.1:8099/api/counter/items.php?session_id=$SESSION_ID")
echo "$ITEMS_ANDI"

echo "$SESSION_ID" > /tmp/e2e_session_id.txt
echo "$CSRF_A" > /tmp/e2e_csrf_admin.txt
echo "$CSRF_ANDI" > /tmp/e2e_csrf_andi.txt
