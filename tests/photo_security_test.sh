#!/bin/bash
# HTTP-level photo evidence tests against a live server: a real multipart
# upload through the full uploadPhoto() path (MIME sniff + GD decode),
# wrong-MIME rejection, oversized rejection, IDOR on the view endpoint,
# and the mandatory-evidence gate (design review test matrix A-C, D, E, I).
cd "$(dirname "$0")/.."

BASE=http://127.0.0.1:8098
jar_admin=/tmp/photo_jar_admin.txt
jar_p1=/tmp/photo_jar_p1.txt
jar_p2=/tmp/photo_jar_p2.txt
rm -f $jar_admin $jar_p1 $jar_p2

PASS=0; FAIL=0
check() {
  local desc="$1" expected="$2" actual="$3"
  if [ "$expected" == "$actual" ]; then echo "[PASS] $desc"; PASS=$((PASS+1));
  else echo "[FAIL] $desc (expected $expected, got $actual)"; FAIL=$((FAIL+1)); fi
}

jget() { echo "$1" | php -r "\$d=json_decode(file_get_contents('php://stdin'),true); echo \$d$2;"; }

echo "=== SETUP ==="
RESP=$(curl -s -c $jar_admin -X POST $BASE/api/auth/login.php -H "Content-Type: application/json" -d '{"username":"superadmin","password":"ChangeMe123"}')
CSRF_A=$(jget "$RESP" "['csrf_token']")

CAT=$(curl -s -b $jar_admin -X POST $BASE/api/categories.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d '{"code":"PHT-CAT","name":"Photo HTTP Test"}')
CAT_ID=$(jget "$CAT" "['data']['id']")
LOC=$(curl -s -b $jar_admin -X POST $BASE/api/locations.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d '{"code":"PHT-LOC","name":"Photo HTTP Location"}')
LOC_ID=$(jget "$LOC" "['data']['id']")
curl -s -b $jar_admin -X POST $BASE/api/items.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"sku\":\"PHT-ITEM\",\"name\":\"Photo HTTP Item\",\"category_id\":$CAT_ID,\"buy_unit\":\"Pcs\",\"buy_content\":1,\"base_unit\":\"Pcs\",\"last_buy_price\":100}" > /dev/null

printf 'sku,system_qty_base,unit_cost\nPHT-ITEM,50,100\n' > /tmp/pht_import.csv
PREVIEW=$(curl -s -b $jar_admin -X POST $BASE/api/stock_import/preview.php -H "X-CSRF-Token: $CSRF_A" -F "location_id=$LOC_ID" -F "file=@/tmp/pht_import.csv;type=text/csv")
BATCH_ID=$(jget "$PREVIEW" "['batch_id']")
curl -s -b $jar_admin -X POST $BASE/api/stock_import/commit.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"batch_id\":$BATCH_ID}" > /dev/null

curl -s -b $jar_admin -X POST $BASE/api/users.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d '{"username":"pht_p1","password":"password123","full_name":"Pht P1","role":"COUNTER","team":"P1"}' > /dev/null
curl -s -b $jar_admin -X POST $BASE/api/users.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d '{"username":"pht_p2","password":"password123","full_name":"Pht P2","role":"COUNTER","team":"P2"}' > /dev/null
P1_ID=$(curl -s -b $jar_admin $BASE/api/users.php | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $u) if($u["username"]=="pht_p1") echo $u["id"];')
P2_ID=$(curl -s -b $jar_admin $BASE/api/users.php | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $u) if($u["username"]=="pht_p2") echo $u["id"];')

SESSION=$(curl -s -b $jar_admin -X POST $BASE/api/sessions.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"name\":\"Photo HTTP Session\",\"location_id\":$LOC_ID,\"scope_type\":\"CATEGORY\",\"category_id\":$CAT_ID}")
SESSION_ID=$(jget "$SESSION" "['data']['id']")
curl -s -b $jar_admin -X POST $BASE/api/sessions/assign.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID,\"user_id\":$P1_ID,\"team\":\"P1\"}" > /dev/null
curl -s -b $jar_admin -X POST $BASE/api/sessions/assign.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID,\"user_id\":$P2_ID,\"team\":\"P2\"}" > /dev/null
curl -s -b $jar_admin -X POST $BASE/api/sessions/start.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" -d "{\"session_id\":$SESSION_ID}" > /dev/null

RESP_P1=$(curl -s -c $jar_p1 -X POST $BASE/api/auth/login.php -H "Content-Type: application/json" -d '{"username":"pht_p1","password":"password123"}')
CSRF_P1=$(jget "$RESP_P1" "['csrf_token']")
RESP_P2=$(curl -s -c $jar_p2 -X POST $BASE/api/auth/login.php -H "Content-Type: application/json" -d '{"username":"pht_p2","password":"password123"}')
CSRF_P2=$(jget "$RESP_P2" "['csrf_token']")

ITEMS=$(curl -s -b $jar_p1 "$BASE/api/counter/items.php?session_id=$SESSION_ID")
SESSION_ITEM_ID=$(jget "$ITEMS" "['data'][0]['session_item_id']")

echo ""
echo "=== A. Damaged > 0 with no photo -> count cannot be COMPLETE ==="
curl -s -b $jar_p1 -X POST $BASE/api/counter/lock.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P1" -d "{\"session_item_id\":$SESSION_ITEM_ID}" > /dev/null
SAVE=$(curl -s -b $jar_p1 -X POST $BASE/api/counter/count.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P1" \
  -d "{\"session_item_id\":$SESSION_ITEM_ID,\"good_base_input_qty\":10,\"damaged_qty\":2,\"damaged_unit\":\"Pcs\",\"expired_qty\":0,\"deadstock_qty\":0}")
STATUS_A=$(jget "$SAVE" "['data']['evidence_status']")
check "A: no photo -> evidence_status=EVIDENCE_REQUIRED" "EVIDENCE_REQUIRED" "$STATUS_A"
COUNT_ID=$(jget "$SAVE" "['data']['count_id']")

echo ""
echo "=== D. Wrong MIME: upload a renamed non-image file ==="
echo '<?php echo "not an image"; ?>' > /tmp/fake_photo.jpg
UPLOAD_FAKE=$(curl -s -w "\nHTTP:%{http_code}" -b $jar_p1 -X POST $BASE/api/counter/photos/upload.php -H "X-CSRF-Token: $CSRF_P1" \
  -F "count_id=$COUNT_ID" -F "condition_type=DAMAGED" -F "file=@/tmp/fake_photo.jpg;type=image/jpeg")
echo "$UPLOAD_FAKE"
CODE_D=$(echo "$UPLOAD_FAKE" | tail -1 | cut -d: -f2)
check "D: renamed non-image is rejected" "422" "$CODE_D"

echo ""
echo "=== Upload a REAL valid JPEG for DAMAGED ==="
php -r '$im = imagecreatetruecolor(50,50); imagefill($im,0,0,imagecolorallocate($im,200,50,50)); imagejpeg($im, "/tmp/real_photo.jpg", 90); imagedestroy($im);'
UPLOAD_REAL=$(curl -s -w "\nHTTP:%{http_code}" -b $jar_p1 -X POST $BASE/api/counter/photos/upload.php -H "X-CSRF-Token: $CSRF_P1" \
  -F "count_id=$COUNT_ID" -F "condition_type=DAMAGED" -F "file=@/tmp/real_photo.jpg;type=image/jpeg")
echo "$UPLOAD_REAL"
CODE_REAL=$(echo "$UPLOAD_REAL" | tail -1 | cut -d: -f2)
check "Real JPEG upload succeeds" "201" "$CODE_REAL"
EVIDENCE_AFTER_UPLOAD=$(echo "$UPLOAD_REAL" | head -1 | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["evidence_status"] ?? "";')
check "After the only required photo -> evidence_status=COMPLETE" "COMPLETE" "$EVIDENCE_AFTER_UPLOAD"

echo ""
echo "=== C. Multiple conditions: only the ones with qty>0 need photos ==="
curl -s -b $jar_p2 -X POST $BASE/api/counter/lock.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P2" -d "{\"session_item_id\":$SESSION_ITEM_ID}" > /dev/null
SAVE_C=$(curl -s -b $jar_p2 -X POST $BASE/api/counter/count.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_P2" \
  -d "{\"session_item_id\":$SESSION_ITEM_ID,\"good_base_input_qty\":10,\"damaged_qty\":1,\"damaged_unit\":\"Pcs\",\"expired_qty\":1,\"expired_unit\":\"Pcs\",\"deadstock_qty\":0}")
echo "$SAVE_C"
EVREQ_C=$(echo "$SAVE_C" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo json_encode($d["data"]["evidence_required"]);')
COUNT_ID_C=$(jget "$SAVE_C" "['data']['count_id']")
echo "evidence_required: $EVREQ_C"
check "C: evidence_required is exactly DAMAGED+EXPIRED (DEADSTOCK, qty=0, excluded)" '["DAMAGED","EXPIRED"]' "$EVREQ_C"

echo "--- uploading DEADSTOCK evidence for a qty=0 condition should be rejected ---"
UPLOAD_ZERO_COND=$(curl -s -w "\nHTTP:%{http_code}" -b $jar_p2 -X POST $BASE/api/counter/photos/upload.php -H "X-CSRF-Token: $CSRF_P2" \
  -F "count_id=$COUNT_ID_C" -F "condition_type=DEADSTOCK" -F "file=@/tmp/real_photo.jpg;type=image/jpeg")
CODE_ZERO=$(echo "$UPLOAD_ZERO_COND" | tail -1 | cut -d: -f2)
check "B/C: uploading evidence for a qty=0 condition is rejected" "422" "$CODE_ZERO"

echo ""
echo "=== I. Unauthorized access: P1 tries to view P2's evidence photo ==="
curl -s -b $jar_p2 -X POST $BASE/api/counter/photos/upload.php -H "X-CSRF-Token: $CSRF_P2" \
  -F "count_id=$COUNT_ID_C" -F "condition_type=DAMAGED" -F "file=@/tmp/real_photo.jpg;type=image/jpeg" > /dev/null
P2_PHOTOS=$(curl -s -b $jar_p2 "$BASE/api/photos/list.php?count_id=$COUNT_ID_C")
P2_PHOTO_ID=$(jget "$P2_PHOTOS" "['data'][0]['id']")
echo "P2 photo id: $P2_PHOTO_ID"

CODE_IDOR=$(curl -s -o /dev/null -w "%{http_code}" -b $jar_p1 "$BASE/api/photos/view.php?id=$P2_PHOTO_ID")
check "I: P1 (different team) cannot view P2's photo via view.php" "403" "$CODE_IDOR"

CODE_IDOR_LIST=$(curl -s -o /dev/null -w "%{http_code}" -b $jar_p1 "$BASE/api/photos/list.php?count_id=$COUNT_ID_C")
check "I: P1 cannot even list P2's photo metadata for that count" "403" "$CODE_IDOR_LIST"

CODE_ADMIN_VIEW=$(curl -s -o /dev/null -w "%{http_code}" -b $jar_admin "$BASE/api/photos/view.php?id=$P2_PHOTO_ID")
check "SUPERADMIN can view the same photo P1 was denied" "200" "$CODE_ADMIN_VIEW"

echo ""
echo "=== J. Path traversal / non-numeric id handling ==="
CODE_TRAVERSAL=$(curl -s -o /dev/null -w "%{http_code}" -b $jar_admin "$BASE/api/photos/view.php?id=../../../../etc/passwd")
check "J: non-numeric/path-like id is rejected cleanly (cast to int, never used as a path)" "422" "$CODE_TRAVERSAL"

echo ""
echo "=== E. Oversized upload rejected server-side (real lock holder, real oversized file) ==="
# P2 still holds the lock on COUNT_ID_C here: DAMAGED was just satisfied
# above but EXPIRED (qty>0) is still pending, so evidence_status is still
# EVIDENCE_REQUIRED and the lock was never released. This targets EXPIRED
# specifically so the request reaches the actual size check, not an
# unrelated 403/ownership rejection.
php -r '$im = imagecreatetruecolor(2000,2000); imagejpeg($im, "/tmp/oversized.jpg", 100);'
# Pad past the 8192 KB default limit with valid trailing JPEG-adjacent bytes
# appended after a real JPEG stream (size is checked before any decode is attempted).
head -c 9437184 /dev/urandom >> /tmp/oversized.jpg
UPLOAD_OVERSIZED=$(curl -s -w "\nHTTP:%{http_code}" -b $jar_p2 -X POST $BASE/api/counter/photos/upload.php -H "X-CSRF-Token: $CSRF_P2" \
  -F "count_id=$COUNT_ID_C" -F "condition_type=EXPIRED" -F "file=@/tmp/oversized.jpg;type=image/jpeg")
echo "$UPLOAD_OVERSIZED"
CODE_OVERSIZED=$(echo "$UPLOAD_OVERSIZED" | tail -1 | cut -d: -f2)
check "E: oversized file (>8192 KB) rejected with 422 by the real lock holder" "422" "$CODE_OVERSIZED"

echo ""
echo "=== Recount round isolation over HTTP ==="
RECOUNT=$(curl -s -b $jar_admin -X POST $BASE/api/review/recount.php -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"session_item_id\":$SESSION_ITEM_ID,\"reason\":\"Verifikasi ulang\"}")
echo "$RECOUNT"
ITEMS_AFTER_RECOUNT=$(curl -s -b $jar_p1 "$BASE/api/counter/items.php?session_id=$SESSION_ID")
STATUS_AFTER_RECOUNT=$(echo "$ITEMS_AFTER_RECOUNT" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach($d["data"] as $r) if($r["session_item_id"]=='"$SESSION_ITEM_ID"') echo $r["status"];')
check "F: after recount, counter sees HITUNG_ULANG (not the old evidence_status)" "HITUNG_ULANG" "$STATUS_AFTER_RECOUNT"

echo ""
echo "=== CLEANUP ==="
mysql -uroot stok_opname -e "
DELETE p FROM stock_opname_photos p JOIN stock_opname_sessions s ON s.id=p.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='PHT-LOC';
DELETE l FROM stock_opname_item_locks l JOIN stock_opname_session_items si ON si.id=l.session_item_id JOIN stock_opname_sessions s ON s.id=si.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='PHT-LOC';
DELETE cr FROM stock_opname_count_revisions cr
  JOIN stock_opname_counts c ON c.id=cr.count_id
  JOIN stock_opname_session_items si ON si.id=c.session_item_id
  JOIN stock_opname_sessions s ON s.id=si.session_id
  JOIN locations loc ON loc.id=s.location_id WHERE loc.code='PHT-LOC';
DELETE c FROM stock_opname_counts c JOIN stock_opname_session_items si ON si.id=c.session_item_id JOIN stock_opname_sessions s ON s.id=si.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='PHT-LOC';
DELETE rc FROM stock_opname_recounts rc JOIN stock_opname_session_items si ON si.id=rc.session_item_id JOIN stock_opname_sessions s ON s.id=si.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='PHT-LOC';
DELETE si FROM stock_opname_session_items si JOIN stock_opname_sessions s ON s.id=si.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='PHT-LOC';
DELETE sc FROM stock_opname_session_counters sc JOIN stock_opname_sessions s ON s.id=sc.session_id JOIN locations loc ON loc.id=s.location_id WHERE loc.code='PHT-LOC';
DELETE FROM audit_logs WHERE actor_id IN (SELECT id FROM users WHERE username LIKE 'pht_%');
DELETE s FROM stock_opname_sessions s JOIN locations loc ON loc.id=s.location_id WHERE loc.code='PHT-LOC';
DELETE a FROM item_stock_adjustments a JOIN item_stock st ON st.id=a.item_stock_id JOIN items i ON i.id=st.item_id WHERE i.sku='PHT-ITEM';
DELETE st FROM item_stock st JOIN items i ON i.id=st.item_id WHERE i.sku='PHT-ITEM';
DELETE r FROM stock_import_rows r JOIN stock_import_batches b ON b.id=r.batch_id JOIN locations l ON l.id=b.location_id WHERE l.code='PHT-LOC';
DELETE b FROM stock_import_batches b JOIN locations l ON l.id=b.location_id WHERE l.code='PHT-LOC';
DELETE FROM items WHERE sku='PHT-ITEM';
DELETE FROM categories WHERE code='PHT-CAT';
DELETE FROM locations WHERE code='PHT-LOC';
DELETE FROM users WHERE username LIKE 'pht_%';
" 2>&1
rm -f /tmp/fake_photo.jpg /tmp/real_photo.jpg /tmp/oversized.jpg /tmp/pht_import.csv

echo ""
echo "==================================="
echo "PASS: $PASS  FAIL: $FAIL"
