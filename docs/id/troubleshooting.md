# 🔧 Troubleshooting & FAQ

Panduan pemecahan masalah umum di MEeL-HUB.

---

## Daftar Isi

- [Masalah Database](#masalah-database)
- [Masalah Storage & HDD](#masalah-storage--hdd)
- [Masalah HLS Streaming](#masalah-hls-streaming)
- [Masalah Transcoding](#masalah-transcoding)
- [Masalah yt-dlp](#masalah-yt-dlp)
- [Masalah Sesi & Login](#masalah-sesi--login)
- [Masalah Upload](#masalah-upload)
- [Masalah Performa](#masalah-performa)
- [Masalah Ikon](#masalah-ikon)
- [Error Codes](#error-codes)
- [FAQ](#faq)
- [Advanced Upload & Antrean](#advanced-upload--antrean)

---

## Masalah Database

### ❌ "Koneksi ke database gagal"

**Penyebab:**
- MySQL/MariaDB tidak berjalan
- Kredensial di `auth/settings.php` salah
- Database `MEeL` belum dibuat

**Solusi:**
```bash
# Cek status MySQL
sudo systemctl status mysql
# atau untuk LAMPP
sudo /opt/lampp/lampp status

# Jika tidak berjalan
sudo systemctl start mysql

# Verifikasi kredensial
mysql -u root -p -e "SHOW DATABASES;"

# Buat database jika belum ada
mysql -u root -p -e "CREATE DATABASE MEeL DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
```

**Khusus MariaDB: `root` memakai plugin `unix_socket`**

Pada instalasi MariaDB default, `root` ter-autentikasi lewat plugin
`unix_socket` — koneksi hanya sah dari proses yang berjalan sebagai user OS
`root` (via socket). Akibatnya `mysql -u root` sukses di CLI, tapi koneksi dari
proses web server (`www-data`) gagal **"Access denied"** meskipun password
kosong. Solusi yang disarankan: buat user database khusus untuk aplikasi:

```sql
CREATE USER 'meel'@'localhost' IDENTIFIED BY 'password_kuat';
GRANT ALL PRIVILEGES ON MEeL.* TO 'meel'@'localhost';
FLUSH PRIVILEGES;
```

Lalu pakai kredensial itu di `auth/settings.php` (`$username`/`$password`).
Alternatif (kurang disarankan): ubah `root` ke `mysql_native_password`:
```sql
ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('password_baru');
```

### ❌ "Tabel tidak ditemukan"

**Penyebab:** SQL belum di-import

**Solusi:**
```bash
mysql -u root -p MEeL < database/schema.sql
```
File `database/schema.sql` berisi seluruh skema (23 tabel + admin default) — import langsung.

### ❌ "Column 'description' cannot be null" (atau error kolom lain)

**Penyebab:** Versi skema database tidak sesuai dengan kode

**Solusi:** Jalankan ALTER TABLE untuk menambahkan kolom yang hilang:
```sql
ALTER TABLE video ADD COLUMN description text DEFAULT NULL;
ALTER TABLE music ADD COLUMN description text DEFAULT NULL;
ALTER TABLE video ADD COLUMN duration int(11) DEFAULT 0;
ALTER TABLE music ADD COLUMN duration int(11) DEFAULT 0;
ALTER TABLE video ADD COLUMN search_metadata text DEFAULT NULL;
ALTER TABLE music ADD COLUMN search_metadata text DEFAULT NULL;
ALTER TABLE users ADD COLUMN last_session_id varchar(128) DEFAULT NULL;
ALTER TABLE users ADD COLUMN access_via varchar(100) DEFAULT NULL;
ALTER TABLE comments ADD COLUMN comment text NOT NULL;
```

---

## Masalah Storage & HDD

### ❌ "HDD tidak terdeteksi"

**Penyebab:** Partisi belum dimount atau permission salah

**Solusi:**
```bash
# Cek partisi
lsblk
sudo mount | grep media

# Mount manual
sudo mkdir -p /media/muhammaddaffa/MEeL/media
sudo mount /dev/nvme0n1p5 /media/muhammaddaffa/MEeL/media

# Set permission
sudo chown -R www-data:www-data /media/muhammaddaffa/MEeL/media
```

### ❌ "Storage penuh"

**Penyebab:** Partisi `/` penuh atau quota habis

**Solusi:**
```bash
# Cek penggunaan disk
df -h

# Bersihkan cache lama
git clean -xdf
docker system prune -af

# Hapus file temporer besar
sudo find /tmp -type f -size +100M -delete

# Tambah storage (opsional)
sudo lvextend -l +100%FREE /dev/ubuntu-vg/root
sudo resize2fs /dev/ubuntu-vg/root
```

### ❌ "Upload gagal: permission denied"

**Penyebab:** Direktori upload tidak memiliki permission write untuk user web

**Solusi:**
```bash
# Set ownership
sudo chown -R www-data:www-data data_drive temp profile/upload music/upload books/upload

# Set permission (770 untuk direktori, 640 untuk file)
find data_drive temp profile/upload music/upload books/upload -type d -exec chmod 770 {} \;
find data_drive temp profile/upload music/upload books/upload -type f -exec chmod 640 {} \;
```

---

## Masalah HLS Streaming

### ❌ "Video tidak bisa diputar (HLS error)"

**Penyebab:** File segment HLS hilang atau playlist rusak

**Solusi:**
```bash
# Cek direktori HLS
ls -la data_drive/video/hls/

# Re-generate HLS
php modules/transcoder/cli/regenerate_hls.php --video-id=123
```

### ❌ "Thumbnail tidak muncul"

**Penyebab:** Thumbnail gagal di-generate atau path salah

**Solusi:**
```bash
# Cek direktori thumbnail
ls -la data_drive/video/thumbnail/

# Generate ulang thumbnail
php modules/transcoder/cli/generate_thumbnails.php --video-id=123

# Cek permission
sudo chown -R www-data:www-data data_drive/video/thumbnail/
```

### ❌ "HLS tidak ter-streaming (404)"

**Penyebab:** Apache tidak mengizinkan akses ke direktori HLS

**Solusi:** Pastikan `.htaccess` di direktori HLS mengizinkan akses:
```apache
<FilesMatch "\.(m3u8|ts)$">
    Require all granted
</FilesMatch>
```

---

## Masalah Transcoding

### ❌ "Transcoding gagal: ffmpeg tidak ditemukan"

**Penyebab:** Path FFmpeg salah atau binary tidak ada

**Solusi:**
```bash
# Cek path yang digunakan
php -r "echo MEEL_FFMPEG_PATH ?? 'tidak didefinisikan';"

# Cek binary
ls -la tools/ffmpeg-lite/ffmpeg

# Update path di auth/settings.php
define('MEEL_FFMPEG_PATH', '/opt/lampp/htdocs/MEeL/tools/ffmpeg-lite/ffmpeg');
```

### ❌ "Format output tidak didukung"

**Penyebab:** FFmpeg tidak memiliki encoder/decoder yang dibutuhkan

**Solusi:** Build ulang FFmpeg dengan komponen yang dibutuhkan:
```bash
cd tools/ffmpeg-build
./build-all.sh
```

### ❌ "Audio tidak sinkron"

**Penyebab:** Metadata audio rusak atau transcoding tidak stabil

**Solusi:** Gunakan `-async 1` atau `-itsoffset`:
```bash
ffmpeg -i input.mp4 -itsoffset 0.5 -c copy output.mp4
```

---

## Masalah yt-dlp

### ❌ "Gagal mengambil metadata" atau "Gagal mem-parsing metadata"

**Penyebab 1: yt-dlp tidak ditemukan di path yang diharapkan**
Paket MEeL tidak lagi membundel yt-dlp. `install.sh` mengunduh binary ke `tools/yt-dlp/yt-dlp`; jika gagal, pasang yt-dlp sistem dan set `MEEL_YTDLP_PATH` di `auth/settings.php`.
```bash
# Cek binary lokal
ls -la tools/yt-dlp/yt-dlp
tools/yt-dlp/yt-dlp --version

# Update binary lokal
cd tools/yt-dlp && ./update.sh
```

**Penyebab 2: Node.js tidak terpasang**
```bash
which node
# Jika tidak ditemukan:
sudo apt install nodejs
```

**Penyebab 3: Cookies tidak valid/kedaluwarsa**
- Ekspor ulang cookies dari browser
- Pastikan login ke YouTube/platform target
- Simpan sebagai `cookies.txt` di `temp/` (file lama di root auto-migrasi)

**Penyebab 4: Update yt-dlp**
```bash
# Update binary lokal (direkomendasikan)
cd tools/yt-dlp && ./update.sh

# Atau jika pakai yt-dlp sistem
yt-dlp -U
# atau
pip3 install -U yt-dlp
```

### ❌ "Download gagal" / error yt-dlp

Cek error log:
```bash
cat /tmp/ytdlp_error.log
```

**Solusi umum:**
1. Update yt-dlp ke versi terbaru (lokal: `cd tools/yt-dlp && ./update.sh`)
2. Update cookies.txt
3. Coba URL langsung di terminal:
   ```bash
   tools/yt-dlp/yt-dlp --cookies temp/cookies.txt "https://youtube.com/watch?v=..."
   ```
4. Jika video private/terkunci region, tidak bisa diunduh

### ❌ Debug Metadata

Saat metadata gagal di-parse, Transcoder menampilkan overlay debug:
```
╔═══════════════════════════════════════════════════╗
║ ⚠️ DEBUG: GAGAL PARSING METADATA                  ║
║                                                   ║
║ URL: https://youtube.com/watch?v=...              ║
║ Kode Kembali: 1 (0 = sukses)                      ║
║ Output: ...                                       ║
║                                                   ║
║ Cek Path Sistem:                                  ║
║ - Node:    /usr/bin/node                          ║
║ - yt-dlp:  tools/yt-dlp/yt-dlp (lokal)            ║
║ - ffmpeg:  tools/ffmpeg-lite/ffmpeg (bundled)     ║
╚═══════════════════════════════════════════════════╝
```

---

## Masalah Sesi & Login

### ❌ Sesi Tiba-tiba Berakhir

**Penyebab:**
- Timeout sesi (12 jam)
- Sesi di-kick oleh admin
- Login dari perangkat lain

**Solusi:**
1. Login ulang
2. Jika sering terjadi, cek waktu server:
   ```bash
   timedatectl status
   ```
3. Pastikan folder sesi PHP dapat ditulis:
   ```bash
   sudo chmod 777 /var/lib/php/sessions
   # atau
   sudo chmod 777 /tmp
   ```

### ❌ Redirect ke `/err/?code=revoked` (Sesi Dicabut)

**Penyebab:** ID sesi di browser tidak cocok dengan yang terdaftar di database (misal: di-kick admin, login dari perangkat lain, atau cookie sesi dihapus/tidak persisten).

**Solusi:**
1. Klik **Back** pada layar error, lalu login ulang (atau buka `auth/login.php`)
2. Hapus cache dan cookies browser secara spesifik untuk domain platform ini
3. Jika menggunakan Cloudflare Tunnel, pastikan pengaturan cookie sesi dikonfigurasi untuk tetap persisten
4. Coba akses menggunakan mode Incognito/Private Window di browser

### ❌ Redirect ke `/err/?code=banned` (Akses Diblokir)

**Penyebab:** Alamat IP Anda terdaftar di tabel blokir firewall internal (`ip_ban`)

**Solusi:**
1. Hubungi Admin MEeL-HUB untuk menghapus IP Anda dari daftar melalui Admin Panel → **Firewall & Banned IPs**
2. Jika Anda seorang Admin, Anda dapat melewati blokir secara default (karena peran admin dikecualikan dari pemeriksaan ban di `activity_logger.php`), atau login menggunakan koneksi lokal/IP lain terlebih dahulu untuk menghapus IP melalui dashboard

### ❌ Login Berulang Gagal (Terkunci)

**Penyebab:** Terlalu banyak percobaan login gagal

**Solusi:**
- Tunggu 15 menit sebelum mencoba lagi
- Reset password jika lupa:
  ```bash
  php modules/core/cli/reset_password.php --user=username
  ```

---

## Masalah Upload

### ❌ "Upload gagal: ukuran file terlalu besar"

**Penyebab:** Batas upload PHP atau server terlalu kecil

**Solusi:**
```bash
# Cek batas PHP
php -r "echo ini_get('upload_max_filesize').'\n';";
php -r "echo ini_get('post_max_size').'\n';";

# Edit php.ini
sudo nano /etc/php/8.2/apache2/php.ini
# Ubah:
# upload_max_filesize = 1024M
# post_max_size = 1024M

sudo systemctl restart apache2
```

### ❌ "Upload gagal: tipe file tidak diizinkan"

**Penyebab:** Ekstensi file tidak ada di daftar putih

**Solusi:** Tambahkan ekstensi di `auth/config.php`:
```php
$allowed_extensions = [
    'mp4', 'webm', 'mkv', 'mp3', 'wav', 'flac', 'ogg', 'm4a',
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'epub', 'mobi',
];
```

### ❌ "Upload lambat atau gagal di tengah"

**Penyebab:** Timeout PHP, koneksi lambat, atau memory limit kecil

**Solusi:**
```bash
# Tingkatkan batas PHP
sudo nano /etc/php/8.2/apache2/php.ini
# Ubah:
# max_execution_time = 300
# memory_limit = 512M

sudo systemctl restart apache2
```

---

## Masalah Performa

### ❌ MEeL lambat / lambat merespons

**Penyebab:**
- Database besar tanpa indeks
- Terlalu banyak proses transcoding berjalan
- Memory server kurang

**Solusi:**
```bash
# Optimasi database
php database/optimize.php

# Batasi proses transcoding
sudo nano /etc/systemd/system/meel-transcoder.service
# Tambah: TasksMax=16

# Tambah memory
sudo nano /etc/default/grub
# GRUB_CMDLINE_LINUX="mem=8G"
sudo update-grub
sudo reboot
```

### ❌ Transcoding video memakan waktu lama

**Penyebab:** Video resolusi tinggi atau format tidak efisien

**Solusi:** Gunakan stream copy jika memungkinkan:
```bash
# Untuk video yang sudah H.264/AAC, gunakan stream copy:
ffmpeg -i input.mp4 -c copy output.mp4
```

---

## Masalah Ikon

### ❌ Ikon tidak muncul / simbol tanya

**Penyebab:** Font Awesome tidak terload atau path salah

**Solusi:**
```bash
# Cek network di browser (F12 → Network → Font)
# Pastikan tidak ada 404 pada font-awesome

# Jika offline, gunakan CDN:
# <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
```

### ❌ Emoji tidak muncul

**Penyebab:** Font emoji tidak terinstall di server

**Solusi:**
```bash
# Install noto-fonts
sudo apt install fonts-noto-color-emoji
# Restart Apache
sudo systemctl restart apache2
```

---

## Error Codes

| Kode | Pesan | Solusi Umum |
|------|-------|-------------|
| **001** | Database connection failed | Cek MySQL/MariaDB, kredensial, dan firewall |
| **002** | Session expired | Login ulang atau hapus cookies |
| **003** | Storage full | Bersihkan disk atau tambah storage |
| **004** | Permission denied | `chown www-data:www-data` pada direktori yang relevan |
| **005** | FFmpeg not found | Set `MEEL_FFMPEG_PATH` di `auth/settings.php` |
| **006** | yt-dlp not found | Update binary lokal di `tools/yt-dlp/` |
| **007** | HLS 404 | Cek `.htaccess` dan permission direktori HLS |
| **008** | Upload size exceeded | Tingkatkan `upload_max_filesize` di PHP |
| **009** | Invalid file type | Tambahkan ekstensi ke daftar putih |
| **010** | Banned IP | Hubungi admin untuk unban |

---

## FAQ

### Bagaimana cara backup database?
```bash
mysqldump -u root -p MEeL > meel_backup_$(date +%F).sql
```

### Bagaimana cara restore database?
```bash
mysql -u root -p MEeL < meel_backup_2026-10-31.sql
```

### Bagaimana cara membersihkan cache?
```bash
# Cache aplikasi
php modules/core/cli/clear_cache.php

# Cache browser (Ctrl+Shift+Del)
# Cache sistem
sudo systemd-tmpfiles --clean
```

### Bagaimana cara menambah admin?
```bash
# Jalankan CLI
php modules/core/cli/add_admin.php --user=username
```

### Bagaimana cara menonaktifkan akun?
```bash
# Jalankan CLI
php modules/core/cli/toggle_user.php --user=username --disable
```

---

## Advanced Upload & Antrean

### Bagaimana cara melihat antrean download?
```bash
# Lihat tabel antrean
mysql -u root -p MEeL -e "SELECT * FROM upload_queue WHERE status='processing' ORDER BY id DESC;"
```

### Bagaimana cara membatalkan antrean?
```bash
# Hapus dari database
mysql -u root -p MEeL -e "DELETE FROM upload_queue WHERE id=123;"
```

### Bagaimana cara memprioritaskan antrean?
Edit `modules/transcoder/DownloadService.php` dan ubah urutan pengolahan di `buildDownloadContext()`.

### Bagaimana cara debug download yang gagal?
```bash
# Aktifkan logging detail di DownloadService.php
# Cek /tmp/ytdlp_error.log
cat /tmp/ytdlp_error.log

# Jalankan yt-dlp manual
tools/yt-dlp/yt-dlp --cookies temp/cookies.txt "URL_VIDEO"
```

---
