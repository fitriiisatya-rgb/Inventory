# Inventory Pro — PERIOD CUTOFF (adjustment SO 30 Sep → Stok Awal Oktober) + OPENING BALANCE KARANG TENGAH (paket inkremental)

Paket: `@NAME@` · sumber commit `@COMMIT@` · token cache-bust `@TOKEN@`

> **BELUM ADA PERINTAH APPLY.** Paket ini hanya boleh dijalankan sampai tahap baca-saja (pre-flight, dry-run, validator, preview). Hasilnya dikirim dulu; perintah apply diberikan setelah ditinjau.
> Tidak menyentuh: sesi Stock Opname 11 & 12, FIFO layer SCM / Cibadak, stok, transaksi / pembelian / transfer historis, `posted_at` / `created_at` / audit trail.

## 1. Akar masalah & model data

### A. Adjustment Stock Opname tampil sebagai pergerakan Oktober
Laporan periode memakai `inventory_transactions.transaction_date`. Adjustment SO ditulis dengan `transaction_date = tanggal sesi 23:59:59`; bila sesi 11 / 12 dihitung dan diposting pada bulan Oktober, tanggalnya Oktober — padahal **cutoff inventori = 30 Sep 2026**.
Maka angka koreksi masuk ke *Adjustment Oktober* dan tidak ada di *Stok Awal Oktober*.

**Model:** tabel baru **aditif** `inventory_effective_dates` (satu baris per transaksi adjustment SO): `effective_at` (tanggal laporan), `original_transaction_date` / `original_posting_date` (bukti tidak ada yang diubah), `source_type/source_id` (sesi), `reason`, `created_by`.
`transaction_date`, `posting_date`, `created_at`, sesi, adjustment, audit **tidak pernah diubah**. Semua laporan periode (Pergerakan, Dashboard, Nilai Stok & HPP, Valuasi) membaca `COALESCE(effective_at, transaction_date)` lewat `InventoryEffectiveDateService`; tabel kosong / belum ada ⇒ perilaku persis seperti sekarang.
Aturan: Stok Akhir September = Stok Awal September + pergerakan efektif September + adjustment SO efektif 30 Sep; Stok Awal Oktober = Stok Akhir September setelah adjustment; adjustment yang sama **tidak** muncul lagi sebagai Adjustment Oktober.
Penulisan baris dilakukan **hanya** oleh `scripts/period_cutoff.php` (dry-run default, diikat ke SHA256 preview, SUPERADMIN, satu transaksi, rollback = hapus baris itu).

### B. Opening balance Karang Tengah
Memakai tipe ledger **`OPENING`** yang sudah ada (bukan Pembelian / Stock IN / Transfer / Adjustment). `transaction_date = 2026-10-01 00:00:00` (tanggal efektif) — aturan batas laporan yang sudah ada menghitung `OPENING` tepat di awal periode sebagai **Stok Awal**, bukan Stock IN. `posting_date` / `created_at` = waktu posting sebenarnya.
Satu `FifoService::postIn()` per item berqty > 0 (FIFO layer pembuka), referensi **`KARANG_TENGAH_SO_20261001`**. Gudang `KARANG_TENGAH` yang nonaktif + terkunci diposting lewat jalur `bypassInactiveWarehouseGuard` milik cutover V2.14 — **gudang tidak diaktifkan** oleh proses ini.
Pemetaan: `kode barang` = `items.sku`; UoM dibandingkan tanpa beda huruf besar/kecil terhadap `units.code` / `units.name`; selain unit dasar hanya lewat `item_unit_conversions` (tidak ada tebakan / hard-code); HPP dikonversi terbalik sehingga `nilai sumber = qty ternormalisasi × HPP ternormalisasi` (hanya toleransi pembulatan 6 dp qty / 4 dp HPP: ≤ Rp 0,50 per baris, ≤ Rp 5,00 total).

## 2. Langkah 1 — PRE-FLIGHT, DRY-RUN, VALIDATOR, PREVIEW (semua baca-saja)
```bash
cd <folder paket hasil ekstrak>
bash scripts/preflight.sh  /home/u7566812/public_html/newinventory
bash scripts/dryrun.sh     /home/u7566812/public_html/newinventory
bash scripts/predeploy_validate.sh /home/u7566812/public_html/newinventory --session=11,12
bash scripts/karang_preview.sh /home/u7566812/public_html/newinventory
bash scripts/period_cutoff_preview.sh /home/u7566812/public_html/newinventory --sessions=11,12 --cutoff=2026-09-30
```
* `karang_preview.sh` memetakan **semua 380 baris** ke Master Barang (SELECT saja, transaksi READ ONLY), mencetak ringkasan + blocker + **PREVIEW SHA256**, dan menulis `out/karang_mapping_all_rows.csv` (kolom lengkap: kode, nama, UoM, qty, HPP, nilai sumber, item_id, SKU/nama master, unit dasar, konversi, qty & HPP & nilai ternormalisasi, status, issue), `out/karang_blockers.csv`, `out/karang_summary.json` — di dalam folder paket, **di luar aplikasi**.
* Status per baris: `MATCH_EXACT`, `MATCH_WITH_UNIT_CONVERSION`, `ZERO_QTY`, `NOT_FOUND`, `AMBIGUOUS`, `UNIT_UNRESOLVED`, `HPP_MISSING` (+ `QTY_INVALID`, `ITEM_INACTIVE`). `NOT_FOUND / AMBIGUOUS / UNIT_UNRESOLVED / HPP_MISSING / QTY_INVALID` dengan qty > 0 = **BLOCKER**; baris qty 0 tidak membuat layer dan tidak memblokir (999609 SP CAIR MILKBATH: HPP 0 + qty 0 = informasi).
* `period_cutoff_preview.sh` menampilkan transaksi adjustment tiap sesi (tanggal asli, `posting_date`, nilai), `effective_at` yang diusulkan, dan angka **SEBELUM → SESUDAH** bulan berikutnya per gudang dan "Semua Gudang" (Stok Awal, Adjustment, Stok Akhir).
* `predeploy_validate.sh` memuat kode kandidat dari payload di memori dan merekonsiliasi enam laporan + dashboard + kontinuitas bulan (`Stok Akhir bulan m + opening balance terkontrol = Stok Awal bulan m+1`).

## 3. Setelah ditinjau (belum — perintah akan diberikan menyusul)
Urutan yang direncanakan, masing-masing dengan gerbang sendiri: cadangan database → pasang file paket (`apply.sh`, tanpa data) → tulis `inventory_effective_dates` untuk sesi 11 & 12 → posting opening Karang Tengah → verifikasi (`installed_verify.sh`, `period_cutoff_verify.sh`, `karang_verify.sh`).
Kode keluar skrip penulisan: 10 = NOT APPLIED (tanpa `--yes`), 11 = diblokir, 12 = `OPENING_BALANCE_ALREADY_POSTED`, 13 = preview berubah, 14 = actor bukan SUPERADMIN aktif, 16 = verifikasi dalam-transaksi gagal (semua ter-rollback).

## 4. Rollback
* File: `bash scripts/rollback.sh <APP ROOT>` (dua fase, byte-identik dengan sebelum apply).
* Period cutoff: hapus **hanya** baris override sesi itu (`period_cutoff.php rollback`, `--yes --confirm=PERIOD_CUTOFF`) — ledger tidak pernah diubah, laporan kembali ke tanggal asli.
* Opening Karang Tengah: **cadangan database sebelum posting adalah rollback utama**. `kt_opening.php rollback` hanya cadangan terakhir: menolak bila ada layer yang terpakai / ada transaksi lain di gudang / periode terkunci.

## 5. File yang dipasang
| File | SHA256 (awal) | Catatan |
|---|---|---|
@@FILES@@
Plus blok CSS `RV3 DASHBOARD MOVEMENT 20261020` dan token cache-bust `dashboard.js` / `app.css` (operasi dashboard idempoten — bila paket koreksi dashboard sudah terpasang, statusnya `UNCHANGED`). Migrasi SQL setara (dokumentasi / alternatif DBA) ada di `database/`.
