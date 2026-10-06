# Inventory Pro — Reports v3 (satu paket produksi untuk 5 laporan)

Paket: `@NAME@` · sumber commit `@COMMIT@` · token cache-bust `@TOKEN@`

**Isi (semua sekaligus, tidak ada patch manual satu per satu):**

| Laporan | Halaman | Endpoint (semua GET, read-only) |
|---|---|---|
| Laporan Pergerakan Stok | `report-pergerakan.js` | `/reports/movement/v3/{overview,items,export}` |
| Laporan IN / OUT (3 tab) | `report-inout-v3.js` | `/reports/io/*` (+ `view=lines`, `tab=all`) |
| Laporan Pembelian | `report-pembelian-v3.js` | `/reports/purchase-v2/*` |
| Laporan Nilai HPP (FIFO/Average × Per Barang/Per Hari) | `report-nilai-hpp-v3.js` | `/reports/inventory-valuation*` |
| Laporan Stock Opname | `report-opname-audit.js` | `/reports/opname-audit/*` (backend SOA dipakai ulang; hanya export diperluas) |

Semua laporan punya **Cetak** (dokumen cetak putih, tabel ber-border, header berulang, tanpa sidebar/tombol) dan **Download Excel** (.xlsx bertipe: angka tetap angka, Rupiah angka dengan format, tanggal valid, header beku, autofilter, lebar kolom).
Sidebar **LAPORAN** menjadi 5 menu; link laporan lain tetap ada di DOM (disembunyikan) sehingga rute lama tetap bekerja.

## Yang TIDAK dilakukan paket ini
* Tidak menulis database (tidak ada migrasi, tidak ada UPDATE/INSERT/DELETE). Validator hanya `SELECT` dan membuktikan koneksinya menolak tulis.
* Tidak mengubah qty / hasil / adjustment / evidence sesi Stock Opname 11 & 12, tidak repost, tidak menyentuh FIFO / HPP / riwayat transaksi.
* Tidak menghapus backend / route / halaman laporan lama (hanya disembunyikan dari sidebar). Route baru **menimpa** route lama dengan key yang sama lewat satu include di `index.php`; menghapus blok include itu mengembalikan perilaku lama.

## Cara kerja (fail-closed)
Setiap perubahan adalah operasi atas satu file: *file baru*, *ganti file dengan versi yang dikenal*, *blok CSS bermarker*, *satu include di index.php*, *re-point baris render di app.js*, *script tag + token + sidebar di index.html*. Setiap operasi dievaluasi terhadap isi file sekarang: `done` / `todo` / `conflict`.
Keadaan yang tidak dikenal (`conflict`) = **berhenti, tidak menulis apa pun**. Dry-run menyimpan plan terikat SHA256; apply menolak jika file berubah sejak dry-run. Backup, tulis atomik + baca-ulang, rollback otomatis bila gagal, idempoten (dijalankan dua kali aman).

## Langkah 1 — PRE-FLIGHT dan DRY-RUN (read-only terhadap aplikasi)

```bash
cd <folder paket hasil ekstrak>
bash scripts/preflight.sh  /home/u7566812/public_html/newinventory
bash scripts/dryrun.sh     /home/u7566812/public_html/newinventory      # menulis state/plan.json + state/dryrun_report.txt DI DALAM folder paket
bash scripts/readonly_validate.sh /home/u7566812/public_html/newinventory --session=11,12
```

`readonly_validate.sh` menjalankan rekonsiliasi semua laporan dengan kode BARU terhadap data NYATA produksi (hanya SELECT): Stock Opname sesi 11 & 12 (harus 16/16 PASS, EXIT_CODE=0), Pergerakan v3, IN/OUT, Pembelian, Nilai HPP.

Kirim hasil ketiga perintah. **Perintah apply tidak diberikan sebelum hasil dry-run ditinjau.**

## File yang dipasang
| File | SHA256 (awal) | Catatan |
|---|---|---|
@@FILES@@
Plus 5 blok CSS bermarker di `app.css`, 1 blok include di `index.php`, 7 re-point `app.js`, script tag + token + sidebar di `index.html`.

## Isi folder
`payload/` file yang dipasang · `scripts/` engine, wrapper, validator read-only · `tests/` suite yang membuktikannya · `manifest.json` · `SHA256SUMS`.
