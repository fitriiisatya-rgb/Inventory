#!/usr/bin/env bash
# Builds the fail-closed Sidebar "Laporan" cleanup production package (NOT a deploy).
# Usage: [SBC_REV=<commit>] [SBC_BASE=<commit>] bash scripts/build_sbc_package.sh <output dir>   -> <out>/sbc_production_deploy_package.tar.gz
# SBC_BASE = the last commit BEFORE the cleanup (source of the audited "old" blocks and of the reference hash); SBC_REV = the cleanup commit.
set -eu
cd "$(dirname "$0")/.."
SBC_REV="${SBC_REV:-87652af}"
SBC_BASE="${SBC_BASE:-39fc986}"
OUT="${1:?usage: build_sbc_package.sh <output dir>}"
N=sbc_production_deploy_package
D="$(mktemp -d)"; trap 'rm -rf "${D:?}"' EXIT
R="$D/$N"; mkdir -p "$R/scripts/lib" "$R/payload"
git show "$SBC_REV:public/index.html" > "$D/new.html"
git show "$SBC_BASE:public/index.html" > "$D/old.html"
python3 - "$D/new.html" "$D/old.html" "$R/payload" <<'PY'
import sys
new = open(sys.argv[1], encoding='utf-8').read()
old = open(sys.argv[2], encoding='utf-8').read()
out = sys.argv[3]
def w(name, text):
    open(out + '/' + name, 'w', encoding='utf-8', newline='').write(text)
START_OLD = '                <div class="sidebar-submenu" hidden>\n                    <a class="sidebar-link" data-tab="laporan-ringkasan"'
END_OLD = '<span class="icon">📜</span> Audit Transaksi</a>\n                </div>\n            </div>\n'
a = old.index(START_OLD); b = old.index(END_OLD, a) + len(END_OLD)
assert old.count(old[a:b]) == 1
w('sbc_laporan_old.txt', old[a:b])
START_NEW = '                <div class="sidebar-submenu" hidden>\n                    <a class="sidebar-link" data-tab="laporan-pergerakan"'
END_NEW = '<span class="icon">📜</span> Audit Transaksi</a>\n            </div>\n'
a = new.index(START_NEW); b = new.index(END_NEW, new.index('sidebar-legacy-routes')) + len(END_NEW)
assert new.count(new[a:b]) == 1
w('sbc_laporan_new.txt', new[a:b])
O0 = '                    <!-- PHASE V2.16.4 (stock-opname-report branch)'
O1 = '<span class="icon">🧾</span> Laporan Stock Opname</a>\n'
a = old.index(O0); b = old.index(O1, a) + len(O1)
assert old.count(old[a:b]) == 1
w('sbc_opname_old.txt', old[a:b])
N0 = '                    <!-- "Laporan Stock Opname" (data-tab opname-laporan) now lives'
a = new.index(N0); b = new.index('-->\n', a) + 4
assert new.count(new[a:b]) == 1
w('sbc_opname_new.txt', new[a:b])
PY
for f in patch_sbc_index_html_production.php rollback_sbc_production.php lib/jejak_patch_common.php; do cp "scripts/$f" "$R/scripts/$f"; done
hp() { sha256sum "$R/payload/$1" | cut -d' ' -f1; }
REFS="   $(git show "$SBC_BASE:public/index.html" | sha256sum | cut -d' ' -f1)  public/index.html (repo @ $SBC_BASE, before the cleanup)\n"
python3 - "$R/collect_production_hashes_sbc.sh" "scripts/sbc_package/collect_production_hashes_sbc.sh" "$REFS" <<'PY'
import sys
t = open(sys.argv[2], encoding='utf-8').read().replace('@@REFS@@', sys.argv[3].replace('\\n', '\n').rstrip('\n'))
open(sys.argv[1], 'w', encoding='utf-8', newline='').write(t)
PY
sed "s/@@H_LOLD@@/$(hp sbc_laporan_old.txt)/g; s/@@H_LNEW@@/$(hp sbc_laporan_new.txt)/g; s/@@H_OOLD@@/$(hp sbc_opname_old.txt)/g; s/@@H_ONEW@@/$(hp sbc_opname_new.txt)/g" scripts/sbc_package/README_DEPLOY_sbc.md.tpl > "$R/README_DEPLOY_sbc.md"
( cd "$R" && find . -type f ! -name SHA256SUMS | sort | xargs sha256sum | sed 's#  \./#  #' > SHA256SUMS )
mkdir -p "$OUT"
tar -C "$D" -czf "$OUT/$N.tar.gz" "$N"
echo "built $OUT/$N.tar.gz (from $SBC_REV, base $SBC_BASE)"; sha256sum "$OUT/$N.tar.gz"
