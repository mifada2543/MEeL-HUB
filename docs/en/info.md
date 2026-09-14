# MEeL Function Reference

Complete list of `meel_*()` functions available in the MEeL-HUB codebase.
These functions were created to eliminate code duplication and provide a consistent centralized API.

---

## Table of Contents

- [Authentication & Session](#authentication--session)
- [URL & Base Path](#url--base-path)
- [Storage & Media Path](#storage--media-path)
- [Upload & File Handling](#upload--file-handling)
- [FFmpeg & Encoding](#ffmpeg--encoding)
- [Database & Migration](#database--migration)
- [Admin Utilities](#admin-utilities)
- [Drive & Cloud Storage](#drive--cloud-storage)
- [Error Handling](#error-handling)
- [Testing & Mecab](#testing--mecab)

---

## Authentication & Session

### `meel_boot_session()`

Initializes PHP session with proper security configuration (httponly, samesite Lax, secure cookie on HTTPS).

```php
function meel_boot_session(): void
```

**File:** `modules/auth/helpers/session.php:3`
**Called by:** All entry points requiring session (index, controllers, APIs).
**Notes:** Session timeout is 12 hours (43200 seconds). Only calls `session_start()` if session is not already active.

---

## URL & Base Path

### `meel_base_url_path()`

Returns the relative base URL path from the project root (e.g., `/MEeL` or `/` if at document root).

```php
function meel_base_url_path(): string
```

**File:** `modules/core/base_url.php:6`
**Return:** Relative path string, no trailing slash.
**Example:** `"/MEeL"` or `""` if project is at document root.

---

## Storage & Media Path

### `meel_media_base_path(string $module)`

Returns the absolute path of the media upload folder based on module (`video`, `music`, `books`).

```php
function meel_media_base_path(string $module): string
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$module` | `string` | Module name: `'video'`, `'music'`, or `'books'` |

**File:** `modules/core/helpers/storage.php:4`
**Return:** Absolute path to module upload folder.
**Logic:**
- Checks `MEEL_HDD_*_UPLOAD` constant (e.g., `MEEL_HDD_VIDEO_UPLOAD`)
- If defined and non-empty → returns HDD path
- If not → falls back to `<root>/<module>/upload`

**Example:**
```php
$video_path = meel_media_base_path('video');
// → '/media/user/MEeL/media/video/upload' or '/opt/lampp/htdocs/MEeL/video/upload'
```

---

### `meel_drive_base_path(?string $hddDriveOverride = null)`

Returns the absolute path of the Drive storage folder (public + private_admins).

```php
function meel_drive_base_path(?string $hddDriveOverride = null): string
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$hddDriveOverride` | `?string` | HDD path override (optional, default: use `MEEL_HDD_DRIVE`) |

**File:** `modules/core/helpers/storage.php:73`
**Return:** Absolute path to Drive folder.
**Logic:**
- If `$hddDriveOverride` or `MEEL_HDD_DRIVE` is defined → returns HDD path
- If not → falls back to `<root>/data_drive`

---

### `meel_serve_media_file(string $module, string $relPath, array $opts = [])`

Serves media files with Range support (HTTP 206), referer gate for HLS, and security validation.

```php
function meel_serve_media_file(string $module, string $relPath, array $opts = []): void
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$module` | `string` | Module name: `'video'`, `'music'`, `'books'` |
| `$relPath` | `string` | Relative path to file within upload folder |
| `$opts` | `array` | Additional options: `'hls_gate' => true` for referer gate |

**File:** `modules/core/helpers/storage.php:246`
**Return:** void (sends response directly and `exit`).
**Features:**
- Path traversal validation (`..`, null byte)
- Extension whitelist (m3u8, ts, vtt, mp4, webm, mkv, jpg, png, webp, gif, pdf, mp3, ogg, m4a, flac, wav, opus)
- Automatic MIME type mapping
- Range request support (206 Partial Content) for HLS `.ts` and large video
- Referer gate for HLS video (anti-hotlink)

---

### `meel_write_cache_file(string $path, string $content)`

Writes cache file atomically with `LOCK_EX` (prevents race conditions).

```php
function meel_write_cache_file(string $path, string $content): void
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$path` | `string` | Full path to cache file |
| `$content` | `string` | Content to write |

**File:** `modules/core/helpers/storage.php:196`
**Return:** void.
**Notes:** Logs error to error_log if directory is not writable.

---

## Upload & File Handling

### `meel_read_magic_bytes(string $path, int $length = 16)`

Reads the first bytes of a file for file type identification (magic bytes).

```php
function meel_read_magic_bytes(string $path, int $length = 16): string
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$path` | `string` | Path to file to read |
| `$length` | `int` | Number of bytes to read (default: 16) |

**File:** `modules/core/helpers/upload.php:12`
**Return:** String containing bytes (binary), or `''` if read fails.

---

### `meel_magic_extension_ok(string $path, string $ext, string $mediaKind = 'audio')`

Validates file magic bytes against extension and allowed media type.

```php
function meel_magic_extension_ok(string $path, string $ext, string $mediaKind = 'audio'): string
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$path` | `string` | Path to file to validate |
| `$ext` | `string` | File extension (without dot) |
| `$mediaKind` | `string` | Media type: `'video'`, `'audio'`, `'image'`, `'pdf'`, `'archive'` |

**File:** `modules/core/helpers/upload.php:31`
**Return:** `''` (empty) if valid; error message if invalid.
**Supported media kinds:**
- `video` — Matroska/WebM, MP4/MOV
- `audio` — Ogg/Opus, FLAC, WAV, MP3, MP4/M4A
- `image` — JPEG, PNG, WebP, GIF
- `pdf` — PDF
- `archive` — ZIP

---

### `meel_sanitize_upload_filename(string $original, string $fallback = 'file')`

Sanitizes upload filename to a safe physical filename.

```php
function meel_sanitize_upload_filename(string $original, string $fallback = 'file'): string
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$original` | `string` | Original filename from user |
| `$fallback` | `string` | Fallback name if result is empty (default: `'file'`) |

**File:** `modules/core/helpers/upload.php:84`
**Return:** Clean filename (only `[a-zA-Z0-9._-]`), no path separators, no null bytes, no traversal.
**Notes:** Original user name can still be stored separately as metadata.

---

### `meel_sanitize_clean_name(string $raw, int $max_len = 120)`

Sanitizes base name (without extension) to safe characters for media filename.

```php
function meel_sanitize_clean_name(string $raw, int $max_len = 120): string
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$raw` | `string` | Raw name to clean |
| `$max_len` | `int` | Maximum result length (default: 120) |

**File:** `modules/core/helpers/upload.php:146`
**Return:** Clean name, or `''` if result is empty.
**Notes:** Replaces unsafe characters with `_`, truncates to `$max_len`.

---

### `meel_reserve_unique_filename(string $dir, string $clean_name, string $ext, int $max_attempts = 1000, string $suffix_sep = '-')`

Atomically allocates a unique filename using `fopen(..., 'x')` (O_EXCL).

```php
function meel_reserve_unique_filename(string $dir, string $clean_name, string $ext, int $max_attempts = 1000, string $suffix_sep = '-'): ?string
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$dir` | `string` | Target directory |
| `$clean_name` | `string` | Clean name (without extension) |
| `$ext` | `string` | File extension (without dot) |
| `$max_attempts` | `int` | Maximum attempts (default: 1000) |
| `$suffix_sep` | `string` | Suffix separator (default: `'-'`) |

**File:** `modules/core/helpers/upload.php:171`
**Return:** Unique filename that was successfully reserved, or `null` if all attempts failed.
**Features:**
- Empty placeholder created first → caller overwrites with `move_uploaded_file` / `ffmpeg -y`
- Anti race condition: two concurrent requests cannot select the same name
- Example results: `my-song.opus`, `my-song-1.opus`, `my-song-2.opus`, ...

---

### `meel_allocate_unique_dir(string $parent, string $base)`

Allocates a unique directory name (suffix `-1`, `-2`, ...) inside parent.

```php
function meel_allocate_unique_dir(string $parent, string $base): string
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$parent` | `string` | Parent directory |
| `$base` | `string` | Base directory name |

**File:** `modules/core/helpers/upload.php:240`
**Return:** Unique directory name without trailing slash.
**Example:** `meel_allocate_unique_dir('/tmp', 'encode')` → `'encode'` or `'encode-1'` or `'encode-2'`, ...

---

### `meel_upload_allowed_table(string $table)`

Validates table name for upload operations.

```php
function meel_upload_allowed_table(string $table): string
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$table` | `string` | Table name to validate |

**File:** `modules/core/helpers/upload.php:96`
**Return:** `string` table name if valid (`'music'` or `'video'`), or `''` if invalid.

---

### `meel_insert_music_row(\mysqli $conn, ...)`

Inserts a new music row into database (the only INSERT path for music).

```php
function meel_insert_music_row(
    \mysqli $conn,
    int $user_id,
    string $title,
    string $artist,
    string $album,
    string $description,
    string $search_metadata,
    string $filename,
    string $thumbnail,
    ?int $duration = null
): array
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$conn` | `\mysqli` | Database connection |
| `$user_id` | `int` | Owner user ID |
| `$title` | `string` | Music title |
| `$artist` | `string` | Artist name |
| `$album` | `string` | Album name |
| `$description` | `string` | Description |
| `$search_metadata` | `string` | Search metadata |
| `$filename` | `string` | Audio filename |
| `$thumbnail` | `string` | Thumbnail filename |
| `$duration` | `?int` | Duration in seconds (optional) |

**File:** `modules/core/helpers/upload.php:311`
**Return:** `[bool $ok, string $error]` — `$ok = true` on success, `$error` contains mysqli message on failure.
**Notes:** `duration` column only included if != null (preserves legacy behavior).

---

## FFmpeg & Encoding

### `meel_ffmpeg_thumbnail_webp(string $ffmpeg_bin, string $src, string $dst, int $max_width, string $extra = '', string $env_prefix = '', int $threads = 0)`

Converts image/frame to WebP via ffmpeg (the only thumbnail conversion path).

```php
function meel_ffmpeg_thumbnail_webp(
    string $ffmpeg_bin,
    string $src,
    string $dst,
    int $max_width,
    string $extra = '',
    string $env_prefix = '',
    int $threads = 0
): bool
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$ffmpeg_bin` | `string` | Path to ffmpeg binary |
| `$src` | `string` | Source file (image, audio, video) |
| `$dst` | `string` | Output path `.webp` |
| `$max_width` | `int` | Maximum width (min scale, iw) |
| `$extra` | `string` | Additional arguments (e.g., `'-ss 00:00:05'` or `'-an -vframes 1'`) |
| `$env_prefix` | `string` | Environment prefix (e.g., `'export LD_LIBRARY_PATH=''; '`) |
| `$threads` | `int` | ffmpeg `-threads` value (0 = default) |

**File:** `modules/core/helpers/upload.php:208`
**Return:** `true` if output file exists and contains data.
**Example:**
```php
$ok = meel_ffmpeg_thumbnail_webp(
    '/usr/bin/ffmpeg',
    '/path/to/video.mp4',
    '/path/to/thumb.webp',
    320,
    '-ss 00:00:05 -an -vframes 1'
);
```

---

### `meel_ffmpeg_encode_opus(string $ffmpeg_bin, string $input, string $output, string $env_prefix = '', int $threads = 0, array $metadata = [])`

Encodes audio → Opus/Ogg via ffmpeg (the only Opus encoding path).

```php
function meel_ffmpeg_encode_opus(
    string $ffmpeg_bin,
    string $input,
    string $output,
    string $env_prefix = '',
    int $threads = 0,
    array $metadata = []
): array
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$ffmpeg_bin` | `string` | Path to ffmpeg binary |
| `$input` | `string` | Source audio file |
| `$output` | `string` | Output path `.ogg` |
| `$env_prefix` | `string` | Environment prefix |
| `$threads` | `int` | ffmpeg `-threads` value (0 = default) |
| `$metadata` | `array` | Metadata tags `['title' => ..., 'artist' => ...]` |

**File:** `modules/core/helpers/upload.php:275`
**Return:** `[int $exit_code, string $log]` — log contains combined stdout+stderr for error messages.
**Example:**
```php
[$code, $log] = meel_ffmpeg_encode_opus(
    '/usr/bin/ffmpeg',
    '/path/to/input.mp3',
    '/path/to/output.ogg',
    '',
    2,
    ['title' => 'Song Title', 'artist' => 'Artist Name']
);
```

---

## Database & Migration

### `meel_mig_has_column(\mysqli $conn, string $table, string $col)`

Checks if a specific column exists in a table (for database migrations).

```php
function meel_mig_has_column(\mysqli $conn, string $table, string $col): bool
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$conn` | `\mysqli` | Database connection |
| `$table` | `string` | Table name |
| `$col` | `string` | Column name |

**File:** `database/migrate.php:14`
**Return:** `true` if column exists.
**Notes:** Identifiers come from internal migration list (hardcoded, not user input).

---

### `meel_mig_has_index(\mysqli $conn, string $table, string $index)`

Checks if a specific index exists in a table (for database migrations).

```php
function meel_mig_has_index(\mysqli $conn, string $table, string $index): bool
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$conn` | `\mysqli` | Database connection |
| `$table` | `string` | Table name |
| `$index` | `string` | Index name |

**File:** `database/migrate.php:24`
**Return:** `true` if index exists.

---

### `meel_arc_mig_has_column(\mysqli $conn, string $table, string $col)`

Arcade version of `meel_mig_has_column` — checks column for Arcade migrations.

```php
function meel_arc_mig_has_column(\mysqli $conn, string $table, string $col): bool
```

**File:** `arcade/migrate.php:14`

---

### `meel_arc_mig_has_index(\mysqli $conn, string $table, string $index)`

Arcade version of `meel_mig_has_index` — checks index for Arcade migrations.

```php
function meel_arc_mig_has_index(\mysqli $conn, string $table, string $index): bool
```

**File:** `arcade/migrate.php:22`

---

## Admin Utilities

### `meel_admin_remove_dir(string $dir, int &$counter, int &$failed)`

Recursively removes a directory (for admin orphan cleaner).

```php
function meel_admin_remove_dir(string $dir, int &$counter, int &$failed): void
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$dir` | `string` | Path to directory to remove |
| `$counter` | `int&` | Reference counter for successfully deleted files |
| `$failed` | `int&` | Reference counter for failed file deletions |

**File:** `controllers/admin/admin_actions.php:129`
**Return:** void.
**Notes:** Deletes contents first, then empty directories. Logs errors to error_log on failure.

---

### `meel_admin_clean_empty_parents(string $path, array $stop_dirs)`

Recursively removes empty parent directories (up to one of `$stop_dirs`).

```php
function meel_admin_clean_empty_parents(string $path, array $stop_dirs): void
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$path` | `string` | Path to recently deleted file/folder |
| `$stop_dirs` | `array` | Array of paths where removal stops |

**File:** `controllers/admin/admin_actions.php:156`
**Return:** void.
**Example:**
```php
meel_admin_clean_empty_parents(
    '/path/to/deleted/file.mp3',
    ['/opt/lampp/htdocs/MEeL/video/upload/video']
);
```

---

### `meel_remove_media_dir(string $dir, int &$counter, array &$failed)`

Recursively removes a media directory (for admin stats/delete).

```php
function meel_remove_media_dir(string $dir, int &$counter, array &$failed): void
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$dir` | `string` | Path to media directory |
| `$counter` | `int&` | Reference counter for successfully deleted files |
| `$failed` | `array&` | Reference array of filenames that failed to delete |

**File:** `admin/stats.php:36`
**Return:** void.
**Notes:** Similar to `meel_admin_remove_dir` but uses array `$failed` (not int) for tracking failed files.

---

## Drive & Cloud Storage

### `log_drive_operation(int $userId, string $username, string $operation, string $filename, string $type, string $scope, string $status = 'success')`

Writes audit log for Drive operations (upload, download, delete, etc.) in JSON format.

```php
function log_drive_operation(
    int $userId,
    string $username,
    string $operation,
    string $filename,
    string $type,
    string $scope,
    string $status = 'success'
): void
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$userId` | `int` | User ID |
| `$username` | `string` | Username |
| `$operation` | `string` | Operation type (upload, download, delete, etc.) |
| `$filename` | `string` | Filename |
| `$type` | `string` | File type |
| `$scope` | `string` | Operation scope (public, private) |
| `$status` | `string` | Operation status (default: `'success'`) |

**File:** `modules/core/helpers/storage.php:208`
**Return:** void.
**Output:** JSON per line in `logs/drive_audit.log` with timestamp, IP, and user agent.

---

### `invalidate_dir_size_cache(string $username)`

Invalidates Drive folder size cache for a specific user.

```php
function invalidate_dir_size_cache(string $username): void
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$username` | `string` | Username whose cache to invalidate |

**File:** `modules/core/helpers/storage.php:180`
**Return:** void.
**Notes:** Deletes cache file `temp/dirsize_<md5>.cache`.

---

## Error Handling

### `meel_err_alpha(string $hex, int $alpha)`

Converts hex color with alpha channel (for error pages).

```php
function meel_err_alpha(string $hex, int $alpha): string
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$hex` | `string` | Hex color without alpha (e.g., `'#3b82f6'`) |
| `$alpha` | `int` | Alpha value 0-255 |

**File:** `err/index.php:10`
**Return:** Hex color with alpha appended (e.g., `'#3b82f6'` + `alpha=204` → `'#3b82f6cc'`).

---

## Testing & Mecab

### `meel_mecab_available()`

Checks if mecab is installed and working on the system.

```php
function meel_mecab_available(): bool
```

**File:** `tests/bootstrap.php:24`
**Return:** `true` if mecab is available and can run basic commands.
**Cache:** Result is statically cached (only checked once per request).

---

## Developer Notes

1. **`function_exists` Guard**: All `meel_*` functions are wrapped with `if (!function_exists('...'))` to prevent duplicate declaration errors if a file is required more than once.

2. **Consistent Naming**:
   - `meel_media_*` — for media paths (video/music/books)
   - `meel_drive_*` — for Drive paths (cloud storage)
   - `meel_mig_*` — for database migration helpers
   - `meel_arc_mig_*` — for Arcade migration helpers
   - `meel_admin_*` — for admin utilities
   - `meel_ffmpeg_*` — for FFmpeg operations
   - `meel_*_ok` — for validation (returns bool or error message)

3. **Error Handling**:
   - Upload functions return `[bool, string]` (ok, error message)
   - FFmpeg functions return `[int, string]` (exit code, log)
   - Path functions return string (absolute path)
   - Validation functions return `''` if valid, error message if not

4. **Path Handling**:
   - Always use `meel_media_base_path()` for media paths, never hardcode
   - Use `rtrim($path, '/\\')` for path normalization
   - Use `realpath()` to resolve symlinks and detect path traversal
