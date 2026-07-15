# 🏗️ Modul & Arsitektur

Dokumentasi mendalam tentang arsitektur modul, class diagram, dan business logic layer MEeL-HUB.

---

## 📋 Daftar Isi

- [Arsitektur Aplikasi](#arsitektur-aplikasi)
- [Core Modules (`modules/`)](#core-modules-modules)
- [Media Pipeline](#media-pipeline)
- [Autentikasi Flow](#autentikasi-flow)
- [Upload & Transcoding Flow](#upload--transcoding-flow)

---

## Arsitektur Aplikasi

```
┌─────────────────────────────────────────────────────────────┐
│                     Browser (User)                          │
├─────────────────────────────────────────────────────────────┤
│              TailwindCSS · HTMX · Plyr.js                   │
└──────────────────────┬──────────────────────────────────────┘
                       │ HTTP / AJAX
┌──────────────────────▼──────────────────────────────────────┐
│              Apache Web Server (mod_rewrite)                │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────┐ ┌──────────┐ ┌──────────┐ ┌────────────────┐  │
│  │ Video   │ │ Music    │ │ Books    │ │ Cloud Drive    │  │
│  │ Module  │ │ Module   │ │ Module   │ │ Module         │  │
│  └────┬────┘ └────┬─────┘ └────┬─────┘ └───────┬────────┘  │
│       │           │            │               │           │
│  ┌────▼───────────▼────────────▼───────────────▼────────┐  │
│  │              Core Modules (modules/)                  │  │
│  │  MediaLibrary · MediaViewer · MediaInteraction        │  │
│  │  Uploader · Transcoder · System · activity_logger     │  │
│  └──────────────────────┬────────────────────────────────┘  │
│                         │                                   │
│  ┌──────────────────────▼────────────────────────────────┐  │
│  │              Database (MySQL/MariaDB)                  │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## Core Modules (`modules/`)

### 1. `MediaLibrary.php`

**Class:** `MediaLibrary`, `BookRepository`, `BookUploader`

Fungsi utama query database untuk katalog media:

```php
class MediaLibrary {
    // Mendapatkan jumlah total per tipe media
    public function getCounts(): array;
    
    // Video
    public function getVideos(int $limit, int $offset);
    public function countVideos(): int;
    public function searchVideo(string $q, int $exclude, bool $sidebar, int $offset);
    
    // Music
    public function getMusicList(string $format, string $artist, int $limit, int $offset);
    public function countMusic(string $format, string $artist): int;
    public function getArtists();
    public function searchMusic(string $q, int $exclude, bool $sidebar);
    public function getUserPlaylists(int $user_id);
}
```

**Method Chaining Pattern:**
```
getMusicList('ogg', 'Mifada', 10, 0)
  → buildMusicWhere('ogg', 'Mifada')
    → WHERE 1=1 AND filename LIKE '%.ogg' AND artist = 'Mifada'
    → Prepared statement dengan parameter binding
```

**Class Diagram Helper:**
```
MediaLibrary
  ├── getCounts()         → query UNION ALL untuk music/video/books
  ├── getVideos()         → ORDER BY upload_date DESC LIMIT ?
  ├── searchVideo()       → CASE WHEN ... THEN rank ... END
  ├── getMusicList()      → filter format + artist
  ├── searchMusic()       → ranked search dengan FULLTEXT-like scoring
  └── buildMusicWhere()   → dynamic WHERE clause builder (private)

BookRepository
  ├── getBooks()          → filter by type (manga/pdf/all)
  ├── getBookById()       → single book by ID
  └── getUserRole()       → role check

BookUploader
  ├── handleUpload()      → entry point
  ├── handleThumbnail()   → image upload + security
  ├── handlePdf()         → PDF validation & storage
  ├── handleManga()       → ZIP/CBZ extraction
  └── insertBook()        → database insert
```

### 2. `MediaViewer.php`

**Class:** `MediaViewer`

Menangani view tracking, komentar, dan rekomendasi:

```php
class MediaViewer {
    public function recordView();           // Track unique view
    public function getMediaData();         // Get media + uploader info
    public function getUserInteraction();   // Like/dislike status
    public function addComment($post_data); // Add nested comment
    public function getComments();          // Get grouped comments
    public function getRecommendations($limit);  // Random recommendations
    public function getPlaylistQueue($playlist_id); // Music queue
}
```

**View Tracking Logic:**
```
recordView()
  → Cek user active && role != 'guest'
  → INSERT IGNORE INTO view_logs (unique per user per media)
  → Jika affected_rows > 0, UPDATE views = views + 1
```

### 3. `MediaInteraction.php`

**Class:** `MediaInteraction`

Like/dislike dan delete komentar:

```php
class MediaInteraction {
    public function toggleLike($media_id, $media_type, $like_type);
    public function getUserInteractionStatus($media_id, $media_type);
    public function getLikesCount($table, $media_id);
    public function deleteComment($comment_id);
}
```

**Toggle Like Logic:**
```
toggleLike()
  → Cek existing interaction
  → Jika same type: DELETE (toggle off)
  → Jika different type: UPDATE (change)
  → Jika no existing: INSERT (new)
  → Sync likes/dislikes count ke tabel media
  → Return updated counts
```

### 4. `Uploader.php`

**Class:** `Uploader` (dengan type hints — private `\mysqli $conn`, `int $user_id`, `string $username`, dll.)

Menangani upload file lokal (bukan via URL):

> ⚠️ **Perubahan:** `$base_dir` sekarang menggunakan konstanta `MEEL_HDD_VIDEO_UPLOAD` dari `auth/config.php`.

```php
class Uploader {
    public function processMusic($post, $files, $base_dir);
    public function processVideo($post, $files, $upload_dir = "");
}
```

**Upload Pipeline:**
```
Upload Video:
  1. Validate file (extension, size, duration)
  2. Generate unique folder name
  3. Stage file ke temp/
  4. Generate thumbnail (user upload → auto-generate)
  5. Transcode ke HLS via FFmpeg (-codec copy)
  6. Generate Sprite & VTT thumbnails
  7. Pindahkan ke HDD storage
  8. Insert record ke database

Upload Music:
  1. Validate file (extension, size, duration)
  2. Generate unique filename
  3. Extract/Generate thumbnail (manual → embedded ID3)
  4. Transcode ke Opus/OGG via FFmpeg
  5. Cleanup original file
  6. Insert record ke database
```

### 5. `Transcoder.php`

**Class:** `Transcoder` (dengan type hints — private `\mysqli $conn`, `int $user_id`, dll.)

Engine utama untuk download URL (yt-dlp) dan transcoding:

> ⚠️ **Perubahan:** Konstanta path `HDD_BASE`/`HDD_VIDEO_DIR`/`HDD_THUMB_DIR` telah dipindahkan ke `auth/config.php` sebagai `MEEL_HDD_VIDEO_UPLOAD`, `MEEL_HDD_VIDEO_DIR`, `MEEL_HDD_THUMB_DIR`.

```php
class Transcoder {
    // Download & finalize
    public function processDownload(string $url, string $type): string;
    
    // Post-encode music
    public function encodeMusic($temp_file, $title, $artist, $album, $duration, $description);
    
    // Transcode video → audio
    public function transcodeVideo(int $video_id, string $format): array;
}
```

**Download Flow:**
```
processDownload()
  → Validasi URL & type
  → Lock queue
  → fetchMetadata() via yt-dlp --print-json
  → Pilih format sesuai platform
  → Tampilkan overlay UI (real-time progress)
  → Download via yt-dlp dengan progress streaming
  → Finalize: video → HLS, music → Opus
  → Release queue
```

### 6. `System.php`

**Class:** `System`

Manajemen queue, storage monitoring, rate limiting:

```php
class System {
    // Monitoring
    public function getActiveQueues(): array;
    public function getTodayUploadStats(): array;
    public function getStorageUsage(): array;
    
    // Limiting
    public function isServerBusy(): bool;
    public function checkRateLimit(int $user_id, string $type, string $user_role): array;
    
    // Management
    public function cleanStuckQueues(): int;
    public function forceStopQueue(int $id, string $task_type): bool;
}
```

**Storage Usage Calculation:**
```
getStorageUsage()
  → disk_free_space("/") untuk SSD
  → du -sb untuk setiap folder media
  → Return [ssd, hdd, sizes, percentages]
```

### 7. `activity_logger.php`

Activity logging & IP Banning system:

```php
// Fungsi utama
function get_real_ip();              // Anti-Cloudflare masking
function validate_and_format_ip();   // Normalize IP (local detection)
function get_access_method();        // Direct/Proxy/Cloudflare
```

**Guest Auto-Registration:**
```
Setiap pengguna yang belum login otomatis:
  → Dicek apakah sudah ada di DB sebagai guest
  → Jika belum: INSERT user dengan role 'guest'
  → Jika sudah: UPDATE last_page, user_agent, ip
```

**Session Kick Detection:**
```
Jika last_session_id berbeda dengan session_id() saat ini:
  → Destroy session
  → Redirect ke halaman /err/revoked.php
  → User harus login ulang
```

### 8. `helpers.php`

Fungsi utilitas global:

> ⚠️ **Perubahan:** `$hdd_check_path` sekarang mengambil nilai dari konstanta `MEEL_HDD_BASE` (didefinisikan di `auth/config.php`), bukan hardcoded.

```php
function time_ago($timestamp);       // Format waktu relatif (ID)
function format_bytes($bytes);       // Format ukuran file
function music_thumbnail_url($thumbnail); // Resolve thumbnail path
function get_user_usage($username);  // Hitung usage drive user
function get_csrf_token();           // Get CSRF token
function verify_csrf_token($token);  // Verifikasi CSRF
function log_drive_operation(...);   // Log operasi drive
```

---

## Media Pipeline

### Video Pipeline

```
Upload → FFmpeg Transcode → HLS (.m3u8 + .ts)
                                ↓
                          Sprite Generator
                                ↓
                         VTT Thumbnails
                                ↓
                         Move to HDD
                                ↓
                          DB Insert
```

### Audio Pipeline

```
Upload/Download → FFmpeg Encode → Opus (.ogg)
                                      ↓
                            Thumbnail Extraction
                                (ID3 → JPG)
                                      ↓
                               DB Insert
```

### Download URL Pipeline

```
URL Input → yt-dlp Metadata → Download → Type Check
                                            ↓
                              ┌──────────────┴──────────────┐
                              ↓                             ↓
                          Video                         Music
                              ↓                             ↓
                     FFmpeg HLS                    FFmpeg Opus
                     (codec copy)                   (libopus)
                              ↓                             ↓
                      Sprite + VTT                  Cover Art
                              ↓                             ↓
                         DB Insert                    DB Insert
```

---

## Autentikasi Flow

```
Request → auth.php
  ↓
Session exists? → Tidak → Redirect ke login.php
  ↓ Ya
Validasi last_session_id
  ↓
Berbeda? → Ya → Session Destroy → Redirect ke /err/revoked.php
  ↓ Tidak
Update last_activity
  ↓
Lanjutkan ke halaman yang diminta
```

### Login Flow

```
POST login
  ↓
Verify CSRF token
  ↓
Validasi username & password
  ↓
Gagal 5x? → Lock 5 menit
  ↓ Berhasil
Set session variables
  ↓
Update last_session_id
  ↓
Redirect ke index.php
```

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
