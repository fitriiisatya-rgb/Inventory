# Sidebar "Laporan" cleanup — five reports only (NOT deployed)

**Navigation markup only.** One file changes: `public/index.html`. No database, no PHP, no JS, no CSS, no route, permission or API change; nothing is deleted
(every other report page and its endpoints keep working). Fail-closed: the script refuses, changing nothing, unless the file's current SHA256 and both anchors match
exactly. Dry-run is the default. Independent of every other package (own state files `index.html.pre-sbc-backup` / `index.html.sbc-patch.json`).
No `?v=` cache token is touched — no script/stylesheet changed, so there is nothing to bust (index.html itself is fetched without a token; do a hard refresh once).

Result — Laporan menu, exactly this order: **Laporan Pergerakan Stok · Laporan IN / OUT · Laporan Pembelian · Laporan Nilai HPP · Laporan Stock Opname**.
Hidden from the menu (still reachable by internal navigation / saved tab, still permission-checked by the API): Ringkasan Inventory, Laporan Stok, Adjustment / Selisih,
Expired / Near Expired, Pembelian per Supplier, Distribusi per Bakery, Slow / No Movement, Rekonsiliasi Arus Stok, Audit Transaksi, Laporan Transfer, Laporan P1/P2 Stock Opname.
"Laporan Stock Opname" moves from the Stock Opname group into Laporan (same tab `opname-laporan`; one link per tab keeps the highlight unambiguous).

| Edit in `public/index.html` | Payload (SHA256) |
|---|---|
| Laporan submenu block → 5 links + hidden container for the other links | old `sbc_laporan_old.txt` `@@H_LOLD@@` → new `sbc_laporan_new.txt` `@@H_LNEW@@` |
| "Laporan Stock Opname" link in the Stock Opname group → a comment | old `sbc_opname_old.txt` `@@H_OOLD@@` → new `sbc_opname_new.txt` `@@H_ONEW@@` |

PHP 8+ CLI. Run from this directory. `PUB` = production `public/`.

## 0. Collect (READ-ONLY) — send me this output before step 3
```
bash collect_production_hashes_sbc.sh "$PUB"
```
Needed: the SHA256 of `index.html` (the `--expect-sha256` value), every anchor count as shown in brackets, "no sidebar-cleanup leftovers".
If any anchor count differs, do not continue — send me the output.

## 1. Safety copy + verify package
```
B=~/sbc_pre_$(date +%Y%m%d%H%M); mkdir -p $B; cp -p "$PUB/index.html" $B/
sha256sum -c SHA256SUMS
```

## 2. Dry-run (writes nothing)
```
H_HTML=<sha256 of index.html from step 0>
php scripts/patch_sbc_index_html_production.php "$PUB/index.html" payload/sbc_laporan_old.txt payload/sbc_laporan_new.txt payload/sbc_opname_old.txt payload/sbc_opname_new.txt \
    --expect-sha256=$H_HTML --expect-laporan-old-sha256=@@H_LOLD@@ --expect-laporan-new-sha256=@@H_LNEW@@ --expect-opname-old-sha256=@@H_OOLD@@ --expect-opname-new-sha256=@@H_ONEW@@
php scripts/rollback_sbc_production.php --public-dir="$PUB"      # must FAIL now: nothing is patched yet
```

## 3. Apply (same command + `--apply`)
Then verify: `sha256sum "$PUB/index.html"` equals the "New SHA256" printed by the script; `grep -c 'sidebar-legacy-routes' "$PUB/index.html"` = 1; `grep -c 'data-tab="opname-laporan"' "$PUB/index.html"` = 1.
Running `--apply` a second time is refused (backup/state file exist; the anchors no longer match).

## 4. Verify in the browser (hard refresh; desktop, iPad, phone)
Log in as an admin: open LAPORAN — exactly the five entries above, in that order; each opens its page and is highlighted with LAPORAN expanded. Collapse the sidebar (☰): the
Laporan flyout shows the same five. On a phone the off-canvas menu shows the same five and closes after a choice. Stock Opname group: "Proses Stock Opname" + "Stock Opname Saya" only.
Log in as a role with fewer permissions: it sees only those of the five it was allowed before.

## 5. Rollback (two-phase, fail-closed; returns the file to its previous bytes)
```
php scripts/rollback_sbc_production.php --public-dir="$PUB"            # dry run
php scripts/rollback_sbc_production.php --public-dir="$PUB" --apply
sha256sum "$PUB/index.html"                                            # must equal the hash from step 0
```
Refuses (changing nothing) if `index.html` was edited after this package (for example a LATER package that moved cache tokens — roll that one back first) or the state file / backup is missing.
Manual fallback: `cp $B/index.html "$PUB/index.html"`.
