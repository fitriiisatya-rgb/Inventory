# Inventory Pro — Reports v3 · KOREKSI UI PRODUKSI (paket inkremental, frontend saja)

Paket: `@NAME@` · sumber commit `@COMMIT@` · token cache-bust `@TOKEN@`

Paket ini **hanya mengubah tampilan** (JS/CSS/index.html/label breadcrumb). **Backend yang sudah tervalidasi tidak disentuh**:
`services/*.php` dan satu baris include route di `public/index.php` berperan sebagai **GATE** — bila belum terpasang persis seperti versi tervalidasi, dry-run menjadi `BLOCKED` dan **tidak ada file yang ditulis**
(paket ini tidak akan menimpa backend). Database tidak pernah ditulis. Sesi Stock Opname 11 & 12, FIFO/HPP dan riwayat transaksi tidak disentuh.

## Yang diperbaiki
1. **Halaman laporan lama tidak lagi menumpuk di atas halaman berikutnya** (`.tab-content:not(.active){display:none!important}`): kelima halaman memberi `display:flex` pada kontainer tab sehingga laporan yang pernah dibuka tetap tampil DI ATAS laporan yang dibuka sesudahnya.
2. **Sidebar LAPORAN = tepat 5 menu**: Laporan Pergerakan Stok · Laporan IN / OUT · Laporan Pembelian · Laporan Nilai HPP · Laporan Stock Opname. Link laporan lama tetap ada di DOM (disembunyikan, rute tetap bekerja).
   Tiga lapis: (a) index.html disapu di mana pun link lama berada (berdasarkan rute **dan** label, juga link ganda), (b) CSS pengaman, (c) guard runtime di `report-tools.js` untuk index.html yang ter-cache / lama.
3. Satu *shell* laporan untuk kelima halaman (header ringkas, filter ≤ 2 baris, KPI satu baris dengan ikon bernada seperti mockup, grafik, tabel dengan scroll horizontal di dalam kontainer sendiri).
4. Pergerakan: 7 KPI, grafik Grafik | Tabel, kartu "Detail Pergerakan Stok Harian" (cari tanggal + Download Detail). Pembelian: 5 KPI. Nilai HPP: judul tepat "Laporan Nilai HPP", detail FIFO rata (tanpa kartu-dalam-kartu). Stock Opname: 7 KPI satu baris, kolom Ringkasan Sesi / Rincian Item sesuai spesifikasi.

## Langkah 1 — PRE-FLIGHT, DRY-RUN, READ-ONLY VALIDATE (tidak menulis apa pun ke aplikasi)

```bash
cd <folder paket hasil ekstrak>
bash scripts/preflight.sh  /home/u7566812/public_html/newinventory
bash scripts/dryrun.sh     /home/u7566812/public_html/newinventory      # menulis state/plan.json + state/dryrun_report.txt DI DALAM folder paket
bash scripts/readonly_validate.sh /home/u7566812/public_html/newinventory --session=11,12
```

Kirim hasilnya. **Perintah apply tidak diberikan sebelum hasil dry-run ditinjau.**

## Setelah apply: `verify` juga memeriksa apa yang DITERIMA BROWSER
`bash scripts/verify.sh <APP ROOT> --base-url=https://newinventory.amorgroup.id` memeriksa (a) menu Laporan di `public/index.html` = 5 laporan, (b) index.html yang **disajikan web server** = 5 laporan dan **byte-identik** dengan file di disk
(bila tidak identik: cache / CDN / document root lain yang menjawab), (c) setiap JS/CSS yang disajikan = file paket. Setelah apply, buka aplikasi dengan **hard refresh (Ctrl+Shift+R / tutup-buka tab)**.

## File yang dipasang
| File | SHA256 (awal) | Catatan |
|---|---|---|
@@FILES@@
Plus blok CSS bermarker `RV3 UI CORRECTION 20261019` di `app.css` (blok CSS lain: sudah terpasang → `done`), re-point/label `app.js`, script tag + token + sidebar di `index.html`.

## Isi folder
`payload/` file yang dipasang · `scripts/` engine, wrapper, validator read-only · `tests/` suite yang membuktikannya · `manifest.json` · `SHA256SUMS`.
Rollback: `bash scripts/rollback.sh <APP ROOT>` (dua fase, mengembalikan byte-identik).
