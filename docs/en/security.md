# 🔒 MEeL Security System

Documentation about authentication, authorization, and protection systems in MEeL-HUB.

---

## Table of Contents

- [Security Architecture](#security-architecture)
- [Role-Based Access Control (RBAC)](#role-based-access-control-rbac)
- [Session Management](#session-management)
- [CSRF Protection](#csrf-protection)
- [IP Banning & Firewall](#ip-banning--firewall)
- [Activity Logging](#activity-logging)
- [API Rate Limiting](#api-rate-limiting)
- [Multi-Factor Authentication (MFA)](#multi-factor-authentication-mfa)
- [File Upload Security](#file-upload-security)
- [Apache .htaccess Protection](#apache-htaccess-protection)
- [SSRF Protection (Advanced Upload)](#ssrf-protection-advanced-upload)
- [Private Drive Protection](#private-drive-protection)
- [Exception Handling](#exception-handling)
- [Disk Space Validation](#disk-space-validation)
- [P0 Hardening (October 2026)](#p0-hardening-october-2026)
- [P1 Hardening (October 2026)](#p1-hardening-october-2026)
- [P2 Hardening (October 2026)](#p2-hardening-october-2026)
- [P4 Hardening — Comment Section (October 2026)](#p4-hardening--comment-section-october-2026)
- [Input Validation](#input-validation)
- [Best Practices](#best-practices)

---

## Security Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Browser Request                      │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              1. Apache .htaccess Layer                  │
│  • Block direct access to sensitive directories         │
│  • mod_rewrite rules                                    │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              2. Session Authentication (auth.php)       │
│  • Check user_id in session                             │
│  • Validate last_session_id (anti-hijack)               │
│  • Session timeout (12 hours)                           │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              3. IP Ban Check (activity_logger.php)      │
│  • Check IP against banned list                         │
│  • Block all non-admin users if IP is banned            │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              4. Role-Based Access (RBAC)                │
│  • Admin / Member / User / Guest                        │
│  • Feature gating per page                              │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              5. CSRF Protection                         │
│  • Token validation on all POST requests                │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              6. Multi-Factor Authentication (MFA)       │
│  • TOTP (Time-based One-Time Password) via Authenticator │
│  • Backup codes for recovery                            │
│  • Brute-force protection (10 attempts → 5 min lock)    │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              7. Prepared Statements                     │
│  • All database queries use mysqli prepared statements  │
│  • No raw SQL concatenation with user input             │
└─────────────────────────────────────────────────────────┘
```

---

## Role-Based Access Control (RBAC)

### Role Definitions

| Role | Level | Access Rights |
|---|---|---|
| **Admin** | 100 | Full system control |
| **Member** | 50 | Media + Cloud Drive (20GB quota) |
| **User** | 30 | Media + comments (no Drive) |
| **Guest** | 0 | View-only, no interaction |

### Feature Gating per Role

| Feature | Admin | Member | User | Guest |
|---|---|---|---|---|
| Watch Video | ✅ | ✅ | ✅ | ✅ |
| Listen Music | ✅ | ✅ | ✅ | ✅ |
| Like/Dislike | ✅ | ✅ | ✅ | ❌ |
| Comments | ✅ | ✅ | ✅ | ❌ |
| Upload Video | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Upload Music | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Books | ✅ | ✅ | ✅ | ❌ |
| Cloud Drive | ✅ (unlimited) | ✅ (20GB) | ❌ | ❌ |
| Advanced Upload | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Transcoder | ✅ | ✅ | ✅ | ❌ |
| Admin Panel | ✅ | ❌ | ❌ | ❌ |
| Chess Multiplayer | ✅ | ✅ | ✅ | ❌ |

### Admin Page Guard (`require_admin()`)

All admin pages use `require_admin($conn)` as a centralized authorization gate:

```php
// modules/auth/helpers/authz.php
function require_admin(mysqli $conn): void
{
    if (!is_admin($conn)) {
        header('Location: ' . meel_base_url_path() . '/err?code=not_found', true, 302);
        exit;
    }
}
```

Non-admin users are redirected to `/err?code=not_found` (HTTP 404) instead of showing a 403 "Access Denied" page. This is a **security-by-obscurity** pattern — unauthorized users see a generic "Not Found" page, hiding the existence of admin-only pages.

The same redirect pattern is used in:
- `controllers/admin/admin_actions.php` — `MEEL_ADMIN_CONTEXT` guard + `is_admin()` guard
- `controllers/admin/admin_data.php` — `MEEL_ADMIN_CONTEXT` guard

---

## Session Management

### Session Configuration

```php
// modules/auth/helpers/session.php — meel_boot_session()
// (auth/config.php & auth/auth_helpers.php delegate to this function)
$timeout = 43200;              // 12 hours
ini_set('session.gc_maxlifetime', $timeout);

// Hardened cookie flags (auto-detect HTTPS / X-Forwarded-Proto)
$secure_cookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

session_set_cookie_params([
    'lifetime' => $timeout,
    'path'     => '/',
    'secure'   => $secure_cookie,  // HTTPS only
    'httponly' => true,            // not readable by JavaScript
    'samesite' => 'Lax',           // CSRF mitigation
]);
session_name('meel');
session_start();
```

| Flag | Value | Protection |
|---|---|---|
| `Secure` | auto (HTTPS) | Cookie never sent over plain HTTP — prevents sniffing |
| `HttpOnly` | `true` | XSS cannot steal the session cookie via JavaScript |
| `SameSite` | `Lax` | Cross-site POST requests don't carry the cookie (CSRF layer 1) |

The configuration above is now **centralized** in `modules/auth/helpers/session.php` as the `meel_boot_session()` function — the single source of truth for session bootstrapping. Every entry point (index, video, music, auth, controllers/api, err, admin) calls it instead of the scattered manual `session_name('meel'); session_start();` pattern, so the session cookie is guaranteed to always use the hardened flags. The function is **idempotent** (no-op if the session is already active); `auth/config.php` and `auth_boot_session()` in `auth/auth_helpers.php` now delegate to it:

```php
// Usage in a new entry point
require_once __DIR__ . '/../modules/core/helpers.php';
meel_boot_session();
```

### Session Hijacking Prevention

Every user has a `last_session_id` in the database. If the browser's session ID differs, the session is considered hijacked/kicked:

```php
if ($user_status['last_session_id'] !== $current_sid) {
    session_unset();
    session_destroy();
    header("Location: .../err/?code=revoked");
    exit();
}
```

### Admin Kick Feature

Admins can forcefully kick users from the admin panel:
```php
$stmt_kick = $conn->prepare("UPDATE users SET 
    last_session_id = 'KICKED', 
    last_page = 'KICKED BY ADMIN', 
    last_activity = DATE_SUB(NOW(), INTERVAL 10 MINUTE) 
    WHERE username = ?");
```

---

## CSRF Protection

### Token Generation

```php
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
```

### Token Validation

```php
// verify_csrf_token() — defined in modules/auth/helpers/csrf.php
// Uses hash_equals() for timing-attack safety
function verify_csrf_token(?string $token = null): bool
{
    if ($token === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}
```

### HTMX Integration

```php
$token = $_SESSION['csrf_token'];
echo "<input type='hidden' name='csrf_token' value='$token'>";
```

### Admin Actions — POST Forms (not GET links)

Admin state-changing actions (approve/reject/delete user, kick user, unban IP, force-stop queue, MFA reset)
use **POST forms with CSRF token** — a GET link can be triggered by a `<img>` tag (CSRF),
a POST form cannot:

```html
<form method="POST" class="inline" onsubmit="return meelConfirmForm(event, {...})">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="approve_id" value="<?= (int)$u['id'] ?>">
    <button type="submit">APPROVE</button>
</form>
```

Handlers in `controllers/admin/admin_actions.php` now read `$_POST` and the
chess admin `catur.php?auto_cleanup=1` endpoint requires a `csrf_token` too
(`window.MEEL_ADMIN_CSRF` bridge).

> ⚠️ **Form action must NOT use `action="."`** — when the page URL is `/admin/beranda`,
> `action="."` resolves to `/admin/`, which gets 301-redirected to `/admin/beranda` by
> the `.htaccess` canonical rule. The 301 converts POST to GET, losing all form data.
> Use no `action` attribute (defaults to current page URL) or `action=""`.

### API Endpoints — POST Enforcement + CSRF

All state-changing API endpoints now enforce **POST method** and **CSRF token verification**:

| Endpoint | CSRF | Method | Notes |
|---|---|---|---|
| `api/notification.php` (mark_read, delete, delete_all) | `$_POST['csrf_token']` | POST only | GET returns 405 |
| `api/chat.php` (send, delete) | `$_POST['csrf_token']` | POST only | Admin-only, GET returns 405 |
| `api/delete_comment.php` | `$_POST['csrf_token']` | POST only | GET returns 405 |
| `api/like.php` | `hx-vals` csrf_token | POST (HTMX) | — |
| `api/comment.php` | `$_POST['csrf_token']` | POST (HTMX) | — |

Read-only endpoints (`unread_count`, `list`, `users`, `get`) remain accessible via GET.

### Chess Multiplayer — Login + CSRF Guards

All `arcade/chess/controller/*.php` endpoints require:
- **Login** — JSON `401` + `login_required: true` (client `arcade/chess/assets/js/api.js` redirects to login)
- **CSRF** — every state-changing POST carries `csrf_token` (JSON body for `save_move`, `FormData` for `create_room`/`join_room`)
- The token is **never stored** in `moves.move_data` (not exposed to opponents)

---

## IP Banning & Firewall

### IP Detection (Anti-Proxy)

```php
function trust_proxy_headers(): bool {
    return defined('MEEL_TRUST_PROXY_HEADERS') && MEEL_TRUST_PROXY_HEADERS === true;
}

function get_real_ip() {
    $valid = fn($ip) => is_string($ip) && $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;

    if (trust_proxy_headers()) {
        // Cloudflare Tunnel / CDN
        if (isset($_SERVER["HTTP_CF_CONNECTING_IP"]) && $valid($_SERVER["HTTP_CF_CONNECTING_IP"])) {
            return $_SERVER["HTTP_CF_CONNECTING_IP"];
        }
        // X-Forwarded-For (first hop)
        if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $xff = trim(explode(',', $_SERVER["HTTP_X_FORWARDED_FOR"])[0]);
            if ($valid($xff)) return $xff;
        }
    }
    $remote = $_SERVER["REMOTE_ADDR"] ?? '0.0.0.0';
    return $valid($remote) ? $remote : '0.0.0.0';
}
```

### Trusted Proxy Gate (`MEEL_TRUST_PROXY_HEADERS`)

Proxy headers should only be trusted if the request goes through a proxy/CDN you control. Configuration in `auth/settings.php`:

```php
// MUST be true when using Cloudflare Tunnel, Nginx reverse proxy, or CDN
define('MEEL_TRUST_PROXY_HEADERS', true);
```

> If set to `true` when the server is accessed directly, an attacker can spoof `X-Forwarded-For` to bypass IP bans or flood the activity log.

> **Behind cloudflared:** Tunnel always connects to Apache from localhost (`REMOTE_ADDR = 127.0.0.1`). Without `MEEL_TRUST_PROXY_HEADERS = true`, all IPs are detected as localhost, rate limiting is disabled, and the environment is detected as "development".

### Auth IP Helpers

`auth_get_ip()` and `auth_is_loopback()` in `auth/auth_helpers.php` also use `get_real_ip()` — not `$_SERVER['REMOTE_ADDR']` directly. This ensures consistent IP detection across the system (login, rate limiting, ban check).

### Ban Check (Real-time)

Checked on every page load. Non-admin users are redirected to `err/?code=banned` with the ban reason.

---

## Activity Logging

### Logger Function

```php
function log_activity(
    mysqli $conn, 
    int $user_id, 
    string $action, 
    string $media_type = '', 
    ?int $media_id = null
): void;
```

### Integrated Events

| Event | Action | Location |
|---|---|---|
| Successful login | `login` | `auth/login` |
| Logout | `logout` | `auth/logout` |
| Video upload | `upload_video` | `video/upload` |
| Music upload | `upload_music` | `music/upload` |
| Book upload | `upload_book` | `books/upload` |
| URL download | `upload_url` | `upload_advanced.php` |
| IP Ban | `ban_ip` | `controllers/admin/admin_actions.php` |
| IP Unban | `unban_ip` | `controllers/admin/admin_actions.php` |
| Approve user | `approve_user` | `controllers/admin/admin_actions.php` |
| Reject user | `reject_user` | `controllers/admin/admin_actions.php` |
| Delete user | `delete_user` | `controllers/admin/admin_actions.php` |
| Kick user | `kick_user` | `controllers/admin/admin_actions.php` |

### Admin Activity Log Viewer

Page `admin/activity_log.php` provides a dedicated audit trail viewer with 3 tabs:

| Tab | Theme | Features |
|---|---|---|
| 📋 **Activity** | Blue-600 | Filter by action type, username/IP, date range (7–365 days); pagination (50/page); stats cards (7-day activity, unique users, total entries); color-coded action badges (login=blue, upload=green, ban=red); manual log cleanup with CSRF |
| 🛡️ **Admin Actions** | Purple-600 | Filter by admin username, action type, date range; stats cards (7-day admin actions, unique admins, total entries); color-coded badges (coin=yellow, reset=red, login=blue, other=gray); maintenance: clear older than 7–365 days |
| 📤 **Upload Queue** | Green-600 | Filter by status (pending/processing/transcoding/completed/failed), uploader, date range; stats cards (total uploads, completed, failed, active); color-coded status badges; export CSV/JSON/XLS with preview modal; maintenance: clear completed/failed older than 7–365 days |

---

## API Rate Limiting

### Architecture

`modules/auth/RateLimiter.php` provides **file-based rate limiting** that protects API endpoints from abuse:

```
Request → RateLimiter::check(key, endpoint)
  ↓
Read cache file at temp/ratelimit/{md5_hash}.cache
  ↓
flock(LOCK_EX) → Increment counter → ftruncate + fwrite
  ↓
Counter > max? → Yes → HTTP 429 Too Many Requests
  ↓ No
Allow request
```

### Endpoint Limits

| Endpoint | Max Requests | Window | Response on Limit |
|---|:---:|:---:|---|
| **Like/Dislike** | 30 | 1 minute | HTTP 429 + HTMX HTML snippet (yellow badge "Wait Xs" + disabled buttons) |
| **Comment** | 10 | 1 minute | Redirect with flash error message |
| **Upload** (video/music/books) | 3 | 1 hour | — |
| **Transcode** | 5 | 1 hour | — |
| **Auto Metadata** (ffmpeg) | 5 | 1 hour | HTTP 429 + JSON error |
| **API Generic** | 60 | 1 minute | — |

### Fail-Closed Behavior

When the rate limiter storage directory (`temp/ratelimit/`) is not writable or
`flock()` fails, the rate limiter **denies all requests** (fail-closed) instead
of silently allowing them through. This prevents a broken filesystem from
disabling rate limiting entirely. Failures are logged via `error_log()`.

### Cleanup

Expired files (>1 hour) are automatically cleaned by `GarbageCollector::run()` called on every request.

---

## Multi-Factor Authentication (MFA)

### MFA Architecture

```
Login Flow:
  POST login → password correct
    ↓
  Check users.mfa_enabled == 1?
    ↓ Yes                         ↓ No
  Save mfa_temp_uid to session    Set session directly
    ↓                              ↓
  Redirect to auth/mfa-verify     Redirect to index
```

### TOTP Implementation

MEeL uses **TOTP (Time-based One-Time Password)** with algorithm:
- **HMAC-SHA1** — standard TOTP
- **6 digits** — verification code
- **30 seconds** — time step
- **Window ±1** — 90 seconds tolerance

### Secret Generation

```php
// modules/auth/helpers/mfa.php
function generate_mfa_secret(): string {
    $random = random_bytes(20);  // 160-bit random
    $base32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    foreach (str_split($random) as $byte) {
        $secret .= $base32[ord($byte) & 31];
        // XOR carry for 5-bit encoding
    }
    return $secret;
}
```

Secret stored in `mfa_secret` column (VARCHAR(64)) in `users` table. TOTP secrets must be readable for verification. If database leaks, attacker still needs access to Google Authenticator or backup codes.

### Backup Codes

- **8 backup codes** — each 6 numeric digits
- **Stored as `password_hash()` (bcrypt) hashes** — one-way, cannot be reversed
- **Single-use** — hash removed from array after use

```php
function generate_backup_codes(): array {
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < 8; $i++) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); // 6 digits
        $plain[] = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT); // bcrypt
    }
    return ['plain' => $plain, 'hashed' => $hashed];
}

function verify_backup_code(string $hashedCodes, string $code): array {
    $codes = json_decode($hashedCodes, true) ?? [];
    foreach ($codes as $i => $hash) {
        if (password_verify($code, $hash)) {
            array_splice($codes, $i, 1); // Remove used code
            return ['valid' => true, 'remaining' => $codes];
        }
    }
    return ['valid' => false, 'remaining' => $codes];
}
```

### Brute-Force Protection

```php
// Max 10 failed MFA attempts, lock 5 minutes
$max_mfa_attempts = 10;
$mfa_lockout_time = 300; // 5 minutes

if (isset($_SESSION['mfa_locked_until'])) {
    if (time() >= $_SESSION['mfa_locked_until']) {
        unset($_SESSION['mfa_locked_until'], $_SESSION['mfa_fail_count']);
    } else {
        $mfa_locked = true;
        $mfa_remaining = $_SESSION['mfa_locked_until'] - time();
    }
}
```

### Admin Reset MFA

Admins can reset a user's MFA from `admin/mfa_reset.php`:
- **Method:** POST form (not GET link) — prevents CSRF via `<img>` tags
- **Cannot reset another admin** — only the admin themselves can disable their own MFA
- **Action logged** — `log_activity($conn, $admin_id, 'reset_mfa', 'user', $target_id)`
- **User needs to re-setup** — MFA reset to default (disabled)

### MFA in Profile

Page `profile/index.php` displays MFA status with visual toggle switch:
- Green "Active" → link to `auth/mfa-setup` for management/disable
- Gray "Inactive" → link to `auth/mfa-setup` for setup
- If active, backup codes also displayed in profile

### Guest Profile Access

Guests (unauthenticated users) can view any user's profile page without login. The profile page uses `meel_boot_session()` (not raw `session_start()`) to ensure the session cookie name matches the rest of the application (`meel`).

- **Guest viewing own profile:** Synthetic profile with `id=0`, `role='guest'`, bio="Akun Guest" — theme toggle available
- **Guest viewing other users:** Real profile from DB — bio, stats, badge shown correctly; no Edit/Manage/MFA buttons; no theme toggle
- **Logged-in user viewing own profile:** Full access — Edit Profile, Kelola Konten, MFA, Backup Codes, Theme Toggle

Session initialization uses `meel_boot_session()` to set `session_name('meel')` and hardened cookie flags. Using raw `session_start()` would look for a `PHPSESSID` cookie instead of `meel`, causing the session to appear empty.

---

## File Upload Security

### Extension Validation

```php
// Video
$allowed_ext = ['mp4', 'webm', 'mkv'];
if (!in_array($ext, $allowed_ext, true) || 
    preg_match('/\.(php|phtml|sh)/i', $files['video']['name'])) {
    return ['status' => 'error', 'msg' => "Security Error / Format rejected!"];
}
```

### File Size Limits

```php
$max_size = ($this->user_role === 'admin') ? 200 * 1024 * 1024 : 50 * 1024 * 1024;
```

### Magic Bytes Validation (Drive)

```php
$header = fread($handle, 16);
if ($detectedType === 'video') { /* WebM/MKV: \x1A\x45\xDF\xA3 */ }
if ($detectedType === 'audio') { /* MP3: 0xFFFB, FLAC: 0x664C6143 */ }
```

### Centralized Magic Bytes Validation (`meel_magic_extension_ok`)

The single source of truth for server-side file-signature validation — every upload path (Uploader video/music, thumbnails, BookUploader, DriveStorage) uses it, replacing duplicated inline checks:

```php
// modules/core/helpers/upload.php
meel_magic_extension_ok(string $path, string $ext, string $mediaKind): string
// mediaKind: 'audio' | 'video' | 'image' | 'pdf' | 'archive'
// returns '' when valid, an error message otherwise
```

Recognized types: Ogg/Opus, FLAC, WAV/RIFF, MP3 (ID3/frame sync), MP4/M4A (ftyp), Matroska/WebM, JPEG/PNG/WebP/GIF, PDF, and ZIP archives. Validation never relies on `$_FILES['type']` or the extension alone.

### Atomic Filename Allocation (race-condition free)

Physical filenames are no longer allocated with a check-then-move pattern (`while (file_exists(...))`). Every media filename is **atomically** reserved via `meel_reserve_unique_filename()` (placeholder `fopen(..., 'x')` / O_EXCL):

```php
// modules/core/helpers/upload.php — used by Uploader, EncodeService,
// admin editors, and the arcade rhythm module
meel_reserve_unique_filename(string $dir, string $clean_name, string $ext): ?string
```

Two concurrent requests can never receive the same name; callers overwrite the placeholder with `move_uploaded_file()` / ffmpeg `-y`. Video work-folder names are allocated through a similar helper (`meel_allocate_unique_dir()`).

### ZIP/CBZ Archive Protection (`ArchiveGuard`)

Manga/book extraction **never** calls `ZipArchive::extractTo()` directly into the final folder. `modules/media/ArchiveGuard.php` runs a validate → staging → move pipeline:

```
open archive
  → inspect every entry (traversal, null byte, count, size, ratio,
    depth, symlink, extension)
  → extract one-by-one into a server-generated staging dir (random name)
  → validate the result
  → move into the final folder (rename; existing files are NOT overwritten;
    symlinks are never moved)
```

Limits are configurable via constants (defaults):

| Constant | Default | Meaning |
|---|---|---|
| `MAX_ARCHIVE_ENTRIES` | 5000 | Maximum number of entries |
| `MAX_ARCHIVE_UNCOMPRESSED_BYTES` | 2 GiB | Total expanded size |
| `MAX_ARCHIVE_ENTRY_BYTES` | 200 MiB | Single file size |
| `MAX_ARCHIVE_COMPRESSION_RATIO` | 300 | Per-entry compression ratio (zip bomb) |
| `MAX_ARCHIVE_PATH_DEPTH` | 16 | Maximum directory depth |

Rejected: absolute paths, `../`, `..\`, null bytes, symlinks, excessive depth, non-image entries (only `jpg/jpeg/png/webp/gif/bmp/avif` for CBZ/manga), and entry count/size/ratio over the limits. Rejections are written to the error log without storing archive contents.

### Quota & Resource Limits

- **Disk pre-flight** — `require_disk_space()` rejects an upload before any byte is written (music 500MB, video 1GB + `/dev/shm` 512MB)
- **Concurrent uploads** — max 3 simultaneous uploads (`flock`-protected counter file + 5-minute TTL + shutdown auto-decrement)
- **Rate limit** — uploads are hourly-limited per role (admin unlimited)
- **Archives** — the entry/size/ratio limits above (zip-bomb protection)
- **Transcode** — audio timeout 600s (`TRANSCODE_AUDIO_TIMEOUT`), HLS remux 120s, `stream_set_timeout(30)`, and output duration validated ≥ 50% of source

---

## Apache .htaccess Protection

### Protected Directories

- `auth/` — Database configuration & session
- `modules/` — Core business logic
- `partials/` — UI components (include-only)
- `tests/` — Test scripts (CLI only)
- `controllers/` — API endpoints
- `logs/` — System logs
- `books/upload/` — Book files (served via `books/file.php` — **login required**, see note below)
- `music/upload/` — Music files
- `video/upload/` — Video files
- `data_drive/private_admins/` — Private Drive files (denied for direct HTTP access, see [Private Drive Protection](#private-drive-protection))

> **Media uploads are not served directly by the web server.** Since the portability refactor, `books/upload/`, `music/upload/`, and `video/upload/` are real tracked directories (placeholder `.gitkeep` + hardened `.htaccess`) whose contents are served through PHP endpoints mapped by an internal rewrite in the root `.htaccess`: `video/upload/...` → `video/stream.php`, `music/upload/...` → `music/file.php`, `books/upload/...` → `books/file.php` (see [Installation §5a](installation.md#5a-media-storage-meel_hdd_base--php-endpoint--rewrite-no-symlinks)). All of them — plus the final serve step of `music/stream.php` — delegate to one shared function, `meel_serve_media_file()` in `modules/core/helpers/storage.php`, which implements: realpath containment against path traversal (`..`, null byte → `403`), an extension whitelist, automatic MIME mapping, HTTP Range support (`206 Partial Content`), output buffer cleanup before streaming, and an optional referer gate for HLS. When `MEEL_USE_XSENDFILE` is on, the `mod_xsendfile` module is loaded, and the file falls under `XSendFilePath` in Apache's configuration, delivery switches to the `X-Sendfile` header (Apache sends the file straight from disk; `Range`/`206` are computed by Apache and PHP never reads the file body) — automatically falling back to regular PHP streaming when any condition is not met, so the response is never empty. The fourth condition is the `XSendFile on` directive in effect for the request (read from Apache's configuration **and** the `.htaccess` files along the URL path/script directory, most specific wins; a missing directive counts as `off`): if Apache would ignore the header (flag `off`), the app never sends it — preventing an empty response as well as leaking the server's absolute paths to the client. Security note: every gate (login, CSRF, `authorize()`, realpath containment, extension whitelist, HLS referer gate) runs **before** the X-Sendfile branch, so enabling or disabling X-Sendfile does not change the authorization model.
>
> **`books/file.php` requires login** (`auth/auth.php`), consistent with the rest of the Books module — `index.php`, `read.php`, `read_pdf.php`, and `controllers/api/pdf.php` are all login-gated. Without this, manga images and PDFs could be downloaded directly without authentication (the raw paths were reachable with `HTTP 200` before the fix). Music audio stays behind `music/stream.php` (session marker + strict referer gate); `music/file.php` and `video/stream.php` intentionally have **no** login gate because the Music/Video index & watch pages are public by design (thumbnails must render for anonymous visitors). `video/stream.php` additionally passes `hls_gate => true` — `.m3u8`/`.ts` segment requests must carry a same-host referer from a video page (`/video`, `/video/watch`), otherwise the request is redirected to `err/?code=denied` (anti-hotlink). `video/stream.php` and `music/stream.php` also call `session_write_close()` before streaming so parallel segment requests don't block each other on the PHP session file lock. Token gate: the watch pages register a path → media-id mapping in the session (`meel_register_stream_path`, **100-entry** FIFO cap) and the stream endpoints verify it via `meel_stream_path_allowed` — verified access **without a DB query per segment**; thumbnails (`thumbnail/`) and display files stay public for cards/OG-images. The mapping applies to the **entire video-folder subtree** (ancestor walk from the path up to a registered key): multibitrate variant playlists (`#EXT-X-STREAM-INF` → subfolders like `v1/`, `720p/`, …) and their segments are verified as long as that video folder is registered — other video folders remain denied, and `video/` can never become a subtree root.

---

## SSRF Protection (Advanced Upload)

The Advanced Upload feature (`upload_advanced.php`) lets authenticated users download media from external URLs via yt-dlp. Because yt-dlp runs **on the server**, an attacker-supplied URL that resolves to an internal address could be abused as a Server-Side Request Forgery (SSRF) vector — probing loopback services, cloud metadata endpoints (`169.254.169.254`), or other machines on the local network.

### Architecture

`modules/auth/SsrfGuard.php` is the **single source of truth** for every outbound request made on behalf of a user:

```
User URL → SsrfGuard::validate()
    ↓
Protocol allowlist (http/https only)
    ↓
Hostname denylist (defense-in-depth: localhost, .local, .internal, …)
    ↓
DNS resolution → EVERY A/AAAA record checked against explicit
private/reserved IPv4 & IPv6 ranges (one bad record = reject)
    ↓
Transcoder::processDownload() pins plain-HTTP connections to the
validated public IP + forces the original Host header
```

### Validation Rules

| Rule | Detail |
|---|---|
| **Protocol** | Only `http` and `https`. Scheme-less, protocol-relative (`//host/…`), and any other scheme are rejected |
| **Credentials** | `user:pass@` embedded in the URL is rejected (commonly used to obscure the real destination host) |
| **Hostname denylist** | `localhost`, `*.local`, `*.internal`, `*.lan`, `*.test`, `*.onion`, … (defense-in-depth only) |
| **DNS** | Every returned A/AAAA record is validated — a single non-public record rejects the URL (blocks mixed-answer DNS tricks) |
| **IP literals** | IPv4 & IPv6 literals are validated directly with no DNS round-trip |
| **Fail closed** | Unresolvable hosts, IDN/punycode hosts, and unparsable IPs are rejected |
| **Length limit** | URLs longer than 2048 characters are rejected |

### Blocked IP Ranges

**IPv4:** `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10` (CGNAT), `127.0.0.0/8`, `169.254.0.0/16` (link-local), `172.16.0.0/12`, `192.0.0.0/24`, `192.0.2.0/24` (TEST-NET-1), `192.168.0.0/16`, `198.18.0.0/15`, `198.51.100.0/24` (TEST-NET-2), `203.0.113.0/24` (TEST-NET-3), multicast/reserved `224.0.0.0/4`.

**IPv6:** `::/128`, `::1/128`, `::ffff:0:0/96` (IPv4-mapped — re-checked with the IPv4 logic), `64:ff9b::/96` (NAT64), `100::/64` (discard-only), `2001:db8::/32`, `2001:10::/28` (ORCHID), `2002::/16` (6to4), `3fff::/20`, `fc00::/7` (unique-local), `fe80::/10` (link-local), `fec0::/10` (site-local), `ff00::/8` (multicast).

### DNS Rebinding & HTTP Pinning

A naive "validate once, then request" flow is vulnerable to **DNS rebinding**: the hostname resolves to a public IP during validation, then to a private IP when the real request is made. To close this window, `SsrfGuard::pinHttpUrl()` rewrites validated `http://` URLs so the connection goes directly to the **validated public IP**, while yt-dlp receives `--add-header Host: <original-hostname>`:

```php
[$dl_url, $dl_extra] = $ssrf->pinHttpUrl($url);
// $dl_url   = http://<public-ip>[:port]/path  (no second, attacker-influenced DNS lookup)
// $dl_extra = --add-header 'Host: example.com'
```

`https://` URLs are returned untouched — TLS SNI and certificate validation require the hostname.

### Validating Forward Proxy (per-redirect enforcement)

A single pre-flight validation cannot protect against **open redirects**: yt-dlp follows redirects itself and re-resolves each target without passing it back through `SsrfGuard`. To close this gap, the download pipeline routes **all** yt-dlp traffic (metadata + download + every redirect hop) through a validating forward proxy:

```
URL → SsrfGuard::validate() + pinHttpUrl()   (pre-flight, fast fail)
   ↓
yt-dlp --proxy http://127.0.0.1:<ephemeral>  (spawned per Transcoder)
   ↓
Validating forward proxy (validating_proxy_server.php)
   • HTTP  absolute-URI → validate host → re-write to origin-form → forward
   • HTTPS CONNECT host:port → validate host → 200 tunnel → byte relay
   • Rejected target (private/reserved IP, blocked hostname, unresolvable
     host, malformed request) → 502, never forwarded
   ↓
Stream relayed back to yt-dlp
```

Key properties:

| Property | Detail |
|---|---|
| **Every hop validated** | A redirect to `127.0.0.1`, `10.x`, cloud metadata, etc. becomes a new CONNECT/absolute-URI request to the proxy, which applies `SsrfGuard::resolvePublicAddresses()` to the **destination of that hop** and refuses it |
| **Loopback-only** | The proxy binds to `127.0.0.1` on an ephemeral port — no external party can use it as an open proxy |
| **DNS-rebinding safe per hop** | Each hop is resolved once and the connection goes to the validated public IP (no separate, later lookup) |
| **Host header preserved** | The client's `Host:` header is kept for the upstream request, so virtual-host routing and TLS SNI are unaffected |
| **Fail closed** | If the proxy cannot start, the download is **refused** — there is no unprotected fallback |
| **Strict header limits** | Header lines containing wild CR/LF/NUL are rejected (prevents HTTP request splitting / header injection that would slip past `explode("\r\n")`), maximum **100 lines** and **8 KB per line** (memory & processing bounds) |
| **Lifecycle** | Spawned lazily on first download via `ValidatingProxy` (proc_open of the CLI script), terminated by the `Transcoder` destructor — no orphan processes |

The `https://` limitation is therefore resolved: even though the original HTTPS URL cannot be pinned (TLS SNI), every redirect destination is re-validated by the proxy before a connection is opened.

### Defense in Depth

`Transcoder::fetchMetadata()` also calls `SsrfGuard::validate()` independently and forces the proxy flag, so even a future caller that skips `processDownload()` cannot pass an unvalidated URL — or an unprotected hop — to yt-dlp.

### Integration Points

| File | Role |
|---|---|
| `modules/auth/SsrfGuard.php` | Central validation: protocol allowlist, explicit IPv4/IPv6 range checks, DNS all-record validation, HTTP pinning |
| `modules/auth/ValidatingProxy.php` | Spawns/terminates the proxy process, exposes `--proxy` URL (loopback-only) |
| `modules/auth/validating_proxy_server.php` | CLI forward proxy: SsrfGuard on every hop (HTTP absolute-URI + CONNECT tunnel) |
| `modules/core/Transcoder.php` (facade → `modules/transcoder/DownloadService.php`) | `processDownload()` / `fetchMetadata()` call the guard and route yt-dlp through `--proxy`; fail closed if the proxy cannot start |
| `modules/autoload.php` | Autoloads the `SsrfGuard` class |

### `temp_file` & `post_encode` Security

`temp_file` never accepts a filesystem path from the client. The music-download flow:

```
yt-dlp download → staging on the RAM disk (`/dev/shm/meel/`) under an opaque
  server-generated token name (uniqid)
  → token + metadata stored in the session (`meel_pending_music`, 1-hour expiry)
  → the browser POSTs only the token to `controllers/api/post_encode.php`
```

`post_encode.php`:
- **POST method** + CSRF verification (state-changing endpoint)
- Token validation: regex `[A-Za-z0-9._-]+`, rejects `..`, absolute paths, and null bytes
- `pathinfo()` + session match → **ownership** (user A cannot use user B's token) + existence check + 1-hour expiry
- The encode input is locked to the server temp dir (`getShmTempPath()`)

Transcode downloads (`download_transcode.php`) use `Transcoder::ownsTranscodeFile()` — session-bound ownership — plus a filename allowlist regex, so another user's transcode files cannot be guessed/downloaded.

---

## Private Drive Protection

MEeL's Cloud Drive stores private user files under `<MEEL_HDD_DRIVE>/private_admins/<username>/...` when `MEEL_HDD_DRIVE` is set in `auth/settings.php`, or the repo-tracked fallback folder `data_drive/private_admins/<username>/...` when it is not (see [Installation §5a](installation.md#5a-media-storage-meel_hdd_base--php-endpoint--rewrite-no-symlinks)). Whenever the private subtree is reachable from the web server (the fallback folder lives in the document root; HDD deployments may expose it via a deploy-time symlink), the web server could serve files directly — bypassing application-level authorization. Two layered controls close this gap.

### Layer 1 — Web Server Deny (tracked in repo)

`data_drive/.htaccess` is committed to the repository, so the deny rule applies to **every deployment** — whether `private_admins/` is the tracked fallback folder or a deploy-time symlink to external storage:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^private_admins/ - [F,L]
</IfModule>
```

Any direct request to `data_drive/private_admins/...` returns **HTTP 403**, regardless of file type (mp3/mp4/jpg/png/pdf/zip — everything) or HTTP method. `Options -Indexes` alone is NOT sufficient — it only hides directory listings, it does not block direct access to a file whose exact name is known.

### Layer 2 — Hard Deny at the Storage Root

`data_drive/private_admins/.htaccess` (tracked in the repo fallback folder; recreated at deploy time on the external storage target):

```apache
Options -Indexes

<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
```

### Authorized Access Paths

Private files are only served through authenticated endpoints:

| Endpoint | Purpose | Checks |
|---|---|---|
| `drive/stream.php` | Inline preview / streaming (video, audio, images, PDF) | session + CSRF + ownership |
| `drive/download.php` | Download of private files | session + CSRF + ownership |

Both endpoints resolve files through `DriveStorage::getFileForDownload()`, which:

1. **Normalizes** scope (`public`/`private`) and type (`video`/`audio`/`dokumen`)
2. Applies `basename()` — strips any path-traversal component
3. Calls `verifyPrivateFileAccess()` — compares `realpath()` of the file against `realpath()` of the requester's own private root, so the file must be inside the requester's own directory (symlink escapes and `..` traversal fail)

`stream.php` also supports HTTP `Range` requests so seeking in video/audio keeps working.

### Preview URLs

`DriveStorage::listFilesByType()` never emits direct web paths for private files. Instead it builds `stream?file=…&type=…&scope=private&csrf_token=…` URLs (clean route — `stream.php?file=…` is still accepted via 301), so the browser never touches the raw storage path (which would also leak filesystem layout).

### Race-Condition Hardening (Drive Uploads)

Two TOCTOU races were closed in `DriveStorage::upload()`:

- **Quota** — the "check usage → check quota → write file" sequence runs inside a per-user `flock()` (lock file `temp/drive_quota_<md5(username)>.lock`) with usage computed fresh (bypassing the 5-minute cache), so concurrent uploads cannot collectively exceed the member quota. If the lock cannot be created, the quota check still runs non-atomically — it is never skipped
- **Filename collisions** — `fopen($path, 'x')` (O_CREAT|O_EXCL) atomically claims a unique filename before `move_uploaded_file()`, so two simultaneous uploads with the same name cannot both win (closes the `file_exists()` → move TOCTOU)

### Regression Tests

| Test file | Coverage |
|---|---|
| `tests/unit/SsrfGuardTest.php` | Protocol allowlist, private/public IP ranges (v4 & v6), DNS resolution incl. mixed records, hostname denylist, HTTP pinning |
| `tests/unit/ValidatingProxyTest.php` | Real-proxy probes: CONNECT/GET to private targets refused, public targets tunneled, loopback-only bind, process lifecycle |
| `tests/unit/DriveSecurityTest.php` | Cross-user access, path traversal, symlink escape, realpath boundary, quota enforcement, atomic filename reservation |
| `tests/security_test.php` / `tests/functional_test.php` | Static wiring checks: guard is called, proxy flag is wired, `.htaccess` deny rules exist, stream endpoint is used for private previews |

**Run everything with one command:** `scripts/verify_security.sh` runs the three security suites (PHPUnit security subset, `security_test.php`, `functional_test.php`) plus a live Private Drive 403 probe and exits with a CI-friendly code (see [testing.md](testing.md)).

---

## Exception Handling

### Custom Exception Classes

Since PHP 8+, MEeL uses 3 custom exceptions for specific error handling:

```php
// ProcessException — External process failure (FFmpeg, yt-dlp)
// Use for: I/O errors, exec() failures, environment issues
catch (RuntimeException $e) { /* disk space */ }
catch (ProcessException $e) { /* ffmpeg failed */ }
catch (TranscodeException $e) { /* HLS failed */ }
catch (DownloadException $e) { /* yt-dlp failed */ }
```

| Exception | Extends | Used For |
|---|---|---|
| `ProcessException` | `\RuntimeException` | External process failure: FFmpeg, yt-dlp, exec() non-zero |
| `DownloadException` | `\RuntimeException` | URL download failure: metadata parsing, connection |
| `TranscodeException` | `\RuntimeException` | Transcoding failure: HLS segments, codec, output missing |

### Best Practice

```php
try {
    $meta = $this->fetchMetadata($url);
} catch (ProcessException $e) {
    // Queue release + specific error
    $this->releaseQueue($queue_id, 'failed');
    throw $e;
}
```

---

## Disk Space Validation

Before download/transcoding operations, disk space validation:

```php
function check_disk_space(int $required_bytes, string $path): array {
    // Check free space, return ["ok" => bool, "free" => bytes, "required" => bytes]
}

function require_disk_space(int $required_bytes, string $path, string $label): void {
    // Throw RuntimeException if disk space insufficient
}
```

---

## Input Validation

### SQL Injection Prevention

All database queries use **Prepared Statements**:

```php
// ✅ SAFE
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $user_input);
$stmt->execute();

// ❌ UNSAFE - NOT USED
// $result = $conn->query("SELECT * FROM users WHERE username = '$user_input'");
```

### XSS Prevention

Output is always escaped with `htmlspecialchars()`:

```php
echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
```

### Stored XSS (hardening): the database stores raw (canonical) data; escaping happens **at output time**, per context:

- Plain-text HTML → `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`
- Attributes/URLs → attribute-context escaping + `urlencode`/allowlist
- JSON/JS → `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`

The audit covers every rendered user-controlled value: profile bios, media title/description, comments, filenames, playlists, search results, chat usernames, and data shown in the admin panel. For inline JS template literals (e.g. admin chat search results), a dedicated `escapeHtml()` function wraps user-derived values before HTML injection. Regression tests (`tests/security_test.php`) verify payloads such as `<script>alert(1)</script>` and `<img src=x onerror=alert(1)>` are never executed as HTML.

### Login Rate Limiting

```php
$max_login_attempts = 5;
$lockout_time = 300; // 5 minutes
```

### Theme System Security

Theme preference is stored securely:

1. **Client-side:** `localStorage` — no sensitive data, only string 'light' or 'dark'
2. **Server-side:** `users.custom_theme` — varchar(50), only accepts 'light' or 'dark'
3. **API validation:** `in_array($theme, ['light', 'dark'], true)` — rejects invalid values
4. **CSRF protection:** POST requires valid CSRF token
5. **No XSS risk:** Theme value is not escaped to HTML, only used for `data-theme` attribute

---

## Best Practices

### For Developers

1. **Always use Prepared Statements** for database queries
2. **Sanitize all input** POST/GET
3. **Verify CSRF token** on every form POST
4. **Don't trust user input** — validate file type, size, and content
5. **Escape output** with `htmlspecialchars()`
6. **Don't expose error details** to non-admin users

### Security Checklist

- [ ] Database credentials only in `auth/settings.php`
- [ ] All `.htaccess` files installed in sensitive directories
- [ ] Prepared statements in all SQL queries
- [ ] CSRF token in all POST forms
- [ ] Session timeout active (12 hours)
- [ ] IP banning system active
- [ ] File upload validation (type, size, magic bytes)
- [ ] Role checking before sensitive actions
- [ ] Theme preference doesn't store sensitive data
---

## P0 Hardening (October 2026)

Notes on the security changes landed in a single batch, with the reasoning behind
each. This section replaces the long comments that used to live inside production
files — explanation like this belongs in documentation.

### 1. `MEEL_ENV` is no longer influenced by client headers

`modules/core/bootstrap.php` decides `development` vs `production`. It used to
consult `$_SERVER['SERVER_NAME']`. On Apache with `UseCanonicalName Off` (the
default) `SERVER_NAME` is copied from the client's `Host:` header, so
`Host: localhost` from a remote attacker was enough to:

- turn on `display_errors=1` (leaking absolute paths, SQL, session internals site-wide)
- add `'unsafe-eval'` to the CSP
- make `Modules::flaggedOff()` ignore the `.disabled` kill switch

The fix has two sides:

- `bootstrap.php` now uses `REMOTE_ADDR` only (loopback is still recognised)
- `install.sh` writes `UseCanonicalName On` into the generated VirtualHost

If `MEEL_ENV` must be forced, set it explicitly in `auth/settings.php` — don't rely
on auto-detection.

### 2. Admin authorization always reads the database

`get_user_role()` used to treat `$_SESSION['role']` as authoritative. Since this
repo has no UI for demoting a role, revoking an admin always means running SQL by
hand — and that never took effect while the session stayed alive.

The database is now the source of truth; `$_SESSION['role']` remains only as a
display cache. Every file calling `is_admin($conn)` is fixed as a consequence:

| File | Before | After |
| --- | --- | --- |
| `admin/stats.php` | `is_admin()` → session cache | unchanged, now reads DB |
| `controllers/api/chat.php` | `is_admin()` → session cache | unchanged, now reads DB |
| `admin/partials/live-monitor-manage.php` | `require_admin()` → session cache | unchanged, now reads DB |
| `update.php` | read `$_SESSION['role']` directly | `is_admin($conn)` |
| `profile/manage.php` | read `$_SESSION['role']` directly | `is_admin($conn)` |
| `controllers/system/UpdateManager.php` | read `$_SESSION['role']` directly | `is_admin($this->db)` |

`UpdateManager` matters most: it is the **write** path for `sidebar_settings` and
`updates`, which are rendered to every anonymous visitor.

> The remaining `$_SESSION['role']` reads (badges, "edit" links) are display only —
> the real gates re-check on the server.

### 3. Disabling MFA requires a password and a TOTP code

It used to require nothing but a session cookie and a CSRF token. A hijacked
session (or an unlocked shared browser) could drop the account to 1FA **and**
destroy the backup codes. It now uses `password_verify` plus a currently valid TOTP
code — the same pattern as the backup-code regeneration in
`controllers/system/mfa.php`.

The TOTP seed is also no longer re-rendered for any logged-in session. The old code
rebuilt `otpauth://` from `mfa_secret` in the DB, so a hijacked session could copy
the seed and keep 2FA on the attacker's own device.

### 4. TOTP codes are single-use (anti-replay)

`verify_totp()` used a ±1 time-step window and stored nothing, so a single code
stayed valid for roughly **90 seconds** and could be replayed without limit — a
phished, shoulder-surfed, or proxy-logged code still worked after the legitimate
user had already consumed it. RFC 6238 §5.2 requires a monotonic counter.

The fix:

- `verify_totp_step()` returns the matched time-step (not `bool`)
- `auth/mfa_verify.php` stores it in the new `users.mfa_last_step` column
- the update is `UPDATE ... WHERE mfa_last_step IS NULL OR mfa_last_step < ?`, so
  two concurrent requests carrying the same code cannot both succeed

`verify_totp()` remains as a safe wrapper for ordinary callers. When a backup code
is used, the step is reset so a pending TOTP code isn't rejected.

### 5. `view_logs` actually stops view inflation

`UNIQUE (user_id, video_id, music_id)` **never blocked anything**: MySQL treats NULL
as distinct from other NULLs in a unique index, so `(9, 299, NULL)` and
`(9, 299, NULL)` stay two separate rows. Every page load incremented `views` —
the exact opposite of what the `schema.sql` comment claimed.

The working pattern is already used by the `interactions` table: two separate unique
keys, one per media type.

> ⚠️ **Trap:** the unused column **must stay `NULL`, not `0`**. Filling it with `0`
> makes every music row share `video_id=0`, and they collide in `uq_view_video`.
> The fact that NULL *escapes* uniqueness is exactly what makes this two-unique-key
> pattern work.

Migration v7 also removed the duplicate rows that had already piled up — precisely
because the old unique key never worked.

### 6. Playlist IDOR

- **Delete:** `delete_playlist` removed `playlist_tracks` rows **before** checking
  ownership. The `user_id`-scoped `DELETE playlists` still reports success at 0 rows,
  so nothing signalled that someone else's tracks were gone. Now ownership is
  verified first, the pivot is deleted through a `JOIN`, and `affected_rows` is
  checked.
- **Read:** `getPlaylistQueue()` had no ownership gate, so `?playlist_id=N` on the
  public watch page leaked another user's playlist queue. The gate is now placed
  **in the data layer**, not the caller, so the next caller cannot forget it.

### 7. Webroot closed off

Before this batch, nine paths were downloadable without authentication, including a
42 MB SQLite dictionary (`/assets/dict/jmdict.sqlite3`) that could be re-downloaded
on every request.

- deny `.htaccess` for `vendor/`, `node_modules/`, `tools/`, `assets/dict/`, and
  `storage/` (the last was a 0-byte file)
- `vendor/` and `node_modules/` are gitignored, so their `.htaccess` cannot be
  committed. `install.sh` now writes them (step 6/9), and `tests/security_test.php`
  guards every sensitive directory so regressions are caught
- root `FilesMatch` extended for `phpunit.xml`, `composer.*`, `package*.json`,
  `install.sh`, `.vscode`, and backup suffixes (`~`, `.bak`, `.old`, `.orig`, `.swp`,
  `.dist`, `.sample`)
- deliberately **not** a blanket `*.json`/`*.xml`/`*.md`, since
  `assets/manifest.json` and `sitemap.xml` must stay public
- a `RewriteRule` blocks tooling directory segments — `FilesMatch` only catches a
  file literally named `.vscode`, not a path containing that segment

### 8. `.htaccess` using bare Apache 2.2 directives

`controllers/.htaccess` and `logs/tests/.htaccess` used a bare `Deny from all`. On
Apache 2.4 without `mod_access_compat` that is a config error, so the **entire**
`.htaccess` is discarded and `controllers/` loses all access control. They are now
wrapped in `IfModule` like the rest of the repo.

---

## P1 Hardening (October 2026)

Follow-up to the previous section, for the same reason: the explanation lives in
documentation, not in production files.

### 9. Download size and duration limits

The yt-dlp `base_cmd` had no ceiling at all. A single URL with a long 1080p stream
wrote into `/dev/shm/meel/temp` staging with no bound, alongside a 10 GB
`memory_limit`. Now:

- `--max-filesize` and `--match-filter "duration < N"` on the yt-dlp command
- `DownloadService::assertWithinDownloadLimits()` checks the metadata
  (`duration`, `filesize`/`filesize_approx`) before the download starts

Values are `MEEL_MAX_DOWNLOAD_BYTES` (2 GB) and `MEEL_MAX_DOWNLOAD_SECONDS`
(7200), defined alongside the other upload limits in
`modules/core/helpers/upload.php`.

### 10. Queue slot claiming is now atomic

`isServerBusy()` was a plain `SELECT COUNT(*) ... WHERE status = 'processing'`,
with the INSERT done separately by the caller. Twenty concurrent requests could
all observe a count below the threshold and all start ffmpeg (8 threads each).

`System::claimQueueSlot()` now wraps the check and the INSERT inside MySQL
`GET_LOCK()` — one Advisory Lock, so only one party gets to check then insert.
Verified with 8 parallel PHP processes: exactly 2 claims succeed, the rest are
refused.

The ceiling is `System::MAX_CONCURRENT_QUEUES` (default 2).

### 11. GC no longer deletes artifacts of running jobs

`GarbageCollector::STALE_SECONDS` was 300, below `TRANSCODE_AUDIO_TIMEOUT` 600
and `DOWNLOAD_TIMEOUT` 900. Any other request touching the transcoder could make
the GC delete the `.processing` marker of a running job — and that marker is the
only thing preventing two transcodes from colliding on the same output. Raised to
1200.

### 12. The installer no longer discards database credentials

The old patcher matched `$server = "localhost";`, but
`auth/settings.example.php` had already moved to
`if (!isset($server)) $server = (getenv(...) ?: "localhost")`. The error was also
swallowed by `2>/dev/null ||`, so the install still reported success while the
operator's answers were thrown away and the defaults were used.

The new patcher uses a durable regex for `define('MEEL_HDD_BASE', ...)` so it is
idempotent, rewrites the override block, verifies the result with `re.search`,
stops with `die` on failure, and finally checks
`php -r 'require auth/settings.php'`.

### 13. Open redirect: one helper instead of seven implementations

Seven separate copies of referer validation, nearly all comparing the referer host
against `$_SERVER['HTTP_HOST']` — the client-supplied header. With
`Host: evil.com` plus `Referer: https://evil.com/` the check passes as "same
origin". Some variants accepted `localhost` and `127.0.0.1` unconditionally.

`meel_same_origin_referer()` replaces them: the host must match the configured
`MEEL_CANONICAL_HOST`, only http/https schemes are allowed, and protocol-relative
URLs are rejected. When `MEEL_CANONICAL_HOST` is empty, every external referer is
rejected — it fails closed.

Used by `music/playlist_action.php`, `profile/notification.php`, and
`meel_back_url()`. The remaining `HTTP_REFERER` reads (such as the HLS gate) are
not security controls and never were.

### 14. Silent data corruption in an arcade bind_param

`difficulty_label` and `cover_file` are varchar columns, but they were bound as
`"i"`. mysqli emits no warning — it coerces the value to the requested type, so
both were stored as `'0'`. Difficulty names became `0` and cover art broke even
though the file was on disk.

`tests/unit/BindParamTypeTest.php` derives the expected types from
`arcade/schema.sql` rather than a hardcoded list, so the test stays correct when
the schema changes.

### 15. Drive rate limit that never fired

`System::checkRateLimit('drive_files')` counts rows in `drive_files`, but nothing
ever inserted into that table — `num_rows` stayed 0, so the 10 files/hour limit
never applied. `DriveStorage::recordUpload()` now records every upload.

### 16. Pending deletion queue limited to media roots

`cleanupPendingDeletions()` deleted whatever path appeared in
`temp/pending_delete.json`, with no containment check. That file lives in a
world-writable directory, so a local user could schedule deletion of any file or
directory as the web user. `realpath()` must now resolve inside one of the media
roots or the drive base.

### 17. Admin exports bounded

`fetchAll()` without a `LIMIT` was used to render a 15-row preview — the entire
filtered table was loaded into memory first. `fetchAll()` now takes a limit
(default 50,000) and the preview uses `countFiltered()` plus `fetchPage(15, 0)`.

---

## P2 Hardening (October 2026)

### 18. MFA and login limiters can no longer be reset by dropping the cookie

The MFA counter lived only in `$_SESSION['mfa_fail_count']`. Discarding the cookie
was enough to start from zero — there was no server-side counter per account.

`RateLimiter` now has two new buckets: `mfa` (10 requests / 300 seconds) and
`login` (10 / 300). `auth/mfa_verify.php` calls it with the key `mfa:<user_id>`
**before** verifying the code, so the ceiling applies across sessions.

### 18b. Login no longer reveals whether an account exists

Two oracles previously:

1. **Different message.** An existing but inactive account got "Akun Anda sedang
   menunggu verifikasi admin." or "Akses ditolak untuk akun ini." — different
   from "Username atau password salah!", directly confirming the account exists.
2. **Timing.** `password_verify` (bcrypt, 50-100 ms) only ran when the user was
   found. A median over a few dozen requests separates the two branches. There is
   now a decoy `password_verify` against a static hash when the user is absent.

Every failure now returns the same message.

### 19. Escaping happens at render, not at write

`UpdateManager::clean()` used to `htmlspecialchars()` **on write** while
`update.php` did `nl2br(htmlspecialchars(...))` **at render**. The result was
double-escaped content: an admin typing `<b>fast</b>` published
`&lt;b&gt;fast&lt;/b&gt;`, and the JS worked around it with a `decodeHtml` trick.

More seriously, `/update` is reachable by **anonymous visitors**, and both sidebar
panes were rendered with no escaping at all. An "escape on write" pattern means a
second writer — an install routine, an import, or a direct `mysql>` edit during an
incident — becomes stored XSS for every visitor. `clean()` is now just `trim()`,
and escaping happens at the render site.

`admin/index.php` is fixed too: the orphan file list (on-disk filenames derived
from user-supplied media titles) was echoed raw into `<li>`, and a bare
`json_encode()` sat inside a single-quoted attribute — `json_encode` does not
escape `'`, so a filename containing an apostrophe closed the attribute. Both are
escaped now, and `json_encode` uses
`JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP`.

### 20. A failing migration now stops instead of continuing

`database/migrate.php` recorded the version in `db_version` even when its steps
only printed `⚠ Warning`. The schema could therefore stay **permanently**
half-migrated — `SELECT MAX(version)` skips that migration forever on that host.

This was observed live while running migration v7: creating the unique index
failed because of the old duplicates, the warning was printed, but `db_version`
still advanced so the step was never retried.

Failures are now collected with their step index, written to STDERR, the version is
**not** recorded, and the process exits 1 — so the installer catches it and the
failed step is retried on the next run.

### 22. CSRF token no longer in URLs

`profile/manage.php` deleted media over GET with the CSRF token in the query
string. That token ends up in browser history, the Apache access log, and the
`Referer` header of the next page.

Deletion is now a POST with the token in the form body, the handler rejects other
methods, and `confirmHapus()` submits the form instead of navigating.

### 23. The stream authorization function no longer fails open

`meel_stream_path_allowed()` returned `true` for any path not starting with
`video/`. Since video and music are public under this project's RBAC model, the
gate adds nothing — it only pretends to be a security control while being a trap
for anyone who reuses it for a private area.

The polarity is inverted: there is now an explicit public prefix allowlist
(`thumbnail/`, `file/`, `lyrics/`, `upload/`, `manga/`, `books/`) and everything
else is refused. Public streaming behaviour is unchanged — what changed is that
the default is now deliberate rather than accidental.

---

## P4 Hardening — Comment Section (October 2026)

Two things could make one track's comment section look permanently empty.

### `parent_id` was never validated

`addComment()` took `parent_id` straight from POST and stored it. It never checked
that the parent comment exists, and never checked that the parent actually belongs
to the same media.

That cuts two ways:

- A reply could attach to a comment on **different media**, so the tree rendered on
  one page pulls in another track's data.
- The renderer only descends from `parent_id = 0`, so a reply whose parent falls
  outside the 200-row window is **never rendered at all** — it exists in the
  database but is unreachable from the root.

`resolveParentId()` now verifies the parent exists **and** carries the same media
column. If not, `parent_id` is set to `NULL` so the comment becomes a root comment —
deterministic and still visible.

Verified directly against the database: a comment on media A attempting to use a
`parent_id` belonging to media B is stored with `parent_id` NULL.

### Unbounded reply chains

Added `MediaViewer::MAX_COMMENT_DEPTH` = 5. Exceeding the depth promotes the comment
to root. Without this, one person could build an unbounded chain just by replying to
replies.

### 200-row window with no pagination

`getComments()` used `LIMIT 200` ordered by `created_at ASC` — oldest first. The
consequence: once those 200 rows are filled (say, 200 chained replies under one root
comment), every new comment is pushed outside the window and never appears again. The
section looks empty even though the rows exist.

Two changes:

- The order is now `created_at DESC, id DESC`. The newest comment is always first,
  so it can no longer be pushed out by older ones.
- An `$offset` parameter was added so older comments remain reachable through
  pagination.

Comments that already exist are **not** deleted. Only the effect changes: new comments
are no longer hidden by old ones.

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
