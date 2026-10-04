# UI2 — Dashboard refinement + collapsible sidebar + Dead Stock from Stock Opname (NOT deployed)

Files only: **no database change, no data change, no new route.** Every script **fails closed**: it refuses, changing nothing,
unless the file's current SHA256 and every anchor match exactly. Dry-run is the default. Independent of the Jejak, Dashboard and
Stock IN/OUT V2 packages (own state files `.pre-ui2-backup` / `.ui2-patch.json`; a Stock IN/OUT V2 CSS block after the dashboard block is fine).

| Target | Action |
|---|---|
| `public/assets/css/app.css` | (1) REPLACE the deployed dashboard CSS block (found exactly once, byte-exact, `payload/ui2_old_dashboard_block.css` SHA256 `@@H_OLDBLK@@`) with the refined block (`ui2_new_dashboard_block.css` `@@H_NEWBLK@@`); (2) APPEND the sidebar-rail block (`ui2_sidebar_block.css` `@@H_SIDEBLK@@`). Nothing else in the file is read or changed. |
| `public/assets/js/dashboard.js` | REPLACE (new SHA256 `@@H_DASHJS@@`) — compact Rupiah fallback ("Rp 2,23 M", exact value in the tooltip) and the Dead Stock drill-down |
| `public/assets/js/sidebar.js` | REPLACE (new SHA256 `@@H_SIDEJS@@`) — collapsible rail, tooltips, flyouts, keyboard-reachable menu items |
| `services/DashboardInventoryService.php` | REPLACE (new SHA256 `@@H_SVC@@`) — **Dead Stock now = the Deadstock quantity of the latest POSTED Stock Opname of each warehouse × its unit cost** (same source and rule as the Rusak card). Read-only queries; no other figure changes. |
| `public/index.html` | tags: `app.css`, `sidebar.js`, `dashboard.js` `?v=` -> `20261011-ui2` |

PHP 8+ CLI. Run from this directory. `PUB` = production `public/`, `SVC` = production `services/`, `APP` = app root (contains `services/`, `config/`, `public/`).

> **Behaviour change you asked for:** the Dead Stock card used to be "no movement for >= 90 days". It now shows the Deadstock quantity
> recorded in the latest posted Stock Opname per warehouse (hint "Hasil Stock Opname terakhir"; click = drill-down with SKU, warehouse, SO session, qty, HPP, value, note).
> A warehouse without a posted opname — or whose latest opname recorded no Deadstock — shows 0. Everything else on the dashboard (KPIs, movement, other attention cards, lists, warehouse filter) is unchanged.

## 0. Collect (READ-ONLY) — send me this output before step 3
```
bash collect_production_hashes_ui2.sh "$PUB" "$SVC"
mysql -u<user> -p <database> < precheck_readonly.sql
```
Needed: SHA256 of the five files; the anchor counts as shown in brackets; "no UI2 leftovers"; the column `final_deadstock_qty` present;
the per-warehouse Dead Stock figures the card will show (second SQL result).

## 1. Database check of the new Dead Stock query (READ-ONLY, BEFORE deploying anything)
Uses the package copy of the service in a READ ONLY transaction (it first proves the server rejects a write) and compares the card with independent SQL:
```
php scripts/ui2_readonly_check.php --app-root="$APP" --service-dir=payload
```
Send me the output. Any FAIL → do not deploy.

## 2. Safety copy + verify package
```
B=~/ui2_pre_$(date +%Y%m%d%H%M); mkdir -p $B
cp -p "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/dashboard.js" "$PUB/assets/js/sidebar.js" "$SVC/DashboardInventoryService.php" $B/
sha256sum -c SHA256SUMS
```

## 3. Dry-run everything (writes nothing)
```
H_CSS=<sha256 app.css>; H_IDX=<…index.html>; H_DASH=<…dashboard.js>; H_SIDE=<…sidebar.js>; H_SVC=<…DashboardInventoryService.php>
php scripts/patch_ui2_app_css_production.php "$PUB/assets/css/app.css" payload/ui2_old_dashboard_block.css payload/ui2_new_dashboard_block.css payload/ui2_sidebar_block.css \
    --expect-sha256=$H_CSS --expect-old-sha256=@@H_OLDBLK@@ --expect-new-sha256=@@H_NEWBLK@@ --expect-sidebar-sha256=@@H_SIDEBLK@@
php scripts/install_ui2_files_production.php payload/DashboardInventoryService.php "$SVC/DashboardInventoryService.php" --expect-payload-sha256=@@H_SVC@@ --replace-expect-sha256=$H_SVC
php scripts/install_ui2_files_production.php payload/dashboard.js "$PUB/assets/js/dashboard.js" --expect-payload-sha256=@@H_DASHJS@@ --replace-expect-sha256=$H_DASH
php scripts/install_ui2_files_production.php payload/sidebar.js "$PUB/assets/js/sidebar.js" --expect-payload-sha256=@@H_SIDEJS@@ --replace-expect-sha256=$H_SIDE
php scripts/patch_ui2_index_html_production.php "$PUB/index.html" --expect-sha256=$H_IDX
php scripts/rollback_ui2_production.php --public-dir="$PUB" --services-dir="$SVC"      # must FAIL now: nothing is patched yet
```

## 4. Apply — backend file first, verify, then frontend (same commands + `--apply`, in this order)
1. `install_ui2_files_production.php payload/DashboardInventoryService.php …`, then `php -l "$SVC/DashboardInventoryService.php"`
2. **Verify before touching the frontend:** `php scripts/ui2_readonly_check.php --app-root="$APP" --service-dir="$SVC"` must be all PASS. (Until step 5 the old dashboard screen still works: its Dead Stock card just shows the new SO-based figure and its link opens the new drawer type only after step 3.)
3. `install_ui2_files_production.php` for `dashboard.js`, then `sidebar.js` (REPLACE, each with its `--replace-expect-sha256`)
4. `patch_ui2_app_css_production.php …`
5. `patch_ui2_index_html_production.php …` (last — it is what makes browsers fetch the new files)
6. `php scripts/ui2_readonly_check.php --public-dir="$PUB" --services-dir="$SVC" --files` — must be all PASS.

## 5. Verify in the browser (hard refresh; desktop 1536 / 1366, iPad landscape + portrait, phone)
* Dashboard: smaller KPI / movement / title type, denser cards, filter row on one line; four movement cards in one row (iPad landscape), 2×2 (iPad portrait / ≤900px); four attention cards in one row (landscape), 2×2 (portrait); no figure clipped. **Nilai Stok**: full Rupiah when it fits, otherwise "Rp 2,23 M" with the exact value on hover/long-press (tooltip) and in the drill-down.
* Sidebar: ☰ in the header collapses to an icon rail (68px) and back; the choice survives reload; tooltips on hover/focus; a group icon opens its submenu as a flyout; Enter/Space activates a focused item; ≤900px it is the off-canvas drawer. Open Stock IN/OUT, Transfer, Master Data, Stock Opname, Laporan in both states — nothing may overflow.
* Dead Stock card: hint "Hasil Stock Opname terakhir", count/value equal the second SQL result of step 0; click opens the drill-down whose GRAND TOTAL equals the card.
* DevTools → Network: only GETs.

## 6. Rollback (two-phase, fail-closed; returns every file to its previous bytes)
```
php scripts/rollback_ui2_production.php --public-dir="$PUB" --services-dir="$SVC"           # dry run
php scripts/rollback_ui2_production.php --public-dir="$PUB" --services-dir="$SVC" --apply
sha256sum "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/dashboard.js" "$PUB/assets/js/sidebar.js" "$SVC/DashboardInventoryService.php"   # must equal H_IDX H_CSS H_DASH H_SIDE H_SVC
```
Refuses (changing nothing) if any of the five files was edited after this package or a state file/backup is missing. No data is involved. Order matters if other packages were applied later: roll those back first.
Manual fallback: copy `$B/*` back per file.
