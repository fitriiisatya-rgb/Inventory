# Inventory Pro — Reports v3 · PAKET PEMULIHAN PRODUKSI (backend + frontend, sadar-kondisi-produksi)

Paket: `@NAME@` · sumber commit `@COMMIT@` · token cache-bust `@TOKEN@`

## Kenapa paket sebelumnya "lolos" padahal backend tidak terpasang
1. **Validator "post-apply" lama memuat kode dari payload paket (in-memory), bukan dari `APP/services`.** Ia lulus (EXIT=0) pada server yang tidak pernah dipasangi satu pun file. Ini terbukti dengan tes: validator lama lulus pada tree yang belum di-apply.
2. **`apply` tanpa `--yes` berakhir dengan exit 0** ("plan verified, re-run with --yes") dan tidak menulis apa pun — tak dapat dibedakan dari apply sukses lewat exit code.
Perbaikan di paket ini: `apply` tanpa `--yes` kini **exit 10 "NOT APPLIED"**; apply selalu diakhiri **asersi terpasang** (file ada di `APP` dengan sha256 paket, marker `index.php`) dan **rollback otomatis** bila gagal;
validator dipisah menjadi **dua mode yang masing-masing harus lulus**: `predeploy_validate.sh` (kode kandidat dari payload, hanya membuktikan paket bekerja) dan `installed_verify.sh` (**hanya** kode terpasang; menolak `--package-dir`; gagal bila ada service yang tidak ada / berbeda / dimuat dari folder paket).

## Yang dilakukan paket ini (hanya kode — tidak ada data yang disentuh)
Dry-run membaca **produksi nyata** dan menampilkan *INSTALL PLAN*: `CREATE` (file V3 yang belum ada), `UPDATE` (hanya file yang isinya berbeda; sha256 terpasang disebut dan dicocokkan dengan commit proyek yang menghasilkannya),
`EDIT` (blok di dalam file yang ada: satu include route di `public/index.php`, blok CSS, label/route di `app.js`, tag script/token/sidebar di `index.html`), `UNCHANGED`, `BLOCKED`.
Versi file yang tidak dikenal = `BLOCKED` (tidak ditimpa buta, tidak ada yang ditulis). Tidak ada migrasi / INSERT / UPDATE / DELETE database; sesi Stock Opname 11 & 12, FIFO/HPP, dashboard, master data, Stock IN/OUT tidak disentuh.

Hasil akhir: backend Reports V3 benar-benar terpasang di `APP/services`, sidebar LAPORAN tepat 5 menu (Laporan Pergerakan Stok · Laporan IN / OUT · Laporan Pembelian · Laporan Nilai HPP · Laporan Stock Opname; link lama disembunyikan, rute tetap ada),
dan setiap laporan punya **Cetak** + **Download Excel**.

## Langkah 1 — PRE-FLIGHT, DRY-RUN, PRE-DEPLOY VALIDATOR (read-only terhadap aplikasi)
```bash
cd <folder paket hasil ekstrak>
bash scripts/preflight.sh  /home/u7566812/public_html/newinventory
bash scripts/dryrun.sh     /home/u7566812/public_html/newinventory      # menulis state/plan.json + state/dryrun_report.txt DI DALAM folder paket
bash scripts/predeploy_validate.sh /home/u7566812/public_html/newinventory --session=11,12
```
Kirim hasilnya. **Perintah apply tidak diberikan sebelum hasil ditinjau.**

## Setelah apply (nanti): verifikasi HANYA kode terpasang
```bash
bash scripts/installed_verify.sh /home/u7566812/public_html/newinventory --session=11,12 --base-url=https://newinventory.amorgroup.id
```
Memeriksa: tiap file backend ada di `APP/services` dengan sha256 paket · `index.php` memuat marker · setiap kelas V3 (Reflection) berasal dari `APP/services/` dan tidak pernah dari folder paket · `ReportsV3Routes.php` terpasang mendefinisikan semua route ·
semua laporan rekonsiliasi memakai kode terpasang saja (sesi 11 & 12 = 16/16) · `index.html` yang disajikan web server = 5 menu dan identik dengan file di disk.

## File yang dipasang
| File | SHA256 (awal) | Catatan |
|---|---|---|
@@FILES@@

## Isi folder
`payload/` file yang dipasang · `scripts/` engine, wrapper, validator · `tests/` suite pembuktian · `manifest.json` · `SHA256SUMS`. Rollback: `bash scripts/rollback.sh <APP ROOT>` (dua fase, byte-identik).
