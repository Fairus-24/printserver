# 🖨️ FIK Smart Print Server v2.0

**Professional Print Queue Management System**

Sistem server pencetakan pintar dengan UI modern untuk mengelola antrian PDF dan pencetakan ke printer EPSONL121.

## ✨ Fitur Utama

### 📤 Upload File PDF
- ✓ Drag & Drop support
- ✓ Validasi format PDF otomatis
- ✓ Limit ukuran: 100MB
- ✓ Feedback real-time upload status

### 📋 Antrian Cetak (Print Queue)
- ✓ Card-based file grid layout (modern)
- ✓ Status visual file (Ready, Printing, Completed)
- ✓ Tombol cetak & hapus di setiap card
- ✓ Real-time update setiap 5 detik
- ✓ Support multi-device/browser bersamaan

### 🖨️ Cetak File
- ✓ Tombol cetak prominent (hijau)
- ✓ Konfirmasi sebelum print
- ✓ Kirim ke printer EPSONL121 via SumatraPDF
- ✓ Proses asynkronis non-blocking
- ✓ Status tracking real-time

### 🗑️ Manajemen File
- ✓ Tombol hapus merah untuk remove dari antrian
- ✓ Konfirmasi sebelum delete
- ✓ Update grid otomatis
- ✓ Full multi-device sync

### 📊 Log Aktivitas Real-Time
- ✓ Dark theme professional log viewer
- ✓ Color-coded entries (success/error/info)
- ✓ Auto-scroll ke entry terbaru
- ✓ Update otomatis setiap 3 detik
- ✓ Daily log files: `logs/printer_YYYY-MM-DD.log`

### 🎨 Modern UI/UX
- ✓ Gradient background profesional
- ✓ Smooth animations & transitions
- ✓ Responsive mobile-friendly design
- ✓ Font Awesome icons untuk clarity
- ✓ Card-based layout dengan shadows

### 🔧 System Testing & Debug
- ✓ Test page di `test-system.php`
- ✓ Debug endpoint untuk troubleshooting
- ✓ Real-time system monitoring
- ✓ Permission & folder integrity checks

## Struktur File

```
printserver/
├── index.php              # Main application
├── api.php                # API endpoints
├── clear_session.php      # Session cleanup
├── queue.php              # Queue status
├── test.php               # System test
├── generate-test-pdf.php  # PDF test generator
├── uploads/               # File queue
└── logs/                  # Daily log files
```

## API Endpoints

### GET /api.php?action=get_files
Ambil daftar file dalam antrian
```json
{
  "success": true,
  "files": [...],
  "queue_count": 5
}
```

### POST /api.php?action=delete_file
Hapus file dari antrian
```
job_id=<filename>
```

### POST /api.php?action=check_status
Cek status print job
```
job_id=<filename>
```

### GET /api.php?action=get_logs
Ambil log entries (50 terakhir)
```json
{
  "success": true,
  "logs": [...]
}
```

## Cara Menggunakan

### 1. Upload File
- Klik area upload atau drag & drop file PDF
- Klik tombol "Upload & Print"
- Tunggu konfirmasi "File berhasil masuk ke antrian"

### 2. Monitor Status
- Lihat progress bar pencetakan
- Monitor log real-time
- Lihat queue counter di header

### 3. Manage Antrian
- Klik tombol "+" untuk tambah file
- Klik "Hapus" untuk remove file dari antrian

## Konfigurasi

Edit `index.php` untuk mengubah:
- `$printer` = Nama printer (default: EPSONL121)
- `$uploadsDir` = Direktori file
- `$logsDir` = Direktori log
- `$sumatraPdfPath` = Path ke SumatraPDF.exe

## Troubleshooting

### Upload gagal
- Pastikan file adalah PDF
- Cek ukuran file (max 100MB)
- Verifikasi folder uploads/ writable

### Print tidak start
- Cek nama printer sudah benar
- Verifikasi SumatraPDF terinstall
- Cek permission ke file

### Log tidak terlihat
- Pastikan folder logs/ existe dan writable
- Restart aplikasi untuk clear cache

## Testing

Buka http://localhost/printserver/test.php untuk:
- Cek directory status
- Verifikasi file existency
- Monitor session data
- Review log files

## Teknologi

- PHP 7+
- HTML5, CSS3
- JavaScript (Vanilla)
- Windows PowerShell
- SumatraPDF CLI
- Session-based storage

## Notes

- File auto-delete 30 detik setelah print selesai
- Log file di-create per hari (printer_YYYY-MM-DD.log)
- Session persistence across page reload
- Real-time queue update setiap 5 detik
