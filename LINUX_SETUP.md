# Instalasi dan Menjalankan FIK Smart Print Server di Linux/Unix

Dokumen ini khusus untuk deployment di Linux (Ubuntu/Debian/RHEL/Alma/Rocky) atau Unix lain (mis. macOS server).  
Fokus dokumen: setup web/API yang stabil, plus catatan realistis untuk fitur print.

## Ringkas Dukungan Linux Saat Ini

- Web app (`index.php`, `users.php`, API auth, queue DB, log UI) dapat berjalan di Linux.
- Fitur print fisik **belum native Linux** pada kode saat ini karena worker memakai:
  - PowerShell commandlet Windows printer (`Get-Printer`, `Get-PrintJob`)
  - SumatraPDF (aplikasi Windows)
- Jadi:
  - Mode web+queue berjalan.
  - Mode print fisik perlu adaptasi worker (CUPS/lp) atau print node Windows.

## Opsi Arsitektur di Linux

### Opsi A (paling cepat, tanpa ubah kode print): Linux untuk web/API saja

- Linux menjalankan UI + API + database.
- Print fisik tetap dieksekusi di mesin Windows (print node) yang menjalankan project ini juga.

### Opsi B (full Linux, perlu modifikasi kode worker)

- Adaptasi `queue_worker.php` dan `printer.php` untuk backend CUPS (`lp`, `lpstat`, `cancel`).
- Dokumen ini memberi baseline instalasi server; adaptasi kode print dikerjakan terpisah.

## 1) Prasyarat Paket (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install -y apache2 mariadb-server php php-mysqli php-json php-mbstring libapache2-mod-php curl unzip
```

Jika Anda pakai Nginx + PHP-FPM:

```bash
sudo apt install -y nginx php-fpm php-mysql php-json php-mbstring mariadb-server
```

## 2) Salin Project ke Server

Contoh:

```bash
sudo mkdir -p /var/www/printserver
sudo rsync -av /path/lokal/printserver/ /var/www/printserver/
```

## 3) Permission Folder Runtime

Folder yang wajib writable:

- `uploads/`
- `logs/`

Contoh untuk Apache user `www-data`:

```bash
sudo chown -R www-data:www-data /var/www/printserver
sudo find /var/www/printserver -type d -exec chmod 755 {} \;
sudo find /var/www/printserver -type f -exec chmod 644 {} \;
sudo chmod 775 /var/www/printserver/uploads /var/www/printserver/logs
```

## 4) Konfigurasi `.env`

Copy file:

```bash
cd /var/www/printserver
cp .env.example .env
```

Contoh isi:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=printserver
DB_USER=printuser
DB_PASS=strong_password
PRINTER_NAME=
SUMATRA_PDF_PATH=/opt/sumatra/SumatraPDF.exe
```

Catatan:
- `SUMATRA_PDF_PATH` pada Linux tidak berlaku langsung kecuali Anda memakai Wine + Sumatra (eksperimental).
- Untuk web-only queue, biarkan tetap ada tetapi fitur print fisik akan gagal tanpa adaptasi worker.

## 5) Setup Database MariaDB

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS printserver CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'printuser'@'localhost' IDENTIFIED BY 'strong_password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON printserver.* TO 'printuser'@'localhost'; FLUSH PRIVILEGES;"
```

Import schema dummy (opsional):

```bash
mysql -u printuser -p printserver < /var/www/printserver/database/dummy_users.sql
```

## 6) Virtual Host Apache (contoh)

Buat file `/etc/apache2/sites-available/printserver.conf`:

```apache
<VirtualHost *:80>
    ServerName printserver.local
    DocumentRoot /var/www/printserver

    <Directory /var/www/printserver>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/printserver_error.log
    CustomLog ${APACHE_LOG_DIR}/printserver_access.log combined
</VirtualHost>
```

Aktifkan:

```bash
sudo a2ensite printserver.conf
sudo a2enmod rewrite
sudo systemctl reload apache2
```

## 7) Jalankan dan Verifikasi

1. Buka: `http://<server-ip>/` atau domain vhost Anda.
2. Login dengan user dummy:
   - `23123456 / dummy12345`
   - `1987654321 / dummy12345`
   - `19770001 / admin12345`
3. Upload PDF dan cek queue/log UI.

## 8) Uji Worker dari CLI Linux

```bash
cd /var/www/printserver
php queue_worker.php 5
```

Jika belum diadaptasi ke Linux print backend, ini biasanya akan gagal pada fase print fisik (expected).

## 9) Monitoring dan Log

- Log aplikasi print: `logs/printer_YYYY-MM-DD.log`
- Log web server:
  - Apache: `/var/log/apache2/`
  - Nginx: `/var/log/nginx/`

## 10) Troubleshooting Linux

### A. Halaman tidak bisa dibuka

- Cek service:

```bash
sudo systemctl status apache2
sudo systemctl status mariadb
```

- Cek PHP module MySQL:

```bash
php -m | grep -i mysqli
```

### B. Login gagal terus

- Pastikan `.env` benar.
- Pastikan tabel `users` ada di DB target.
- Cek `DB_HOST`, `DB_USER`, `DB_PASS`.

### C. Upload gagal

- Cek permission `uploads/`.
- Cek batas upload PHP (`upload_max_filesize`, `post_max_size`).

### D. Print tidak jalan di Linux

Itu normal jika worker belum diport ke CUPS.  
Langkah lanjut:

1. Ganti eksekusi print di `queue_worker.php` dari Sumatra -> `lp`.
2. Ganti deteksi printer di `printer.php` dari PowerShell -> `lpstat`.
3. Ganti monitor spooler dari `Get-PrintJob` -> `lpstat -W not-completed`.

## 11) Catatan untuk macOS/BSD

Langkah web/API sama prinsipnya:

- Install PHP + web server + MariaDB/MySQL.
- Pastikan permission folder runtime benar.
- Print backend tetap perlu adaptasi native OS (CUPS/lpr/lpq).

## 12) Rekomendasi Produksi

- Gunakan HTTPS (reverse proxy + cert).
- Batasi akses `users.php` via role admin (sudah ada di aplikasi).
- Backup harian database.
- Rotasi file log `logs/`.
- Gunakan service account khusus untuk web server.

