# ⚙️ MEeL Configuration

Reference guide for all configuration files and parameters in MEeL-HUB.

---

## Table of Contents

- [Main Configuration Files](#main-configuration-files)
- [Database (`auth/settings.php`)](#database-authsettingsphp)
- [Session & Security](#session--security)
- [Media Storage Paths](#media-storage-paths)
- [Transcoder Configuration](#transcoder-configuration)
- [Uploader Configuration](#uploader-configuration)
- [System Configuration](#system-configuration)
- [Cookies & yt-dlp Authentication](#cookies--yt-dlp-authentication)
- [Environment Variables](#environment-variables)
- [Rate Limiting](#rate-limiting)

---

## Main Configuration Files

| File | Purpose | Key Variables |
|---|---|---|
| `auth/config.php` | Entry point: bootstrap, session, CSRF, headers | (init logic only) |
| `auth/settings.php` | **Pure data**: DB credentials + **centralized paths** | `$server`, `$username`, `$password`, `$db`, `MEEL_HDD_*` |
| `auth/config.example.php` | Entry point template (copy to config.php) | Same as config.php |
| `auth/settings.example.php` | Config data template (copy to settings.php) | Same as settings.php |
| `database/schema.sql` | Standalone database schema | — |
| `modules/core/TranscoderBase.php` | FFmpeg, yt-dlp, CPU threads | `FFMPEG_THREADS`, `DOWNLOAD_TIMEOUT`, `TRANSCODE_AUDIO_TIMEOUT` |
| `modules/core/Uploader.php` | Upload paths, FFmpeg | `$ffmpeg_bin`, `$ffprobe_bin` |
| `modules/core/helpers.php` | **Shim** — requires `helpers/main.php` + `modules/auth/loader.php` (backward-compat) | — |
| `modules/core/helpers/*.php` | Per-domain utilities (main, storage, audio, url) | `dir_size()`, `check_disk_space()`, `get_audio_mime_type()`, `resolve_binary()`, `log_drive_operation()` |
| `modules/auth/helpers/user.php` | User & role helpers | `get_user_role()`, `get_user_usage()`, `invalidate_user_role_cache()` |
| `modules/core/System.php` | Queue management | Rate limit constants |
| `modules/core/GarbageCollector.php` | Auto-cleanup temp files + guests + chess rooms + rate limits | `STALE_SECONDS`, `GUEST_STALE_HOURS`, `ROOM_LOBBY_STALE_HOURS`, `ROOM_GAME_STALE_HOURS`, `CHESS_CLEANUP_INTERVAL` |
| `modules/auth/RateLimiter.php` | File-based API rate limiter | Per-endpoint limits (30 likes/min, 10 comments/min, etc.) |
| `modules/core/japanese.php` | Japanese text processing (MeCab + transliterator) | `getRomajiName()`, `analyzeJapaneseText()` |
| `modules/core/activity_logger.php` | Activity logging, IP banning, session kick | `get_real_ip()`, `log_activity()`, `validate_and_format_ip()` |
| `modules/core/bootstrap.php` | Bootstrap (env detection, error reporting, timezone) | `MEEL_ENV`, error log config |
| `modules/core/base_url.php` | Centralized base URL computation (`meel_base_url_path()`) | `MEEL_BASE_URL` (via `bootstrap.php`/`config.php`) |
| `modules/transcoder/FfmpegUtils.php` | **Trait** for FFmpeg utilities | `resolveBinary()`, `probeDuration()`, `generateSpriteAndVTT()` |
| `modules/autoload.php` | Class-map autoloader | List of scanned directories |
| `modules/core/SwPrecache.php` | PWA precache generator (service worker) | `baseAssets()`, `moduleAssets()`, `all()`, `version()` |
| `sw.js.php` | Dynamic service worker generator (served as `/sw.js`) | `SW_VERSION`, `PRECACHE_URLS` (auto) |
| `database/migrate.php` | Database migration v1–v15 | FULLTEXT index, FK, activity_log, UNIQUE KEY, MFA, comments indexes, interactions unique keys, chess room identity, user_notifications |

---

## Database (`auth/settings.php`)

### Database Connection

DB credentials live in **`auth/settings.php`** (pure data). `auth/config.php` requires it and creates the connection:

```php
// File: auth/settings.php — can be copied from settings.example.php

$server   = "localhost";   // Database host
$username = "root";        // Database username
$password = "";            // Database password
$db       = "MEeL";        // Database name

// auth/config.php — entry point creates the connection:
$conn = new mysqli($server, $username, $password, $db);
$conn->set_charset('utf8mb4'); // charset forced to utf8mb4
```

### Error Handling

If database credentials are empty, the system displays an educational error message.

---

## Session & Security

### Session Configuration

```php
// In auth/config.php
$timeout = 43200;     // 12 hour session timeout
ini_set('session.gc_maxlifetime', $timeout);

// Secure cookie flags (auto-detect HTTPS / X-Forwarded-Proto)
$secure_cookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

session_set_cookie_params([
    'lifetime' => $timeout,
    'path'     => '/',
    'secure'   => $secure_cookie,  // only sent via HTTPS
    'httponly' => true,            // not readable by JavaScript
    'samesite' => 'Lax',           // CSRF level-1 protection
]);
session_name('meel');  // Session cookie name: "meel"
session_start();
```

> `auth/auth_helpers.php` (`auth_boot_session()`) uses the same cookie parameters.

### Trusted Proxy (`MEEL_TRUST_PROXY_HEADERS`)

**File:** `auth/settings.example.php` (and `auth/settings.php`)

```php
// false = (default, safe) use only REMOTE_ADDR
// true  = trust proxy headers (only if behind a trusted proxy)
define('MEEL_TRUST_PROXY_HEADERS', false);
```

Header `HTTP_X_FORWARDED_FOR` / `HTTP_CF_CONNECTING_IP` should only be trusted if the request actually goes through a proxy/CDN you control (Cloudflare, Nginx reverse proxy). If set to `true` while the server is accessed directly, an attacker can spoof IPs to bypass IP bans or flood the activity log.

### Connection Charset (`utf8mb4`)

```php
// auth/config.php & auth/config.example.php
$conn->set_charset('utf8mb4');
```

MySQL connection is forced to `utf8mb4` to match the schema — emoji, Japanese scripts, and multibyte text store/retrieve correctly.

### CSRF Protection

```php
// Auto-generated token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Verification function (defined in modules/auth/helpers/csrf.php)
// Uses hash_equals() for timing-attack safety
function verify_csrf_token(?string $token = null): bool
{
    if ($token === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}
```

### Session Timeout Check

```php
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];
    if ($elapsed_time > $timeout) {  // 12 hours
        session_unset();
        session_destroy();
        header("Location: ../auth/login?reason=expired");
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

> ⚠️ This function requires the PHP `intl` extension.

---

## Media Storage Paths (CENTRALIZED)

All media storage paths are **centralized** in `auth/settings.php` through `MEEL_HDD_*` constants. Change just **one line** to relocate storage.

### Main Path Configuration

```php
// File: auth/settings.php — ★ Just change MEEL_HDD_BASE, everything else follows
define('MEEL_HDD_BASE', '/media/[user]/MEeL/media');

// Derived paths (automatically follow MEEL_HDD_BASE)
define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');
```

### How to Change

1. Determine your HDD mount path: `df -h` or `lsblk`
2. Edit `auth/settings.php`:
   ```php
   define('MEEL_HDD_BASE', '/media/[username]/MEeL/media');
   ```
3. Done! All modules (video, music, books, drive) automatically use the new path.

### X-Sendfile Configuration (Streaming Acceleration)

> Available in: `auth/settings.php`

```php
define('MEEL_USE_XSENDFILE', false);
```

X-Sendfile speeds up streaming of large files like FLAC (33MB+) by letting Apache serve files directly from disk (zero-copy), without PHP reading the file at all.

**Performance impact based on testing (FLAC 33MB):**

| Metric | Without X-Sendfile (PHP chunking) | With X-Sendfile |
|---|---|---|
| Full file 33MB | 0.020 seconds | ~0.010 seconds (2× faster) |
| Range request 256KB | 0.011 seconds | ~0.003 seconds (3× faster) |
| Server RAM per request | ~33MB | 0 bytes |
| PHP process blocking | Yes, until stream finishes | No, exits immediately |

**Activation:** See the guide in [`installation.md`](installation.md) section "Enable mod_xsendfile".

### Examples for Different Scenarios

| Scenario | `MEEL_HDD_BASE` Value |
|---|---|
| External HDD | `/media/username/MEeL/media` |
| Local SSD | `/var/www/meel-storage/media` |
| Development (fallback) | `__DIR__ . '/../storage/media'` |
| Docker volume | `/data/media` |

### ⚠️ Important

If `MEEL_HDD_BASE` doesn't match the mount point, the maintenance page `err/?code=maintance` (HTTP 503) may be displayed.

### Storage Directory Structure

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

### File: `modules/core/TranscoderBase.php`

> ⚠️ **Change:** Transcoding constants now live in `TranscoderBase` as `protected const`
> (inherited by `EncodeService`, `DownloadService`, `TranscodeService` — no longer `private`
> in `Transcoder.php`, since child services must access them).

```php
// ─── HARDWARE CONSTANTS (modules/core/TranscoderBase.php) ──
protected const FFMPEG_THREADS        = 8;

// HLS segment duration (seconds)
protected const HLS_SEGMENT_DURATION  = 10;

// Download timeout (seconds)
protected const DOWNLOAD_TIMEOUT      = 900;

// Audio transcode timeout (seconds)
protected const TRANSCODE_AUDIO_TIMEOUT = 600;
```

> ⚠️ **Change:** Sprite constants `SPRITE_TILE_W/H/COLS` have been **removed**.
> Sprite dimensions (160×90, 5 columns) are now hardcoded in `modules/transcoder/FfmpegUtils.php`
> (`generateSpriteAndVTT()`: `$w = 160; $h = 90; $cols = 5;`) together with the dynamic interval.

### Binary Path Resolution

```php
// Trait modules/transcoder/FfmpegUtils.php - Auto-detect FFmpeg path (resolveBinary)
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
// YouTube: prefer H.264 + AAC/M4A for stream-copy
return "bestvideo[height<=1080][vcodec^=avc1]+bestaudio[ext=m4a]/best[height<=1080][vcodec^=avc1]";

// NicoNico: standard format
return "bestvideo[height<=1080]+bestaudio/best";

// TikTok/others
return "bestvideo+bestaudio/best";

// Fallback
return "bestvideo[height<=1080]+bestaudio/best";
```

### Sprite Interval (Dynamic)

```php
if ($duration > 3600) $interval = 300;   // > 1 hour → every 5 minutes
elseif ($duration > 1800) $interval = 180;   // > 30 min → every 3 minutes
elseif ($duration > 300)  $interval = 60;    // > 5 min → every 1 minute
else                       $interval = 10;    // ≤ 5 min → every 10 seconds
```

---

## Uploader Configuration

### File: `modules/core/Uploader.php`

### Upload Limits

```php
// Admin: 200MB per file, 60 minute duration
$max_size = ($this->user_role === 'admin') ? 200 * 1024 * 1024 : 50 * 1024 * 1024;
$max_dur  = ($this->user_role === 'admin') ? 3600 : 300; // 300 seconds = 5 minutes
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

### File: `modules/core/System.php`

```php
// Queue Processing
// Maximum 2 simultaneous processes (download + transcode)
$active = count($this->getActiveQueues());
return $active >= 2; // isServerBusy()
```

---

## Cookies & yt-dlp Authentication

The `cookies.txt` file in the project root is used for yt-dlp authentication:

```php
// Path: /opt/lampp/htdocs/MEeL/cookies.txt
$this->cookies_path = $this->base_path . "/cookies.txt";
```

### How to Get cookies.txt

1. Install browser extension "Get cookies.txt LOCALLY"
2. Log in to YouTube (or other platform) in your browser
3. Click the extension → Export cookies
4. Save the file as `cookies.txt` in the project root

---

## Environment Variables

```php
// In upload_advanced.php (override environment)
putenv("LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu:/usr/local/lib");
putenv("PATH=/usr/local/bin:/usr/bin:/bin");
```

```php
// In modules/core/TranscoderBase.php (used by child services EncodeService/DownloadService/TranscodeService)
protected const ENV_PREFIX = "export LD_LIBRARY_PATH='/usr/lib/x86_64-linux-gnu:/usr/local/lib'; "
    . "export PATH=/usr/local/bin:/usr/bin:/bin; "
    . "export LC_ALL=en_US.UTF-8; ";
```

---

## Rate Limiting

### File: `modules/auth/RateLimiter.php`

File-based rate limiter for API endpoints (fail-closed on storage errors):

| Endpoint | Limit | Window | File |
|---|:---:|:---:|---|
| Like/Dislike | 30 | 1 minute | `controllers/api/like.php` |
| Comment | 10 | 1 minute | `controllers/api/delete_comment.php`, `WatchController.php` |
| Upload | 3 | 1 hour | — |
| Transcode | 5 | 1 hour | —
| Auto Metadata | 5 | 1 hour | `controllers/api/auto_metadata.php` |
| API Generic | 60 | 1 minute | — |

**Configuration:** Edit directly in `modules/auth/RateLimiter.php`:
```php
private static array $limits = [
    'like'          => ['requests' => 30, 'window' => 60],
    'comment'       => ['requests' => 10, 'window' => 60],
    'upload'        => ['requests' => 3,  'window' => 3600],
    'transcode'     => ['requests' => 5,  'window' => 3600],
    'auto_metadata' => ['requests' => 5,  'window' => 3600],
    'api'           => ['requests' => 60, 'window' => 60],
];
```

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
