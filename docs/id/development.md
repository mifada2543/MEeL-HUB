# 👨‍💻 Panduan Development & Kontribusi

Panduan untuk pengembang yang ingin berkontribusi atau memahami standar koding di MEeL-HUB.

---

## Daftar Isi

- [Lingkungan Development](#lingkungan-development)
- [Standar Koding](#standar-koding)
- [Struktur Database](#struktur-database)
- [Coding Conventions](#coding-conventions)
- [Catatan `.htaccess` & Route Legacy](#catatan-htaccess--route-legacy)
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

Dipakai library video & music, hasil search, dan sidebar rekomendasi watch — menggantikan tombol "Muat Lebih Banyak" lama:

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
  `#load-more-music-search`) — CSS `overflow-anchor: none` dan guard
  `isFromLoadMore` di `assets/js/music/index/index.js` bergantung padanya.
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
