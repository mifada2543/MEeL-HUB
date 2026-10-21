# 🏗️ Modules & Architecture

In-depth documentation of module architecture, class diagrams, and business logic layer of MEeL-HUB.

---

## Table of Contents

- [Application Architecture](#application-architecture)
- [Core Modules (`modules/core/`, `modules/media/`, `modules/exceptions/`, `modules/transcoder/`)](#core-modules-modulescore-modulesmedia-modulesexceptions-modulestranscoder)
- [Media Pipeline](#media-pipeline)
- [Authentication Flow](#authentication-flow)
- [26. JS/CSS Modularization](#26-jscss-modularization)
- [27. Adaptive Aspect Ratio Player](#27-adaptive-aspect-ratio-player)
- [28. Chat API](#28-chat-api)
- [ProgressObserver Architecture](#progressobserver-architecture)
- [Filesystem Safety Convention (no @ suppression)](#filesystem-safety-convention-no--suppression)

---

## Application Architecture

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
│  │  Uploader · Transcoder · System · RateLimiter         │  │
│  └──────────────────────┬────────────────────────────────┘  │
│                         │                                   │
│  ┌──────────────────────▼────────────────────────────────┐  │
│  │              Database (MySQL/MariaDB)                  │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## Core Modules (`modules/core/`, `modules/media/`, `modules/exceptions/`, `modules/transcoder/`)

### 📁 Directory Structure

```
modules/
├── core/                   # Core business logic
│   ├── System.php          # Queue management, storage monitoring
│   ├── Uploader.php        # Local file upload (video + music)
│   ├── Transcoder.php      # Facade: yt-dlp download & transcoding → delegates to modules/transcoder/
│   ├── TranscoderBase.php  # Base class: shared constants, process/PID management, path resolution
│   ├── helpers.php         # Backward-compat shim → requires helpers/main.php + auth/loader.php
│   ├── helpers/            # Per-domain utilities: main.php, storage.php, audio.php, url.php, metadata.php, text_upload.php, subtitle.php, upload.php, settings.php
│   ├── Router.php          # MeelRouter — front-controller route table (routeFor/url/dispatch)
│   ├── bootstrap.php       # Environment detection & error reporting
│   ├── base_url.php        # Centralized base URL computation (meel_base_url_path)
│   ├── activity_logger.php # Activity logging, IP banning, session kick
│   ├── GarbageCollector.php# Auto-cleanup temp files & guests
│   ├── ProgressObserver.php# Progress event contract (interface + callable adapter)
│   ├── BrowserProgressObserver.php # Browser presenter — progress events → overlay/JS
│   ├── CommentRenderer.php # Nested comment rendering
│   ├── japanese.php        # Japanese text processing (MeCab)
│   └── SwPrecache.php      # PWA precache generator (service worker)
├── auth/                   # Centralized security infrastructure (loaded via loader.php)
│   ├── RateLimiter.php     # File-based API rate limiter
│   ├── SsrfGuard.php       # SSRF-safe URL validation
│   ├── ValidatingProxy.php # SSRF-defense forward proxy
│   ├── ValidatingProxyProtocol.php # Proxy protocol rules (parsing, forwarding, relay)
│   └── helpers/            # authz.php, csrf.php, session.php, stream_auth.php, mfa.php, user.php
├── media/                  # Media query modules
│   ├── MediaLibrary.php    # DB queries, pagination, BookRepository, BookUploader
│   ├── MediaViewer.php     # View tracking, comments, recommendations
│   ├── MediaInteraction.php# Like/dislike, comment deletion
│   ├── SearchEngine.php    # FULLTEXT search with sanitizer + parameter filtering
│   ├── ArchiveGuard.php    # Safe ZIP/CBZ extraction — anti zip-bomb limits
│   ├── PlaylistRepository.php # Playlist queries & playlist slug routes
│   ├── MediaAdminRepository.php # Media metadata queries for the admin panel (edit video/music)
│   ├── ProfileRepository.php # Profile data queries (video/music counts)
│   ├── AdminFilteredRepository.php # Shared base for the two admin repositories below
│   ├── AdminActivityRepository.php # Activity-log queries & filters for the admin viewer
│   └── AdminUploadQueueRepository.php # Upload-queue queries, stats & filters for the admin viewer
├── exceptions/             # Exception classes
│   ├── ProcessException.php
│   ├── DownloadException.php
│   └── TranscodeException.php
├── transcoder/             # Services extracted from Transcoder (extend TranscoderBase)
│   ├── EncodeService.php   # encodeMusic() — download → Opus encode → thumbnail → INSERT music
│   ├── DownloadService.php # processDownload() — URL download (yt-dlp) + video HLS finalization
│   ├── TranscodeService.php# transcodeVideo() + ownsTranscodeFile() — audio/video transcode
│   └── FfmpegUtils.php     # Trait: probeDuration(), generateSpriteAndVTT(), filesystem helpers
└── autoload.php            # Class-map autoloader

# ── IN ROOT PROJECT (not in modules/) ────────────────────────────────
sw.js.php                   # Service worker generator — served as /sw.js via .htaccess rewrite
```

---

### 1. `modules/media/MediaLibrary.php`

**Class:** `MediaLibrary`, `BookRepository`, `BookUploader`

Database query functions for media catalog — with **pagination metadata**:

```php
class MediaLibrary {
    protected function paginateResult($result, int $total, int $page, int $perPage): array;
    public function getCounts(): array;
    public static function clearCountsCache(): void;
    public function getVideosWithMeta(int $page = 1, int $perPage = 15): array;
    public function getVideos(int $limit, int $offset);
    public function countVideos(): int;
    private function searchMedia(array $cfg): ?\mysqli_result;
    private function countSearchMedia(array $cfg): int;
    public function searchVideo(string $q, int $exclude = 0, bool $sidebar = false, int $offset = 0);
    public function getMusicListWithMeta(string $format, string $artist, int $page = 1, int $perPage = 10): array;
    public function getMusicList(string $format, string $artist, int $limit, int $offset);
    public function countMusic(string $format, string $artist): int;
    public function getArtists();
    public function searchMusic(string $q, int $exclude = 0, bool $sidebar = false, int $offset = 0);
    public function searchBooks(string $q, string $type = 'all', int $offset = 0, int $limit = 24);
    public function getUserPlaylists(int $user_id);
}
```

`searchVideo()` and `searchMusic()` are thin wrappers that call `searchMedia()` / `countSearchMedia()` with module-specific config (table, match columns, session key).

**Pagination Metadata Array:**
```php
[
    'data'        => mysqli_result,
    'total'       => 100,
    'page'        => 1,
    'per_page'    => 15,
    'total_pages' => 7,
    'from'        => 1,
    'to'          => 15,
]
```

**Search resilience:** `searchVideo()`, `searchMusic()`, and `searchBooks()`
wrap their FULLTEXT queries in `try/catch (\mysqli_sql_exception)` — a malformed
boolean-mode query falls back to an empty result instead of a 500 error.

### 2. `modules/media/MediaViewer.php`

**Class:** `MediaViewer` — view tracking, comments, recommendations:

```php
class MediaViewer {
    public function recordView();
    public function getMediaData();
    public function getUserInteraction();
    public function addComment($post_data);
    public function getComments();
    public function getRecommendations($limit);
    public function getPlaylistQueue($playlist_id);
}
```

### 3. `modules/media/MediaInteraction.php`

**Class:** `MediaInteraction` — like/dislike and comment deletion.

#### The like/dislike buttons (`partials/like_dislike.php`)

The container is rendered from **one** partial, included by `music/watch.php`,
`video/watch.php` and `controllers/api/like.php`.

That matters because the container is replaced wholesale: the buttons carry
`hx-swap="outerHTML"`, so whatever the server sends back becomes the new
container. The markup used to be written out three times, and the copies had
drifted apart — the API copy still had the old `plus` icon and the word
"Playlist" where the pages had `list-plus` and "Simpan", and the accent
colour differed too (`red-500` vs `red-600`, orange vs white for the active
dislike). Whichever copy was stale was the one that won, because the API
answers the swap. So pressing like made the button change shape in front of
you: it came back as a plain plus, labelled "Playlist" instead of "Simpan".

Now both sides render the same partial, so the swapped-in container is
byte-for-byte the one that was already on screen.

The partial takes the differences as data instead of as a second copy:

| Variable | Meaning |
|---|---|
| `$like_dislike_media_id` | media id |
| `$like_dislike_media_type` | `music` or `video` — picks the accent colour and whether the save button appears |
| `$like_dislike_state` | `''`, `like` or `dislike` |
| `$like_dislike_likes` / `$like_dislike_dislikes` | counters |
| `$like_dislike_csrf` | CSRF token for the `hx-vals` payload |
| `$like_dislike_rate_limit` | seconds left when the rate limiter rejects the request; `0` means normal |

`LikeDislikePartialTest` keeps the copies from coming back: it fails if the
container markup exists in more than one file.

#### Comment Reply Rules

| Rule | Value |
|---|---|
| Parent validation | `resolveParentId()` checks that the parent exists **and** has the same media column. Otherwise `parent_id` is set to `NULL` so the comment becomes a root comment — deterministic and still visible. |
| Reply depth | `MediaViewer::MAX_COMMENT_DEPTH` = 5. Going past the limit is promoted to root, so one person cannot build an unbounded chain just by replying to a reply. |
| Order & pagination | `getComments()` uses `created_at DESC, id DESC` and takes an `$offset` parameter. With the old order (`created_at ASC` + `LIMIT 200`), new comments could be pushed out of the window and never appear again. Comments already written are not deleted. |

### 4. `modules/core/Uploader.php`

**Class:** `Uploader` (uses `FfmpegUtils` trait) — local file uploads with:
- Magic bytes validation (MP4/WebM/MKV)
- Active upload limit (max 3 simultaneous)
- Pre-flight disk space check via `require_disk_space()`
- RAM disk staging (`/dev/shm`) for HLS transcoding
- Atomic DB transaction with rollback + file cleanup

**Delegation to shared helpers (`helpers/upload.php`)** — to avoid duplication,
`Uploader`, `EncodeService`, `DownloadService`, and the admin editors all use the
same global functions:
- `meel_reserve_unique_filename()` — atomic `fopen(..., 'x')` name reservation (race-safe)
- `meel_allocate_unique_dir()` — unique directory allocation without overwrites
- `meel_sanitize_clean_name()` / `meel_sanitize_upload_filename()` — name sanitization
- `meel_ffmpeg_thumbnail_webp()` — single image → WebP conversion path (`libwebp`)
- `meel_ffmpeg_encode_opus()` — single audio → Ogg/Opus encode path
- `meel_insert_music_row()` — single INSERT path for the `music` table
- `meel_magic_extension_ok()` — magic-bytes + extension validation per media kind

```php
class Uploader {
    public function processMusic($post, $files, $base_dir);
    public function processVideo($post, $files);
}
```

`processVideo()` no longer takes a `$upload_dir` argument. It was never read, and
the one caller passed `__DIR__ . "/"` to it.

Both upload paths are now orchestrators over private helpers rather than one
long method. The helpers follow a single convention: **return `null` to continue,
or return the final result array to stop.**

| Method | Responsibility |
|---|---|
| `assertVideoUploadAllowed()` / `assertMusicUploadAllowed()` | rate limit, concurrent-upload limit, pre-flight disk space |
| `inspectVideoCodecs()` | magic bytes, video codec, audio codec |
| `buildVideoCleanName()` / `buildVideoThumbnail()` | romaji-clean name, user thumbnail or ffmpeg frame, small sprite |
| `prepareVideoWorkFolder()` | unique folder under lock, `/dev/shm` when it has room |
| `runVideoHlsTranscode()` | the ffmpeg HLS invocation |
| `stageVideoSubtitle()` | `.srt`/`.vtt` subtitle, only when the transcode succeeded |
| `moveVideoFilesToHdd()` | the rename loop into storage, under a move lock |
| `insertVideoRecord()` | the transaction; the caller cleans up files when it fails |
| `validateMusicUpload()` / `stageMusicFile()` | extension, magic bytes, size, duration |
| `buildMusicThumbnail()` | user thumbnail or one frame from the audio |
| `maybeTranscodeToOgg()` | re-encode to Ogg unless the admin skipped it |

### 5. `modules/core/Transcoder.php` — facade + split services

**Class:** `Transcoder` — **facade** that keeps the legacy public contract
(constructor, `processDownload`, `encodeMusic`, `transcodeVideo`) while delegating
implementation to the services in `modules/transcoder/` (all `extends TranscoderBase`):

```
Existing caller
    ↓
Transcoder (facade, modules/core/Transcoder.php)
    ↓
├── EncodeService    (modules/transcoder/EncodeService.php)    encodeMusic()
├── DownloadService  (modules/transcoder/DownloadService.php)  processDownload() + video finalization
└── TranscodeService (modules/transcoder/TranscodeService.php) transcodeVideo() + ownsTranscodeFile()
    ↓
TranscoderBase (modules/core/TranscoderBase.php) — shared constants, processes/PID, path resolution
```

**`TranscoderBase`** holds the shared responsibilities: configuration constants
(`FFMPEG_THREADS=8`, `HLS_SEGMENT_DURATION=10`, `DOWNLOAD_TIMEOUT=900`,
`TRANSCODE_AUDIO_TIMEOUT=600`, `PID_DIR`, `FFMPEG_LIB_PATH`, `ENV_PREFIX` — all
`protected` so child services can resolve them), the `ProgressObserver` constructor,
process management (`terminateAllProcesses()`, static `killByPidFile()`,
`cleanupStalePidFiles()`), and safe path resolution (`getTranscodeFilePath()`,
`resolveMusicInputPath()` — server-side paths, never client input).

**Pure business logic — no HTML/JS output:** progress is reported through a
`ProgressObserver` (see [ProgressObserver Architecture](#progressobserver-architecture)),
so the same engine runs cleanly in browsers, CLI scripts, cron jobs, and API endpoints.

Public facade contract:
```php
class Transcoder {
    public function __construct(\mysqli $db_connection, int $session_user_id,
                                callable|ProgressObserver|null $progressListener = null);
    public function setProgressListener(callable|ProgressObserver|null $listener): void;
    public function terminateAllProcesses(): void;   // Graceful shutdown hook
    public function processDownload(string $url, string $type): string;
    public function encodeMusic($temp_file, $title, $artist, $album, $duration, $description);
    public function transcodeVideo(int $video_id, string $format): array;
}
```

Features:
- RAM disk priority (`/dev/shm/meel/`) with automatic fallback
- Per-platform format resolution (YouTube H.264+AAC, NicoNico, TikTok)
- Real-time progress via `ProgressObserver` events — the browser overlay
  (`partials/ui.php` + `meel*` JS) is rendered by `BrowserProgressObserver`, not by this class
- **Transactional finalization** — `finalizeVideo()` moves HLS files to the USB HDD
  *and* inserts the database record inside a single MySQL transaction. Any failure
  rolls back and automatically deletes the HLS folder/thumbnail already copied to
  the HDD (no orphaned files on storage)
- **PID-based process termination** — child processes (yt-dlp, ffmpeg) are spawned
  via `proc_open()` and tracked by PID/process-group; timeout aborts use
  `posix_kill()` (SIGTERM → grace period → SIGKILL) instead of `pkill -f` string
  matching. Callers register `terminateAllProcesses()` as a shutdown function
- **PID file management** — each spawned process writes its PID to `/tmp/meel_pids/{type}_{id}.pid`, enabling the admin panel to kill processes across requests via `killByPidFile()`
- **FFmpeg library path** — `proc_open()` env sets `LD_LIBRARY_PATH` explicitly to prevent FFmpeg hangs when the parent process env differs from the child
- **Transcode timeout** — audio transcode has a 600-second max (`TRANSCODE_AUDIO_TIMEOUT`); HLS remux has a 120-second max; stream reads use `stream_set_timeout(30)` to detect pipe stalls
- **Cache validation** — transcoded files are validated by both `filesize > 10KB` and `duration ≥ 50%` of source duration to reject corrupt/stub files
- **FFmpeg exit code check** — `proc_close()` exit code is verified; non-zero exits are logged with the last 15 lines of stderr and the queue status is set to `failed`. The queue row is also marked `failed` when `proc_open()` cannot start ffmpeg at all, or when ffmpeg exits cleanly but leaves no output file — previously both cases fell through and were recorded as `completed`
- **Filename sanitization** — whitelist regex (`[^a-zA-Z0-9_\x{3000}-\x{9fff}...]`) preserves CJK characters while preventing path traversal
- Cached directory size via `dir_size()`
- Thumbnail sprite + VTT generation

#### `transcodeVideo()` pipeline

`transcodeVideo()` used to be a single 341-line method. It is now a 71-line
orchestrator over focused private helpers, so each step can be read and tested on
its own:

| Step | Method | Responsibility |
|---|---|---|
| 1 | `prepareOutputDirectory()` | create the shm directory, refuse to start with under 256 MB free |
| 2 | `purgeStaleArtifacts()` | drop outputs older than 2 h and queue rows older than 15 min |
| 3 | `loadTranscodeSource()` | resolve the video row, HLS playlist, sorted `.ts` segments, duration, total size |
| 4 | `enforceSourceQuota()` | non-admin ceiling of 200 MB / 10 min |
| 5 | `claimTranscodeSlot()` | under an exclusive lock: reuse a valid cache, drop an invalid one, claim a marker, then `System::claimQueueSlot()` |
| 6 | `writeConcatList()` | build the ffmpeg concat manifest with single quotes escaped |
| 7 | `resolveAudioFormat()` | codec, bitrate, thumbnail eligibility, ID3 metadata |
| 8 | `buildFfmpegArgv()` | assemble the argv array passed to `proc_open()` |
| 9 | `runFfmpeg()` | spawn the process, stream stderr, report progress, enforce the timeout |
| 10 | `reportProgress()` | translate ffmpeg's `time=` marker into a percentage |

Private helpers follow one convention: **return `null` to continue, or return the
final result array to stop**. `claimTranscodeSlot()` returns a third state —
`null` means "proceed with the transcode", an array means "cache hit or failure,
stop here". The lock in step 5 is released with `try`/`finally` instead of being
unlocked at three separate exit points.

Two things were removed rather than carried forward:

- **A dead second command builder.** The old code assembled both `$cmd` (a shell
  string) and `$tc_cmd` (an argv array). Only `$tc_cmd` was ever passed to
  `proc_open()`; `$cmd` was built and discarded. They had already drifted apart —
  `$cmd` asked for `-c:v copy` while the live builder used `-c:v mjpeg` — so the
  unused copy was a trap for whoever tried to "fix" the wrong one.
- **`escapeshellarg()` on that dead string.** With it gone, the file performs no
  shell string-building at all; `TranscodeService` now only ever invokes ffmpeg
  through an argv array.

`tests/unit/TranscodeArgvTest.php` pins the argv invariants. It exists because
the split initially introduced a regression: `use_thumb` was only assigned in the
`ogg` branch of the format switch, so `mp3`/`m4a`/`flac` read an uninitialised
variable and silently produced audio with no cover art. The tests assert
observable behaviour (which streams get mapped, which flags appear) rather than
re-implementing the builder.

#### Video finalization in `DownloadService`

`finalizeVideo()` is split into steps that each have one responsibility. Two of
them are worth spelling out, because their shape is what makes them safe:

**`runParallelFfmpeg()`** runs several ffmpeg processes at once and supervises
them until all of them finish. This is the part most prone to going wrong:
leaked pipes, child processes that are never reaped (zombies plus PID files
that are never removed), and a timeout path that leaves ffmpeg running in the
background.

It was split out of `finalizeVideo()` in T19, when that method was 268 lines
with nine phases. What decided the shape: **it stays a method, not a new class**
(`FfmpegProcess`). Every hook it needs — `trackChildProcess()`,
`untrackChildProcess()`, `terminateChildProcess()`, `writePidFile()`,
`removePidFile()`, `isProcessAlive()` — is `protected` in `TranscoderBase`, and
the child process list (`$childProcesses`) is per-instance state that
`terminateAllProcesses()` scans at shutdown. A separate class could not reach
that without widening those to `public` or moving the child list into a shared
collaborator. Both land exactly on the mechanism whose job is to **prevent**
orphaned ffmpeg processes when a request dies mid-transcode. Trading that for
tidier file layout would be a large loss, so it was not taken. The seam is
left open: as soon as there is a test harness that can exercise it end to end,
the class can be extracted without rewriting its logic from scratch.

**Moving the result to the HDD and recording it in the database** happen inside
one transaction, and the important property is this: if anything fails midway,
the system must not be left half-done — the file moved but the database row
missing, or the other way round. That is why `moveFile()` throws instead of
returning `false`, and the `catch` performs a database rollback **plus**
cancellation of the filesystem effect. The cancellation order matters and must
not be reversed:

1. roll back the transaction (the database row never comes into existence)
2. `rollbackFinalizeVideo()` (delete the target folder and the thumbnail)
3. delete the work folder

`finalizeVideo()` as the caller does not need to know those details — it only
needs to know whether it succeeded; the error message is already emitted
inside.

### 6. `modules/core/System.php`

**Class:** `System` — queue management, monitoring, rate limiting.

Key method — `forceStopQueue(int $id, string $task_type): bool`:
- **Inline PID kill** — reads PID file from `/tmp/meel_pids/` and sends `SIGTERM` → `SIGKILL` directly (no `Transcoder.php` dependency, avoiding output-before-headers issues in the admin panel)
- Deletes the queue record from `upload_queue` or `transcode_queue`
- Returns `true` on success, `false` on failure

#### PID identity verification (`killByPidFile`)

PID files can be **stale**: once the process is gone, the OS recycles that PID
number for something else. Killing without verification means the admin panel
could kill a process it has **nothing to do with**. So the PID's cmdline is
checked first (`ffmpeg`, `ffprobe`, `yt-dlp`, `youtube-dl`, `setsid`, `sh`).

Two failure conditions are deliberately treated differently:

| Condition | Behaviour | Why |
|---|---|---|
| `/proc` available, cmdline doesn't match | **fail-closed** (refuse to kill) | The PID was most likely recycled — this is the case being protected |
| `/proc` unavailable (platform without procfs, or `hidepid`) | **fail-open** (kill anyway) + `error_log` | Nothing can be proven; refusing would orphan ffmpeg forever |

When the PID is a *process group leader*, the kill is aimed at `-PID` so the
whole ffmpeg child tree dies with it. `kill -- -PID` uses `--` so a negative PID
isn't parsed as an option.

#### Queue records are NOT deleted when the process survives

`forceStopQueue()` only deletes the queue record **when there is no live
process left to stop**. If a PID file exists but the kill was refused, the
record is **kept** — otherwise the process becomes a permanent orphan with no
trace in the DB and can never be stopped from the UI.

The two cases are distinguished via `TranscoderBase::hadPidFile()`, which
**must be called before `killByPidFile()`** because that function removes the
PID file at the end (on success and on failure alike):

| Condition | Result |
|---|---|
| No PID file (job finished / never started) | Record deleted — the queue must not jam |
| PID file present, kill refused | Record kept, `error_log`, `return false` |

### 7. `modules/core/activity_logger.php`

Activity logging & IP Banning:

```php
function get_real_ip();              // Anti-Cloudflare masking
function validate_and_format_ip();   // Normalize IP
function get_access_method();        // Direct/Proxy/Cloudflare
function get_connection_protocol();  // IPv4 vs IPv6 detection
function log_activity(...);          // INSERT INTO activity_log (audit trail)
```

**Features:**
- Guest auto-registration with `ON DUPLICATE KEY UPDATE`
- Session kick detection (last_session_id mismatch)
- Device & page detection ("Browsing Music Library", "Watching: ..., "Listening: ...")
- Stream.php throttling (avoids DB query on every range request)
- IP ban check with admin bypass
- IPv4-mapped IPv6 support (`::ffff:192.168.x.x`)

### 8. `modules/core/helpers/` (global utilities)

`helpers.php` is now a shim that requires `helpers/main.php` + `modules/auth/loader.php`.
Functions are wrapped in `function_exists()` guard and split across per-domain subfolders:

```php
function resolve_binary(array $candidates): string;     // Binary path discovery (with MEEL_*_PATH constant override)
function base_url(string $path = ''): string;           // Dynamic base URL (fallback via meel_base_url_path(), see base_url.php)
function detectProtocol(): string;                       // HTTPS detection with Cloudflare support
function time_ago($timestamp);                           // Relative time (ID locale)
function format_bytes($bytes);                           // Human-readable file size
function music_thumbnail_url($thumbnail);                // Thumbnail path resolver
function get_user_usage($username);                      // User drive usage
function get_user_role(mysqli $conn, int $user_id): string;   // Role with 3-level cache
function invalidate_user_role_cache();                   // Force refresh role cache
function get_csrf_token();                               // Get CSRF token
function verify_csrf_token($token = null);               // Verify CSRF
function check_disk_space(int $required, string $path);  // Disk capacity check
function require_disk_space(...);                        // Pre-flight disk guard
function dir_size(string $path, int $cache_ttl = 300): float;  // Cached directory size
function get_audio_mime_type(string $ext): string;       // MIME type resolver
function get_audio_format_label(string $ext): string;    // Format label
function get_audio_format_description(string $ext): string;
function log_drive_operation(...);                       // Drive audit trail
function meel_validate_text_upload(string $path, int $max_bytes = 2097152): bool;  // Uploaded text file guard
```

#### Uploaded text file validation

Lyrics (`.lrc`, `.txt`) and subtitles (`.vtt`, `.srt`) accept the same kind of
file: plain text, at most 2 MB, no NUL byte and no PHP tag. That rule used to
be written twice — once in `helpers/lyrics.php`, once in `helpers/subtitle.php`
— with byte-for-byte identical bodies. Two copies of a security rule means one
of them can drift while the other has already been tightened.

`meel_validate_text_upload()` in `modules/core/helpers/text_upload.php` now
holds the rule. `validate_lyrics_file()` and `validate_subtitle_file()` are
thin wrappers over it, so every existing caller keeps working unchanged.
`helpers/main.php` loads the new file before both of them.
`TextUploadValidationTest` pins that the wrappers and the shared rule agree.

#### Storage helper entry point

`modules/core/helpers/storage.php` deliberately still exists even though its
own body is nearly empty: `main.php` loads it in a single line, and almost 50
files outside tests/ call its functions. Splitting that entry point is far
riskier than the benefit it would bring.

Its contents are already split per domain, following this folder's
convention:

| File | Contents | Functions |
|---|---|---:|
| `storage_paths.php` | base path, thumbnail, PID, mount guard | 14 |
| `storage_disk.php` | disk capacity, cache file, audit log | 8 |
| `storage_xsendfile.php` | detection & X-Sendfile header | 11 |
| `storage_stream.php` | cache validator + media serving | 9 |

The `require` order must not be shuffled, because it follows the dependency
direction: `paths` → `disk` → `xsendfile` → `stream`. PHP defers function
definitions until a file has been read completely, so the order only matters
for load-time side effects — the only one being the `MEEL_HDD_CHECKED` block
in `storage_paths.php`. Every definition stays wrapped in
`function_exists()`, so the file is safe to load more than once.

#### Data export helpers (CSV, JSON, SpreadsheetML)

`admin/activity_log.php` used to have two export blocks and six identical
row-mapping definitions in total: the SpreadsheetML preamble, the `$xls_cell`
closure, the upload-queue header, and the six-column upload-queue row
mapping. The consequence was not just long files — change one column and
five other places have to change with it, and nothing tells you when you
forget. The CSV export could silently end up with different columns than the
JSON export.

What `modules/core/helpers/export.php` collects is the shape, not the data:
each dataset's column list stays defined in the page that uses it, because
that is data domain, not utility.

Two semantic differences that must be preserved:

- `meel_export_values()` uses `??` for columns that declare a `default` — a
  missing key **and** a `null` value both produce the default. Columns
  without a `default` pass `null` through unchanged.
- `meel_export_map()` reads the raw value for `int?` columns instead of the
  `??` result. Applying the `default` first turns `null` into `''` and then
  into `0`: JSON that used to report `"user_id": null` suddenly reports
  `"user_id": 0`. Null means "unknown", 0 means "user 0".

#### Video thumbnail naming convention

These rules live in `Uploader.php` and are reused wherever a variant name must
be derived:

| Kind | Name pattern | Source |
|---|---|---|
| Main thumbnail | `{folder_name}_thumb.webp` | `Uploader.php` |
| 256px variant (sm) | `{folder_name}_thumb_sm.webp` | `Uploader.php` |
| Default (fallback) | `default_thumb.webp` / `default_thumb.jpg` | never deleted |

Three things that are easy to get wrong:

1. **`folder_name`, not `clean_name`.** `folder_name` comes from
   `meel_allocate_unique_dir()` and is therefore guaranteed unique;
   `clean_name` (from `getRomajiName()`) can collide between videos. If
   thumbnails were derived from `clean_name`, two videos with the same title
   would overwrite each other's thumbnail file.
2. **The `_sm` variant is deliberately NOT stored in the DB.** Only
   `video.thumbnail` is persisted; the `_sm` variant's existence is probed via
   `is_file()`. The admin "Check Database Sync" scan therefore uses the same
   exemption so the variant is never mistaken for junk.
3. **`default_thumb.*` is never deleted.** Every deletion path (thumbnail
   replacement in `admin/edit-video.php`, media deletion in `fun-manage.php`,
   and rollback in `Uploader.php`) checks `meel_is_default_thumbnail()` first.
   Deleting the default thumbnail would break the image fallback entirely.

### 9. `modules/core/CommentRenderer.php`

**Functions:** `render_comments()` — nested comment rendering with 2 themes (video/music); `comment_preview()` — latest comment preview for the comment header.

### 10. `modules/core/GarbageCollector.php`

**Class:** `GarbageCollector` (static methods) — auto-cleanup:
- Temp files in RAM disk (`/dev/shm/meel/*`) and project `temp/`
- Guest accounts (>2 hours inactive) with throttle (1x/hour)
- Abandoned multiplayer chess rooms via `cleanChessRooms()` (throttle 1x/hour):
  - Stale lobby: `black_joined = 0` and created >24 hours ago
  - Abandoned mid-game: started, **no terminal event** (`resign`/`draw_accept`/`disconnect`/`game_over`) and no activity for >7 days (moves history removed too)
  - **Finished games are never deleted** — their history is preserved
- Expired rate limit cache via `RateLimiter::cleanup()`
- Deferred deletion queue (`temp/pending_delete.json`) via
  `cleanPendingFileDeletions()` (throttle 1x/5 minutes, entry TTL 30 minutes)
- Timeboxed execution (max 3 seconds)
- Static `removeFile()`/`removeDirectory()` helpers with proactive `is_writable()`
  guards — subtrees owned by other users (e.g. `temp/cache/` owned by another
  process) are skipped with an error log instead of a PHP warning

#### Media cache TTLs (`cleanCacheDirectory()`)

Each cache prefix has its own TTL; files **still within** their TTL are
protected (never deleted):

| Prefix | TTL | Source |
|---|:---:|---|
| `media_stats_` | 30 seconds | `media.php` |
| `admin_orphans` | 120 seconds | `admin_data.php` |
| `dirsize_` | 300 seconds | `storage.php` |
| `server_stats_info` | 300 seconds | `System.php` |
| `xsendfile_roots` | 86400 seconds | `storage.php` |
| `.tmp.<epoch>.<hash>` | 300 seconds | orphaned temp |

Files with an **unknown prefix** are deleted once older than 1 day (86400 seconds).

> **Why the fallback uses a `$prefixMatched` flag instead of `isset($ttlMap)`?**
> The old code read `if (!$expired && !isset($ttlMap))`. `$ttlMap` is a local
> array that is **always** assigned before the loop, so `isset($ttlMap)` was
> always `true` and the fallback became dead code — unknown-prefix files were
> never deleted and `temp/cache/` grew without bound. The flag now records
> "did any prefix match" explicitly, so the fallback actually runs.

**How the TTL is actually measured.** The cache families do not agree on a
time key: `media_stats_` and `server_stats_info` write `ts`, `dirsize_` and
`xsendfile_roots` write `time`, `admin_orphans` writes `checked_at`. The keys
are therefore looked up in that order, and a file with none of them falls back
to its modification time so nothing hangs around forever.

> **A shortcut that silently broke the whole table.** The expiry check used to
> read `meel_read_cache_file($path, 0, [], '')`, and that helper returns `null`
> outright when the TTL is `0` — which meant "not expired" was read as
> "expired". Every file with a known prefix was therefore deleted on every
> sweep, including one that had just been written. The cache never took
> effect: the statistics, the directory sizes and the xsendfile root list were
> recomputed on every request. The call is gone, and the key lookup that was
> written as its fallback — and never reached — is now the only path.

**Where the cache lives.** The collector resolves the directory through
`meel_media_cache_dir()`, the same helper the application uses. It used to
have its own copy that only looked at the `MEEL_MEDIA_CACHE_DIR` constant and
ignored the environment variable of the same name, so a deployment that
configured the cache through the environment had the application writing to
one directory and the sweeper cleaning another.

#### Orphan cleaner safety guards (`controllers/admin/admin_actions.php`)

The orphan list comes from a 10-minute cache, so it is **not trusted blindly**.
Before deleting anything, entries are re-verified against the DB, with two
exclusions:
- `*_sm.*` thumbnail variants (never stored in the DB, only checked via `is_file()`)
- files that turn out to still be registered in the DB

#### `users` AUTO_INCREMENT is deliberately NOT reset

After guests are deleted, `ALTER TABLE users AUTO_INCREMENT = MAX(id)+1` was
historically used to "tidy up" numbering. This was **removed** because
`auth/auth.php` validates `$_SESSION['user_id']` straight against the `users`
table: a recycled id means a session belonging to user A can silently become
user B. Ids now grow monotonically, and
`GarbageCollector::resetGuestsAutoIncrement()` (method name kept for history)
only calls `cleanGuests()`.

### 11. `modules/auth/RateLimiter.php`

File-based rate limiter with `flock()` safety. Role-based limits (admin = unlimited, member = 2x).

| Endpoint | Max/Window | Notes |
|---|:---:|---|
| `like` | 30/min | HTMX 429 HTML response |
| `comment` | 10/min | Flash message redirect |
| `upload` | 3/hour | — |
| `transcode` | 5/hour | — |
| `auto_metadata` | 5/hour | CPU-intensive (ffprobe + ffmpeg) |
| `register` | 5/hour | **Per-IP** bucket, only successful inserts counted, loopback exempt |
| `api` | 60/min | Generic fallback |

**Fail-closed behavior:** When the storage directory (`temp/ratelimit/`) is not writable or `flock()` fails, the rate limiter **denies all requests** instead of silently allowing them. This prevents a broken filesystem from disabling rate limiting. Failures are logged via `error_log()`.

The `transcode` endpoint is checked **before** the coin spend and heavy work start (order in `transcode.php`: rate-limit → validation → spend); admins pass without a limit (delegated to the internal `RateLimiter`).

### 12. `modules/exceptions/`

Three typed exception classes extending `\RuntimeException`:

```php
class ProcessException extends \RuntimeException {      // External process failure
    public function getCommand(): string;
    public function getExitCode(): int;
    public function getOutput(): ?string;
}

class DownloadException extends \RuntimeException {      // URL download failure
    public function getUrl(): string;
    public function getStage(): ?string;  // 'validation' | 'metadata' | 'download'
}

class TranscodeException extends \RuntimeException {    // FFmpeg transcoding failure
    public function getInput(): string;
    public function getOutput(): ?string;
    public function getFfmpegLog(): ?string;
}
```

### 13. `modules/transcoder/FfmpegUtils.php` (Trait)

Used by `Uploader`, `TranscoderBase`, and the `modules/transcoder/` services:

```php
trait FfmpegUtils {
    protected function resolveBinary(array $candidates): string;
    protected function probeDuration(string $file): int;
    protected function generateSpriteAndVTT(string $video, string $work_folder): void;

    // Filesystem helpers — no @ suppression (see Filesystem Safety Convention)
    protected function ensureDir(string $dir, int $perms = 0755): bool;
    protected function removeFile(string $path): void;
    protected function removeDir(string $dir): void;
    protected function moveFile(string $src, string $dst): bool;  // Cross-device safe (RAM → HDD)
}
```

> Note: `cleanupDir()` (alias of `removeDir()`) has been **removed** — it had no
> callers anywhere in the project; use `removeDir()` directly.

The `moveFile()` helper compares `stat()` device IDs before attempting
`rename()`: moving from the RAM disk (`/dev/shm`) to the USB HDD is the *normal*
cross-device case, so the expected `EXDEV` failure is skipped entirely and the
copy+unlink fallback runs without emitting a misleading warning.

### 14. `modules/core/japanese.php`

Japanese text processing:

```php
function getRomajiName(string $text): string;           // Kana → Romaji for filenames
function analyzeJapaneseText(string $text): array;       // MeCab analysis → [romaji, english]
function getMecabPath(): string;                         // MeCab binary resolver
```

Uses MeCab + PHP `Transliterator` (`intl` extension). Custom kana-to-romaji mapping for better accuracy.

### 15. `modules/core/bootstrap.php`

Centralized bootstrap for all entry points:
- Auto-detect `MEEL_ENV` (development/production/maintenance)
- Configures error reporting and logging per environment
- Sets `MEEL_BASE_URL` constant
- Default timezone (Asia/Jakarta)

#### The application clock

The timezone is set **unconditionally** — `date_default_timezone_set()` runs
whatever `php.ini` says. It used to be guarded by `if (!ini_get('date.timezone'))`,
but PHP's default for that directive is "UTC", so `ini_get()` always returned
"UTC" and the guard never fired. The result was a clock that differed by SAPI:
the CLI ran in UTC while Apache ran in Asia/Jakarta.

That matters because MySQL converts `TIMESTAMP` columns using the **session**
`time_zone` when it reads them. A value written as a PHP wall-clock string and
read back as a MySQL wall-clock string therefore crossed a seven-hour
displacement whenever the two sides disagreed. The symptoms were a MEeLCoin
countdown that jumped back to five hours on every refresh, and an "online"
indicator in the admin panel that disagreed with reality.

Two rules keep the clock single:

| Rule | Where |
|---|---|
| PHP always runs in the application timezone (`MEEL_TIMEZONE`, default `Asia/Jakarta`) | `modules/core/bootstrap.php` |
| MySQL's session `time_zone` is aligned with PHP's offset on every connection | `auth/config.php` |

With both in place, a timestamp written by PHP and read back by MySQL is the
same instant, and code that compares a database column with PHP's clock
(`strtotime($row['last_activity'])`) is correct. `DateTimeSourceTest` holds
both rules in place, and keeps an explicit inventory of the files that
deliberately mix the database clock with PHP's.

### 15a. `modules/core/base_url.php`

Centralized **base URL computation** — the single source of truth for the project's base URL path (relative to `DOCUMENT_ROOT`):

```php
function meel_base_url_path(): string;   // Project root relative to DOCUMENT_ROOT (e.g. "/MEeL")
```

Used by `bootstrap.php` (`MEEL_BASE_URL` fallback), `auth/config.php`, `auth/config.example.php`, and the `base_url()` fallback in `helpers.php`. Computed from this file's location (`dirname(__DIR__, 2)`) rather than `dirname(SCRIPT_NAME)` — consistent for all pages in subdirectories (admin/, video/, etc.).

### 15b. Error Pages (`err/`)

Error handling is centralized in one dynamic page `err/index.php` — content & theme adapt to the error source:

| File | Purpose |
|---|---|
| `err/index.php` | Unified dynamic error page — invoked via `?code=...` |
| `err/offline.php` | PWA offline page (service worker fallback) — must be kept |

**`err/index.php` parameters:**

| Param | Values | Effect |
|---|---|---|
| `code` | `denied` / `not_found` / `banned` / `revoked` / `maintance` | Error type + HTTP status (403 / 404 / 403 / 401 / 503). Default `not_found` |
| `reason` | text | Extra reason line (used by IP-ban redirect) |
| `back` | relative path | Overrides the "Back" button target |

**Source adaptation:** the origin module is detected from `HTTP_REFERER` (video/music/books/drive/admin/profile) → accent color & back-button label change automatically. Back-button priority: `?back=` → referer (GET page) → module home → hub (`index.php`).

### 15c. Keyboard Shortcuts

Keyboard shortcuts are implemented in JavaScript and guarded by `assets/js/shared/keyboard.js` (`meelKeyShortcutIgnored()`) — prevents shortcuts from firing when typing in inputs, holding modifier keys (Ctrl/Alt/Meta), or on key repeat.

**Video Watch (`assets/js/video/watch/`):**

| Key | Action | Source |
|-----|--------|--------|
| `0–9` | Jump to 0–90% duration | Plyr default |
| `Space / K` | Play / Pause | Plyr default |
| `←` / `→` | Seek backward / forward | Plyr default |
| `↑` / `↓` | Volume up / down | Plyr default |
| `M` | Mute / Unmute | Plyr default |
| `F` | Fullscreen | Plyr default |
| `C` | Toggle captions | Plyr default |
| `L` | Toggle loop | `misc.js` |
| `A` | Toggle auto-next | `misc.js` |
| `S` | Toggle AI Upscale | `misc.js` → `toggleUpscale()` in `upscaler.js` |
| `B` | Toggle ambient glow | `misc.js` → `toggleGlow()` in `player-events.js` |
| `N` | Skip to next video | `mini-player.js` |
| `I` | Toggle mini player | `mini-player.js` |

> **Locked while the resume modal is open.** While the "Lanjutkan Sesi?" modal is
> visible, `meelResumeModalActive` is `true` and every shortcut above is disabled
> (except `Esc`), so the video cannot be controlled behind the dialog. `←` / `→`
> move the selection following button position — **← Lanjut**, **→ Ulang**;
> `Home` / `End` jump to the first/last choice; `Enter` or `Space` runs the
> highlighted choice. Enforced by a `window` listener in the **capture** phase in
> `assets/js/shared/resume-modal.js`, which pre-empts Plyr (`window` bubble) and
> `mini-player.js` (`window` capture).

**Icons after an HTMX swap (`assets/js/shared/htmx-lucide.js`):**

Lucide icons are `<i data-lucide="...">` elements that Lucide replaces with an
SVG, and the replacement only happens when `lucide.createIcons()` runs. Every
fragment the server sends back contains plain `<i data-lucide>` markup, so
something has to render it again after the swap — that is what this file
does, on `htmx:afterOnLoad`.

The listener is registered on `document`, **not** on `document.body`. The file
is loaded in `<head>` on some pages (`music/watch.php`, `books/index.php`),
and at that point `document.body` is still `null`; a listener registered on
`document.body` would never be attached, and every later swap would silently
leave the icons unrendered. The symptom is icons that reappear on refresh and
disappear again after pressing like/dislike.

`partials/link.php` installs a queueing stub for `window.lucide` before
`lucide.js` loads, so a call made too early is not lost.

**Music Watch (`assets/js/music/watch/`):**

| Key | Action | Source |
|-----|--------|--------|
| `0–9` | Jump to 0–90% duration | Plyr default |
| `Space / K` | Play / Pause | Plyr default |
| `←` / `→` | Seek backward / forward | Plyr default |
| `↑` / `↓` | Volume up / down | Plyr default |
| `M` | Mute / Unmute | Plyr default |
| `L` | Toggle loop | `misc.js` |
| `E` | Toggle equalizer | `misc.js` |
| `V` | Toggle visualizer | `misc.js` |
| `I` | Go back to library / mini player | `misc.js` |

### 16. `modules/media/SearchEngine.php`

**Class:** `SearchEngine` — FULLTEXT search engine (video, music, books) with query sanitizer:

```php
class SearchEngine {
    public const VIDEO_LIMIT    = 20;
    public const MUSIC_LIMIT    = 20;
    public const MIN_SEARCH_QUERY = 3;   // Short queries (< 3) are ignored
    public const MAX_SEARCH_QUERY = 255; // Maximum query length

    public function __construct(mysqli $db_connection);
    public function parseParams(): array;                    // q (sanitized), offset, etc.
    public static function sanitizeQuery(string $q): string; // FULLTEXT-safe: strip bare operators, balance quotes, strip leading asterisks from tokens
    public function searchVideo(array $params): array;
    public function searchMusic(array $params): array;
    public static function clearCache(): void;
}
```

**Key behaviors:**
- `sanitizeQuery()` is **public static** — shared by every search entry point so
  the FULLTEXT syntax is always valid (no `mysqli_sql_exception` on malformed input).
- `parseParams()` reads `$_GET['search']` + `$_GET['offset']`; offset is included
  in the **cache key**, so pagination never serves a stale page.
- `searchVideo()` / `searchMusic()` always compute the **total** — even for an
  empty result set — so `hasMore` and the pagination metadata are trustworthy
  (fixes the bogus "1/1 → 2/1" progress display).
- `MIN_SEARCH_QUERY = 3` — shorter queries are ignored (index efficiency)

### 17. `modules/autoload.php`

Class-map autoloader via `spl_autoload_register()`. Auto-loads 19 classes from `modules/core/`, `modules/media/`, `modules/transcoder/`, `modules/auth/`, `drive/`, etc.

### 18. WatchController (`controllers/api/WatchController.php`)

```php
class VideoWatchController { public function getViewData(): array; }
class MusicWatchController { public function getViewData(): array; public function requireMedia(): void; }
```

### 19. Migration System (`database/migrate.php`)

The migration system is **idempotent** — safe to run multiple times. The `db_version` table tracks which migrations have been applied (one row per version; each version runs exactly once).

> ⚠️ **Read this first if you have seen a different migration number.**
>
> Numbers **v1–v15 are the old history**, which was *redunized* (reset) on
> 17 Sep 2026 — all of it is now **consolidated into v1**, without losing any of
> its functionality. So:
>
> - **v1 today is not the "first" migration chronologically.** It is the
>   consolidation of legacy v1–v15 (v13 = MEeLCoin, v14 = `view_logs` indexes,
>   v15 = the `user_notifications` table — all now covered by v1).
> - Numbers **from v2 up were recounted from zero** after the reset: entries
>   briefly numbered 16/17 (2 Oct) became **v2/v3**, and v4/v5 followed as
>   genuine new migrations.
> - The old history is **no longer used**; `db_version` rows were cleaned to
>   `{1..5}`. The loop only runs versions `> MAX(db_version)`.
>
> If you find docs or code mentioning "Migration v9" or "migration v15" as an
> **active version**, that is a legacy number — not a bug.

**v1 — schema consolidation (result of redunizing legacy v1–v15)**

| What v1 Syncs | Detail |
|---|---|
| FULLTEXT indexes | `video`, `music`, `books` search |
| Performance indexes | `upload_date` on video, music, books, drive_files |
| Foreign keys | `upload_queue`, `transcode_queue`, `drive_files` → `users.id` |
| title type | `VARCHAR(255)` → `TEXT` (video, music, books) |
| activity_log table | Audit trail for user actions |
| UNIQUE KEY | `users.username` (dedup guest accounts) |
| Role column | `varchar(20)` — supports admin, member, user, guest |
| MFA columns | `mfa_secret`, `mfa_backup_codes`, `mfa_enabled` |
| Comments indexes | Composite `(video_id, created_at)` & `(music_id, created_at)` |
| Interactions unique keys | Split into `(user_id, video_id)` & `(user_id, music_id)` |
| MEeLCoin system | `meelcoin` + `meelcoin_last_refill` columns, `site_settings`, `meelcoin_log` tables |
| view_logs indexes | `(video_id)`, `(music_id)` — accelerates `syncViewsFromLogs` |
| user_notifications | Notification system for likes, replies, MEeLCoin, admin chat |

**v2 — MEeLCoin state normalization**

| What v2 Syncs | Detail |
|---|---|
| MEeLCoin upload costs | `meelcoin_upload_cost` & `meelcoin_advanced_cost` normalized to a minimum of `1` (a cost of `0` makes `spend()` deduct nothing) |

**v3 — hot-query indexes + comment FK**

| What v3 Syncs | Detail |
|---|---|
| `activity_log` | `(created_at)`, `(user_id, created_at)`, `(action, created_at)` — admin feed (time-ordered), per-user & per-action filters |
| `upload_queue` | `(status, created_at)` (status polling), `(user_id, media_type, id)` (per-user/media queue listing) |
| `transcode_queue` | `(status)` (polling), `(created_at)` (history) |
| `interactions` | `(video_id, type)`, `(music_id, type)` — like/dislike counts per media |
| `users` | `(role, is_active, last_activity)` (admin listing + idle detection), `(last_activity)` |
| FK `comments.video_id → video.id` | `ON DELETE CASCADE`; orphan rows are cleaned up before the FK is added |
| Dropped redundant indexes | `site_settings.idx_setting_key`, `view_logs.idx_vl_user_video` (duplicates of UNIQUE/PRIMARY) |

**v4 — notification coalescing index**

| What v4 Syncs | Detail |
|---|---|
| `user_notifications` | `idx_un_user_type_created (user_id, type, created_at)` — supports the `like` coalescing lookup and doubles as a covering index for retention pruning. Without it `isCoalesced()` scans every notification row of that user on each like press |

**v5 — `interactions` foreign keys**

| What v5 Syncs | Detail |
|---|---|
| Orphan cleanup | `interactions` rows whose `user_id`/`video_id`/`music_id` no longer exist are deleted first |
| FK `interactions.user_id → users.id` | `ON DELETE CASCADE` |
| FK `interactions.video_id → video.id` | `ON DELETE CASCADE` |
| FK `interactions.music_id → music.id` | `ON DELETE CASCADE` |

**v6 — record MEeLCoin cost in the queues (basis for refunds)**

| What v6 Syncs | Detail |
|---|---|
| `upload_queue` / `transcode_queue` | Columns `coin_cost` (the MEeLCoin actually deducted when the job was created) and `coin_refunded` (0/1, prevents double refunds) |
| Sweeper indexes | `idx_uq_status_created`, `idx_tq_status_created` for finding stuck rows |

> **Why the cost must be stored rather than recomputed?** If the sweeper
> recomputed it from `site_settings`, and an admin changed the price after a
> user uploaded, the refunded amount would be off — the user would get less

The refund itself is one transaction: the queue row is locked with
`FOR UPDATE`, `MeelCoin::refund()` runs, and only then is `coin_refunded`
set to 1. Any failed step rolls the whole thing back, so a user can never
end up with the coin back but the row unmarked, or the row marked without
the coin coming back. `refundQueueRow()` holds the transaction; the lock
and the mark are separate helpers so the sequence stays readable.
> or more than they paid. `coin_cost` records **the amount at transaction
> time**, so the result is always correct.

Migration notes:

- FK installation uses an **existence check** via `information_schema` (`meel_mig_add_fk`) — rejects duplicate FKs, whether same-named or another FK on the same column under a different constraint name.
- The FULLTEXT + `idx_*_upload_date` entries remain in **v1 in addition to** `schema.sql` — idempotent (`has_index`) and supporting old databases that never imported the latest schema; fresh installs only need to import `schema.sql`.

> Fresh installs use `database/schema.sql` (import directly). The migration is for **existing databases** to sync to the latest schema.

> 💡 **Rhythm module (MEeL!Mania) does NOT use the main migration system.** The
> `rooms`, `moves`, `arcade_song`, & `arcade_score` tables come from
> `arcade/schema.sql` + `arcade/migrate.php` (separate extension —
> see [Arcade Collection](#21a-arcade-extension-arcade)).

### 20. MFA System

Multi-Factor Authentication (TOTP) protects user accounts:

| File | Function |
|---|---|
| `auth/mfa_setup.php` | MFA Setup — generate secret, scan QR/barcode, verify TOTP, backup codes |
| `auth/mfa_verify.php` | TOTP verification after login — rate limit 10 failed attempts, lock 5 minutes |
| `admin/mfa_reset.php` | Admin reset MFA for users who lost Authenticator access |
| `controllers/system/mfa.php` | Backend controller — AJAX verify, regenerate backup codes, email backup |

**Flow:** `login.php` → check `mfa_enabled` → redirect `mfa_verify.php` → valid TOTP → set full session

**Helper functions** (in `modules/auth/helpers/mfa.php`):
```php
function generate_mfa_secret(): string;      // Base32 random secret
function generate_totp(string $secret): string;// TOTP 6-digit code
function verify_totp(string $secret, string $code): bool; // Verify with window ±1
function generate_backup_codes(): array;      // 8 backup codes (6 digits, password_hash/bcrypt)
function verify_backup_code(string $stored, string $code): array; // Verify + consume code
```

### 21. Chess Multiplayer (`arcade/chess/`) — Arcade Extension

> ⚠️ **Part of the arcade extension** — not included in MEeL-HUB core. The
> `arcade/` folder is installed separately. See [§21a](#21a-arcade-extension-arcade).

Real-time LAN multiplayer chess:

| File | Function |
|---|---|
| `index.php` | Chess board with drag-and-drop, timer, chat, sound effects |
| `controller/create_room.php` | Create new room, return room code |
| `controller/join_room.php` | Join room with code |
| `controller/get_move.php` | Fetch opponent's move + `opponent_online` flag (polling) |
| `controller/save_move.php` | Save move with legal move validation |
| `controller/check_room_status.php` | Check room status (waiting/playing/ended) |
| `controller/game_action.php` | Resign / draw offer / accept / decline / `disconnect_win` / `game_over` (checkmate & stalemate) |
| `controller/chess_helpers.php` | Shared helper: `chess_opponent_online()` (offline detection) |

**Multiplayer flow (color picker):**

```
Klik "Multiplayer LAN" → konfirmasi SweetAlert
  → overlay "Pilih Warna" (papan disembunyikan & terkunci)
      ├── Putih = createRoom() → state "Menunggu Lawan" + room code
      │        → lawan join → overlay tertutup → polling mulai
      └── Hitam = joinRoom() (prompt kode) → sync papan → overlay tertutup
```

**Disconnect detection:**
- `get_move.php` returns `opponent_online` based on `users.last_activity` (updated on every request by `activity_logger`)
- Offline threshold: `CHESS_OPPONENT_OFFLINE_SECONDS` (default 90s) — above background-tab timer throttling
- `game_action.php` action `disconnect_win`: claim win, **server re-verifies** the opponent is actually offline before recording a `disconnect` terminal event
- `game_action.php` action `game_over`: client records checkmate/stalemate (only detectable client-side) so the GC preserves finished games

**Security guards (all controllers):**
- Login required — JSON response `401` + `login_required: true` (JS `arcade/chess/assets/js/api.js` redirects to login)
- All POST actions require a valid `csrf_token` (403 otherwise)
- CSRF tokens are never stored in `moves.move_data`
- `admin/catur.php?auto_cleanup=1` also requires `csrf_token` (sent by JS via `window.MEEL_ADMIN_CSRF`)

### 21a. Arcade Extension (`arcade/`)

The arcade extension is a **separate module** from MEeL-HUB core. The `arcade/`
folder can be installed or removed without affecting HUB functionality. This
extension manages its own database (`arcade/schema.sql` + `arcade/migrate.php`).

Beyond multiplayer chess, the arcade extension provides **9 games** — 7 static
games (pure HTML/JS, no backend) + Chess (PHP multiplayer) + Rhythm (PHP with
its own DB):

| Game | Folder | Type | Description |
|---|---|---|---|
| Miku & Teto Run | `arcade/dino/` | Static | Endless runner inspired by Chrome Dino |
| Snake | `arcade/snake/` | Static | Classic Snake |
| 2048 | `arcade/2048/` | Static | Tile-merging puzzle |
| Tetris | `arcade/tetris/` | Static | Legendary tetromino game |
| Breakout | `arcade/breakout/` | Static | Bouncing ball & bricks |
| Simon Says | `arcade/simon-says/` | Static | Memory game |
| Ludo | `arcade/ludo/` | Static | 2–4 player board game / vs Bot |
| Chess | `arcade/chess/` | PHP + DB | Real-time LAN multiplayer (see §21) |
| **MEeL!Mania** | `arcade/rhythm/` | PHP + DB | 4-lane rhythm game inspired by osu!mania |

**Rhythm module (`arcade/rhythm/`):**

| File | Function |
|---|---|
| `index.php` | Lobby — song list (builtin + custom), filter/sort/search |
| `game.php` | 4-lane gameplay — A/S/K/L or touch, 4 speed levels |
| `editor/` | Beatmap editor — create/save beatmaps in the browser (localStorage) |
| `manage/` | Custom song management (list, edit, delete) |
| `api/songs.php` | GET — song list (builtin + custom, sort/filter/search, limit 100) |
| `api/beatmap.php` | GET — fetch beatmap per song (builtin via slug, custom via numeric ID; increments `play_count`) |
| `api/upload.php` | POST — upload custom song (auth + CSRF; non-admin 10/hour; MP3/OGG/OPUS/FLAC/WAV ≤ 20MB & ≤ 5 min; beatmap 10–5000 notes; FLAC auto-transcoded to Opus; cover → WebP) |
| `api/delete.php` | POST — delete custom song (owner/admin only) |
| `migration.sql` | **Extension DB tables** — `rooms`, `moves`, `arcade_song` & `arcade_score` |

> ⚠️ **Installation:** run the arcade migration once:
> `php arcade/migrate.php` — not part of `database/schema.sql`
> (23 tables) nor `database/migrate.php`. Alternatively,
> `install.sh` offers interactive arcade installation.

### 22. `modules/core/Router.php` — front controller

`router.php` resolves the request path and hands it to `MeelRouter::dispatch()`.
The route table is two constants: `ROUTES` for core pages and
`OPTIONAL_ROUTES` for modules that may be switched off (`arcade`).

`dispatch()` used to hold every branch in one 50-line method, with an `exit`
in almost every one of them. It now reads as a short sequence of decisions,
each one in a named place:

| Step | Where | What it decides |
|---|---|---|
| Profile alias | `profileAliasRedirect()` | `/profile?u=<name>` and `/profile/<name>/<tab>` both point at `/profile/<name>` with the tab as a query parameter. Pure — returns the target URL or `null` |
| Route lookup | `routeFor()` | exact table first, then optional routes, then the slug patterns (`music/<slug>`, `profile/<name>`, `admin/chat/<name>`) |
| Optional guard | `guardOptionalRoute()` | a disabled page module redirects to the hub, a disabled API module answers JSON 404 |
| Handler run | `runHandler()` | sets `SCRIPT_NAME`, moves to the handler's directory so its relative paths work, then includes it |

`profileAliasRedirect()` being pure is the point: the redirect rules can be
tested without sending a request. `RouterDispatchTest` does exactly that,
and also checks that every route in both tables points at a file that
actually exists — a route pointing at a missing file fails the whole page
with a 500 that says nothing about the cause.

One such route existed. `/arcade/rhythm/editor` pointed at
`arcade/rhythm/editor/index.php`, a directory that has not existed since the
editor moved into `arcade/rhythm/manage/`. Requesting it produced
"500 — Gagal mengubah working directory." It now points at
`arcade/rhythm/manage/edit.php`, the same file the two other editor URLs
already reach.

### 23. `modules/auth/` and the shared config

`auth/config.php` is included by every page: it loads the credentials from
`auth/settings.php`, opens the database connection, starts the session and
sets the security headers.

Its failure paths used to report through `fwrite(STDERR, ...)`. `STDERR` only
exists in the CLI and CGI SAPIs — under Apache it does not exist, so a failed
database connection turned into "Undefined constant STDERR", a fatal that
hid the message it was trying to deliver. The file now uses `error_log()`,
which works in every SAPI and still writes to stderr under the CLI.

`WebSafeErrorOutputTest` keeps that from coming back: a file may only use
`STDERR`, `STDOUT` or `STDIN` if it lives in a CLI tooling directory, if it
guards itself with a `PHP_SAPI` check that stops execution, or if nothing
includes it.

### Admin Media Editor (music + video)

`admin/edit-music.php` and `admin/edit-video.php` used to be copies of each
other: about 50% of their PHP logic was identical, including the thumbnail
upload block with its MIME allowlist, size limit and ffmpeg call. The
`codebase-memory` graph showed the two files changing together 20 times
(coupling 0.87) — the signature of a copy that should have been shared.

The shared parts now live in one place:

| File | Responsibility |
|---|---|
| `admin/includes/media_editor.php` | `meel_editor_media_config()` — per-type labels, default filenames and error wording; `meel_editor_context_redirect()` — the admin/profile redirect; `meel_editor_upload_thumbnail()` — the whole upload path |
| `admin/partials/edit_media_uploader_card.php` | the "uploaded by" card in the sidebar |
| `admin/partials/edit_media_stats.php` | the views/likes/dislikes strip |
| `admin/edit-music.php` | the music-specific parts: artist, album, lyrics section, the database write |
| `admin/edit-video.php` | the video-specific parts: subtitle upload, the database write |

`admin/edit-music.php` went from 427 to 357 lines and `admin/edit-video.php`
from 468 to 398. The shell call to ffmpeg moved from two files into one, so
there is now a single audited place where it happens.

One difference is deliberately preserved. When the thumbnail upload fails, the
music page still saves the title and description, while the video page saves
nothing. That asymmetry came from the two copies drifting apart over time; it
was not introduced by the merge, and changing it would be a product decision
rather than a refactor. `AdminEditorSharedTest` pins it so it cannot change
by accident.

### Admin repositories — shared base

`AdminActivityRepository` and `AdminUploadQueueRepository` back the two tabs of
the admin activity log viewer. Their queries used to be copies of each other:
the same time filter, the same search, the same count, the same paging, the
same export and the same purge. Only the table name, its alias and the two
filtered columns actually differed — and a copy that is one query change
behind silently keeps serving stale results.

The shared queries now live in `modules/media/AdminFilteredRepository.php`.
Each subclass supplies what genuinely differs:

| Subclass | Table | Alias | Primary filter | Search columns |
|---|---|---|---|---|
| `AdminActivityRepository` | `activity_log` | `al` | `action` | `u.username`, `al.ip_address` |
| `AdminUploadQueueRepository` | `upload_queue` | `uq` | `status` (limited to `processing`, `completed`, `failed`) | `u.username`, `uq.url` |

The status limit matches the four options the admin UI offers; `pending` is
deliberately not one of them. A status outside that list means "no status
filter", not "no rows" — filtering by an unknown value would otherwise make
the tab look empty for no visible reason.

What stays in each subclass: `getDistinctActions()`, `getWeeklyStats()` and
`clearAll()` for the activity log; `getStats()` for the queue.

`AdminFilteredRepositoryTest` and
`AdminFilteredRepositoryIntegrationTest` pin this: the shared queries must not
be copied back into a subclass, each repository must keep pointing at its own
table, and the filter behaviour of the two must stay different.

### Admin Activity Log Viewer

`admin/activity_log.php` — audit trail viewer with 3 tabs. The entry file holds
the request handling (324 lines); each tab's markup lives in its own partial
under `admin/partials/`:

| File | Content |
|---|---|
| `admin/activity_log.php` | POST handlers, export, tab dispatch — 402 lines |
| `admin/partials/activity_log_log.php` | the activity tab |
| `admin/partials/activity_log_views.php` | the view-stats tab |
| `admin/partials/activity_log_uploads.php` | the upload-queue tab |

The partials sit in a web-reachable directory, so each one returns 403 instead
of running without a database connection. `admin/partials/live-monitor-manage.php`
is the opposite case: it is fetched over AJAX, so it bootstraps itself and
checks the admin role.

**Activity Tab** (blue-600 theme):
- Filter by action type, username/IP, date range
- Pagination (50/page)
- Stats cards (7-day activity, unique users, total entries)
- Color-coded action badges (login=blue, upload=green, ban=red)
- Manual log cleanup (7–365 days) with CSRF

**Admin Actions Tab** (purple-600 theme):
- Filter by admin username, action type, date range
- Stats cards (7-day admin actions, unique admins, total entries)
- Color-coded badges (coin=yellow, reset=red, login=blue, other=gray)
- Maintenance: clear older than 7–365 days

**Upload Queue Tab** (green-600 theme):
- Filter by status (`processing`/`completed`/`failed` — matching the `upload_queue.status` enum), uploader, date range
- Stats cards (total uploads, completed, failed, active)
- Color-coded status badges
- Export CSV/JSON/XLS with preview modal
- Maintenance: clear completed/failed older than 7–365 days

> **Note:** Admin users are excluded from the MEeLCoin manual adjustment dropdown
> (`WHERE role NOT IN ('guest', 'admin')`) — admin balance is managed via auto-refill
> and upload costs only.

### 22. PWA Service Worker (`sw.js.php` + `modules/core/SwPrecache.php`)

The service worker is **generated dynamically by PHP** — see the full guide in
[`pwa.md`](pwa.md).

| Component | Role |
|---|---|
| `modules/core/SwPrecache.php` | `baseAssets()` + `moduleAssets()` (all `assets/css/*/manifest.php`) → `all()`; `version()` = content hash → auto SW update |
| `sw.js.php` | Full SW script, `Content-Type: application/javascript`, deterministic output |
| `.htaccess` | `RewriteRule ^sw\.js$ sw.js.php [L]` — URL `/sw.js` preserved |

Adding a new module folder (`assets/css/<folder>/manifest.php`) automatically
adds its CSS to the precache — **no manual SW changes needed**.

### 23. Theme System (`assets/css/shared/theme-tokens.css` + `light-theme.css` + `assets/js/shared/theme.js`)

Light/dark mode system with CSS variables and JavaScript toggle.

| Component | Role |
|---|---|
| `assets/css/shared/theme-tokens.css` | CSS variables for dark mode (default) — `--meel-bg`, `--meel-surface`, `--meel-text`, etc. |
| `assets/css/shared/light-theme.css` | Override Tailwind utilities when `html[data-theme="light"]` — 500+ lines of overrides |
| `assets/js/shared/theme.js` | `MEELTheme` — toggle manager, localStorage + DB sync, smooth transition |
| `controllers/api/theme.php` | REST API (GET/POST) for theme preference |
| `database/schema.sql` | `custom_theme` column in `users` table |

**Architecture:**
```
theme-tokens.css (variables)
       ↓
light-theme.css (overrides when data-theme="light")
       ↓
theme.js (toggle + persist)
       ↓
localStorage (source of truth, anti-flash, guest-only)
+ DB custom_theme (sync for logged-in users)
```

**Guest behavior:** Theme preference is stored in `localStorage` only (no DB write). `MEELTheme.init({ isLoggedIn: false })` → `toggle()` saves to localStorage without calling the API.

**Logged-in behavior:** Theme is saved to both `localStorage` and `users.custom_theme` (DB). On page load, localStorage is applied first (anti-flash), then synced with DB if different.

### 24. Profile Module (`profile/index.php` + `controllers/profile/`)

User profile page with role-based visibility, theme toggle, and public channel grid.

| Component | Role |
|---|---|
| `profile/index.php` | Profile page — displays avatar, bio, stats, action buttons, content channel grid |
| `profile/channel_more.php` | HTMX fragment for infinite scroll load-more on profile channel (auto-load sentinel) |
| `profile/content_card.php` | Channel content card component, shared by `index.php` and `channel_more.php` |
| `controllers/profile/profile_edit.php` | Profile edit handler |
| `controllers/profile/manage.php` | Content management (video/music) |
| `modules/media/ProfileRepository.php` | Profile data queries (count video, music, paginated feed) |

**Key variables:**
- `$is_logged_in` — whether the visitor has an active session
- `$is_guest_profile` — whether the profile being viewed is the synthetic "Guest" profile
- `$is_owner` — whether the visitor is viewing their own profile
- `$active_tab` — content filter tab (`all`, `video`, `music`)

**Visibility rules:**

| Element | Owner | Visitor (logged-in) | Guest |
|---|:---:|:---:|:---:|
| Edit Profile, Kelola Konten, MFA | ✅ | ❌ | ❌ |
| Theme Toggle | ✅ | ❌ | ✅ (own profile only) |
| Upload Stats | ✅ | ✅ | ❌ |
| Channel Tabs (All/Video/Music) | ✅ | ✅ | ❌ |
| Content Grid + Load More | ✅ | ✅ | ❌ |
| Bio | from DB | from DB | "Akun Guest" |
| Badge | Staff/Member | Staff/Member | Guest |

**Profile as Channel:** The profile page doubles as a public channel. For logged-in users, it renders a content grid with initial batch of 12 items. HTMX-powered infinite scroll loads more via `profile/channel-more` — the `#channel-more-area` sentinel uses `hx-trigger=revealed`, so the next batch loads on its own when it enters the viewport (retry via `assets/js/shared/sentinel-retry.js`, end box once content runs out). Cards render through the `profile/content_card.php` partial. Guest profiles only show the profile card — tabs and content grid are hidden.

**Canonical redirects:**
- `profile/?u=X` → 301 → `profile/X`
- `profile/<user>/<all|video|music>` → 301 → `profile/<user>?tab=<type>`

**Guest profile access:** Guests can view any user's profile (including their own synthetic Guest profile). The Guest profile is constructed in-memory (no DB query) with `id=0`, `role='guest'`.

**Session initialization:** Uses `meel_boot_session()` (not raw `session_start()`) to ensure the session cookie name matches the rest of the application (`meel`).

### 25. Notification Module (`modules/core/Notification.php` + `controllers/api/notification.php`)

Database-backed notification system with user scoping and actor tracking.

**Database:** `user_notifications` table (part of migration **v1** — formerly legacy v15, now consolidated)

```php
class Notification {
    public static function create(mysqli $conn, int $userId, string $type, string $title, string $message, ?int $relatedId = null, ?string $relatedSlug = null, ?int $actorUserId = null): void;
    public static function getUnreadCount(mysqli $conn, int $userId): int;
    public static function getList(mysqli $conn, int $userId, int $limit = 20): array;
    public static function markRead(mysqli $conn, int $notifId, int $userId): void;
    public static function markAllRead(mysqli $conn, int $userId): void;
    public static function deleteOne(mysqli $conn, int $notifId, int $userId): bool;
    public static function deleteAllByUser(mysqli $conn, int $userId): bool;
    public static function deleteByChat(mysqli $conn, int $userId, string $message, int $actorUserId): bool;
}
```

**Notification types:** `like`, `reply`, `admin_chat`

**Link generation:** The `related_slug` column stores the media type (`video`/`music`) or a compound key (`type:id` for replies). The notification page builds links dynamically using `meel_base_url_path()` prefix.

**API:** `controllers/api/notification.php` — POST-only for state-changing actions (mark_read, delete, delete_all) with CSRF verification. Read-only actions (unread_count, list) accept GET.

---

### 26. MEeLCoin System (`modules/core/MeelCoin.php`)

Internal currency that limits uploads per user. Every balance change uses **a single `UPDATE` with a balance guard** — no read-modify-write — so a deduction can no longer be overwritten by a concurrent request.

**Database:** `users.meelcoin` & `users.meelcoin_last_refill` columns, `site_settings` table (`meelcoin_*` keys), `meelcoin_log` table (credit/debit audit trail).

```php
class MeelCoin {
    public static function spend(mysqli $conn, int $userId, int $amount, string $reason): array; // [ok, error]
    public static function refund(mysqli $conn, int $userId, int $amount, string $reason): bool;
    public static function refill(mysqli $conn, int $userId, string $role): bool;
    public static function getRefillCountdown(mysqli $conn, int $userId, string $role): int;     // 0 = ready to refill
}
```

| Rule | Implementation |
|---|---|
| Spend | `SET meelcoin = meelcoin - ? WHERE id = ? AND meelcoin >= ?` — 0 affected rows = insufficient balance |
| Refund | `SET meelcoin = meelcoin + ?` (added on top of the current DB value) |
| Refill | Per-user cycle: the grant is capped by `LEAST(max, meelcoin + refill)` in the DB. The refill window starts when the balance **drops below the maximum** (that is, at the moment of the spend), so a refill can neither pile up nor mask the next upload's deduction |
| Countdown | Derived from that user's `meelcoin_last_refill`, measured with `TIMESTAMPDIFF(..., NOW())` — the database clock on both sides, so the value cannot drift with the server's timezone. `0` = ready to refill |
| Cost | Minimum 1 (enforced in the admin panel). Cost ≤ 0 → the coin flow is skipped at runtime |
| Admin | Excluded from deductions (`$is_admin`) and from the manual adjustment dropdown |
| `upload_advanced.php` refund | Only for explicit failure sentinels (`''`, `DISCONNECTED`, `Download gagal*`, `File audio tidak ditemukan*`); unrecognized results are not refunded and go to `error_log` |
| Failed-queue refund | Runs **at the moment of failure**: `modules/core/helpers/upload.php` (`upload_failed_refund` — download/upload) and `transcode.php` (`transcode_refund` — transcode). Idempotent via a unique `reason` |

**Queue status & PID (`modules/transcoder/DownloadService.php`):**

- Status is written honestly by `releaseQueue()` **after** `finalizeVideo()`/`finalizeMusic()` succeeds (`DONE:…` / `ENCODE_MUSIC:…` → `completed`; anything else → `failed`). The `upload_queue.status` enum only holds `processing/completed/failed` — there is no `orphaned` value.
- The `QueueReconciler` class was **removed**: zero production callers, `checkDownloadedFile()` ignored its arguments (marking a failed queue as `completed` based on anyone's temp file), and it wrote/queried the out-of-enum `orphaned` status. There is no global sweeper for stale `processing` queues — if needed, build a CLI job (planned T16/T17) instead of guessing from temp files.
- PID files use taskType `transcode_dl_{upload_queue_id}` (not `transcode`): `transcode` is used by `TranscodeService` with ids from `transcode_queue` — different tables → different id ranges → key collisions if the name were shared. The real `queue_id` (not `0`) keeps parallel jobs from overwriting each other's PID files.

**UI sync:** `meelRefreshCoinBalance()` (`assets/js/engine/result.js`) refreshes the `#coin-balance` element after the `meelDone`/`meelError` overlay — the page itself is not re-rendered by the overlay.

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

#### Video cards (`video/cards.php`)

One file, two functions, and they **cannot be swapped**:

| Function | Used in |
|---|---|
| `meel_render_video_card()` | the grid card on the video home/library |
| `meel_render_video_rec_card()` | the recommendation sidebar card in `video/watch.php` |

The pattern deliberately mirrors `music/cards.php`. The sidebar markup used to
be copied verbatim in two places (`video/watch.php` and
`video/search_video.php`), and two separate files with an `include` only move
the implicit "fill this variable first before including" contract somewhere
else — the variable still leaks into the caller's scope. A function makes the
contract explicit through its arguments.

Two data sources give the same username different column names:
`MediaViewer::getRecommendations()` selects `u.username AS uploader`, while
`MediaLibrary::searchVideo()` selects `u.username AS uploader_name`. Both are
`users.username`, so both are read here.

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

## Authentication Flow

```
Request → auth.php
  ↓
Session exists? → No → Redirect to login.php
  ↓ Yes
Validate last_session_id
  ↓
Different? → Yes → Session Destroy → Redirect to /err/?code=revoked
  ↓ No
Update last_activity
  ↓
Continue to requested page
```

### Login Flow

```
POST login
  ↓
Verify CSRF token
  ↓
Validate username & password
  ↓
Failed 5x? → Lock 5 minutes
  ↓ Success
Check MFA (mfa_enabled)
  ↓
Active? → Save mfa_temp_uid → Redirect to mfa_verify.php
  ↓ No
Set session variables (user_id, username, role)
  ↓
Update last_session_id
  ↓
Redirect to index.php
```

### MFA Verification Flow

```
POST mfa_verify.php
  ↓
Rate limit: max 10 failures, lock 5 minutes
  ↓
Verify TOTP 6-digit code
  ↓
Failed? → Increment fail count
  ↓ Valid
Set full session (user_id, username, role)
  ↓
Set mfa_verified = true
  ↓
Remove mfa_temp_uid from session
  ↓
Redirect to index.php
```

---

## 26. JS/CSS Modularization

MEeL uses a modular approach for JavaScript and CSS — each module has separate files that are loaded dynamically.

### Directory Structure

```
assets/
├── css/
│   ├── video/           # Video module CSS (13 files)
│   │   ├── autonext.css   # Auto-next overlay
│   │   ├── base.css     # Base styles
│   │   ├── cards.css    # Video card styles
│   │   ├── fullscreen.css # Fullscreen player
│   │   ├── glow.css     # Ambient glow effect
│   │   ├── layout.css   # Player layout
│   │   ├── mini-player.css # Floating mini player
│   │   ├── navbar.css   # Video navbar
│   │   ├── player.css   # Plyr overrides
│   │   ├── resume-modal.css # Resume playback modal
│   │   ├── seek.css     # Seek indicator
│   │   ├── toast.css    # Toast notifications
│   │   ├── utility.css  # Utility classes
│   │   └── watch/       # Watch page specific
│   ├── profile/         # Profile module CSS (10 files)
│   │   ├── base.css     # Base profile styles
│   │   ├── cards.css    # Media cards
│   │   ├── coin.css     # MEeLCoin display
│   │   ├── edit.css     # Edit profile
│   │   ├── manage.css   # Profile management
│   │   ├── notification.css # Notification settings
│   │   ├── stat.css     # Statistics
│   │   ├── mfa-switch.css # MFA toggle
│   │   ├── type-badge.css # User role badges
│   │   └── empty-state.css # Empty state displays
│   ├── admin/           # Admin module CSS (6 files)
│   │   ├── activity_log.css # Activity log viewer
│   │   ├── chat.css     # Admin chat styles
│   │   ├── catur.css    # Chess admin
│   │   ├── index.css    # Admin dashboard
│   │   ├── mfa_reset.css # MFA reset page
│   │   └── stats.css    # Statistics page
│   ├── music/           # Music module CSS (10 files)
│   │   ├── base.css     # Base music styles
│   │   ├── cards.css    # Music card styles
│   │   ├── layout.css   # Music layout
│   │   ├── mini-player.css # Music mini player
│   │   ├── player.css   # Music player
│   │   ├── playlist.css # Playlist styles
│   │   ├── playlist-modal.css # Playlist modal
│   │   ├── resume-modal.css # Resume playback modal
│   │   ├── utility.css  # Utility classes
│   │   └── visualizer.css # Audio visualizer
│   ├── books/           # Books module CSS (6 files)
│   │   ├── base.css     # Base books styles
│   │   ├── cards.css    # Book card styles
│   │   ├── manga.css    # Manga reader
│   │   ├── pdf.css      # PDF reader
│   │   ├── reader.css   # Book reader base
│   │   └── utility.css  # Utility classes
│   └── shared/          # Shared CSS (7 files)
│       ├── comment.css  # Comment section
│       ├── design-tokens.css # Design tokens
│       ├── light-theme.css  # Light mode overrides
│       ├── nav.css      # Navigation bar
│       ├── notification.css # Notification styles
│       ├── theme-tokens.css # Theme CSS variables
│       └── upload-form.css  # Upload form styles
├── js/
│   ├── video/watch/     # Video watch page JS (12 files)
│   │   ├── state.js     # Global state variables
│   │   ├── lifecycle.js # Page lifecycle management
│   │   ├── player-init.js # Player initialization
│   │   ├── player-events.js # Player event handlers + aspect ratio
│   │   ├── recovery.js  # Error recovery & stuck detector
│   │   ├── mini-player.js # Floating mini player
│   │   ├── gestures.js  # Touch gestures
│   │   ├── search.js    # Search functionality
│   │   ├── seek-indicator.js # Seek visual feedback
│   │   ├── vtt-sprites.js # VTT sprite thumbnails
│   │   └── misc.js      # Miscellaneous utilities
│   ├── shared/          # Shared JS (21 files)
│   │   ├── nav.js       # Navigation behavior
│   │   ├── theme.js     # Theme toggle
│   │   ├── keyboard.js  # Keyboard shortcuts
│   │   ├── comment.js   # Comment section
│   │   ├── notification.js # Notification system
│   │   ├── plyr-config.js # Plyr configuration
│   │   ├── format-time.js # Time formatting
│   │   ├── resume-modal.js # Resume playback modal
│   │   ├── recovery-manager.js # Recovery factory: stuck detector, waiting timeout, reconnect overlay
│   │   ├── media-session.js # Media Session API: OS media controls artwork/metadata
│   │   ├── index-hub.js # Homepage hub
│   │   └── ...          # Other shared utilities
│   ├── profile/         # Profile JS (5 files)
│   │   ├── manage.js    # Profile management
│   │   ├── avatar-crop.js # Avatar cropping
│   │   ├── coin-countdown.js # MEeLCoin countdown
│   │   └── theme-init.js # Theme initialization
│   ├── admin/           # Admin JS (3 files)
│   │   ├── activity_log.js # Activity log viewer
│   │   └── chat/        # Admin chat
│   ├── music/           # Music JS
│   │   └── watch/       # Music watch page JS
│   │       ├── player-core.js # Music player core (visualizer, EQ, Media Session)
│   │       ├── description-toggle.js # "Selengkapnya" toggle for music description
│   │       └── ...
│       └── read/reader.js # Book reader
```

### CSS Mapping per Module

Each CSS module has a `manifest.php` that registers its CSS files. `SwPrecache` reads these manifests to automatically generate service worker precache lists.

```php
// assets/css/video/manifest.php
return ['base.css', 'cards.css', 'fullscreen.css', 'glow.css', 'layout.css',
        'mini-player.css', 'navbar.css', 'player.css', 'seek.css', 'toast.css'];
```

#### Per-module CSS bundle

`scripts/build-css-bundle.php` merges the files listed in `manifest.php` into
a single `assets/css/<module>/bundle.css`.

- **The manifest stays the source of truth.** One truth; the original files
  are not deleted, so pages that do not use the bundle yet keep working.
- **The file order is preserved exactly** as in the manifest, because CSS
  order decides the cascade — `light-theme.css` has to stay last.
- **The bundle is not committed.** `assets/css/*/bundle.css` is in
  `.gitignore`, so a fresh clone falls back to the individual file list.

```bash
php scripts/build-css-bundle.php --dry-run   # show the result without writing
php scripts/build-css-bundle.php
```

### Dynamic Loader

JavaScript is loaded dynamically by `main.js` on each module page. JS files are loaded sequentially according to dependencies:

```javascript
// Example: video/watch/main.js loads scripts sequentially
const scripts = [
    'state.js', 'recovery.js', 'player-init.js', 'player-events.js',
    'lifecycle.js', 'mini-player.js', 'gestures.js', 'vtt-sprites.js',
    'seek-indicator.js', 'misc.js'
];
```

Music watch pages also use a view-router (`shared/view-router.js`) for SPA navigation. Scripts listed in `DIRECT_SCRIPTS` (e.g., `recovery-manager.js`, `description-toggle.js`) are loaded via `loadScriptOnce()` during SPA transitions — ensuring globals like `meelCreateReconnectOverlay` are available when `player-core.js` executes.

---

## 27. Adaptive Aspect Ratio Player

MEeL's video player supports adaptive aspect ratio for various video formats (4:3, 16:9, 21:9, portrait).

### How It Works

1. **Placeholder:** Server renders wrapper with `style="aspect-ratio: 16/9;"` as default
2. **Runtime:** When video metadata loads, JavaScript `applyMeelVideoAspect()` changes aspect ratio to match actual dimensions
3. **Constraint:** Non-16:9 videos get proportional `max-width` to equalize height with 16:9

### `applyMeelVideoAspect(wrapper, videoW, videoH)` Logic

| Condition | Behavior |
|---|---|
| Portrait (videoW < videoH) | `max-height: 80vh`, width auto, center horizontally |
| Landscape < 16:9 (4:3, 5:4, 1:1) | `max-width: calc(100% × 9 × videoW / (16 × videoH))`, center |
| 16:9 or wider (21:9) | Full width, natural height |

### Calculation Example for 4:3

```
max-width = calc(100% × 9 × 4 / (16 × 3))
         = calc(100% × 36/48)
         = 75% of parent width
```

At 1000px parent width:
- **4:3:** 750px × 562.5px (centered)
- **16:9:** 1000px × 562.5px (full width)

Both have the same height — the 4:3 video is smaller and centered, just like YouTube.

### CSS Support

```css
/* player.css — prevents stretching */
.plyr__video-wrapper video {
    object-fit: contain;
}
```

### Related Files

| File | Role |
|---|---|
| `assets/js/video/watch/player-events.js` | `applyMeelVideoAspect()` function |
| `assets/css/video/player.css` | `object-fit: contain` for video |
| `assets/css/video/watch/main.css` | Mobile max-height constraint |

---

## 28. Chat API

Real-time chat system between users with HTMX polling.

### Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/chat` | GET | Get chat messages (params: `after_id`, `limit`) |
| `/api/chat` | POST | Send chat message (params: `message`, `csrf_token`) |

### Database Schema

```sql
CREATE TABLE chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

### Features

- Messages ordered by `created_at` ASC
- HTMX polling every 3 seconds for new messages
- Delete own messages (with ownership validation)
- `admin_chat` notification type for messages to admin
- Rate limiting: 30 messages per minute per user

### Related Files

| File | Role |
|---|---|
| `controllers/api/chat.php` | Chat API endpoint |
| `admin/chat.php` | Admin chat interface |
| `assets/js/shared/notification.js` | Notification polling including chat |
| `assets/css/admin/chat.css` | Admin chat styles |

---

## ProgressObserver Architecture

`Transcoder` is a **pure business-layer** class — it never echoes HTML/JS. Progress
is reported as structured events to a `ProgressObserver`, letting the same engine
run cleanly in browsers, CLI scripts, cron jobs, or API endpoints without polluting
output buffers.

### Files

| File | Role |
|---|---|
| `modules/core/ProgressObserver.php` | `ProgressObserver` interface + `CallableProgressObserver` adapter |
| `modules/core/BrowserProgressObserver.php` | Browser presenter: maps events to the MEeL overlay (`partials/ui.php`) + `meel*` JS calls |

### Usage

```php
// CLI / cron / API — no output at all
$tc = new Transcoder($conn, $uid);

// Browser streaming overlay (upload_advanced.php, transcode.php)
require_once 'modules/core/BrowserProgressObserver.php';
$tc = new Transcoder($conn, $uid, new BrowserProgressObserver());

// Custom observer or raw callable
$tc = new Transcoder($conn, $uid, function (string $stage, array $data): void {
    fwrite(STDERR, "[{$stage}] " . json_encode($data) . PHP_EOL);
});
```

### Event contract — `ProgressObserver::onProgress(string $stage, array $data)`

| Stage | Payload | Meaning |
|---|---|---|
| `download_start` | `['url' => string]` | Download begins (overlay injection point) |
| `transcode_start` | `[]` | Transcode begins (overlay injection point) |
| `phase` | `['phase' => string]` | Overlay phase switch (`transcode`, `sprite`, ...) |
| `download_progress` | `['pct' => int]` + optional `eta`, `speed`, `size`, `frag` | yt-dlp progress |
| `transcode_progress` | `['pct' => int, 'label' => ?string]` | FFmpeg progress |
| `sprite_progress` | `['pct' => int, 'label' => ?string]` | Sprite/VTT progress |
| `done` | `['title' => string, 'url' => string]` | Video finalized |
| `done_transcode` | `['title' => string, 'download_link' => string]` | Audio transcode complete |
| `error` | `['message' => string]` | Fatal error shown to the user |

There is no `redirect` stage. An older version of the observer had an empty
`redirect` case for browser navigation, but nothing ever emitted it — music
navigation goes through the `REDIRECT:`-prefixed message string handled in
`upload_advanced.php`, not through a progress event. The case was removed.

Each stage is handled by its own private method, reached through the
`BrowserProgressObserver::STAGE_HANDLERS` map. `onProgress()` only looks the
handler up, so a stage that is emitted but not mapped would be silently
dropped; `BrowserProgressObserverTest` reads the transcoder sources and
asserts that every stage they emit has a handler.

**Guarantees:**
- An observer exception is caught and logged inside `emit()` — it never propagates
  into the media pipeline (no orphaned processes or half-moved files).
- With no observer attached, `emit()` is a no-op — zero output-buffer pollution
- Music downloads return a `REDIRECT:`-prefixed string so the *caller* decides how
  to continue (no `exit` call in the business layer).

---

## Filesystem Safety Convention (no @ suppression)

Following the media-processing engine audit, the codebase **never uses the `@`
error-suppression operator on filesystem operations** (`unlink`, `rmdir`, `mkdir`,
`copy`, `rename`, `fopen`, `scandir`, `file_put_contents`, ...). A blanket `@`
hides real permission/IO failures — e.g. a USB HDD mounted read-only, or a temp
folder owned by another process — and makes debugging impossible.

Every filesystem access follows three rules:

1. **Proactive existence & permission checks** — guard with `is_file()`, `is_dir()`,
   `is_readable()`, `is_writable()` before touching the filesystem. Remember that
   `unlink`/`rmdir` require a writable **parent** directory, not just an existing file.
2. **Return-value checks** — treat `false`/`null` returns as failures and log them
   via `error_log()` (or a dedicated logger) including the path involved.
3. **Shared helpers instead of inline `@`** — reuse the centralized helpers below
   rather than scattering ad-hoc suppression.

### Shared filesystem helpers

| Helper | Location | Purpose |
|---|---|---|
| `ensureDir()` | `FfmpegUtils` trait | `mkdir -p`-style creation with logging |
| `removeFile()` | `FfmpegUtils` trait | Guarded unlink (existence + writable parent) |
| `removeDir()` | `FfmpegUtils` trait | Flat-dir cleanup (glob → removeFile → rmdir) |
| `moveFile()` | `FfmpegUtils` trait | Cross-device move with `stat()` device check |
| `GarbageCollector::removeFile()` | `GarbageCollector.php` | Static guarded unlink |
| `GarbageCollector::removeDirectory()` | `GarbageCollector.php` | Recursive guarded cleanup (skips non-writable subtrees, `rmdir` only when empty) |
| `meel_write_cache_file()` | `helpers/storage.php` | **Atomic** cache write (temp file + `rename()`), not `LOCK_EX` |
| `meel_read_cache_file()` | `helpers/storage.php` | Read JSON cache + check TTL + required keys (the only implementation in the repo) |
| `meel_sweep_stale_cache_tmp()` | `helpers/storage.php` | Drop leftover `.tmp.*` files from processes that died mid-write |

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
