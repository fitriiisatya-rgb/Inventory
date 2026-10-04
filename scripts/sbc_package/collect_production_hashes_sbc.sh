#!/usr/bin/env bash
# READ-ONLY. Run on the production host.
# Usage: bash collect_production_hashes_sbc.sh /path/to/production/public
set -u
P="${1:?usage: collect_production_hashes_sbc.sh <public dir>}"
echo "== SHA256 of the ONE file the package changes (this is the --expect-sha256 value; send it back) =="
sha256sum "$P/index.html"
echo
echo "== reference hashes of the previously delivered repo versions of index.html (a match = exactly what the repo had) =="
cat <<REFS
@@REFS@@
REFS
echo
echo "== leftovers of this package (must be absent) =="
ls "$P"/index.html.pre-sbc-backup "$P"/index.html.sbc-patch.json 2>/dev/null || echo "no sidebar-cleanup leftovers (good)"
echo
c() { printf '%s  ' "$(grep -cF -- "$2" "$1")"; echo "$3"; }
echo "== anchor counts in index.html (expected value in brackets) =="
c "$P/index.html" 'data-group="laporan"' "Laporan group   [1]"
c "$P/index.html" 'data-tab="laporan-ringkasan"' "old first Laporan link (Ringkasan Inventory)   [1]"
c "$P/index.html" 'data-tab="laporan-audit"' "old last Laporan link (Audit Transaksi)   [1]"
c "$P/index.html" 'Pergerakan Stok Harian</a>' "old Pergerakan label   [1]"
c "$P/index.html" 'data-tab="opname-laporan"' "Laporan Stock Opname link under Stock Opname   [1]"
c "$P/index.html" 'PHASE V2.16.4 (stock-opname-report branch)' "the comment that precedes that link   [1]"
c "$P/index.html" 'sidebar-legacy-routes' "cleanup already applied?   [0]"
echo
echo "== Laporan submenu now (lines between data-group=\"laporan\" and data-group=\"inventory\") =="
awk '/data-group="laporan"/{f=1} /data-group="inventory"/{f=0} f' "$P/index.html" | grep -o 'data-tab="[a-z-]*"' | tr '\n' ' '; echo
