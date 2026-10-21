# MEeL Download/Upload Troubleshooting Guide

> **Audience:** whoever has to diagnose a download that does not start — developers, operators, and AI agents.
> **Project:** MEeL — PHP media hub (XAMPP, yt-dlp, FFmpeg).
> **Typical issue:** "`upload_advanced.php` does not download the video".

---

## 1. 🧠 Understand the Download Flow

```
upload_advanced.php (form POST)
  → Transcoder::processDownload($url, $type)
    → fetchMetadata()          [yt-dlp --skip-download --print-json]
    → emit('download_start')   [BrowserProgressObserver → inject overlay partials/ui.php]
    → proc_open("exec setsid timeout 900 sh -c '<export …; yt-dlp …>'")
                               [PID/PGID tracked; the sh -c wrapper is REQUIRED — exec
                                replaces the shell instantly, so without it the export
                                prefix and the yt-dlp command would never run]
    → finalizeVideo()          [HLS transcode + sprite + move to HDD — transactional, auto-rollback]
      → emit('done')           [BrowserProgressObserver → meelDone() JS]
```

**Key classes:** `modules/core/Transcoder.php` (facade → `modules/transcoder/`: `DownloadService`, `EncodeService`, `TranscodeService` — pure business logic), `modules/core/BrowserProgressObserver.php` (presentation layer), `modules/core/Uploader.php`.
**Key dependencies:** yt-dlp, FFmpeg, FFprobe, node, `temp/cookies.txt`.

---

## 2. 🔴 Most Common Bug: Wrong `base_path`

### Symptom

- The download overlay never appears
- No progress is shown and the page seems to do nothing
- Or a console error: `meelError is not defined`

### Root cause

Files in `modules/core/` use `dirname(__DIR__)`, which resolves to `.../modules/` — not the project root.

### Fix

Use `dirname(__DIR__, 2)` in files inside `modules/core/`.

```php
// WRONG (modules/transcoder/DownloadService.php)
$this->base_path = dirname(__DIR__);    // → .../MEeL/modules/

// CORRECT
$this->base_path = dirname(__DIR__, 2); // → .../MEeL/
```

### What it affects when `base_path` is wrong

| Path used | Correct location | Wrong location |
|---|---|---|
| `cookies.txt` | `.../MEeL/temp/cookies.txt` | `.../MEeL/modules/cookies.txt` |
| `partials/ui.php` | `.../MEeL/partials/ui.php` | `.../MEeL/modules/partials/ui.php` |
| `music/upload/file/` | `.../MEeL/music/upload/file/` | `.../MEeL/modules/music/upload/file/` |

### 🔍 How to check

```bash
php -r "echo dirname(__DIR__);" 2>/dev/null   # Test from modules/core/
```

Files in `modules/core/` need `dirname(__DIR__, 2)`. Files in `auth/`, `drive/`, `partials/`, and `tests/` need only `dirname(__DIR__)`.

---

## 3. 🔧 Systematic Diagnostic Steps

### Step 1 — Environment basics

```bash
which yt-dlp          # Must be installed
which ffmpeg          # Must be installed
which ffprobe         # Must be installed
which node            # Must be installed (yt-dlp JS runtime)
ls -la temp/cookies.txt   # Must exist in temp/ (the root copy is auto-migrated)
php -v                # Must be 7.0+ (for dirname($path, 2))
```

### Step 2 — PHP functions

```bash
php -r "echo function_exists('proc_open') ? 'YES' : 'NO';"
php -r "echo function_exists('posix_kill') ? 'YES' : 'NO';"   # posix extension
php -r "echo ini_get('disable_functions') ?: 'none';"
```

`proc_open` and `proc_close` must NOT be disabled (they are called with array arguments and env vars, no shell). `posix_kill()` is used for precise termination (SIGTERM → grace → SIGKILL); without the `posix` extension the fallback `kill -- -PGID` via `shell_exec` is used. `popen` is no longer used by the transcoder.

### Step 3 — Storage paths

```bash
ls -la /media/[user]/MEeL/media/            # HDD mount (replace [user] with your username)
ls -la /dev/shm/meel/temp/                  # RAM disk staging
df -h /dev/shm                              # Needs > 512 MB free
```

### Step 4 — File paths used by PHP

For each `dirname(__DIR__)` in the codebase, verify the resolved path:

```bash
grep -rn "dirname(__DIR__)" modules/core/
```

Compare the file's depth from the project root. A file at `modules/core/X.php` resolves `dirname(__DIR__)` one level up to `modules/` — wrong if it expects the project root.

### Step 5 — Test in the browser

```json
{
  "agent_type": "browser-use",
  "prompt": "1. Login at /auth/login.php with credentials
   2. Navigate to /upload_advanced.php
   3. Submit a short YouTube URL
   4. Check if the overlay appears and progresses through the phases",
  "params": { "url": "http://localhost/auth/login.php" }
}
```

---

## 4. ⚠️ Other Common Issues

### The overlay does not appear / `meelPhase is not defined`

The overlay is no longer included by the transcoder. `Transcoder` only reports events; `BrowserProgressObserver` injects `partials/ui.php` mid-stream when the first **`download_start`** (or `transcode_start`) event arrives — guarded by an `$overlayInjected` flag so it can never be double-included.

If the overlay never appears:

- Make sure the page attaches the observer: `new Transcoder($conn, $uid, new BrowserProgressObserver())` (see `upload_advanced.php` / `transcode.php`). Without an observer, `emit()` is a no-op.
- Make sure `download_start` is actually emitted (fetchMetadata succeeded).
- The main template (`upload_advanced.php`) still includes `partials/ui.php` for non-POST / busy / rate_limit pages — that is normal, not a double include.

`BrowserProgressObserver::injectOverlay()` must include `partials/ui.php` via **`dirname(__DIR__, 2) . '/partials/ui.php'`**. The observer lives in `modules/core/` (two levels below the project root); using `__DIR__ . '/../partials/...'` resolves to the non-existent `modules/partials/` (`file_exists = false` → the include is silently skipped → the streamed response contains only the padding plus `<script>meelPhase(...)` with **no `#meel-overlay` markup**, so nothing is displayed). Symptom: the POST response starts with a run of spaces instead of `<link` CSS tags.

### Error handlers fire before the overlay loads

Errors flow as **`error`** events → the observer calls `meelError()`. If the overlay has not been injected yet (the `download_start` event has not fired), the JS `meelError` function is undefined. That is why the caller (`upload_advanced.php`) keeps its own try-catch with a plain HTML/JS fallback, and the global error handler (`set_error_handler` + `register_shutdown_function`) emits `meelError()` directly.

The caller-side fallback:

```php
echo "<script>
  if (typeof meelError === 'function') {
    meelError(" . json_encode($msg) . ");
  } else {
    document.write('<div style=\"...\">' + " . json_encode($msg) . " + '</div>');
  }
</script>";
```

### The yt-dlp format string is too restrictive

The YouTube format `bestvideo[height<=1080][vcodec^=avc1]` requires H.264. If only VP9/AV1 is available, the download fails. Relax it to `bestvideo[height<=1080]+bestaudio/best[height<=1080]`, or use `--format-sort` for a fallback.

---

## 5. 🧪 Testing Procedure

After making changes:

1. **Syntax check**

   ```bash
   php -l modules/core/Transcoder.php
   php -l modules/core/TranscoderBase.php
   php -l modules/transcoder/EncodeService.php
   php -l modules/transcoder/DownloadService.php
   php -l modules/transcoder/TranscodeService.php
   php -l modules/core/Uploader.php
   ```

2. **Browser test** with a real YouTube video URL

3. **Check for** (the phases are driven by `BrowserProgressObserver` from engine events):
   - The overlay appears immediately → `meelPhase('download')` (event `download_start`)
   - Progress updates → `meelDlPct(...)` (event `download_progress`)
   - Transcoding starts → `meelPhase('transcode')` (event `phase`)
   - Completion → `meelPhase('done')` (event `done` → `meelDone(...)`)
   - Error → `meelError(...)` (event `error`)
   - No console errors

   Quick debug: if `meelPhase`/`meelDlPct` are never called, check whether the observer is attached (see "The overlay does not appear") and whether the events are actually emitted.

4. **Check the error log** if the download fails:

   ```bash
   cat /tmp/ytdlp_error.log
   ```

---

## 6. 📁 Relevant Files Reference

| File | Purpose |
|---|---|
| `upload_advanced.php` | Advanced upload form + POST handler |
| `modules/core/Transcoder.php` + `modules/transcoder/` | Facade; download/HLS/finalize in `DownloadService`, music encoding in `EncodeService`, transcoding in `TranscodeService` |
| `modules/core/Uploader.php` | Direct file upload processing |
| `modules/core/helpers/` | `resolve_binary()`, `require_disk_space()` (helpers.php is a shim) |
| `modules/core/System.php` | Server busy, rate limit, queue management |
| `modules/core/japanese.php` | `getRomajiName()` filename sanitization |
| `modules/transcoder/FfmpegUtils.php` | `probeDuration()`, `generateSpriteAndVTT()`, `moveFile()` |
| `partials/ui.php` | MEeL Engine overlay HTML/CSS/JS |
| `controllers/api/post_encode.php` | Music post-encode (Opus/OGG) |
| `auth/config.php` | DB connection, path constants, security headers |

---

<div align="center">
  <sub><a href="index.md">← Back to the documentation index</a></sub>
</div>
