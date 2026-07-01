# ⚙️ Konfigurasi MEeL

Panduan referensi untuk semua file konfigurasi dan parameter di MEeL-HUB.

---

## 📋 Daftar Isi

- [File Konfigurasi Utama](#file-konfigurasi-utama)
- [Database (`auth/config.php`)](#database-authconfigphp)
- [Session & Security](#session--security)
- [Media Storage Paths](#media-storage-paths)
- [Transcoder Configuration](#transcoder-configuration)
- [Uploader Configuration](#uploader-configuration)
- [System Configuration](#system-configuration)
- [Rate Limiting](#rate-limiting)

---

## File Konfigurasi Utama

| File | Tujuan | Variabel Kunci |
|------|--------|----------------|
| `auth/config.php` | Database, session, CSRF | `$server`, `$username`, `$password`, `$db` |
| `modules/Transcoder.php` | FFmpeg, yt-dlp, HDD paths | `HDD_BASE`, `FFMPEG_THREADS` |
| `modules/Uploader.php` | Upload paths, FFmpeg | `$base_dir`, `$ffmpeg_bin` |
| `modules/helpers.php` | HDD check path | `$hdd_check_path` |
| `modules/System.php` | Queue management | Rate limit constants |

---

## Database (`auth/config.php`)

### Koneksi Database

```php
// File: auth/config.php

$server   = "localhost";   // Host database
$username = "root";        // Username database
$password = "";            // Password database
$db       = "MEeL";        // Nama database

$conn = new mysqli($server, $username, $password, $db);
```

### Error Handling

Jika kredensial database kosong, sistem akan menampilkan pesan error edukatif:

```
[MEeL SYSTEM ERROR]
Wah, tampaknya kamu terlalu terburu-buru!
Kamu belum mengisi konfigurasi database di file 'auth/config.php'.
```

---

## Session & Security

### Session Configuration

```php
// Di auth/config.php
$timeout = 43200;     // 12 jam session timeout
ini_set('session.gc_maxlifetime', $timeout);
session_set_cookie_params($timeout, "/");
session_name('meel');  // Session cookie name: "meel"
session_start();
```

### CSRF Protection

```php
// Auto-generated token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Verifikasi function
function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || 
            !isset($_SESSION['csrf_token']) || 
            $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            return false;
        }
    }
    return true;
}
```

### Session Timeout Check

```php
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];
    if ($elapsed_time > $timeout) {  // 12 jam
        session_unset();
        session_destroy();
        header("Location: ../auth/login.php?reason=expired");
        exit;
    }
}
$_SESSION['LAST_ACTIVITY'] = time();
```

### Transliterator (Romaji Conversion)

```php
function getRomajiName($text) {
    $rule = "Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII; Any-Lower;";
    $transliterator = Transliterator::create($rule);
    $text = $transliterator->transliterate($text);
    $clean = preg_replace('/[^a-z0-9\-]/u', '-', $text);
    $clean = preg_replace('/-+/', '-', trim($clean, '-'));
    return $clean ?: 'untitled-media';
}
```

> ⚠️ Fungsi ini membutuhkan ekstensi PHP `intl`.

---

## Media Storage Paths

### Base Path di Uploader

```php
// File: modules/Uploader.php
$this->base_dir = "/media/muhammaddaffa/MEeL/media/video/upload/";
```

### Base Path di Transcoder

```php
// File: modules/Transcoder.php
private const HDD_BASE      = "/media/muhammaddaffa/MEeL/media/video/upload/";
private const HDD_VIDEO_DIR = self::HDD_BASE . "video/";
private const HDD_THUMB_DIR = self::HDD_BASE . "thumbnail/";
```

### HDD Check Path

```php
// File: modules/helpers.php
$hdd_check_path = '/media/muhammaddaffa/MEeL/media';
```

> ⚠️ **Penting:** Semua path di atas HARUS disesuaikan dengan konfigurasi mount HDD server Anda. Jika path tidak cocok, aplikasi akan redirect ke `err/maintance.php`.

### Struktur Direktori Media

```
/media/[user]/MEeL/media/
├── video/
│   ├── upload/
│   │   ├── video/
│   │   │   ├── [folder_name]/
│   │   │   │   ├── [folder_name].m3u8      # HLS playlist
│   │   │   │   ├── [folder_name]_000.ts    # HLS segments
│   │   │   │   ├── thumb_sprite.jpg        # Sprite thumbnail
│   │   │   │   └── thumbnails.vtt          # VTT timeline
│   │   └── thumbnail/
│   │       └── [video]_thumb.jpg
├── music/
│   ├── upload/
│   │   ├── file/
│   │   │   └── [song].ogg
│   │   └── thumbnail/
│   │       └── [song].thumb.webp
└── books/
    └── upload/
        ├── manga/
        ├── pdf/
        └── thumbnail/
```

---

## Transcoder Configuration

### File: `modules/Transcoder.php`

```php
// ─── KONSTANTA HARDWARE ───────────────────────────────────
private const FFMPEG_THREADS        = 8;

// Sprite thumbnail dimensions
private const SPRITE_TILE_W         = 160;
private const SPRITE_TILE_H         = 90;
private const SPRITE_COLS           = 5;

// HLS segment duration (detik)
private const HLS_SEGMENT_DURATION  = 10;

// Download timeout (detik)
private const DOWNLOAD_TIMEOUT      = 900;

// ─── PATH STORAGE ─────────────────────────────────────────
private const HDD_BASE      = "/media/muhammaddaffa/MEeL/media/video/upload/";
```

### Binary Path Resolution

```php
// Transcoder.php - Auto-detect FFmpeg path
$this->ffmpeg_bin  = $this->resolveBinary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);
$this->ffprobe_bin = $this->resolveBinary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);

// Uploader.php
$this->ffmpeg_bin  = $this->resolveBinary(['/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg', 'ffmpeg']);
$this->ffprobe_bin = $this->resolveBinary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);
```

### yt-dlp Configuration

```php
$this->base_cmd = "export PATH=/usr/local/bin:/usr/bin:/bin; "
    . " /usr/local/bin/yt-dlp --js-runtime node:/usr/bin/node"
    . " --no-warnings --restrict-filenames"
    . " --user-agent " . escapeshellarg($this->user_agent)
    . " --referer " . escapeshellarg("https://www.youtube.com/")
    . " --cookies " . escapeshellarg($this->cookies_path) . " ";
```

### Video Format Resolution

```php
// YouTube: prefer H.264 + AAC/M4A untuk stream-copy
return "bestvideo[height<=1080][vcodec^=avc1]+bestaudio[ext=m4a]/best[height<=1080][vcodec^=avc1]";

// NicoNico: format standar
return "bestvideo[height<=1080]+bestaudio/best";

// TikTok/others
return "bestvideo+bestaudio/best";

// Fallback
return "bestvideo[height<=1080]+bestaudio/best";
```

### Sprite Interval (Dinamis)

```php
if ($duration > 3600) $interval = 300;   // > 1 jam → tiap 5 menit
elseif ($duration > 1800) $interval = 180;   // > 30 menit → tiap 3 menit
elseif ($duration > 300)  $interval = 60;    // > 5 menit → tiap 1 menit
else                       $interval = 10;    // ≤ 5 menit → tiap 10 detik
```

---

## Uploader Configuration

### File: `modules/Uploader.php`

```php
$this->base_dir = "/media/muhammaddaffa/MEeL/media/video/upload/";
```

### Upload Limits

```php
// Admin: 200MB per file, 60 menit durasi
$max_size = ($this->user_role === 'admin') ? 200 * 1024 * 1024 : 50 * 1024 * 1024;
$max_dur  = ($this->user_role === 'admin') ? 3600 : 300; // 300 detik = 5 menit
```

### Allowed File Formats

```php
// Video
$allowed_ext = ['mp4', 'webm', 'mkv'];

// Music
$allowed_ext = ['mp3', 'opus', 'ogg', 'm4a', 'wav', 'flac'];
```

---

## System Configuration

### File: `modules/System.php`

```php
// Queue Processing
// Maksimal 2 proses simultan (download + transcode)
$active = count($this->getActiveQueues());
return $active >= 2; // isServerBusy()
```

### Rate Limiting

```php
// Default: 2 upload per jam (non-admin)
$max_upload = 2;

// Drive: 10 upload per jam
if ($type === 'drive_files') {
    $max_upload = 10;
}

// Admin: unlimited
if ($user_role === 'admin') return ['allowed' => true];
```

---

## Cookies & yt-dlp Authentication

File `cookies.txt` di root proyek digunakan untuk autentikasi yt-dlp:

```php
// Path: /opt/lampp/htdocs/MEeL/cookies.txt
$this->cookies_path = $this->base_path . "/cookies.txt";
```

### Cara Mendapatkan Cookies.txt

1. Install ekstensi browser "Get cookies.txt LOCALLY"
2. Login ke YouTube (atau platform lain) di browser
3. Klik ekstensi → Export cookies
4. Simpan file sebagai `cookies.txt` di root proyek

---

## Environment Variables

```php
// Di upload_advanced.php (override environment)
putenv("LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu:/usr/local/lib");
putenv("PATH=/usr/local/bin:/usr/bin:/bin");
```

```php
// Di Transcoder.php
private const ENV_PREFIX = "export LD_LIBRARY_PATH=''; "
    . "export PATH=/usr/local/bin:/usr/bin:/bin; "
    . "export LC_ALL=en_US.UTF-8; ";
```

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
