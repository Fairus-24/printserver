# FIK Smart Print Server

Sistem antrian print PDF berbasis web (PHP + MySQL) untuk multi-user, dengan login SQL (NIM/NIPY + password), queue global realtime, file list per-user, dan eksekusi print melalui SumatraPDF di Windows.

Dokumen ini menjelaskan alur kerja nyata aplikasi sesuai kode saat ini: instalasi dari nol, konfigurasi, cara pakai, arsitektur queue worker, endpoint API, dan troubleshooting.

## Ringkasan Fitur

- Login menggunakan tabel SQL `users` (`nim_nipy` + `password_hash`)
- Multi-user dengan isolasi file per user (user hanya bisa lihat/aksi file miliknya)
- Queue global untuk proses print (`ready` + `printing`)
- Header realtime:
  - `Queue`: jumlah antrian global
  - `Status`: `Ready` / `Queueing...` / `Printing...`
- Upload PDF (max 100 MB), rename sebelum upload, opsi samarkan nama file
- Preview PDF di upload panel dan di card antrian
- Opsi mode print saat klik print: `Color` atau `Grayscale`
- Worker background (`queue_worker.php`) dengan lock file, proses serial, dan log print global
- Countdown auto-delete file 60 detik setelah status `done`
- Log aktivitas yang ditampilkan di UI hanya log kategori print (`[print]`)
- Manajemen user oleh admin (`users.php`) dengan konfirmasi edit/hapus

## Stack dan Dependensi

- PHP 7.4+ (disarankan PHP 8.x)
- MySQL / MariaDB
- Apache (XAMPP direkomendasikan)
- Windows + PowerShell (dipakai untuk deteksi printer dan monitoring spooler)
- SumatraPDF CLI
- Browser modern (Chrome/Edge/Firefox)

## Struktur Folder

```text
printserver/
|- api.php
|- index.php
|- users.php
|- db.php
|- printer.php
|- queue_worker.php
|- env.php
|- .env.example
|- database/
|  |- dummy_users.sql
|- uploads/
|- logs/
|- test/
|  |- test-system.php
|  |- test.php
|  |- generate-test-pdf.php
|- clear_session.php
|- queue.php                 (legacy, bukan alur utama)
`- README.md
```

## Arsitektur Singkat

1. User login via `api.php?action=login`.
2. User upload PDF ke `api.php?action=upload_file` (status awal `uploaded`).
3. User klik `Print` atau `Print Lagi`:
   - API mengubah status job ke `ready`
   - API menulis log `Print sent` lalu `Queue enqueue`
   - API memicu worker background (`triggerQueueWorker()`)
4. Worker (`queue_worker.php`) mengambil 1 job `ready` paling depan, ubah ke `printing`, jalankan SumatraPDF.
5. Worker menunggu antrian spooler printer benar-benar selesai (Get-PrintJob) sebelum tandai `done`.
6. Setelah `done`, worker lanjut otomatis ke job `ready` berikutnya (serial queue).
7. Job `done` dihitung mundur 60 detik lalu file dihapus otomatis dan status jadi `deleted`.

## Instalasi dari Nol (Step by Step)

### 1. Letakkan project

Contoh path di Windows/XAMPP:

```powershell
C:\xampp\htdocs\printserver
```

### 2. Jalankan service

- Start `Apache`
- Start `MySQL`

### 3. Buat file konfigurasi `.env`

Copy dari template:

```powershell
Copy-Item .env.example .env
```

Isi minimal (sesuaikan jika perlu):

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=printserver
DB_USER=root
DB_PASS=
PRINTER_NAME=
SUMATRA_PDF_PATH=C:\Users\LENOVO\AppData\Local\SumatraPDF\SumatraPDF.exe
```

Catatan:
- Jika `PRINTER_NAME` dikosongkan, sistem auto-detect printer.
- `SUMATRA_PDF_PATH` wajib benar ke executable SumatraPDF.

### 4. Siapkan database

Aplikasi bisa auto-create DB/table saat pertama dipanggil (`db.php`), termasuk seed user dummy jika belum ada.

Opsional (manual import SQL):
- Import file: `database/dummy_users.sql`

### 5. Pastikan folder writable

Folder berikut harus bisa ditulis oleh web server:

- `uploads/`
- `logs/`

### 6. Buka aplikasi

```text
http://localhost/printserver/
```

### 7. Login akun dummy

Akun default (jika seed aktif):

- `23123456` / `dummy12345` (mahasiswa)
- `1987654321` / `dummy12345` (dosen)
- `19770001` / `admin12345` (admin)

## Cara Pakai Harian (User)

1. Login dengan NIM/NIPY + password.
2. Upload file PDF:
   - bisa rename sebelum upload
   - bisa samarkan nama file
3. Cek card file status `SIAP CETAK` (`uploaded`).
4. Klik `Print`:
   - pilih mode `Color` atau `Grayscale`
5. File masuk queue global (`ready`) dengan nomor antrian.
6. Saat diproses, status jadi `MENCETAK...` (`printing`).
7. Setelah benar-benar selesai spooler, status jadi `SELESAI` (`done`).
8. Countdown auto-delete berjalan 60 detik lalu file hilang dari list.

## Cara Pakai Admin (Manajemen User)

1. Login sebagai role `admin`.
2. Buka `users.php` (tombol `Kelola User` dari dashboard).
3. Fitur:
   - tambah user
   - edit user
   - reset password (saat edit isi field password)
   - nonaktifkan user
   - hapus user
4. Konfirmasi muncul untuk aksi edit/hapus.

## Status Job dan Artinya

Status di tabel `print_jobs`:

- `uploaded`: file sudah upload, belum dimasukkan queue
- `ready`: sudah masuk queue menunggu giliran
- `printing`: sedang diproses worker
- `done`: selesai print (menunggu auto-delete)
- `cancelled`: dibatalkan user
- `failed`: gagal print
- `deleted`: sudah dihapus (otomatis/manual)

## Aturan Queue Global

Urutan pengambilan job `ready` oleh worker:

1. `updated_at` terlama
2. lalu `id` terkecil

Implikasi:
- Jika user A print (job #1), user B print (#2), lalu user A print lagi saat queue masih berjalan, job baru A akan masuk di belakang (misalnya #3), bukan menyela urutan lama.

## Log Aktivitas (UI)

UI hanya menampilkan log `[print]` global dari file:

```text
logs/printer_YYYY-MM-DD.log
```

Label utama yang dipakai:

- `SENT`
- `QUEUE`
- `PRINT`
- `DONE`
- `CANCEL` / `ERROR` / lainnya

Catatan tampilan:
- Nama user di log ditampilkan nama saja.
- Warna badge dibedakan per kategori status.
- Jika user scroll area log, auto-scroll ditahan; jika idle 5 detik, auto-scroll aktif lagi.

## Monitoring DONE agar Real

Worker tidak langsung menandai `DONE` hanya karena command Sumatra sukses.

Worker melakukan:

1. Ambil baseline job spooler printer (`Get-PrintJob`) sebelum print.
2. Jalankan print command.
3. Pantau job baru di spooler sampai job tersebut hilang (selesai) atau timeout.
4. Baru set status `done` dan tulis log `Print done`.

Jika monitoring spooler tidak tersedia, worker pakai soft-hold beberapa detik berdasarkan ukuran file sebelum menandai selesai.

## Auto Delete

- Durasi: 60 detik setelah `printed_at`
- Trigger cleanup terjadi saat endpoint API dipanggil (`cleanupExpiredDoneJobs()`), jadi bersifat lazy cleanup.

## Endpoint API Utama

Semua endpoint ada di `api.php?action=...`.

### Auth

- `POST login`
- `GET auth_status`
- `POST logout`

### File/Queue (user login wajib)

- `POST upload_file`
- `GET get_files`
- `GET preview_file&job_id=<stored_filename>`
- `POST print_file`
- `POST reset_file_status` (retry)
- `POST cancel_print`
- `POST delete_file`
- `POST check_status`
- `GET get_logs` (hanya log print)
- `GET debug`

### User management (admin)

- `GET list_users`
- `POST create_user`
- `POST update_user`
- `POST delete_user`

## Menjalankan Worker Secara Manual

Normalnya worker dipanggil otomatis dari API saat ada job `ready`.

Manual run (opsional, untuk debug):

```powershell
cd C:\xampp\htdocs\printserver
C:\xampp\php\php.exe queue_worker.php 50
```

Argumen `50` = max job yang diproses per run.

## Troubleshooting

### 1. Tidak bisa login

- Cek `.env` koneksi DB
- Pastikan MySQL berjalan
- Pastikan tabel `users` ada
- Coba akun dummy default

### 2. Upload gagal

- File harus `.pdf`
- Ukuran max 100 MB
- Cek permission `uploads/`

### 3. Klik print berhasil tapi printer tidak keluar kertas

- Cek `SUMATRA_PDF_PATH`
- Cek printer online/default
- Cek log harian di `logs/`
- Coba set `PRINTER_NAME` eksplisit di `.env`

### 4. Preview tidak bisa dibuka

- Pastikan popup browser tidak diblok
- Pastikan file milik user yang sedang login

### 5. DONE terlalu cepat / tidak realistis

- Pastikan layanan spooler Windows aktif
- Pastikan command `Get-PrintJob` bisa dijalankan oleh user service web
- Jika monitoring spooler gagal, sistem fallback soft-hold

### 6. Queue atau status header tidak update

- Cek koneksi browser (polling)
- Hard refresh (`Ctrl+F5`)
- Pastikan endpoint `get_files` tidak mengembalikan `auth_required`

## Checklist Verifikasi End-to-End

1. Login user A.
2. Upload file A1, klik Print (pilih mode).
3. Login user B di browser lain, upload file B1, Print.
4. Login user A lagi, Print file A2.
5. Verifikasi urutan queue global (A1 -> B1 -> A2).
6. Verifikasi user A tidak bisa akses file user B (preview/delete/print).
7. Verifikasi log muncul urutan `SENT -> QUEUE -> PRINT -> DONE`.
8. Verifikasi countdown done 60 detik dan auto-delete jalan.

## Catatan Penting

- Sistem saat ini ditujukan untuk lingkungan Windows karena integrasi PowerShell + SumatraPDF.
- `queue.php` adalah file legacy dan bukan jalur utama UI terbaru.
- Beberapa utilitas di folder `test/` adalah utilitas lama/debug dan tidak semua mengikuti alur auth terbaru.

## Lisensi / Penggunaan Internal

Belum ada lisensi formal di repository ini. Gunakan sesuai kebijakan internal institusi.
