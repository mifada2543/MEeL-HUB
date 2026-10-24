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
- [Input Validation](#input-validation)
- [Security Checker](#security-checker)
- [MEeLCoin Accounting](#meelcoin-accounting)

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

### Baseline Rules That Apply

| Area | Rule |
|---|---|
| Environment detection | `MEEL_ENV` is read only from `REMOTE_ADDR` — never from a client-sent header. `install.sh` writes `UseCanonicalName On`; if `MEEL_ENV` must be forced, set it explicitly in `auth/settings.php`. |
| Path resolution | Every `require`/`include` and `filemtime()` call uses `__DIR__`, not a path relative to the working directory. `href`/`src` attributes are left alone — those are URLs the browser resolves. |
| Configuration loading | `auth/config.php` is loaded with `require_once`. The whole drive authorization gate shares `drive/bootstrap.php`. |
| Referer validation | `meel_same_origin_referer()` accepts a referer only when its host is in `meel_trusted_hosts()`: `MEEL_CANONICAL_HOST`, the loopback hosts (`localhost`, `127.0.0.1`, `::1` — always trusted because they only point at the machine itself), and `MEEL_TRUSTED_HOSTS`. Schemes other than http/https and protocol-relative URLs are rejected. `HTTP_HOST` is deliberately not used as an allowlist: it is a client-supplied header, so it can be forged (`Host: evil.com`) and would open an open redirect. Used by `introduction.php`, `err/index.php`, `music/playlist_action.php`, `profile/notification.php`, `profile/index.php`, `auth_back_url()`, `safe_comment_back_url()`, and `meel_back_url()`. |
| Stream check | `drive/stream.php` calls `authorize()`; the checker reads that file together with its whole `include` chain via `sourceWithIncludes()` in `tests/helpers.php`. The pattern is `->authorize()`. |

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

### Source of Truth for Roles

The database is the source of truth. `$_SESSION['role']` is a display cache only — badges and "edit" links may read it, but the gate always re-checks against the database. Every caller uses `is_admin($conn)` or `require_admin($conn)`; `update.php`, `profile/manage.php`, and `controllers/system/UpdateManager.php` no longer read `$_SESSION['role']` directly.

`UpdateManager` is the **write** path for `sidebar_settings` and `updates`, which are rendered to every anonymous visitor.

### Resource Authorization (IDOR)

Ownership is checked in the **data layer**, not in the caller, so the next caller cannot forget it.

| Operation | Rule |
|---|---|
| Delete playlist | Check ownership first, then delete the `playlist_tracks` rows through a `JOIN`, then check `affected_rows`. A `DELETE` scoped by `user_id` still succeeds on 0 rows without giving any sign. |
| Read playlist queue | `getPlaylistQueue()` has an ownership gate — without it, `?playlist_id=N` on the public watch page leaks another user's queue. |

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

### Login Does Not Leak Whether an Account Exists

Every login failure uses the **same** message — there is no longer a different message for "account exists but is inactive" versus "username or password wrong".

A dummy `password_verify` runs against a static hash when the user is not found, so response time cannot separate the two branches.

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

### The Token Is Never in the URL

The CSRF token never lives in the query string — there it would end up in the browser history, the Apache access log, and the `Referer` header of the next page. Media deletion uses POST with the token in the form body, the handler rejects other methods, and `confirmHapus()` submits a form instead of navigating.

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

### Buckets That Apply

| Bucket | Limit | Key |
|---|---|---|
| `mfa` | 10 / 300 seconds | `mfa:<user_id>` — holds across sessions |
| `login` | 10 / 300 seconds | per account |
| `drive_files` | 10 files / hour | `DriveStorage::recordUpload()` records every upload |

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

### Disabling MFA

Disabling MFA requires `password_verify` **and** a currently valid TOTP code — the same pattern as resetting the backup codes. The TOTP seed is never re-rendered for any logged-in session.

### Anti-Replay

`verify_totp()` uses a ±1 time-step window, so a single code is valid for roughly 90 seconds. The anti-replay mechanism:

- `verify_totp_step()` returns the matching time-step (not a `bool`)
- `auth/mfa_verify.php` stores it in `users.mfa_last_step`
- the update uses `UPDATE ... WHERE mfa_last_step IS NULL OR mfa_last_step < ?` — two concurrent requests with the same code cannot both pass

`verify_totp()` remains as a safe wrapper for ordinary callers. When a backup code is used, the step is reset so the next TOTP code is not rejected.

### Limiter

`RateLimiter` has two buckets: `mfa` (10 requests / 300 seconds) and `login` (10 / 300). `auth/mfa_verify.php` calls it with the key `mfa:<user_id>` **before** verifying the code, so the limit holds across sessions — dropping the cookie does not reset it.

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

### Download Limits

The yt-dlp command uses `--max-filesize` and `--match-filter "duration < N"`, and `DownloadService::assertWithinDownloadLimits()` checks the metadata (`duration`, `filesize`/`filesize_approx`) before the download starts. The values are `MEEL_MAX_DOWNLOAD_BYTES` (2 GB) and `MEEL_MAX_DOWNLOAD_SECONDS` (7200), defined together with the other upload limit constants in `modules/core/helpers/upload.php`.

### Atomic Queue Slot Claim

`System::claimQueueSlot()` wraps the check and the INSERT in a MySQL `GET_LOCK()` — one advisory lock, so only one party may check and then insert. The limit is `System::MAX_CONCURRENT_QUEUES` (default 2).

### Artifacts of Running Jobs

`GarbageCollector::STALE_SECONDS` = **1200**, larger than `TRANSCODE_AUDIO_TIMEOUT` (600) and `DOWNLOAD_TIMEOUT` (900). The `.processing` marker is the only guard against two transcodes colliding on the same output, so it must never be below those two timeout values.

### Pending Deletion Queue

`cleanupPendingDeletions()` may only delete paths whose `realpath()` lies inside one of the media or drive base roots. The queue file sits in a world-writable directory, so without a containment check a local user could schedule the deletion of any file or directory as the web user.

### Admin Export Limit

`fetchAll()` takes a limit (default 50,000) — the 15-row preview uses `countFiltered()` + `fetchPage(15, 0)` instead of loading the whole filtered table into memory.

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
> **`books/file.php` requires login** (`auth/auth.php`), consistent with the rest of the Books module — `index.php`, `read.php`, `read_pdf.php`, and `controllers/api/pdf.php` are all login-gated. Without this, manga images and PDFs could be downloaded directly without authentication (the raw paths were reachable with `HTTP 200` before the fix). Music audio stays behind `music/stream.php` (session marker + strict referer gate); `music/file.php` and `video/stream.php` intentionally have **no** login gate because the Music/Video index & watch pages are public by design (thumbnails must render for anonymous visitors). `video/stream.php` additionally passes `hls_gate => true` — `.m3u8`/`.ts` segment requests must carry a same-host referer from a video page (`/video`, `/video/watch`), otherwise the request is redirected to `err/?code=denied` (anti-hotlink). `video/stream.php` and `music/stream.php` also call `session_write_close()` before streaming so parallel segment requests don't block each other on the PHP session file lock. Token gate: the watch pages register a path → media-id mapping in the session (`meel_register_stream_path`, **500-entry** FIFO cap) and the stream endpoints verify it via `meel_stream_path_allowed` — verified access **without a DB query per segment**; tokens last 12 hours and refresh automatically during active playback; thumbnails (`thumbnail/`) and display files stay public for cards/OG-images. The mapping applies to the **entire video-folder subtree** (ancestor walk from the path up to a registered key): multibitrate variant playlists (`#EXT-X-STREAM-INF` → subfolders like `v1/`, `720p/`, …) and their segments are verified as long as that video folder is registered — other video folders remain denied, and `video/` can never become a subtree root.

### The Webroot Is Closed

`.htaccess` denies `vendor/`, `node_modules/`, `tools/`, `assets/dict/`, and `storage/`. The `vendor/` and `node_modules/` rules cannot be committed (both are git-ignored), so `install.sh` writes them and `tests/security_test.php` watches every sensitive directory so a regression is caught.

The root `FilesMatch` also covers `phpunit.xml`, `composer.*`, `package*.json`, `install.sh`, `.vscode`, and backup suffixes (`~`, `.bak`, `.old`, `.orig`, `.swp`, `.dist`, `.sample`). It deliberately does **not** blanket `*.json`/`*.xml`/`*.md`, because `assets/manifest.json` and `sitemap.xml` must stay public. Tooling directory segments are closed with a `RewriteRule`, because `FilesMatch` only catches a file named `.vscode`, not a path containing that segment.

### Apache 2.4 Compatibility

Every `Deny from all` is wrapped in `IfModule`. Without that wrapper, Apache 2.4 without `mod_access_compat` treats the directive as a configuration error — and the whole `.htaccess` is discarded, so `controllers/` loses all access control.

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

#### The protocol rules live outside the server script

`validating_proxy_server.php` runs the server at file scope, so the file could
not be included without starting a proxy. Every rule in it — how a request head
is parsed, which headers are stripped, how the forwarded request is assembled —
was therefore untestable.

Those rules now live in `modules/auth/ValidatingProxyProtocol.php`, which has no
side effects: it can be included, and the rules can be exercised without a
server and without a network. `ValidatingProxyProtocolTest` does exactly that,
including the socket-level tunnel and response relay through a pair of local
sockets. `ValidatingProxyTest` still drives the real server process end to end.

`security_test.php` looks for the proxy's guard patterns across both files
together, so moving a rule between them does not weaken the check.

### Defense in Depth

`Transcoder::fetchMetadata()` also calls `SsrfGuard::validate()` independently and forces the proxy flag, so even a future caller that skips `processDownload()` cannot pass an unvalidated URL — or an unprotected hop — to yt-dlp.

### Integration Points

| File | Role |
|---|---|
| `modules/auth/SsrfGuard.php` | Central validation: protocol allowlist, explicit IPv4/IPv6 range checks, DNS all-record validation, HTTP pinning |
| `modules/auth/ValidatingProxy.php` | Spawns/terminates the proxy process, exposes `--proxy` URL (loopback-only) |
| `modules/auth/validating_proxy_server.php` | CLI forward proxy: SsrfGuard on every hop (HTTP absolute-URI + CONNECT tunnel) |
| `modules/auth/ValidatingProxyProtocol.php` | The proxy's protocol rules, split out of the server script: request-head parsing and its limits, CONNECT target parsing, forwarded-request assembly, tunnel and response relay |
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

### Stream Authorization Fails Closed

`meel_stream_path_allowed()` uses an explicit allowlist of public prefixes — `thumbnail/`, `file/`, `lyrics/`, `upload/`, `manga/`, `books/` — and rejects everything else. Video and music are public under this project's RBAC model, so public streaming behaviour is unchanged; what changes is that the default is now deliberate rather than accidental, and a private area can no longer pass simply because its path does not start with `video/`.

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

### CSRF in All Forms

Every POST form carries a CSRF token, and the handler rejects a request without a valid one. The token is verified with `hash_equals()` so a comparison cannot be timed.

### Login Rate Limiting

```php
$max_login_attempts = 5;
$lockout_time = 300; // 5 minutes
```

### Register Rate Limiting

```php
$max_reg_attempts = 3;
$reg_time_window = 3600; // 1 hour
```

### Theme System Security

Theme preference is stored securely:

1. **Client-side:** `localStorage` — no sensitive data, only string 'light' or 'dark'
2. **Server-side:** `users.custom_theme` — varchar(50), only accepts 'light' or 'dark'
3. **API validation:** `in_array($theme, ['light', 'dark'], true)` — rejects invalid values
4. **CSRF protection:** POST requires valid CSRF token
5. **No XSS risk:** Theme value is not escaped to HTML, only used for `data-theme` attribute

### Escape at Render Time, Not at Write Time

`UpdateManager::clean()` only does `trim()`. Escaping happens at the render point, per context. The `/update` page is reachable by anonymous visitors, so "escape at write time" means a second author — an install, an import, or a direct `mysql>` edit during an incident — becomes stored XSS for every visitor.

`admin/index.php` also uses `json_encode` with the flags `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP`; plain `json_encode` does not escape `'`, so a filename with an apostrophe could close the single-quoted attribute.

### View Inflation

`UNIQUE (user_id, video_id, music_id)` **holds nothing back**: MySQL treats NULL as a distinct value in a unique index, so `(9, 299, NULL)` and `(9, 299, NULL)` stay two different rows. The correct pattern is two separate unique constraints per media kind, like the `interactions` table.

> ⚠️ **Trap:** an unused column **must stay `NULL`, not `0`**. Filling in `0` makes every music row share `video_id=0` and collide in `uq_view_video`. It is the fact that NULL *passes* that makes this two-unique pattern work.

### `bind_param` Types Follow the Schema

`bind_param` must use the column's real type. `difficulty_label` and `cover_file` are varchar and must not be bound as `"i"` — mysqli raises no warning, it converts the value to the requested type, so both end up stored as `'0'`. `tests/unit/BindParamTypeTest.php` derives the expected types from `arcade/schema.sql` instead of a hardcoded list.

---


## Security Checker

The checker in `tests/security_test.php` must genuinely scan what it claims to. Three rules apply:

### The Allowlist Map Is Not the List of Scanned Files

The checker **finds its own files** via `getPhpFiles()`, then recomputes `escapeshellarg`/int-cast for every hit. The `$expected`/`$allowed` map stays, but its role changes: it is no longer the list of files that get scanned, but the list that is *expected* to still run a shell. A file outside the map that runs a shell **fails**, with a message naming what to change if it is genuinely legitimate. A file that is listed but has lost its `shell_exec` is reported too, so a stale map becomes visible immediately.

Number of files scanned: **21**.

### Taint Tracking for Open Redirects

A variable initialized from `HTTP_REFERER` is marked tainted; an assignment that uses a tainted variable taints the next one (two levels — enough for the `$ref` → `$back_url` pattern); every `header('Location: ...')` whose expression uses a tainted variable is reported as an open redirect. Both layers matter, because a redirect through a variable slips past a checker that only looks for `HTTP_REFERER` inside `header()`.

What is **not** reported: files that read the referer and then redirect to a local path — the HLS gate in `music/stream.php` and `modules/core/helpers/storage_stream.php` redirecting to `../err/?code=denied`.

### A Branch-Aware Rule

`display_errors=1` is accepted **only** when the same file also contains `case 'development'` **and** `display_errors=0`. There is no list of exempted filenames, so a new exemption cannot hide a regression.

CI fails on any non-zero exit code — warnings are red too.

### A Guard Against Regressing

`StorageSplitTest::testSecurityAndFunctionalMapsStillCoverTheRealFiles` looks for the `$risky`, `$expected`, or `$allowed` map, and adds an assertion that `testCommandInjection()` really calls `getPhpFiles()`. If the check is ever reverted to a literal list of paths, that assertion fails first.

## MEeLCoin Accounting

The refund accounting rules for queue rows. All of them are pinned by `QueueRefundAccountingTest` and `QueueRefundRecoveryIntegrationTest`.

### The Recorded Cost Is the Amount Actually Charged

`upload_queue.coin_cost` records the MEeLCoin that was really deducted when the row was created — the caller that creates the row is the one that must record the amount, not some other flow. `coin_refunded` = 1 means the cost has been returned.

### One Implementation, Two Callers

Clearing stuck rows uses `GarbageCollector::releaseStaleQueueRows()` — the same code as the 6-hour sweeper. If one of them marks a row `failed` without refunding it, that row leaves the sweeper's reach and the MEeLCoin cost is lost for good. The order inside a row matters: **refund first, then mark `failed`**. Reversed, there is a window where the row is already `failed` but the coins have not come back, and `failed` rows are no longer picked by the sweeper.

### A Refund Happens Exactly Once

`releaseStaleQueueRows()` reads the cost from the row itself, not from the current price — the MEeLCoin price can change, and what must be returned is the amount that was really deducted. Rows are locked `FOR UPDATE` for the duration of the transaction, so two concurrent sweepers cannot refund the same row. `coin_refunded = 1` is written **only after** the coins have actually been returned, and every `execute()` result is checked.

`releaseQueue()` still touches only the status column. That invariant matters: if it also set `coin_refunded`, a row already refunded by the normal failure path would be refunded again by the sweeper.

### A Failed Notification Must Not Cancel the Upload

`Notification::create()` is wrapped in error handling — the notification is cosmetic, so its failure is logged and the upload continues. Otherwise the user has paid, nothing was uploaded, and the refund never ran.