# MEeL — Media Hub Platform

<div align="center">
  <img src="assets/MEeL.png" alt="MEeL Logo" width="500" />
</div>

**Platform media cloud terpadu untuk streaming video, musik, membaca buku digital, dan penyimpanan file pribadi**

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![TailwindCSS](https://img.shields.io/badge/Tailwind_CSS-CDN-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white)](https://tailwindcss.com/)
[![License](https://img.shields.io/badge/License-Custom-22c55e?style=flat-square)](/LICENSE)

---

MEeL adalah media hub berbasis PHP & MySQL yang berjalan di atas Apache (XAMPP/LAMPP). Platform ini menggabungkan empat modul utama — **Video**, **Music**, **Books**, dan **Cloud Drive** — dalam satu antarmuka gelap bertema monospace dengan streaming HLS, transcoding FFmpeg, dan manajemen file berbasis role.

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
- Player berbasis Plyr dengan quality switching, preview thumbnail sprite (VTT), PiP, keyboard shortcuts, dan **mini player SPA**.
- **Gesture sentuh kustom untuk mobile**: double‑tap kiri (rewind 5 s), double‑tap kanan (forward 5 s), single‑tap tengah (play/pause).
- **Seamless next‑video transition**: tetap fullscreen tanpa reload halaman, termasuk refresh VTT sprite setelah transisi.
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
- Download media via URL (yt‑dlp) + transcoding otomatis (FFmpeg).
- Komentar bertingkat + like/dislike untuk video & music.
- Profil user dengan avatar dan edit akun.
- Session security (timeout 12 jam, CSRF token, single‑session enforcement).
- Activity logging & ban IP.
- **Mode sehat 20‑20‑20** untuk istirahat mata.
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
| Downloader | yt‑dlp |
| Transliterasi | PHP `intl` (Transliterator) |

---

## Struktur Proyek
```
MEeL/
├── auth/                 # Autentikasi & session
│   ├── config.php        # DB, session, CSRF, utils
│   ├── auth.php          # Guard login
│   ├── login.php
│   ├── register.php
│   └── logout.php
├── modules/              # Business logic
│   ├── MediaLibrary.php
│   ├── MediaViewer.php
│   ├── MediaInteraction.php
│   ├── Uploader.php
│   ├── Transcoder.php
│   ├── System.php
│   ├── activity_logger.php
│   └── helpers.php
├── video/                # Video module
│   ├── index.php
│   ├── watch.php
│   ├── upload.php
│   ├── search_video.php
│   ├── load_more.php
│   └── video_card.php
├── music/                # Music module
│   ├── index.php
│   ├── watch.php
│   ├── upload.php
│   ├── search_music.php
│   ├── load_more_music.php
│   ├── music_item.php
│   ├── playlist_action.php
│   └── view_playlist.php
├── books/                # Books module
│   ├── index.php
│   ├── read.php
│   └── upload.php
├── drive/                # Cloud Drive module
│   ├── DriveService.php
│   ├── index.php
│   ├── upload.php
│   ├── download.php
│   └── delete.php
├── anime/                # Anime (in development)
│   ├── index.php
│   ├── watch.php
│   └── sidebar.php
├── controllers/          # UI actions
│   ├── like.php
│   ├── delete_comment.php
│   ├── profile_edit.php
│   ├── post_encode.php
│   ├── proses_sidebar.php
│   ├── proses_update.php
│   └── fun.php
├── partials/             # Shared UI components
│   ├── nav.php
│   ├── navbar.php
│   ├── ui.php
│   ├── link.php
│   └── footer.php
├── profile/              # User profile
│   ├── index.php
│   └── upload/            # Avatar uploads
├── assets/
│   ├── css/
│   │   ├── styles.css
│   │   ├── plyr.css
│   │   ├── video.css
│   │   ├── music.css
│   │   ├── drive.css
│   │   ├── up.css
│   │   └── font.css
│   ├── js/
│   │   ├── tailwind.js
│   │   ├── plyr.js
│   │   ├── hls.js
│   │   ├── htmx.js
│   │   ├── lucide.js
│   │   ├── sweetalert2.all.min.js
│   │   ├── player_video.js   # Mobile gesture & VTT refresh logic
│   │   ├── player_music.js
│   │   └── script.js
│   ├── img/
│   ├── MEeL.png
│   └── logo.png
├── data_drive/           # Drive storage (public / private)
├── temp/                 # Staging area for transcoding / downloads
├── err/                  # Error pages
│   ├── denied.php
│   └── maintance.php
├── index.php
├── introduction.php
├── update.php
├── transcode.php
├── upload_advanced.php
├── system_check.php
├── cookies.php
├── about.html
├── .htaccess
├── LICENSE
└── README.md
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
| yt‑dlp | latest | Opsional (download URL) |
| RAM | 2 GB+ | Minimum untuk transcoding |
| Storage | 8 GB+ | Tergantung jumlah media |

### PHP Extensions
```
mysqli, pdo, gd, fileinfo, json, mbstring, intl
```
> **Catatan:** Extension `intl` diperlukan untuk transliterasi nama file via `Transliterator` (fungsi `getRomajiName` di `auth/config.php`).

### Instalasi Dependencies (Linux)
```bash
sudo apt-get install ffmpeg
pip install yt-dlp   # atau sudo apt-get install yt-dlp
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
mkdir -p data_drive/public data_drive/private_admins temp profile/upload
chmod -R 755 data_drive temp profile/upload
```
### 5. Aktifkan mod_rewrite
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```
### 6. Akses Aplikasi
Buka di browser: `http://localhost/MEeL/`
---

## Konfigurasi
### Session & CSRF (`auth/config.php`)
- Session name: `meel`
- Timeout: `43200` detik (12 jam)
- CSRF token otomatis per session via `bin2hex(random_bytes(32))`
- Validasi: `verify_csrf()` pada semua request POST

### Path Media Storage (`modules/helpers.php`)
```php
$hdd_check_path = '/media/muhammaddaffa/MEeL/media';
```
> **Catatan:** Sesuaikan path ini jika lingkungan Anda berbeda.

### Path Upload Video (`modules/Uploader.php`)
```php
$this->base_dir = '/media/muhammaddaffa/MEeL/media/video/upload/';
```
---

## Penggunaan
### Role User
| Role | Akses |
|------|-------|
| `admin` | Akses penuh (upload, transcode, monitoring, admin panel) |
| `member` | Upload media, interaksi, private drive dengan kuota |
| `guest` | Akses terbatas (tonton/mendengar tanpa interaksi) |

### Alur Umum
1. Login via `auth/login.php`
2. Pilih modul dari homepage (Video, Music, Books, Drive)
3. Upload atau konsumsi konten
4. Manfaatkan pencarian, komentar, like/dislike, playlist

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
| `0‑9` | Seek 0‑90% |

### Gesture Mobile (Video)
| Gesture | Zona | Fungsi |
|---------|------|--------|
| Double‑tap | Kiri 40% | Rewind 5 detik |
| Double‑tap | Kanan 40% | Forward 5 detik |
| Single‑tap | Tengah 20% | Play/Pause |
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
                 → yt‑dlp fetch → FFmpeg processing
                 → Simpan ke storage + update DB
```
### Drive Flow
```
drive/index.php → DriveUserContext::fromSession()
                 → DriveStorage::list/upload/download/delete
                 → Render UI berdasarkan tipe file
```
### Seamless Video Transition (SPA‑like)
```
Video ended → fetch halaman video berikutnya
            → parse HTML, swap sumber video (HLS/MP4)
            → update URL (pushState), info, komentar, rekomendasi
            → refresh VTT sprite thumbnails
            → re‑enter fullscreen bila sebelumnya aktif
```
---

## Modul & API
### `MediaLibrary` (`modules/MediaLibrary.php`)
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

### `BookRepository` & `BookUploader` (`modules/MediaLibrary.php`)
| Method | Deskripsi |
|--------|-----------|
| `BookRepository::getBooks($filter)` | Daftar buku dengan filter |
| `BookRepository::getBookById($id)` | Detail buku |
| `BookRepository::getUserRole($user_id)` | Role user untuk akses buku |

### `MediaViewer` (`modules/MediaViewer.php`)
| Method | Deskripsi |
|--------|-----------|
| `recordView()` | Catat view media |
| `getMediaData()` | Data lengkap media |
| `getUserInteraction()` | Status like/dislike user |
| `addComment($post_data)` | Tambah komentar |
| `getComments()` | Daftar komentar bertingkat |
| `getRecommendations($limit)` | Video/musik rekomendasi |
| `getPlaylistQueue($playlist_id)` | Antrian playlist musik |

### `Uploader` (`modules/Uploader.php`)
| Method | Deskripsi |
|--------|-----------|
| `processVideo($post, $files, $upload_dir)` | Upload & proses video |
| `processMusic($post, $files, $base_dir)` | Upload & proses musik |

### `Transcoder` (`modules/Transcoder.php`)
| Method | Deskripsi |
|--------|-----------|
| `processDownload($url, $type)` | Download & transcode media via URL |

### `DriveService` (`drive/DriveService.php`)
Service layer untuk cloud drive dengan `DriveUserContext` (session‑based role) dan `DriveStorage` (file CRUD dengan scope public/private).
---

## Keamanan
### Implementasi Aktif
1. **CSRF Protection** – Token per session, divalidasi di setiap POST via `verify_csrf()`.
2. **Session Timeout** – Auto‑expire setelah 12 jam tidak aktif.
3. **Session Enforcement** – Validasi sesi aktif dan role di `auth/auth.php`.
4. **Prepared Statements** – Semua query DB menggunakan prepared statements.
5. **Role‑based Access** – Kontrol akses drive & fitur admin berdasarkan role.
6. **Activity Logging** – Tracking IP, user‑agent, dan aktivitas di `modules/activity_logger.php`.
7. **Ban System** – Blokir IP tertentu melalui activity logger.

### Rekomendasi Production
- Simpan kredensial DB di environment variable, bukan hard‑coded.
- Set `display_errors = Off` pada `php.ini`.
- Aktifkan HTTPS + `Secure` dan `HttpOnly` cookie flags.
- Audit semua path hard‑coded sebelum deploy ke server lain.
- Batasi akses langsung ke folder `modules/`, `partials/`, dan `auth/` via `.htaccess`.

---

## Troubleshooting
### Storage Offline → Redirect ke Maintenance
```bash
# Cek mount point media aktif
df -h
# Cek path di modules/helpers.php
grep 'hdd_check_path' modules/helpers.php
# Pastikan permission folder
ls -la /media/muhammaddaffa/MEeL/media
```
### Session Expired → User di‑redirect ke Login
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
- FFmpeg lebih stabil untuk transcoding video/audio.
- Permission file (`chmod`/`chown`) lebih mudah dikontrol.
- Case sensitivity path konsisten dengan lingkungan production.
- LAMPP/XAMPP tersedia untuk Linux sebagai web server development.
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
*en: Domains sometimes don't work because they are running on developer devices.
*id: Domain terkadang tidak berfungsi karena berjalan di perangkat developer
</div>
