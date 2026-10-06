# 👨‍💻 Panduan Development & Kontribusi

Panduan untuk pengembang yang ingin berkontribusi atau memahami standar koding di MEeL-HUB.

---

## Daftar Isi

- [Lingkungan Development](#lingkungan-development)
- [Standar Koding](#standar-koding)
- [Struktur Database](#struktur-database)
- [Coding Conventions](#coding-conventions)
- [Catatan `.htaccess` & Route Legacy](#catatan-htaccess--route-legacy)
- [Keputusan Teknis & Konfirmasi Perbaikan](#keputusan-teknis--konfirmasi-perbaikan-2026-10-03)
- [Testing](#testing)
- [Pull Request Guide](#pull-request-guide)
- [Troubleshooting Development](#troubleshooting-development)

---

## Lingkungan Development

### Setup Development

1. **Install dependencies:**
```bash
# Clone repo
git clone https://github.com/mifada2543/MEeL.git
cd MEeL

# Copy config
cp auth/settings.example.php auth/settings.php
cp auth/config.example.php auth/config.php

# Setup database (lihat installation.md)
```

2. **Aktifkan debug mode:**
```php
// Di awal file PHP yang sedang dikerjakan
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

3. **Nonaktifkan HDD check untuk development:**
```php
// modules/core/helpers.php - comment out baris berikut
// if (!is_dir(MEEL_HDD_BASE)) { ... }
```

4. **Path konfigurasi terpusat:**
   Semua path penyimpanan dikelola dari **satu tempat** (`auth/settings.php`):
   ```php
   define('MEEL_HDD_BASE', '/media/username/MEeL/media');
   ```
   Tidak perlu lagi mencari-cari path di banyak file. (`auth/config.php` hanya entry point — tidak berisi konstanta storage.)

4. **Tools yang disarankan:**
- Editor: VS Code dengan PHP Intelephense
- Database: MySQL Workbench / phpMyAdmin
- API Testing: Postman / Insomnia
- Browser: Chrome DevTools untuk debugging HTMX

---

## Standar Koding

### PHP

#### 1. PSR-12 Basic Coding Style

```php
<?php
// Gunakan PHP tags dengan benar
declare(strict_types=1);

namespace MEeL\Modules;

class MediaLibrary
{
    private mysqli $conn;
    
    public function __construct(mysqli $connection)
    {
        $this->conn = $connection;
    }
    
    public function getVideos(int $limit = 15, int $offset = 0): mysqli_result
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM video ORDER BY upload_date DESC LIMIT ? OFFSET ?"
        );
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        return $stmt->get_result();
    }
}
```

#### 2. Prepared Statements WAJIB

```php
// ✅ BENAR - Prepared Statement
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

// ❌ SALAH - Jangan gunakan query() dengan concatenation
// $result = $conn->query("SELECT * FROM users WHERE username = '$username'");
```

#### 3. Parameter Binding Types

| Type | PHP Type | SQL Type |
|---|---|---|
| `i` | int | INTEGER |
| `d` | float | DOUBLE/FLOAT |
| `s` | string | VARCHAR/TEXT |
| `b` | blob | BLOB/BINARY |

#### 4. Error Handling

```php
try {
    $stmt = $conn->prepare("INSERT INTO video (...) VALUES (...)");
    if (!$stmt) {
        throw new Exception($conn->error);
    }
    $stmt->bind_param("sss", $title, $filename, $description);
    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }
} catch (Exception $e) {
    error_log("[MEeL] ERROR: " . $e->getMessage());
    return ['status' => 'error', 'msg' => $e->getMessage()];
}
```

#### 5. Class Naming Convention

```php
// Class: PascalCase
class MediaLibrary {}
class BookRepository {}
class DriveUserContext {}
class DriveViewRenderer {}

// Methods: camelCase
public function getVideos();
public function toggleLike();
public function processDownload();

// Properties: camelCase with $ prefix
private $user_id;
private $base_path;
private $conn;
```

#### 6. Constants

```php
// Class constants: UPPER_SNAKE_CASE
private const FFMPEG_THREADS = 8;
private const HLS_SEGMENT_DURATION = 10;
private const DOWNLOAD_TIMEOUT = 900;

// Global constants: MEEL_HDD_* untuk path terpusat (di auth/settings.php)
define('MEEL_HDD_BASE', '/media/username/MEeL/media');
define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
```

#### 7. Type Hints

Properti class dan parameter constructor **wajib** memiliki type hints (PHP 8.0+):

```php
// ✅ BENAR - Type hints
private \mysqli $conn;
private int $user_id;
private string $username;

public function __construct(\mysqli $db_connection, int $session_user_id, string $session_username) { ... }

// ❌ SALAH - Tanpa type hint
// private $conn;
// public function __construct($db_connection, $session_user_id) { ... }
```

### JavaScript

#### 1. Event Handlers

```javascript
// ✅ BENAR - Named functions
function handleSearch(event) {
    const query = event.target.value;
    // ...logic
}

// ❌ SALAH - Inline anonymous functions in HTML
// onclick="doSomething()"
```

```javascript
// ✅ BENAR - Event listeners
document.getElementById('search-input').addEventListener('input', handleSearch);
```

#### 2. HTMX Integration

```javascript
// Monitor HTMX events
document.body.addEventListener('htmx:afterOnLoad', function(evt) {
    // Re-initialize Lucide icons after HTMX swap
    lucide.createIcons();
    
    // Re-attach event listeners
    setupMusicItemClicks();
});
```

#### 3. Variables & Functions

```javascript
// Variables: camelCase
let isMiniPlayerActive = false;
const miniPlayerIndex = document.getElementById('mini-player-index');

// Functions: camelCase
function updateIndexUI() {}
function toggleMiniLoopIndex() {}

// Global functions for HTML onclick: window scoped
window.miniPlayPauseIndex = function() {};
```

### CSS

Proyek menggunakan **TailwindCSS (self-hosted, purged)** untuk styling utama, dengan CSS kustom minimal untuk efek khusus.

```css
/* CSS kustom hanya untuk efek yang tidak bisa dicapai dengan Tailwind */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image: url("data:image/svg+xml,...");
    pointer-events: none;
    z-index: 0;
}

/* Animasi kustom */
@keyframes meel-fade-in {
    from { opacity: 0; backdrop-filter: blur(0px); }
    to { opacity: 1; backdrop-filter: blur(8px); }
}
```

### Database

#### Naming Convention

```sql
-- Tables: lowercase, plural
CREATE TABLE video (...);
CREATE TABLE music (...);
CREATE TABLE playlists (...);
CREATE TABLE playlist_tracks (...);

-- Columns: snake_case
id, user_id, created_at, is_active, path_folder

-- Foreign keys: descriptive
CONSTRAINT fk_parent_comment FOREIGN KEY (parent_id) REFERENCES comments (id)
```

#### Migration Pattern

Karena MEeL belum menggunakan migration framework, ikuti pattern ini:

```sql
-- File: migrations/001_add_description_column.sql
ALTER TABLE video ADD COLUMN description text DEFAULT NULL;
ALTER TABLE music ADD COLUMN description text DEFAULT NULL;

-- Update di update.php
-- Tambahkan entry di tabel updates
```

---

## Struktur Database

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

### Gaya Komentar

- **Bebas untuk *why*** — alasan desain, trade-off, jebakan yang pernah terjadi
  (mis. "tanpa reset ini loop render berhenti senyap"), dan kontrak yang tidak
  terbaca dari kode.
- **Dilarang mengulang kode** — komentar yang hanya menceritakan apa yang baris
  di bawahnya lakukan tidak menambah informasi dan cepat basi.
- **Detail teknis panjang → dokumentasi** — kontrak API, alur pipeline, dan
  catatan perilaku yang butuh konteks panjang ditulis di file MD; file kode
  cukup menunjuk ke sana.
- **Tanpa banner seksi** — jangan pakai blok pemisah `/* ==== 1. Nama ==== */`;
  pemisah bab di file JS cukup baris kosong.

#### Kapan komentar dihapus

Berlaku untuk kode first-party. **Dikecualikan:** file vendor/pihak ketiga
(`assets/js/compatibilitas/`, `*.min.js`, `plyr.css`, `tailwind.min.css`,
`font.css`, dll.), marker `reference build:` hasil `marks.php`, dan file
konfigurasi (`.htaccess`, `install.sh`, `.github/workflows`).

**Dihapus:**

1. Banner & pemisah — `/* ==== */`, `/* ---- */`, `────`, `════`, `// 1. Nama`;
   blok cukup dipisah baris kosong.
2. Komentar yang mengulang kode (apa yang baris di bawahnya lakukan).
3. Komentar kosong dan duplikat berurutan (`//`, `/** */`).
4. Kode mati yang dikomentari — hapus kodenya, jangan disimpan sebagai komentar.
5. Docblock yang hanya mengulang nama fungsi/konstanta
   (`/** Tolak (nonaktif)? */` di atas `return ...`).
6. `@param`/`@return` yang hanya menduplikasi tipe dari signature; **disisakan**
   bila menambah kontrak (nilai balik khusus, format array, enum status).
7. Komentar langkah-demi-langkah di test (`// buat data`) — sisakan yang
   menjelaskan *mengapa* skenario itu dipilih.
8. Header naratif panjang → pindahkan ke `docs/id/development.md`
   (`docs/en/development.md`), file cukup pointer 1–2 baris.

**Dipertahankan:**

- Komentar *why*: trade-off, invarian keamanan/race, perilaku browser/GPU/DB
  yang tidak terbaca dari kode.
- PHPDoc yang menjelaskan kontrak nyata, bukan tipe ulangan.
- Marker `reference build:`, lisensi, dan attribution vendor.

### Keamanan

1. **Selalu Prepared Statement** — Tidak ada SQL concat
2. **Selalu htmlspecialchars()** — Untuk output
3. **CSRF Token** — Setiap form POST wajib
4. **Role Check** — Sebelum aksi sensitif
5. **Input Validation** — Tipe, ukuran, ekstensi file
6. **Tanpa `@` (error suppression) pada operasi filesystem** — gunakan guard proaktif
   `is_file()`/`is_dir()`/`is_readable()`/`is_writable()`, cek nilai balik, dan pakai
   helper bersama (trait `FfmpegUtils`, `GarbageCollector::removeFile()`/`removeDirectory()`,
   `meel_write_cache_file()`). Lihat [Konvensi Keamanan Filesystem](modules.md#konvensi-keamanan-filesystem-tanpa-).
7. **Session Boot Terpusat** — Setiap entry point wajib memanggil `meel_boot_session()`
   (dari `modules/auth/helpers/session.php`) — jangan `session_name()` + `session_start()`
   manual. Fungsi ini yang menjamin cookie sesi memakai flag `HttpOnly`/`SameSite=Lax`/
   `Secure` (auto-detect HTTPS) dan timeout 12 jam secara konsisten.

### File Structure per Modul

Setiap modul (video, music, books, drive) mengikuti pola. Halaman diakses via
**URL bersih** (front controller `router.php` → `modules/core/Router.php`),
contoh `video/beranda` → `video/index.php`, `music/watch?v=X` → `music/watch.php`:

```
[module]/
├── index.php          # Katalog / daftar (URL: [module]/beranda)
├── watch.php          # Player / detail (URL: [module]/watch?v=X)
├── upload.php         # Form upload (URL: [module]/upload)
├── search_[module].php  # Pencarian (HTMX) (URL: [module]/search)
├── load_more.php      # Infinite scroll — batch berikutnya + rantai sentinel (hx-trigger=revealed) (URL: [module]/load-more)
└── [module]_item.php  # Komponen kartu
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

### Pola Infinite Scroll (Sentinel)

Dipakai library video & music, hasil search, sidebar rekomendasi watch, dan grid channel profil — menggantikan tombol "Muat Lebih Banyak" lama:

```html
<!-- Sentinel: elemen yang sama menjadi requester sekaligus target -->
<div id="load-more-area" role="status"
    hx-get="load-more?offset=15&page=1"
    hx-target="#load-more-area"
    hx-swap="outerHTML"
    hx-trigger="revealed">          <!-- htmx memicu saat masuk viewport -->
    <div class="animate-spin …"></div>
    <span>Memuat...</span>
</div>
```

**Aturan:**
- **Semantik rantai:** setiap respons berisi kartu berikutnya **plus sentinel
  pengganti**. Elemen hasil swap baru mulai tanpa `data-hx-revealed`, sehingga
  rantai terus terpicu selama sentinel terlihat di viewport.
- **Terminal:** saat `offset + limit ≥ total` (atau `hasMore` false) server
  mengirim end box **"Out Of Content · Konten sudah tidak ada lagi"** — respons
  kosong juga menghasilkan end box yang sama.
- **Retry saat gagal:** `assets/js/shared/sentinel-retry.js` menghapus
  `data-hx-revealed` dari sentinel yang request-nya gagal, sehingga scroll
  *berikutnya* memicunya lagi (retry by user scroll — tanpa loop otomatis).
- **Id itu penta:** pertahankan id sentinel (`#load-more-area`, `#load-more-music`,
  `#load-more-music-search`, `#channel-more-area`) — CSS `overflow-anchor: none`
  dan guard `isFromLoadMore` di `assets/js/music/index/index.js` bergantung padanya.
  Hasil search memakai id sentinel terpisah agar pembersihan pasca-search tidak mematikannya.
- **Gotcha:** pada swap `outerHTML`, `htmx:afterSwap` menyala **sekali per elemen
  baru** — kartu tidak punya `id` sehingga `targetId === ""`. Perlakukan fragment
  swap sebagai content update (lihat `isFragmentSwap` di
  `assets/js/music/index/index.js`), kalau tidak logika view-boot
  (`bootPlayerIndex()` termasuk scroll-to-active) jalan per kartu dan menarik
  halaman saat scroll.
- **Jangan** memasangkan `revealed` dengan smooth-scroll programatik — loop
  `scrollTo` akan terus menarik halaman tanpa henti (alasan `load-more.js` lama dihapus).

### CSS File Organization

```css
/* assets/css/[module].css */

/* 1. CSS Variables */
:root {
    --bg-main: #0b0f1a;
    --accent: #3b82f6;
}

/* 2. Base styles */
body { ... }

/* 3. Component styles */
.glass { ... }

/* 4. Utility overrides */
@media (max-width: 768px) { ... }
```

### Theme System (Light/Dark Mode)

MEeL mendukung light dan dark mode dengan arsitektur CSS variables:

```
assets/css/shared/
├── theme-tokens.css    # CSS variables (meel-bg, meel-surface, meel-text, dll.)
├── light-theme.css     # Light mode overrides untuk Tailwind utilities
└── design-tokens.php   # Shared tokens untuk upload forms
```

**Cara Kerja:**
1. `theme-tokens.css` mendefinisikan CSS variables untuk dark mode (default)
2. `light-theme.css` override variables saat `html[data-theme="light"]`
3. `theme.js` manage toggle, localStorage, dan DB sync
4. Toggle hanya tersedia di halaman Profile

**Adding Light Mode Support:**
- Gunakan CSS variables (`var(--meel-bg)`, `var(--meel-surface)`, dll.)
- Hindari hardcoded colors seperti `bg-[#0d1017]` atau `text-gray-300`
- Jika harus menggunakan Tailwind hardcoded, tambah override di `light-theme.css`
- Logo/icon harus di-exclude dari color overrides (gunakan `:not(.nav-logo-text)`)

**Theme Toggle Flow:**
```
User klik toggle → MEELTheme.toggle()
→ applyTheme('light'/'dark')
→ Save ke localStorage + DB (jika login)
→ Update CSS variables + icon + label
```

---

## Catatan `.htaccess` & Route Legacy

### `auto_prepend_file` gerbang arcade (portabel & fail-closed)

- `arcade/.htaccess` memakai `php_value auto_prepend_file _gate.php` dengan **filename polos tanpa path**: PHP me-resolve relatif terhadap cwd = direktori script utama yang dieksekusi, sehingga portabel di mesin mana pun (tanpa path absolut hardcoded).
- Konsekuensi fail-closed: skrip yang dieksekusi langsung di subdirektori wajib punya `_gate.php` sendiri — cukup satu baris `require __DIR__ . '/../../_gate.php';` (lihat `arcade/chess/controller/_gate.php`).
- Request clean-URL (router di root) **tidak** kena `php_value` ini — gating-nya oleh `modules/core/Modules.php` di Router.

### `controllers/.htaccess` menaungi aturan 301 legacy

`Deny from all` di `controllers/.htaccess` (file include-only; akses langsung 403 by design, diuji `security_test.php:419`) menaungi seluruh aturan 301 `controllers/*` di root `.htaccess` — aturan itu terlihat mati padahal hidup. Sifatnya **latent**: bila deny dilepas, aturan tetap mengarah 301 ke rute induk yang benar.

### Route dihapus (Fase 0/T7) & rute bersih

- `profile/manage-action`, `admin/actions`, `admin/data` dihapus dari `Router.php` — keduanya fragment include ber-guard `MEEL_MANAGE_ACCESS`/`MEEL_ADMIN_CONTEXT` sehingga route mandiri mustahil berdiri. URL lama di-301 ke induk (`/profile/manage`, `/admin`) di root `.htaccess`.
- `assets/js/drive/upload.js` memakai rute bersih `../api/ajax-refresh`; URL lama `controllers/api/ajax_refresh.php` selalu 403 oleh deny di atas.
- Catatan empiris: root 301 generik **tidak** diproses untuk subtree `arcade/` (seluruh `*.php` arcade dieksekusi langsung).

---

## Testing

### Manual Testing Checklist

Setiap perubahan harus di-test:

**Frontend:**
- [ ] Halaman tidak error di browser console
- [ ] HTMX request/response bekerja
- [ ] Mobile responsive (min width 320px)
- [ ] Dark mode konsisten
- [ ] Semua tombol dan link berfungsi

**Backend:**
- [ ] Prepared statements tidak error
- [ ] CSRF validation berfungsi
- [ ] Role-based access berfungsi
- [ ] File upload validasi berfungsi
- [ ] Error handling menampilkan pesan yang sesuai

### Debug Tools

```php
// 1. PHP Error Log
error_log("[MEeL] Debug message: " . $variable);

// 2. AJAX Response Log (server-side)
error_log("LIKE.PHP - POST: " . json_encode($_POST));

// 3. Browser Console
console.log('HTMX response received');
console.error('Error:', error);

// 4. Query Logging
$stmt = $conn->prepare("SELECT ...");
// Pastikan prepared statement tidak error
if (!$stmt) error_log("SQL Error: " . $conn->error);
```

---

## MFA / TOTP Development

### TOTP Implementation (Time-based One-Time Password)

MEeL mengimplementasikan TOTP sesuai [RFC 6238](https://datatracker.ietf.org/doc/html/rfc6238):

| Parameter | Nilai |
|---|---|
| Algoritma | HMAC-SHA1 |
| Digit | 6 digit |
| Time Step | 30 detik |
| Window | ±1 (90 detik toleransi) |
| Encoding | Base32 |

### Helper Functions (di `modules/auth/helpers/mfa.php`)

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

// ─── VERIFY TOTP (dengan window ±1) ────────────────────────
function verify_totp(string $secret, string $code): bool {
    for ($i = -1; $i <= 1; $i++) {
        // Generate TOTP dengan offset waktu $i step
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
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);  // 6 digit
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
            array_splice($codes, $i, 1);  // Hapus yang sudah dipakai
            return ['valid' => true, 'remaining' => $codes];
        }
    }
    return ['valid' => false, 'remaining' => $codes];
}
```

### Database Schema

3 kolom baru di tabel `users` (Migration v9):

```sql
ALTER TABLE users
    ADD COLUMN mfa_secret      VARCHAR(64)  DEFAULT NULL AFTER last_session_id,
    ADD COLUMN mfa_backup_codes TEXT        DEFAULT NULL AFTER mfa_secret,
    ADD COLUMN mfa_enabled     TINYINT(1)   DEFAULT 0     AFTER mfa_backup_codes;
```

### MFA Session Flow

```
1. Login password benar → Cek mfa_enabled == 1
2. Ya → Simpan $_SESSION['mfa_temp_uid'] = user_id
          Simpan $_SESSION['mfa_temp_username']
          Simpan $_SESSION['mfa_temp_role']
3. Redirect ke auth/mfa-verify
4. User input kode 6-digit
5. Valid → Set $_SESSION['user_id'], 'username', 'role']
          Set $_SESSION['mfa_verified'] = true
          Hapus mfa_temp_* dari session
6. Invalid → Increment $_SESSION['mfa_fail_count']
             Jika >= 10 → $_SESSION['mfa_locked_until'] = time() + 300
```

### Rate Limiting

| Endpoint | Limit | Mekanisme |
|---|:---:|---|
| MFA Verify | 10 gagal → lock 5 menit | Session-based `mfa_fail_count` + `mfa_locked_until` |
| Backup Password | 5 gagal → lock 5 menit | Session-based `backup_pwd_attempts` + `backup_pwd_lock_until` |

### Security Considerations

1. **Secret TOTP** — Disimpan plaintext di DB (TOTP secret harus bisa dibaca)
2. **Backup Codes** — Disimpan sebagai hash password_hash()/bcrypt (one-way, tidak bisa dibaca balik)
3. **Session Temp** — `mfa_temp_uid` hanya ada di session, tidak di cookie
4. **Brute Force** — 10 percobaan MFA gagal → lock 5 menit
5. **QR Code** — 100% offline (library qrcode.min.js lokal, tidak ada data dikirim ke server eksternal)
6. **Admin Reset** — Admin tidak bisa reset MFA admin lain
7. **Activity Log** — Semua event MFA (setup, verify, gagal, reset) dicatat di `activity_log`

### Testing MFA Locally

1. **Aktifkan MFA:** Buka `profile/index.php` → klik toggle MFA → ikuti setup
2. **Dapatkan TOTP:** Buka `auth/mfa_setup.php`, scan QR dengan Google Authenticator
3. **Simulate TOTP:** Gunakan `generate_totp($secret)` via script test untuk verifikasi
4. **Test rate limit:** Input kode salah 10× → cek lockout
5. **Test backup code:** Coba salah satu backup code untuk login
6. **Test admin reset:** Login sebagai admin → `admin/mfa_reset.php` → reset user

---

## Pull Request Guide

### 📜 Lisensi & Kontribusi

Proyek ini dilisensikan di bawah **GNU General Public License v3.0 (GPLv3)**. Lihat file [`LICENSE`](../../LICENSE) untuk teks lengkap.

> **Dengan mengirimkan Pull Request, Anda menyetujui bahwa kontribusi Anda akan dilisensikan di bawah GPL v3** — lihat [Pasal 10](https://www.gnu.org/licenses/gpl-3.0.html#section10) (Automatic Licensing of Downstream Recipients).

#### Copyright Header pada File Baru

Setiap file sumber baru (PHP, JavaScript, CSS) **wajib** menyertakan header copyright berikut:

```php
/**
 * MEeL - Media Hub Platform
 *
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */
```

#### Atribusi & Modified Version

GPL v3 mewajibkan (Pasal 5a):
1. Setiap file yang dimodifikasi harus diberi **notice perubahan** yang jelas
2. File yang dimodifikasi harus tetap **mengacu pada lisensi GPL v3**
3. **Karya turunan** (derivative work) harus dirilis di bawah **lisensi yang sama**

---

### Checklist Kontribusi

- [ ] Gunakan **Prepared Statements** untuk semua query database
- [ ] Sanitasi input POST/GET
- [ ] CSRF token di setiap form POST baru
- [ ] Role check sebelum operasi sensitif
- [ ] Update `update.php` dengan changelog
- [ ] Test upload file besar di lokal
- [ ] Test di mode incognito (session test)
- [ ] Setiap file baru memiliki **copyright header GPL v3**
- [ ] Perubahan ditandai dengan **notice modifikasi** yang jelas

### Git Commit Convention

```
[type]: Deskripsi singkat (max 50 chars)

- Detail perubahan jika perlu
- Bisa multi-line
```

**Type:**
| Type | Penggunaan |
|---|---|
| `feat` | Fitur baru |
| `fix` | Bug fix |
| `security` | Perbaikan keamanan |
| `perf` | Optimasi performa |
| `refactor` | Refactoring kode |
| `docs` | Dokumentasi |
| `style` | CSS/perbaikan UI |

**Contoh:**
```
feat: Add playlist queue next/prev navigation

- Implement auto-next on song end
- Add keyboard shortcuts for skip
- Fix mini player sync across pages
```

### Branch Strategy

```
main (stable)
  └── Experiment (development branch)
       ├── feature/[nama-fitur]
       └── fix/[nama-fix]
```

---

## Troubleshooting Development

### ❌ HTMX tidak bekerja

**Cek:**
1. File `assets/js/compatibilitas/htmx.min.js` ter-load (cek Network tab)
2. Element target (`hx-target`) ada di DOM
3. Tidak ada JavaScript error di console
4. Response dari server valid HTML

### ❌ "Headers already sent" error

**Penyebab:** Output sebelum `header()` atau `session_start()`.

**Solusi:**
```php
// Output buffering di awal
ob_start();

// Atau pindahkan session_start() ke paling atas
session_name('meel');
session_start();

// Redirect dengan JavaScript fallback
if (!headers_sent()) {
    header("Location: index.php");
} else {
    echo "<script>window.location.href='index.php';</script>";
}
```

### ❌ Session tidak tersimpan

**Cek:**
1. `session_name('meel')` dipanggil SEBELUM `session_start()`
2. `auth/config.php` di-include di setiap halaman
3. Tidak ada output sebelum `session_start()`
4. Folder session writable

### ❌ SweetAlert2 tidak muncul

**Cek:**
1. File `assets/js/compatibilitas/sweetalert2.all.min.js` ter-load
2. Fungsi `meelAlertRedirect()` didefinisikan di `assets/js/compatibilitas/script.min.js`
3. Tidak ada CSS conflict

---

## Keputusan Teknis & Konfirmasi Perbaikan (2026-10-03)

> Berkas ini memuatkan keputusan yang diambil saat optimalisasi performa & perbaikan
> bug. Semua catatan ini **dipindahkan dari komentar di dalam kode** sesuai
> [Gaya Komomentar](#gaya-komentar) aturan 8 — kode cukup jauh lebih ringkas,
> semua konteks panjang hidup di sini.
>
> Cakupan: Fase 1 (T11–T16) selesai 6/6, T20 (`release.yml`), plus
> T26 (bug timezone). Detail status per tugas ada di `.agents/Todo.md`.

### Kontrak Jam: `DATETIME` adalah *wall clock* tanpa timezone

Aturan ini yang mendasari T26 dan jadi patokan wajib di seluruh repo.

Kolom `DATETIME` di MySQL **tidak menyimpan timezone**. Nilai yang ditulis bisa datang
dari dua jam berbeda:

| Sumber | Dipakai oleh |
| --- | --- |
| `NOW()` / `CURRENT_TIMESTAMP` | **jam server MySQL** (`@@time_zone`) |
| `date()` / `time()` / `strtotime()` | **jam PHP** (`date_default_timezone_get()`) |

Hampir seluruh kode repo **membaca** kolom datetime lewat `strtotime()` → selalu
jam PHP. Begitu `date_default_timezone` PHP berbeda dari `time_zone` MySQL, kolom
yang ditulis `NOW()` terlihat **di masa depan**, dan dua hal rusak:

- `$elapsed = max(0, time() - $ts)` → negatif → di-clamp `0` → **countdown/umur
  selalu penuh** (UI menampilkan angka yang tidak pernah terjadi);
- ambang `col <= $threshold` → tidak pernah true → **logika refill/lockout mati
  total** (user terkunci saldo habis).

**Aturan repo:** kalau sebuah kolom `DATETIME` dibaca lewat `strtotime()`/`time()`
dari PHP, kolom itu **wajib ditulis dari jam PHP**. Gunakan
`meel_now()` / `meel_now_ago()` dari `modules/core/helpers/datetime.php`.
`NOW()` hanya boleh tetap dipakai bila kolomnya dibaca murni sebagai DATETIME SQL
(mis. `ORDER BY created_at DESC`) tanpa pernah dibandingkan dengan jam PHP.

#### Skema yang sudah diperbaiki

| Kolom | Ditulis | Dibaca | Gejala kalau timezone meleset |
| --- | --- | --- | --- |
| `users.meelcoin_last_refill` | `MeelCoin.php` (3 titik) | `MeelCoin.php` `$threshold` + `$elapsed` | refill mati, countdown selalu penuh |
| `users.last_activity` | 5 titik produksi (lihat di bawah) | 6+ titik | **user yang di-"kick" tetap tampil ONLINE**, `live-monitor` menampilkan waktu di masa depan |

Titik tulis `last_activity` yang sudah dikonversi: `activity_logger.php` (UPDATE
user terautentikasi + INSERT guest `ON DUPLICATE KEY UPDATE`), `auth/login.php` (×2),
`auth/mfa_verify.php`, `auth/auth.php`, dan `admin_actions.php` (`kick_user`, yang
sebelumnya memakai `DATE_SUB(NOW(), INTERVAL 10 MINUTE)`).

#### ⚠️ Audit yang BELUM selesai

Kolom `created_at`, `login_attempts`, dan `rate_limit` **masih ditulis `NOW()`**.
Untuk `created_at` dampaknya **display-only** (drift jam di
`admin/activity_log.php`) — belum diaudit per-kolom. **Lakukan audit ini sebelum
membangun worker (T17)**, karena scheduler berbasis waktu akan makin sensitif
terhadap skew.

#### Reproduksi di mesin dev

PHP CLI memakai `date.timezone = UTC` (`/etc/php/8.3/cli/php.ini`) sedangkan
MariaDB XAMPP `@@time_zone = SYSTEM` = **WIB (UTC+7)** → selisih **25195 detik
≈ 7 jam**. Griffiths test `MeelCoinIntegrationTest::testRefillResetsTimerWhenBalanceIsAtMax`
tepatnya menangkap selisih 7 jam ini sebelum perbaikannya.

---

### Housekeeping per-request: throttle vs. bagian keamanan

#### `ActivityLogger` (T11)

Blok prosedural di `modules/core/activity_logger.php` dirapikan jadi kelas
`ActivityLogger::onRequest($conn)` yang dipanggil dari `auth/config.php`. Yang
berubah adalah **biaya**, bukan perilakunya:

- **Throttle 60 detik.** Signature `last_page|device|access_via|ip_address`
  disimpan di `$_SESSION['_meel_touch']`. Payload identik dalam window itu tidak
  ditulis ulang. Signature sengaja memuat halaman + perangkat + jaringan supaya
  perpindahan halaman tetap tercatat seketika, bukan menunggu window habis.
- **Skip respons non-HTML.** `shouldSkipTelemetry()` menolak: partial htmx
  (`HTTP_HX_REQUEST`), `Accept` yang tidak memuat `text/html`/`*/*` (JSON & segmen
  HLS), dan endpoint `stream.php` / `file.php` (binary).
- 🔒 **Bagian keamanan TIDAK di-throttle.** `enforceIpBan()` (redirect ban IP) dan
  `enforceSingleSession()` (cabut sesi) selalu jalan. Throttle hanya untuk
  telemetry. Ini keputusan yang tidak bisa ditawar — throttle di sini akan
  membuka celah revoke.
- ⚠️ **`$hasRun` static tidak berguna di PHP-FPM.** Static tidak di-share antar-request,
  jadi guard itu hanya berguna di dalam satu proses. **Motif sebenarnya throttle
  adalah file**, bukan static. Test menutup keduanya (mock `mysqli` membuktikan
  idempoten di satu proses; test terpisah membuktikan throttle bertahan setelah
  static di-reset).

#### `GarbageCollector` (T11 + T16)

`run()` menjalankan dua hal mahal pada **setiap request**: scan direktori storage
dan `RateLimiter::cleanup()`. Keduanya kini berada di balik satu file throttle
(`temp/gc_run_last_run.txt`, `RUN_INTERVAL_SECONDS = 60`). Scan dihentikan setelah
batas waktu 3 detik seperti sebelumnya.

**Pemisahan CLI/web (T16):** `ALTER TABLE ... AUTO_INCREMENT` memblokir metadata
MySQL dan `syncViewsFromLogs()` menulis ulang kolom agregat — keduanya terlalu
berat untuk request. Dipindah ke `GarbageCollector::runCliMaintenance()`, yang
memiliki **seatbelt `PHP_SAPI !== 'cli'`**: dipanggil dari web Request menjadi
no-op, bukan error. Entry point: `scripts/gc.php` (mode `--run-only` untuk
housekeeping ringan). Seatbelt diuji lewat SAPI `cgi-fcgi` sungguhan, bukan
hanya diasumsikan.

**Known-gap yang masih ada:** tak ada sweeper untuk `upload_queue` /
`transcode_queue` berstatus `processing` yang mati karena crash → baris menggantung
selamanya dan user tidak pernah mendapat refund-nya.

---

### Query: mana yang benar-benar mahal

#### `System::countActiveQueues()` (T12)

`isServerBusy()` hanya butuh **angka**, tapi sebelumnya memanggil
`getActiveQueues()` yang menarik seluruh baris antrean (URL, username,
`created_at`) lengkap dengan JOIN dan filesort. Kini memakai `COUNT(*)` per tabel.
`getActiveQueues()` **dipertahankan** karena `server_stats.php`,
`server_stats_sse.php`, dan `admin_actions.php` memang butuh detail baris.

#### Angka pengukuran — baca dengan jujur

⚠️ **Jumlah query tidak berkurang.** `Com_select` tetap **2** di kedua jalur
(keduanya menyentuh 2 tabel). Ini `COUNT(*)`-only, bukan pengurangan jumlah query.
Benefit-nya ada di **payload dan parse**:

| Skenario | `isServerBusy()` (baru) | `getActiveQueues()` (lama) |
| --- | --- | --- |
| 2 baris `processing`, 300× | 57 ms · 0 B | 78 ms · 2.216 B |
| 2.001 baris `processing`, 200× | **109 ms · 0 B** | **7.088 ms · 1.366.920 B** |

Pada beban realistis yang penting (antrean menumpuk), jalur count **~65× lebih
cepat**. `EXPLAIN`: jalur `COUNT(*)` → `type: ref`, `key: status`,
`Using index` (covering, tak perlu menyentuh baris). Jalur penuh → JOIN `users`
+ `Using temporary; Using filesort`.

#### `MediaViewer::__construct()` (T12)

Sebelumnya query `users` langsung. Kini memakai `get_user_role()` (sudah ada,
cached + session-aware) dan helper baru **`get_user_active()`**
(`modules/auth/helpers/user.php`, cached, `LIMIT 1`, `close()` — mencerminkan
`get_user_role()`). Hampir selalu kena cache karena `MediaViewer` dipakai di
halaman yang sudah punya sesi.

#### `MediaViewer::getMediaData()`

`($result && $result->num_rows > 0) ? $result->fetch_assoc() : null` →
`return $result ? $result->fetch_assoc() : null;`. `num_rows` butuh panggilan
handler tambahan tanpa mengubah apa pun, dan **tidak bisa di-fake di test double**
(karena `num_rows` adalah *read-only virtual property* — lihat
[Test Infrastructure](#test-infrastructure-catatan-penting)).

---

### Sampling id acak tanpa `ORDER BY RAND()` (T13)

Helper bersama di `modules/core/helpers/media.php`:

| Fungsi | Peran |
| --- | --- |
| `meel_media_stats_all()` | **Satu** query `UNION ALL` untuk `video`/`music`/`books` (COUNT + MIN(id) + MAX(id)), cache file per tabel 30 detik |
| `meel_media_stats()` | Satu tabel; cache miss menghangatkan **ketiganya** sekaligus |
| `meel_pick_random_ids()` | Sampling **rentang acak** untuk rekomendasi |
| `meel_invalidate_media_stats_cache()` | Buang cache (dipanggil di 4 titik mutasi) |
| `meel_media_table_whitelist()` | Validasi nama tabel (anti injeksi lewat nama tabel) |

**Strategi sampling:** beberapa jendela `id >= ? ORDER BY id LIMIT ?` dengan titik
awal acak, digabung jadi himpunan unik. Setiap jendela memakai indeks id
(range scan) → biaya `O(log n + limit)`, tanpa filesort, dan tetap unbiased ke
seluruh rentang id.

**`ORDER BY RAND()` kini nol tersisa di seluruh query repo.**

#### Keputusan: kenapa **tidak** memakai `LIMIT 500`

Advice awal Take list `LIMIT 500` untuk pengambilan id. **Ditolak** — mengambil
500 id pertama akan membiasakan rekomendasi ke id terkecil. Dipakai sampling rentang
acak yang unbiased, dan **selalu** punya `LIMIT` — jadi memenuhi maksud "jangan
tarik seluruh tabel" tanpa mengorbankan sebaran.

#### Sumber kebenaran tunggal untuk hitungan media

Sebelumnya ada **dua** cache: `media_counts.json` (di `MediaLibrary`) dan hitungan
yang dikomputasi on-the-fly (di `MediaViewer`). Keduanya kini memakai
`meel_media_stats_all()`. `MediaLibrary::clearCountsCache()` cukup memanggil
`meel_invalidate_media_stats_cache()`.

Titik invalidasi: upload video (`Uploader.php`, `DownloadService.php`), upload
musik (`helpers/upload.php`), hapus video & musik (`fun-manage.php`).

Cache bisa dialihkan lewat konstanta **`MEEL_MEDIA_CACHE_DIR`** (mengikuti pola
`MEEL_SERVER_STATS_CACHE`) supaya test tidak bergantung pada hak akses
`temp/cache/` milik Apache.

#### Dua bug yang ditemukan oleh test

1. `array_keys()` yang diaplikasikan pada list id mengubah pool menjadi `[0, 1, …]`
   sehingga kondisi "semua kandidat sudah pernah dilihat" tidak pernah terdeteksi.
2. Cache memakai path yang tidak writable oleh proses CLI — memicu kebutuhan
   konstanta `MEEL_MEDIA_CACHE_DIR`.

---

### Conditional request untuk media streaming (T14)

Tiga fungsi murni (bukan inline di dalam fungsi yang me-`exit()`), supaya bisa diuji:

| Fungsi | Peran |
| --- | --- |
| `meel_cache_is_fresh()` | Keputusan 304 dari `If-None-Match` / `If-Modified-Since` |
| `meel_media_etag()` | Strong ETag dari path+size+mtime |
| `meel_media_cache_control()` | `m3u8` → `private, no-cache`; lainnya → `private, max-age=31536000, immutable` |

Aturan yang harus dijaga:

- 🔒 **Tidak pernah 304 pada Range request.** 304 + 206/`Content-Range` bersamaan
  itu respons invalid. Syaratnya `if (!$isPartial && …)`.
- 🔒 **Tetap `private`, tidak pernah `public`.** Konten di-gate per sesi/pengguna;
  `public` berisiko bocor lewat shared cache/CDN ke pengguna lain.
- Playlist HLS berubah terus (segmen baru ditambahkan) → wajib divalidasi ulang.
  Segmen/berkas media immutable setelah ditulis → boleh dicache lama.
- `ETag`, `Last-Modified`, `Cache-Control` dikirim di **kedua** jalur
  (X-Sendfile **dan** streaming), bukan hanya satu.

**Bug yang ditemukan test:** `If-None-Match: *` sempat tidak dianggap fresh,
melanggar RFC 7232 §3.2 ("*" = "jika ada representasi apa pun").

---

### Versi aset: kenapa `meel_asset_dir_version()` sengaja max-mtime direktori

T11 (T15) memberi `?v=` pada 48 URL aset telanjang di 25 file. Tapi advice awal
"ganti max-mtime direktori dengan versi per-file" **ditolak**, karena akan menjadi
**regresi**:

`assets/js/{video/watch,music/index,music/watch}/main.js` membaca `?v=` dari
`document.currentScript.src`, lalu **meneruskannya ke seluruh modul anak** yang
dimuat dinamis. Kalau versinya per-file, perubahan pada modul anak **tidak akan
menginvalidasi cache browser sama sekali** — hanya file entry yang berubah.

Closure penuhnya tetap **T22** (content-hash saat build), bukan `?v=filemtime`.
Daftar pemanggil `meel_asset_dir_version()` dikunci di `AssetVersioningTest`.

---

### Anotasi `@var` untuk analyzer (T20)

Halaman admin mewarisi variabel dari file yang di-`include` (`auth/config.php`,
`controllers/admin/admin_data.php`) — sah saat runtime, tapi tidak terlihat oleh
static analyzer karena analyzer tidak menelusuri variabel lintas `include`.
Karena itu 12 halaman admin punya blok `@var` di awal file.

#### Akar masalahnya satu pola: `@var` ditulis berkoma

```php
/** @var \mysqli_result $a, $b, $c; @var array $d */   // ❌ hanya baris pertama terbaca
```

Bentuk daftar berkoma **tidak dipahami analyzer mana pun** — hanya baris `@var`
pertama yang dibaca, sisanya diabaikan diam-diam. Intelephense yang melaporkan
`P1008 Undefined variable` kalau bentuk ini dipakai. Perbaikannya:

- satu variabel per baris `@var`;
- blok docblock **harus diletakkan sebelum pemakaian pertama** — `@var` hanya
  berlaku ke depan. Inilah alasan `$conn` di baris 8 masih dilaporkan error saat
  bloknya diletakkan di baris 15.

`$conn` berasal dari `auth/config.php` dan dipakai 10 halaman admin, jadi blok
`@var \mysqli $conn` ditambahkan ke masing-masing. Baris `@var` ini **fungsional**
(bukan komentar hiasan) — menghapusnya mengembalikan ratusan `P1008` di editor.

> **Catatan:** PHPStan pernah dicoba di sini (level 2, scope `admin/`) dan berhasil
> menurunkan 165 error menjadi 0 — tapi **dicabut lagi** (2026-10-03). Alasannya:
> config `scanFiles`-nya rapuh (34 path absolut; kalau salah satu di-rename,
> PHPStan hanya print "Scanned file … does not exist" lalu keluar diam-diam dan
> hasilnya menyesatkan), belum ada job CI yang menjalankannya, dan
> `require-dev` tidak ikut terpasang di build produksi. `@var` yang diperbaiki
> tetap valuable untuk IDE.

#### ⚠️ Intelephense P1038 pada `FakeMysqliStmt::bind_param()`

`P1038` = "method signature incompatible with parent". Satu-satunya trigger di repo
ini adalah test double mysqli, dan itu **false positive dari stub Intelephense**:

| Bentuk | Diterima PHP 8.3? |
| --- | --- |
| Stub Intelephense 1.18.5: `bind_param($types, &$var1, &...$_)` | ❌ **DITOLAK engine** |
| Nyata: `bind_param(string $types, mixed &...$vars): bool` | ✅ DITERIMA |

Stub Intelephense belum menyusul perubahan signature `mysqli_stmt::bind_param()`
di PHP 8.0, jadi analyzer dan engine **tidak bisa dipenuhi bersamaan**. Ditutup
lewat `.vscode/settings.json` → `intelephense.diagnostics.exclude`, dikunci ke file
dan kode saja (`["P1038"]`) supaya pemeriksaan lain di file yang sama tetap aktif.
File `.vscode/` sudah masuk `.gitignore`, jadi kontributor lain tidak terpengaruh.

> Konsekuensi praktis: `FakeMysqliResult::close()` ditulis `: void` mengikuti stub
> analyzer, meski runtime PHP menerima juga bentuk untyped. Bentuk `: void`
> memenuhi keduanya, jadi itu pilihannya.

---

### Notifikasi: coalescing `like` & retensi (2026-10-03)

Tujuan: notifikasi MEeLCoin **isi ulang** otomatis, sekaligus menghentikan
pertumbuhan `user_notifications` tanpa batas.

#### Latar belakang (dihitung dari data, bukan asumsi)

Sebelum perubahan ini tabel `user_notifications` **tidak punya pruning sama
sekali** — satu-satunya `DELETE` berasal dari aksi user (`deleteOne`,
`deleteAllByUser`, `deleteByChat`). Tidak ada batas umur maupun batas jumlah.

Yang membuatnya tidakurgency: halaman notifikasi hanya mengambil **50 baris
terbaru** (`profile/notification.php` → `getList($conn, $userId, 50, …)`; API
meng-imposed `min(50, …)`). Jadi **baris di luar 50 terbaru per pengguna tidak
pernah tampil** — murni ballast.

Kondisi saat itu: 30 baris / 3.351 B, rata-rata 112 B per baris. Tipe `meelcoin`
menyumbang 27 dari 30 baris — karena `message`-nya menyisipkan **URL dan judul
upload penuh**. Dari 30 baris itu hanya **27 yang unik**: ada 3 duplikat persis,
salah satunya jejak **retry upload**.

#### Aturan 1 — Notifikasi isi ulang otomatis

Dibuat di dalam `MeelCoin::refill()`, bukan di titik pemanggil. Alasannya: jumlah
yang dikreditkan hanya diketahui di sana, jadi tidak ada pemanggil yang bisa lupa.
`refill()` dipanggil dari 7 tempat produksi dan **tidak satu pun berubah**.

`admin` sudah keluar lebih dulu di `refill()` (`if ($role === 'admin') return true;`)
sehingga role itu otomatis tidak menerima notifikasi.

**Pesannya memakai jumlah yang benar-benar dikreditkan, bukan angka tetap:**

> Saldo Anda bertambah {jumlah} dari isi ulang.

Hardcode "25" akan berbohong di dua kasus nyata:

| Sumber | Nilai |
| --- | --- |
| Default `meelcoin_user_refill` | **15** |
| Default `meelcoin_member_refill` | 25 |
| Semua nilai | bisa diubah admin di `admin/meelcoin.php` → `site_settings` |
| Jumlah nyata | `min($refillAmt, $maxCoins - $current)` — **dipotong sisa kapasitas** |

Jadi user `member` dengan saldo 45 (max 50) dan refill 25 hanya menerima **+5**.
Tipe notifikasi memakai ulang `meelcoin` (bukan tipe baru), supaya
`$validTypes`, `$ICONS`, dan filter di `notification.js` tidak perlu berubah dan
seluruh histori koin tetap dalam satu tab.

#### Aturan 2 — Coalescing notifikasi `like`

Satu video viral bisa menghasilkan ratusan like dalam hitungan menit. Tanpa
penggabungan,uploader menerima ratusan baris notifikasi yang isinya cuma
"X menyukai karyamu".

Jadi `Notification::create()` menjadi no-op bila notifikasi `like` untuk pengguna
yang sama sudah dibuat dalam **60 menit terakhir**. Atribut yang dikorbankan:
identitas peng-`like` di dalam jendela tersebut (notifikasi menampilkan orang
 pertama yang memicu) dan video mana pun ikut tertunda. Ini memang disengaja —
batasnya jadi jelas: **maksimal satu notifikasi like per jam per pengguna**.

Hanya `like` yang digabung. Tipe lain **tidak boleh** di-throttle:

| Tipe | Alasan |
| --- | --- |
| `reply` | percakapan — tanpa notifikasi berarti komentar hilang |
| `admin_chat` | pesan admin harus masuk semuanya |
| `meelcoin` | terikat pada saldo — user perlu riwayat utuh |
| `system` | pengumuman sistem |

#### Aturan 3 — Retensi: 50 baris & 15 hari

`Notification::pruneUser()` menghapus baris yang:

1. berumur lebih dari **15 hari**, atau
2. berada di luar **50 terbaru** (urut `created_at DESC, id DESC`).

Baris yang belum dibaca juga ikut terhapus bila sudah terdorong keluar batas 50 —
sah karena halaman notifikasi memang tidak pernah menampilkan lebih dari itu.

Dijalankan dari `scripts/gc.php` (CLI/cron), bukan dari web request — prune
membuat `DELETE` dan tidak pantas dipanggil per-request. `pruneAll()` mengembalikan
jumlah **pengguna** yang dipangkas, bukan jumlah baris: `mysqli_stmt::$affected_rows`
termasuk properti internal yang tidak bisa dibaca pada test double, jadi menghitung
baris akan membuat kode sulit diuji tanpa manfaat nyata.

#### Yang perlu migration

Indeks baru `idx_un_user_type_created (user_id, type, created_at)` — menopang
lookup coalescing `like` sekaligus jadi covering untuk query pruning. Tanpa ini,
`isCoalesced()` menyisir seluruh baris pengguna tersebut per-press like.

Migrasi **v4** + `schema.sql` sudah disinkronkan (keduanya wajib ikut, sesuai
konvensi T9). Sudah diverifikasi idempoten 2×.

#### Verifikasi

`tests/unit/NotificationRetentionTest.php` (**11 test**) mengunci: hanya `like`
yang punya jendela coalescing, tipe lain tidak menyentuh DB sama sekali, kedua
batas retensi benar, argumen tidak masuk akal di-clamp (keep ≥ 1), dan prune tidak
menyentuh pengguna lain.

Ditambah verifikasi langsung ke DB (6 skenario, semua lulus):

| # | Skenario | Hasil |
| --- | --- | --- |
| 1 | 5× like dalam 1 jam | **1** baris |
| 2 | 3× reply | **3** baris (tidak di-throttle) |
| 3 | 120 baris → prune | **50** baris |
| 4 | umur 20 hari | terhapus; yang baru **utuh** |
| 5 | pengguna lain | **tidak terpengaruh** |
| 6 | refill role `user` | notifikasi tampil: "bertambah **15**" |

Skenario 6 sekaligus membuktikan koreksi di atas: angka yang muncul adalah **15**
(default role `user`), bukan 25.

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

### Test Infrastructure — catatan penting

#### 🚨 `createMock(mysqli::class)` menghabiskan 8,86 GiB RAM

Gejala: `phpunit --testsuite='MEeL Core Unit Tests'` naik ke **8,86 GiB RSS** lalu
OOM di `MockClass.php`.

Akar masalah: PHPUnit membangun kelas mock lewat `eval()` dan **menahannya di static
cache selama proses**. `mysqli` punya `bind_param(string $types, mixed &...$vars)`
— parameter by-reference + variadik — sehingga kelas hasil generate-nya sangat
besar. Menumpuknya di beberapa test memindahkan proses ke territory GB.

Solusi: `tests/unit/support/MysqlFake.php` — `FakeMysqli`, `FakeMysqliStmt`,
`FakeMysqliResult` sebagai subclass yang **tidak memanggil constructor parent**
(tanpa koneksi, tanpa kelas yang di-generate, RAM ≈ 0).

Ditambah `phpunit.xml` → `<ini name="memory_limit" value="2G"/>` karena
`phpunit.xml` CLI repo ini memakai `memory_limit = -1` (tak terbatas); tanpa itu
satu test salah bisa menghabiskan RAM mesin sebelum ketahuan.

Hasil: **8,86 GiB → 453 MiB** (~19× lebih ringan), OOM hilang.

> **Aturan:** kalau menambah test yang butuh `mysqli`, pakai `FakeMysqli` — **jangan**
> `createMock(mysqli::class)`. Kalau memang perlu mock, buat di `setUp` **sekali**
> lalu pakai ulang.

#### `mysqli_result::$num_rows` tidak bisa di-fake

Di PHP 8.3 `num_rows` adalah **read-only virtual property**, bukan method.
Override `num_rows()` maupun `__get()` sama-sama **tidak mengintervensi** — akses
`$result->num_rows` tetap melempar `object is already closed`.
`ReflectionMethod('mysqli_result', 'num_rows')` bahkan melempar *method does not
exist*, yang membuktikan conclusively bahwa ini murni property. Produksi
`MediaViewer::getMediaData()` sengaja tidak memakainya.

#### Catatan lingkungan dev

- ⚠️ `MEEL_TEST_DB_HOST=127.0.0.1` **wajib** untuk integration test. Socket
  MariaDB XAMPP ada di `/opt/lampp/var/mysql/mysql.sock`, sedangkan PHP CLI
  mencari `/var/run/mysqld/mysqld.sock` (`mysqli.default_socket`) →
  `No such file or directory`. `localhost` gagal, TCP `127.0.0.1` jalan. Ini
  masalah mesin dev saja — CI sudah benar (`ci.yml` memakai `127.0.0.1`).
- ⚠️ `StorageMountGuardTest` (menjalankan subprocess PHP via `proc_open`) pernah
  gagal 1× (`exit 255`) bila suite dijalankan **bersamaan** dengan proses test lain
  yang menyentuh `temp/`. Jangan dijalankan paralel dengan `security_test` /
  `check_deploy`.
- Angka pada kolom **IO/R** di `htop` adalah *laju baca*, bukan RSS.

#### `release.yml` sebelumnya mustahil hijau

Job `test-gate` menjalankan `vendor/bin/phpunit` (**kedua** suite, termasuk
integration) tanpa service MySQL → 96 integration test ERROR. kini service
`mysql:8.0` + healthcheck + `extensions: mysqli` + env `MEEL_TEST_DB_*`.

---

### Gate yang dipakai untuk memverifikasi perubahan

```bash
vendor/bin/phpunit --testsuite='MEeL Core Unit Tests'
MEEL_TEST_DB_HOST=127.0.0.1 vendor/bin/phpunit --testsuite='MEeL Integration Tests'
php tests/functional_test.php
php tests/security_test.php
php tests/check_deploy.php
php database/migrate.php   # 2× untuk idempotensi
php scripts/gc.php          # housekeeping berat (butuh $server = TCP)
```

---

## Resource untuk Developer

### File Penting untuk Dipahami

| File | Alasan |
|---|---|
| `auth/config.php` | Entry point configurasi |
| `auth/auth.php` | Authentication middleware |
| `modules/core/helpers/` | Utilitas global (helpers.php = shim) |
| `modules/core/Transcoder.php` + `modules/transcoder/` | Facade + service terpecah: `EncodeService`, `DownloadService`, `TranscodeService` (extend `TranscoderBase`) |
| `modules/core/Uploader.php` | Proses upload file |
| `modules/core/System.php` | Queue & monitoring |
| `modules/auth/RateLimiter.php` | API Rate Limiter |
| `modules/core/ProgressObserver.php` | Kontrak event progress (interface + adapter callable) — lihat `modules.md` |
| `modules/core/BrowserProgressObserver.php` | Presenter browser — memetakan event engine ke overlay/JS `meel*` |
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
| `assets/js/shared/keyboard.js` | Guard shortcut keyboard bersama (meelKeyShortcutIgnored) — dipakai misc/mini-player video & music |
| `assets/js/video/watch/misc.js` | Shortcut spesifik video (L=loop, A=auto-next) |
| `assets/js/video/watch/mini-player.js` | Shortcut video (N=next, I=mini-player) + logika mini-player |
| `assets/js/music/watch/misc.js` | Shortcut musik (L=loop, E=equalizer, V=visualizer, I=mini-player) |
| `assets/js/shared/temp-index.js` | Loader bersama index.php ke #temp-index-content tanpa reload (meelLoadTempIndex) — dipakai mini-player video & music |
| `assets/js/shared/plyr-config.js` | Konfigurasi dasar Plyr bersama (MEEL_PLYR_COMMON: iconUrl, speed, keyboard, tooltips) — dipakai player video & music |
| `assets/js/shared/upload-progress.js` | Animasi progress-bar upload bersama (meelUploadProgress) — dipakai halaman upload music & video |
| `assets/js/shared/resume-modal.js` | Modal resume bersama (meelResumeModal) — dipakai player-events video & player-core music |
| `assets/js/shared/format-time.js` | Formatter waktu mm:ss bersama (formatTime) — dipindah dari music/shared/utils.js, dipakai mini-player music & resume-modal |
| `assets/js/shared/mini-player-popstate.js` | Handler popstate bersama untuk keluar dari mode mini-player (meelMiniPlayerPopstate) — dipakai mini-player watch video & music |
| `assets/js/video/watch/main.js` | Entry point folder watch/ — memuat sibling secara sinkron (document.write) |
| `assets/js/video/watch/state.js` | Video player state management |
| `assets/js/video/watch/player-init.js` | Plyr + HLS.js initialization |
| `assets/js/video/watch/player-events.js` | Event orchestration (auto-next, glow, resume) |
| `assets/js/video/watch/mini-player.js` | Mini-player floating mode |
| `assets/js/video/watch/recovery.js` | Player auto-recovery system — menggunakan factory dari `recovery-manager.js` |
| `assets/js/video/watch/gestures.js` | Mobile touch gestures |
| `assets/js/music/watch/main.js` | Entry point folder watch/ — memuat sibling secara sinkron (document.write) |
| `assets/js/music/watch/mini-player.js` | Mode mini-player music (Spotify-style) — dipisah dari player-core.js |
| `assets/js/music/watch/player-core.js` | Inti player music (visualizer, EQ, bitrate, Media Session, logika resume-modal & sesi) |
| `assets/js/music/watch/description-toggle.js` | Toggle "Selengkapnya" deskripsi music + deteksi overflow |
| `assets/js/shared/recovery-manager.js` | Recovery factory — stuck detector, waiting timeout, reconnect overlay (shared video & music) |
| `assets/js/shared/media-session.js` | Media Session API helper — memperbarui kontrol media OS dengan artwork/metadata |
| `assets/js/music/watch/state.js` | Music player state, preset equalizer & marker sesi resume (`window.__meelResumeSessionActive`) |
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

### Musik — Perilaku Resume Modal

Player musik menampilkan modal **"Lanjut Musik?"** ketika sebuah lagu punya
posisi putar tersimpan (`music_pos_<id>` di `localStorage`) dan user **tidak**
datang dari sesi mini-player yang aktif.

| Konteks | Perilaku |
|---|---|
| **Sesi mini-player** — user men-tap kartu / item playlist atau expand mini-player di `index.php`, dan masih mendengarkan | 🎧 **Auto-continue** — tanpa modal; semua lagu berikutnya di sesi itu langsung diputar otomatis |
| **Kunjungan dingin** — buka `watch.php` langsung, reload halaman, atau setelah pause/close eksplisit mini-player | ❓ **Modal muncul** — "Lanjut Musik?" menanyakan apakah lanjut dari posisi tersimpan |

**Mekanisme:**

- **Flag one-shot `skip_resume_once`** (`sessionStorage`) — dipasang sisi index
  saat tap kartu/playlist dan di `expandPlayerFromMiniPlayer()`. Dibaca dan
  dibuang di **setiap** pemanggilan `meelInitWatchPlayer()` (termasuk transisi
  gapless), jadi tidak pernah nyangkut di storage.
- **Marker sesi `window.__meelResumeSessionActive`** (in-memory, dideklarasikan
  di `assets/js/music/watch/state.js`) — diaktifkan saat flag one-shot
  dikonsumsi. Bertahan selama dokumen SPA, jadi **semua** perpindahan lagu
  berikutnya di watch (auto-next, ganti lagu) melewati modal.
- **Akhir sesi eksplisit** — `miniPlayPauseIndex()` (pause) dan
  `closeMiniPlayerIndex()` di `index.php` membersihkan flag one-shot dan marker
  sesi (`assets/js/music/shared/mini-player.js`). Setelah itu, membuka lagu
  dari link menampilkan modal lagi.
- **Kunjungan dingin** — full page load membuat dokumen baru di mana marker
  in-memory hilang, jadi modal bisa muncul (`skipOnce` di `player-core.js`
  mengecek `skipResumeModalOnce || window.__meelResumeSessionActive`).
- **Guard stuck-paused** — jika modal ditekan tapi lagu punya posisi tersimpan,
  `onFreshTrackReady()` auto putar dari awal, bukan membiarkan lagu diam.

> **Keputusan desain (2026-08):** sesi mendengarkan aktif auto-continue tanpa
> interupsi; hanya kunjungan dingin yang menanyakan resume.

### Video — AI Upscale & Play Recovery

Catatan perilaku player video yang tidak terbaca sekilas dari kode; rujukan
utamanya `assets/js/video/watch/upscaler.js` dan `recovery.js`.

#### Ketersediaan opsi (gating WebGPU)

Baris **AI Upscale** di menu Pengaturan selalu ada, termasuk saat browser tidak
mendukung WebGPU — tidak ada toast otomatis saat halaman dibuka:

- Tak didukung → baris diberi `aria-disabled="true"` + class
  `meel-upscale-unavail` (redup tapi **tetap bisa diklik**), nilai kolom jadi
  `Tidak tersedia`, dan panel normal (toggle/Model/Mode/Skala) tidak terjangkau.
- Klik baris → sub-panel read-only `upscale-why` berisi judul, alasan spesifik
  per penyebab, daftar persyaratan, dan tombol **"Cek ulang dukungan"** yang
  menjalankan `checkSupport(true)`; bila kini didukung → toast + panel beralih
  ke toggle normal.
- Saat pemeriksaan berjalan (`supportChecking`) baris menampilkan spinner dan
  **belum** disabled — anti-kedip, menu tidak berubah sendiri di tengah cek.
- Setelah cek selesai, `updateUI()` menyinkronkan baris (nilai + aria-disabled)
  lewat `setHomeRowValue()` → `applyHomeRowState()`.

Alasan bersumber tunggal: `unsupportedReason()` (juga diekspos sebagai
`MEEL_UPSCALER.unsupportedReason()`), dipakai `supportLabel()` dan
`diagnose()` lewat konstanta `WHY` — urutan pemeriksaan sama dengan `diagnose()`:

| Kode | Kondisi | Alasan |
| ---- | ------- | ------ |
| `insecure` | `!window.isSecureContext` | halaman non-HTTPS/non-localhost |
| `nogpu` | `!navigator.gpu` | browser tanpa WebGPU |
| `no-adapter` | `requestAdapter()` → `null` | GPU/driver diblokir, GPU blank, atau flag WebGPU mati |

#### Status on/off per video (sessionStorage)

Status AI Upscale disimpan di **sessionStorage** (`MEEL_KEYS.UPSCALE_ENABLED`:
`"true|<videoId>"` saat ON, `"false"` saat OFF) — bukan localStorage — sehingga
default-nya **OFF** dan hanya bertahan selama video yang sama:

| Peristiwa | Status |
| --------- | ------ |
| Tab baru / belum pernah dinyalakan | **OFF** (default) |
| Refresh halaman (video yang sama) | **ON tetap** |
| Video loop / ulang sendiri tanpa pindah halaman | **ON tetap** |
| Klik video lain (rekomendasi, tombol next, kartu mini-player) | **OFF** |
| Auto-next saat video habis | **OFF** |
| Buka URL video lain di tab yang sama (navigasi penuh) | **OFF** |

Dua lapis penjagaannya:

- `readEnabled()` hanya menganggap `"true|<videoId>"` bila id video saat ini
  cocok. `validateState()` menjalankannya lagi pada setiap `attach()` —
  termasuk saat instance Plyr tidak berubah — sehingga navigasi penuh ke video
  lain mematikan upscale sekaligus membuang nilai basi dari video sebelumnya.
- Jalur transisi in-place (tanpa reload halaman) memanggil
  `MEEL_UPSCALER.resetForNewVideo()`: `skipToNextVideo()` di `player-events.js`
  (klik manual **dan** auto-next sama-sama lewat sana) serta listener kartu
  mini-player di `mini-player.js`. Reset berjalan tanpa toast agar transisi
  tidak dibanjiri notifikasi; mati total disatukan di `turnOff()`.

`modelId`/`modeId`/`scaleId` tetap di localStorage sebagai preferensi lintas
sesi — hanya status on/off yang bersifat per video. Nilai `localStorage` lama
untuk key yang sama dihapus sekali saat skrip dimuat.

#### Skala: ukuran tekstur keluaran

Panel **Skala** hanya memilih ukuran *tekstur* hasil upscale — bukan ukuran
tampilan video (video selalu melar ke kotaknya sendiri lewat blit linear, jadi
membesarkan Skala tidak membuat video tampak lebih besar):

| Pilihan | Ukuran tekstur |
| ------- | -------------- |
| `auto` — *Auto (ikuti layar)* | pas dengan kotak tampilan × `devicePixelRatio` (maks 2×), **tidak pernah mengecil** dari resolusi native video |
| `1.5` | 1,5× piksel video (1920×1080 → 2880×1620) |
| `2` | 2× piksel video (1920×1080 → 3840×2160) |

- Cap `MAX_W × MAX_H` (3840×2160) tetap otoritas terakhir untuk semua pilihan
  — termasuk `auto`.
- `auto` dulu boleh menghasilkan tekstur **lebih kecil** dari native (kotak
  tampilan ~1217×685 pada layar 1080p): Skala terlihat "tak berpengaruh"
  karena tekstur dikecilkan lalu direntangkan ke kotak yang sama — kerja GPU
  tambahan tanpa hasil terlihat. Kini `s = max(s, 1)` (lihat
  `computeTarget()`), jadi hasil minimal setara native; saat target memang
  native, jalur resample dilewati (lihat MEeLScale).
- Baris info di bawah menu Skala (`<p class="meel-upscale-scale-info">`,
  `aria-live="polite"`) menampilkan `Video WxH → keluaran WxH (n×)` plus
  petunjuk bahwa Skala mengatur tekstur, bukan tampilan. Elemen itu disisipkan
  **sesudah** `<div role="menu">` (lihat `buildScaleList()`) supaya kontrak
  ARIA menu tetap utuh; teksnya dihitung dari `computeTarget()` yang sama
  dengan rebuild sehingga langsung benar walau rantai GPU belum selesai.
  Gaya di `assets/css/video/upscaler.css`.

#### Kontrak API model upscale

Model pluggable lewat `MEEL_UPSCALER.registerModel()`:

```javascript
MEEL_UPSCALER.registerModel({
  id, label, short,
  modes: [{ id, label, short }, ...],
  load: function () { return Promise; },      // lazy-load berat (bundle / bobot)
  buildChain: function ({ device, modeId, inputTexture, native, target }) {
    return [node, ...];
    // node = {
    //   pass(encoder): void,            // encode pass untuk frame ini
    //   getOutputTexture(): GPUTexture, // tekstur output node terakhir
    //   pipelines?: [GPUPipelineBase],  // didaftarkan untuk pembersihan
    //   destroy?(): void,               // opsional: bebaskan resource milik node;
    //                                   // bila ada, menggantikan getOutputTexture
    // };
  },
});
```

- `inputTexture` bertipe `rgba16float` seukuran `native`; ukuran akhir boleh
  berbeda dari `target` — blit linear meregangkannya ke ukuran canvas.
- File model ada di `assets/models/<id>/model.js` dan diinjeksi saat pertama
  dipilih. `registerModel()` di dalam file itu **menimpa** deskriptor statis
  yang didaftarkan shell, jadi `doRebuild()` selalu mengambil entri terbaru
  sebelum memanggil `buildChain()`.
- Model bawaan: `anime4k` (bundle vendor), `meelscale` (resampler lokal) dan
  `meelsharp` — **MEeLSharp** (Lanczos-3 + CAS, tanpa bobot). `meelvision` —
  **MEeLVision** (bobot di `assets/models/meelvision/weights.js`) masih ada
  filenya, tetapi sudah tidak didaftarkan di menu AI Upscaler.

#### Catatan model: MEeLVision (arsitektur FSRCNN) & MEeLScale

Keduanya model lokal di `assets/models/<id>/` — tanpa unduhan internet.
MEeLVision sudah tidak tampil di menu (deskriptornya dihapus dari `upscaler.js`);
catatan di bawah tetap berlaku untuk filenya.

**MEeLVision ×2** (`meelvision/model.js` + `meelvision/weights.js`)

- Nama produk **MEeLVision** (id `meelvision`, folder `assets/models/meelvision/`);
  istilah arsitektur tetap FSRCNN. Bobot = base64 `Float32Array(13163)` di
  `window.MEEL_VISION_WEIGHTS_B64`, dilatih lokal dengan trainer vanilla JS
  yang tidak ikut repo (regenerasi: `node scripts/train-meelvision.js` pada
  checkout lokal).
- Arsitektur: input LR (dikurangi 0.5) → conv1 5×5 pad2 3→24 + PReLU →
  shrink 1×1 24→16 + PReLU → 3× map 3×3 pad1 16→16 + PReLU → deconv 9×9
  stride2 fase (16→3) + bias 0.5 → clamp 0..1.
- Offset dalam blob (konstanta `OFF` di `model.js` wajib identik):
  `c1w 0, c1b 1800, c1p 1824, sw 1848, sb 2232, sp 2248, m1w 2264, m1b 4568,
  m1p 4584, m2w 4600, m2b 6904, m2p 6920, m3w 6936, m3b 9240, m3p 9256,
  dw 9272, db 13160`.
- Eksekusi bertile (T=512 px LR, halo 5/sisi) agar jejak memori antara tetap
  kecil berapa pun resolusi video; 16 kanal antara = 4 tekstur rgba16float
  per lapisan. Tiap tile = 5 render pass (P1 conv1+shrink terfusion, P2–P4 map,
  P5 deconv ke tekstur keluaran 2× dengan scissor) dan urutannya **tile-major**:
  rantai P1→P5 satu tile selesai dulu karena tekstur antara dipakai bersama.
- Semantik tepi identik dengan trainer CPU: baca di luar batas frame di-skip
  (zero-pad).
- Evaluasi (n=150, patch 64×64 HR, 1500 step): PSNR **32.409 dB** (bicubic
  32.390 dB, bilinear 31.308 dB).

**MEeLScale** (`meelscale/model.js`)

- Resampler separable tanpa bobot. `buildChain()` memilih jalur termurah dari
  selisih `target` vs `native`:

  | Selisih | Rantai pass |
  | ------- | ----------- |
  | `target == native` | **CAS saja** (resample dilewati — satu pass) |
  | hanya lebar beda | horizontal → CAS |
  | hanya tinggi beda | vertikal → CAS |
  | kedua sumbu beda | horizontal → vertikal → CAS (teksel antara `rgba16float`) |

  Tiap node memakai `destroy()` untuk membebaskan tekstur miliknya.
- Konvensi koordinat `src = pos * scale - 0.5` dengan `pos` = pusat piksel
  hasil (`@builtin(position)` sudah +0.5), sehingga pada `scale 1` hasil
  identik dengan sumber. Rumus lama `(pos + 0.5) * scale - 0.5` menambahkan
  geseran **setengah piksel** yang tampak sebagai blur pada resolusi native;
  konvensi yang sama ikut diperbaiki di `meelsharp/model.js`.
- Mode = nama algoritma (`bilinear`, `mitchell` B=1/3 C=1/3, `catrom` B=0
  C=1/2, `lanczos2`, `lanczos3`). Saat mengecilkan resolusi tap diperluas:
  `rx/ry = clamp(scale, 1, 8)`, jendela tap dibatasi 63 tap/sumbu, tepi
  clamp-to-edge lalu hasil dibagi total bobot (bila `wsum ≤ 0` jatuh ke sampel
  terdekat, bukan piksel hitam).
- Pass terakhir **CAS** (FidelityFX milik AMD, lisensi MIT — atensi dipertahankan
  di header file) selalu aktif dengan `SHARPNESS 0.45`, termasuk pada jalur 1:1,
  sehingga target native tetap mendapat penajeman adaptif.

#### Pipeline render & backpressure

- Hanya ada satu submit GPU *in-flight*: `renderFrame()` menahan submit
  selama `onSubmittedWorkDone()` sebelumnya belum selesai (`loopBp.busy`);
  frame yang dilewati ditandai `pendingRender` dan di-*catch-up* begitu GPU
  selesai. Tanpa aturan ini antrian menumpuk → GPU idle mendadak → video berhenti.
- Timeout antrian 3 detik sebanyak 3 kali berturut-turut → upscale dimatikan +
  toast. Bila `queue.onSubmittedWorkDone` tidak tersedia, jalur berjalan tanpa
  backpressure (bukan error).
- Metrik (jendela 120 sampel) dibaca lewat `MEEL_UPSCALER.diagnose().perf`
  atau `stats().perf` → `{msAvg, msP95, videoFps, renderFps, budgetMs,
  fitsBudget, samples, skipped, timeouts}`; `fitsBudget === null` berarti
  belum ada sampel.
- `stats()` juga memantau state loop (`loopPending`, `loopRvfc`, `loopRaf`,
  `pendingRender`, `gpuBusy`) dan `rebuildReason`
  (`enable|model|mode|scale|resolution|resize|attach`) untuk membedakan
  rebuild normal dari loop yang macet.

#### Anti-hitam, debounce resolusi, watchdog rVFC

- **Transisi anti-hitam:** class `meel-upscale-active` (menyembunyikan video
  asli) hanya dipasang `renderFrame()` setelah frame baru sukses, dan dilepas
  saat rebuild mulai serta selama debounce resolusi. Jangan memasang class ini
  di tempat lain.
- **Debounce resolusi 600 ms:** ganti kualitas HLS bisa membuat `videoWidth`
  berubah beberapa kali sebelum stabil; selama menunggu copy lintas ukuran
  dilewati (`skipped`) dan video asli tampil. Metadata yang belum siap menunda
  timer, bukan menyerah. Ukuran yang kembali sama cukup melanjutkan loop.
- **Watchdog rVFC tiap 500 ms:** Chrome membuang `requestVideoFrameCallback`
  saat transisi hidden↔visible atau saat `load()`; bila pemicu pertama jatuh,
  `loop.pending` mentok `true` dan canvas beku walau video terus bermain.
  Watchdog mendaftarkan ulang callback bila tab aktif, video bermain, tapi tak
  ada tick >700 ms. `loadstart` me-reset `loop.pending`, `loadeddata` men-kick
  loop kembali.

#### Recovery saat tab di-background

- `triggerPlayerRecovery()` menunda pemulihan sampai `visibilitychange` →
  aktif (aturan cooldown tetap berlaku untuk pemulihan tertunda), autoplay
  retry (`pendingPlayRetry`) menunggu tab aktif, dan `RecoveryManager`
  me-reset baseline `lastTime`/`lastTs` saat hidden — durasi ter-hidden tidak
  dihitung sebagai "stuck".
- Tanpa gate itu `play()` ditolak senyap oleh browser dan video freeze ketika
  user kembali ke tab.

#### Catatan CSP `blankVideo`

- Plyr memanggil `cancelRequests()` dengan `blankVideo` bawaan
  `https://cdn.plyr.io/static/blank.mp4`; URL eksternal itu melanggar
  `media-src 'self' data: blob:` dan memicu `MEDIA_ELEMENT_ERROR` berantai
  setelah `player.destroy()`.
- `assets/js/shared/plyr-config.js` menyetel `blankVideo` ke data URI lokal
  (berlaku untuk video, musik, dan audio). **Jangan kembalikan URL CDN** dan
  jangan melonggarkan CSP.

### Proses yang Perlu Dipahami

1. **Upload Pipeline** — Uploader → FFmpeg → HDD → DB
2. **Download Pipeline** — URL → yt-dlp → FFmpeg → HDD → DB
3. **Auth Flow** — Login → Session → RBAC → Activity Log
4. **HTMX Flow** — Event → Request → Server → Response → DOM swap
5. **MFA Flow** — Login password valid → Cek mfa_enabled → Redirect auth/mfa-verify → Verify TOTP → Set session penuh
6. **Sesi Music Player & Resume** — Tap kartu/playlist → mini-player (set `skip_resume_once`) → expand → watch (konsumsi flag, aktifkan marker sesi) → auto-continue; kunjungan dingin menampilkan resume-modal
7. **Video Upscale & Recovery** — toggle → lazy-load model → rebuild pipeline → satu submit in-flight (tunggu GPU, frame dilewati di-catch-up); ganti kualitas di-debounce 600 ms tanpa frame hitam; recovery & autoplay ditunda sampai tab aktif

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
