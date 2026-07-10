# 🚀 Panduan Instalasi MEeL

Panduan lengkap untuk menginstal dan menjalankan MEeL-HUB di server lokal Anda.

---

## 📋 Daftar Isi

- [Persyaratan Sistem](#persyaratan-sistem)
- [Instalasi Langkah demi Langkah](#instalasi-langkah-demi-langkah)
- [Setup Database](#setup-database)
- [Konfigurasi Aplikasi](#konfigurasi-aplikasi)
- [Direktori Runtime & Perizinan](#direktori-runtime--perizinan)
- [Konfigurasi Apache](#konfigurasi-apache)
- [Verifikasi Instalasi](#verifikasi-instalasi)
- [Setup FFmpeg & yt-dlp](#setup-ffmpeg--yt-dlp)
- [Troubleshooting Instalasi](#troubleshooting-instalasi)

---

## Persyaratan Sistem

### Minimum Requirements

| Komponen | Versi | Keterangan |
|----------|-------|------------|
| **PHP** | 8.0+ | **8.1+** sangat disarankan |
| **MySQL** | 5.7+ / MariaDB 10.2+ | Encoding `utf8mb4` |
| **Apache** | 2.4+ | Wajib `mod_rewrite` |
| **FFmpeg** | 6.0+ | Untuk HLS & transcoding |
| **FFprobe** | (bundled with FFmpeg) | Untuk probing media |
| **yt-dlp** | Versi terbaru | Untuk download URL |
| **RAM** | 2 GB (4 GB+) | 4 GB+ untuk transcoding |
| **Storage** | 10 GB+ | Tergantung ukuran media |

### Translator mecab
```bash
sudo apt install mecab mecab-ipadic-utf8 libmecab-dev
```

### PHP Extensions yang Diperlukan

```bash
# Pada Ubuntu/Debian
sudo apt install php8.1-mysqli php8.1-pdo-mysql php8.1-gd php8.1-fileinfo \
                 php8.1-mbstring php8.1-intl php8.1-zip php8.1-xml
```

Atau aktifkan di `php.ini`:
```ini
extension=mysqli
extension=pdo_mysql
extension=gd
extension=fileinfo
extension=json
extension=mbstring
extension=intl
extension=zip
```

> ⚠️ **Ekstensi `intl`** wajib untuk fitur transliterasi nama file (karakter Jepang/Kana → Romaji).
> ⚠️ **Ekstensi `zip`** diperlukan untuk upload manga (ZIP/CBZ).
> ⚠️ **Ekstensi `mecab`** Diperlukan untuk translate yang lebih baik

### OS yang Direkomendasikan

**Linux (Ubuntu Server / Debian)** sangat direkomendasikan. Windows memiliki keterbatasan:
- Manajemen sinyal proses FFmpeg berbeda
- Sistem permission file berbeda
- Case-sensitive path pada file PHP

---

## Instalasi Langkah demi Langkah

### 1. Install Web Server (XAMPP/LAMPP atau Manual)

**Opsi A — LAMPP (Linux):**
```bash
# Download XAMPP for Linux
wget https://www.apachefriends.org/xampp-files/8.2.12/xampp-linux-x64-8.2.12-0-installer.run
chmod +x xampp-linux-*.run
sudo ./xampp-linux-*.run
```

**Opsi B — Manual (Ubuntu/Debian):**
```bash
sudo apt update
sudo apt install apache2 mysql-server php8.1 php8.1-mysqli php8.1-gd \
                 php8.1-mbstring php8.1-intl php8.1-zip php8.1-fileinfo
```

### 2. Kloning Repositori

```bash
cd /opt/lampp/htdocs  # Untuk XAMPP/LAMPP
# atau
cd /var/www/html      # Untuk Apache manual

git clone https://github.com/mifada2543/MEeL.git MEeL
cd MEeL
```

### 3. Setup Database

#### Via MySQL CLI:
```bash
mysql -u root -p
```

```sql
CREATE DATABASE IF NOT EXISTS `MEeL` 
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
  
USE `MEeL`;

-- Buat tabel users
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `ip_address` varchar(45) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_page` varchar(255) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `bio` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT 'default_avatar.png',
  `favorite_genre` varchar(100) DEFAULT NULL,
  `custom_theme` varchar(50) DEFAULT 'default',
  `last_session_id` varchar(128) DEFAULT NULL,
  `access_via` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert admin default (password: Admin#123)
INSERT INTO `users` (`id`, `username`, `role`, `password`, `is_active`) VALUES
(1, 'Admin', 'admin', '$2y$10$e0M2Vdf9vN2V3X7g4h9uO.g4gH8Z8K5E1gX4G2Y5Z6W7V8U9T0S1S', 1);

-- Buat tabel video
CREATE TABLE IF NOT EXISTS `video` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `filename` text NOT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `duration` int(11) DEFAULT 0,
  `views` int(11) DEFAULT 0,
  `likes` int(11) DEFAULT 0,
  `dislikes` int(11) DEFAULT 0,
  `search_metadata` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `video_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel music
CREATE TABLE IF NOT EXISTS `music` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `artist` varchar(100) DEFAULT NULL,
  `album` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `filename` text NOT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `duration` int(11) DEFAULT 0,
  `views` int(11) DEFAULT 0,
  `likes` int(11) DEFAULT 0,
  `dislikes` int(11) DEFAULT 0,
  `search_metadata` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `music_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel books
CREATE TABLE IF NOT EXISTS `books` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `author` varchar(100) DEFAULT NULL,
  `type` enum('manga','pdf') NOT NULL,
  `has_chapters` tinyint(1) DEFAULT 0,
  `category` varchar(50) DEFAULT NULL,
  `path_folder` text DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `books_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel comments
CREATE TABLE IF NOT EXISTS `comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `video_id` int(11) DEFAULT NULL,
  `music_id` int(11) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `fk_parent_comment` (`parent_id`),
  CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_parent_comment` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel interactions (like/dislike)
CREATE TABLE IF NOT EXISTS `interactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `video_id` int(11) DEFAULT NULL,
  `music_id` int(11) DEFAULT NULL,
  `type` enum('like','dislike') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_interaction` (`user_id`,`video_id`,`music_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel playlists
CREATE TABLE IF NOT EXISTS `playlists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `playlists_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel playlist_tracks
CREATE TABLE IF NOT EXISTS `playlist_tracks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `playlist_id` int(11) NOT NULL,
  `music_id` int(11) NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `playlist_id` (`playlist_id`),
  KEY `music_id` (`music_id`),
  CONSTRAINT `playlist_tracks_ibfk_1` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `playlist_tracks_ibfk_2` FOREIGN KEY (`music_id`) REFERENCES `music` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel upload_queue
CREATE TABLE IF NOT EXISTS `upload_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `url` text NOT NULL,
  `media_type` varchar(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('processing','completed','failed') DEFAULT 'processing',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel transcode_queue
CREATE TABLE IF NOT EXISTS `transcode_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `status` enum('processing','completed','failed') DEFAULT 'processing',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel view_logs
CREATE TABLE IF NOT EXISTS `view_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `video_id` int(11) DEFAULT NULL,
  `music_id` int(11) DEFAULT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_view` (`user_id`,`video_id`,`music_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel ip_ban
CREATE TABLE IF NOT EXISTS `ip_ban` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `banned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel updates
CREATE TABLE IF NOT EXISTS `updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL,
  `content` text NOT NULL,
  `created_at` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel sidebar_settings
CREATE TABLE IF NOT EXISTS `sidebar_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `important_content` text DEFAULT NULL,
  `announcement_content` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel activity_log
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `media_type` varchar(20) DEFAULT NULL,
  `media_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Buat tabel drive_files
CREATE TABLE IF NOT EXISTS `drive_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_size` bigint(20) DEFAULT 0,
  `file_type` varchar(50) DEFAULT NULL,
  `scope` enum('public','private') DEFAULT 'private',
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
```

### 4. Konfigurasi Aplikasi

```bash
cd /opt/lampp/htdocs/MEeL/auth
cp config.example.php config.php
```

Edit `auth/config.php`:
```php
$server   = "localhost";
$username = "root";       // User database Anda
$password = "";           // Password database Anda
$db       = "MEeL";       // Nama database
```

### 5. Buat Direktori Runtime

```bash
cd /opt/lampp/htdocs/MEeL
mkdir -p data_drive/public data_drive/private_admins temp profile/upload music/upload/file music/upload/thumbnail books/upload/manga books/upload/pdf books/upload/thumbnail

sudo chown -R www-data:www-data data_drive temp profile/upload music/upload books/upload
sudo chmod -R 775 data_drive temp profile/upload music/upload books/upload
```

> 💡 Jika `www-data` tidak berfungsi, coba `daemon` atau `nobody`.

### 6. Konfigurasi Apache

#### Aktifkan mod_rewrite:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Pastikan AllowOverride aktif:
Edit `/etc/apache2/apache2.conf`:
```apache
<Directory /var/www/html>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

### 7. Setup FFmpeg

```bash
# Ubuntu/Debian
sudo apt install ffmpeg

# Verifikasi
ffmpeg -version
ffprobe -version

# Cek lokasi binary
which ffmpeg    # Output: /usr/bin/ffmpeg
which ffprobe   # Output: /usr/bin/ffprobe
```

### 8. Setup yt-dlp

```bash
# Install via pip
sudo apt install python3-pip
pip3 install yt-dlp

# Atau download langsung
sudo wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp
sudo chmod +x /usr/local/bin/yt-dlp

# Verifikasi
yt-dlp --version
```

### 9. Setup cookies.txt (untuk yt-dlp)

Untuk download dari YouTube dan platform lain, ekspor cookie browser:
1. Install ekstensi [Get cookies.txt LOCALLY](https://chrome.google.com/webstore/detail/get-cookiestxt-locally/cclelndahbckbenkjhflpdbgdldlbecc)
2. Login ke YouTube di browser
3. Ekspor cookies ke format Netscape
4. Simpan sebagai `cookies.txt` di root proyek:

```bash
cp /path/to/cookies.txt /opt/lampp/htdocs/MEeL/cookies.txt
```

---

## Verifikasi Instalasi

1. Nyalakan Apache & MySQL:
   ```bash
   # LAMPP
   sudo /opt/lampp/lampp start
   
   # Manual
   sudo systemctl start apache2 mysql
   ```

2. Buka browser: `http://localhost/MEeL/`

3. Login dengan:
   - **Username:** `Admin`
   - **Password:** `Admin#123`

4. Cek halaman Admin: `http://localhost/MEeL/admin/`

---

## Troubleshooting Instalasi

### ❌ "Koneksi ke database gagal"
- Pastikan MySQL/MariaDB berjalan: `sudo systemctl status mysql`
- Verifikasi kredensial di `auth/config.php`
- Coba: `mysql -u root -p -e "SHOW DATABASES;"`

### ❌ "Penyimpanan Offline" / Redirect ke maintenance
- Periksa path HDD di `auth/config.php`:
  ```php
  define('MEEL_HDD_BASE', '/media/[user]/MEeL/media');
  ```
- Sesuaikan dengan mount point Anda: `df -h` untuk cek mount point
- Atau nonaktifkan sementara untuk development

### ❌ "403 Forbidden" pada halaman
- Periksa `.htaccess` di direktori terkait
- Pastikan `AllowOverride All` di konfigurasi Apache

### ❌ "500 Internal Server Error"
- Cek error log: `sudo tail -f /var/log/apache2/error.log`
- Aktifkan error reporting di PHP: `ini_set('display_errors', 1);`

### ❌ FFmpeg/yt-dlp tidak ditemukan
- Pastikan binary terinstall: `which ffmpeg && which yt-dlp`
- Transcoder dan Uploader sudah auto-detect via `resolveBinary()`

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
