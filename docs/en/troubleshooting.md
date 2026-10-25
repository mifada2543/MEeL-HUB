# 🔧 MEeL Troubleshooting Guide

Solutions for common issues encountered while using MEeL-HUB.

---

## Table of Contents

- [HLS Streaming Issues](#hls-streaming-issues)
- [Upload Problems](#upload-problems)
- [FFmpeg/yt-dlp Errors](#ffmpegyt-dlp-errors)
- [Database Issues](#database-issues)
- [Permission Problems](#permission-problems)
- [Browser/Player Issues](#browserplayer-issues)
- [Session & Auth Issues](#session--auth-issues)
- [Storage & Disk Issues](#storage--disk-issues)
- [HTMX/AJAX Issues](#htmxajax-issues)
- [Performance Issues](#performance-issues)
- [Error Codes](#error-codes)
- [FAQ](#faq)
- [Advanced Upload & Queue](#advanced-upload--queue)

---

## HLS Streaming Issues

### ❌ Video doesn't play (black screen)

**Symptoms:**
- Player loads but screen stays black
- "No compatible source" error
- Infinite buffering

**Causes & Solutions:**

1. **HLS segments not generated:**
   ```bash
   # Check if .m3u8 and .ts files exist
   ls /media/[user]/MEeL/media/video/upload/video/[folder]/
   # Should show: [name].m3u8, [name]_000.ts, ...
   ```
   If missing, re-transcode the video.

2. **FFmpeg version too old:**
   ```bash
   ffmpeg -version  # Must be 6.0+
   # Update if needed
   sudo apt update && sudo apt upgrade ffmpeg
   ```

3. **Wrong file path in database:**
   Check `video` table — `path_folder` must match actual folder name.

4. **Browser doesn't support HLS:**
   - HLS.js works on all modern browsers
   - Check console for HLS.js errors
   - Try Chrome/Firefox/Edge (Safari has native HLS)

5. **CORS issues:**
   If using external storage, ensure proper CORS headers:
   ```apache
   Header set Access-Control-Allow-Origin "*"
   ```

### ❌ Video stutters or buffers frequently

**Causes & Solutions:**

1. **Network bandwidth:** HLS adaptive bitrate should handle this
2. **Server load:** Check CPU usage during streaming
3. **HDD speed:** If using USB HDD, ensure USB 3.0+
4. **Too many concurrent streams:** Each stream uses ~10-50 Mbps for 1080p

---

### ❌ "Connection lost" overlay appears on its own while looping a video

**Symptom:** after the video has looped 2–4 times (~10 minutes), the
"Sambungan Terputus" overlay appears and playback jumps / changes position by
itself. Console shows:

```
HTML5 video error: PipelineStatus::PIPELINE_ERROR_DECODE: video decode error! code: 3
Mengecek ketersediaan file media di: upload/video/.../....m3u8
Media terdeteksi online! Memulai pemulihan via HTMX...
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

The log `[MEeL] Error media self-healed, playback lanjut normal.` means the
grace period worked. If you instead see
`Error media tidak pulih dalam 8 detik, lanjut ke recovery`, the media really
is broken and recovery ran as designed.

**Still happening?** Raise `MEDIA_ERROR_GRACE_MS` in
`assets/js/video/watch/state.js` (default `8e3`).

---

## Upload Problems

### ❌ Upload fails with no error message

**Troubleshooting:**
```bash
# Check PHP error log
tail -f /opt/lampp/logs/php_error_log

# Check upload directory permissions
ls -la /path/to/upload/
# Should be writable by www-data

# Check PHP upload limits
php -i | grep -i upload_max_filesize
php -i | grep -i post_max_size
```

**Common fixes:**
1. Increase `upload_max_filesize` and `post_max_size` in `php.ini`
2. Ensure upload directory exists and is writable
3. Check disk space: `df -h`

### ❌ "File type not allowed"

**Check:**
- File extension is in the allowed list
- Magic bytes match the declared type
- File isn't renamed with fake extension

### ❌ Upload takes too long

**Solutions:**
1. Increase `max_execution_time` in `php.ini`
2. Use smaller files (split large videos)
3. Use Advanced Upload (yt-dlp) for URL downloads

---

## FFmpeg/yt-dlp Errors

### ❌ "FFmpeg not found"

**Check:**
```bash
which ffmpeg      # Should return path
ffmpeg -version   # Should return version
```

**Solution:**
```bash
sudo apt install ffmpeg
# Or set the binary path in auth/settings.php (MEEL_FFMPEG_PATH)
```

### ❌ "yt-dlp not found"

**Check:**
```bash
which yt-dlp
yt-dlp --version
```

**Solution:**
```bash
sudo wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp
sudo chmod +x /usr/local/bin/yt-dlp
```

### ❌ "HTTP Error 403" when downloading

**Cause:** YouTube/other platforms blocking requests.

**Solutions:**
1. Update yt-dlp: `sudo yt-dlp -U`
2. Export fresh cookies (cookies.txt)
3. Use a different user-agent

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

On default MariaDB installs, `root` authenticates via the `unix_socket` plugin
— connections are only valid from a process running as the OS `root` user (via
the socket). So `mysql -u root` works from the CLI, but connections from the
web-server process (`www-data`) fail with **"Access denied"** even with an
empty password. Recommended fix: create a dedicated database user for the app:

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

---

## Permission Problems

### ❌ "Permission denied" when uploading

**Fix:**
```bash
# Find your web server user
ps aux | grep apache | head -1
# Usually: www-data, daemon, or nobody

# Set correct ownership
sudo chown -R www-data:www-data /path/to/upload/dir
sudo chmod -R 775 /path/to/upload/dir
```

### ❌ "Storage Offline" error

**Check:**
```php
// auth/settings.php
define('MEEL_HDD_BASE', '/media/[user]/MEeL/media');
```

Verify the mount point exists and is accessible.

---

## HTMX/AJAX Issues

### ❌ HTMX not working

**Check:**
1. HTMX script is loaded
2. Target element exists in DOM
3. Response is valid HTML
4. No JavaScript errors

### ❌ Icons disappear after an HTMX swap

**Symptom:** the icons are there when the page loads, but as soon as
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

## Browser/Player Issues

### ❌ Player controls not showing

**Check:**
- Plyr.js is loaded (check Network tab)
- No JavaScript console errors
- CSS files are loading

### ❌ "Lucide icons not loading"

**Solution:**
```html
<script src="/MEeL/assets/js/compatibilitas/lucide.js"></script>
<script>lucide.createIcons();</script>
```

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

## Session & Auth Issues

### ❌ "Session expired" frequently

**Check:**
```php
// auth/config.php
$timeout = 43200; // 12 hours in seconds
```

### ❌ "Access Denied" for valid users

**Check:**
- User role in database: `SELECT role, is_active FROM users WHERE username = '...'`
- IP is not banned: `SELECT * FROM ip_ban`

### ❌ Can't login

**Solutions:**
1. Check password: use password reset or direct DB update
2. Check if account is active: `is_active` must be 1
3. Check ban status

---

## Storage & Disk Issues

### ⚠️ Redirected to `err/?code=storage` — "Storage Not Ready" (HTTP 503)

**Symptoms:**
- Opening Drive / an upload page redirects to `err/?code=storage` (HTTP 503)
- Video/music/books streaming responds with an **empty 503** (no body)
- Drive `upload`/`delete` endpoints respond with JSON
  `{"status":"error","code":"storage_unmounted"}`
- Log contains
  `[MEeL] Peringatan: MEEL_HDD_BASE tidak dapat diakses: /media/<user>/MEeL/media`

**Cause:** the `MEEL_HDD_BASE` volume (`auth/settings.php`) is not mounted —
external HDD unplugged, `mount` not run yet, or a wrong path. This used to end
in a fatal `mkdir(): Permission denied ... RuntimeException: Folder
penyimpanan gagal dibuat`; every path is now checked by `meel_storage_ready()`
first and routed to a safe error page, so no garbage folders are created on
the root filesystem.

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

**The `.meel_mount` marker** is created by `install.sh` and is in `.gitignore`
(**never commit it**). It tells `meel_storage_ready()` that the folder is a
mounted volume rather than a leftover empty directory on the mountpoint.
Without a marker: an **empty** folder counts as unmounted (fails safe: block),
a folder with content (legacy volume) is still served with a warning in the
log. A missing marker is also reported by `tests/check_deploy.php`.

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

### ❌ "Disk full" error

**Check:**
```bash
df -h
du -sh /media/[user]/MEeL/
```

**Solutions:**
- Delete unnecessary files
- Move storage to larger HDD
- Run orphan cleanup from admin panel

> **Note on "Database Sync Check":** the line *"{n} 256px thumbnail variants (X MB) kept — not orphans"* is expected. Every video has two thumbnail files: the original (registered in the DB) and a derived 256px variant `<stem>_sm.webp` that is deliberately not registered because it is used for grid cards. Do not remove them by hand via "Bersihkan SSD Thinkpad" — that line is informational only.

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
| "Server sedang sibuk" (Server is busy) | Queue full (max 2) | Wait or clean stuck queues |
| "Batas upload tercapai!" (Upload limit reached) | Rate limit active | Wait 1 hour or ask admin |
| "File terlalu besar!" (File too large) | Exceeds quota | Upload smaller file |
| "Durasi terlalu panjang!" (Duration too long) | Exceeds duration limit | Use shorter video |
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

### Download Queue System

#### How the Queue Works

```
Request → Queue → Process → Download → Transcode → Complete
                          
                          ↓ (if failed)
                        Retry (3x max)
                          ↓ (if still failed)
                        Mark as failed
```

The system uses `upload_queue` and `transcode_queue` tables to manage concurrent downloads.

#### Queue Limitations

- Maximum 2 simultaneous processes
- Maximum 3 retry attempts per download
- Queue timeout: 900 seconds (15 minutes)

---

### Common Download Issues

#### ❌ Download stuck at 0%

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

#### ❌ "HTTP Error 403" when downloading

**Cause:** YouTube blocking automated requests.

**Solutions:**
1. **Update yt-dlp:**
   ```bash
   sudo yt-dlp -U
   ```
2. **Re-export cookies:**
   - Use browser extension to export cookies
   - Save as `cookies.txt` in `temp/` (legacy root files are auto-migrated)
3. **Try different user-agent**

#### ❌ Download completes but no file found

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

### YouTube & Platform Issues

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

### Process Management

#### ❌ Stuck queue items

**Solution:**
Use the admin panel:
1. Go to Admin Panel → Queue Management
2. Click "Clean Stuck Queues"
3. Or manually force stop individual queues

#### ❌ Too many processes running

**Check active downloads:**
```bash
ps aux | grep yt-dlp
ps aux | grep ffmpeg
```

**Kill stuck processes:**
```bash
sudo killall yt-dlp
sudo killall ffmpeg
```

---

### Resource Issues

#### ❌ CPU usage too high during transcoding

**Adjust FFmpeg threads:**
```php
// modules/core/TranscoderBase.php (inherited by all transcoder services)
protected const FFMPEG_THREADS = 4; // Reduce from 8
```

#### ❌ Not enough disk space

**Check space:**
```bash
df -h
du -sh /opt/lampp/htdocs/MEeL/temp/
```

**Clean temp directory:**
```bash
rm -rf /opt/lampp/htdocs/MEeL/temp/*
```

---

### Queue Stuck Resolution

#### Force Reset All Queues

```bash
# Via MySQL
mysql -u root -p -e "UPDATE MEeL.upload_queue SET status='failed' WHERE status='processing';"
mysql -u root -p -e "UPDATE MEeL.transcode_queue SET status='failed' WHERE status='processing';"
```

#### Manual Process Kill

```bash
# Find and kill stuck processes
ps aux | grep -E 'yt-dlp|ffmpeg' | grep -v grep
sudo kill -9 [PID]
```