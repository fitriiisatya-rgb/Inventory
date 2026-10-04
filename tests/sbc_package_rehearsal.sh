#!/usr/bin/env bash
# Rehearses the Sidebar "Laporan" cleanup package against PRODUCTION-LIKE index.html files (never the live dev tree): the file as it
# was at four earlier revisions (the oldest pre-Jejak, the Jejak v3 era, UI2, and the revision right before the cleanup) plus a variant
# whose ?v= cache tokens a later package moved. Proves: dry-run writes nothing; wrong preimage / payload hash, a missing or duplicated
# anchor and a double apply are all refused; the patched bytes are exactly old->new in the two regions (and byte-identical to the
# committed cleanup for the pre-cleanup revision); backup == preimage; two-phase rollback restores the exact bytes, and refuses
# when the file was edited after the package.
# Usage: [SBC_REV=<commit>] [SBC_BASE=<commit>] bash tests/sbc_package_rehearsal.sh
set -u
cd "$(dirname "$0")/.."
SBC_REV="${SBC_REV:-87652af}"; SBC_BASE="${SBC_BASE:-39fc986}"
W="$(mktemp -d)"; trap 'rm -rf "${W:?}"' EXIT
pass=0; fail=0
ok()  { echo "PASS - $1"; pass=$((pass+1)); }
bad() { echo "FAIL - $1"; fail=$((fail+1)); }
expect_fail() { local d="$1"; shift; if "$@" >"$W/out.txt" 2>&1; then bad "$d (unexpectedly succeeded)"; else ok "$d"; fi; }
sha() { sha256sum "$1" | cut -d' ' -f1; }

SBC_REV="$SBC_REV" SBC_BASE="$SBC_BASE" bash scripts/build_sbc_package.sh "$W/out" >/dev/null 2>&1 || { echo "FAIL - package build"; exit 1; }
tar -C "$W" -xzf "$W/out/sbc_production_deploy_package.tar.gz"
PK="$W/sbc_production_deploy_package"; PAY="$PK/payload"; S="$PK/scripts"
( cd "$PK" && sha256sum -c SHA256SUMS >/dev/null 2>&1 ) && ok "package SHA256SUMS verify" || bad "package SHA256SUMS"
H_LO=$(sha "$PAY/sbc_laporan_old.txt"); H_LN=$(sha "$PAY/sbc_laporan_new.txt"); H_OO=$(sha "$PAY/sbc_opname_old.txt"); H_ON=$(sha "$PAY/sbc_opname_new.txt")
grep -q 'data-tab="laporan-audit"' "$PAY/sbc_laporan_old.txt" && ! grep -q 'sidebar-legacy-routes' "$PAY/sbc_laporan_old.txt" && grep -q 'sidebar-legacy-routes' "$PAY/sbc_laporan_new.txt" && ok "payload old/new blocks are the audited old and the cleaned markup" || bad "payload content"
[ "$(grep -c 'class="sidebar-link"' "$PAY/sbc_laporan_new.txt")" = 16 ] && [ "$(grep 'class="sidebar-link"' "$PAY/sbc_laporan_new.txt" | grep -c 'data-require-permission')" = 16 ] && ok "new block keeps all 16 links (5 visible + 11 hidden), each with its data-require-permission" || bad "new block link/permission count"
PH() { echo "$1 $PAY/sbc_laporan_old.txt $PAY/sbc_laporan_new.txt $PAY/sbc_opname_old.txt $PAY/sbc_opname_new.txt --expect-laporan-old-sha256=$H_LO --expect-laporan-new-sha256=$H_LN --expect-opname-old-sha256=$H_OO --expect-opname-new-sha256=$H_ON"; }

run_tree() {
  local label="$1" T="$W/tree_$1"; shift
  mkdir -p "$T/public"; P="$T/public"
  cat > "$P/index.html"
  local pre; pre=$(sha "$P/index.html"); cp "$P/index.html" "$T/pre.html"
  # dry-run writes nothing
  php "$S/patch_sbc_index_html_production.php" $(PH "$P/index.html") --expect-sha256=$pre >"$W/dry.txt" 2>&1 && [ "$(sha "$P/index.html")" = "$pre" ] && [ ! -e "$P/index.html.pre-sbc-backup" ] && ok "[$label] dry-run: exit 0, file unchanged, no backup/state" || bad "[$label] dry-run"
  # refusals
  expect_fail "[$label] wrong preimage hash refused" php "$S/patch_sbc_index_html_production.php" $(PH "$P/index.html") --expect-sha256=$(printf 0%.0s {1..64})
  expect_fail "[$label] wrong payload hash refused" php "$S/patch_sbc_index_html_production.php" $(PH "$P/index.html" | sed "s/$H_LN/$(printf 1%.0s {1..64})/") --expect-sha256=$pre
  python3 - "$P/index.html" "$T/noanchor.html" "$T/dup.html" "$PAY/sbc_opname_old.txt" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read(); o = open(sys.argv[4], encoding='utf-8').read()
open(sys.argv[2], 'w', encoding='utf-8', newline='').write(s.replace(o, ''))
open(sys.argv[3], 'w', encoding='utf-8', newline='').write(s + o)
PY
  expect_fail "[$label] missing anchor refused" php "$S/patch_sbc_index_html_production.php" $(PH "$T/noanchor.html") --expect-sha256=$(sha "$T/noanchor.html")
  expect_fail "[$label] duplicated anchor (not exactly once) refused" php "$S/patch_sbc_index_html_production.php" $(PH "$T/dup.html") --expect-sha256=$(sha "$T/dup.html")
  expect_fail "[$label] rollback before apply refused (no state file)" php "$S/rollback_sbc_production.php" --public-dir="$P"
  # apply
  php "$S/patch_sbc_index_html_production.php" $(PH "$P/index.html") --expect-sha256=$pre --apply >"$W/apply.txt" 2>&1 && ok "[$label] apply succeeds" || { bad "[$label] apply"; cat "$W/apply.txt"; }
  local post; post=$(sha "$P/index.html")
  grep -q "New       SHA256: $post" "$W/apply.txt" && ok "[$label] printed new SHA256 == file" || bad "[$label] printed hash"
  [ "$(sha "$P/index.html.pre-sbc-backup")" = "$pre" ] && ok "[$label] backup == preimage" || bad "[$label] backup"
  python3 - "$T/pre.html" "$P/index.html" "$PAY" <<'PY' && ok "[$label] patched bytes == preimage with ONLY the two blocks replaced" || bad "[$label] patched bytes differ beyond the two blocks"
import sys
pre = open(sys.argv[1], encoding='utf-8').read(); post = open(sys.argv[2], encoding='utf-8').read(); d = sys.argv[3] + '/'
r = lambda n: open(d + n, encoding='utf-8').read()
exp = pre.replace(r('sbc_laporan_old.txt'), r('sbc_laporan_new.txt'), 1).replace(r('sbc_opname_old.txt'), r('sbc_opname_new.txt'), 1)
sys.exit(0 if exp == post else 1)
PY
  # the rendered sidebar facts (static)
  local legacy; legacy=$(awk '/sidebar-legacy-routes/{f=1} f' "$P/index.html" | grep -c 'data-tab="laporan-')
  local vis; vis=$(awk '/data-group="laporan"/{f=1} /sidebar-legacy-routes/{f=0} f' "$P/index.html" | grep -o 'data-tab="[a-z-]*"' | tr '\n' ' ')
  [ "$vis" = 'data-tab="laporan-pergerakan" data-tab="laporan-inout" data-tab="laporan-pembelian" data-tab="laporan-hpp" data-tab="opname-laporan" ' ] && [ "$legacy" = 11 ] && ok "[$label] Laporan menu = the 5 tabs in order; 11 legacy links hidden" || bad "[$label] menu content: $vis / $legacy"
  [ "$(grep -c 'data-tab="opname-laporan"' "$P/index.html")" = 1 ] && ok "[$label] opname-laporan has exactly one link" || bad "[$label] opname-laporan count"
  for tabn in laporan-ringkasan laporan-stok laporan-transfer laporan-opname laporan-adjustment laporan-expiry laporan-supplier laporan-bakery laporan-slow-movement laporan-rekonsiliasi laporan-audit; do [ "$(grep -c "data-tab=\"$tabn\"" "$P/index.html")" = 1 ] || bad "[$label] legacy tab $tabn not kept exactly once"; done
  # cache tokens untouched
  [ "$(grep -o '?v=[A-Za-z0-9._-]*' "$T/pre.html" | sort | md5sum)" = "$(grep -o '?v=[A-Za-z0-9._-]*' "$P/index.html" | sort | md5sum)" ] && ok "[$label] every ?v= cache token untouched" || bad "[$label] cache tokens changed"
  # double apply
  expect_fail "[$label] second apply refused" php "$S/patch_sbc_index_html_production.php" $(PH "$P/index.html") --expect-sha256=$post --apply
  [ "$(sha "$P/index.html")" = "$post" ] && ok "[$label] file unchanged by the refused second apply" || bad "[$label] second apply changed the file"
  # rollback refuses on an edited file, then works
  cp "$P/index.html" "$T/post.html"; echo "<!-- local edit -->" >> "$P/index.html"
  expect_fail "[$label] rollback refused when index.html was edited after the package" php "$S/rollback_sbc_production.php" --public-dir="$P" --apply
  cp "$T/post.html" "$P/index.html"
  php "$S/rollback_sbc_production.php" --public-dir="$P" >/dev/null 2>&1 && [ "$(sha "$P/index.html")" = "$post" ] && ok "[$label] rollback dry-run changes nothing" || bad "[$label] rollback dry-run"
  php "$S/rollback_sbc_production.php" --public-dir="$P" --apply >/dev/null 2>&1 && [ "$(sha "$P/index.html")" = "$pre" ] && [ ! -e "$P/index.html.sbc-patch.json" ] && ok "[$label] rollback restores the exact preimage bytes and removes the state file" || bad "[$label] rollback"
  expect_fail "[$label] second rollback refused" php "$S/rollback_sbc_production.php" --public-dir="$P" --apply
  # collect script (read-only) sees the anchors on the preimage
  bash "$PK/collect_production_hashes_sbc.sh" "$P" >"$W/collect.txt" 2>&1
  grep -q "$pre  " "$W/collect.txt" && grep -q "no sidebar-cleanup leftovers" "$W/collect.txt" && grep -Eq '^1  Laporan group' "$W/collect.txt" && grep -Eq '^0  cleanup already applied' "$W/collect.txt" && ok "[$label] collect script: hash + anchor counts as documented ([1] … [0])" || bad "[$label] collect script"
  LAST_POST_TREE="$T"; LAST_POST_HASH="$post"
}

for rev in 853cd69 9dcb367 6775b7f "$SBC_BASE"; do git show "$rev:public/index.html" > "$W/src.html"; run_tree "$rev" < "$W/src.html"; done
# a later package moved the cache tokens (independence): same blocks, different ?v=
git show "$SBC_BASE:public/index.html" | sed -E 's/\?v=[A-Za-z0-9._-]+/?v=20261099-later/g' > "$W/src.html"; run_tree "later-tokens" < "$W/src.html"
# byte-equality with the committed cleanup for the pre-cleanup revision
git show "$SBC_BASE:public/index.html" > "$W/b.html"; cp "$W/b.html" "$W/bp.html"; mkdir -p "$W/bt"; cp "$W/b.html" "$W/bt/index.html"
php "$S/patch_sbc_index_html_production.php" $(PH "$W/bt/index.html") --expect-sha256=$(sha "$W/b.html") --apply >/dev/null 2>&1
git show "$SBC_REV:public/index.html" | cmp -s - "$W/bt/index.html" && ok "patched $SBC_BASE index.html is byte-identical to the committed cleanup ($SBC_REV)" || bad "patched != committed cleanup"

echo; echo "$pass passed, $fail failed"; [ "$fail" = 0 ]
