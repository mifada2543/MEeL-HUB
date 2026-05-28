<div align="center">
  <img src="assets/MEeL.png" alt="MEeL Logo" width="500">

  # MEeL — Media Hub Platform

  **Platform media cloud terpadu untuk streaming video, musik, membaca buku digital, dan penyimpanan file pribadi**

  [![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
  [![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
  [![TailwindCSS](https://img.shields.io/badge/Tailwind_CSS-CDN-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white)](https://tailwindcss.com/)
  [![License](https://img.shields.io/badge/License-Custom-22c55e?style=flat-square)](/LICENSE)

</div>

---

MEeL adalah media hub berbasis PHP & MySQL yang berjalan di atas Apache (XAMPP/LAMPP). Platform ini menggabungkan empat modul utama — **Video**, **Music**, **Books**, dan **Cloud Drive** — dalam satu antarmuka web gelap bertema monospace dengan streaming HLS, transcoding FFmpeg, dan manajemen file berbasis role.

## Daftar Isi

- [Fitur Utama](#fitur-utama)
- [Tech Stack](#tech-stack)
- [Struktur Proyek](#struktur-proyek)
- [Persyaratan Sistem](#persyaratan-sistem)
- [Instalasi](#instalasi)
- [Konfigurasi](#konfigurasi)
- [Penggunaan](#penggunaan)
- [Arsitektur Aplikasi](#arsitektur-aplikasi)
- [Modul & API](#modul--api)
- [Keamanan](#keamanan)
- [Troubleshooting](#troubleshooting)
- [Lisensi](#lisensi)

---

## Fitur Utama

### 🎬 Video
- Streaming HLS (`.m3u8` + segment `.ts`) dengan fallback MP4.
- Player berbasis Plyr dengan dukungan quality switching, preview thumbnail sprite (VTT), PiP, keyboard shortcut, dan mini player SPA.
- Gesture sentuh kustom untuk mobile (double-tap rewind/forward, tap tengah play/pause).
- Seamless next-video transition (tetap fullscreen tanpa reload halaman).
- Resume posisi terakhir via `localStorage`.

### 🎵 Music
- Streaming audio dengan Plyr (MP3/FLAC/lossless).
- Sistem playlist dan antrian putar.
- Filter berdasarkan format dan artis.

### 📚 Books
- Pembaca buku/komik digital di browser.
- Upload dan katalog buku dengan cover dan metadata.

### ☁️ Cloud Drive
- Penyimpanan file public dan private per user.
- Pembatasan akses berdasarkan role (`admin` / `member`).
- Upload, download, dan hapus file dengan kuota per user.

### 🔧 Umum
- Dashboard hub terpusat dengan statistik jumlah media.
- Download media via URL (yt-dlp) + transcoding otomatis (FFmpeg).
- Komentar bertingkat + like/dislike untuk video & music.
- Profil user dengan avatar dan edit akun.
- Session security (timeout 12 jam, CSRF token, single-session enforcement).
- Activity logging & ban IP.
- Mode sehat 20-20-20 untuk istirahat mata.
- Halaman panduan interaktif (`introduction.php`).
- Changelog (`update.php`).

---

## Tech Stack

| Layer | Teknologi |
|-------|-----------|
| Backend | PHP 8.0+, MySQL/MariaDB |
| Web Server | Apache 2.4+ (mod_rewrite) |
| Frontend CSS | TailwindCSS (CDN), custom CSS per modul |
| Frontend JS | HTMX, Lucide Icons, SweetAlert2 |
| Media Player | Plyr.js, HLS.js |
| Transcoding | FFmpeg, FFprobe |
| Downloader | yt-dlp |
| Transliterasi | PHP `intl` (Transliterator) |

---

## Struktur Proyek

```text
MEeL/
├── auth/                          # Autentikasi & session
│   ├── config.php                 # Koneksi DB, session, CSRF token, getRomajiName()
│   ├── auth.php                   # Login guard + validasi session aktif
│   ├── login.php
│   ├── register.php
│   └── logout.php
│
├── modules/                       # Business logic (reusable)
│   ├── MediaLibrary.php           # Query layer video/music + BookRepository + BookUploader
│   ├── MediaViewer.php            # Watch page: views, komentar, interaksi, rekomendasi
│   ├── MediaInteraction.php       # Like/dislike, interaksi media tambahan
│   ├── Uploader.php               # Upload video/music lokal + ffmpeg thumbnail/transcode
│   ├── Transcoder.php             # Download & transcode via yt-dlp + ffmpeg
│   ├── System.php                 # Queue monitor, storage stats, rate limit
│   ├── activity_logger.php        # Logging aktivitas, IP tracking, session enforcement
│   └── helpers.php                # time_ago(), format_bytes(), get_user_usage(), HDD check
│
├── video/                         # Modul Video
│   ├── index.php                  # Halaman daftar video
│   ├── watch.php                  # Halaman pemutar video
│   ├── upload.php                 # Form upload video
│   ├── search_video.php           # Endpoint pencarian video
│   ├── load_more.php              # Infinite scroll / load more
│   └── video_card.php             # Komponen kartu video
│
├── music/                         # Modul Music
│   ├── index.php                  # Halaman daftar musik (termasuk content)
│   ├── watch.php                  # Halaman pemutar musik
│   ├── upload.php                 # Form upload musik
│   ├── search_music.php           # Endpoint pencarian musik
│   ├── load_more_music.php        # Infinite scroll musik
│   ├── music_item.php             # Komponen item musik
│   ├── playlist_action.php        # CRUD playlist
│   └── view_playlist.php          # Tampilan playlist
│
├── books/                         # Modul Buku
│   ├── index.php                  # Katalog buku
│   ├── read.php                   # Pembaca buku digital
│   └── upload.php                 # Form upload buku
│
├── drive/                         # Cloud Drive
│   ├── DriveService.php           # Service layer (DriveUserContext, DriveStorage)
│   ├── index.php                  # Halaman drive
│   ├── upload.php                 # Upload file drive
│   ├── download.php               # Download file drive
│   └── delete.php                 # Hapus file drive
│
├── anime/                         # Modul Anime (dalam pengembangan)
│   ├── index.php
│   ├── watch.php
│   └── sidebar.php
│
├── controllers/                   # Endpoint aksi UI
│   ├── like.php                   # Like/dislike handler
│   ├── delete_comment.php         # Hapus komentar
│   ├── profile_edit.php           # Edit profil & avatar
│   ├── post_encode.php            # Trigger transcode dari UI
│   ├── proses_sidebar.php         # Proses sidebar
│   ├── proses_update.php          # Proses update
│   └── fun.php                    # Fungsi utilitas controller
│
├── partials/                      # Komponen UI bersama
│   ├── nav.php                    # Navigasi utama (sidebar/navbar)
│   ├── navbar.php                 # Navbar homepage
│   ├── ui.php                     # Komponen UI reusable
│   ├── link.php                   # Link stylesheet/script bersama
│   └── footer.php                 # Footer
│
├── profile/                       # Halaman profil
│   ├── index.php                  # Tampilan profil user
│   └── upload/                    # Direktori avatar upload
│
├── assets/                        # Aset statis
│   ├── css/                       # Stylesheet
│   │   ├── styles.css             # Stylesheet utama
│   │   ├── plyr.css               # Stylesheet player Plyr
│   │   ├── video.css              # Style khusus modul video
│   │   ├── music.css              # Style khusus modul musik
│   │   ├── drive.css              # Style khusus cloud drive
│   │   ├── up.css                 # Style halaman upload
│   │   └── font.css               # Font definitions
│   ├── js/                        # JavaScript
│   │   ├── tailwind.js            # TailwindCSS runtime
│   │   ├── plyr.js                # Plyr media player
│   │   ├── hls.js                 # HLS.js streaming library
│   │   ├── htmx.js                # HTMX untuk partial page update
│   │   ├── lucide.js              # Lucide icon library
│   │   ├── sweetalert2.all.min.js.js  # SweetAlert2
│   │   ├── player_video.js        # Logic player video kustom
│   │   ├── player_music.js        # Logic player musik kustom
│   │   └── script.js              # Script umum (tema, navigasi)
│   ├── img/                       # Gambar statis
│   ├── MEeL.png                   # Logo MEeL
│   └── logo.png                   # Logo kecil
│
├── data_drive/                    # Penyimpanan drive (public/private)
├── temp/                          # Staging area transcoding/download
├── err/                           # Halaman error
│   ├── denied.php                 # Akses ditolak
│   └── maintance.php              # Mode maintenance (storage offline)
│
├── index.php                      # Homepage hub
├── introduction.php               # Halaman panduan penggunaan
├── update.php                     # Changelog / riwayat pembaruan
├── transcode.php                  # Entry point transcode/download media
├── upload_advanced.php            # Advanced upload flow (admin)
├── system_check.php               # Monitoring & admin panel
├── cookies.php                    # Kebijakan cookie
├── about.html                     # Halaman tentang MEeL
├── .htaccess                      # URL rewriting rules
├── LICENSE                        # Lisensi proyek
└── README.md                      # Dokumentasi ini
```

---

## Persyaratan Sistem

### Minimum Requirements

| Komponen | Versi | Keterangan |
|----------|-------|------------|
| PHP | 7.4+ | Wajib |
| MySQL | 5.7+ / MariaDB 10.2+ | Wajib |
| Apache | 2.4+ (mod_rewrite) | Wajib |
| FFmpeg | 6.0+ | Wajib untuk transcoding |
| yt-dlp | latest | Opsional (download URL) |
| RAM | 2GB+ | Minimum untuk transcoding |
| Storage | 8GB+ | Tergantung jumlah media |

### PHP Extensions

```
mysqli, pdo, gd, fileinfo, json, mbstring, intl
```

> **Catatan:** Extension `intl` dibutuhkan untuk transliterasi nama file via `Transliterator` (fungsi `getRomajiName` di `auth/config.php`).

### Instalasi Dependencies (Linux)

```bash
sudo apt-get install ffmpeg
pip install yt-dlp
# atau
sudo apt-get install yt-dlp
```

---

## Instalasi

### 1. Clone Repository

```bash
cd /opt/lampp/htdocs
git clone <repo-url> MEeL
```

### 2. Persiapan Database

```sql
CREATE DATABASE MEeL CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE MEeL;
-- Import schema dari dump SQL proyek
```

### 3. Konfigurasi Database

Edit `auth/config.php`:
```php
$conn = new mysqli("localhost", "root", "", "MEeL");
```

### 4. Buat Struktur Folder Runtime

```bash
cd /opt/lampp/htdocs/MEeL
mkdir -p data_drive/public data_drive/private_admins
mkdir -p temp
mkdir -p profile/upload
chmod -R 755 data_drive temp profile/upload
```

### 5. Aktifkan mod_rewrite

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 6. Akses Aplikasi

```
http://localhost/MEeL/
```

---

## Konfigurasi

### Session & CSRF (`auth/config.php`)

- Session name: `meel`
- Timeout: `43200` detik (12 jam)
- CSRF token otomatis per session via `bin2hex(random_bytes(32))`
- Validasi: `verify_csrf()` untuk semua request POST

### Path Media Storage (`modules/helpers.php`)

```php
$hdd_check_path = '/media/muhammaddaffa/MEeL/media';
```

Jika path ini tidak tersedia, aplikasi redirect ke `err/maintance.php`. **Sesuaikan path ini untuk environment Anda.**

### Path Upload Video (`modules/Uploader.php`)

```php
$this->base_dir = "/media/muhammaddaffa/MEeL/media/video/upload/";
```

**Wajib disesuaikan saat deploy ke server lain.**

---

## Penggunaan

### Role User

| Role | Akses |
|------|-------|
| `admin` | Akses penuh: upload, transcode, monitoring queue, public drive, admin panel |
| `member` | Upload media, interaksi (komentar/like), private drive dengan kuota |
| `guest` | Akses terbatas: menonton/mendengar, tanpa interaksi |

### Alur Umum

1. Login melalui `auth/login.php`
2. Pilih modul dari homepage hub (`Video`, `Music`, `Books`, `Drive`)
3. Upload atau konsumsi konten
4. Gunakan fitur pencarian, komentar, like/dislike, dan playlist

### Keyboard Shortcuts (Video/Music Player)

| Tombol | Fungsi |
|--------|--------|
| `Space` / `K` | Play/Pause |
| `←` / `→` | Rewind / Forward |
| `↑` / `↓` | Volume naik / turun |
| `M` | Toggle mute |
| `F` | Toggle fullscreen |
| `C` | Toggle caption |
| `L` | Toggle loop |
| `I` | Toggle mini player |
| `0-9` | Seek 0-90% |

### Gesture Mobile (Video)

| Gesture | Zona | Fungsi |
|---------|------|--------|
| Double-tap | Kiri 40% | Rewind 5 detik |
| Double-tap | Kanan 40% | Forward 5 detik |
| Single-tap | Tengah 20% | Play/Pause |

---

## Arsitektur Aplikasi

### Authentication Flow

```
Login → auth/config.php (session + CSRF)
      → auth/auth.php (guard user_id + role + session validity)
      → Halaman modul
```

### Media Query Layer

```
index.php ─→ MediaLibrary::getCounts()
video/index.php ─→ MediaLibrary::getVideos()
video/watch.php ─→ MediaViewer::getMediaData()
                 ─→ MediaViewer::getComments()
                 ─→ MediaViewer::getRecommendations()
```

### Upload & Transcode Flow

```
Upload Lokal:
  Form → Uploader::processVideo() / processMusic()
       → FFmpeg thumbnail/transcode
       → Simpan record DB

Download URL:
  transcode.php → Transcoder::processDownload()
                → yt-dlp fetch → FFmpeg processing
                → Simpan ke storage + update DB
```

### Drive Flow

```
drive/index.php → DriveUserContext::fromSession()
                → DriveStorage::list/upload/download/delete
                → Render UI berdasarkan tipe file
```

### Seamless Video Transition (SPA-like)

```
Video ended → fetch halaman video berikutnya
            → parse HTML, swap sumber video (HLS/MP4)
            → update URL (pushState), info, komentar, rekomendasi
            → refresh VTT sprite thumbnails
            → re-enter fullscreen jika sebelumnya aktif
```

---

## Modul & API

### `MediaLibrary` — `modules/MediaLibrary.php`

| Method | Deskripsi |
|--------|-----------|
| `getCounts()` | Jumlah total video, music, books |
| `getVideos($limit, $offset)` | Daftar video dengan pagination |
| `countVideos()` | Total video |
| `searchVideo($q, $exclude, $sidebar)` | Pencarian video |
| `getMusicList($format, $artist, $limit, $offset)` | Daftar musik dengan filter |
| `countMusic($format, $artist)` | Total musik |
| `getArtists()` | Daftar artis |
| `getUserPlaylists($user_id)` | Playlist milik user |
| `searchMusic($q, $exclude, $sidebar)` | Pencarian musik |

### `BookRepository` & `BookUploader` — `modules/MediaLibrary.php`

| Method | Deskripsi |
|--------|-----------|
| `BookRepository::getBooks($filter)` | Daftar buku dengan filter |
| `BookRepository::getBookById($id)` | Detail buku |
| `BookRepository::getUserRole($user_id)` | Role user untuk akses buku |

### `MediaViewer` — `modules/MediaViewer.php`

| Method | Deskripsi |
|--------|-----------|
| `recordView()` | Catat view media |
| `getMediaData()` | Data lengkap media |
| `getUserInteraction()` | Status like/dislike user |
| `addComment($post_data)` | Tambah komentar |
| `getComments()` | Daftar komentar bertingkat |
| `getRecommendations($limit)` | Video/musik rekomendasi |
| `getPlaylistQueue($playlist_id)` | Antrian playlist musik |

### `Uploader` — `modules/Uploader.php`

| Method | Deskripsi |
|--------|-----------|
| `processVideo($post, $files, $upload_dir)` | Upload & proses video |
| `processMusic($post, $files, $base_dir)` | Upload & proses musik |

### `Transcoder` — `modules/Transcoder.php`

| Method | Deskripsi |
|--------|-----------|
| `processDownload($url, $type)` | Download & transcode media via URL |

### `DriveService` — `drive/DriveService.php`

Service layer untuk cloud drive dengan `DriveUserContext` (session-based role) dan `DriveStorage` (file CRUD dengan scope public/private).

---

## Keamanan

### Implementasi Aktif

1. **CSRF Protection** — Token per session, divalidasi di setiap POST via `verify_csrf()`.
2. **Session Timeout** — Auto-expire setelah 12 jam inaktif.
3. **Session Enforcement** — Validasi sesi aktif dan role di `auth/auth.php`.
4. **Prepared Statements** — Query DB mayoritas menggunakan prepared statements.
5. **Role-based Access** — Kontrol akses drive dan fitur admin berdasarkan role.
6. **Activity Logging** — Tracking IP, user agent, dan aktivitas di `modules/activity_logger.php`.
7. **Ban System** — Blokir IP tertentu melalui activity logger.

### Rekomendasi Production

- Simpan kredensial DB di environment variable, bukan hard-coded.
- Set `display_errors = Off` pada `php.ini`.
- Aktifkan HTTPS + `Secure` dan `HttpOnly` cookie flags.
- Audit semua path hard-coded sebelum deploy ke server yang berbeda.
- Batasi akses langsung ke folder `modules/`, `partials/`, dan `auth/` via `.htaccess`.

---

## Troubleshooting

### Storage Offline → Redirect ke Maintenance

```bash
# Cek apakah mount point media aktif
df -h
# Cek path di modules/helpers.php
grep 'hdd_check_path' modules/helpers.php
# Pastikan permission folder
ls -la /media/muhammaddaffa/MEeL/media
```

### Session Expired → User di-redirect ke Login

```bash
# Cek timeout di auth/config.php
grep 'timeout' auth/config.php
# Pastikan jam server sinkron
timedatectl status
```

### Upload Gagal

```bash
# Cek limit PHP
php -r "echo 'upload_max_filesize: '.ini_get('upload_max_filesize').PHP_EOL;"
php -r "echo 'post_max_size: '.ini_get('post_max_size').PHP_EOL;"

# Cek FFmpeg tersedia
which ffmpeg && ffmpeg -version | head -1
which ffprobe && ffprobe -version | head -1
```

### Database Error

```bash
# Cek status MySQL
sudo systemctl status mysql

# Cek database ada
mysql -u root -p -e "SHOW DATABASES LIKE 'MEeL';"
```

### Permission Denied

```bash
sudo chown -R www-data:www-data /opt/lampp/htdocs/MEeL
chmod -R 755 /opt/lampp/htdocs/MEeL/data_drive
chmod -R 755 /opt/lampp/htdocs/MEeL/temp
chmod -R 755 /opt/lampp/htdocs/MEeL/profile/upload
```

---

## Rekomendasi Sistem Operasi

Sangat disarankan menjalankan MEeL di **Linux** (Ubuntu Server / Debian) karena:
- FFmpeg lebih stabil untuk transcoding video/audio di Linux.
- Permission file (`chmod`/`chown`) lebih mudah dikontrol.
- Case sensitivity path konsisten dengan lingkungan web production.
- Tersedia LAMPP/XAMPP untuk Linux sebagai web server development.

---

## Kontribusi

1. Ikuti struktur folder yang sudah ada.
2. Gunakan prepared statements untuk semua query baru.
3. Tambahkan validasi input di setiap endpoint controller.
4. Uji upload/transcode di environment staging sebelum merge.
5. Perbarui dokumentasi saat menambah fitur baru.

---

## Lisensi

Proyek ini dilisensikan di bawah **Custom License by Mifada**.

<div align="center">

```
✅ Diizinkan:
  • Pembelajaran & penelitian personal
  • Pengembangan & modifikasi pribadi
  • Deployment internal / offline

⚠️ Memerlukan izin tertulis:
  • Publikasi resmi atau redistribusi ke publik
  • Penggunaan komersial / penjualan
  • Menghilangkan atribut pembuat asli
```

**Baca file [LICENSE](LICENSE) untuk detail lengkap**

</div>

---

<div align="center">

**MEeL** © 2025 — Mifada

`minecraft.daffa2501@gmail.com` · [github.com/mifada2543](https://github.com/mifada2543)

</div>