# 👨‍💻 Development & Contribution Guide

Guide for developers who want to contribute or understand coding standards in MEeL-HUB.

---

## Table of Contents

- [Development Environment](#development-environment)
- [Coding Standards](#coding-standards)
- [Database Structure](#database-structure)
- [Coding Conventions](#coding-conventions)
- [`.htaccess` & Legacy Route Notes](#htaccess--legacy-route-notes)
- [Technical Decisions & Fix Confirmations](#technical-decisions--fix-confirmations-2026-10-03)
- [Testing](#testing)
- [Pull Request Guide](#pull-request-guide)
- [Troubleshooting Development](#troubleshooting-development)

---

## Development Environment

### Setup

1. **Install dependencies:**
```bash
git clone https://github.com/mifada2543/MEeL.git
cd MEeL
cp auth/settings.example.php auth/settings.php
cp auth/config.example.php auth/config.php
```

2. **Enable debug mode:**
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

3. **Disable HDD check for development:**
```php
// modules/core/helpers.php - comment out:
// if (!is_dir(MEEL_HDD_BASE)) { ... }
```

4. **Recommended tools:**
- Editor: VS Code with PHP Intelephense
- Database: MySQL Workbench / phpMyAdmin
- API Testing: Postman / Insomnia
- Browser: Chrome DevTools for HTMX debugging

---

## Coding Standards

### PHP

#### 1. PSR-12 Basic Coding Style

```php
<?php
declare(strict_types=1);

namespace MEeL\Modules;

class MediaLibrary
{
    private mysqli $conn;
    
    public function __construct(mysqli $connection)
    {
        $this->conn = $connection;
    }
}
```

#### 2. Prepared Statements REQUIRED

```php
// ✅ CORRECT - Prepared Statement
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();

// ❌ WRONG - Don't use query() with concatenation
// $result = $conn->query("SELECT * FROM users WHERE username = '$username'");
```

#### 3. Class Naming Convention

```php
// Class: PascalCase
class MediaLibrary {}
class BookRepository {}

// Methods: camelCase
public function getVideos();
public function toggleLike();
```

#### 4. Type Hints

Properties and constructor parameters **must** have type hints (PHP 8.0+):

```php
// ✅ CORRECT
private \mysqli $conn;
private int $user_id;
private string $username;

public function __construct(\mysqli $db, int $user_id, string $username) { }
```

### JavaScript

```javascript
// ✅ CORRECT - Named functions
function handleSearch(event) {
    const query = event.target.value;
}

// Event listeners preferred over inline HTML
document.getElementById('search-input').addEventListener('input', handleSearch);

// HTMX event monitoring
document.body.addEventListener('htmx:afterOnLoad', function(evt) {
    lucide.createIcons();
});
```

### CSS

Project uses **TailwindCSS (self-hosted, purged)** for main styling with minimal custom CSS for special effects.

---

## Database Structure

### Entity Relationship Diagram

```
users ──1:N── video
users ──1:N── music
users ──1:N── books
users ──1:N── comments
users ──1:N── playlists
users ──1:N── interactions
users ──1:N── upload_queue
users ──1:N── drive_files

comments ──1:N── comments (parent_id, nested)
playlists ──1:N── playlist_tracks
music ──1:N── playlist_tracks
```

### Key Relationships

| Table | Foreign Key | References | Type |
|---|---|---|---|
| `video` | `user_id` | `users.id` | CASCADE |
| `music` | `user_id` | `users.id` | CASCADE |
| `books` | `user_id` | `users.id` | SET NULL |
| `comments` | `user_id` | `users.id` | CASCADE |
| `comments` | `parent_id` | `comments.id` | CASCADE |
| `interactions` | `user_id` | `users.id` | NO ACTION |
| `playlists` | `user_id` | `users.id` | CASCADE |
| `playlist_tracks` | `playlist_id` | `playlists.id` | CASCADE |
| `playlist_tracks` | `music_id` | `music.id` | CASCADE |

---

## Coding Conventions

### Comment Style

- **Free for *why*** — design rationale, trade-offs, past pitfalls (e.g. "without
  this reset the render loop dies silently"), and contracts that are not visible
  in the code.
- **Never restate the code** — a comment that only narrates what the next line
  does adds nothing and goes stale quickly.
- **Long technical detail → documentation** — API contracts, pipeline flows, and
  behaviour notes that need context live in the MD files; the code file only
  points there.
- **No section banners** — do not use `/* ==== 1. Name ==== */` separators in JS;
  a blank line is enough.

#### When a comment gets deleted

Applies to first-party code. **Excluded:** third-party/vendor files
(`assets/js/compatibilitas/`, `*.min.js`, `plyr.css`, `tailwind.min.css`,
`font.css`, …), the `reference build:` markers produced by `marks.php`, and
configuration files (`.htaccess`, `install.sh`, `.github/workflows`).

**Deleted:**

1. Banners & separators — `/* ==== */`, `/* ---- */`, `────`, `════`,
   `// 1. Name`; a blank line separates blocks instead.
2. Comments that restate the code (what the line below does).
3. Empty and consecutive duplicate comments (`//`, `/** */`).
4. Commented-out dead code — delete the code, do not keep it as a comment.
5. Docblocks that only repeat the function/constant name
   (`/** Reject (inactive)? */` above a `return ...`).
6. `@param`/`@return` that only duplicate the signature's types; **kept** when
   they add contract (special return value, array shape, status enum).
7. Step-by-step comments in tests (`// create data`) — keep the ones explaining
   *why* the scenario exists.
8. Long narrative headers → move to `docs/en/development.md`, the file keeps a
   1–2 line pointer.

**Kept:**

- *Why* comments: trade-offs, security/race invariants, browser/GPU/DB
  behaviour that is not readable from the code.
- PHPDoc that documents a real contract instead of restating types.
- `reference build:` markers, licences, and vendor attribution.

### Security

1. **Always Prepared Statement** — No SQL concat
2. **Always htmlspecialchars()** — For output
3. **CSRF Token** — Every POST form required
4. **Role Check** — Before sensitive actions
5. **Input Validation** — Type, size, file extension
6. **No `@` suppression on filesystem ops** — use proactive `is_file()`/`is_dir()`/
   `is_readable()`/`is_writable()` guards, check return values, and reuse the shared
   helpers (`FfmpegUtils` trait, `GarbageCollector::removeFile()`/`removeDirectory()`,
   `meel_write_cache_file()`). See the [Filesystem Safety Convention](modules.md#filesystem-safety-convention-no--suppression).
7. **Centralized Session Boot** — Every entry point must call `meel_boot_session()`
   (from `modules/auth/helpers/session.php`) — never raw `session_name()` + `session_start()`.
   This function guarantees the session cookie uses `HttpOnly`/`SameSite=Lax`/`Secure`
   (auto-detect HTTPS) flags and the 12-hour timeout consistently.

### File Structure per Module

Each module (video, music, books, drive) follows this pattern. Pages are reached
via **clean URLs** (front controller `router.php` → `modules/core/Router.php`),
e.g. `video/beranda` → `video/index.php`, `music/watch?v=X` → `music/watch.php`:

```
[module]/
├── index.php          # Catalog / listing (URL: [module]/beranda)
├── watch.php          # Player / detail (URL: [module]/watch?v=X)
├── upload.php         # Upload form (URL: [module]/upload)
├── search_[module].php  # Search (HTMX) (URL: [module]/search)
├── load_more.php      # Infinite scroll — next batch + sentinel chain (hx-trigger=revealed) (URL: [module]/load-more)
└── [module]_item.php  # Card component
```

### HTMX Pattern

```php
<!-- Trigger -->
<input type="text" name="search"
    hx-get="video/search"
    hx-trigger="keyup[key=='Enter']"
    hx-target="#video-container"
    hx-indicator="#search-indicator">

<!-- Target -->
<div id="video-container">
    <!-- Results loaded here -->
</div>

<!-- Indicator -->
<div id="search-indicator" class="htmx-indicator">
    <div class="animate-spin">⏳</div>
</div>
```

### Infinite Scroll (Sentinel) Pattern

Used by the video & music libraries, search results, watch recommendation sidebars, and the profile channel grid — replaces the old "Load More" button:

```html
<!-- Sentinel: requester and target are the same element -->
<div id="load-more-area" role="status"
    hx-get="load-more?offset=15&page=1"
    hx-target="#load-more-area"
    hx-swap="outerHTML"
    hx-trigger="revealed">          <!-- htmx fires when it enters the viewport -->
    <div class="animate-spin …"></div>
    <span>Memuat...</span>
</div>
```

**Rules:**
- **Chain semantics:** each response returns the next cards **plus a replacement
  sentinel**. Freshly swapped elements start without `data-hx-revealed`, so the
  chain keeps firing while the sentinel stays visible.
- **Termination:** when `offset + limit ≥ total` (or `hasMore` is false) the
  server returns the end box **"Out Of Content · Konten sudah tidak ada lagi"** —
  an empty response renders the same end box.
- **Error retry:** `assets/js/shared/sentinel-retry.js` removes `data-hx-revealed`
  from a sentinel whose request failed, so the *next* scroll re-fires it
  (retries on user scroll — no automatic loop).
- **IDs matter:** keep the sentinel ids (`#load-more-area`, `#load-more-music`,
  `#load-more-music-search`, `#channel-more-area`) — CSS `overflow-anchor: none`
  and the `isFromLoadMore` guard in `assets/js/music/index/index.js` depend on them.
  Search results use their own sentinel id so post-search cleanup can't disable them.
- **Gotcha:** for an `outerHTML` swap, `htmx:afterSwap` fires **once per new
  element** — cards have no `id`, so `targetId === ""`. Treat fragment swaps as
  content updates (see `isFragmentSwap` in `assets/js/music/index/index.js`),
  otherwise view-boot logic (`bootPlayerIndex()` incl. scroll-to-active) runs per
  card and yanks the page during scroll.
- **Don't** pair `revealed` with programmatic smooth-scroll — a `scrollTo` loop
  would keep pulling pages endlessly (this is why the old `load-more.js` was removed).

### Theme System (Light/Dark Mode)

MEeL supports light and dark mode with CSS variables architecture:

```
assets/css/shared/
├── theme-tokens.css    # CSS variables (meel-bg, meel-surface, meel-text, etc.)
├── light-theme.css     # Light mode overrides for Tailwind utilities
└── design-tokens.php   # Shared tokens for upload forms
```

**How it works:**
1. `theme-tokens.css` defines CSS variables for dark mode (default)
2. `light-theme.css` overrides variables when `html[data-theme="light"]`
3. `theme.js` manages toggle, localStorage, and DB sync
4. Toggle is only available on the Profile page

**Adding Light Mode Support:**
- Use CSS variables (`var(--meel-bg)`, `var(--meel-surface)`, etc.) instead of hardcoded colors
- If you must use Tailwind hardcoded (`bg-[#0d1017]`), add override in `light-theme.css`
- Logo/icons must be excluded from color overrides (use `:not(.nav-logo-text)`)

---

## `.htaccess` & Legacy Route Notes

### Arcade module `auto_prepend_file` (portable & fail-closed)

- `arcade/.htaccess` uses `php_value auto_prepend_file _gate.php` with a **bare filename, no path**: PHP resolves it relative to the cwd of the executed main script, making it portable on any machine (no hardcoded absolute path).
- Fail-closed consequence: scripts executed directly in a subdirectory must ship their own `_gate.php` — a single `require __DIR__ . '/../../_gate.php';` line (see `arcade/chess/controller/_gate.php`).
- Clean-URL requests (root router) are **not** subject to this `php_value` — they are gated by `modules/core/Modules.php` in the Router.

### `controllers/.htaccess` shadows the legacy 301 rules

`Deny from all` in `controllers/.htaccess` (include-only files; direct access is 403 by design, tested at `security_test.php:419`) shadows every `controllers/*` 301 rule in the root `.htaccess` — the rules look dead while being live. The relationship is **latent**: if the deny is lifted, the rules still 301 to the correct parent routes.

### Removed routes (Phase 0/T7) & clean routes

- `profile/manage-action`, `admin/actions`, `admin/data` were removed from `Router.php` — they were fragment includes guarded by `MEEL_MANAGE_ACCESS`/`MEEL_ADMIN_CONTEXT`, so standalone routes could never work. Old URLs 301 to their parents (`/profile/manage`, `/admin`) in the root `.htaccess`.
- `assets/js/drive/upload.js` uses the clean route `../api/ajax-refresh`; the old URL `controllers/api/ajax_refresh.php` always returned 403 due to the deny above.
- Empirical note: the generic root 301 does **not** apply to the `arcade/` subtree (every arcade `*.php` is executed directly).

---

## Testing

### Manual Testing Checklist

**Frontend:**
- [ ] No errors in browser console
- [ ] HTMX request/response working
- [ ] Mobile responsive (min width 320px)
- [ ] Dark mode consistent
- [ ] All buttons and links functional

**Backend:**
- [ ] Prepared statements not erroring
- [ ] CSRF validation working
- [ ] Role-based access working
- [ ] File upload validation working
- [ ] Error handling showing appropriate messages

---

## MFA / TOTP Development

### TOTP Implementation (Time-based One-Time Password)

MEeL implements TOTP per [RFC 6238](https://datatracker.ietf.org/doc/html/rfc6238):

| Parameter | Value |
|---|---|
| Algorithm | HMAC-SHA1 |
| Digits | 6 digits |
| Time Step | 30 seconds |
| Window | ±1 (90 seconds tolerance) |
| Encoding | Base32 |

### Helper Functions (in `modules/auth/helpers/mfa.php`)

```php
// ─── GENERATE SECRET ───────────────────────────────────────
function generate_mfa_secret(): string {
    $random = random_bytes(20);  // 160-bit
    // Base32 encode (A-Z, 2-7)
    $base32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    $bits = 0; $buffer = 0;
    foreach (str_split($random) as $byte) {
        $buffer = ($buffer << 8) | ord($byte);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $secret .= $base32[($buffer >> $bits) & 31];
        }
    }
    return $secret;
}

// ─── GENERATE TOTP ─────────────────────────────────────────
function generate_totp(string $secret): string {
    $decoded = base32_decode($secret);  // Base32 → raw bytes
    $counter = pack('N*', 0) . pack('N*', intdiv(time(), 30));
    $hash = hash_hmac('sha1', $counter, $decoded, true);
    $offset = ord($hash[19]) & 0xf;
    $code = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset+1]) & 0xff) << 16) |
        ((ord($hash[$offset+2]) & 0xff) << 8) |
        (ord($hash[$offset+3]) & 0xff)
    ) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

// ─── VERIFY TOTP (with window ±1) ────────────────────────
function verify_totp(string $secret, string $code): bool {
    for ($i = -1; $i <= 1; $i++) {
        // Generate TOTP with time offset $i step
        $expected = generate_totp_at($secret, time() + ($i * 30));
        if (hash_equals($expected, $code)) return true;
    }
    return false;
}
```

### Backup Codes System

```php
// ─── GENERATE 8 BACKUP CODES ───────────────────────────────
function generate_backup_codes(): array {
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < 8; $i++) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);  // 6 digits
        $plain[] = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT);  // bcrypt
    }
    return ['plain' => $plain, 'hashed' => $hashed];
}

// ─── VERIFY BACKUP CODE (single-use) ───────────────────────
function verify_backup_code(string $hashedJson, string $code): array {
    $codes = json_decode($hashedJson, true) ?? [];
    foreach ($codes as $i => $hash) {
        if (password_verify($code, $hash)) {
            array_splice($codes, $i, 1);  // Remove used code
            return ['valid' => true, 'remaining' => $codes];
        }
    }
    return ['valid' => false, 'remaining' => $codes];
}
```

### Database Schema

3 new columns in `users` table (Migration v9):

```sql
ALTER TABLE users
    ADD COLUMN mfa_secret      VARCHAR(64)  DEFAULT NULL AFTER last_session_id,
    ADD COLUMN mfa_backup_codes TEXT        DEFAULT NULL AFTER mfa_secret,
    ADD COLUMN mfa_enabled     TINYINT(1)   DEFAULT 0     AFTER mfa_backup_codes;
```

### MFA Session Flow

```
1. Login password correct → Check mfa_enabled == 1
2. Yes → Save $_SESSION['mfa_temp_uid'] = user_id
          Save $_SESSION['mfa_temp_username']
          Save $_SESSION['mfa_temp_role']
3. Redirect to auth/mfa-verify
4. User inputs 6-digit code
5. Valid → Set $_SESSION['user_id', 'username', 'role']
          Set $_SESSION['mfa_verified'] = true
          Remove mfa_temp_* from session
6. Invalid → Increment $_SESSION['mfa_fail_count']
             If >= 10 → $_SESSION['mfa_locked_until'] = time() + 300
```

### Rate Limiting

| Endpoint | Limit | Mechanism |
|---|:---:|---|
| MFA Verify | 10 failures → lock 5 minutes | Session-based `mfa_fail_count` + `mfa_locked_until` |
| Backup Password | 5 failures → lock 5 minutes | Session-based `backup_pwd_attempts` + `backup_pwd_lock_until` |

### Security Considerations

1. **TOTP Secret** — Stored plaintext in DB (TOTP secrets must be readable)
2. **Backup Codes** — Stored as password_hash()/bcrypt hashes (one-way, cannot be reversed)
3. **Session Temp** — `mfa_temp_uid` only exists in session, not in cookies
4. **Brute Force** — 10 failed MFA attempts → lock 5 minutes
5. **QR Code** — 100% offline (local qrcode.min.js library, no data sent to external server)
6. **Admin Reset** — Admin cannot reset another admin's MFA
7. **Activity Log** — All MFA events (setup, verify, fail, reset) logged in `activity_log`

### Testing MFA Locally

1. **Enable MFA:** Go to `profile/index.php` → click MFA toggle → follow setup
2. **Get TOTP:** Open `auth/mfa_setup.php`, scan QR with Google Authenticator
3. **Simulate TOTP:** Use `generate_totp($secret)` via test script for verification
4. **Test rate limit:** Enter wrong code 10× → check lockout
5. **Test backup code:** Try one of the backup codes to login
6. **Test admin reset:** Login as admin → `admin/mfa_reset.php` → reset user

---

## Pull Request Guide

### 📜 License & Contribution

This project is licensed under **GNU General Public License v3.0 (GPLv3)**. See the [`LICENSE`](../../LICENSE) file for full text.

> **By submitting a Pull Request, you agree that your contributions will be licensed under GPL v3.**

#### Copyright Header on New Files

```php
/**
 * MEeL - Media Hub Platform
 *
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */
```

### Contribution Checklist

- [ ] Use **Prepared Statements** for all database queries
- [ ] Sanitize POST/GET input
- [ ] CSRF token on every new POST form
- [ ] Role check before sensitive operations
- [ ] Update `update.php` with changelog
- [ ] Every new file has **GPL v3 copyright header**
- [ ] Changes clearly marked with **modification notice**

### Git Commit Convention

```
[type]: Short description (max 50 chars)

- Detailed changes if needed
- Multi-line allowed
```

**Types:**
| Type | Usage |
|---|---|
| `feat` | New feature |
| `fix` | Bug fix |
| `security` | Security fix |
| `perf` | Performance optimization |
| `refactor` | Code refactoring |
| `docs` | Documentation |
| `style` | CSS/UI fix |

### Branch Strategy

```
main (stable)
  └── Experiment (development branch)
       ├── feature/[feature-name]
       └── fix/[fix-name]
```

---

## Troubleshooting Development

### ❌ HTMX not working

**Check:**
1. File `assets/js/compatibilitas/htmx.min.js` is loaded (check Network tab)
2. Target element (`hx-target`) exists in DOM
3. No JavaScript errors in console
4. Server response is valid HTML

### ❌ "Headers already sent" error

**Cause:** Output before `header()` or `session_start()`.

**Solution:**
```php
// Output buffering at the top
ob_start();

// Or move session_start() to the very top
session_name('meel');
session_start();

// Redirect with JavaScript fallback
if (!headers_sent()) {
    header("Location: index.php");
} else {
    echo "<script>window.location.href='index.php';</script>";
}
```

### ❌ Session not saving

**Check:**
1. `session_name('meel')` is called BEFORE `session_start()`
2. `auth/config.php` is included on every page
3. No output before `session_start()`
4. Session folder is writable

### ❌ SweetAlert2 not showing

**Check:**
1. File `assets/js/compatibilitas/sweetalert2.all.min.js` is loaded
2. Function `meelAlertRedirect()` is defined in `assets/js/compatibilitas/script.min.js`
3. No CSS conflicts

---

## Technical Decisions & Fix Confirmations (2026-10-03)

> This file captures the decisions made during the performance work and bug
> fixes. Every note here was **moved out of in-code comments** per
> [Comment Style](#comment-style) rule 8 — the code is now much leaner while all
> the long-form context lives here.
>
> Scope: Phase 1 (T11–T16) completed 6/6, T20 (`release.yml`), plus T26
> (the timezone bug). Per-task status lives in `.agents/Todo.md`.

### The Clock Contract: `DATETIME` is a *wall clock* with no timezone

This rule underpins T26 and is a hard requirement across the whole repo.

A MySQL `DATETIME` column **stores no timezone**. The value written can therefore
come from two different clocks:

| Source | Used by |
| --- | --- |
| `NOW()` / `CURRENT_TIMESTAMP` | the **MySQL server clock** (`@@time_zone`) |
| `date()` / `time()` / `strtotime()` | the **PHP clock** (`date_default_timezone_get()`) |

Nearly all repo code **reads** datetime columns through `strtotime()` — always the
PHP clock. As soon as PHP's `date_default_timezone` differs from MySQL's
`time_zone`, a column written with `NOW()` appears to be **in the future**, and two
things break:

- `$elapsed = max(0, time() - $ts)` goes negative, gets clamped to `0` →
  **countdowns/ages are permanently full** (the UI shows a number that can never
  occur);
- the `col <= $threshold` comparison is never true → **refill/lockout logic dies
  outright** (users stuck with a drained balance).

**Repo rule:** if a `DATETIME` column is read via `strtotime()`/`time()` from PHP,
it **must be written from the PHP clock**. Use `meel_now()` / `meel_now_ago()` from
`modules/core/helpers/datetime.php`. `NOW()` may stay only when the column is read
purely as a SQL DATETIME (e.g. `ORDER BY created_at DESC`) and never compared
against the PHP clock.

#### Schemas already fixed

| Column | Written by | Read by | Symptom when timezones skew |
| --- | --- | --- | --- |
| `users.meelcoin_last_refill` | `MeelCoin.php` (3 sites) | `MeelCoin.php` `$threshold` + `$elapsed` | refill never fires, countdown always full |
| `users.last_activity` | 5 production sites (below) | 6+ sites | a **"kicked" user still shows as ONLINE**, and `live-monitor` displays times in the future |

`last_activity` write sites converted: `activity_logger.php` (authenticated user
UPDATE + guest INSERT `ON DUPLICATE KEY UPDATE`), `auth/login.php` (×2),
`auth/mfa_verify.php`, `auth/auth.php`, and `admin_actions.php` (`kick_user`, which
previously used `DATE_SUB(NOW(), INTERVAL 10 MINUTE)`).

#### ⚠️ Audit not yet done

The `created_at`, `login_attempts` and `rate_limit` columns **still use `NOW()`**.
For `created_at` the impact is **display-only** (clock drift in
`admin/activity_log.php`) and has not been audited per column. **Do this audit
before building the worker (T17)** — time-based scheduling gets increasingly
sensitive to skew.

#### Reproducing on a dev machine

The PHP CLI uses `date.timezone = UTC` (`/etc/php/8.3/cli/php.ini`) while XAMPP's
MariaDB has `@@time_zone = SYSTEM` = **WIB (UTC+7)** → a skew of **25,195 seconds
≈ 7 hours**. The integration test
`MeelCoinIntegrationTest::testRefillResetsTimerWhenBalanceIsAtMax` captured exactly
that 7-hour gap before the fix.

---

### Per-request housekeeping: throttling vs. the security path

#### `ActivityLogger` (T11)

The procedural block in `modules/core/activity_logger.php` was reorganised into an
`ActivityLogger::onRequest($conn)` class called from `auth/config.php`. What changed
is **cost**, not behaviour:

- **60-second throttle.** The signature `last_page|device|access_via|ip_address` is
  stored in `$_SESSION['_meel_touch']`. An identical payload inside that window is
  not rewritten. The signature deliberately includes page + device + network so
  that navigating between pages is still recorded immediately instead of waiting
  for the window to expire.
- **Skip non-HTML responses.** `shouldSkipTelemetry()` skips htmx partials
  (`HTTP_HX_REQUEST`), an `Accept` header without `text/html`/`*/*` (JSON & HLS
  segments), and the `stream.php` / `file.php` endpoints (binary).
- 🔒 **The security path is NOT throttled.** `enforceIpBan()` (IP-ban redirect) and
  `enforceSingleSession()` (session revocation) always run. Throttling is limited to
  telemetry. This is not negotiable — throttling here would open a revocation gap.
- ⚠️ **The `$hasRun` static is useless under PHP-FPM.** Statics are not shared
  across requests, so the guard only helps within a single process. **The real
  reason for the throttle is the file**, not the static. Tests cover both (a mocked
  `mysqli` proves idempotency within one process; a separate test proves the
  throttle survives a static reset).

#### `GarbageCollector` (T11 + T16)

`run()` performed two expensive things on **every request**: scanning storage
directories and calling `RateLimiter::cleanup()`. Both now sit behind a single file
throttle (`temp/gc_run_last_run.txt`, `RUN_INTERVAL_SECONDS = 60`). The scan still
stops after its 3-second budget as before.

**CLI/web split (T16):** `ALTER TABLE ... AUTO_INCREMENT` blocks MySQL metadata and
`syncViewsFromLogs()` rewrites aggregate columns — both too heavy for a request.
They moved to `GarbageCollector::runCliMaintenance()`, which carries a
**`PHP_SAPI !== 'cli'` seatbelt**: called from a web request it becomes a no-op
rather than an error. Entry point: `scripts/gc.php` (with `--run-only` for light
housekeeping). The seatbelt was verified against a real `cgi-fcgi` SAPI rather than
merely assumed.

**Known gap that remains:** there is no sweeper for `upload_queue` /
`transcode_queue` rows stuck in `processing` after a crash — they hang forever and
the user never gets their refund.

---

### Queries: what was actually expensive

#### `System::countActiveQueues()` (T12)

`isServerBusy()` only needs a **number**, yet it called `getActiveQueues()`, which
pulls every queue row (URL, username, `created_at`) with a JOIN and a filesort. It
now issues `COUNT(*)` per table. `getActiveQueues()` is **kept**, because
`server_stats.php`, `server_stats_sse.php` and `admin_actions.php` genuinely need the
row detail.

#### Measured numbers — read these honestly

⚠️ **The query count did not go down.** `Com_select` stays at **2** on both paths
(both touch 2 tables). This is `COUNT(*)`-only, not a reduction in query count. The
benefit is in **payload and parsing**:

| Scenario | `isServerBusy()` (new) | `getActiveQueues()` (old) |
| --- | --- | --- |
| 2 `processing` rows, 300× | 57 ms · 0 B | 78 ms · 2,216 B |
| 2,001 `processing` rows, 200× | **109 ms · 0 B** | **7,088 ms · 1,366,920 B** |

On the realistic load that matters (queues backing up) the count path is **~65×
faster**. `EXPLAIN`: the `COUNT(*)` path is `type: ref`, `key: status`,
`Using index` (covering, no row touches); the full path adds a `users` JOIN plus
`Using temporary; Using filesort`.

#### `MediaViewer::__construct()` (T12)

It used to query `users` directly. It now uses `get_user_role()` (already present,
cached and session-aware) plus a new **`get_user_active()`** helper
(`modules/auth/helpers/user.php`, cached, `LIMIT 1`, `close()` — mirroring
`get_user_role()`). It almost always hits the cache because `MediaViewer` is used on
pages that already have a session.

#### `MediaViewer::getMediaData()`

`($result && $result->num_rows > 0) ? $result->fetch_assoc() : null` became
`return $result ? $result->fetch_assoc() : null;`. `num_rows` costs an extra handler
call and changes nothing, and it **cannot be faked in a test double** (because
`num_rows` is a *read-only virtual property* — see
[Test Infrastructure](#test-infrastructure-important-notes)).

---

### Random id sampling without `ORDER BY RAND()` (T13)

Shared helpers in `modules/core/helpers/media.php`:

| Function | Role |
| --- | --- |
| `meel_media_stats_all()` | **One** `UNION ALL` query for `video`/`music`/`books` (COUNT + MIN(id) + MAX(id)), 30-second per-table file cache |
| `meel_media_stats()` | One table; a cache miss warms **all three** at once |
| `meel_pick_random_ids()` | **Random-range sampling** for recommendations |
| `meel_invalidate_media_stats_cache()` | Drop the cache (called from 4 mutation sites) |
| `meel_media_table_whitelist()` | Table-name validation (guards against injection via table name) |

**Sampling strategy:** several `id >= ? ORDER BY id LIMIT ?` windows at random
start offsets, merged into a unique set. Each window uses the id index (a range
scan), so the cost is `O(log n + limit)` with no filesort, and it stays unbiased
across the whole id range.

**`ORDER BY RAND()` is now gone from every query in the repo.**

#### Decision: why we do **not** use `LIMIT 500`

The original suggestion was to cap the id fetch at `LIMIT 500`. **Rejected** —
fetching the first 500 ids would bias recommendations towards the lowest ids. Random
-range sampling is used instead, which is unbiased and **always** carries a `LIMIT`,
satisfying the intent ("don't pull the whole table") without sacrificing spread.

#### Single source of truth for media counts

There used to be **two** caches: `media_counts.json` (in `MediaLibrary`) and counts
computed on the fly (in `MediaViewer`). Both now go through
`meel_media_stats_all()`. `MediaLibrary::clearCountsCache()` just calls
`meel_invalidate_media_stats_cache()`.

Invalidation points: video upload (`Uploader.php`, `DownloadService.php`), music
upload (`helpers/upload.php`), video & music deletion (`fun-manage.php`).

The cache can be redirected via the **`MEEL_MEDIA_CACHE_DIR`** constant (following
the `MEEL_SERVER_STATS_CACHE` pattern) so tests don't depend on write access to
Apache's `temp/cache/`.

#### Two real bugs the tests caught

1. Applying `array_keys()` to an id list turned the pool into `[0, 1, …]`, so the
   "every candidate already seen" condition was never detected.
2. The cache used a path not writable by the CLI process — which surfaced the need
   for the `MEEL_MEDIA_CACHE_DIR` constant.

---

### Conditional requests for media streaming (T14)

Three pure functions (rather than inline inside a function that `exit()`s), so they
can be tested:

| Function | Role |
| --- | --- |
| `meel_cache_is_fresh()` | The 304 decision from `If-None-Match` / `If-Modified-Since` |
| `meel_media_etag()` | Strong ETag from path+size+mtime |
| `meel_media_cache_control()` | `m3u8` → `private, no-cache`; everything else → `private, max-age=31536000, immutable` |

Rules that must hold:

- 🔒 **Never return 304 for a Range request.** 304 alongside 206/`Content-Range` is
  an invalid response. The condition is `if (!$isPartial && …)`.
- 🔒 **Stay `private`, never `public`.** Content is gated per session/user;
  `public` risks leaking through shared caches/CDNs to other users.
- HLS playlists keep changing (segments get appended) so they must be revalidated.
  Media segments/files are immutable once written and may be cached long-term.
- `ETag`, `Last-Modified` and `Cache-Control` are sent on **both** paths (X-Sendfile
  **and** streaming), not just one.

**Bug caught by the tests:** `If-None-Match: *` was initially not treated as fresh,
violating RFC 7232 §3.2 ("*" means "if any representation exists").

---

### Asset versioning: why `meel_asset_dir_version()` is intentionally directory-mtime

T11 (T15) added `?v=` to 48 bare asset URLs across 25 files. But the original
suggestion to "switch from directory max-mtime to per-file versioning" was
**rejected**, because it would be a **regression**:

`assets/js/{video/watch,music/index,music/watch}/main.js` reads `?v=` from
`document.currentScript.src` and then **propagates it to every child module** it
loads dynamically. With per-file versioning, a change in a child module would
**not invalidate the browser cache at all** — only the entry file would change.

Full closure still belongs to **T22** (content-hash at build time), not
`?v=filemtime`. The list of `meel_asset_dir_version()` callers is locked down in
`AssetVersioningTest`.

---

### `@var` annotations for analyzers (T20)

Admin pages inherit variables from files they `include` (`auth/config.php`,
`controllers/admin/admin_data.php`) — valid at runtime, but invisible to a static
analyzer because it does not trace variables across `include`. That is why 12 admin
pages carry an `@var` block at the top of the file.

#### The root cause was a single pattern: comma-separated `@var`

```php
/** @var \mysqli_result $a, $b, $c; @var array $d */   // ❌ only the first line is read
```

The comma-list form is **understood by no analyzer** — only the first `@var` tag is
read and the rest are silently ignored. Intelephense reports `P1008 Undefined
variable` when this form is used. The fix:

- one variable per `@var` line;
- the docblock **must sit before the first use** — `@var` only applies forward.
  That is why `$conn` on line 8 was still reported while the block sat on line 15.

`$conn` comes from `auth/config.php` and is used by 10 admin pages, so an
`@var \mysqli $conn` block was added to each. These `@var` lines are **functional**
(not decorative comments) — deleting them brings hundreds of `P1008` back in the
editor.

> **Note:** PHPStan was tried here (level 2, scope `admin/`) and did drive 165
> errors down to 0 — but it was **removed again** (2026-10-03). Reasons: its
> `scanFiles` config is brittle (34 explicit paths; if any one is renamed PHPStan
> merely prints "Scanned file … does not exist", bails, and produces misleadingly
> clean results), no CI job ran it, and `require-dev` is not installed in
> production builds. The corrected `@var` blocks remain valuable for IDEs.

#### ⚠️ Intelephense P1038 on `FakeMysqliStmt::bind_param()`

`P1038` means "method signature incompatible with parent". The only trigger in this
repo is the mysqli test double, and it is a **false positive from Intelephense's
own stub**:

| Form | Accepted by PHP 8.3? |
| --- | --- |
| Intelephense 1.18.5 stub: `bind_param($types, &$var1, &...$_)` | ❌ **REJECTED by the engine** |
| Actual: `bind_param(string $types, mixed &...$vars): bool` | ✅ ACCEPTED |

Intelephense's stub has not caught up with the `mysqli_stmt::bind_param()`
signature change in PHP 8.0, so the analyzer and the engine **cannot both be
satisfied**. It is closed via `.vscode/settings.json` →
`intelephense.diagnostics.exclude`, locked to that file and code only (`["P1038"]`)
so every other check in the same file stays active. `.vscode/` is already in
`.gitignore`, so other contributors are unaffected.

> Practical consequence: `FakeMysqliResult::close()` is written `: void` to match
> the analyzer's stub, even though PHP's runtime also accepts an untyped form. The
> `: void` form satisfies both, so it is the chosen one.

---

### Test doubles: properti internal mysqli tidak bisa di-fake


Discovery yang berlaku untuk test double: `num_rows`,
`affected_rows`, dan properti internal serupa adalah **read-only virtual property**
di PHP 8.x. Override method maupun `__get()` sama-sama **tidak mengintervensi** —
`ReflectionMethod('mysqli_result', 'num_rows')` bahkan melempar *method does not
exist*, yang membuktikan murni itu property.

Konsekuensi praktis: **kode produksi jangan bergantung pada membaca properti
internal tersebut** kalau pemanggilannya ingin bisa diuji dengan
`tests/unit/support/MysqlFake.php`. Kalau memang butuh nilai tersebut, kirim
melewat parameter atau kembalikan lewat jalur lain — bukan lewat properti stmt.

---

### Notifications: `like` coalescing & retention (2026-10-03)

Goal: automatic **refill** MEeLCoin notifications, while stopping the unbounded
growth of `user_notifications`.

#### Background (measured, not assumed)

Before this change `user_notifications` had **no pruning whatsoever** — the only
`DELETE` statements came from user actions (`deleteOne`, `deleteAllByUser`,
`deleteByChat`). No age limit, no count limit.

What made it not urgent: the notifications page only ever fetches the **50 newest
rows** (`profile/notification.php` → `getList($conn, $userId, 50, …)`; the API
caps at `min(50, …)`). So **rows beyond the newest 50 per user are never shown** —
pure ballast.

State at the time: 30 rows / 3,351 B, averaging 112 B per row. The `meelcoin` type
accounted for 27 of those 30, because its `message` embeds the **full upload URL and
title**. Only **27 of the 30 were unique**: there were 3 exact duplicates, one of
them the fingerprint of an **upload retry**.

#### Rule 1 — Automatic refill notification

Created inside `MeelCoin::refill()` rather than at each call site, because the
credited amount is only known there — no caller can forget. `refill()` is invoked
from 7 production places and **none of them changed**.

`admin` already returns early inside `refill()`
(`if ($role === 'admin') return true;`), so that role automatically gets no
notification.

**The message uses the amount actually credited, never a fixed number:**

> Saldo Anda bertambah {amount} dari isi ulang.
> *(Your balance increased by {amount} from the refill.)*

Hardcoding "25" would lie in two real cases:

| Source | Value |
| --- | --- |
| Default `meelcoin_user_refill` | **15** |
| Default `meelcoin_member_refill` | 25 |
| All of them | admin-editable in `admin/meelcoin.php` → `site_settings` |
| Actual credited | `min($refillAmt, $maxCoins - $current)` — **clamped by remaining headroom** |

So a `member` with a balance of 45 (max 50) and a refill of 25 only receives
**+5**. The notification reuses the existing `meelcoin` type (not a new one), so
`$validTypes`, `$ICONS` and the filters in `notification.js` need no changes and
all coin history stays in a single tab.

#### Rule 2 — Coalescing `like` notifications

One viral video can produce hundreds of likes within minutes. Without
coalescing, the uploader receives hundreds of rows all reading "X liked your
work".

`Notification::create()` therefore becomes a no-op when a `like` notification for
the same user was already created within the **last 60 minutes**. What is traded
away: the identity of the liker inside that window (the notification names whoever
triggered it first) and the specific video is deferred too. That is deliberate —
the bound becomes clear: **at most one like notification per hour per user**.

Only `like` is coalesced. Every other type **must not** be throttled:

| Type | Reason |
| --- | --- |
| `reply` | conversation — a dropped notification means a lost comment |
| `admin_chat` | admin messages must all get through |
| `meelcoin` | tied to balance — the user needs the full history |
| `system` | system announcements |

#### Rule 3 — Retention: 50 rows & 15 days

`Notification::pruneUser()` deletes rows that are either:

1. older than **15 days**, or
2. outside the **50 newest** (ordered `created_at DESC, id DESC`).

Unread rows are pruned too once they fall outside the 50-row bound — which is
fair, since the notifications page never renders more than that anyway.

It runs from `scripts/gc.php` (CLI/cron), never from a web request — pruning
issues `DELETE` and does not belong in the per-request path. `pruneAll()` returns
the number of **users** pruned rather than rows: `mysqli_stmt::$affected_rows` is
an internal property that cannot be read from a test double, so counting rows
would make the code hard to test for no real benefit.

#### What needed a migration

A new index `idx_un_user_type_created (user_id, type, created_at)` — it backs the
`like` coalescing lookup and doubles as covering for the pruning query. Without it,
`isCoalesced()` scans all of that user's rows on every like press.

Migration **v4** plus `schema.sql` were kept in sync (both must move together, per
the T9 convention). Verified idempotent across 2 runs.

#### Verification

`tests/unit/NotificationRetentionTest.php` (**11 tests**) locks in: only `like` has a
coalescing window, other types touch no database at all, both retention bounds hold,
nonsensical arguments are clamped (keep ≥ 1), and pruning never touches other users.

Plus direct database verification (6 scenarios, all passing):

| # | Scenario | Result |
| --- | --- | --- |
| 1 | 5× like within 1 hour | **1** row |
| 2 | 3× reply | **3** rows (not throttled) |
| 3 | 120 rows → prune | **50** rows |
| 4 | 20 days old | removed; the new one **survived** |
| 5 | Another user | **untouched** |
| 6 | refill, role `user` | notification shown: "increased by **15**" |

Scenario 6 also proves the correction above: the number displayed is **15** (the
`user` role default), not 25.

---

### Test doubles: internal mysqli properties cannot be faked

A finding that applies to any fake: `num_rows`, `affected_rows` and similar
internals are **read-only virtual properties** in PHP 8.x. Overriding the method
and adding `__get()` **both fail to intercept** —
`ReflectionMethod('mysqli_result', 'num_rows')` even throws *method does not
exist*, which proves it is purely a property.

Practical consequence: **production code should not depend on reading those internal
properties** if it is meant to be testable with
`tests/unit/support/MysqlFake.php`. When such a value is genuinely needed, pass it
in as a parameter or return it through another path — never through a statement
property.### Test Infrastructure — important notes

#### 🚨 `createMock(mysqli::class)` consumed 8.86 GiB of RAM

Symptom: `phpunit --testsuite='MEeL Core Unit Tests'` climbed to **8.86 GiB RSS**
and then OOM'd inside `MockClass.php`.

Root cause: PHPUnit builds mock classes via `eval()` and **holds them in a static
cache for the lifetime of the process**. `mysqli` has
`bind_param(string $types, mixed &...$vars)` — by-reference plus variadic
parameters — so the generated class is very large. Accumulating them across several
tests pushed the process into gigabyte territory.

Solution: `tests/unit/support/MysqlFake.php` — `FakeMysqli`, `FakeMysqliStmt` and
`FakeMysqliResult` as subclasses that **never call the parent constructor** (no
connection, no generated class, ≈0 RAM).

Also added `phpunit.xml` → `<ini name="memory_limit" value="2G"/>` because this
repo's CLI php.ini uses `memory_limit = -1` (unlimited); without it a single bad
test can exhaust the machine's RAM before anyone notices.

Result: **8.86 GiB → 453 MiB** (~19× lighter) and the OOM is gone.

> **Rule:** when adding a test that needs `mysqli`, use `FakeMysqli` — **never**
> `createMock(mysqli::class)`. If a mock is genuinely required, build it **once**
> in `setUp` and reuse it.

#### `mysqli_result::$num_rows` cannot be faked

In PHP 8.3 `num_rows` is a **read-only virtual property**, not a method. Overriding
`num_rows()` and `__get()` **both fail to intercept** — accessing
`$result->num_rows` still throws `object is already closed`.
`ReflectionMethod('mysqli_result', 'num_rows')` even throws *method does not exist*,
which conclusively proves it is purely a property. Production code in
`MediaViewer::getMediaData()` deliberately avoids it.

#### Dev environment notes

- ⚠️ `MEEL_TEST_DB_HOST=127.0.0.1` is **mandatory** for integration tests. XAMPP's
  MariaDB socket lives at `/opt/lampp/var/mysql/mysql.sock`, while the PHP CLI looks
  in `/var/run/mysqld/mysqld.sock` (`mysqli.default_socket`) → *No such file or
  directory*. `localhost` fails, TCP `127.0.0.1` works. This is a dev-machine quirk
  only — CI is already correct (`ci.yml` uses `127.0.0.1`).
- ⚠️ `StorageMountGuardTest` (which spawns PHP subprocesses via `proc_open`) has
  failed once with `exit 255` when the suite runs **concurrently** with another test
  process touching `temp/`. Do not run it in parallel with `security_test` /
  `check_deploy`.
- The **IO/R** column in `htop` is a read *rate*, not RSS.

#### `release.yml` could previously never go green

The `test-gate` job runs `vendor/bin/phpunit` (**both** suites, including
integration) with no MySQL service, so all 96 integration tests ERRORed. It now has
a `mysql:8.0` service + healthcheck + `extensions: mysqli` + the `MEEL_TEST_DB_*`
env vars.

---

### The gate used to verify changes

```bash
vendor/bin/phpunit --testsuite='MEeL Core Unit Tests'
MEEL_TEST_DB_HOST=127.0.0.1 vendor/bin/phpunit --testsuite='MEeL Integration Tests'
php tests/functional_test.php
php tests/security_test.php
php tests/check_deploy.php
php database/migrate.php   # run twice to prove idempotency
php scripts/gc.php          # heavy housekeeping (needs $server = TCP)
```

---

## Resource for Developers

### Key Files to Understand

| File | Reason |
|---|---|
| `auth/config.php` | Configuration entry point |
| `auth/auth.php` | Authentication middleware |
| `modules/core/helpers.php` | Global utility functions |
| `modules/core/Transcoder.php` + `modules/transcoder/` | Facade + split services: `EncodeService`, `DownloadService`, `TranscodeService` (extend `TranscoderBase`) |
| `modules/core/Uploader.php` | File upload process |
| `modules/core/System.php` | Queue & monitoring |
| `modules/auth/RateLimiter.php` | API Rate Limiter |
| `modules/core/ProgressObserver.php` | Progress event contract (interface + callable adapter) — see `modules.md` |
| `modules/core/BrowserProgressObserver.php` | Browser presenter — maps engine events to the overlay/`meel*` JS |
| `modules/core/GarbageCollector.php` | Auto-cleanup |
| `modules/media/SearchEngine.php` | FULLTEXT Search engine |
| `modules/core/japanese.php` | Japanese text processing |
| `modules/core/bootstrap.php` | Bootstrap & environment |
| `modules/exceptions/*.php` | Exception classes |
| `modules/transcoder/FfmpegUtils.php` | FFmpeg utilities trait |
| `auth/mfa_setup.php` | MFA Setup (multi-step: secret → QR → verify → backup) |
| `auth/mfa_verify.php` | MFA TOTP verification page (rate limited) |
| `controllers/system/mfa.php` | MFA backend controller (generate/download backup codes) |
| `admin/mfa_reset.php` | Admin MFA reset panel |
| `partials/ui.php` | Overlay UI system (JS heavy) |
| `assets/js/shared/keyboard.js` | Shared keyboard shortcut guard (meelKeyShortcutIgnored) — used by video & music misc/mini-player |
| `assets/js/video/watch/misc.js` | Video-specific shortcuts (L=loop, A=auto-next) |
| `assets/js/video/watch/mini-player.js` | Video shortcuts (N=next, I=mini-player) + mini-player logic |
| `assets/js/music/watch/misc.js` | Music shortcuts (L=loop, E=equalizer, V=visualizer, I=mini-player) |
| `assets/js/shared/temp-index.js` | Shared loader of index.php into #temp-index-content without reload (meelLoadTempIndex) — used by video & music mini-player |
| `assets/js/shared/plyr-config.js` | Shared Plyr base config (MEEL_PLYR_COMMON: iconUrl, speed, keyboard, tooltips) — used by video & music players |
| `assets/js/shared/upload-progress.js` | Shared upload progress-bar animation (meelUploadProgress) — used by music & video upload pages |
| `assets/js/shared/resume-modal.js` | Shared resume modal (meelResumeModal) — used by video player-events & music player-core |
| `assets/js/shared/format-time.js` | Shared mm:ss time formatter (formatTime) — moved from music/shared/utils.js, used by music mini-players & resume-modal |
| `assets/js/shared/mini-player-popstate.js` | Shared popstate handler to exit mini-player mode (meelMiniPlayerPopstate) — used by video & music watch mini-players |
| `assets/js/video/watch/main.js` | Entry point folder watch/ — loads siblings synchronously (document.write) |
| `assets/js/video/watch/state.js` | Video player state management |
| `assets/js/video/watch/player-init.js` | Plyr + HLS.js initialization |
| `assets/js/video/watch/player-events.js` | Event orchestration (auto-next, glow, resume) |
| `assets/js/video/watch/mini-player.js` | Mini-player floating mode |
| `assets/js/video/watch/recovery.js` | Player auto-recovery system — uses shared `recovery-manager.js` factories |
| `assets/js/video/watch/gestures.js` | Mobile touch gestures |
| `assets/js/music/watch/main.js` | Entry point folder watch/ — loads siblings synchronously (document.write) |
| `assets/js/music/watch/mini-player.js` | Music mini-player mode (Spotify-style) — separated from player-core.js |
| `assets/js/music/watch/player-core.js` | Music player core (visualizer, EQ, bitrate, Media Session, resume-modal & session logic) |
| `assets/js/music/watch/description-toggle.js` | Music description "Selengkapnya" toggle + overflow detection |
| `assets/js/shared/recovery-manager.js` | Recovery factory — stuck detector, waiting timeout, reconnect overlay (shared video & music) |
| `assets/js/shared/media-session.js` | Media Session API helper — updates OS media controls with artwork/metadata |
| `assets/js/music/watch/state.js` | Music player state, equalizer presets & resume-session marker (`window.__meelResumeSessionActive`) |
| `assets/js/profile/manage.js` | Profile management (edit, delete media) |
| `assets/js/profile/avatar-crop.js` | Avatar cropping tool |
| `assets/js/profile/coin-countdown.js` | MEeLCoin refill countdown |
| `assets/js/profile/theme-init.js` | Theme initialization on profile page |
| `assets/js/admin/activity_log.js` | Activity log viewer with 3 tabs |
| `assets/js/shared/nav.js` | Navigation bar behavior |
| `assets/js/shared/theme.js` | Theme toggle logic |
| `assets/js/shared/notification.js` | Notification polling system |
| `assets/css/video/player.css` | Plyr video player overrides (object-fit: contain) |
| `assets/css/profile/*.css` | Profile module CSS (10 files: base, cards, coin, edit, manage, notification, stat, mfa-switch, type-badge, empty-state) |

### Music Player — Resume Modal Behavior

The music player shows the **"Lanjut Musik?"** resume modal when a song has a
saved playback position (`music_pos_<id>` in `localStorage`) and the user did
**not** arrive from an active mini-player session.

| Context | Behavior |
|---|---|
| **Mini-player session** — user tapped a card / playlist item or expanded the mini-player on `index.php`, and is still listening | 🎧 **Auto-continue** — no modal; every following song in the session plays automatically |
| **Cold visit** — direct `watch.php` open, page reload, or after an explicit pause/close of the mini-player | ❓ **Modal shown** — "Lanjut Musik?" asks whether to resume from the saved position |

**Mechanisms:**

- **One-shot flag `skip_resume_once`** (`sessionStorage`) — set by the index
  side on card/playlist tap and in `expandPlayerFromMiniPlayer()`. It is read
  and removed at **every** `meelInitWatchPlayer()` call (including gapless
  transitions), so it never leaks or sticks in storage.
- **Session marker `window.__meelResumeSessionActive`** (in-memory, declared in
  `assets/js/music/watch/state.js`) — activated when the one-shot flag is
  consumed. It lives for the whole SPA document, so **all** subsequent
  in-watch track changes (auto-next, song switch) skip the modal.
- **Explicit end of session** — `miniPlayPauseIndex()` (pause) and
  `closeMiniPlayerIndex()` on `index.php` clear both the one-shot flag and the
  session marker (`assets/js/music/shared/mini-player.js`). After that, opening
  a song from a link shows the modal again.
- **Cold visits** — a full page load creates a new document where the in-memory
  marker is gone, so the modal can appear (`skipOnce` in `player-core.js`
  checks `skipResumeModalOnce || window.__meelResumeSessionActive`).
- **Stuck-paused guard** — if the modal is suppressed but the song has a saved
  position, `onFreshTrackReady()` auto-plays from the beginning instead of
  leaving the song silent.

> **Design decision (2026-08):** active listening sessions auto-continue
> without interruption; only cold visits ask to resume.

### Video — AI Upscale & Play Recovery

Behaviour notes for the video player that are not obvious from a quick code
read; primary references are `assets/js/video/watch/upscaler.js` and
`recovery.js`.

#### Option gating (WebGPU support)

The **AI Upscale** row in the Settings menu is always present, including when
the browser does not support WebGPU — no automatic toast when the page opens:

- Unsupported → the row gets `aria-disabled="true"` + the
  `meel-upscale-unavail` class (dimmed but **still clickable**), its value cell
  reads `Tidak tersedia`, and the normal panel (toggle/Model/Mode/Scale) is
  unreachable.
- Clicking the row opens the read-only `upscale-why` sub-panel: title, the
  specific reason per cause, a requirement list, and a **"Cek ulang dukungan"**
  button running `checkSupport(true)`; if support is now present → toast + the
  panel switches to the normal toggle.
- While a check is running (`supportChecking`) the row shows a spinner and is
  **not** disabled yet — anti-flicker, the menu does not change under the user.
- After the check, `updateUI()` syncs the row (value + aria-disabled) through
  `setHomeRowValue()` → `applyHomeRowState()`.

Reasons come from a single source: `unsupportedReason()` (also exposed as
`MEEL_UPSCALER.unsupportedReason()`), shared with `supportLabel()` and
`diagnose()` through the `WHY` constants — same check order as `diagnose()`:

| Code | Condition | Reason |
| ---- | --------- | ------ |
| `insecure` | `!window.isSecureContext` | page is not HTTPS/localhost |
| `nogpu` | `!navigator.gpu` | browser has no WebGPU |
| `no-adapter` | `requestAdapter()` → `null` | GPU/driver blocked, GPU blank, or WebGPU flag off |

#### On/off status per video (sessionStorage)

The AI Upscale state lives in **sessionStorage** (`MEEL_KEYS.UPSCALE_ENABLED`:
`"true|<videoId>"` while ON, `"false"` while OFF) — not localStorage — so the
default is **OFF** and it only survives for the current video:

| Event | State |
| ----- | ----- |
| New tab / never switched on | **OFF** (default) |
| Page refresh (same video) | stays **ON** |
| Video loops on its own without leaving the page | stays **ON** |
| Switching to another video (recommendation, next button, mini-player card) | **OFF** |
| Auto-next when the video ends | **OFF** |
| Opening another video URL in the same tab (full navigation) | **OFF** |

Two guard layers:

- `readEnabled()` only honours `"true|<videoId>"` when the current video id
  matches. `validateState()` runs it again on every `attach()` — including when
  the Plyr instance is unchanged — so a full navigation to another video turns
  the upscale off and discards the stale value from the previous video.
- In-place transitions (no page reload) call
  `MEEL_UPSCALER.resetForNewVideo()`: `skipToNextVideo()` in `player-events.js`
  (both manual clicks and auto-next go through it) and the mini-player card
  listener in `mini-player.js`. The reset runs without a toast so transitions
  are not flooded with notifications; the actual shutdown is centralised in
  `turnOff()`.

`modelId`/`modeId`/`scaleId` stay in localStorage as cross-session preferences
— only the on/off state is per video. The old `localStorage` value for the same
key is removed once when the script loads.

#### Scale: output texture size

The **Scale** panel only picks the *texture* size of the upscaled output — not
the on-screen video size (the video always stretches to its own box through the
linear blit, so raising Scale does not make the video look bigger):

| Choice | Texture size |
| ------ | ------------ |
| `auto` — *Auto (follow screen)* | fits the display box × `devicePixelRatio` (capped at 2×), **never smaller** than the video's native resolution |
| `1.5` | 1.5× the video's pixels (1920×1080 → 2880×1620) |
| `2` | 2× the video's pixels (1920×1080 → 3840×2160) |

- The `MAX_W × MAX_H` cap (3840×2160) remains the final authority for every
  choice — including `auto`.
- `auto` used to be allowed to produce a texture **smaller** than native (a
  ~1217×685 display box on a 1080p screen): Scale then looked like "it does
  nothing" because the texture was shrunk and stretched back to the same box —
  extra GPU work with no visible result. Now `s = max(s, 1)` (see
  `computeTarget()`), so the output is at least native; when the target is
  native the resample path is skipped (see MEeLScale).
- The info row under the Scale menu (`<p class="meel-upscale-scale-info">`,
  `aria-live="polite"`) shows `Video WxH → output WxH (n×)` plus a hint that
  Scale controls the texture, not the display. That element is inserted
  **after** the `<div role="menu">` (see `buildScaleList()`) so the ARIA menu
  contract stays intact; its text comes from the same `computeTarget()` used
  by the rebuild, so it is correct even before the GPU chain finishes. Styles
  live in `assets/css/video/upscaler.css`.

#### Upscale model API contract

Models are pluggable through `MEEL_UPSCALER.registerModel()`:

```javascript
MEEL_UPSCALER.registerModel({
  id, label, short,
  modes: [{ id, label, short }, ...],
  load: function () { return Promise; },      // heavy lazy-load (bundle / weights)
  buildChain: function ({ device, modeId, inputTexture, native, target }) {
    return [node, ...];
    // node = {
    //   pass(encoder): void,            // encode this frame's passes
    //   getOutputTexture(): GPUTexture, // output texture of the last node
    //   pipelines?: [GPUPipelineBase],  // registered for cleanup
    //   destroy?(): void,               // optional: free the node's resources;
    //                                   // when present it replaces getOutputTexture
    // };
  },
});
```

- `inputTexture` is `rgba16float` at `native` size; the final size may differ
  from `target` — the linear blit stretches it to the canvas size.
- Model files live in `assets/models/<id>/model.js` and are injected on first
  selection. The `registerModel()` inside that file **overwrites** the static
  descriptor registered by the shell, so `doRebuild()` always re-reads the
  entry before calling `buildChain()`.
- Built-in models: `anime4k` (vendor bundle), `meelscale` (local resampler) and
  `meelsharp` — **MEeLSharp** (Lanczos-3 + CAS, no weights). `meelvision` —
  **MEeLVision** (weights in `assets/models/meelvision/weights.js`) is still in
  the repo, but it is no longer registered in the AI Upscaler menu.

#### Model notes: MEeLVision (FSRCNN architecture) & MEeLScale

Both are local models under `assets/models/<id>/` — no internet downloads.
MEeLVision no longer shows up in the menu (its descriptor was removed from
`upscaler.js`); the notes below still apply to its files.

**MEeLVision ×2** (`meelvision/model.js` + `meelvision/weights.js`)

- Product name **MEeLVision** (id `meelvision`, folder
  `assets/models/meelvision/`); the architecture term stays FSRCNN. Weights are
  a base64 `Float32Array(13163)` in `window.MEEL_VISION_WEIGHTS_B64`, trained
  locally by a vanilla JS trainer that is not in the repo (regenerate with
  `node scripts/train-meelvision.js` in a local checkout).
- Architecture: LR input (minus 0.5) → conv1 5×5 pad2 3→24 + PReLU →
  shrink 1×1 24→16 + PReLU → 3× map 3×3 pad1 16→16 + PReLU → deconv 9×9
  stride2 phase (16→3) + bias 0.5 → clamp 0..1.
- Blob offsets (the `OFF` constant in `model.js` must match exactly):
  `c1w 0, c1b 1800, c1p 1824, sw 1848, sb 2232, sp 2248, m1w 2264, m1b 4568,
  m1p 4584, m2w 4600, m2b 6904, m2p 6920, m3w 6936, m3b 9240, m3p 9256,
  dw 9272, db 13160`.
- Tiled execution (T=512 LR px, 5 px halo per side) keeps the intermediate
  footprint small regardless of video resolution; the 16 intermediate channels
  are 4 rgba16float textures per layer. Each tile runs 5 render passes (P1
  fused conv1+shrink, P2–P4 map, P5 deconv into the 2× output texture with a
  scissor) and the order is **tile-major**: one tile finishes P1→P5 before the
  next one, because the intermediate textures are shared.
- Edge semantics match the CPU trainer: reads outside the frame are skipped
  (zero-pad).
- Evaluation (n=150, 64×64 HR patches, 1500 steps): PSNR **32.409 dB** (bicubic
  32.390 dB, bilinear 31.308 dB).

**MEeLScale** (`meelscale/model.js`)

- Weightless separable resampler. `buildChain()` picks the cheapest path from
  the `target` vs `native` difference:

  | Difference | Pass chain |
  | ---------- | ---------- |
  | `target == native` | **CAS only** (resample skipped — one pass) |
  | width only | horizontal → CAS |
  | height only | vertical → CAS |
  | both axes | horizontal → vertical → CAS (intermediate `rgba16float` texture) |

  Each node calls `destroy()` to release the texture it owns.
- Coordinate convention `src = pos * scale - 0.5` with `pos` = the output pixel
  centre (`@builtin(position)` already includes +0.5), so at `scale 1` the
  result is identical to the source. The old formula
  `(pos + 0.5) * scale - 0.5` added a **half-pixel shift** that shows up as
  blur at native resolution; the same fix was applied to
  `meelsharp/model.js`.
- Modes are algorithm names (`bilinear`, `mitchell` B=1/3 C=1/3, `catrom` B=0
  C=1/2, `lanczos2`, `lanczos3`). When reducing resolution the taps widen:
  `rx/ry = clamp(scale, 1, 8)`, the tap window is capped at 63 taps per axis,
  edges are clamped and the result is normalised by the total weight (`wsum ≤
  0` falls back to the nearest sample, not a black pixel).
- The final **CAS** pass (FidelityFX by AMD, MIT licence — attribution kept in
  the file header) is always active with `SHARPNESS 0.45`, including on the 1:1
  path, so a native target still gets adaptive sharpening.

#### Render pipeline & backpressure

- Only one GPU submit is *in-flight*: `renderFrame()` holds further submits
  until the previous `onSubmittedWorkDone()` resolves (`loopBp.busy`); skipped
  frames are flagged `pendingRender` and caught up once the GPU is done.
  Without this rule the queue grows → the GPU idles suddenly → playback stalls.
- A 3-second queue timeout three times in a row turns the upscaler off with a
  toast. If `queue.onSubmittedWorkDone` is unavailable the path runs without
  backpressure (not an error).
- Metrics (120-sample window) are read via `MEEL_UPSCALER.diagnose().perf` or
  `stats().perf` → `{msAvg, msP95, videoFps, renderFps, budgetMs, fitsBudget,
  samples, skipped, timeouts}`; `fitsBudget === null` means no samples yet.
- `stats()` also exposes loop state (`loopPending`, `loopRvfc`, `loopRaf`,
  `pendingRender`, `gpuBusy`) and `rebuildReason`
  (`enable|model|mode|scale|resolution|resize|attach`) to tell a normal
  rebuild apart from a stuck loop.

#### Anti-black transition, resolution debounce, rVFC watchdog

- **Anti-black transition:** the `meel-upscale-active` class (hides the source
  video) is only added by `renderFrame()` after a frame renders successfully,
  and removed when a rebuild starts and while the resolution debounce runs. Do
  not add this class anywhere else.
- **600 ms resolution debounce:** an HLS quality switch can change
  `videoWidth` several times before settling; while waiting, cross-size copies
  are skipped (`skipped`) and the source video stays visible. Metadata that is
  not ready yet re-arms the timer instead of giving up. A size that reverts to
  the previous value simply resumes the loop.
- **500 ms rVFC watchdog:** Chrome drops `requestVideoFrameCallback` on
  hidden↔visible transitions or on `load()`; if the first callback never fires,
  `loop.pending` stays `true` and the canvas freezes while the video keeps
  playing. The watchdog re-registers the callback when the tab is active and
  the video plays but no tick happened for >700 ms. `loadstart` resets
  `loop.pending`, `loadeddata` kicks the loop again.

#### Recovery while the tab is in the background

- `triggerPlayerRecovery()` defers recovery until `visibilitychange` → visible
  (the cooldown rule still applies to a deferred run), autoplay retry
  (`pendingPlayRetry`) waits for an active tab, and `RecoveryManager` resets
  the `lastTime`/`lastTs` baseline while hidden — time spent hidden is never
  counted as "stuck".
- Without those gates the browser silently rejects `play()` and the video
  freezes when the user comes back.

#### CSP note for `blankVideo`

- Plyr calls `cancelRequests()` with its built-in
  `https://cdn.plyr.io/static/blank.mp4`; that external URL violates
  `media-src 'self' data: blob:` and triggers a chain of
  `MEDIA_ELEMENT_ERROR` after `player.destroy()`.
- `assets/js/shared/plyr-config.js` points `blankVideo` at a local data URI
  (applies to video, music, and audio). **Do not restore the CDN URL** and do
  not relax the CSP.

### Key Processes

1. **Upload Pipeline** — Uploader → FFmpeg → HDD → DB
2. **Download Pipeline** — URL → yt-dlp → FFmpeg → HDD → DB
3. **Auth Flow** — Login → Session → RBAC → Activity Log
4. **HTMX Flow** — Event → Request → Server → Response → DOM swap
5. **MFA Flow** — Login password valid → Check mfa_enabled → Redirect mfa_verify.php → Verify TOTP → Set full session
6. **Music Player Session & Resume** — Card/playlist tap → mini-player (sets `skip_resume_once`) → expand → watch (consumes flag, activates session marker) → auto-continue; cold visits show the resume modal
7. **Video Upscale & Recovery** — toggle → lazy-load model → rebuild pipeline → one in-flight submit (wait for GPU, skipped frames catch up); quality switches are debounced 600 ms with no black frame; recovery & autoplay wait for an active tab

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
