# 🔒 Sistem Keamanan MEeL

Dokumentasi tentang sistem keamanan, autentikasi, otorisasi, dan proteksi yang ada di MEeL-HUB.

---

## Daftar Isi

- [Arsitektur Keamanan](#arsitektur-keamanan)
- [Role-Based Access Control (RBAC)](#role-based-access-control-rbac)
- [Session Management](#session-management)
- [Multi-Factor Authentication (MFA)](#multi-factor-authentication-mfa)
- [CSRF Protection](#csrf-protection)
- [IP Banning & Firewall](#ip-banning--firewall)
- [Activity Logging](#activity-logging)
- [API Rate Limiting](#api-rate-limiting)
- [File Upload Security](#file-upload-security)
- [Apache .htaccess Protection](#apache-htaccess-protection)
- [Proteksi SSRF (Advanced Upload)](#proteksi-ssrf-advanced-upload)
- [Proteksi Private Drive](#proteksi-private-drive)
- [Exception Handling](#exception-handling)
- [Disk Space Validation](#disk-space-validation)
- [Input Validation](#input-validation)
- [Pemeriksa Keamanan](#pemeriksa-keamanan)
- [Akuntansi MEeLCoin](#akuntansi-meelcoin)

---

## Arsitektur Keamanan

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
│  • Session timeout (12 jam)                             │
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
│  • Backup codes untuk recovery                          │
│  • Brute-force protection (10 attempts → 5 menit lock)  │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              7. Prepared Statements                     │
│  • All database queries use mysqli prepared statements  │
│  • No raw SQL concatenation with user input             │
└─────────────────────────────────────────────────────────┘
```

### Aturan Dasar yang Berlaku

| Area | Aturan |
|---|---|
| Deteksi lingkungan | `MEEL_ENV` hanya dibaca dari `REMOTE_ADDR` — tidak pernah dari header kiriman klien. `install.sh` menulis `UseCanonicalName On`; kalau `MEEL_ENV` perlu dipaksa, setel eksplisit di `auth/settings.php`. |
| Resolusi path | Semua `require`/`include` dan `filemtime()` memakai `__DIR__`, bukan path relatif ke working directory. Atribut `href`/`src` dibiarkan — itu URL yang diselesaikan browser. |
| Pemuatan konfigurasi | `auth/config.php` dimuat `require_once`. Seluruh gerbang otorisasi drive berbagi `drive/bootstrap.php`. |
| Alur secret | `meel_same_origin_referer()` menerima referer hanya bila host-nya termasuk `meel_trusted_hosts()`: `MEEL_CANONICAL_HOST`, host loopback (`localhost`, `127.0.0.1`, `::1` — selalu dipercaya karena hanya menunjuk ke mesin sendiri), dan `MEEL_TRUSTED_HOSTS`. Skema selain http/https dan URL protocol-relative ditolak. `HTTP_HOST` sengaja tidak dipakai sebagai allowlist: itu header kiriman klien, jadi bisa dipalsukan (`Host: evil.com`) dan membuka open redirect. Dipakai `introduction.php`, `err/index.php`, `music/playlist_action.php`, `profile/notification.php`, `profile/index.php`, `auth_back_url()`, `safe_comment_back_url()`, dan `meel_back_url()`. |
| Pemeriksaan stream | `drive/stream.php` memanggil `authorize()`; pemeriksa membacanya beserta seluruh rantai `include`-nya lewat `sourceWithIncludes()` di `tests/helpers.php`. Polanya `->authorize()`. |

---

## Role-Based Access Control (RBAC)

### Definisi Role

| Role | Level | Hak Akses |
|---|---|---|
| **Admin** | 100 | Kontrol penuh sistem |
| **Member** | 50 | Media + Cloud Drive (quota 20GB) |
| **User** | 30 | Media + komentar (tanpa Drive) |
| **Guest** | 0 | View-only, tanpa interaksi |

### Implementasi di Code

**Auth Middleware (`auth/auth.php`):**
```php
// Proteksi halaman - redirect jika belum login
if (!isset($_SESSION['user_id'])) {
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header("Location: ../auth/login?next={$next}");
    exit;
}
```

**Role Check Pattern:**
```php
// Cek role admin
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_role = $stmt->get_result()->fetch_assoc()['role'] ?? 'user';

if ($user_role !== 'admin') {
    header("Location: ../?error=ditolak");
    exit();
}
```

**Guest Blocking (Like/Dislike):**
```php
// Di like.php dan MediaViewer.php
if ($user['is_active'] != 1 || $user['role'] === 'guest') {
    http_response_code(403);
    exit;
}
```

**Drive Access Control (`DriveService.php`):**
```php
final class DriveUserContext {
    public function authorize(): void {
        if (!$this->isAllowedRole()) {
            header('Location: ' . meel_base_url_path() . '/err?code=denied', true, 302);
            exit;
        }
    }
    
    public function isAllowedRole(): bool {
        return in_array($this->role, ['admin', 'member'], true);
    }
}
```

### Admin Page Guard (`require_admin()`)

Semua halaman admin menggunakan `require_admin($conn)` sebagai gate otorisasi terpusat:

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

User non-admin di-redirect ke `/err?code=not_found` (HTTP 404) alih-alih menampilkan halaman 403 "Access Denied". Ini adalah pola **security-by-obscurity** — user yang tidak berizan melihat halaman "Not Found" generik, menyembunyikan keberadaan halaman admin-only.

Pola redirect yang sama digunakan di:
- `controllers/admin/admin_actions.php` — guard `MEEL_ADMIN_CONTEXT` + guard `is_admin()`
- `controllers/admin/admin_data.php` — guard `MEEL_ADMIN_CONTEXT`

### Feature Gating per Role

| Fitur | Admin | Member | User | Guest |
|---|---|---|---|---|
| Nonton Video | ✅ | ✅ | ✅ | ✅ |
| Dengar Musik | ✅ | ✅ | ✅ | ✅ |
| Like/Dislike | ✅ | ✅ | ✅ | ❌ |
| Komentar | ✅ | ✅ | ✅ | ❌ |
| Upload Video | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Upload Musik | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Books | ✅ | ✅ | ✅ | ❌ |
| Cloud Drive | ✅ (unlimited) | ✅ (20GB) | ❌ | ❌ |
| Advanced Upload | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Transcoder | ✅ | ✅ | ✅ | ❌ |
| Admin Panel | ✅ | ❌ | ❌ | ❌ |
| Chess Multiplayer | ✅ | ✅ | ✅ | ❌ |

### Sumber Kebenaran Role

Database adalah sumber kebenaran. `$_SESSION['role']` hanya cache tampilan — badge dan link "edit" boleh membacanya, tapi gerbang selalu memeriksa ulang ke DB. Semua pemanggil memakai `is_admin($conn)` atau `require_admin($conn)`; `update.php`, `profile/manage.php`, dan `controllers/system/UpdateManager.php` tidak lagi membaca `$_SESSION['role']` langsung.

`UpdateManager` adalah jalur **tulis** untuk `sidebar_settings` dan `updates`, yang dirender ke setiap pengunjung anonim.

### Otorisasi Sumber Daya (IDOR)

Kepemilikan dicek di **lapisan data**, bukan di pemanggil, supaya pemanggil berikutnya tidak bisa lupa.

| Operasi | Aturan |
|---|---|
| Hapus playlist | Cek kepemilikan dulu, baru hapus baris `playlist_tracks` lewat `JOIN`, lalu periksa `affected_rows`. `DELETE` yang di-scope `user_id` tetap sukses pada 0 baris tanpa memberi tanda. |
| Baca antrean playlist | `getPlaylistQueue()` punya gate kepemilikan — tanpa itu, `?playlist_id=N` di halaman watch yang publik membocorkan antrean user lain. |

---

## Session Management

### Session Configuration

```php
// modules/auth/helpers/session.php — meel_boot_session()
// (auth/config.php & auth/auth_helpers.php mendelegasikan ke fungsi ini)
$timeout = 43200;              // 12 jam
ini_set('session.gc_maxlifetime', $timeout);

// Flag cookie aman (auto-detect HTTPS / X-Forwarded-Proto)
$secure_cookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

session_set_cookie_params([
    'lifetime' => $timeout,
    'path'     => '/',
    'secure'   => $secure_cookie,  // hanya via HTTPS
    'httponly' => true,            // tidak bisa dibaca JavaScript
    'samesite' => 'Lax',           // mitigasi CSRF
]);
session_name('meel');           // Cookie name: "meel"
session_start();
```

| Flag | Nilai | Proteksi |
|---|---|---|
| `Secure` | auto (HTTPS) | Cookie tidak pernah terkirim lewat HTTP polos — cegah sniffing |
| `HttpOnly` | `true` | XSS tidak bisa mencuri session cookie via JavaScript |
| `SameSite` | `Lax` | Request POST lintas-situs tidak membawa cookie (CSRF layer 1) |

Konfigurasi di atas kini **dipusatkan** di `modules/auth/helpers/session.php` sebagai fungsi
`meel_boot_session()` — satu-satunya sumber kebenaran inisialisasi session. Semua entry point
(index, video, music, auth, controllers/api, err, admin) memanggilnya menggantikan pola lama
`session_name('meel'); session_start();` yang tersebar di banyak file, sehingga cookie sesi
dijamin selalu memakai flag aman. Fungsi ini **idempotent** (no-op jika session sudah aktif),
`auth/config.php` dan `auth_boot_session()` di `auth/auth_helpers.php` kini mendelegasikan
ke fungsi ini:

```php
// Cara pakai di entry point baru
require_once __DIR__ . '/../modules/core/helpers.php';
meel_boot_session();
```

### Session Hijacking Prevention

**Mechanism:** Setiap user memiliki `last_session_id` di database. Jika session ID di browser berbeda dengan yang di database, session dianggap dibajak/ditendang.

```php
// auth/config.php - Timeout check
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];
    if ($elapsed_time > $timeout) {
        session_unset();
        session_destroy();
        header("Location: ../auth/login?reason=expired");
        exit;
    }
}
$_SESSION['LAST_ACTIVITY'] = time();

// activity_logger.php - Kick detection (Header Redirect)
if ($current_dir !== 'err') {
    if ($user_status && $user_status['role'] !== 'admin') {
        if (!empty($user_status['last_session_id']) && $user_status['last_session_id'] !== $current_sid) {
            session_unset();
            session_destroy();

            $root_dir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
            $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
            $relative_base = rtrim('/' . ltrim(str_replace($doc_root, '', $root_dir), '/'), '/');
            $revoked_url = $relative_base . '/err/?code=revoked';
            header("Location: " . $revoked_url);
            exit();
        }
    }
}
```

### Admin Kick Feature

Admin dapat menendang paksa user dari admin panel:
```php
// controllers/fun.php
$stmt_kick = $conn->prepare("UPDATE users SET 
    last_session_id = 'KICKED', 
    last_page = 'KICKED BY ADMIN', 
    last_activity = DATE_SUB(NOW(), INTERVAL 10 MINUTE) 
    WHERE username = ?");
$stmt_kick->bind_param("s", $target_username);
```

### Login Tidak Membocorkan Keberadaan Akun

Semua kegagalan login memakai **pesan yang sama** — tidak ada lagi pesan berbeda untuk "akun ada tapi tidak aktif" versus "username atau password salah".

`password_verify` Boneka dijalankan terhadap hash statis ketika user tidak ditemukan, sehingga waktu respons tidak memisahkan kedua cabang.

---

## Multi-Factor Authentication (MFA)

### Arsitektur MFA

```
Login Flow:
  POST login → password benar
    ↓
  Cek users.mfa_enabled == 1?
    ↓ Ya                             ↓ Tidak
  Simpan mfa_temp_uid ke session    Set session langsung
    ↓                                ↓
  Redirect ke auth/mfa-verify       Redirect ke index
```

### TOTP Implementation

MEeL menggunakan **TOTP (Time-based One-Time Password)** dengan algoritma:
- **HMAC-SHA1** — standard TOTP
- **6 digit** — kode verifikasi
- **30 detik** — time step
- **Window ±1** — toleransi 90 detik

### Secret Generation

```php
// modules/auth/helpers/mfa.php
function generate_mfa_secret(): string {
    $random = random_bytes(20);  // 160-bit random
    $base32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    foreach (str_split($random) as $byte) {
        $secret .= $base32[ord($byte) & 31];
        // XOR carry untuk 5-bit encoding
    }
    return $secret;
}
```

Secret disimpan di kolom `mfa_secret` (VARCHAR(64)) di tabel `users`, di-hash? **Tidak** — secret TOTP harus bisa dibaca untuk verifikasi. Namun, jika database bocor, attacker butuh akses ke Google Authenticator atau backup codes untuk login.

### Backup Codes

- **8 backup codes** — masing-masing 6 digit angka
- **Disimpan sebagai hash `password_hash()` (bcrypt)** — tidak bisa dibaca balik
- **Sekali pakai** — setelah digunakan, hash dihapus dari daftar

```php
function generate_backup_codes(): array {
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < 8; $i++) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); // 6 digit
        $plain[] = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT); // bcrypt
    }
    return ['plain' => $plain, 'hashed' => $hashed];
}

function verify_backup_code(string $hashedCodes, string $code): array {
    $codes = json_decode($hashedCodes, true) ?? [];
    foreach ($codes as $i => $hash) {
        if (password_verify($code, $hash)) {
            array_splice($codes, $i, 1); // Hapus yang sudah dipakai
            return ['valid' => true, 'remaining' => $codes];
        }
    }
    return ['valid' => false, 'remaining' => $codes];
}
```

### Brute-Force Protection

```php
// Max 10 percobaan MFA gagal, lock 5 menit
$max_mfa_attempts = 10;
$mfa_lockout_time = 300; // 5 menit

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

Admin dapat mereset MFA user dari halaman `admin/mfa_reset.php`:
- **Metode:** Form POST (bukan link GET) — mencegah CSRF via tag `<img>`
- **Tidak bisa reset admin lain** — hanya admin yang bersangkutan bisa menonaktifkan sendiri
- **Aksi dicatat** — `log_activity($conn, $admin_id, 'reset_mfa', 'user', $target_id)`
- **User perlu setup ulang** — MFA di-reset ke default (nonaktif)

### MFA di Profile

Halaman `profile/index.php` menampilkan status MFA dengan toggle switch visual:
- Hijau "Aktif" → link ke `auth/mfa-setup` untuk kelola/disable
- Abu-abu "Nonaktif" → link ke `auth/mfa-setup` untuk setup
- Jika aktif, backup codes juga ditampilkan di profil

### Akses Profil Guest

Guest (pengguna tanpa autentikasi) bisa melihat halaman profil pengguna lain tanpa login. Halaman profil menggunakan `meel_boot_session()` (bukan `session_start()` mentah) untuk memastikan nama cookie session cocok dengan seluruh aplikasi (`meel`).

- **Guest melihat profil sendiri:** Profil sintetis dengan `id=0`, `role='guest'`, bio="Akun Guest" — theme toggle tersedia
- **Guest melihat profil lain:** Profil nyata dari DB — bio, statistik, badge ditampilkan dengan benar; tidak ada tombol Edit/Manage/MFA; tidak ada theme toggle
- **User login melihat profil sendiri:** Akses penuh — Edit Profile, Kelola Konten, MFA, Backup Codes, Theme Toggle

Inisialisasi session menggunakan `meel_boot_session()` untuk mengatur `session_name('meel')` dan cookie flags yang di-hardened. Menggunakan `session_start()` mentah akan mencari cookie `PHPSESSID` bukan `meel`, menyebabkan session terlihat kosong.

### Menonaktifkan MFA

Menonaktifkan MFA mewajibkan `password_verify` **dan** kode TOTP yang sedang berlaku — pola yang sama dengan reset kode cadangan. Seed TOTP tidak pernah dirender ulang untuk sesi mana pun yang login.

### Anti-Replay

`verify_totp()` memakai jendela ±1 time-step, jadi satu kode sah sekitar 90 detik. Anti-replay-nya:

- `verify_totp_step()` mengembalikan time-step yang cocok (bukan `bool`)
- `auth/mfa_verify.php` menyimpannya ke `users.mfa_last_step`
- pembaruan memakai `UPDATE ... WHERE mfa_last_step IS NULL OR mfa_last_step < ?` — dua permintaan bersamaan dengan kode sama tidak bisa dua-duanya lolos

`verify_totp()` tetap ada sebagai pembungkus aman untuk pemanggil biasa. Kalau kode cadangan yang dipakai, step di-reset agar kode TOTP berikutnya tidak tertolak.

### Limiter

Bucket `mfa` (10 permintaan / 300 detik) dan `login` (10 / 300) pada `RateLimiter`. `auth/mfa_verify.php` memanggilnya dengan kunci `mfa:<user_id>` **sebelum** memverifikasi kode, jadi batasnya berlaku lintas sesi — membuang cookie tidak meresetnya.

---

## CSRF Protection

### Token Generation

```php
// auth/config.php
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
```

### Token Validation

```php
// verify_csrf_token() — didefinisikan di modules/auth/helpers/csrf.php
// Menggunakan hash_equals() untuk timing-attack safety
function verify_csrf_token(?string $token = null): bool
{
    if ($token === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}
```

### Implementation in Forms

```php
// Setiap form POST harus menyertakan token
<input type="hidden" name="csrf_token" 
       value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
```

### HTMX Integration

```php
// Untuk HTMX POST requests
$token = $_SESSION['csrf_token'];
echo "<input type='hidden' name='csrf_token' value='$token'>";
```

### Admin Actions — Form POST (bukan link GET)

Aksi admin yang mengubah state (approve/reject/delete user, kick user, unban IP, force-stop queue, MFA reset)
menggunakan **form POST dengan token CSRF** — link GET bisa
dipicu oleh tag `<img>` (CSRF), form POST tidak:

```html
<form method="POST" class="inline" onsubmit="return meelConfirmForm(event, {...})">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="approve_id" value="<?= (int)$u['id'] ?>">
    <button type="submit">APPROVE</button>
</form>
```

Handler di `controllers/admin/admin_actions.php` kini membaca `$_POST`, dan
endpoint catur admin `catur.php?auto_cleanup=1` juga wajib `csrf_token`
(bridge `window.MEEL_ADMIN_CSRF`).

> ⚠️ **Form action tidak boleh menggunakan `action="."`** — ketika URL halaman adalah `/admin/beranda`,
> `action="."` resolve ke `/admin/`, yang mendapat 301 redirect ke `/admin/beranda` oleh
> rule canonical `.htaccess`. 301 mengubah POST menjadi GET, menghilangkan semua data form.
> Gunakan tanpa atribut `action` (default = URL halaman saat ini) atau `action=""`.

### Endpoint API — POST Enforcement + CSRF

Semua endpoint API yang mengubah state kini mewajibkan **metode POST** dan **verifikasi CSRF token**:

| Endpoint | CSRF | Metode | Catatan |
|---|---|---|---|
| `api/notification.php` (mark_read, delete, delete_all) | `$_POST['csrf_token']` | POST only | GET return 405 |
| `api/chat.php` (send, delete) | `$_POST['csrf_token']` | POST only | Admin-only, GET return 405 |
| `api/delete_comment.php` | `$_POST['csrf_token']` | POST only | GET return 405 |
| `api/like.php` | `hx-vals` csrf_token | POST (HTMX) | — |
| `api/comment.php` | `$_POST['csrf_token']` | POST (HTMX) | — |

Endpoint read-only (`unread_count`, `list`, `users`, `get`) tetap bisa diakses via GET.

### Chess Multiplayer — Guard Login + CSRF

Semua endpoint `arcade/chess/controller/*.php` mewajibkan:
- **Login** — JSON `401` + `login_required: true` (client `arcade/chess/assets/js/api.js` redirect ke login)
- **CSRF** — setiap POST yang mengubah state membawa `csrf_token` (body JSON untuk `save_move`, `FormData` untuk `create_room`/`join_room`)
- Token **tidak pernah disimpan** di `moves.move_data` (tidak ter-expose ke lawan)

### Token Tidak Berada di URL

Token CSRF tidak pernah ada di query string — di sana ia ikut masuk ke riwayat browser, access log Apache, dan header `Referer` halaman berikutnya. Penghapusan media memakai POST dengan token di body form, handler menolak metode lain, dan `confirmHapus()` mengirim form alih-alih navigasi.

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
        // X-Forwarded-For (hop pertama)
        if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $xff = trim(explode(',', $_SERVER["HTTP_X_FORWARDED_FOR"])[0]);
            if ($valid($xff)) return $xff;
        }
    }
    $remote = $_SERVER["REMOTE_ADDR"] ?? '0.0.0.0';
    return $valid($remote) ? $remote : '0.0.0.0';
}
```

### Gerbang Trusted Proxy (`MEEL_TRUST_PROXY_HEADERS`)

Header proxy **hanya** boleh dipercaya jika request lewat proxy/CDN yang Anda kendalikan. Konfigurasi di `auth/settings.php`:

```php
// WAJIB true jika menggunakan Cloudflare Tunnel, Nginx reverse proxy, atau CDN
define('MEEL_TRUST_PROXY_HEADERS', true);
```

> Jika diset `true` padahal server diakses langsung, attacker bisa memalsukan `X-Forwarded-For` untuk mem-bypass IP-ban atau membanjiri activity log.

> **Behind cloudflared:** Tunnel selalu menghubungkan ke Apache dari localhost (`REMOTE_ADDR = 127.0.0.1`). Tanpa `MEEL_TRUST_PROXY_HEADERS = true`, semua IP terdeteksi sebagai localhost, rate limiting lumpuh, dan environment terdeteksi sebagai "development".

### Auth IP Helpers

`auth_get_ip()` dan `auth_is_loopback()` di `auth/auth_helpers.php` juga menggunakan `get_real_ip()` — bukan `$_SERVER['REMOTE_ADDR']` langsung. Ini memastikan IP detection konsisten di seluruh sistem (login, rate limiting, ban check).

### IP Validation

```php
function validate_and_format_ip($ip) {
    // Local access detection
    if (strpos($ip, '127.') === 0 || $ip === '::1' || $ip === 'localhost') {
        return ['ip' => 'LOCAL', 'display' => 'Local Access', 'is_local' => true];
    }
    // IPv4-mapped IPv6
    if (strpos($ip, '::ffff:') === 0) {
        // Extract IPv4 part
    }
    // IPv6 validation
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { ... }
    // IPv4 validation
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { ... }
}
```

### Ban Check (Real-time)

```php
// Di activity_logger.php - dijalankan di setiap halaman (Header Redirect)
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));
if ($current_dir !== 'err') {
    if ($ban_res->num_rows > 0) {
        // Jika bukan admin, baru di-redirect
        if ($session_role !== 'admin') {
            $row = $ban_res->fetch_assoc();
            $root_dir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
            $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
            $relative_base = rtrim('/' . ltrim(str_replace($doc_root, '', $root_dir), '/'), '/');
            $banned_url = $relative_base . '/err/?code=banned';
            header("Location: " . $banned_url . "&reason=" . urlencode($row['reason']));
            exit();
        }
    }
}
```

### Admin Ban Interface

Admin dapat memban IP via form di `admin/index.php`:
```php
$stmt = $conn->prepare("INSERT IGNORE INTO ip_ban (ip_address, reason) VALUES (?, ?)");
$stmt->bind_param("ss", $ip_to_ban, $reason);
```

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

Mencatat aktivitas user ke tabel `activity_log` dengan prepared statement. Null `$media_id` ditangani dengan query terpisah agar NULL tersimpan di database (bukan 0).

### Yang Dicatat (Terintegrasi)

`log_activity()` sudah terintegrasi langsung di berbagai entry point aplikasi:

| Event | Aksi | Lokasi Integrasi |
|---|---|---|
| Login sukses | `login` | `auth/login.php` |
| Logout | `logout` | `auth/logout.php` |
| Upload video | `upload_video` | `video/upload.php` |
| Upload musik | `upload_music` | `music/upload.php` |
| Upload buku | `upload_book` | `books/upload.php` |
| Download URL | `upload_url` | `upload_advanced.php` |
| Ban IP | `ban_ip` | `controllers/admin/admin_actions.php` |
| Unban IP | `unban_ip` | `controllers/admin/admin_actions.php` |
| Approve user | `approve_user` | `controllers/admin/admin_actions.php` |
| Reject user | `reject_user` | `controllers/admin/admin_actions.php` |
| Delete user | `delete_user` | `controllers/admin/admin_actions.php` |
| Kick user | `kick_user` | `controllers/admin/admin_actions.php` |

### Admin Activity Log Viewer

Halaman `admin/activity_log.php` menyediakan viewer khusus untuk audit trail dengan 3 tab:

| Tab | Tema | Fitur |
|---|---|---|
| 📋 **Activity** | Biru-600 | Filter berdasarkan tipe aksi, username/IP, rentang waktu (7–365 hari); pagination (50/halaman); stats cards (aktivitas 7 hari, user unik, total entri); badge aksi berwarna (login=biru, upload=hijau, ban=merah); cleanup manual dengan CSRF |
| 🛡️ **Admin Actions** | Ungu-600 | Filter berdasarkan username admin, tipe aksi, rentang waktu; stats cards (aksi admin 7 hari, admin unik, total entri); badge berwarna (coin=kuning, reset=merah, login=biru, lainnya=abu-abu); maintenance: hapus yang lebih lama dari 7–365 hari |
| 📤 **Upload Queue** | Hijau-600 | Filter berdasarkan status (pending/processing/transcoding/completed/failed), uploader, rentang waktu; stats cards (total upload, selesai, gagal, aktif); badge status berwarna; export CSV/JSON/XLS dengan preview modal; maintenance: hapus yang selesai/gagal lebih lama dari 7–365 hari |

### Live Activity Monitor

Selain `activity_log`, admin dashboard juga menampilkan aktivitas user real-time via tabel `users`:

```php
$result_monitor = $conn->query(
    "SELECT username, role, last_activity, last_page, 
            user_agent, access_via, ip_address 
     FROM users ORDER BY last_activity DESC LIMIT 10"
);
```

Detil yang ditampilkan:
- Status online/offline (300 detik threshold)
- Halaman terakhir yang dikunjungi
- Tipe koneksi (Local/IPv4/IPv6/Cloudflare)
- Device type (Smartphone/PC/Mac)
- IP Address (dengan badge tipe)

---

## API Rate Limiting

### Arsitektur Rate Limiter

`modules/auth/RateLimiter.php` menyediakan **file-based rate limiter** yang melindungi API endpoint dari abuse:

```
Request → RateLimiter::check(key, endpoint)
  ↓
Baca file cache di temp/ratelimit/{md5_hash}.cache
  ↓
flock(LOCK_EX) → Increment counter → ftruncate + fwrite
  ↓
Counter > max? → Ya → HTTP 429 Too Many Requests
  ↓ Tidak
Allow request
```

### Storage

Menggunakan file JSON di `temp/ratelimit/` (tanpa schema DB tambahan):
- Setiap key+endpoint punya file sendiri (`md5(key_endpoint).cache`)
- Isi: `{"count": N, "window_start": timestamp}`
- Auto-cleanup via `GarbageCollector::run()` setiap request

### Endpoint Limits

| Endpoint | Max Request | Window | Respons pada Limit |
|---|:---:|:---:|---|
| **Like/Dislike** | 30 | 1 menit | HTTP 429 + HTMX HTML snippet (badge kuning "Wait Xs" + disabled buttons) |
| **Comment** | 10 | 1 menit | Redirect dengan flash error message |
| **Upload** (video/music/books) | 3 | 1 jam | — |
| **Transcode** | 5 | 1 jam | — |
| **Auto Metadata** (ffmpeg) | 5 | 1 jam | HTTP 429 + JSON error |
| **API Generic** | 60 | 1 menit | — |

### Fail-Closed Behavior

Ketika direktori penyimpanan rate limiter (`temp/ratelimit/`) tidak writable atau `flock()` gagal, rate limiter **menolak semua request** (fail-closed) daripada diam-diam membiarkannya lewat. Ini mencegah filesystem yang rusak menonaktifkan rate limiting sepenuhnya. Kegagalan dicatat via `error_log()`.

### Integrasi

```php
// controllers/api/like.php — HTMX endpoint
$rateCheck = RateLimiter::check('user_'.$userId, 'like');
if (!$rateCheck['allowed']) {
    http_response_code(429);
    header('HX-Retarget: #like-dislike-container');
    // Return HTML dengan badge "⏱️ Wait Xs"
    exit;
}

// controllers/api/delete_comment.php — redirect-based
$rateCheck = RateLimiter::check('user_'.$userId, 'comment');
if (!$rateCheck['allowed']) {
    $_SESSION['error'] = 'Terlalu banyak request.';
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}

// controllers/api/WatchController.php — comment rate limit
$rateCheck = RateLimiter::check('user_'.$userId, 'comment');
if (!$rateCheck['allowed']) {
    $_SESSION['error'] = 'Terlalu banyak komentar.';
    header("Location: music/watch?v={$id}#comment-section");
    exit;
}
```

### Admin Monitoring

Rate limit status bisa dimonitor di admin dashboard:
- **Active Keys:** Jumlah key rate limit yang sedang aktif
- **Endpoints Protected:** 5 endpoint dengan limit berbeda

### Cleanup

File expired (>1 jam) otomatis dibersihkan oleh `GarbageCollector::run()` yang dipanggil di setiap request.

### Bucket yang Berlaku

| Bucket | Batas | Kunci |
|---|---|---|
| `mfa` | 10 / 300 detik | `mfa:<user_id>` — berlaku lintas sesi |
| `login` | 10 / 300 detik | per akun |
| `drive_files` | 10 file / jam | `DriveStorage::recordUpload()` mencatat setiap unggahan |

---

## File Upload Security

### Extension Validation

```php
// Video
$allowed_ext = ['mp4', 'webm', 'mkv'];
if (!in_array($ext, $allowed_ext, true) || 
    preg_match('/\.(php|phtml|sh)/i', $files['video']['name'])) {
    return ['status' => 'error', 'msg' => "Security Error / Format ditolak!"];
}

// Music  
$allowed_ext = ['mp3', 'opus', 'ogg', 'm4a', 'wav', 'flac'];
if (!in_array($ext, $allowed_ext, true) || 
    preg_match('/\.(php|phtml|sh)/i', $files['media']['name'])) {
    return ['status' => 'error', 'msg' => "Security Error / Format ditolak!"];
}
```

### File Size Limits

```php
// Admin: 200MB
$max_size = ($this->user_role === 'admin') ? 200 * 1024 * 1024 : 50 * 1024 * 1024;
```

### Magic Bytes Validation (Drive)

```php
// DriveService.php - validateFileByMagicBytes()
$header = fread($handle, 16);
if ($detectedType === 'video') {
    // WebM/MKV: \x1A\x45\xDF\xA3
    // MP4/MOV: ftyp
}
if ($detectedType === 'audio') {
    // MP3: 0xFFFB, FLAC: 0x664C6143, OGG: 0x4F676753
}
```

### Duration Limits

```php
// Video: admin unlimited, user max 5 menit
$max_dur = ($this->user_role === 'admin') ? 3600 : 300;
```

### Validasi Magic Bytes Terpusat (`meel_magic_extension_ok`)

Satu-satunya sumber kebenaran validasi signature file (server-side) — semua jalur upload (Uploader video/music, thumbnail, BookUploader, DriveStorage) memakainya, menggantikan cek inline yang diduplikasi:

```php
// modules/core/helpers/upload.php
meel_magic_extension_ok(string $path, string $ext, string $mediaKind): string
// mediaKind: 'audio' | 'video' | 'image' | 'pdf' | 'archive'
// return '' jika cocok, pesan error jika tidak
```

Jenis yang dikenali: Ogg/Opus, FLAC, WAV/RIFF, MP3 (ID3/frame sync), MP4/M4A (ftyp), Matroska/WebM, JPEG/PNG/WebP/GIF, PDF, dan arsip ZIP. Validasi tidak pernah mengandalkan `$_FILES['type']` atau ekstensi saja.

### Alokasi Nama File Atomik (anti race condition)

Nama file fisik tidak lagi dialokasikan dengan pola check-then-move (`while (file_exists(...))`). Semua nama media di-reserve **atomik** via `meel_reserve_unique_filename()` (placeholder `fopen(..., 'x')` / O_EXCL):

```php
// modules/core/helpers/upload.php — dipakai Uploader, EncodeService,
// admin editors, dan arcade rhythm
meel_reserve_unique_filename(string $dir, string $clean_name, string $ext): ?string
```

Dua request bersamaan tidak mungkin menerima nama yang sama; pemanggil menimpa placeholder dengan `move_uploaded_file()` / ffmpeg `-y`. Nama folder kerja video dialokasikan lewat helper serupa (`meel_allocate_unique_dir()`).

### Proteksi Arsip ZIP/CBZ (`ArchiveGuard`)

Ekstraksi manga/book **tidak pernah** memakai `ZipArchive::extractTo()` langsung ke folder final. `modules/media/ArchiveGuard.php` menjalankan pipeline validasi → staging → move:

```
buka arsip
  → inspeksi tiap entry (traversal, null byte, count, ukuran, rasio,
    kedalaman, symlink, ekstensi)
  → ekstrak satu-per-satu ke staging server-generated (nama acak)
  → validasi hasil
  → pindahkan ke folder final (rename; file yang sudah ada TIDAK ditimpa;
    symlink tidak pernah dipindahkan)
```

Limit dikonfigurasi via konstanta (default):

| Konstanta | Default | Arti |
|---|---|---|
| `MAX_ARCHIVE_ENTRIES` | 5000 | Jumlah entry maksimum |
| `MAX_ARCHIVE_UNCOMPRESSED_BYTES` | 2 GiB | Total ukuran setelah ekstraksi |
| `MAX_ARCHIVE_ENTRY_BYTES` | 200 MiB | Ukuran satu file |
| `MAX_ARCHIVE_COMPRESSION_RATIO` | 300 | Rasio kompresi per-entry (zip bomb) |
| `MAX_ARCHIVE_PATH_DEPTH` | 16 | Kedalaman direktori maksimum |

Ditolak: absolute path, `../`, `..\`, null byte, symlink, kedalaman berlebihan, entri non-gambar (hanya `jpg/jpeg/png/webp/gif/bmp/avif` untuk CBZ/manga), dan count/ukuran/rasio melebihi batas. Kejadian ditolak dicatat ke error log tanpa menyimpan isi arsip.

### Quota & Resource Limits

- **Disk pre-flight** — `require_disk_space()` menolak upload sebelum menulis byte pertama (music 500MB, video 1GB + `/dev/shm` 512MB)
- **Upload simultan** — maksimal 3 upload bersamaan (counter file ber-`flock` + TTL 5 menit + auto-decrement di shutdown)
- **Rate limit** — upload dibatasi per jam berbasis role (admin unlimited)
- **Arsip** — limit entri/ukuran/rasio di atas (anti zip bomb)
- **Transcode** — timeout audio 600s (`TRANSCODE_AUDIO_TIMEOUT`), HLS remux 120s, `stream_set_timeout(30)`, durasi hasil divalidasi ≥ 50% sumber

### Batas Unduhan

Perintah yt-dlp memakai `--max-filesize` dan `--match-filter "duration < N"`, dan `DownloadService::assertWithinDownloadLimits()` memeriksa metadata (`duration`, `filesize`/`filesize_approx`) sebelum unduhan dimulai. Nilainya `MEEL_MAX_DOWNLOAD_BYTES` (2 GB) dan `MEEL_MAX_DOWNLOAD_SECONDS` (7200), didefinisikan bersama konstanta batas upload lain di `modules/core/helpers/upload.php`.

### Klaim Slot Antrean

`System::claimQueueSlot()` membungkus pemeriksaan dan INSERT di dalam `GET_LOCK()` MySQL — satu Advisory Lock, jadi hanya satu pihak yang boleh memeriksa lalu inserting. Batasnya `System::MAX_CONCURRENT_QUEUES` (default 2).

### Artefak Job yang Sedang Jalan

`GarbageCollector::STALE_SECONDS` = **1200**, lebih besar dari `TRANSCODE_AUDIO_TIMEOUT` (600) dan `DOWNLOAD_TIMEOUT` (900). Marker `.processing` adalah satu-satunya pelindung tabrakan dua transcode atas output yang sama, jadi nilainya tidak boleh di bawah kedua batas timeout itu.

Antrean hapus tertunda dan batas ekspor admin didokumentasikan di **[Panduan Konfigurasi](configuration.md#antrean-hapus-tertunda)**.

---

## Apache .htaccess Protection

### Directori Protection

```apache
# auth/.htaccess - Block all direct access
Order Deny,Allow
Deny from all
<FilesMatch "^$|^[^.]+$">
    Allow from all
</FilesMatch>
```

Direktori yang diproteksi:
- `auth/` — Konfigurasi database & session
- `modules/` — Core business logic
- `partials/` — UI components (include-only)
- `admin/` — Admin panel (hanya index)
- `books/upload/` — File buku (disajikan via `books/file.php` — **wajib login**, lihat catatan di bawah)
- `music/upload/` — File musik
- `video/upload/` — File video

> **File upload tidak disajikan langsung oleh web server.** Sejak refactor portabilitas, `books/upload/`, `music/upload/`, dan `video/upload/` adalah folder nyata ter-track (placeholder `.gitkeep` + `.htaccess` hardening) yang isinya disajikan lewat endpoint PHP yang dipetakan internal rewrite di `.htaccess` root: `video/upload/...` → `video/stream.php`, `music/upload/...` → `music/file.php`, `books/upload/...` → `books/file.php` (lihat [Installation §5a](installation.md#5a-media-storage-meel_hdd_base--endpoint-php--rewrite-tanpa-symlink)). Semuanya — termasuk langkah penyajian akhir di `music/stream.php` — mendelegasikan ke satu fungsi bersama, `meel_serve_media_file()` di `modules/core/helpers/storage.php`, yang mengimplementasikan: containment realpath terhadap path traversal (`..`, null byte → `403`), whitelist ekstensi, MIME mapping otomatis, dukungan HTTP Range (`206 Partial Content`), pembersihan output buffer sebelum streaming, dan referer gate opsional untuk HLS. Bila `MEEL_USE_XSENDFILE` aktif, modul `mod_xsendfile` terpasang, dan file termasuk `XSendFilePath` di konfigurasi Apache, penyajian dialihkan ke header `X-Sendfile` (Apache mengirim file langsung dari disk; `Range`/`206` dihitung Apache dan PHP tidak membaca isi file sama sekali) — dengan fallback otomatis ke streaming PHP biasa bila salah satu syarat tidak terpenuhi, sehingga respons tidak pernah kosong. Syarat keempat adalah direktif `XSendFile on` yang berlaku untuk request tersebut (dibaca dari konfigurasi Apache **dan** `.htaccess` sepanjang jalur URL/direktori skrip, nilai paling spesifik menang; tanpa direktif dianggap `off`): bila Apache akan mengabaikan header (flag `off`), aplikasi tidak mengirimnya sama sekali — mencegah respons kosong sekaligus kebocoran path absolut server ke client. Catatan keamanan: seluruh gerbang (login, CSRF, `authorize()`, containment realpath, whitelist ekstensi, referer gate HLS) berjalan **sebelum** cabang X-Sendfile, sehingga mengaktifkan atau menonaktifkan X-Sendfile tidak mengubah model otorisasi.
>
> **`books/file.php` wajib login** (`auth/auth.php`), konsisten dengan seluruh modul Books — `index.php`, `read.php`, `read_pdf.php`, dan `controllers/api/pdf.php` semuanya login-gated. Tanpa ini, gambar manga dan PDF bisa diunduh langsung tanpa autentikasi (path mentah sebelumnya dapat diakses dengan `HTTP 200`). Audio musik tetap di belakang `music/stream.php` (marker session + referer gate ketat); `music/file.php` dan `video/stream.php` sengaja **tanpa** login gate karena halaman index/watch Music & Video memang publik by design (thumbnail harus tampil untuk pengunjung anonim). `video/stream.php` tambahan melewatkan `hls_gate => true` — request `.m3u8`/`.ts` harus membawa referer same-host dari halaman video (`/video`, `/video/watch`), jika tidak request di-redirect ke `err/?code=denied` (anti-hotlink). `video/stream.php` dan `music/stream.php` juga memanggil `session_write_close()` sebelum streaming agar request segment paralel tidak saling memblokir karena lock file session PHP. Gerbang token: halaman watch mendaftarkan mapping path → id media di sesi (`meel_register_stream_path`, kapasitas **500 entri** FIFO) dan endpoint stream memverifikasinya via `meel_stream_path_allowed` — akses terverifikasi **tanpa query DB per segmen**; token berlaku 12 jam dan di-refresh otomatis selama playback aktif; thumbnail (`thumbnail/`) dan file display tetap publik untuk kartu/OG-image. Mapping berlaku untuk **seluruh subtree folder video** (walk ancestor dari path ke kunci terdaftar): playlist varian multibitrate (`#EXT-X-STREAM-INF` → subfolder `v1/`, `720p/`, dst.) beserta segmennya ikut terverifikasi selama folder video tersebut terdaftar — folder video lain tetap ditolak, dan `video/` tidak pernah menjadi subtree root.

### Root .htaccess

```apache
# Root rewrite rules
RewriteEngine On
# Custom rules here
```

Direktori private Drive juga di-deny untuk akses HTTP langsung — lihat [Proteksi Private Drive](#proteksi-private-drive).

### Webroot Ditutup

`.htaccess` deny untuk `vendor/`, `node_modules/`, `tools/`, `assets/dict/`, dan `storage/`. Aturan `vendor/` dan `node_modules/` tidak bisa di-commit (keduanya diabaikan git), jadi `install.sh` menulisnya dan `tests/security_test.php` mengawasi seluruh direktori sensitif agar regresi ketahuan.

`FilesMatch` root juga menutupi `phpunit.xml`, `composer.*`, `package*.json`, `install.sh`, `.vscode`, dan sufiks cadangan (`~`, `.bak`, `.old`, `.orig`, `.swp`, `.dist`, `.sample`). **Tidak** memakai `*.json`/`*.xml`/`*.md` blanket, karena `assets/manifest.json` dan `sitemap.xml` harus tetap publik. Segmen direktori tooling ditutup dengan `RewriteRule`, karena `FilesMatch` hanya menangkap file bernama `.vscode`, bukan path yang memuat segmen itu.

### Kompatibilitas Apache 2.4

Semua `Deny from all` dibungkus `IfModule`. Tanpa pembungkus itu, Apache 2.4 tanpa `mod_access_compat` memperlakukan direktif itu sebagai error config — dan seluruh `.htaccess` dibuang, sehingga `controllers/` kehilangan seluruh kontrol akses.

---

## Proteksi SSRF (Advanced Upload)

Fitur Advanced Upload (`upload_advanced.php`) mengizinkan user terautentikasi mengunduh media dari URL eksternal melalui yt-dlp. Karena yt-dlp berjalan **di server**, URL yang di-supply attacker dan me-resolve ke alamat internal bisa disalahgunakan sebagai vektor Server-Side Request Forgery (SSRF) — memindai layanan loopback, endpoint metadata cloud (`169.254.169.254`), atau mesin lain di jaringan lokal.

### Arsitektur SSRF Guard

`modules/auth/SsrfGuard.php` adalah **satu-satunya sumber kebenaran** untuk setiap request keluar yang dibuat atas nama user:

```
User URL → SsrfGuard::validate()
    ↓
Allowlist protokol (hanya http/https)
    ↓
Denylist hostname (defense-in-depth: localhost, .local, .internal, …)
    ↓
Resolusi DNS → SEMUA record A/AAAA dicek terhadap range IPv4/IPv6
private/reserved secara eksplisit (satu record buruk = tolak)
    ↓
Transcoder::processDownload() me-pin koneksi HTTP ke IP publik yang
sudah divalidasi + memaksa Host header asli
```

### Aturan Validasi

| Aturan | Detail |
|---|---|
| **Protokol** | Hanya `http` dan `https`. URL tanpa skema, protocol-relative (`//host/…`), dan skema lain ditolak |
| **Kredensial** | `user:pass@` yang disematkan di URL ditolak (sering dipakai untuk menyembunyikan host tujuan) |
| **Denylist hostname** | `localhost`, `*.local`, `*.internal`, `*.lan`, `*.test`, `*.onion`, … (hanya defense-in-depth) |
| **DNS** | Setiap record A/AAAA yang dikembalikan divalidasi — satu record non-publik menolak URL (memblokir trik DNS mixed-answer) |
| **IP literal** | Literal IPv4 & IPv6 divalidasi langsung tanpa round-trip DNS |
| **Fail closed** | Host yang tidak bisa di-resolve, host IDN/punycode, dan IP yang tidak bisa di-parse ditolak |
| **Batas panjang** | URL lebih dari 2048 karakter ditolak |

### Range IP yang Diblokir

**IPv4:** `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10` (CGNAT), `127.0.0.0/8`, `169.254.0.0/16` (link-local), `172.16.0.0/12`, `192.0.0.0/24`, `192.0.2.0/24` (TEST-NET-1), `192.168.0.0/16`, `198.18.0.0/15`, `198.51.100.0/24` (TEST-NET-2), `203.0.113.0/24` (TEST-NET-3), multicast/reserved `224.0.0.0/4`.

**IPv6:** `::/128`, `::1/128`, `::ffff:0:0/96` (IPv4-mapped — dicek ulang dengan logika IPv4), `64:ff9b::/96` (NAT64), `100::/64` (discard-only), `2001:db8::/32`, `2001:10::/28` (ORCHID), `2002::/16` (6to4), `3fff::/20`, `fc00::/7` (unique-local), `fe80::/10` (link-local), `fec0::/10` (site-local), `ff00::/8` (multicast).

### DNS Rebinding & HTTP Pinning

Alur "validasi sekali, lalu request" yang naif rentan terhadap **DNS rebinding**: hostname me-resolve ke IP publik saat validasi, lalu ke IP private saat request sungguhan dilakukan. Untuk menutup celah ini, `SsrfGuard::pinHttpUrl()` menulis ulang URL `http://` yang sudah divalidasi agar koneksi langsung ke **IP publik yang sudah divalidasi**, sementara yt-dlp menerima `--add-header Host: <hostname-asli>`:

```php
[$dl_url, $dl_extra] = $ssrf->pinHttpUrl($url);
// $dl_url   = http://<ip-publik>[:port]/path  (tidak ada DNS lookup kedua yang dipengaruhi attacker)
// $dl_extra = --add-header 'Host: example.com'
```

URL `https://` dikembalikan apa adanya — validasi SNI dan sertifikat TLS membutuhkan hostname.

### Validating Forward Proxy (penegakan per-redirect)

Validasi awal sekali tidak bisa melindungi dari **open redirect**: yt-dlp mengikuti redirect sendiri dan me-resolve ulang setiap target tanpa mengembalikannya ke `SsrfGuard`. Untuk menutup celah ini, pipeline download mengarahkan **semua** trafik yt-dlp (metadata + download + setiap hop redirect) melalui validating forward proxy:

```
URL → SsrfGuard::validate() + pinHttpUrl()   (pre-flight, fast fail)
   ↓
yt-dlp --proxy http://127.0.0.1:<ephemeral>  (di-spawn per Transcoder)
   ↓
Validating forward proxy (validating_proxy_server.php)
   • HTTP  absolute-URI → validasi host → tulis ulang origin-form → teruskan
   • HTTPS CONNECT host:port → validasi host → tunnel 200 → relay byte
   • Target ditolak (IP private/reserved, hostname diblokir, host tidak bisa
     di-resolve, request cacat) → 502, tidak pernah diteruskan
   ↓
Stream diteruskan kembali ke yt-dlp
```

Properti kunci:

| Properti | Detail |
|---|---|
| **Setiap hop divalidasi** | Redirect ke `127.0.0.1`, `10.x`, metadata cloud, dsb. menjadi request CONNECT/absolute-URI baru ke proxy, yang menerapkan `SsrfGuard::resolvePublicAddresses()` ke **tujuan hop tersebut** dan menolaknya |
| **Loopback-only** | Proxy bind ke `127.0.0.1` pada port ephemeral — tidak ada pihak eksternal yang bisa memakainya sebagai open proxy |
| **DNS-rebinding aman per hop** | Setiap hop di-resolve sekali dan koneksi menuju IP publik yang sudah divalidasi (tidak ada lookup terpisah belakangan) |
| **Host header dipertahankan** | Header `Host:` dari client dipertahankan untuk request upstream, sehingga routing virtual-host dan SNI TLS tidak terganggu |
| **Fail closed** | Jika proxy tidak bisa dijalankan, download **ditolak** — tidak ada fallback tanpa proteksi |
| **Batas header ketat** | Baris header dengan CR/LF/NUL liar ditolak (mencegah HTTP request splitting / header injection yang lolos dari `explode("\r\n")`), maksimum **100 baris** dan **8 KB per baris** (batas memori & pemrosesan) |
| **Siklus hidup** | Di-spawn lazy saat download pertama via `ValidatingProxy` (proc_open script CLI), di-terminate oleh destructor `Transcoder` — tidak ada proses yatim |

Keterbatasan `https://` dengan demikian teratasi: meskipun URL HTTPS asli tidak bisa di-pin (SNI TLS), setiap tujuan redirect tetap divalidasi ulang oleh proxy sebelum koneksi dibuka.

#### Aturan protokolnya tinggal di luar skrip server

`validating_proxy_server.php` menjalankan server di level berkas, jadi berkasnya
tidak bisa di-include tanpa menjalankan proxy. Semua aturan di dalamnya —
bagaimana kepala permintaan diurai, header mana yang dibuang, bagaimana
permintaan yang diteruskan disusun — karena itu tidak punya test.

Aturan itu kini ada di `modules/auth/ValidatingProxyProtocol.php`, yang tidak
punya efek samping: berkasnya bisa di-include, dan aturannya bisa diuji tanpa
server dan tanpa jaringan. `ValidatingProxyProtocolTest` yang
melakukan, termasuk tunnel dan relay respons di level soket lewat sepasang
soket lokal. `ValidatingProxyTest` tetap menjalankan proses server sungguhan
dari ujung ke ujung.

`security_test.php` mencari pola penjaga proxy di kedua berkas sekaligus, jadi
memindahkan aturan di antara keduanya tidak melemahkan pemeriksaannya.

### Defense in Depth

`Transcoder::fetchMetadata()` juga memanggil `SsrfGuard::validate()` secara independen dan memaksa flag proxy, sehingga calon pemanggil lain yang melewati `processDownload()` pun tidak bisa meneruskan URL yang belum divalidasi — atau hop yang tidak terlindungi — ke yt-dlp.

### Titik Integrasi

| File | Peran |
|---|---|
| `modules/auth/SsrfGuard.php` | Validasi sentral: allowlist protokol, cek range IPv4/IPv6 eksplisit, validasi semua record DNS, HTTP pinning |
| `modules/auth/ValidatingProxy.php` | Spawn/terminate proses proxy, expose URL `--proxy` (loopback-only) |
| `modules/auth/validating_proxy_server.php` | CLI forward proxy: SsrfGuard di setiap hop (HTTP absolute-URI + CONNECT tunnel) |
| `modules/auth/ValidatingProxyProtocol.php` | Aturan protokol proxy, dipisah dari skrip server: parsing kepala permintaan beserta batasannya, parsing target CONNECT, penyusunan permintaan yang diteruskan, tunnel dan relay respons |
| `modules/core/Transcoder.php` (facade → `modules/transcoder/DownloadService.php`) | `processDownload()` / `fetchMetadata()` memanggil guard dan mengarahkan yt-dlp lewat `--proxy`; fail closed jika proxy tidak bisa start |
| `modules/autoload.php` | Mengautoload kelas `SsrfGuard` |

### Keamanan `temp_file` & `post_encode`

`temp_file` tidak pernah menerima path filesystem dari client. Alur download-music:

```
yt-dlp download → staging di RAM disk (`/dev/shm/meel/`) dengan nama token
  opaque (uniqid server-side)
  → nama + metadata disimpan di sesi (`meel_pending_music`, expiry 1 jam)
  → browser POST ke `controllers/api/post_encode.php` hanya membawa token
```

`post_encode.php`:
- **Method POST** + verifikasi CSRF (endpoint state-changing)
- Validasi token: regex `[A-Za-z0-9._-]+`, tolak `..`, path absolut, dan null byte
- `pathinfo()` + pencocokan sesi → **ownership** (user A tidak bisa memakai token milik user B) + cek eksistensi + expiry 1 jam
- Input encode dikunci ke direktori temp server (`getShmTempPath()`)

Download hasil transcode (`download_transcode.php`) memakai `Transcoder::ownsTranscodeFile()` — binding ke sesi — plus allowlist regex nama file, sehingga file transcode milik user lain tidak bisa ditebak/unduh.

---

## Proteksi Private Drive

Cloud Drive MEeL menyimpan file private user di bawah `<MEEL_HDD_DRIVE>/private_admins/<username>/...` saat `MEEL_HDD_DRIVE` di-set di `auth/settings.php`, atau folder fallback ter-track repo `data_drive/private_admins/<username>/...` saat tidak (lihat [Installation §5a](installation.md#5a-media-storage-meel_hdd_base--endpoint-php--rewrite-tanpa-symlink)). Selama subtree private dapat dijangkau dari web server (folder fallback berada di document root; deployment HDD bisa mengeksposnya lewat symlink saat deploy), web server bisa melayani file secara langsung — melewati otorisasi level aplikasi. Dua lapisan kontrol menutup celah ini.

### Lapisan 1 — Deny Web Server (ter-track di repo)

`data_drive/.htaccess` ter-commit di repository, sehingga aturan deny berlaku di **semua deployment** — baik saat `private_admins/` adalah folder fallback ter-track maupun symlink ke storage eksternal saat deploy:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^private_admins/ - [F,L]
</IfModule>
```

Request langsung apa pun ke `data_drive/private_admins/...` mengembalikan **HTTP 403**, apa pun tipe filenya (mp3/mp4/jpg/png/pdf/zip — semuanya) atau metode HTTP-nya. `Options -Indexes` saja TIDAK cukup — itu hanya menyembunyikan daftar direktori, tidak memblokir akses langsung ke file yang nama persisnya diketahui.

### Lapisan 2 — Hard Deny di Root Storage

`data_drive/private_admins/.htaccess` (ter-track di folder fallback repo; dibuat ulang saat deploy pada target storage eksternal):

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

### Jalur Akses yang Ber-otorisasi

File private hanya disajikan lewat endpoint terautentikasi:

| Endpoint | Fungsi | Pemeriksaan |
|---|---|---|
| `drive/stream.php` | Preview / streaming inline (video, audio, gambar, PDF) | session + CSRF + kepemilikan |
| `drive/download.php` | Unduh file private | session + CSRF + kepemilikan |

Kedua endpoint me-resolve file melalui `DriveStorage::getFileForDownload()`, yang:

1. **Menormalkan** scope (`public`/`private`) dan tipe (`video`/`audio`/`dokumen`)
2. Menerapkan `basename()` — menghapus komponen path-traversal
3. Memanggil `verifyPrivateFileAccess()` — membandingkan `realpath()` file dengan `realpath()` root private peminta, sehingga file harus berada di dalam direktori milik peminta sendiri (symlink escape dan traversal `..` gagal)

`stream.php` juga mendukung HTTP `Range` agar seeking video/audio tetap berfungsi.

### URL Preview

`DriveStorage::listFilesByType()` tidak pernah mengeluarkan path web langsung untuk file private. Sebagai gantinya ia membangun URL `stream?file=…&type=…&scope=private&csrf_token=…` (rute bersih — `stream.php?file=…` juga tetap diterima via 301), sehingga browser tidak pernah menyentuh path storage mentah (yang juga membocorkan struktur filesystem).

### Hardening Race Condition (Upload Drive)

Dua race TOCTOU ditutup di `DriveStorage::upload()`:

- **Kuota** — rangkaian "cek usage → cek kuota → tulis file" berjalan di dalam `flock()` per-user (lock file `temp/drive_quota_<md5(username)>.lock`) dengan usage dihitung segar (melewati cache 5 menit), sehingga upload berbarengan tidak bisa melewati kuota member secara kolektif. Jika lock tidak bisa dibuat, pengecekan kuota tetap berjalan non-atomik — tidak pernah dilewati
- **Bentrok nama file** — `fopen($path, 'x')` (O_CREAT|O_EXCL) mengklaim nama file unik secara atomik sebelum `move_uploaded_file()`, sehingga dua upload bersamaan dengan nama sama tidak bisa sama-sama menang (menutup TOCTOU `file_exists()` → move)

### Test Regresi

| File test | Cakupan |
|---|---|
| `tests/unit/SsrfGuardTest.php` | Allowlist protokol, range IP private/publik (v4 & v6), resolusi DNS termasuk record campuran, denylist hostname, HTTP pinning |
| `tests/unit/ValidatingProxyTest.php` | Probe proxy nyata: CONNECT/GET ke target private ditolak, target publik di-tunnel, bind loopback-only, siklus hidup proses |
| `tests/unit/DriveSecurityTest.php` | Akses cross-user, path traversal, symlink escape, boundary realpath, penegakan kuota, reservasi nama atomik |
| `tests/security_test.php` / `tests/functional_test.php` | Pemeriksaan statis wiring: guard dipanggil, flag proxy ter-wire, aturan deny `.htaccess` ada, endpoint stream dipakai untuk preview private |

**Jalankan semuanya dengan satu perintah:** `scripts/verify_security.sh` menjalankan ketiga suite keamanan (subset PHPUnit keamanan, `security_test.php`, `functional_test.php`) plus probe live 403 Private Drive dan keluar dengan exit code ramah-CI (lihat [testing.md](testing.md)).

### Otorisasi Stream Fail-Closed

`meel_stream_path_allowed()` memakai allowlist prefiks publik yang eksplisit — `thumbnail/`, `file/`, `lyrics/`, `upload/`, `manga/`, `books/` — dan sisanya ditolak. Video dan musik memang publik pada model RBAC proyek ini, jadi perilaku streaming publik tidak berubah; yang berubah adalah default-nya sekarang disengaja, bukan kebetulan, dan area privat tidak lagi bisa lolos hanya karena tidak berawalan `video/`.

---

## Exception Handling

### Custom Exception Classes

Mulai PHP 8+, MEeL menggunakan 3 custom exception untuk error handling spesifik:

```php
// ProcessException — Gagal eksekusi proses eksternal (FFmpeg, yt-dlp)
// Gunakan untuk: I/O errors, exec() failures, environment issues
catch (RuntimeException $e) { /* disk space */ }
catch (ProcessException $e) { /* ffmpeg failed */ }
catch (TranscodeException $e) { /* HLS failed */ }
catch (DownloadException $e) { /* yt-dlp failed */ }
```

| Exception | Extends | Digunakan Untuk |
|---|---|---|
| `ProcessException` | `\RuntimeException` | Gagal proses eksternal: FFmpeg, yt-dlp, exec() non-zero |
| `DownloadException` | `\RuntimeException` | Gagal download URL: metadata parsing, koneksi |
| `TranscodeException` | `\RuntimeException` | Gagal transcoding: HLS segments, codec, output hilang |

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

Sebelum operasi download/transcoding, validasi disk space:
```php
function check_disk_space(int $required_bytes, string $path): array {
    // Cek free space, return ["ok" => bool, "free" => bytes, "required" => bytes]
}

function require_disk_space(int $required_bytes, string $path, string $label): void {
    // Throw RuntimeException jika disk tidak cukup
}
```

---

## Input Validation

### SQL Injection Prevention

Semua query database menggunakan **Prepared Statements**:

```php
// ✅ AMAN
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $user_input);
$stmt->execute();

// ❌ TIDAK AMAN - TIDAK DIGUNAKAN
// $result = $conn->query("SELECT * FROM users WHERE username = '$user_input'");
```

### XSS Prevention

Output selalu di-escape dengan `htmlspecialchars()`:

```php
echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
```

**Stored XSS (hardening):** database menyimpan data mentah (canonical); escaping dilakukan **saat output**, sesuai konteks:

- Plain text HTML → `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`
- Attribute/URL → escaping konteks attribute + `urlencode`/whitelist
- JSON/JS → `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`

Audit mencakup seluruh nilai user-controlled yang dirender: bio profil, judul/deskripsi media, komentar, nama file, playlist, hasil pencarian, username chat, dan data yang tampil di panel admin. Untuk inline JS template literal (contoh: hasil pencarian chat admin), fungsi `escapeHtml()` khusus membungkus nilai user-derived sebelum injeksi HTML. Regression test (`tests/security_test.php`) memverifikasi payload `<script>alert(1)</script>`, `<img src=x onerror=alert(1)>`, dll. tidak pernah dieksekusi sebagai HTML.

### CSRF in All Forms

```php
// Setiap form POST memiliki CSRF token
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    $error_msg = "Sesi keamanan kadaluarsa.";
}
```

### Login Rate Limiting

```php
$max_login_attempts = 5;
$lockout_time = 300; // 5 menit

if ($_SESSION['login_fail_count'] >= $max_login_attempts) {
    $_SESSION['login_locked_until'] = time() + $lockout_time;
}
```

### Register Rate Limiting

```php
$max_reg_attempts = 3;
$reg_time_window = 3600; // 1 jam
```

### Theme System Security

Theme preference disimpan dengan aman:

1. **Client-side:** `localStorage` — tidak ada data sensitif, hanya string 'light'/'dark'
2. **Server-side:** `users.custom_theme` — varchar(50), hanya menerima 'light' atau 'dark'
3. **API validation:** `in_array($theme, ['light', 'dark'], true)` — reject invalid values
4. **CSRF protection:** POST memerlukan valid CSRF token
5. **No XSS risk:** Theme value tidak di-escape ke HTML, hanya digunakan untuk `data-theme` attribute

### Escape Saat Render, Bukan Saat Simpan

`UpdateManager::clean()` hanya melakukan `trim()`. Escaping terjadi di titik render, per konteks. Halaman `/update` bisa diakses pengunjung anonim, jadi "escape saat tulis" berarti penulis kedua — instalasi, impor, atau edit `mysql>` langsung — menjadi stored XSS untuk semua pengunjung.

`admin/index.php` juga memakai `json_encode` dengan flag `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP`; `json_encode` polos tidak meng-escape `'`, jadi nama berkas ber-apostrof bisa menutup atribut berkoma tunggal.

### View Inflation

`UNIQUE (user_id, video_id, music_id)` **tidak menahan apa pun**: MySQL memperlakukan NULL sebagai nilai berbeda satu sama lain di unique index, jadi `(9, 299, NULL)` dan `(9, 299, NULL)` tetap dua baris berbeda. Pola yang benar adalah dua unique terpisah per jenis media, seperti tabel `interactions`.

> ⚠️ **Jebakan:** kolom yang tidak dipakai **harus tetap `NULL`, bukan `0`**. Mengisi `0` membuat semua baris musik berbagi `video_id=0` dan saling bentrok di `uq_view_video`. Sifat NULL yang *lolos* justru yang membuat pola dua-unique ini bekerja.

### Tipe `bind_param` Mengikuti Skema

`bind_param` harus memakai tipe kolom yang sebenarnya. `difficulty_label` dan `cover_file` bertipe varchar tidak boleh di-bind `"i"` — mysqli tidak memberi warning, ia mengonversi nilai sesuai tipe yang diminta, jadi keduanya tersimpan `'0'`. `tests/unit/BindParamTypeTest.php` menurunkan tipe yang diharapkan dari `arcade/schema.sql`, bukan dari daftar hardcode.
## Pemeriksa Keamanan

Pemeriksa di `tests/security_test.php` harus benar-benar memindai apa yang diklaimnya. Tiga aturan yang berlaku:

### Peta allowlist bukan daftar berkas yang dipindai

Pemeriksa **menemukan sendiri** berkasnya lewat `getPhpFiles()`, lalu menghitung ulang `escapeshellarg`/cast-int untuk setiap hasil temuan. Peta `$expected`/`$allowed` tetap ada, tapi perannya berubah: bukan daftar berkas yang dipindai, melainkan daftar yang *diharapkan* masih menjalankan shell. Berkas di luar peta yang menjalankan shell **gagal**, dengan pesan yang menyebut apa yang harus diubah kalau memang sah. Berkas yang terdaftar tapi kehilangan `shell_exec`-nya ikut dilaporkan, supaya peta basi langsung terlihat.

Jumlah berkas yang dipindai: **21**.

### Pelacakan taint untuk open redirect

Variabel yang diinisialisasi dari `HTTP_REFERER` ditandai tercemar; penugasan yang memakai variabel tercemar ikut menandai variabel berikutnya (dua tingkat — cukup untuk pola `$ref` → `$back_url`); setiap `header('Location: ...')` yang ekspresinya memakai variabel tercemar dilaporkan sebagai open redirect. Dua lapis itu penting karena redirect lewat variabel lolos dari pemeriksaan yang hanya mencari `HTTP_REFERER` di dalam `header()`.

Yang **tidak** dilaporkan: berkas yang membaca referer lalu mengarahkan ke path lokal tetap — misalnya gate HLS di `music/stream.php` dan `modules/core/helpers/storage_stream.php` yang mengalihkan ke `../err/?code=denied`.

### Aturan pemeriksaan yang sadar-cabang

`display_errors=1` diterima **hanya** bila berkas yang sama juga memuat `case 'development'` **dan** `display_errors=0`. Daftar nama berkas yang dikecualikan tidak ada, jadi pengecualian baru tidak lagi bisa menyembunyikan regresi.

CI gagal pada kode keluar apa pun yang bukan nol — peringatan pun merah.

### Penjaga agar tidak kembali korup

`StorageSplitTest::testSecurityAndFunctionalMapsStillCoverTheRealFiles` mencari peta `$risky`, `$expected`, atau `$allowed`, dan menambahkan assertion bahwa `testCommandInjection()` benar-benar memanggil `getPhpFiles()`. Kalau suatu saat pemeriksaan ini dikembalikan ke daftar path literal, assertion tersebut gagal lebih dulu.

## Akuntansi MEeLCoin

Aturan akuntansi refund di baris antrean. Semuanya dijaga `QueueRefundAccountingTest` dan `QueueRefundRecoveryIntegrationTest`.

### Biaya yang dicatat adalah jumlah yang dipungut

`upload_queue.coin_cost` mencatat MEeLCoin yang benar-benar dipotong saat baris dibuat — pemanggil yang membuat baris yang wajib mencatat jumlahnya, bukan alir lain. `coin_refunded` = 1 berarti biaya sudah dikembalikan.

### Satu implementasi untuk dua pemanggil

Pembersihan baris basi memakai `GarbageCollector::releaseStaleQueueRows()` — kode yang sama dengan sweeper 6 jam. Kalau salah satu menandai `failed` tanpa refund, baris itu hilang dari jangkauan sweeper dan biaya MEeLCoin hilang permanen. Urutan di dalam satu baris penting: **refund dulu, baru tandai `failed`**. Kalau dibalik, ada celah di mana baris sudah `failed` tapi coins belum dikembalikan, dan baris `failed` tidak lagi dipilih sweeper.

### Refund hanya satu kali

`releaseStaleQueueRows()` membaca biaya dari baris itu sendiri, bukan dari harga saat ini — harga MEeLCoin bisa berubah, dan yang harus dikembalikan adalah jumlah yang benar-benar dipotong. Baris dikunci `FOR UPDATE` selama transaksi, jadi dua sweeper yang jalan bersamaan tidak bisa merefund baris yang sama. `coin_refunded = 1` hanya ditulis **setelah** coins benar-benar dikembalikan, dan setiap hasil `execute()` diperiksa.

`releaseQueue()` tetap hanya menyentuh kolom status. Invarian itu penting: kalau ia ikut menyetel `coin_refunded`, baris yang sudah direfund jalur gagal normal akan direfund lagi oleh sweeper.

### Notifikasi gagal tidak boleh membatalkan upload

`Notification::create()` dibungkus penanganan error — notifikasi itu kosmetik, jadi kegagalannya dicatat dan upload diteruskan. Kalau tidak, user sudah membayar, tidak ada yang diunggah, dan refund tidak pernah dijalankan.