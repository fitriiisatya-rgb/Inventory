# Inventory Pro — KOREKSI DASHBOARD "Ringkasan Pergerakan Stok" (paket inkremental)

Paket: `@NAME@` · sumber commit `@COMMIT@` · token cache-bust `@TOKEN@`

**Hanya tampilan/perhitungan baca-saja dashboard.** Tidak ada tulis database, tidak ada perubahan stok, FIFO/HPP, Stock Opname (sesi 11 & 12), transaksi historis, opening balance.
Backend Reports V3 (`services/*.php` + include route di `public/index.php`) berperan sebagai **GATE**: bila belum terpasang persis versi tervalidasi, dry-run `BLOCKED` dan tidak ada file yang ditulis.

## Akar masalah
Dashboard menghitung "Ringkasan Pergerakan Stok" dengan SQL sendiri (interpretasi kedua) dan menaruh transfer, adjustment, saldo awal baru, reversal, produksi, dan IN yang di-void dalam satu angka generik **"Pergerakan lain"**,
sementara Laporan Pergerakan Stok (Reports V3) memisahkan Transfer IN / Transfer OUT / Adjustment. Angka Stok Awal / IN / OUT / Stok Akhir sama, tetapi penyajiannya menyesatkan dan dua implementasi dapat menyimpang.

## Perbaikan
`DashboardInventoryService::movement()` kini mengambil **semua angka dari `MovementReportV3Service::overview()`** (sumber yang sama dengan laporan): `Stok Awal + Stock IN − Stock OUT + Transfer IN − Transfer OUT + Adjustment = Stok Akhir`.
Kartu: Stok Awal, Stock IN, Stock OUT, Adjustment, Stok Akhir (Transfer IN/OUT ikut sebagai kartu hanya bila aritmetikanya memerlukan: per gudang atau nilai dalam perjalanan; selain itu rincian pendukung — secara company-wide saling meniadakan).
Baris aritmetika di bawah kartu menampilkan nilai penuh. **Adjustment diberi nama per jenis ledger** (Adjustment +/− = koreksi / Stock Opname, Saldo Awal Baru, Reversal, Produksi, Stock IN yang di-void, jenis lain dengan kodenya); jumlahnya harus sama persis dengan Adjustment laporan — selisih ditampilkan, tidak pernah ditutup. Tidak ada "Pergerakan lain".
Hari Ini / Bulan Ini / Custom memakai batas tanggal dan aturan yang sama dengan laporan.

## Langkah 1 — PRE-FLIGHT, DRY-RUN, PRE-DEPLOY VALIDATOR, TABEL SEBELUM/SESUDAH (read-only)
```bash
cd <folder paket hasil ekstrak>
bash scripts/preflight.sh  /home/u7566812/public_html/newinventory
bash scripts/dryrun.sh     /home/u7566812/public_html/newinventory
bash scripts/predeploy_validate.sh /home/u7566812/public_html/newinventory --session=11,12
bash scripts/dashboard_before_after.sh /home/u7566812/public_html/newinventory
```
`dashboard_before_after.sh` mencetak, dari data NYATA produksi (SELECT saja, transaksi READ ONLY), untuk semua gudang dan tiap gudang × Hari Ini / Bulan Ini / 7 hari: angka dashboard SEKARANG (struktur lama, isi "Pergerakan lain" dirinci), angka dashboard SESUDAH (kode paket), dan angka Laporan Pergerakan Stok.

## Setelah apply (nanti)
```bash
bash scripts/installed_verify.sh /home/u7566812/public_html/newinventory --session=11,12 --base-url=https://newinventory.amorgroup.id
```
Memuat HANYA kode terpasang (menolak `--package-dir`), memastikan `DashboardInventoryService.php` dan `dashboard.js` terpasang dengan sha256 paket, setiap kelas dimuat dari `APP/services/`, dan dashboard == laporan untuk semua gudang × periode.

## File yang dipasang
| File | SHA256 (awal) | Catatan |
|---|---|---|
@@FILES@@
Plus blok CSS bermarker `RV3 DASHBOARD MOVEMENT 20261020` di `app.css` dan token cache-bust `dashboard.js` / `app.css` di `index.html`. Rollback: `bash scripts/rollback.sh <APP ROOT>`.
