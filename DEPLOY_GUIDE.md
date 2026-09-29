# Panduan Deploy — Shared Hosting (cPanel)

Target: PHP 8.1+, MySQL 8.0+ / MariaDB 10.11+, ekstensi `pdo_mysql`, `zip`, `gd`, `fileinfo`, `mbstring`.

## 1. Build paket rilis

Di mesin development:

```bash
php bin/build_release.php
```

Menghasilkan `release_YYYYMMDD_HHMMSS.zip` — sudah tidak berisi `.git/`, `tests/`,
file referensi legacy (`inventory.html`, `trace-stok-awal.js`), `config/config.php`,
atau `bin/clear_test_data.php` (tool destruktif, sengaja tidak diikutkan).

## 2. Buat database MySQL di cPanel

cPanel → **MySQL Databases**:
1. Buat database baru (mis. `cpaneluser_stokopname`).
2. Buat user baru dengan password kuat.
3. Tambahkan user ke database dengan **ALL PRIVILEGES**.
4. Catat: nama database, username, password, host (biasanya `localhost`).

## 3. Upload dan ekstrak

cPanel → **File Manager**, masuk ke `public_html/` (atau subdomain/folder tujuan):
1. Upload `release_*.zip`.
2. Klik kanan → **Extract**.
3. Hapus file zip setelah ekstrak selesai.

Alternatif: upload via FTP/SFTP lalu `unzip release_*.zip` via SSH jika tersedia.

## 4. Konfigurasi `config/config.php`

Di File Manager, salin `config/config.sample.php` menjadi `config/config.php`, lalu isi:

```php
'db' => [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'cpaneluser_stokopname',
    'user' => 'cpaneluser_dbuser',
    'pass' => '...password dari langkah 2...',
    'charset' => 'utf8mb4',
],
```

Set juga `app.timezone` (default `Asia/Jakarta`) sesuai kebutuhan.

**Jangan pernah commit atau upload `config/config.php` ke tempat publik** — file ini
berisi kredensial database.

## 5. Jalankan migrasi database

**Jika SSH/Terminal tersedia** (cPanel modern sering menyediakan "Terminal" di menu Advanced):

```bash
cd public_html
php bin/migrate.php
```

**Jika tidak ada SSH sama sekali**, gunakan salah satu:
- cPanel → **Cron Jobs**: buat satu cron job one-time (jadwalkan 1 menit ke depan,
  lalu hapus jobnya setelah jalan sekali) dengan command:
  `php /home/cpaneluser/public_html/bin/migrate.php >> /home/cpaneluser/migrate.log 2>&1`
- Atau minta provider hosting menjalankan `php bin/migrate.php` sekali via panel mereka.

Jangan pernah menjalankan migrasi lewat URL publik (file `bin/*.php` tidak dirancang
untuk diakses browser dan tidak melakukan pengecekan auth — pastikan folder `bin/`
tidak reachable dari web, atau blok lewat `.htaccess` bila document root mengarah
langsung ke root repo).

## 6. Buat akun SUPERADMIN pertama

Sama seperti langkah 5 (SSH/Terminal atau cron one-time):

```bash
php bin/create_admin.php admin "PasswordKuatAnda123" "Nama Lengkap Admin"
```

Tidak ada password default yang di-hardcode — ini satu-satunya cara membuat login pertama.

## 7. Permission folder

Pastikan folder berikut writable oleh proses PHP (biasanya `750` atau `755`
tergantung konfigurasi hosting):
- `uploads/opname/` (foto bukti kondisi barang)
- `backups/` (dump database)

Kedua folder sudah punya `.htaccess` yang memblokir akses HTTP langsung —
**jangan hapus file `.htaccess` ini**, itu satu-satunya pelindung foto/backup dari
akses publik langsung di banyak konfigurasi shared hosting.

## 8. Verifikasi via System Check

Login sebagai SUPERADMIN → menu **System Check** (`/admin/system-check.php`).
Semua item harus **PASS**, kecuali:
- **HTTPS**: akan WARNING sampai SSL dipasang (langkah 9) — ini blocking untuk go-live sungguhan.
- **mysqldump binary**: WARNING saja jika tidak tersedia (fallback backup PHP-native tetap valid).

Lanjutkan ke menu **Go-Live Checklist** (`/admin/go-live-checklist.php`) untuk
memastikan kesiapan data bisnis (user, lokasi, kategori, Master Barang, system stock).

## 9. Pasang SSL (HTTPS)

cPanel → **SSL/TLS Status** → **Run AutoSSL** (Let's Encrypt gratis, biasanya otomatis
tersedia di kebanyakan hosting modern). Setelah aktif, cookie session akan otomatis
memakai flag `secure` (lihat `includes/bootstrap.php`) tanpa perlu ubah kode.

## 10. Backup pertama + jadwalkan backup rutin

Jalankan backup manual pertama:

```bash
php bin/backup_database.php initial
```

Lalu cPanel → **Cron Jobs**, tambahkan job harian (mis. jam 2 pagi):

```
0 2 * * * php /home/cpaneluser/public_html/bin/backup_database.php nightly >> /home/cpaneluser/backup.log 2>&1
```

File backup masuk ke `backups/` (di-blok dari web via `.htaccess`) — unduh berkala
lewat File Manager/FTP ke tempat penyimpanan terpisah, jangan andalkan hosting
sebagai satu-satunya salinan.

---

## Setelah deploy: migrasi data dari aplikasi legacy (jika berlaku)

Jika data Master Barang dan stok sudah ada di aplikasi legacy (localStorage-based),
JANGAN input ulang manual. Ikuti alur di README bagian
"Migrasi Data Legacy", ringkasnya:

1. Buka `tools/legacy-export.html` (lihat instruksi di halaman itu — bookmarklet,
   console DevTools, atau load langsung jika origin sama) untuk mengekspor
   `localStorage` aplikasi legacy menjadi satu file JSON.
2. Login SUPERADMIN → menu **Tools: Import Legacy** (`/admin/legacy-import.php`).
3. Upload file JSON tersebut. Jika terdeteksi format aplikasi "Inventory FIFO Pro"
   (key `master_sku` + `stock_batches`), tombol **Auto-Deteksi** akan muncul dan
   langsung mengisi data Master + Stock dengan benar (termasuk agregasi FIFO batch
   per gudang, persis seperti perhitungan `getStock()` di aplikasi legacy).
4. Klik **Backup Sekarang** sebelum lanjut.
5. **Preview Master** → periksa ringkasan VALID/WARNING/INVALID → **Import Master**.
6. **Preview Stock** → petakan setiap lokasi legacy ke lokasi di aplikasi baru →
   **Import Stock**.
7. Verifikasi hasil: buka **Master Barang** dan bandingkan sampel SKU dengan
   aplikasi legacy (SKU, konversi satuan, stok, harga) — harus cocok.

## Troubleshooting cepat

| Gejala | Kemungkinan penyebab |
|---|---|
| Halaman putih / 500 error | Cek `error_log` di cPanel; biasanya `config/config.php` belum dibuat atau salah kredensial |
| Login gagal terus | Pastikan `bin/create_admin.php` sudah dijalankan; cek `users` table via phpMyAdmin |
| Foto evidence gagal upload | Cek permission `uploads/opname/` writable; cek `upload_max_kb` di config vs `upload_max_filesize` di php.ini hosting |
| Backup gagal total | Jarang — fallback PHP-native seharusnya selalu jalan selama PDO connect berhasil; cek permission folder `backups/` |
| Import stok/legacy lambat sekali | Batasi ukuran file JSON per batch (tool menolak >20000 baris sekaligus) — pecah jadi beberapa file |
