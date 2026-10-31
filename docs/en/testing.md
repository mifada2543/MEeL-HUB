# 🧪 Testing Guide

**Last updated:** November 1, 2026

---

## Overview

MEeL uses a multi-layered testing approach:

| Layer | Tool | Scope | Location |
|---|---|---|---|
| **Unit Tests** | PHPUnit 9.6 | Pure logic, mocked DB | `tests/unit/` |
| **Integration Tests** | PHPUnit 9.6 | Real DB operations | `tests/integration/` |
| **Functional Tests** | Custom PHP | Application workflows | `tests/functional_test.php` |
| **Security Tests** | Custom PHP | Vulnerability scanning | `tests/security_test.php` |
| **Deployment Check** | Custom PHP | Environment verification (storage, upload dirs, .htaccess, mod_rewrite) | `tools/check_deploy.php` |
| **CI Pipeline** | GitHub Actions | Automated validation | `.github/workflows/ci.yml` |

> The main distribution ZIP does not include `tests/`. An optional
> `MEeL-HUB-tests-*.zip` release asset contains the suite; extract it over the
> core/full package, then run `composer install` before using PHPUnit.

---

## 🧪 PHPUnit Test Suite

### Installation

PHPUnit is installed as a Composer dev dependency:

```bash
cd /path/to/MEeL
composer install --no-progress
```

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit --no-coverage

# Run only unit tests
vendor/bin/phpunit --no-coverage --testsuite='MEeL Core Unit Tests'

# Run only integration tests
vendor/bin/phpunit --no-coverage --testsuite='MEeL Integration Tests'

# Run specific test file
vendor/bin/phpunit --no-coverage tests/unit/RateLimiterTest.php
```

### Test Logs

All test output is logged to `logs/tests/`:

```
logs/tests/
└── .htaccess          # Deny all — protected from public access
```

### Test Architecture

#### Unit Tests (`tests/unit/`)

| File | Tests | Coverage |
|---|---|---|
| `RateLimiterTest.php` | 11 | Admin bypass, role limits, blocking, cleanup, stats, fail-closed on storage failure, independent keys |
| `HelpersTest.php` | 50 | format_bytes, time_ago, audio MIME types, disk space, CSRF, dir_size, protocol detection (data providers) |
| `JapaneseTest.php` | 15 | Romaji conversion, analyzeJapaneseText, English translation (MeCab-optional) |
| `GarbageCollectorTest.php` | 7 | Class existence, idempotency, graceful handling, cleanup rate-limit (dir test terisolasi), active-cache dir preservation |
| `CacheDirectoryCleanupTest.php` | 17 | Cache sweep TTLs per prefix and per time key (`ts`/`time`/`checked_at`), temporary files, unreadable cache, mtime fallback, shared cache directory |
| `SearchEngineTest.php` | 5 | Parse params, sanitizer (`sanitizeQuery`), default values, constants |
| `MediaLibraryTest.php` | 11 | Pagination logic (pure math), BookRepository mock |
| `MediaInteractionTest.php` | 7 | Input validation (invalid IDs, types) |
| `MediaViewerTest.php` | 9 | Media recommendations / playlist queue / next-url logic |
| `RouterProfileRouteTest.php` | 9 | Profile route resolution, reserved routes, nested/trailing-slash cases |
| `RouterDispatchTest.php` | 22 | Profile-alias redirects (pure, no request needed), and every registered route points at a file that exists |
| `BootstrapTest.php` | 9 | Env detection, error reporting config, timezone |
| `CssManifestTest.php` | 18 | CSS module manifests — every entry exists, **all** folders pre-cached by `SwPrecache`, precache entries resolve, deterministic SW version |
| `SharedJsTest.php` | 7 | Shared JS harness — download-backup-codes flow |
| `LikeDislikePartialTest.php` | 7 | **Like/dislike buttons** — the container markup lives in exactly one file, every caller uses the shared partial, and the music and video variants differ only in the accent colour, the label and the save button |
| `LucideLoadOrderTest.php` | 6 | **Lucide + HTMX** — the render helper is loaded, the queue stub in `partials/link.php` is synchronous, and the swap listener is registered even when `document.body` does not exist yet (script loaded in `<head>`) |
| `StreamAuthTest.php` | 8 | Stream endpoint authorization guards |
| `SsrfGuardTest.php` | 76 | **SSRF guard** — protocol allowlist, private/public IP ranges (v4 & v6), DNS mixed-record rejection, hostname denylist, HTTP pinning (see below) |
| `DriveSecurityTest.php` | 13 | **Private Drive** — cross-user access, path traversal, symlink escape, realpath boundary, quota, atomic filename reservation (see below) |
| `DriveStorageBasePathTest.php` | 8 | Drive storage base-path resolution — `MEEL_HDD_DRIVE` constant, fallback, priority |
| `DateTimeSourceTest.php` | 7 | **The application clock** — the timezone is set unconditionally, MySQL's session `time_zone` is aligned with it, columns read by `strtotime()` are never written with the database clock, and the files that deliberately mix the two are an explicit inventory |
| `WebSafeErrorOutputTest.php` | 2 | **Web-safe error output** — `STDERR`/`STDOUT`/`STDIN` only in CLI tooling, CLI-guarded files, or files nothing includes |
| `AdminFilteredRepositoryTest.php` | 13 | Shared admin repository base — the shared queries stay in the base, each repository keeps its own table and alias, the two filter behaviours stay different |
| `ValidatingProxyTest.php` | 27 | **Validating forward proxy** — real CONNECT/GET probes: private targets refused (502), public targets tunneled, loopback-only bind, malformed absolute-URI refused (see below) |
| `ValidatingProxyProtocolTest.php` | 27 | **Proxy protocol rules** — request-head parsing and its limits, CONNECT target parsing, hop-by-hop header stripping, forwarded-request assembly, tunnel chunk and response relay |
| `ArchiveGuardTest.php` | 11 | **Archive guard** — `../` traversal, absolute paths, null bytes, symlinks, nesting, compression ratio, ZIP bomb, entry count (see below) |
| `UploadValidationTest.php` | 9 | **Upload validation** — fake magic bytes, double extensions, null bytes, oversized, executable (see below) |
| `TranscoderConstantsTest.php` | 2 | Regression guard for shared-constant visibility (`TranscoderBase` → child services) |

#### Integration Tests (`tests/integration/`)

| File | Tests | Coverage |
|---|---|---|
| `MediaInteractionIntegrationTest.php` | 24 | Like/dislike music+video, toggle, ownership check, count sync, guest denial |
| `ChessGameOverIntegrationTest.php` | 13 | Chess game-over flow against real DB |
| `ChessHelpersIntegrationTest.php` | 6 | Chess helper functions with real DB |
| `ChessRematchIntegrationTest.php` | 21 | Chess rematch flow against real DB |
| `GarbageCollectorChessRoomsIntegrationTest.php` | 15 | Chess room garbage collection with real DB |
| `MeelCoinIntegrationTest.php` | 18 | Spend/refund/refill, role caps, countdown that never goes backwards, refill window that starts at the spend |
| `SystemTest.php` | 2 | System class existence & utilities |

### Test Helpers

| File | Purpose |
|---|---|
| `tests/DbTestHelper.php` | Real DB connection with transaction rollback isolation. Builds the throw-away `MEeL-test` database from **both** `database/schema.sql` and `arcade/schema.sql`, and exposes that connection as `$GLOBALS['conn']` so `Modules::enabled()` resolves against the test database instead of production. |
| `tests/bootstrap.php` | Autoloader, `$_SERVER` defaults, temp directory setup |

### PHPUnit Configuration (`phpunit.xml`)

```xml
<phpunit bootstrap="tests/bootstrap.php" colors="true" verbose="true">
    <testsuites>
        <testsuite name="MEeL Core Unit Tests">
            <directory>tests/unit</directory>
        </testsuite>
        <testsuite name="MEeL Integration Tests">
            <directory>tests/integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

### Security Regression Tests (SSRF Guard, Validating Proxy, Private Drive)

Three dedicated test classes cover the security boundaries implemented in the
security-hardening pass. Run them together or individually:

```bash
# All security tests (SSRF + Drive + proxy) at once
vendor/bin/phpunit --no-coverage --filter 'SsrfGuardTest|DriveSecurityTest|ValidatingProxyTest'

# Or per file:
vendor/bin/phpunit --no-coverage tests/unit/SsrfGuardTest.php
vendor/bin/phpunit --no-coverage tests/unit/DriveSecurityTest.php
vendor/bin/phpunit --no-coverage tests/unit/ValidatingProxyTest.php
```

| Test file | What it verifies (real rejection/acceptance, not just existence) |
|---|---|
| `SsrfGuardTest.php` | Private IPv4/IPv6 ranges rejected, public IPs allowed, hostname denylist, unsupported protocols, malformed URLs, credentials rejection, hostname→private-IP DNS rejection, HTTP pinning (`pinHttpUrl`) |
| `DriveSecurityTest.php` | Cross-user download blocked, path traversal blocked, symlink escape blocked, realpath prefix boundary, quota enforcement (atomic), atomic filename reservation |
| `ArchiveGuardTest.php` | `../` / `../../` / absolute / `..\..\` / null-byte entries rejected, symlinks rejected, excessive nesting rejected, extreme compression ratio (zip bomb) rejected, entry count & total size bounded, valid CBZ accepted, corrupt archive rejected |
| `UploadValidationTest.php` | Fake MIME (`$_FILES['type']` untrusted), double extensions, null bytes, oversized files, executable extensions rejected, magic-byte mismatch rejected |
| `ValidatingProxyTest.php` | **Spawns a real proxy process** and sends real CONNECT/absolute-URI requests: private targets refused with 502, public targets tunneled/relayed, loopback-only bind, process lifecycle |

### One-command verification (`scripts/verify_security.sh`)

All three security suites plus the Private Drive 403 probe can be run with a
single command:

```bash
scripts/verify_security.sh
# optional flags: --url=https://staging.example/MEeL --deploy --hdd=... --no-color
```

The script runs, in order:

1. **PHPUnit security subset** — `SsrfGuardTest | DriveSecurityTest | ValidatingProxyTest`
2. **Security Test** — `php tests/security_test.php` (static scan)
3. **Functional Test** — `php tests/functional_test.php` (patch verification)
4. **Private Drive 403 probe** — direct HTTP access to `data_drive/private_admins/`
   and a throwaway file path must both return `403` (the `.htaccess` deny rule)
5. *Optional* (`--deploy`) — `php tools/check_deploy.php --no-color [--hdd=...]`

Exit code `0` = all suites passed (warnings allowed), `1` = at least one
failure — CI-friendly. Missing tools (`vendor/bin/phpunit`) or an unreachable
web server produce warnings and are **skipped, not failed**. `security_test.php`
and `functional_test.php` return `1` when there are only warnings (Score A —
e.g. HDD storage not mounted); the script maps that to **WARN**, not FAIL,
matching their own semantics (they return `2` only for real failures). Flags:
`--url=…` (probe base URL), `--skip-403`, `--deploy`, `--hdd=…`,
`--no-color`. Requires `bash`, `php` CLI and `curl`.

> 💡 The same components run separately in CI (see [CI Pipeline](#-ci-pipeline-github-actions));
> this script is the local / pre-release one-shot equivalent.

**Notes:**
- `SsrfGuardTest` and `ValidatingProxyTest` contain **network-dependent cases**
  (real DNS lookups). In an offline environment they degrade to
  `markTestSkipped()`; in CI (GitHub Actions) and on a machine with a resolver
  they run for real.
- `ValidatingProxyTest` requires **PHP CLI + pcntl + stream_socket_server**
  (all present on the default XAMPP/LAMP setup) because it spawns
  `validating_proxy_server.php` as a subprocess.
- The static wiring checks for the same boundaries live in
  `tests/security_test.php` (TEST 13) and `tests/functional_test.php` (patch
  verification), run via:

```bash
php tests/security_test.php
php tests/functional_test.php
```

### Writing New Tests

#### Unit Test Template

```php
<?php
use PHPUnit\Framework\TestCase;

class MyClassTest extends TestCase
{
    public function testSomething(): void
    {
        $result = myFunction('input');
        $this->assertSame('expected', $result);
    }
}
```

#### Integration Test Template (with DB)

```php
<?php
use PHPUnit\Framework\TestCase;

class MyIntegrationTest extends TestCase
{
    private DbTestHelper $dbHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbHelper = new DbTestHelper();
        $this->conn = $this->dbHelper->getConnection(); // auto-start transaction
    }

    protected function tearDown(): void
    {
        $this->dbHelper->rollback(); // undo all changes
        $this->dbHelper->close();
        parent::tearDown();
    }
}
```

---

## Functional Tests (`tests/functional_test.php`)

Custom test script that validates application workflows:

```bash
php tests/functional_test.php
```

**Covers:**
- User authentication (login, register, logout)
- Media playback (video, music)
- File operations (upload, delete)
- RBAC (role-based access)
- Rate limiting
- System health checks

## 🛡️ Security Tests (`tests/security_test.php`)

Automated vulnerability scanning:

```bash
php tests/security_test.php
```

**Covers:**
- SQL Injection — all prepared statements verified
- XSS — output encoding checks
- CSRF — token validation
- Path Traversal — basename() enforcement
- Command Injection — escapeshellarg() usage
- File Upload — magic bytes validation
- Session Security — cookie params, hijack detection
- Open Redirect — all referer redirects must pass host validation; `playlist_action.php` rejects `//host` & `://` schemes
- Fatal-Bug Regression — duplicate class includes (RateLimiter) & raw `HTTP_REFERER` redirects are detected

<a id="deployment-check-check_deployphp"></a>
## 🚀 Deployment Check (`tools/check_deploy.php`)

Quick health-check of the deployment environment — mirrors what the security
test checks, but focused on infrastructure:

```bash
php tools/check_deploy.php                          # local check + auto HTTP probe
php tools/check_deploy.php --url=http://localhost/MEeL   # explicit HTTP probe
php tools/check_deploy.php --hdd=/tmp/meel-storage/media  # override MEEL_HDD_BASE (testing/CI)
php tools/check_deploy.php --no-color               # no ANSI colors (for CI/logs)
```

**Verifies:**
- `MEEL_HDD_BASE` — defined, not a placeholder, storage mounted & writable
- Upload dirs + **non-auto-created subdirectories** — `{video,music,books}/upload`
  resolve to storage; `music/upload/{file,thumbnail}` & `books/upload/{pdf,thumbnail}`
  must exist (the music/books modules do **not** create them) — missing ones are a **FAIL**
- `.htaccess` hardening in upload dirs — `php_flag engine off`, `ForceType`, `Options -Indexes`
- `data_drive/` portability guard — deploy-time symlinks inside `MEEL_HDD_DRIVE` =
  **PASS**, pointing outside = **WARN**
- PWA `mod_rewrite` — root `.htaccess` rule + real HTTP probe of `/sw.js`

Exit code `0` = healthy, `1` = at least one FAIL (CI-friendly). Full storage
guide: [Installation §5a](installation.md).

---

## 🤖 CI Pipeline (GitHub Actions)

The CI pipeline runs automatically on push/pull request:

```
PHP Syntax (8.0–8.3)
    ├── Functional Tests          → php tests/functional_test.php
    ├── Security Tests            → php tests/security_test.php
    ├── PHPUnit Unit Tests        → php vendor/bin/phpunit --no-coverage --testsuite='MEeL Core Unit Tests'
    ├── PHPUnit Integration Tests → php vendor/bin/phpunit --no-coverage --testsuite='MEeL Integration Tests' (MySQL service)
    ├── Installer Regression Tests → bash tests/installer/run_all.sh (MariaDB service)
    ├── Migration Test            → schema.sql import + idempotent migrate.php + schema parity
    ├── HTACCESS & Integrity      → .htaccess presence + permissions
    ├── ShellCheck                → shellcheck every *.sh
    ├── Deployment Check         → php tools/check_deploy.php --no-color --hdd=…
    ├── Tailwind Reproducibility  → npm run css:check
    └── Drive Storage Integrity   → storage path verification
            └── CI Summary
```

### Build artifacts: snapshots vs releases

CI only validates code. ZIP packages are **not** built on every commit/push,
to avoid wasting runner time and artifact storage.

| Path | Trigger | Result |
|---|---|---|
| **Snapshot** (`.github/workflows/snapshot.yml`) | Push to `main`/`master`/`develop`, a PR merged into another branch (except `Experiment`), or manual | 3 packages labelled with the commit SHA, 7-day artifact, **no** GitHub Release |
| **Release** (`.github/workflows/release.yml`) | `core-vX.Y.Z` / `arcade-vX.Y.Z` tag, or `workflow_dispatch` | Final packages + `SHA256SUMS`, published as a GitHub Release |

A snapshot can also be started from Actions → **MEeL Snapshot** → **Run
workflow**, for example to package a feature branch or a specific commit.
Snapshots never create tags or overwrite a Release. A snapshot only packages
the source (no test gate); releases still pass syntax, unit, integration,
security, functional, installer, and Tailwind checks.

#### Package contents

| Package | Contents | Requires Core? | Asset name |
|---|---|---|---|
| **Core** | Everything except `arcade/` | No, standalone | `MEeL-HUB-core-v2.1.0.zip` |
| **Arcade addon** | `arcade/` only | Yes — copy into the Core root and run the Arcade migration | `MEeL-Arcade-v2.1.0.zip` |
| **Full** | Core + `arcade/` | No, already complete | `MEeL-HUB-full-v2.1.0.zip` |

A `core-v2.1.0` tag builds **all three** packages in one Release.
An `arcade-v2.1.0` tag builds only the Arcade addon (for Arcade-only fixes).

Installing the Arcade addon on top of Core:

```bash
unzip MEeL-Arcade-v2.1.0.zip -d /path/to/MEeL
cd /path/to/MEeL && php arcade/migrate.php
```

Build locally without a tag (for testing):

```bash
bash scripts/ci/build-package.sh --track core --label local --out-dir dist
```

The script archives a commit (`HEAD`), not the working tree — commit first or
use `--ref <tag>` to package a specific commit.

#### Creating the v2.1.0 release

Make sure the commit to release is on the main branch, the working tree is
clean, and this workflow is already part of that commit (a tag points to a
commit, not to uncommitted changes):

```bash
git tag -a core-v2.1.0 -m "MEeL Core v2.1.0"
git push origin core-v2.1.0
```

For an Arcade-only release:

```bash
git tag -a arcade-v2.1.0 -m "MEeL Arcade v2.1.0"
git push origin arcade-v2.1.0
```

Re-release/backfill a tag that already contains this workflow (the workflow
runs from the default branch, then checks out the tagged commit):

```bash
gh workflow run release.yml -f tag=core-v2.1.0
gh workflow run release.yml -f tag=core-v2.1.0 -f dry_run=true
```

`dry_run=true` only builds and uploads the artifact; it does not create or
modify a GitHub Release. After publishing, download the assets from the
**Releases** page or verify the checksums:

```bash
sha256sum -c SHA256SUMS
# or verify a single asset only:
grep 'MEeL-HUB-core-v2.1.0.zip' SHA256SUMS | sha256sum -c -
unzip -l MEeL-HUB-core-v2.1.0.zip | head
```

> ⚠️ The tag must point to a commit that already contains this workflow,
> including `scripts/ci/php-lint.sh` and `scripts/ci/build-package.sh`. For
> older tags that do not, create a new tag from a commit that contains this
> workflow; do not move an already-published tag.

### How the security regression tests run in CI

All three security test classes (`SsrfGuardTest`, `DriveSecurityTest`,
`ValidatingProxyTest`) live in `tests/unit/`, which is picked up by the
`phpunit-tests` job (`php vendor/bin/phpunit --no-coverage
--testsuite='MEeL Core Unit Tests'`). No extra CI configuration is needed —
they are part of the **MEeL Core Unit Tests** testsuite. The DNS-dependent
cases run for real because GitHub Actions runners have a resolver;
`ValidatingProxyTest` passes because the runner has PHP CLI with
pcntl/stream sockets.

> **CI runs both unit and integration suites.** The `phpunit-tests` job runs the
> unit suite, while `phpunit-integration-tests` runs integration tests against a
> real MySQL 8.0 service container. The Japanese/romaji tests (`JapaneseTest`,
> parts of `HelpersTest`) call **MeCab** via `proc_open`. CI installs it
> (`apt-get install mecab mecab-ipadic-utf8`), and when MeCab is unavailable the
> affected tests degrade to `markTestSkipped()` via `meel_mecab_available()` — so
> a machine without mecab still gets a green suite, not failures.

The **static wiring checks** for the same boundaries are exercised by the
`security-tests` job (TEST 13 in `tests/security_test.php`) and the
`functional-tests` job (patch verification). The `htaccess-check` job verifies
`.htaccess` presence; the repo-level deny rule for `data_drive/private_admins`
is asserted by the security test's `data_drive/.htaccess` check (layer 1),
plus the tracked `data_drive/private_admins/.htaccess` (`Require all denied`,
layer 2) which is checked by both `tests/security_test.php` (TEST 13) and
`tools/check_deploy.php`.

The `deploy-check` job in `.github/workflows/ci.yml` runs this suite
(`php tools/check_deploy.php --no-color --hdd=...`) on a simulated storage
layout, and includes an optional live-403 probe that runs only when a staging
URL is configured as a GitHub secret:

```yaml
# .github/workflows/ci.yml — job deploy-check
# Note: the `secrets` context is NOT available in a step-level `if:`. The secret
# is exposed via job-level `env`, then read as an env context inside `if:`.
    env:
      STAGING_URL: ${{ secrets.STAGING_URL }}
    steps:
      - name: Verify Private Drive 403 (staging)
        if: env.STAGING_URL != ''
        run: |
          code=$(curl -s -o /dev/null -w '%{http_code}' \
            "$STAGING_URL/data_drive/private_admins/" || true)
          [ "$code" = "403" ] || { echo "Expected 403, got $code"; exit 1; }
```

### Tailwind CSS reproducibility gate

`scripts/ci/tailwind-coverage-check.php` guards `assets/css/tailwind.min.css`
— a committed build artifact — so that it can be reproduced. Without this
gate, changing markup and then wanting to rebuild the CSS amounts to
guessing.

Three things are checked:

1. **The `content` glob really scans the repo, and does not scan what is
   excluded.** The config can contain a typo and still build "successfully"
   with half-empty CSS — no error, only missing styling.
2. **No regression.** A class that IS in the old CSS, IS still used by markup,
   but is GONE from the new CSS = a regression. This is the most damaging
   kind: the page renders, the layout breaks, and there is no error.
3. **A class that disappeared and is no longer used may disappear.** That is
   a saving, not a bug; a build that deliberately prunes stale tokens is the
   intended result.

Two implementation details that are not style choices:

- **Tokenisation runs on the ORIGINAL selector** (which still carries CSS
  escapes), and only then is the result unescaped. If the selector were
  unescaped first and then tokenised, the `:` character (the variant
  separator) would become a boundary too and every variant-marked class
  would collapse into a single token: `hover:border-green-500/30` becomes
  `hover`. The regression check would then be nearly useless — `hover` is
  obviously present in other files.
- **Declarations are collected per class in COMBINED form**, not per rule.
  Tailwind merges selectors with identical declarations into one rule; when
  a group's contents change, one class can move from a combined rule to its
  own rule (or the other way round) without changing the final result.

Only the last compound of a selector counts as the declaration carrier. In
`.group:hover .group-hover:text-white`, the declaration comes from
`group-hover:text-white`; `group` is only an ancestor and has no declaration
of its own.

```bash
php scripts/ci/tailwind-coverage-check.php                  # audit the committed artifact
php scripts/ci/tailwind-coverage-check.php --new=/tmp/x.css  # compare a candidate
php scripts/ci/tailwind-coverage-check.php --rebuild         # build to a temp file first, then compare
```

Exit code 0 = safe. Non-zero = a regression or a broken config.

### Verifying Private Drive 403 manually (deploy-time)

After deploying (or before releasing a change), confirm the web server really
rejects direct access to private Drive storage — a `.htaccess` file alone is
not proof. Use a throwaway probe file, then remove it:

```bash
cd /path/to/MEeL

# 1. Create probe file in private Drive storage.
#    Storage location follows MEEL_HDD_DRIVE (auth/settings.php); falls back to
#    the tracked real folder data_drive/private_admins if the constant is not set
#    (Drive module no longer uses symlinks for storage — see installation.md).
BASE=$(php -r 'require "modules/core/helpers.php"; echo meel_drive_base_path();')
TARGET="$BASE/private_admins"
mkdir -p "$TARGET/zz_403_probe/video"
echo 'PROBE' > "$TARGET/zz_403_probe/video/probe.mp4"

# 2. Direct access → MUST be 403 (not 200/404 from web server)
curl -s -o /dev/null -w 'file: %{http_code}\n' \
  'http://localhost/MEeL/data_drive/private_admins/zz_403_probe/video/probe.mp4'

# 3. Directory listing → MUST be 403
curl -s -o /dev/null -w 'dir:  %{http_code}\n' \
  'http://localhost/MEeL/data_drive/private_admins/zz_403_probe/'

# 4. Clean up probe
rm -rf "$TARGET/zz_403_probe"
```

**Expected output:** both `file: 403` and `dir: 403`. Anything else (200, 301,
404 served by the storage) means `AllowOverride` / `mod_rewrite` is not active
for `data_drive/` — fix `httpd.conf` (`AllowOverride All`) before release.

> 💡 Tip: this exact check is also part of `tools/check_deploy.php`
> (`.htaccess` deny rule) — run `php tools/check_deploy.php --url=http://localhost/MEeL`
> for the full deployment health report.

---

> Run `php vendor/bin/phpunit --list-tests` for current counts. Exact numbers intentionally not duplicated here as they change with each test addition.

