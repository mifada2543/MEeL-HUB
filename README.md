<div align="center">
  <img src="assets/MEeL.png" alt="MEeL Logo" width="500">
  
  # 🎬 MEeL - Media Hub Platform
  
  **Platform media cloud terpadu untuk mengelola musik, video, buku, dan file dengan mudah**
  
  [![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue?style=flat-square)](https://www.php.net/)
  [![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange?style=flat-square)](https://www.mysql.com/)
  [![License](https://img.shields.io/badge/License-Custom-green?style=flat-square)](/LICENSE)
  [![Status](https://img.shields.io/badge/Status-Production%20Ready-success?style=flat-square)](https://github.com)
  
</div>

> Aplikasi media hub modern yang dibangun dengan PHP dan MySQL untuk mengelola koleksi musik, video, buku, dan file pribadi dengan fitur berbagi dan manajemen pengguna yang lengkap. Sempurna untuk personal storage atau sharing media dalam skala kecil hingga menengah.

## 💡 Rekomendasi Sistem Operasi
> **Saran Utama:** Sangat disarankan untuk menjalankan platform ini di atas lingkungan **Linux** (seperti Ubuntu Server atau Debian). 
> 
> Mengapa Linux?
> - **FFmpeg Performance:** Proses transcoding video jauh lebih stabil dan efisien.
> - **Permission System:** Manajemen hak akses file (`chmod/chown`) yang lebih ketat dan aman untuk folder media.
> - **Case Sensitivity:** Konsistensi pemanggilan file yang sesuai dengan standar pengembangan web profesional.

## � Daftar Isi

- **[✨ Fitur Utama](#fitur-utama)** - Apa saja yang bisa dilakukan
- **[📁 Struktur Proyek](#struktur-proyek)** - Organisasi folder & file
- **[🔧 Persyaratan Sistem](#persyaratan-sistem)** - Requirements & dependencies
- **[📥 Instalasi](#instalasi)** - Setup awal
- **[⚙️ Konfigurasi](#konfigurasi)** - Configuration guide
- **[🚀 Penggunaan](#penggunaan)** - Cara menggunakan aplikasi
- **[🏗️ Arsitektur](#arsitektur-aplikasi)** - Technical architecture
- **[🔒 Keamanan](#keamanan)** - Security features & best practices
- **[🔌 API](#api-dan-fungsi-utama)** - Available APIs & functions
- **[📝 License](#lisensi)** - Informasi lisensi

---

## ✨🎯 Modul Unggulan

| Modul | Deskripsi | Fitur |
|-------|-----------|-------|
| 🎬 **Video** | Streaming video berkualitas tinggi | Upload, transcoding, search, rekomendasi, streaming |
| 🎵 **Musik** | Music library dengan playlist support | Upload, playlist, sorting by artist, visualisasi |
| 📚 **Buku** | E-book management dan reader | Upload, kategorisasi, pembacaan online |
| ☁️ **Cloud Drive** | Personal cloud storage | Upload/download, sharing, quota management |
| 👤 **User System** | Authentication & profiling | Login, role-based access, activity logging |
| ❤️ **Social** | Community features | Like, comment, recommendations, playlists |

### 🌟 Highlight Features

✅ **Media Hub Dashboard** - Agregasi semua konten dalam satu dashboard  
✅ **Auto-Transcoding** - Video/audio otomatis dikonversi ke format optimal  
✅ **Role-Based Access** - Admin & member dengan permission berbeda  
✅ **Cloud Storage** - 20GB quota per member dengan management system  
✅ **Smart Search** - Pencarian cepat dengan ranking berdasarkan relevansi  
✅ **Responsive UI** - Design modern yang mobile-friendly  
✅ **Session Management** - Timeout 12 jam dengan security features  
✅ **Activity Logging** - Track semua aktivitas user (admin only)
   - Rekomendasi konten
   - Playlist kolaboratif

---

## 📁 Struktur Proyek

```
MEeL/
│
├── 🔐 auth/                     # Authentication & Configuration
│   ├── config.php              # 📌 Database & session setup
│   ├── auth.php                # 🛡️ Middleware proteksi halaman
│   ├── login.php               # 📝 Login form & processing
│   ├── register.php            # ✍️ User registration
│   ├── MediaLibrary.php        # 📚 Media utility class
│   ├── MediaViewer.php         # 👁️ Media viewer class
│   ├── Transcoder.php          # 🎞️ Video/audio transcoding
│   ├── Uploader.php            # 📤 File upload handler
│   └── activity_logger.php     # 📊 User activity tracking
│
├── 🎨 assets/                   # Static files (CSS, JS, images)
│   ├── css/
│   │   ├── styles.css          # 🎯 Main stylesheet
│   │   ├── tailwind.css        # 🎨 Tailwind CSS compiled
│   │   ├── music.css / video.css / drive.css
│   │   └── overlay.css / plyr.css
│   ├── js/
│   │   ├── script.js           # 🔧 Main frontend logic
│   │   ├── tailwind.js / htmx.js / lucide.js
│   │   ├── hls.js / plyr.js / overlay.js
│   │   └── script/ (utility scripts)
│   └── img/                    # 🖼️ Images & media assets
│
├── 🎬 video/                    # Video module
│   ├── index.php               # 📺 Video hub
│   ├── watch.php               # ▶️ Video player page
│   ├── upload.php              # 📤 Upload interface
│   └── search_video.php        # 🔍 Search functionality
│
├── 🎵 music/                    # Music module
│   ├── index.php               # 🎧 Music hub
│   ├── watch.php               # ▶️ Music player
│   ├── upload.php              # 📤 Upload interface
│   ├── playlist_action.php     # 📋 Playlist management
│   └── search_music.php        # 🔍 Search functionality
│
├── 📚 books/                    # E-book module
│   ├── index.php               # 📖 Book library
│   ├── read.php                # 📖 E-book reader
│   └── upload.php              # 📤 Upload interface
│
├── ☁️ drive/                    # Cloud storage (REFACTORED v2.0)
│   ├── index.php               # 💾 Drive explorer
│   ├── api.php                 # 🔌 RESTful API endpoints
│   ├── DriveManager.php        # 🏛️ Business logic class
│   └── README.md               # 📖 API documentation
│
├── 👤 profile/                  # User profiles
│   ├── index.php               # 👤 Profile page
│   └── upload/                 # Avatar storage
│
├── 🧩 partials/                 # Reusable UI components
│   ├── navbar.php              # 🧭 Navigation bar
│   ├── nav.php / link.php
│   └── ui.php
│
├── 💾 data_drive/               # Storage directories
│   ├── public/                 # 🌐 Shared files
│   └── private_admins/         # 🔒 User private storage
│
├── ⚙️ Core files
│   ├── index.php               # 🏠 Homepage & hub
│   ├── helpers.php             # 🔧 Utility functions
│   ├── auth.php                # 🔐 Authentication
│   └── system_check.php        # 🏥 Health check
│
├── 🚨 err/                      # Error pages
│   ├── denied.php              # ❌ 403 Forbidden
│   └── maintance.php           # 🔧 Maintenance
│
└── 📋 Configuration
    ├── .htaccess               # 🔗 Apache routing
    ├── .gitignore              # 🚫 Git exclusions
    └── README.md               # 📖 This file
```

---

## 🔧 Persyaratan Sistem

### ⚙️ Minimum Requirements

| Komponen | Versi | Status |
|----------|-------|--------|
| **PHP** | 7.4+ | ✅ Wajib |
| **MySQL** | 5.7+ / MariaDB 10.2+ | ✅ Wajib |
| **Apache** | 2.4+ | ✅ Wajib |
| **RAM** | 512MB+ | ✅ Rekomendasi |
| **Storage** | 1GB+ | ✅ Minimum |

### 📦 PHP Extensions

```
✅ MySQLi           (Database connection)
✅ PDO              (Data abstraction)
✅ GD Library       (Image processing)
✅ FileInfo         (MIME type detection)
✅ Session          (User sessions)
✅ JSON             (API responses)
```

### 🛠️ Software Dependencies

```bash
# FFmpeg - Video/Audio transcoding (wajib)
sudo apt-get install ffmpeg

# yt-dlp - Download video/music dari internet (Pendukung)
sudo apt install yt-dlp

```

### 🌐 Browser Compatibility

| Browser | Support |
|---------|---------|
| Chrome 90+ | ✅ Full support |
| Firefox 88+ | ✅ Full support |
| Safari 14+ | ✅ Full support |
| Edge 90+ | ✅ Full support |
| IE 11 | ❌ Not supported |

---

## 📥 Instalasi

### 1. Persiapan Database
```sql
-- Buat database MEeL
CREATE DATABASE MEeL;
USE MEeL;

-- Import database schema (sesuaikan dengan file SQL di proyek)
-- source path/to/database/schema.sql;
```

### 2. Konfigurasi Awal

Edit file `auth/config.php`:
```php
$conn = new mysqli("localhost", "root", "", "MEeL");
```

Sesuaikan dengan kredensial database Anda.

### 3. Struktur Direktori Media

Buat direktori untuk menyimpan file media:
```bash
# External storage path (di helpers.php)
mkdir -p #Dibuat ke path ke penyimpanan anda

# Atau setup di internal storage
mkdir -p data_drive/public
mkdir -p data_drive/private_admins
mkdir -p temp
mkdir -p video/upload
mkdir -p music/upload
mkdir -p books/upload
mkdir -p profile/upload

# Set permissions
chmod -R 755 data_drive temp video music books profile
```

### 4. Konfigurasi Apache

Pastikan file `.htaccess` sudah tersedia dan `mod_rewrite` aktif:
```bash
a2enmod rewrite
systemctl restart apache2
```

### 5. Setup Sessions dan Cookies

MEeL menggunakan session name `meel` dengan timeout 12 jam. Direktori session default sudah terconfigurasi di `auth/config.php`.

---

## ⚙️ Konfigurasi

### Environment & Database
File: `auth/config.php`

```php
// Database credentials
$conn = new mysqli("$host", "$usr", "$pass", "$db");

// Session timeout: 12 jam (43200 detik)
$timeout = 43200;

// Session security
session_set_cookie_params($timeout, "/");
session_name('meel');
```

### Direktori Media
File: `helpers.php`

```php
// Path untuk penyimpanan media
$hdd_check_path = '$path_media';

// Jika HDD tidak terdeteksi, halaman akan redirect ke maintenance
```

### CSRF Protection
Token CSRF otomatis dihasilkan dan disimpan dalam session untuk semua POST request.

---

## 🚀 Penggunaan

### Akses Aplikasi
```
http://localhost/MEeL/
```

### User Roles

1. **Regular User**
   - Upload media (video, musik, buku)
   - Akses cloud drive pribadi
   - Like/comment pada konten
   - Lihat profil dan aktivitas

2. **Admin**
   - Akses seluruh fitur user
   - Activity logging dan monitoring
   - Manajemen pengguna
   - System health check

### Fitur Utama

#### Upload Media
- Navigasi ke modul yang sesuai (Video, Music, Books)
- Klik tombol "Upload"
- Pilih file dan isi metadata
- Sistem akan melakukan transcoding jika perlu
- Media tersedia di dashboard setelah selesai

#### Pencarian
Gunakan fitur search di setiap modul untuk mencari media berdasarkan:
- Judul/nama file
- Artis/creator
- Metadata tambahan

#### Cloud Drive
- Akses `/drive/` untuk file management pribadi
- Upload file dengan drag-drop
- Buat folder baru
- Share file dengan user lain

#### Playlist & Favorit
- Buat playlist musik custom
- Like media favorit
- Akses rekomendasi berdasarkan preference

---

## 🏗️ Arsitektur Aplikasi

### Authentication Flow
```
1. User login → auth/login.php
2. Verifikasi credentials di database
3. Generate session & CSRF token
4. Set cookie dengan session name 'meel'
5. Redirect ke homepage atau previous page
6. Session timeout: 12 jam atau logout manual
```

### Authorization & Session
- **File**: `auth/auth.php` - Middleware proteksi halaman
- **Check**: Verifikasi `$_SESSION['user_id']`
- **Multi-tab**: Last session ID tracking untuk single-session enforcement
- **Admin Check**: `$_SESSION['role'] === 'admin'`

### Media Processing Pipeline
```
User Upload → Uploader.php 
    → File validation
    → Store to storage location
    → Transcoder.php (if video/audio)
    → Generate thumbnails
    → Update database
    → Media ready
```

### Database Schema (Umum)

#### Users Table
```
- id (PRIMARY KEY)
- username (UNIQUE)
- email (UNIQUE)
- password (hashed)
- role (user/admin)
- last_session_id
- last_activity
- created_at
```

#### Media Tables (video, music, books)
```
- id (PRIMARY KEY)
- title
- user_id (FOREIGN KEY)
- file_path
- metadata (search_metadata)
- upload_date
- views/likes
- thumbnail_path
```

#### Comments Table
```
- id (PRIMARY KEY)
- media_id
- user_id
- comment_text
- created_at
```

#### Likes Table
```
- id (PRIMARY KEY)
- media_id
- user_id
- media_type
```

---

## 🔒 Keamanan

### Implementasi Keamanan

1. **CSRF Protection**
   - Token otomatis dihasilkan per session
   - Diverifikasi di setiap POST request
   - Token tersimpan di `$_SESSION['csrf_token']`

2. **Session Security**
   - Session name custom: `meel`
   - Timeout: 12 jam (43200 detik)
   - Last session ID tracking untuk prevent session hijacking
   - Automatic logout saat session expired

3. **Database Security**
   - Prepared statements untuk semua query
   - Parameter binding untuk prevent SQL injection
   - Password hashing (recommended: bcrypt/argon2)

4. **Authentication**
   - Login middleware di `auth/auth.php`
   - Role-based access control
   - Redirect ke login jika belum authenticated

5. **File Upload Security**
   - File validation di upload handler
   - Unique filename generation
   - Isolated upload directories
   - MIME type checking

### Best Practices untuk Production

1. **Environment Variables**
   ```php
   // Gunakan env file untuk sensitive data
   $db_host = getenv('DB_HOST') ?: 'localhost';
   $db_password = getenv('DB_PASSWORD') ?: '';
   ```

2. **Error Handling**
   - Hide detailed error messages di production
   - Log errors secara internal
   - User-friendly error pages

3. **HTTPS**
   - Force HTTPS di production
   - Update session cookie settings

4. **Rate Limiting**
   - Implementasi rate limiting di upload & login

---

## 🔌 API dan Fungsi Utama

### MediaLibrary Class
File: `auth/MediaLibrary.php`

```php
$library = new MediaLibrary($conn);

// Get media counts
$counts = $library->getCounts(); 
// Returns: ['music' => int, 'video' => int, 'books' => int]

// Get videos with pagination
$videos = $library->getVideos(limit: 8, offset: 0);

// Search video
$results = $library->searchVideo(query: 'keyword', exclude: 0);

// Get music list
$music = $library->getMusicList(format: 'all', artist: 'all', limit: 10, offset: 0);

// Get books
$books = $library->getBooks(limit: 10, offset: 0);
```

### Helper Functions
File: `helpers.php`

```php
// Format timestamp menjadi "X waktu lalu"
time_ago($timestamp); // Returns: "2 jam yang lalu"

// Format bytes ke human-readable
format_bytes($bytes); // Returns: "2.5 MB"

// Get user storage usage
get_user_usage($username); // Returns: bytes used
```

### Utility Functions

```php
// CSRF Token verification
verify_csrf(); // Returns: boolean

// Activity logging
log_activity($user_id, $action, $details);

// File validation
validate_upload_file($file);
```
❌ HDD/Storage Offline
**Problem**: Halaman redirect ke maintenance  
**Solution**:
1. Cek path storage di `helpers.php`
2. Verify storage device sudah mounted: `df -h`
3. Check permissions: `chmod -R 755 data_drive`

### ⏱️ Session Expired
**Problem**: User di-redirect ke login tanpa warning  
**Solution**:
1. Default timeout: 12 jam (43200 detik)
2. Edit di `auth/config.php`: `$timeout = 43200`
3. Clear browser cache jika perlu

### 📤 Upload Failed
**Problem**: File gagal diupload  
**Solution**:
```bash
# Check file size limits
php -r "echo ini_get('upload_max_filesize');"

# Check folder permissions
ls -ld /opt/lampp/htdocs/MEeL/data_drive

# Verify FFmpeg installed
which ffmpeg
```

### 🔴 Database Connection Error
**Problem**: "Koneksi gagal: (error message)"  
**Solution**:
```bash
# Check MySQL running
sudo systemctl status mysql

# Verify credentials in auth/config.php
# Test connection
mysql -u root -p MEeL -e "SELECT 1;"
```

### 🔐 Permission Denied Errors
**Problem**: Files dapat't be written/deleted  
**Solution**:
```bash
# Fix ownership
sudo chown -R www-data:www-data /opt/lampp/htdocs/MEeL #atau sudo chown -R daemon:daemon /opt/lampp/htdocs/MEeL

# Fix permissions
chmod -R 755 data_drive temp video music books profile
chmod 644 auth/config.php
```
## 🐛 Troubleshooting

### ❌ HDD/Storage Offline
**Problem**: Halaman redirect ke maintenance  
**Solution**:
1. Cek path storage di `helpers.php`
2. Verify storage device sudah mounted: `df -h`
3. Check permissions: `chmod -R 755 data_drive`

### ⏱️ Session Expired
**Problem**: User di-redirect ke login tanpa warning  
**Solution**:
1. Default timeout: 12 jam (43200 detik)
2. Edit di `auth/config.php`: `$timeout = 43200`
3. Clear browser cache jika perlu

### 📤 Upload Failed
**Problem**: File gagal diupload  
**Solution**:
```bash
# Check file size limits
php -r "echo ini_get('upload_max_filesize');"

# Check folder permissions
ls -ld /opt/lampp/htdocs/MEeL/data_drive

# Verify FFmpeg installed
which ffmpeg
```

### 🔴 Database Connection Error
**Problem**: "Koneksi gagal: (error message)"  
**Solution**:
```bash
# Check MySQL running
sudo systemctl status mysql

# Verify credentials in auth/config.php
# Test connection
mysql -u root -p MEeL -e "SELECT 1;"
```

### 🔐 Permission Denied Errors
**Problem**: Files dapat't be written/deleted  
**Solution**:
```bash
# Fix ownership
sudo chown -R www-data:www-data /opt/lampp/htdocs/MEeL

# Fix permissions
chmod -R 755 data_drive temp video music books profile
chmod 644 auth/config.php
```

---

## 📝 File Penting untuk Development

- `index.php` - Homepage & media hub
- `auth/config.php` - Database & session config
- `auth/auth.php` - Authentication middleware
- `auth/MediaLibrary.php` - Media utility class
- `helpers.php` - Global helper functions
- `assets/js/script.js` - Main frontend logic
- `assets/css/styles.css` - Main stylesheet

---

## 🤝 Kontribusi

Untuk pengembangan lebih lanjut:
1. Follow structure yang sudah ada
2. Gunakan prepared statements untuk database
3. Implement error handling yang proper
4. Test di staging sebelum production
5. Update dokumentasi jika menambah fitur baru

---

## 📄 Lisensi
Project ini open-source untuk dipelajari, tapi kalau mau dipublikasikan secara official atau dipakai jualan, wajib izin ya! Cek file LICENSE untuk detailnya

---

## 🤝 Support & Community

**Dokumentasi Lengkap**
- 📖 Lihat komentar inline di source code
- 🔍 Check [drive/README.md](drive/README.md) untuk API documentation
- 🛡️ Baca [SECURITY_AUDIT.md](SECURITY_AUDIT.md) untuk security details

**Issues & Questions**
- 🐛 Report bugs di GitHub Issues
- 💬 Diskusi di GitHub Discussions
- 📧 Direct contact: muhammaddaffa@meel.local

---

## 📊 Project Stats

| Metrik | Nilai |
|--------|-------|
| **Total Files** | 96+ |
| **Code Lines** | 10,000+ |
| **PHP Modules** | 8 utama |
| **Database Tables** | 8+ |
| **API Endpoints** | 20+ |
| **Last Updated** | Mei 2026 |
| **Status** | ✅ Production Ready |

---

<div align="center">

### Made with ❤️ for MEeL Media Hub Platform

**v2.0 - Refactored & Optimized**

![PHP](https://img.shields.io/badge/Made%20with-PHP-777bb4?style=for-the-badge&logo=php)
![MySQL](https://img.shields.io/badge/Database-MySQL-00758f?style=for-the-badge&logo=mysql)
![Bootstrap](https://img.shields.io/badge/UI-Tailwind%20CSS-38b2ac?style=for-the-badge&logo=tailwind-css)

</div>


<div align="center">

### 🔐 Custom License

```
✅ Boleh digunakan untuk:
  - Pembelajaran & penelitian personal
  - Pengembangan & modifikasi pribadi
  - Deployment internal/pribadi

⚠️ Memerlukan izin untuk:
  - Publikasi resmi atau publikasi ulang
  - Penggunaan komersial/penjualan
  - Redistribusi ke publik

📧 Untuk izin, hubungi: muhammaddaffa@meel.local
```

**Baca file [LICENSE](LICENSE) untuk detail lengkapnya**

</div>