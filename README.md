# MEeL - Media Hub Platform

Aplikasi media hub modern yang dibangun dengan PHP dan MySQL untuk mengelola koleksi musik, video, buku, dan file pribadi dengan fitur berbagi dan manajemen pengguna yang lengkap.

## 📋 Daftar Isi
- [Fitur Utama](#fitur-utama)
- [Struktur Proyek](#struktur-proyek)
- [Persyaratan Sistem](#persyaratan-sistem)
- [Instalasi](#instalasi)
- [Konfigurasi](#konfigurasi)
- [Penggunaan](#penggunaan)
- [Arsitektur Aplikasi](#arsitektur-aplikasi)
- [Keamanan](#keamanan)
- [API dan Fungsi Utama](#api-dan-fungsi-utama)

---

## ✨ Fitur Utama

### Modul Utama
1. **Media Hub**
   - Agregasi konten musik, video, dan buku
   - Dashboard dengan statistik konten
   - Tampilan grid responsif

2. **Manajemen Video**
   - Upload video dengan dukungan berbagai format
   - Video transcoding otomatis
   - Pencarian dan sorting
   - Rekomendasi konten berdasarkan user
   - Streaming media

3. **Manajemen Musik**
   - Upload audio dengan metadata
   - Dukungan playlist
   - Sorting berdasarkan artis dan format
   - Visualisasi musik

4. **Manajemen Buku**
   - Upload dan manajemen e-book
   - Sistem kategorisasi
   - Pembacaan online

5. **Media Drive (Penyimpanan Cloud)**
   - Upload dan download file
   - Manajemen folder/file pribadi
   - File sharing dengan pengguna lain
   - Quota management per user

6. **Sistem Pengguna**
   - Registrasi dan login
   - Profil pengguna yang dapat diedit
   - Role-based access control (User & Admin)
   - Activity logging untuk admin
   - Session management dengan timeout 12 jam

7. **Fitur Sosial**
   - Like/unlike konten
   - Sistem komentar
   - Rekomendasi konten
   - Playlist kolaboratif

---

## 📁 Struktur Proyek

```
MEeL/
├── auth/                    # Autentikasi dan konfigurasi
│   ├── config.php          # Konfigurasi database & session
│   ├── auth.php            # Middleware autentikasi
│   ├── login.php           # Form login
│   ├── logout.php          # Fungsi logout
│   ├── register.php        # Registrasi pengguna baru
│   ├── MediaLibrary.php    # Kelas utilitas untuk media
│   ├── MediaViewer.php     # Kelas viewer media
│   ├── Transcoder.php      # Video/audio transcoding
│   ├── Uploader.php        # File upload handler
│   └── activity_logger.php # Logging aktivitas user
│
├── assets/                  # File statis (CSS, JS, images)
│   ├── css/
│   │   ├── styles.css      # Stylesheet utama
│   │   ├── tailwind.css    # Tailwind CSS compiled
│   │   ├── music.css       # Styling musik
│   │   ├── video.css       # Styling video
│   │   ├── drive.css       # Styling drive
│   │   ├── overlay.css     # Modal & overlay styles
│   │   └── plyr.css        # Player styles
│   ├── js/
│   │   ├── script.js       # Script utama
│   │   ├── tailwind.js     # Tailwind runtime
│   │   ├── htmx.js         # HTMX library
│   │   ├── lucide.js       # Icon library
│   │   ├── hls.js          # HLS streaming
│   │   ├── plyr.js         # Media player
│   │   ├── overlay.js      # Modal handling
│   │   └── script/
│   │       └── drive.php   # Drive utility scripts
│   └── img/               # Gambar dan aset media
│
├── video/                   # Modul video
│   ├── index.php           # Halaman utama video
│   ├── watch.php           # Page pemutaran video
│   ├── upload.php          # Form upload video
│   ├── search_video.php    # Pencarian video
│   ├── load_more.php       # Infinite scroll
│   ├── video_card.php      # Komponen kartu video
│   └── script/
│       └── js.php          # Dynamic JS untuk video
│
├── music/                   # Modul musik
│   ├── index.php           # Halaman utama musik
│   ├── watch.php           # Page pemutar musik
│   ├── upload.php          # Form upload musik
│   ├── upload/             # Direktori upload musik
│   ├── search_music.php    # Pencarian musik
│   ├── load_more_music.php # Infinite scroll musik
│   ├── music_item.php      # Komponen item musik
│   ├── playlist_action.php # Manajemen playlist
│   ├── view_playlist.php   # Tampilkan playlist
│   └── script/
│       └── js.php          # Dynamic JS untuk musik
│
├── books/                   # Modul buku
│   ├── index.php           # Halaman utama buku
│   ├── read.php            # Reader buku
│   ├── upload.php          # Upload buku
│   └── upload/             # Direktori upload buku
│
├── drive/                   # Modul cloud storage
│   ├── index.php           # Drive explorer
│   ├── upload.php          # Upload file
│   ├── download.php        # Download file
│   └── delete.php          # Delete file
│
├── profile/                 # Profil pengguna
│   ├── index.php           # Halaman profil
│   └── upload/             # Avatar & file profil
│
├── partials/                # Komponen UI reusable
│   ├── navbar.php          # Navigation bar
│   ├── nav.php             # Navigation
│   ├── link.php            # Link component
│   └── ui.php              # UI utilities
│
├── data_drive/              # Storage data
│   ├── public/             # File publik
│   └── private_admins/     # File private per user
│
├── temp/                    # File temporary
├── err/                     # Error pages
│   ├── denied.php          # 403 Forbidden
│   └── maintance.php       # Maintenance/HDD offline
│
├── index.php               # Homepage/hub utama
├── about.html              # About page
├── introduction.php        # Intro page
├── helpers.php             # Utility functions
├── cookies.php             # Cookie management
├── like.php                # API like/unlike
├── delete_comment.php      # Delete komentar
├── post_encode.php         # Post encoding
├── profile_edit.php        # Edit profil user
├── proses_sidebar.php      # Sidebar processing
├── proses_update.php       # Update processing
├── transcode.php           # Transcoding handler
├── update.php              # Update handler
├── upload_advanced.php     # Advanced upload
├── system_check.php        # System health check
├── fun.php                 # Fun features
└── .htaccess               # Apache configuration
```

---

## 🔧 Persyaratan Sistem

### Minimum Requirements
- **PHP**: 7.4+
- **MySQL**: 5.7+ atau MariaDB 10.2+
- **Web Server**: Apache (dengan mod_rewrite)
- **Storage**: External HDD/SSD (opsional, untuk media storage)

### Modul PHP yang Diperlukan
- MySQLi
- PDO
- GD Library (image processing)
- FFmpeg (untuk video transcoding)
- File system functions

### Perangkat Lunak Tambahan
- **FFmpeg**: Untuk transcoding video dan audio
  ```bash
  sudo apt-get install ffmpeg
  ```
- **yt-dlp**: Untuk download video dan music dari luar langsung kedalam MEeL
  ```bash
  sudo apt install yt-dlp
  ```

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
mkdir -p /media/muhammaddaffa/MEeL/media

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
$conn = new mysqli("localhost", "root", "", "MEeL");

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
$hdd_check_path = '/media/muhammaddaffa/MEeL/media';

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

---

## 📊 Statistik & Monitoring

### System Check
Akses `/system_check.php` untuk monitoring:
- HDD connection status
- Database connection status
- Storage usage
- Session information (admin only)

### Activity Logging
Admin dapat melihat aktivitas user di:
- `auth/activity_logger.php` - Logging & retrieval
- Dashboard dengan filter waktu

---

## 🐛 Troubleshooting

### HDD Offline
- Halaman akan redirect ke `/err/maintance.php`
- Check path di `helpers.php`
- Pastikan storage device sudah mounted

### Session Expired
- User akan di-redirect ke login page
- Timeout default: 12 jam
- Ubah di `auth/config.php` jika perlu

### Upload Failed
- Check file size limit di server configuration
- Verify write permissions di upload directories
- Check FFmpeg installation untuk transcoding

### Database Connection Error
- Verify MySQL service running
- Check credentials di `auth/config.php`
- Verify database dan tables sudah dibuat

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

License Notice: MEeL is a proprietary software. All rights are reserved by the author (Mifada). See the LICENSE file for more details.

---

## 👤 Author & Support

Dibuat untuk MEeL Media Hub Platform.

Untuk dokumentasi lebih detail, lihat komentar dalam source code yang tersedia.

---

**Last Updated**: April 2026
**Status**: Production Ready

