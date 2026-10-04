# Master Data — "Tambah …" buttons + compact modals (NOT deployed)

Every Master Data page (Barang, Gudang, Divisi, Supplier, Bakery Tujuan, Kategori) gets a page header with a compact blue **+ Tambah …** button
and a compact modal for Create (Edit of Supplier / Bakery / Kategori uses the same modal; the inline forms are gone). **No database schema change,
no existing data touched.** Backend: three new create endpoints (`POST /items`, `/warehouses`, `/divisions` — same `MASTER_*_MANAGE` permissions the
edit/delete routes already require), `is_active` honoured on Supplier / Bakery / Kategori create, a duplicate-NAME guard on Kategori create, and
length / e-mail guards on Supplier / Bakery create. Every script **fails closed**: it refuses, changing nothing, unless the file's current SHA256 and every
anchor match exactly. Dry-run is the default. Independent of the Jejak, Dashboard, Stock IN/OUT V2 and UI2 packages (own state files `.pre-mdm-backup` / `.mdm-patch.json`).

| Target | Action |
|---|---|
| `services/MasterRecordService.php` | NEW (SHA256 `@@H_MRS@@`) — item (+ identity / purchase conversion + optional Harga Beli via the existing `item_price_history`), warehouse, division; one transaction, audited |
| `services/SupplierService.php` | REPLACE (new SHA256 `@@H_SUP@@`) — create accepts `is_active`, code/name length + e-mail guards |
| `services/BakeryDestinationService.php` | REPLACE (new SHA256 `@@H_BAK@@`) — create accepts `is_active`, code/name length guards |
| `public/index.php` | patch: +1 `require_once`, +3 routes inserted before `PUT /warehouses/{id}` (payload `mdm_index_php_routes.txt` `@@H_ROUTES@@`), the `POST /categories` duplicate-check block replaced (`mdm_category_old.txt` `@@H_CATOLD@@` → `mdm_category_new.txt` `@@H_CATNEW@@`) |
| `public/assets/js/api-client.js` | patch: +3 one-line methods after `createCategory` |
| `public/assets/js/master-common.js` | REPLACE (new SHA256 `@@H_COMMON@@`) — `pageHeader` + `recordModal` added; existing helpers unchanged |
| `public/assets/js/master-categories.js` / `master-vendors.js` / `master-bakery-destinations.js` | REPLACE (new SHA256 `@@H_CATJS@@` / `@@H_VENJS@@` / `@@H_BAKJS@@`) |
| `public/assets/js/master-items.js` / `master-warehouses.js` / `master-divisions.js` | REPLACE (new SHA256 `@@H_ITEMJS@@` / `@@H_WHJS@@` / `@@H_DIVJS@@`) |
| `public/assets/css/app.css` | APPEND the self-contained `.mdm-*` block (block SHA256 `@@H_BLK@@`) |
| `public/index.html` | tags: `app.css` + the 8 script tags above → `?v=20261012-mdm` |

PHP 8+ CLI. Run from this directory. `PUB` = production `public/`, `SVC` = production `services/`, `APP` = app root (contains `services/`, `config/`, `public/`).

## 0. Collect (READ-ONLY) — send me this output before step 3
```
bash collect_production_hashes_mdm.sh "$PUB" "$SVC"
mysql -u<user> -p <database> < precheck_readonly.sql
```
Needed: SHA256 of the 13 existing files; the anchor counts as shown in brackets; "no Master Data leftovers"; the six permissions listed and held only by
ADMIN / SUPERADMIN; no `MISSING_column` rows. The reference-hash list tells which of your files equal the repo's previous version (a file that differs is still
fine — the patchers only need its *current* hash — but anything REPLACED loses local edits, so tell me if a file differs).

## 1. Database check of the new validators (READ-ONLY, BEFORE deploying anything)
Uses the package copies of the three services inside a READ ONLY transaction (it first proves the server rejects a write); drives them with inputs that are refused
before any write (empty input, an existing real SKU / warehouse code / division code, over-long values) — nothing is created:
```
php scripts/mdm_readonly_check.php --app-root="$APP" --service-dir=payload
```
Send me the output. Any FAIL → do not deploy.

## 2. Safety copy + verify package
```
B=~/mdm_pre_$(date +%Y%m%d%H%M); mkdir -p $B
cp -p "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/api-client.js" "$PUB"/assets/js/master-*.js "$SVC/SupplierService.php" "$SVC/BakeryDestinationService.php" $B/
sha256sum -c SHA256SUMS
```

## 3. Dry-run everything (writes nothing)
```
H_PHP=<sha256 index.php>; H_HTML=<…index.html>; H_CSS=<…app.css>; H_API=<…api-client.js>
H_COMMON=<…master-common.js>; H_CAT=<…master-categories.js>; H_VEN=<…master-vendors.js>; H_BKJS=<…master-bakery-destinations.js>
H_ITEM=<…master-items.js>; H_WH=<…master-warehouses.js>; H_DIV=<…master-divisions.js>; H_SUP=<…SupplierService.php>; H_BKS=<…BakeryDestinationService.php>
php scripts/install_mdm_files_production.php payload/MasterRecordService.php "$SVC/MasterRecordService.php" --expect-payload-sha256=@@H_MRS@@
php scripts/install_mdm_files_production.php payload/SupplierService.php "$SVC/SupplierService.php" --expect-payload-sha256=@@H_SUP@@ --replace-expect-sha256=$H_SUP
php scripts/install_mdm_files_production.php payload/BakeryDestinationService.php "$SVC/BakeryDestinationService.php" --expect-payload-sha256=@@H_BAK@@ --replace-expect-sha256=$H_BKS
php scripts/patch_mdm_index_php_production.php "$PUB/index.php" payload/mdm_index_php_routes.txt payload/mdm_category_old.txt payload/mdm_category_new.txt \
    --expect-sha256=$H_PHP --expect-routes-sha256=@@H_ROUTES@@ --expect-catold-sha256=@@H_CATOLD@@ --expect-catnew-sha256=@@H_CATNEW@@
php scripts/patch_mdm_api_client_production.php "$PUB/assets/js/api-client.js" --expect-sha256=$H_API
for pair in "master-common:$H_COMMON:@@H_COMMON@@" "master-categories:$H_CAT:@@H_CATJS@@" "master-vendors:$H_VEN:@@H_VENJS@@" "master-bakery-destinations:$H_BKJS:@@H_BAKJS@@" "master-items:$H_ITEM:@@H_ITEMJS@@" "master-warehouses:$H_WH:@@H_WHJS@@" "master-divisions:$H_DIV:@@H_DIVJS@@"; do
  IFS=: read f cur new <<<"$pair"
  php scripts/install_mdm_files_production.php payload/$f.js "$PUB/assets/js/$f.js" --expect-payload-sha256=$new --replace-expect-sha256=$cur
done
php scripts/patch_mdm_app_css_production.php "$PUB/assets/css/app.css" payload/mdm_app_css_block.css --expect-sha256=$H_CSS --expect-block-sha256=@@H_BLK@@
php scripts/patch_mdm_index_html_production.php "$PUB/index.html" --expect-sha256=$H_HTML
php scripts/rollback_mdm_production.php --public-dir="$PUB" --services-dir="$SVC"      # must FAIL now: nothing is patched yet
```
(`H_BKJS` = master-bakery-destinations.js, `H_BKS` = BakeryDestinationService.php.)

## 4. Apply — BACKEND FIRST, verify, then FRONTEND (same commands + `--apply`, in this order)
1. the three `install_mdm_files_production.php payload/*Service.php …`
2. `patch_mdm_index_php_production.php …`, then `php -l "$PUB/index.php"`
3. **Verify the backend before touching the frontend** (the old screens keep working meanwhile — they do not call the new routes): `php scripts/mdm_readonly_check.php --app-root="$APP" --service-dir="$SVC"` must be all PASS.
4. `patch_mdm_api_client_production.php …`, then the seven `install_mdm_files_production.php payload/master-*.js …`
5. `patch_mdm_app_css_production.php …`
6. `patch_mdm_index_html_production.php …` (last — it is what makes browsers fetch the new files)
7. `php scripts/mdm_readonly_check.php --public-dir="$PUB" --services-dir="$SVC" --files` — must be all PASS.

## 5. Verify in the browser (hard refresh; desktop + iPad + phone)
Log in as an admin and open each page under Master Data: Master Barang, Master Gudang, Master Divisi, Vendor / Supplier, Bakery Tujuan, Kategori.
* Header = title + one-line description, blue **+ Tambah …** top-right, then the existing search / filters / table; no create form above the table.
* The button opens a centered compact modal (Batal / X / Esc close it). Empty Simpan → field errors, modal stays. A duplicate code shows "Kode … sudah digunakan." under the field and keeps what you typed.
* Save → modal closes, green toast, the table refreshes (no page reload), the new row is findable by search.
* **Use disposable records first** (e.g. code `ZZ-TEST`), then deactivate/delete them from the row menu if you do not want them.
* Master Barang: choose Kategori, optional Supplier (chosen from the list — never auto-created), Satuan Dasar, optional Satuan Beli + Konversi (1 beli = N dasar), optional Harga Beli (lands in the same price history Stock IN uses; "250.000" = 250 ribu). The new item appears in Stock IN/OUT item search.
* Bakery Tujuan: create `SUDIRMAN` / Bakery Sudirman / address / Sukabumi / Aktif → Stock OUT V2 lists it under Bakery Tujuan immediately (users already logged in need one page refresh).
* Log in as a STOCK or VIEWER user: the **+ Tambah** buttons are absent, and a direct `POST /api/items` etc. answers 403.

## 6. Rollback (two-phase, fail-closed; returns every file to its previous bytes)
```
php scripts/rollback_mdm_production.php --public-dir="$PUB" --services-dir="$SVC"           # dry run
php scripts/rollback_mdm_production.php --public-dir="$PUB" --services-dir="$SVC" --apply
sha256sum "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/api-client.js" "$PUB"/assets/js/master-*.js "$SVC/SupplierService.php" "$SVC/BakeryDestinationService.php"   # must equal the hashes from step 0
```
Refuses (changing nothing) if any of the fourteen files was edited after this package or any state file / backup is missing. The new service file is deleted; the thirteen changed files are
restored byte-exactly. **Data is never rolled back**: items / warehouses / divisions / suppliers / bakeries / categories created through the new screens stay (they are ordinary rows; after a rollback
the old screens still list them, and the old inline forms can edit them). Order matters if other packages were applied later: roll those back first. Manual fallback: copy `$B/*` back per file and delete `MasterRecordService.php`.

## Behaviour notes
* **Permissions** — create/edit/delete stay on `MASTER_ITEM_MANAGE`, `MASTER_WAREHOUSE_MANAGE`, `MASTER_DIVISION_MANAGE`, `MASTER_SUPPLIER_MANAGE`, `MASTER_BAKERY_DESTINATION_MANAGE`, `MASTER_CATEGORY_MANAGE` (SUPERADMIN + ADMIN today). The buttons are hidden without the permission AND the backend refuses a direct POST (403).
* **Master Barang create** — one transaction: item row, identity conversion (1 base = 1 base), optional purchase conversion (marked purchase-default), optional Harga Beli appended to `item_price_history` (per purchase unit if chosen, else per base unit; 0 allowed; blank = no price row, "Harga Belum Diisi" badge as before). Category must exist and be active; supplier optional, must exist and be active (never created); `minimum_stock` starts at 0 (stock policy stays on its own screen). SKU unique (case-insensitive), no spaces.
* **Gudang** — Kode / Nama / Tipe (MAIN or TRANSIT, the schema's two values) / Status. `activation_locked` is never set from here. No item data is copied: Master Barang stays centralised.
* **Divisi** — only the columns the table has: Kode, Nama, Status.
* **Kategori** — the table has no description column, so there is no Deskripsi field. A second category with the same name (any case) is refused; existing duplicates are left alone.
* **Edit** — Supplier / Bakery / Kategori Edit now opens the same modal (Kategori code stays read-only: the API never changed it). Gudang / Divisi / Barang Edit keep their existing dialogs. Row menus (Lihat Jejak, Aktifkan/Nonaktifkan, Hapus Permanen with its reference-safe checks) are untouched.
