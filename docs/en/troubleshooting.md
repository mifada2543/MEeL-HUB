# 🔧 MEeL Troubleshooting Guide

Solutions for common issues encountered while using MEeL-HUB.

---

## Table of Contents

- [Database Issues](#database-issues)
- [Storage & HDD Issues](#storage--hdd-issues)
- [HLS Streaming Issues](#hls-streaming-issues)
- [Transcoding Issues](#transcoding-issues)
- [yt-dlp Issues](#yt-dlp-issues)
- [Session & Login Issues](#session--login-issues)
- [Upload Issues](#upload-issues)
- [Performance Issues](#performance-issues)
- [Icon Issues](#icon-issues)
- [Error Codes](#error-codes)
- [FAQ](#faq)
- [Advanced Upload & Queue](#advanced-upload--queue)

---

## Database Issues

### ❌ "Connection failed"

**Check:**
```bash
systemctl status mysql
mysql -u root -p -e "SHOW DATABASES;"
```

**Solutions:**
- Start MySQL: `sudo systemctl start mysql`
- Check credentials in `auth/settings.php`
- Check MySQL port (default: 3306)

**MariaDB-specific: `root` uses the `unix_socket` auth plugin**

On default MariaDB installs, `root` authenticates via the `unix_socket` plugin — connections are only valid from a process running as the OS `root` user (via the socket). So `mysql -u root` works from the CLI, but connections from the web-server process (`www-data`) fail with **"Access denied"** even with an empty password. Recommended fix: create a dedicated database user for the app:

```sql
CREATE USER 'meel'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON MEeL.* TO 'meel'@'localhost';
FLUSH PRIVILEGES;
```

Then use those credentials in `auth/settings.php` (`$username`/`$password`).
Alternative (less recommended): switch `root` to `mysql_native_password`:
```sql
ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('new_password');
```

### ❌ "Table not found"

**Solution:**
```bash
# Import schema
mysql -u root -p MEeL < database/schema.sql

# Run migrations
php database/migrate.php
```

### ❌ "Column 'description' cannot be null" (or other column errors)

**Cause:** Database schema version doesn't match the code

**Solution:** Run ALTER TABLE to add missing columns:
```sql
ALTER TABLE video ADD COLUMN description text DEFAULT NULL;
ALTER TABLE music ADD COLUMN description text DEFAULT NULL;
ALTER TABLE video ADD COLUMN duration int(11) DEFAULT 0;
ALTER TABLE music ADD COLUMN duration int(11) DEFAULT 0;
ALTER TABLE video ADD COLUMN search_metadata text DEFAULT NULL;
ALTER TABLE music ADD COLUMN search_metadata text DEFAULT NULL;
ALTER TABLE users ADD COLUMN last_session_id varchar(128) DEFAULT NULL;
ALTER TABLE users ADD COLUMN access_via varchar(100) DEFAULT NULL;
ALTER TABLE comments ADD COLUMN comment text NOT NULL;
```

---

## Storage & HDD Issues

### ❌ Maintenance page appears (`err/?code=maintance`)

**Cause:** `MEEL_HDD_BASE` in `auth/settings.php` doesn't match the mount point.

**Solutions:**

1. Check HDD mount point:
   ```bash
   df -h
   lsblk
   ```

2. Adjust path in `auth/settings.php` — **only one line**:
   ```php
   define('MEEL_HDD_BASE', '/correct/path');
   ```
   All modules will automatically follow.

3. For development/testing, temporarily disable HDD check:
   ```php
   // modules/core/helpers.php - comment out the check
   // if (!is_dir($hdd_check_path)) { ... }
   ```

4. Fix permissions if needed:
   ```bash
   sudo chown -R www-data:www-data /media/[user]/MEeL
   sudo chmod -R 775 /media/[user]/MEeL
   ```

### ❌ "File not found on HDD" after upload

**Cause:** Filesystem permission or wrong `MEEL_HDD_BASE`.

**Solution:**
```bash
# Check if destination folder is accessible
ls -la /media/[user]/MEeL/media/video/upload/

# Fix permissions
sudo setfacl -R -m u:daemon:rx /media/[user]/MEeL/media

# Or give full access
sudo chmod -R 777 /opt/lampp/htdocs/MEeL/temp/
```

### Debug Mode for Admin

Maintenance page is now unified at `err/?code=maintance` (HTTP 503) and only shows maintenance status. Storage path diagnosis is done via filesystem commands (see `ls -la` and `df -h` examples above).

### ⚠️ Redirected to `err/?code=storage` — "Storage Not Ready" (HTTP 503)

**Symptoms:**
- Opening Drive / an upload page redirects to `err/?code=storage` (HTTP 503)
- Video/music/books streaming responds with an **empty 503** (no body)
- Drive `upload`/`delete` endpoints respond with JSON
  `{"status":"error","code":"storage_unmounted"}`
- Log contains
  `[MEeL] Peringatan: MEEL_HDD_BASE tidak dapat diakses: /media/<user>/MEeL/media`

**Cause:** the `MEEL_HDD_BASE` volume (`auth/settings.php`) is not mounted — external HDD unplugged, `mount` not run yet, or a wrong path. This used to end in a fatal `mkdir(): Permission denied ... RuntimeException: Folder penyimpanan gagal dibuat`; every path is now checked by `meel_storage_ready()` first and routed to a safe error page, so no garbage folders are created on the root filesystem.

**Diagnosis:**
```bash
df -h | grep MEeL
ls -la "$(php -r "require 'auth/settings.php'; echo MEEL_HDD_BASE;")"
php -r "require 'auth/settings.php'; require 'modules/core/helpers.php'; var_dump(meel_storage_ready());"
```

**Fix:**
```bash
sudo mount /dev/sdX? /media/<user>/MEeL          # match your fstab
touch "$(php -r "require 'auth/settings.php'; echo MEEL_HDD_BASE;")/.meel_mount"
```

**The `.meel_mount` marker** is created by `install.sh` and is in `.gitignore` (**never commit it**). It tells `meel_storage_ready()` that the folder is a mounted volume rather than a leftover empty directory on the mountpoint. Without a marker: an **empty** folder counts as unmounted (fails safe: block), a folder with content (legacy volume) is still served with a warning in the log. A missing marker is also reported by `tests/check_deploy.php`.

---

### ❌ Drive module crashes: "Storage folder creation failed" (RuntimeException)

**Symptoms:**
- Opening `drive/index.php`, uploading, downloading or streaming throws
  `RuntimeException: Folder penyimpanan gagal dibuat` from `drive/DriveService.php`
  (`ensureDirectoryExists()`)
- Happens on a fresh clone or on a machine where the old repo symlinks were broken

**Causes & Solutions:**

1. **Legacy committed symlink to a dead absolute path** (old checkout): the repo
   used to track `data_drive/public` & `data_drive/private_admins` as **symlinks**
   pointing to `/media/<devuser>/MEeL/media/drive/...` — broken on any other
   machine. Update the repo (the symlinks are removed and the folders are now
   tracked as real directories), or fix locally:
   ```bash
   rm -f data_drive/public data_drive/private_admins   # remove old symlinks
   mkdir -p data_drive/public data_drive/private_admins
   ```

2. **`MEEL_HDD_DRIVE` points to an unreadable/nonexistent path:** when defined,
   the Drive module reads its storage directly from `MEEL_HDD_DRIVE` (derived
   from `MEEL_HDD_BASE` in `auth/settings.php`) — **no symlink is involved**.
   Verify where the app actually resolves storage:
   ```bash
    php -r "require 'auth/settings.php'; echo defined('MEEL_HDD_DRIVE') ? MEEL_HDD_DRIVE : 'NOT SET';"
    php -r "require 'auth/settings.php'; require 'modules/core/helpers.php'; echo meel_drive_base_path();"
   ```
   If the resolved path doesn't exist or isn't writable, fix `MEEL_HDD_BASE` in
   `auth/settings.php` (or leave it unset to use the `data_drive/` fallback).

3. **Fallback folders not writable** (when `MEEL_HDD_DRIVE` is not defined):
   ```bash
   sudo chown -R www-data:www-data data_drive
   sudo chmod -R 775 data_drive
   ```
   `data_drive/public` & `data_drive/private_admins` are auto-created by
   `DriveStorage::ensureDirectoryExists()`.

> ⚠️ **Never commit symlinks inside `data_drive/`** — `.gitignore` blocks them.
> `tests/check_deploy.php` only warns about symlinks pointing OUTSIDE
> `MEEL_HDD_DRIVE` (e.g. `/media/<user>/...`); deploy-time symlinks pointing
> inside `MEEL_HDD_DRIVE` are rated PASS. See
> [Installation §5a](installation.md#5a-media-storage-meel_hdd_base--php-endpoint--rewrite-no-symlinks)
> for the Drive storage layout (`MEEL_HDD_DRIVE` vs the `data_drive/` fallback).

---

## HLS Streaming Issues

### ❌ "Connection lost" overlay appears on its own while looping a video

**Symptom:** after the video has looped 2–4 times (~10 minutes), the
"Connection Lost" overlay appears and playback jumps / changes position by
itself. Console shows:

```
HTML5 video error: PipelineStatus::PIPELINE_ERROR_DECODE: video decode error! code: 3
Checking media file availability at: upload/video/.../....m3u8
Media detected online! Starting recovery via HTMX...
```

**Cause:** the native `loop` attribute makes the browser seek
`duration → 0` without telling hls.js. `HLS_CONFIG.backBufferLength` is only
**10 seconds**, so the data at position 0 has already been evicted from the
`SourceBuffer`. Chromium then seeks outside the buffer and throws
`PIPELINE_ERROR_DECODE` — even though the media file is perfectly healthy (the
`.m3u8` HEAD probe returns 200).

The previous version escalated error code 3 straight to *full recovery*: HEAD
probe → `htmx` swap → the entire player rebuilt, for no actual fault.

**Fix (shipped in MEeL core):**

| Layer | File | What it does |
|-------|------|--------------|
| Self-heal | `recovery.js` | Codes 2 & 3 get an 8-second *grace period* — `hls.recoverMediaError()`, then watch whether `currentTime` advances. If it moves, done: no recovery. |
| Loop wrap | `player-events.js` | The `ended` handler tells hls.js the target position (`hls.startLoad()`) so it reloads segments instead of reacting blindly to the seek. |
| Back buffer | `player-init.js` | Short videos (≤2 min) stay fully buffered, so looping needs no flush at all. |

The log `[MEeL] Error media self-healed, playback continues normal.` means the
grace period worked. If you instead see
`Error media not recovered within 8 seconds, proceeding to recovery`, the media really
is broken and recovery ran as designed.

**Still happening?** Raise `MEDIA_ERROR_GRACE_MS` in
`assets/js/video/watch/state.js` (default `8e3`).

---

### ❌ Loop Playback Status Is Reset On Every Refresh

**Symptom:** the Loop button is on, but after a refresh — or after an
auto-recovery, or after a quality change — it is back to normal, even though
that video really was playing in a loop. The other way round, a loop that
stays "on" gets carried over to the next video.

**Cause:** the loop status only lived in the player instance, so it was lost
every time the player was rebuilt. And more subtly, nothing cleared it when
moving to another video.

**Fix (already in MEeL core):**

| Location | What it does |
|---|---|
| `assets/js/video/watch/state.js` | Reads `MEEL_KEYS.VIDEO_LOOP` (`meel_video_loop`) at boot, and exports `persistVideoLoop()`, `restoreVideoLoop()`, `clearVideoLoop()` |
| `setupMeelPlayerEvents()` | Calls `restoreVideoLoop()`, so the loop is restored on **every** player creation path: initial load, auto-recovery, rebuild after a quality change, and switching video through the mini player |
| Switching video & Auto Next | `clearVideoLoop()` is called on `skipToNextVideo`, clicking a mini player card, the `.recommendation-item` click guard, and when Auto Next is enabled — so the loop is not carried into the next video |

Auto Next and Loop cancel each other out in both directions: enabling Auto Next
clears the loop key, enabling Loop turns Auto Next off, and stale state while
both are active is cleaned at boot.

---

## Transcoding Issues

### ❌ Transcoding Process Failed / Stuck

**Cause 1: Queue Stuck**
1. Open Admin Panel → **Active Background Tasks**
2. Click **Clean Stuck Queues** or **Force Stop** per task

**Cause 2: FFmpeg Error**
```bash
# Check FFmpeg installation
ffmpeg -version
which ffmpeg

# resolveBinary() (trait modules/transcoder/FfmpegUtils.php) auto-detects
# If you want custom path, set MEEL_FFMPEG_PATH / MEEL_FFPROBE_PATH in auth/settings.php
```

**Cause 3: Resource Limit**
```bash
# Monitor CPU/RAM
htop

# Check running processes
ps aux | grep ffmpeg

# Force kill if needed
killall -9 ffmpeg
```

### ❌ "FFmpeg failed to produce output file"

**Solutions:**
1. Check disk space:
   ```bash
   df -h
   ```

2. Check temp folder permissions:
   ```bash
   ls -la /opt/lampp/htdocs/MEeL/temp/
   sudo chmod -R 777 /opt/lampp/htdocs/MEeL/temp/
   ```

3. Try manual transcode for debugging:
   ```bash
   ffmpeg -i input.mp4 -codec copy -hls_time 10 output.m3u8
   ```

### ❌ Transcoding Slow

**Optimization:**
```php
// Adjust FFMPEG_THREADS to match your CPU
// In modules/core/TranscoderBase.php (inherited by all transcoder services)
protected const FFMPEG_THREADS = 8; // Change to your CPU core count (nproc)
```

Check CPU:
```bash
nproc  # Core count
lscpu  # CPU details
```

---

## yt-dlp Issues

### ❌ "Failed to fetch metadata" or "Failed to parse metadata"

**Cause 1: yt-dlp not installed**
```bash
which yt-dlp
# If not found:
sudo wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp
sudo chmod +x /usr/local/bin/yt-dlp
```

**Cause 2: Node.js not installed**
```bash
which node
# If not found:
sudo apt install nodejs
```

**Cause 3: Cookies invalid/expired**
- Re-export cookies from browser
- Make sure you're logged into YouTube/target platform
- Save as `cookies.txt` in `temp/` (old file in root auto-migrated)

**Cause 4: Update yt-dlp**
```bash
yt-dlp -U
# or
pip3 install -U yt-dlp
```

### ❌ "Download failed" / yt-dlp error

Check error log:
```bash
cat /tmp/ytdlp_error.log
```

**Common solutions:**
1. Update yt-dlp to latest version
2. Update cookies.txt
3. Try URL directly in terminal:
   ```bash
   yt-dlp --cookies cookies.txt "https://youtube.com/watch?v=..."
   ```
4. If video is private/region-locked, it cannot be downloaded

### ❌ Debug Metadata

When metadata fails to parse, Transcoder displays a debug overlay:
```
╔═══════════════════════════════════════════════════╗
║ ⚠️ DEBUG: FAILED PARSING METADATA                 ║
║                                                   ║
║ URL: https://youtube.com/watch?v=...              ║
║ Return Code: 1 (0 = success)                      ║
║ Output: ...                                       ║
║                                                   ║
║ Check System Paths:                               ║
║ - Node:    /usr/bin/node                          ║
║ - yt-dlp:  /usr/local/bin/yt-dlp                  ║
║ - ffmpeg:  /usr/bin/ffmpeg                        ║
╚═══════════════════════════════════════════════════╝
```

---

## Session & Login Issues

### ❌ Session Suddenly Ends

**Causes:**
- Session timeout (12 hours)
- Session kicked by admin
- Login from another device

**Solutions:**
1. Log in again
2. If it happens often, check server time:
   ```bash
   timedatectl status
   ```
3. Ensure PHP session folder is writable:
   ```bash
   sudo chmod 777 /var/lib/php/sessions
   # or
   sudo chmod 777 /tmp
   ```

### ❌ Redirected to `/err/?code=revoked` (Session Revoked)

**Cause:** Session ID in your browser doesn't match the one registered in the database (e.g. kicked by Admin, logged in from another device, or session cookie deleted/not persistent).

**Solutions:**
1. Click **Back** on the error screen, then log in again (or open `auth/login.php`).
2. Clear your browser cache and cookies specifically for this platform's domain.
3. If using Cloudflare Tunnel, ensure session cookie settings are configured to remain persistent.
4. Try accessing using Incognito / Private Window mode in your browser.

### ❌ Redirected to `/err/?code=banned` (Access Blocked)

**Cause:** Your IP address is listed in the internal firewall block table (`ip_ban`).

**Solutions:**
1. Contact the MEeL-HUB Admin to remove your IP from the list via Admin Panel → **Firewall & Banned IPs**.
2. If you are an Admin, you can bypass the block by default (since admin role is excluded from ban check in `activity_logger.php`), or log in using a local connection/another IP first to remove the IP via the dashboard.

### ❌ Login Keeps Failing (Locked)

**Cause:** 5 failed login attempts in a short time

**Solutions:**
- Wait 5 minutes (automatic countdown)
- Or remove session lock from database:
  ```sql
  -- Delete login_fail_count from session (cannot be done directly from DB)
  -- Alternative: restart Apache
  sudo systemctl restart apache2
  ```

---

## Upload Issues

### ❌ Upload Failed "File too large"

**Solution:** Increase `upload_max_filesize` in `php.ini`:
```ini
upload_max_filesize = 500M
post_max_size = 500M
max_execution_time = 300
max_input_time = 300
```

Restart Apache after changing:
```bash
sudo systemctl restart apache2
```

### ❌ "Upload limit reached!"

Non-admin users have limits:
- **2 uploads per hour** for video & music
- **10 uploads per hour** for drive

Wait for quota to reset, or ask admin to upload for you.

### ❌ Advanced Upload URL Error

**Checklist:**
1. URL must be valid (https://)
2. URL must not exceed 500 characters
3. Server not busy (max 2 simultaneous processes)
4. yt-dlp installed and cookies valid
5. Sufficient storage space

---

## Performance Issues

### ❌ Slow Pages

**Optimizations:**
1. **Enable OPcache** in `php.ini`:
   ```ini
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.max_accelerated_files=4000
   ```

2. **Monitor database:**
   ```sql
   SHOW PROCESSLIST;
   EXPLAIN SELECT * FROM video ORDER BY upload_date DESC;
   ```
   Add indexes if needed:
   ```sql
   ALTER TABLE video ADD INDEX idx_upload_date (upload_date);
   ALTER TABLE music ADD INDEX idx_upload_date (upload_date);
   ```

3. **Check internet connection** for external assets (if loading CDN like Google Fonts in testing files)

### ❌ CPU 100% during Transcoding

**Cause:** HLS transcoding or sprite generation

**Solution:**
1. Lower `FFMPEG_THREADS`:
   ```php
   protected const FFMPEG_THREADS = 4; // From 8
   ```
2. Limit concurrent processes (already implemented: max 2)
3. Schedule transcoding during off-peak hours using cron

### ❌ HDD/SSD Full

**Routine cleanup:**
1. Admin Panel → **Database Sync Check** → Clean orphan files
2. Delete temporary files:
   ```bash
   rm -rf /opt/lampp/htdocs/MEeL/temp/*
   ```
3. Clean inactive guests: Admin Panel → **Clean Inactive Guests**
4. Delete stuck queues: Admin Panel → **Clean Stuck Queues**
5. Delete unnecessary drive files

> **Note on "Database Sync Check":** the line *"{n} 256px thumbnail variants (X MB) kept — not orphans"* is expected. Every video has two thumbnail files: the original (registered in the DB) and a derived 256px variant `<stem>_sm.webp` that is deliberately not registered because it is used for grid cards. Do not remove them by hand via "Bersihkan SSD Thinkpad" — that line is informational only.

---

## Icon Issues

### ❌ Lucide icons not showing

**Solution:**
```html
<script src="/MEeL/assets/js/compatibilitas/lucide.js"></script>
<script>lucide.createIcons();</script>
```

### ❌ Icons disappear after HTMX swap

**Symptom:** icons are there when the page loads, but as soon as
something is swapped in place — pressing like/dislike, for example — the
icons in that area vanish. A refresh brings them back, and the next swap
takes them away again. Nothing appears in the console.

**Cause:** icons are `<i data-lucide="...">` elements that Lucide replaces
with an SVG. The replacement only happens when `lucide.createIcons()` runs.
The server sends plain `<i data-lucide>` markup back with every swapped
fragment, so something has to render them again afterwards.

`assets/js/shared/htmx-lucide.js` does that on `htmx:afterOnLoad`. It
registers that listener on `document`, **not** on `document.body` — on
purpose. The file is loaded in `<head>` on some pages, and at that point
`document.body` is still `null`; a listener registered on `document.body`
would never be attached, and every later swap would silently leave the
icons unrendered.

**Check:**
- `assets/js/shared/htmx-lucide.js` is loaded on the page
- the page does not bundle its own copy of the file
- in the console: `typeof lucide` is `"object"` and `lucide.createIcons` exists

---

## Error Codes

### HTTP Status Codes

| Code | Meaning | Common Cause |
|---|---|---|
| **401** | Unauthorized | User not logged in |
| **403** | Forbidden | IP banned, user inactive, insufficient role |
| **404** | Not Found | Media doesn't exist, file missing |
| **500** | Internal Server Error | Database error, PHP error |
| **503** | Service Unavailable | HDD offline, server busy |

### System Error Messages

| Message | Meaning | Solution |
|---|---|---|
| "Storage Offline" | External HDD not mounted | Check `df -h` and path in `helpers.php` |
| "Server is busy" | Queue full (max 2) | Wait or clean stuck queues |
| "Upload limit reached!" | Rate limit active | Wait 1 hour or ask admin |
| "File too large!" | Exceeds quota | Upload smaller file |
| "Duration too long!" | Exceeds duration limit | Use shorter video |
| "Security Error" | Suspicious file | Upload file with correct format |

---

## FAQ

### Q: Can MEeL be accessed from the internet?

**A:** Yes, but it's recommended to use Cloudflare Tunnel or VPN. Don't expose directly without HTTPS.

### Q: How to backup data?

**A:** Backup database + media folder:
```bash
mysqldump -u root -p MEeL > backup_meel.sql
tar -czf media_backup.tar.gz /media/[user]/MEeL/
```

### Q: Why doesn't the video show a thumbnail?

**A:** Thumbnails are automatically generated from the 5th frame of the video. Make sure FFmpeg is installed.

### Q: What music formats are supported?

**A:** MP3, OGG/Opus, M4A/AAC, FLAC, WAV. All will be transcoded to Opus/OGG.

### Q: How to add a new admin?

**A:** Register a regular user, then change the role in the database:
```sql
UPDATE users SET role = 'admin', is_active = 1 WHERE id = [user_id];
```

### Q: Can I stream 4K video?

**A:** Technically yes, but bandwidth and storage are limiting factors. 1080p is recommended.

### Q: Why is the guest session invalid?

**A:** Guests are automatically created on first access. If there are issues, delete inactive guests via Admin Panel.

---

## Advanced Upload & Queue

Troubleshooting guide for yt-dlp downloads and background queue processing.

### Common Symptoms & Issues

Advanced Upload uses the `yt-dlp` CLI library combined with an async PHP wrapper to download media from external URLs (YouTube, NicoNico, TikTok, etc.) and transcode it automatically. Issues usually manifest as queue status stuck at `"processing"` or **"Server is busy"** notifications despite no active processes.

---

### Problem 1: Queue Stuck / Server Busy

The system limits to a maximum of **2 simultaneous download/transcode processes** to maintain CPU stability. If a process is interrupted unexpectedly (e.g., connection loss or sudden server crash), the queue status in the database remains stuck as `"processing"`.

#### Solution A — Via Admin Panel (Recommended)

1. Log in as **Admin**.
2. Open **Admin Dashboard** (`admin/index.php`).
3. Scroll to **Active Background Tasks** section.
4. Click **Clean Stuck Queues** (clears tasks stuck over 30 minutes).
5. Or click the **Force Stop** (×) icon on a specific stuck task.

#### Solution B — Via Database (Manual SQL)

Run the following SQL via phpMyAdmin or MySQL Client to reset queues:
```sql
-- Remove stuck queues
DELETE FROM `upload_queue` 
WHERE `status` = 'processing';
```

#### Solution C — Kill Processes at OS Level (Linux)

If FFmpeg or yt-dlp processes are still running in background consuming CPU:
```bash
# Check for active processes
ps aux | grep -E "yt-dlp|ffmpeg"

# Force kill all download/transcode processes
sudo killall -9 yt-dlp
sudo killall -9 ffmpeg
```

---

### Problem 2: Download Stuck at 0%

**Causes:**
1. **yt-dlp not found**: Check installation
2. **No internet connection**: Verify network
3. **URL blocked**: Check if URL is accessible
4. **Cookies expired**: Re-export cookies.txt

**Solutions:**
```bash
# Verify yt-dlp
yt-dlp --version

# Test URL directly
yt-dlp --simulate "https://www.youtube.com/watch?v=..."
```

---

### Problem 3: Download Completes but No File Found

**Causes:**
- Wrong format selection
- FFmpeg not installed or too old
- Disk space full

**Solutions:**
```bash
# Check FFmpeg
ffmpeg -version

# Check disk space
df -h

# Clear temp directory
rm -rf /opt/lampp/htdocs/MEeL/temp/*
```

---

### Problem 4: YouTube & Platform-Specific Issues

#### ❌ "This video is unavailable" / Private video

**Solutions:**
- Ensure you're logged into YouTube in browser before exporting cookies
- Check if the video is region-locked
- Some private/age-restricted videos may not be downloadable

#### ❌ "Sign in to confirm your age"

**Solution:**
Export cookies after signing into a YouTube account that has age verification.

#### ❌ Geo-restricted content

**Workarounds:**
- Use a VPN in the target country
- Use proxy settings with yt-dlp

---

### Force Reset All Queues

```bash
# Via MySQL
mysql -u root -p -e "UPDATE MEeL.upload_queue SET status='failed' WHERE status='processing';"
mysql -u root -p -e "UPDATE MEeL.transcode_queue SET status='failed' WHERE status='processing';"
```

### Manual Process Kill

```bash
# Find and kill stuck processes
ps aux | grep -E 'yt-dlp|ffmpeg' | grep -v grep
sudo kill -9 [PID]
```