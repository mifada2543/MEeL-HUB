<div align="center">
  <img src="assets/MEeL.png" alt="MEeL Logo" width="500">

  # MEeL - Media Hub Platform

  **Platform media cloud terpadu untuk mengelola musik, video, buku, dan file dengan mudah**

  [![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue?style=flat-square)](https://www.php.net/)
  [![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange?style=flat-square)](https://www.mysql.com/)
  [![License](https://img.shields.io/badge/License-Custom-green?style=flat-square)](/LICENSE)
  [![Status](https://img.shields.io/badge/Status-Production%20Ready-success?style=flat-square)](https://github.com)

</div>

Aplikasi media hub berbasis PHP dan MySQL untuk mengelola koleksi video, musik, buku, dan file cloud pribadi/public dengan sistem user role, interaksi sosial, serta workflow upload/transcode.

## Rekomendasi Sistem Operasi
Sangat disarankan menjalankan platform ini di Linux (Ubuntu Server/Debian) karena:
- FFmpeg lebih stabil untuk transcoding video/audio.
- Permission file (`chmod/chown`) lebih mudah dikontrol.
- Case sensitivity path konsisten dengan lingkungan produksi web.

## Daftar Isi
- [Fitur Utama](#fitur-utama)
- [Struktur Proyek](#struktur-proyek)
- [Persyaratan Sistem](#persyaratan-sistem)
- [Instalasi](#instalasi)
- [Konfigurasi](#konfigurasi)
- [Penggunaan](#penggunaan)
- [Arsitektur Aplikasi](#arsitektur-aplikasi)
- [API dan Fungsi Utama](#api-dan-fungsi-utama)
- [Keamanan](#keamanan)
- [Troubleshooting](#troubleshooting)
- [File Penting untuk Development](#file-penting-untuk-development)
- [Lisensi](#lisensi)

---

## Fitur Utama
- Dashboard media gabungan (video, musik, buku, drive).
- Upload video/music/book dengan validasi dan metadata.
- Transcoding video ke HLS (`.m3u8` + segment `.ts`) dan thumbnail sprite VTT.
- Download media via URL (yt-dlp) untuk video/music.
- Sistem komentar bertingkat + like/dislike untuk video/music.
- Cloud drive dengan scope public/private, pembatasan role, dan kuota member.
- Session security (timeout 12 jam, CSRF token, validasi sesi aktif).

---

## Struktur Proyek
```text
MEeL/
├── auth/                        # Authentication dan session bootstrap
│   ├── config.php               # DB connection, CSRF, session timeout
│   ├── auth.php                 # Login guard + validasi session user
│   ├── login.php
│   ├── register.php
│   └── logout.php
│
├── modules/                     # Business logic reusable
│   ├── MediaLibrary.php         # Query layer video/music + BookRepository/BookUploader
│   ├── MediaViewer.php          # View counter, komentar, interaksi, rekomendasi
│   ├── Uploader.php             # Upload video/music lokal + transcode awal
│   ├── Transcoder.php           # Download/transcode media via yt-dlp + ffmpeg
│   ├── System.php               # Queue monitor, storage stats, rate limit
│   ├── MediaInteraction.php     # Interaksi media tambahan
│   ├── activity_logger.php      # Logging aktivitas/IP/session enforcement
│   └── helpers.php              # time_ago, format_bytes, get_user_usage, HDD check
│
├── video/                       # Modul video
│   ├── index.php
│   ├── watch.php
│   ├── upload.php
│   ├── search_video.php
│   ├── load_more.php
│   └── video_card.php
│
├── music/                       # Modul musik
│   ├── index.php
│   ├── index-content.php
│   ├── watch.php
│   ├── upload.php
│   ├── search_music.php
│   ├── load_more_music.php
│   ├── playlist_action.php
│   └── view_playlist.php
│
├── books/                       # Modul buku
│   ├── index.php
│   ├── read.php
│   └── upload.php
│
├── drive/                       # Cloud drive
│   ├── index.php
│   ├── upload.php
│   ├── download.php
│   ├── delete.php
│   └── DriveService.php
│
├── controllers/                 # Endpoint aksi UI (like, comment, profile edit, dll)
├── partials/                    # Komponen UI bersama
├── assets/                      # CSS/JS/image (Tailwind, HLS.js, Plyr, HTMX, Lucide)
├── data_drive/                  # Penyimpanan drive public/private
├── temp/                        # Staging transcoding/download
├── profile/                     # Halaman profil + avatar
├── anime/                       # Modul anime
├── err/                         # Halaman error
├── index.php                    # Homepage hub
├── transcode.php                # Entry untuk transcode/download media
├── upload_advanced.php          # Advanced upload flow
└── system_check.php             # Monitoring sistem
```

---

## Persyaratan Sistem
### Minimum Requirements
| Komponen | Versi | Status |
|----------|-------|--------|
| PHP | 7.4+ | Wajib |
| MySQL | 5.7+ / MariaDB 10.2+ | Wajib |
| Apache | 2.4+ | Wajib |
| RAM | 512MB+ | Rekomendasi |
| Storage | 1GB+ | Minimum |

### PHP Extensions
- `mysqli`
- `pdo`
- `gd`
- `fileinfo`
- `json`
- `mbstring`
- `intl` (dipakai untuk transliterasi `getRomajiName`)

### Software Dependencies
```bash
sudo apt-get install ffmpeg
sudo apt-get install yt-dlp
```

---

## Instalasi
### 1. Persiapan Database
```sql
CREATE DATABASE MEeL;
USE MEeL;
-- Import schema sesuai dump SQL yang Anda gunakan.
```

### 2. Konfigurasi Database
Edit `auth/config.php`:
```php
$conn = new mysqli("localhost", "root", "", "MEeL");
```

### 3. Struktur Folder Runtime
```bash
mkdir -p data_drive/public
mkdir -p data_drive/private_admins
mkdir -p temp
mkdir -p video/upload
mkdir -p music/upload
mkdir -p books/upload
mkdir -p profile/upload
chmod -R 755 data_drive temp video music books profile
```

### 4. Konfigurasi Apache
```bash
a2enmod rewrite
systemctl restart apache2
```

### 5. Session/Cookie
Session name default adalah `meel` dengan timeout `43200` detik (12 jam) di `auth/config.php`.

---

## Konfigurasi
### Session + CSRF (`auth/config.php`)
- Session timeout `43200` detik.
- CSRF token otomatis dibuat pada session.
- `verify_csrf()` digunakan untuk validasi request POST.

Snippet penting:
```php
$timeout = 43200;
session_set_cookie_params($timeout, "/");
session_name('meel');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
```

### Path Media dan HDD Check (`modules/helpers.php`)
```php
$hdd_check_path = '/media/muhammaddaffa/MEeL/media';
```
Jika path ini tidak ada, aplikasi akan redirect ke `err/maintance.php`.

### Path Upload Video (`modules/Uploader.php`)
Uploader video memakai base path hard-coded:
```php
$this->base_dir  = "/media/muhammaddaffa/MEeL/media/video/upload/";
```
Sesuaikan path ini saat deploy ke server lain.

---

## Penggunaan
### Akses Aplikasi
```text
http://localhost/MEeL/
```

### Role User
1. `admin`: akses penuh, termasuk operasi public drive dan monitoring queue.
2. `member`: upload, interaksi media, private drive dengan kuota.
3. `guest`: akses terbatas (tidak boleh melakukan beberapa aksi interaktif tertentu).

### Alur Umum
1. Login melalui `auth/login.php`.
2. Pilih modul (`video`, `music`, `books`, `drive`).
3. Upload atau konsumsi konten.
4. Gunakan fitur search, komentar, like/dislike, playlist sesuai modul.

---

## Arsitektur Aplikasi
### Authentication Flow
```text
Login -> auth/config.php (session + csrf)
      -> auth/auth.php (guard user_id + role + session validity)
      -> halaman modul
```

### Media Query Layer
- `modules/MediaLibrary.php` menjadi query layer utama untuk listing/search video/music.
- `BookRepository` (di file yang sama) menangani listing/detail buku.
- `MediaViewer` menangani detail watch page, komentar, views, rekomendasi.

### Upload/Transcode Flow
```text
Form Upload (video/music)
-> modules/Uploader.php
-> validasi file + metadata
-> ffmpeg (thumbnail/transcode)
-> simpan record DB
-> tampil di modul terkait
```

### URL Download/Encode Flow
```text
transcode.php atau controllers/post_encode.php
-> modules/Transcoder.php
-> yt-dlp fetch metadata + media
-> ffmpeg processing
-> simpan hasil ke storage + update DB
```

### Drive Flow
```text
drive/index.php
-> DriveUserContext::fromSession()
-> DriveStorage (list/upload/download/delete)
-> render UI by type (video/audio/dokumen)
```

---

## API dan Fungsi Utama
### `MediaLibrary` (`modules/MediaLibrary.php`)
Method yang dipakai lintas modul:
- `getCounts()`
- `getVideos($limit, $offset)`
- `countVideos()`
- `searchVideo($q, $exclude, $sidebar)`
- `getMusicList($format, $artist, $limit, $offset)`
- `countMusic($format, $artist)`
- `getArtists()`
- `getUserPlaylists($user_id)`
- `searchMusic($q, $exclude, $sidebar)`

Contoh:
```php
$library = new MediaLibrary($conn);
$videos = $library->getVideos(8, 0);
$results = $library->searchVideo('anime', 0, false);
```

### `BookRepository` dan `BookUploader` (`modules/MediaLibrary.php`)
- `BookRepository::getBooks($filter)`
- `BookRepository::getBookById($id)`
- `BookRepository::getUserRole($user_id)`
- `BookUploader` dipakai pada proses upload buku di `books/upload.php`.

### `MediaViewer` (`modules/MediaViewer.php`)
- `recordView()`
- `getMediaData()`
- `getUserInteraction()`
- `addComment($post_data)`
- `getComments()`
- `getRecommendations($limit)`
- `getPlaylistQueue($playlist_id)` (khusus mode music)

### `Uploader` (`modules/Uploader.php`)
- `processMusic($post, $files, $base_dir)`
- `processVideo($post, $files, $upload_dir)`

### `Transcoder` (`modules/Transcoder.php`)
- `processDownload($url, $type)`
- `checkServerBusy()` (deprecated, diarahkan ke `System`)
- Queue lock/release internal untuk proses download/transcode.

### Helper Functions (`modules/helpers.php`)
- `time_ago($timestamp)`
- `format_bytes($bytes, $precision = 2)`
- `get_user_usage($username)`

### Security Helper (`auth/config.php`)
- `verify_csrf()`

---

## Keamanan
### Implementasi Saat Ini
1. CSRF token per session (`auth/config.php`).
2. Session timeout otomatis 12 jam.
3. Validasi sesi aktif dan role di `auth/auth.php`.
4. Query DB mayoritas sudah memakai prepared statements.
5. Kontrol akses drive berdasarkan role (`DriveUserContext`).
6. Ban IP + activity tracking di `modules/activity_logger.php`.

### Catatan Production
- Simpan kredensial DB di environment variable.
- Nonaktifkan `display_errors` pada production.
- Aktifkan HTTPS + secure cookie flags.
- Audit path hard-coded sebelum deploy lintas server.

---

## Troubleshooting
### HDD/Storage Offline
Problem: redirect ke halaman maintenance.
Solusi:
1. Cek nilai `$hdd_check_path` di `modules/helpers.php`.
2. Pastikan mount point storage aktif (`df -h`).
3. Cek permission folder media.

### Session Expired
Problem: user kembali ke login.
Solusi:
1. Cek timeout di `auth/config.php` (`$timeout = 43200`).
2. Pastikan jam server sinkron (NTP).
3. Cek apakah user login dari device lain (single-session logic).

### Upload Failed
Problem: file gagal diunggah.
Solusi:
```bash
php -r "echo ini_get('upload_max_filesize'), PHP_EOL;"
php -r "echo ini_get('post_max_size'), PHP_EOL;"
which ffmpeg
which ffprobe
```

### Database Connection Error
Problem: muncul `Koneksi gagal`.
Solusi:
```bash
sudo systemctl status mysql
mysql -u root -p -e "SHOW DATABASES LIKE 'MEeL';"
```

### Permission Denied
Problem: file/folder tidak bisa ditulis/dihapus.
Solusi:
```bash
sudo chown -R www-data:www-data /opt/lampp/htdocs/MEeL
chmod -R 755 /opt/lampp/htdocs/MEeL/data_drive /opt/lampp/htdocs/MEeL/temp
```

---

## File Penting untuk Development
- `auth/config.php` - bootstrap session, DB, CSRF.
- `auth/auth.php` - middleware proteksi halaman user.
- `modules/MediaLibrary.php` - query layer utama media/books.
- `modules/MediaViewer.php` - logic watch page media.
- `modules/Uploader.php` - upload + transcode local file.
- `modules/Transcoder.php` - pipeline yt-dlp + ffmpeg.
- `modules/helpers.php` - helper global + HDD guard.
- `drive/DriveService.php` - service layer cloud drive.
- `assets/js/player_video.js` - logic player video (Plyr + HLS + resume).
- `video/watch.php` - halaman player video.

---

## Kontribusi
1. Ikuti struktur folder yang sudah ada.
2. Gunakan prepared statements untuk query baru.
3. Tambahkan validasi input di endpoint controller.
4. Uji upload/transcode di staging sebelum produksi.
5. Perbarui dokumentasi saat menambah fitur baru.

---

## Lisensi
Project ini open-source untuk pembelajaran dan pengembangan pribadi. Untuk publikasi ulang/komersial, lihat detail ketentuan di file `LICENSE`.

---

## Support
- Gunakan issue tracker repository untuk bug report.
- Gunakan diskusi internal tim untuk perubahan arsitektur besar.
- Kontak maintainer: `minecraft.daffa2501@gmail.com`.

---

<div align="center">

### Custom License Summary

```text
Boleh digunakan untuk:
- Pembelajaran & penelitian personal
- Pengembangan & modifikasi pribadi
- Deployment internal/pribadi

Memerlukan izin untuk:
- Publikasi resmi atau publikasi ulang
- Penggunaan komersial/penjualan
- Redistribusi ke publik
```

**Baca file [LICENSE](LICENSE) untuk detail lengkapnya**

</div>