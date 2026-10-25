# 👨‍💻 Development & Contribution Guide

Guide for developers who want to contribute or understand coding standards in MEeL-HUB.

---

## Table of Contents

- [Development Environment](#development-environment)
- [Coding Standards](#coding-standards)
- [Database Structure](#database-structure)
- [Coding Conventions](#coding-conventions)
- [`.htaccess` & Legacy Route Notes](#htaccess--legacy-route-notes)
- [Testing](#testing)
- [MFA / TOTP Development](#mfa--totp-development)
- [Pull Request Guide](#pull-request-guide)
- [Troubleshooting Development](#troubleshooting-development)
- [Time Contract](#time-contract)
- [Housekeeping & Throttle](#housekeeping--throttle)
- [Cache & Performance](#cache--performance)
- [Annotations for the Static Analyzer](#annotations-for-the-static-analyzer)
- [Notifications](#notifications)
- [Resource for Developers](#resource-for-developers)

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

### Dev Environment Notes

- ⚠️ `MEEL_TEST_DB_HOST=127.0.0.1` is **required** for integration tests. The XAMPP MariaDB socket is at `/opt/lampp/var/mysql/mysql.sock`, while the PHP CLI looks for `/var/run/mysqld/mysqld.sock` (`mysqli.default_socket`) → `No such file or directory`. `localhost` fails, TCP `127.0.0.1` works. This is a dev machine issue only — CI is already correct (`ci.yml` uses `127.0.0.1`).
- ⚠️ `StorageMountGuardTest` (it spawns a PHP subprocess via `proc_open`) has failed once with `exit 255` when the suite runs **at the same time** as another test process touching `temp/`. Do not run it in parallel with `security_test` / `check_deploy`.
- The **IO/R** column in `htop` is a read rate, not RSS.

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

Properties and constructor parameters **must** have type hints (PHP 8.1+):

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
### Internal mysqli Properties Cannot Be Faked

`num_rows`, `affected_rows`, and similar internal properties are **read-only virtual properties** in PHP 8.x. Overriding the method and `__get()` alike **do not intervene** — `ReflectionMethod('mysqli_result', 'num_rows')` even throws *method does not exist*, which proves it is purely a property.

Practical consequence: **production code must not depend on reading those internal properties** if the call is meant to be testable with `tests/unit/support/MysqlFake.php`. If the value is really needed, pass it through a parameter or return it through another path.

### Static Cache Across Tests

`get_user_role()` and `get_user_active()` use a per-process `static $cache`. That cache is **not** reset between tests, so one test can poison a later test running in the same process. A real case: `MediaViewerTest` builds a `MediaViewer` with a **mock connection**, and the constructor calls `get_user_role($mock, 1)`. The mock has no row, so the function returns the fallback `'user'` — and **memoizes** that fallback into `static $cache[1]`. Several tests later, in the same process, use the real test database where `role = 'admin'`, but the stale cache wins.

What makes this hard to detect: the test **passes when run alone** (`--filter`) and fails only when the whole suite runs.

The rule: **a cache may only hold facts, not uncertainty.** A row that is not found means "not known yet", not "this user has role `user`". So a negative result is deliberately **not** memoized:

```php
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row === null) {
    return 'user';          // fallback, NOT cached
}

$role = (string)$row['role'];
$cache[$user_id] = $role;   // a fact → safe to cache
```

`get_user_active()` has the twin problem: it memoizes a `false` derived from a missing row, so a user who just became active still reads as inactive until the process ends. Pinned by `tests/unit/UserRoleCacheNotFoundTest.php`.

### Media Constants Are Locked in `tests/bootstrap.php`

The media path constants (`MEEL_HDD_*`) are set in `tests/bootstrap.php`, **before** any production file that could define them while being `require`d. `modules/core/TranscoderBase.php` defines `MEEL_HDD_BASE` as the placeholder `'/path/to/your/media'` if the constant is not there yet — and `require_once` **cannot be undone**, so every test running after that sees `meel_media_base_path('video')` pointing at a non-writable placeholder.

The fix is to lock the constants to `temp/media-test/` first. Two effects at once: test order no longer determines the result, and no media directory leaks outside the repo while tests run.

### Test Infrastructure

**`createMock(mysqli::class)` burns 8.86 GiB of RAM.** PHPUnit builds a mock class through `eval()` and **keeps it in a static cache for the lifetime of the process**. `mysqli` has `bind_param(string $types, mixed &...$vars)` — by-reference plus variadic — so the generated class is very large, and stacking it across several tests pushes the process into gigabyte territory.

The solution is `tests/unit/support/MysqlFake.php` — `FakeMysqli`, `FakeMysqliStmt`, `FakeMysqliResult` as subclasses that **do not call the parent constructor** (no connection, no generated class, RAM ≈ 0). Plus `phpunit.xml` → `<ini name="memory_limit" value="2G"/>`; without it a single bad test can exhaust the machine's RAM before it is noticed. Result: **8.86 GiB → 453 MiB** (~19× lighter), OOM gone.

> **Rule:** when adding a test that needs `mysqli`, use `FakeMysqli` — **not** `createMock(mysqli::class)`. If a mock is really needed, build it once in `setUp` and reuse it.

### Verification Gate

```bash
vendor/bin/phpunit --testsuite='MEeL Core Unit Tests'
MEEL_TEST_DB_HOST=127.0.0.1 vendor/bin/phpunit --testsuite='MEeL Integration Tests'
php tests/functional_test.php
php tests/security_test.php
php tests/check_deploy.php
php database/migrate.php   # 2× for idempotence
php scripts/gc.php          # heavy housekeeping (needs $server = TCP)
```

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

### Helper Functions

The implementation is in `modules/auth/helpers/mfa.php` — that file is the single
source of truth, so it is not copied into the documentation. Its functions:
`generate_mfa_secret()`, `generate_totp()`, `verify_totp()`, `verify_totp_step()`,
`generate_backup_codes()`, `verify_backup_code()`, `generate_otpauth_url()`,
`base32_decode()`.

### Backup Codes System

Eight backup codes, each 6 digits, stored as a `password_hash()` (bcrypt,
one-way) hash that cannot be read back. A code that has been used is removed
from the list when it is verified, so each code works exactly once. The
implementation is in `modules/auth/helpers/mfa.php`.

### Database Schema

3 new columns in `users` table (now covered by migration **v1** — formerly legacy v9):

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

This project is licensed under **GNU Affero General Public License v3.0 (AGPLv3)**. See the [`LICENSE`](../../LICENSE) file for full text.

> **By submitting a Pull Request, you agree that your contributions will be licensed under AGPL v3.**

#### Copyright Header on New Files

```php
/**
 * MEeL - Media Hub Platform
 *
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3
 */
```
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


## Time Contract

A MySQL `DATETIME` column **does not store a timezone**. The value written can come from two different clocks:

| Source | Clock used |
|---|---|
| `NOW()` / `CURRENT_TIMESTAMP` | the MySQL server clock (`@@time_zone`) |
| `date()` / `time()` / `strtotime()` | the PHP clock (`date_default_timezone_get()`) |

Almost every datetime read in this repo goes through `strtotime()` → always the PHP clock. As soon as the PHP clock differs from MySQL's `time_zone`, a column written with `NOW()` looks like it is in the future, and two things break: `$elapsed = max(0, time() - $ts)` goes negative and is clamped to `0` (countdowns and ages always full), and the `col <= $threshold` threshold is never true (refill/lockout logic dead entirely).

**Repo rule:** if a `DATETIME` column is read from PHP via `strtotime()`/`time()`, that column **must be written from the PHP clock**. Use `meel_now()` / `meel_now_ago()` from `modules/core/helpers/datetime.php`. `NOW()` may stay only when the column is read purely as a SQL DATETIME (e.g. `ORDER BY created_at DESC`) and never compared against the PHP clock.

| Column | Written by | Read by | Symptom when the timezone drifts |
|---|---|---|---|
| `users.meelcoin_last_refill` | `MeelCoin.php` (3 points) | `MeelCoin.php` `$threshold` + `$elapsed` | refill dead, countdown always full |
| `users.last_activity` | 5 production points (see below) | 6+ points | a "kicked" user still shows as ONLINE |

The `last_activity` write points already converted: `activity_logger.php` (authenticated user UPDATE + guest INSERT `ON DUPLICATE KEY UPDATE`), `auth/login.php` (×2), `auth/mfa_verify.php`, `auth/auth.php`, and `admin_actions.php` (`kick_user`, which previously used `DATE_SUB(NOW(), INTERVAL 10 MINUTE)`).

**Audit not finished yet:** the columns `created_at`, `login_attempts`, and `rate_limit` are **still written with `NOW()`**. For `created_at` the impact is display-only (clock drift in `admin/activity_log.php`) and has not been audited per column. Do that audit before building a time-based worker, because a time-based scheduler gets more sensitive to skew as it grows.

On a dev machine the PHP CLI uses `date.timezone = UTC` while MariaDB's `@@time_zone = SYSTEM` is WIB (UTC+7) — a difference of 25195 seconds ≈ 7 hours. `MeelCoinIntegrationTest::testRefillResetsTimerWhenBalanceIsAtMax` caught exactly that gap.

## Housekeeping & Throttle

### ActivityLogger

`ActivityLogger::onRequest($conn)` is called from `auth/config.php`. What is guarded is the **cost**, not the behaviour:

- **60-second throttle.** The signature `last_page|device|access_via|ip_address` is stored in `$_SESSION['_meel_touch']`; an identical payload inside that window is not written again. The signature deliberately includes page + device + network so that a page change is still recorded immediately instead of waiting for the window to expire.
- **Skip non-HTML responses.** `shouldSkipTelemetry()` rejects htmx partials (`HTTP_HX_REQUEST`), an `Accept` header without `text/html`/`*/*` (JSON and HLS segments), and the `stream.php` / `file.php` endpoints.
- 🔒 **The security part is NOT throttled.** `enforceIpBan()` and `enforceSingleSession()` always run. Throttling is for telemetry only — a throttle here would open a revocation gap.
- ⚠️ **The `$hasRun` static is useless under PHP-FPM.** Statics are not shared between requests, so that guard is only useful inside one process. The real throttle mechanism is the **file**, not the static.

### GarbageCollector

`run()` does two expensive things on **every request**: scanning the storage directory and `RateLimiter::cleanup()`. Both now sit behind a single file throttle (`temp/gc_run_last_run.txt`, `RUN_INTERVAL_SECONDS = 60`), with the 3-second time limit unchanged.

**CLI/web split:** `ALTER TABLE ... AUTO_INCREMENT` blocks MySQL metadata and `syncViewsFromLogs()` rewrites aggregate columns — both are too heavy for a request. They moved to `GarbageCollector::runCliMaintenance()`, which has a **`PHP_SAPI !== 'cli'` seatbelt**: called from a web request it becomes a no-op, not an error. Entry point `scripts/gc.php` (mode `--run-only` for light housekeeping).

## Cache & Performance

### The queries that are genuinely expensive

`isServerBusy()` only needs a **number**, so it uses `COUNT(*)` per table instead of pulling every queue row (URL, username, `created_at`) complete with JOIN and filesort. `getActiveQueues()` is **kept** because `server_stats.php`, `server_stats_sse.php`, and `admin_actions.php` really do need the row details.

The query count does not drop (`Com_select` stays at 2 on both paths). The benefit is in **payload and parsing**: at 2,001 `processing` rows × 200, the count path takes 109 ms · 0 B, the full path 7,088 ms · 1,366,920 B. `EXPLAIN`: the `COUNT(*)` path → `type: ref`, `key: status`, `Using index` (covering, no row access); the full path → JOIN `users` + `Using temporary; Using filesort`.

`MediaViewer::__construct()` uses `get_user_role()` and `get_user_active()` (`modules/auth/helpers/user.php`, cached, `LIMIT 1`) — almost always a cache hit, because `MediaViewer` runs on pages that already have a session.

### Random id sampling without `ORDER BY RAND()`

| Function | Role |
|---|---|
| `meel_media_stats_all()` | **One** `UNION ALL` query for `video`/`music`/`books` (COUNT + MIN(id) + MAX(id)), cache file per table for 30 seconds |
| `meel_media_stats()` | One table; a cache miss warms **all three** at once |
| `meel_pick_random_ids()` | **Random range** sampling for recommendations |
| `meel_invalidate_media_stats_cache()` | Drops the cache (called at 5 mutation points) **and** the admin orphan cache with it |
| `meel_media_table_whitelist()` | Validates the table name (anti injection through the table name) |

The sampling strategy: several `id >= ? ORDER BY id LIMIT ?` windows with a random start, merged into a unique set. Each window uses the id index (range scan) → cost `O(log n + limit)`, no filesort, and still unbiased across the whole id range. **`ORDER BY RAND()` is now zero across every query in the repo.**

The advice to "take the first 500 ids with `LIMIT 500`" was **rejected** — that would bias recommendations toward the smallest ids. Random range sampling stays unbiased across the whole range and **always** carries a `LIMIT`, so it satisfies "do not pull the whole table" without giving up the spread.

### File cache convention

1. **Write only through `meel_write_cache_file()`** — never `file_put_contents()` directly. That function writes a temporary file and then `rename()`s it, which is atomic. `LOCK_EX` is **not** an atomic substitute: it only locks the writer, while a reader can still read a file that is being written.
2. **Read only through `meel_read_cache_file()`** — do not re-implement per-file TTL logic. The time keys used are: `ts` (default), `time` (`dir_size`), `checked_at` (admin orphan).
3. **Short TTL + invalidation on mutation** — TTL is not a freshness tool. A cache that has to be fresh is invalidated at the mutation point (`meel_invalidate_media_stats_cache()` also drops the orphan cache). The TTL only holds back repeated scans during a fast refresh.
4. **JSON only** — no `serialize()`/pickle in the cache.
5. **The directory must be group-writable** — `temp/cache/` is created with mode `0775`. For older installs: `sudo chgrp daemon temp/cache && sudo chmod 775 temp/cache`.

The cache path can be redirected through the constant **`MEEL_MEDIA_CACHE_DIR`** (following the `MEEL_SERVER_STATS_CACHE` pattern) so tests do not depend on Apache's ownership of `temp/cache/`. The admin orphan cache follows the same constant through `meel_admin_orphan_cache_path()` (override `MEEL_ADMIN_ORPHANS_CACHE`).

### Conditional request for media streaming

Three pure functions (not inlined into a function that `exit()`s), so they can be tested:

| Function | Role |
|---|---|
| `meel_cache_is_fresh()` | The 304 decision from `If-None-Match` / `If-Modified-Since` |
| `meel_media_etag()` | Strong ETag from path+size+mtime |
| `meel_media_cache_control()` | `m3u8` → `private, no-cache`; everything else → `private, max-age=31536000, immutable` |

Rules that must hold:

- 🔒 **Never 304 on a Range request.** 304 together with 206/`Content-Range` is an invalid response. The condition is `if (!$isPartial && …)`.
- 🔒 **Stay `private`, never `public`.** The content is gated per session/user; `public` risks leaking through a shared cache/CDN to other users.
- HLS playlists change constantly (new segments are added) → they must be revalidated. Media segments/files are immutable once written → they may be cached long.
- `ETag`, `Last-Modified`, `Cache-Control` are sent on **both** paths (X-Sendfile **and** streaming), not just one.

`If-None-Match: *` must be treated as fresh (RFC 7232 §3.2).

### Asset versioning

`meel_asset_dir_version()` deliberately uses the max mtime of the **directory**, not a per-file version. The reason: `assets/js/{video/watch,music/index,music/watch}/main.js` reads `?v=` from `document.currentScript.src` and forwards it to every dynamically loaded child module — with a per-file version, a change in a child module would **not invalidate the browser cache at all**, only the entry file would. The full closure lives in the content-hash build. The list of `meel_asset_dir_version()` callers is pinned by `AssetVersioningTest`.

## Annotations for the Static Analyzer

Admin pages inherit variables from files that are `include`d (`auth/config.php`, `controllers/admin/admin_data.php`) — valid at runtime, but invisible to a static analyzer because analyzers do not follow variables across `include`. That is why 12 admin pages carry an `@var` block at the top of the file.

### One Variable per `@var` Line

```php
/** @var \mysqli_result $a, $b, $c; @var array $d */   // ❌ only the first line is read
```

The comma-list form is **not understood by any analyzer** — only the first `@var` line is read and the rest are silently ignored. The fix:

- one variable per `@var` line;
- the docblock block **must be placed before the first use** — `@var` only applies forwards. That is why `$conn` is still reported as an error when its block is placed after the first use.

These `@var` lines are **functional** (not decorative comments) — removing them brings back hundreds of `P1008` in the editor.

> **Note:** PHPStan was tried here (level 2, scope `admin/`) and successfully brought 165 errors down to 0 — but it was **withdrawn again**. The reasons: its `scanFiles` config is fragile (absolute paths; if one is renamed, PHPStan only prints "Scanned file … does not exist" and then exits silently, with a misleading result), there is no CI job running it, and `require-dev` is not installed in the production build. The corrected `@var` blocks remain valuable for the IDE.

### `P1038` on `FakeMysqliStmt::bind_param()`

`P1038` means "method signature incompatible with parent". The only trigger for it in this repo is the mysqli test double, and that is a **false positive from the Intelephense stub**:

| Form | Accepted by PHP 8.3? |
|---|---|
| Intelephense 1.18.5 stub: `bind_param($types, &$var1, &...$_)` | ❌ **REJECTED by the engine** |
| Real: `bind_param(string $types, mixed &...$vars): bool` | ✅ **ACCEPTED** |

The Intelephense stub has not caught up with the `mysqli_stmt::bind_param()` signature change in PHP 8.0, so the analyzer and the engine **cannot both be satisfied**. It is handled through `.vscode/settings.json` → `intelephense.diagnostics.exclude`, locked to that file and code only (`["P1038"]`) so the other checks in the same file stay active. Practical consequence: `FakeMysqliResult::close()` is written `: void` to match the analyzer stub, even though the PHP runtime also accepts the untyped form — the `: void` form satisfies both.

## Notifications

Goal: an automatic MEeLCoin **top-up** notification, while stopping `user_notifications` from growing without bound.

### Rule 1 — Automatic top-up notification

Created inside `MeelCoin::refill()`, not at the call site. The reason: the amount that was credited is only known there, so no caller can forget it. `refill()` is called from 7 production places and **not one of them changed**. The `admin` role already returns early in `refill()` (`if ($role === 'admin') return true;`), so that role automatically gets no notification.

The message uses the amount that was really credited, never a fixed number:

> Saldo Anda bertambah {jumlah} dari isi ulang.

Hardcoding "25" would lie in two real cases:

| Source | Value |
|---|---|
| Default `meelcoin_user_refill` | **15** |
| Default `meelcoin_member_refill` | 25 |
| All values | changeable by an admin in `admin/meelcoin.php` → `site_settings` |
| Real amount | `min($refillAmt, $maxCoins - $current)` — **cut by the remaining headroom** |

So a `member` user with a balance of 45 (max 50) and a refill of 25 receives only **+5**. The notification reuses the `meelcoin` type (not a new type), so `$validTypes`, `$ICONS`, and the filter in `notification.js` do not need to change and the whole coin history stays in one tab.

### Rule 2 — Coalescing `like` notifications

One viral video can produce hundreds of likes within minutes. Without coalescing, the uploader receives hundreds of notification rows that all say "X liked your work". So `Notification::create()` becomes a no-op when a `like` notification for the same user was already created within the **last 60 minutes**. What is sacrificed: the identity of the liker within that window (the notification shows whoever triggered it first) and any video that also got a like is deferred. That is deliberate — the limit becomes clear: **at most one like notification per hour per user**.

Only `like` is coalesced. Other types **must not** be throttled:

| Type | Reason |
|---|---|
| `reply` | a conversation — without the notification the comment is lost |
| `admin_chat` | admin messages must all come through |
| `meelcoin` | tied to the balance — the user needs the full history |
| `system` | system announcements |

### Rule 3 — Retention: 50 rows and 15 days

`Notification::pruneUser()` deletes rows that are **older than 15 days**, or that fall outside the **50 newest** (ordered `created_at DESC, id DESC`). Unread rows are deleted too once they are pushed past the 50-row limit — legitimate, because the notification page never shows more than that anyway.

It runs from `scripts/gc.php` (CLI/cron), not from a web request — pruning issues a `DELETE` and does not belong in a per-request path. `pruneAll()` returns the number of **users** pruned, not the number of rows: `mysqli_stmt::$affected_rows` is an internal property that cannot be read on a test double, so counting rows would make the code hard to test for no real benefit.

The index `idx_un_user_type_created (user_id, type, created_at)` supports the `like` coalescing lookup and doubles as a covering index for the pruning query. Migration **v4** and `schema.sql` are in sync (both must change together, per the T9 convention) and verified idempotent twice.

`tests/unit/NotificationRetentionTest.php` (11 tests) pins: only `like` has a coalescing window, other types never touch the database, both retention limits are correct, nonsensical arguments are clamped (keep ≥ 1), and pruning never touches another user.
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
| `assets/js/video/watch/misc.js` | Video-specific shortcuts (L=loop, A=auto-next, S=AI-upscale, B=ambient-glow) |
| `assets/js/video/watch/mini-player.js` | Video shortcuts (N=next, I=mini-player) + mini-player logic |
| `assets/js/music/watch/misc.js` | Music shortcuts (L=loop, E=equalizer, V=visualizer, I=mini-player) |
| `assets/js/shared/temp-index.js` | Shared loader of index.php into #temp-index-content without reload (meelLoadTempIndex) — used by video & music mini-player |
| `assets/js/shared/plyr-config.js` | Shared Plyr base config (MEEL_PLYR_COMMON: iconUrl, speed, keyboard, tooltips) — used by video & music players |
| `assets/js/shared/upload-progress.js` | Shared upload progress-bar animation (meelUploadProgress) — used by music & video upload pages |
| `assets/js/shared/resume-modal.js` | Shared resume modal (meelResumeModal) + keyboard guard (flag `meelResumeModalActive`) — used by video player-events & music player-core |
| `assets/js/shared/format-time.js` | Shared mm:ss time formatter (formatTime) — moved from music/shared/utils.js, used by music mini-players & resume-modal |
| `assets/js/shared/mini-player-popstate.js` | Shared popstate handler to exit mini-player mode (meelMiniPlayerPopstate) — used by video & music watch mini-players |
| `assets/js/video/watch/main.js` | Entry point folder watch/ — loads siblings synchronously (document.write) |
| `assets/js/video/watch/state.js` | Video player state management |
| `assets/js/video/watch/player-init.js` | Plyr + HLS.js initialization |
| `assets/js/video/watch/player-events.js` | Event orchestration (auto-next, glow, resume) |
| `assets/js/video/watch/mini-player.js` | Mini-player floating mode |
| `assets/js/video/watch/recovery.js` | Player auto-recovery system — uses shared `recovery-manager.js` factories, plus a grace-period self-heal for decode/network errors |
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
| `assets/js/video/watch/upscaler.js` | WebGPU-based AI Upscale (model/mode/scale, glow) — exports `window.MEEL_UPSCALER` + `window.toggleUpscale()` for the `S` shortcut |
| `assets/css/video/resume-modal.css` | Video resume modal — fullscreen z-index + keyboard focus ring on the choice buttons |

### Video — Resume Modal Behavior & Keyboard Guard

The video player shows the **"Lanjutkan Sesi?"** modal when a video has a saved
playback position (`video_pos_<id>` in `localStorage`) greater than 10 seconds
and is not near the end of the duration.

**Trigger & timing window.** `meelResumeModal()` is called from
`player.on("ready")`. For HLS that call is **deferred**: it waits for
`FRAG_BUFFERED` (≥ 15 seconds buffered) or a **8-second timeout**
(`player-events.js`). That leaves a window in which the user can still enter
fullscreen before the modal appears — and `skipToNextVideo()` automatically
calls `player.fullscreen.toggle()` again after a seamless video transition.
Together these are why the modal could appear (or fail to appear) while in
fullscreen.

#### Why the modal was invisible in fullscreen

Plyr **never reuses** `#main-video-wrapper` as its container. Its constructor
always creates a fresh `<div class="plyr">` and inserts it *between*
`#video-glow-container` and `#main-video-wrapper`:

```html
#video-glow-container
└─ div.plyr.plyr--full-ui…          ← Plyr container + fullscreen target
   └─ #main-video-wrapper           ← z-index:2 (layout.css)
      ├─ .plyr__video-wrapper > video, .plyr__poster
      └─ #resume-modal              ← z-index:20
```

The modal declared in `watch.php` as a sibling of `<video>` therefore ends up
**one level below** the real Plyr container. In normal mode
`#main-video-wrapper` is only `position:relative; z-index:2`, so `z-index:20`
still beats `.plyr__poster` (1), the big play button (2), and `.plyr__controls` (3).

In fullscreen, `assets/css/video/fullscreen.css` promotes **three nested
elements** at once to `position:fixed` + `z-index:999999`:
`#video-glow-container`, `.plyr`, and `#main-video-wrapper`. `.plyr__video-wrapper`
then covers the modal — the overlay is painted behind the video area and is not
visible at all. Measured: `z-index:999999` is still covered, `z-index:1000000`
is on top. The fix is `body.meel-fs-active #resume-modal { z-index: 1000001 }`
in `assets/css/video/resume-modal.css`.

> **Note:** `#meel-reconnect-overlay` (`recovery-manager.js`) uses `z-index:60`
> and is also appended to `#main-video-wrapper`, so it has the same fullscreen
> problem. Not fixed yet.

#### Keyboard guard

Plyr registers `keydown keyup` on `window` in the **bubble** phase via
`config.keyboard.global`, and `keyboard.js` / `misc.js` / `mini-player.js`
register their own listeners. Plyr only exempts `Space` when focus sits on a
`<button>`, so `k`, `↑`/`↓`, `←`/`→`, `f`, `m`, `c`, `l`, and `0–9` still take
effect even with a modal button focused.

`assets/js/shared/resume-modal.js` closes that hole with a single `window`
listener in the **capture** phase, registered once at load. Because
`resume-modal.js` is loaded in `video/watch.php` **before** the `video/watch/main.js`
module loader, it is registered ahead of `mini-player.js` (which is also capture
on `window`).

| Class | Handling |
|---|---|
| **Allowed** | `Tab` · `Shift+Tab` · `←` → Lanjut · `→` → Ulang (follows button position) · `Home` `End` (first/last choice) · `Enter` (native `<button>` activation) · `Esc` (still exits fullscreen) · all `Ctrl`/`Meta`/`Alt` combinations |
| **Blocked** | `Space` (re-routed to activate the focused choice) · `↑` `↓` · `k` `j` `l` `m` `f` `c` · `0`–`9` · `i` `n` `a` · and any other key |
| **Second layer** | `window.meelResumeModalActive` is `true` while the modal is visible; used as an early return in `video/watch/misc.js`, `video/watch/mini-player.js`, and `music/watch/misc.js` |

Additional details:

- Focus starts on `#btn-resume` (**Lanjut** — the choice that does not discard
  progress). After the modal closes, focus returns to the player container.
- `player-events.js` passes `onShow: () => player.pause()` so the video does not
  keep playing behind the dialog.
- `#resume-modal` uses `role="dialog"` + `aria-modal="true"` +
  `aria-labelledby` / `aria-describedby`, with a visible `:focus` ring in
  `assets/css/{video,music}/resume-modal.css`.
- **Bug fixed alongside:** `countdownTimer` was never `clearInterval()`-ed when the
  modal was hidden, so the countdown label kept mutating in the DOM for 15
  seconds. All timers now go through a single `close()` function.
- The 15-second auto-restart also routes through `close()`, so the flag and hook
  are cleared too.
- `window.__meelResumeDismiss()` closes the previous instance — used when
  switching tracks on the music page so two timers are never active at once.

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
