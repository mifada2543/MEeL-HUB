# 🔒 Sistem Keamanan MEeL

Dokumentasi tentang sistem keamanan, autentikasi, otorisasi, dan proteksi yang ada di MEeL-HUB.

---

## 📋 Daftar Isi

- [Arsitektur Keamanan](#arsitektur-keamanan)
- [Role-Based Access Control (RBAC)](#role-based-access-control-rbac)
- [Session Management](#session-management)
- [CSRF Protection](#csrf-protection)
- [IP Banning & Firewall](#ip-banning--firewall)
- [Activity Logging](#activity-logging)
- [File Upload Security](#file-upload-security)
- [Apache .htaccess Protection](#apache-htaccess-protection)
- [Input Validation](#input-validation)

---

## Arsitektur Keamanan

```
┌─────────────────────────────────────────────────────────┐
│                    Browser Request                       │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              1. Apache .htaccess Layer                   │
│  • Block direct access to sensitive directories         │
│  • mod_rewrite rules                                    │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              2. Session Authentication (auth.php)        │
│  • Check user_id in session                             │
│  • Validate last_session_id (anti-hijack)               │
│  • Session timeout (12 jam)                             │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              3. IP Ban Check (activity_logger.php)       │
│  • Check IP against banned list                         │
│  • Block all non-admin users if IP is banned            │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              4. Role-Based Access (RBAC)                 │
│  • Admin / Member / User / Guest                        │
│  • Feature gating per page                              │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              5. CSRF Protection                          │
│  • Token validation on all POST requests                 │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              6. Prepared Statements                      │
│  • All database queries use mysqli prepared statements  │
│  • No raw SQL concatenation with user input             │
└─────────────────────────────────────────────────────────┘
```

---

## Role-Based Access Control (RBAC)

### Definisi Role

| Role | Level | Hak Akses |
|------|-------|-----------|
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
    header("Location: ../auth/login.php?next={$next}");
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
    header("Location: ../index.php?error=ditolak");
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
            die(include __DIR__ . '/../err/denied.php');
        }
    }
    
    public function isAllowedRole(): bool {
        return in_array($this->role, ['admin', 'member'], true);
    }
}
```

### Feature Gating per Role

| Fitur | Admin | Member | User | Guest |
|-------|-------|--------|------|-------|
| Nonton Video | ✅ | ✅ | ✅ | ✅ |
| Dengar Musik | ✅ | ✅ | ✅ | ✅ |
| Like/Dislike | ✅ | ✅ | ✅ | ❌ |
| Komentar | ✅ | ✅ | ✅ | ❌ |
| Upload Video | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Upload Musik | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Books | ✅ | ✅ | ✅ | ❌ |
| Cloud Drive | ✅ (unlimited) | ✅ (20GB) | ❌ | ❌ |
| Advanced Upload | ✅ | ✅ | ✅ | ❌ |
| Transcoder | ✅ | ✅ | ✅ | ❌ |
| Admin Panel | ✅ | ❌ | ❌ | ❌ |

---

## Session Management

### Session Configuration

```php
// auth/config.php
$timeout = 43200;              // 12 jam
ini_set('session.gc_maxlifetime', $timeout);
session_set_cookie_params($timeout, "/");
session_name('meel');           // Cookie name: "meel"
session_start();
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
        header("Location: ../auth/login.php?reason=expired");
        exit;
    }
}
$_SESSION['LAST_ACTIVITY'] = time();

// activity_logger.php - Kick detection
if ($user_status['role'] !== 'admin') {
    if (!empty($user_status['last_session_id']) && 
        $user_status['last_session_id'] !== $current_sid) {
        
        session_unset();
        session_destroy();
        
        die("
            <div style='background:#0b0e14; color:#f97316; ...'>
                <h1>SESSION REVOKED</h1>
                <p>Akses sesi ini telah dihentikan oleh Admin 
                   atau login dari perangkat lain.</p>
                <a href='/MEeL/login.php'>KEMBALI KE LOGIN</a>
            </div>
        ");
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
function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || 
            !isset($_SESSION['csrf_token']) || 
            $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            return false;
        }
    }
    return true;
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

---

## IP Banning & Firewall

### IP Detection (Anti-Proxy)

```php
function get_real_ip() {
    // Cloudflare
    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
        return $_SERVER["HTTP_CF_CONNECTING_IP"];
    }
    // X-Forwarded-For
    if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
        return trim(explode(',', $_SERVER["HTTP_X_FORWARDED_FOR"])[0]);
    }
    // Fallback
    return $_SERVER["REMOTE_ADDR"];
}
```

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
// Di activity_logger.php - dijalankan di setiap halaman
$check_ban = $conn->prepare("SELECT reason FROM ip_ban WHERE ip_address = ?");
$check_ban->bind_param("s", $user_ip);
$check_ban->execute();
$ban_res = $check_ban->get_result();

if ($ban_res->num_rows > 0) {
    // Admin still allowed (for debugging)
    if ($session_role !== 'admin') {
        die("403 - Akses Dibatasi: " . $ban_reason);
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
function log_activity($conn, $user_id, $action, $media_type, $media_id) {
    // Mencatat aktivitas ke tabel activity_log
}
```

### Yang Dicatat

| Event | Aksi | Detail |
|-------|------|--------|
| Login | `login` | User login success |
| Upload Video | `upload` | Video ditambahkan |
| Upload Music | `upload` | Musik ditambahkan |
| Download URL | `download` | URL diproses yt-dlp |
| Comment | `comment` | Komentar ditambahkan |
| Like/Dislike | `interaction` | Interaksi media |

### Live Activity Monitor

Admin dapat melihat aktivitas real-time user di dashboard admin:

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
- `books/upload/` — File buku
- `music/upload/` — File musik
- `video/upload/` — File video

### Root .htaccess

```apache
# Root rewrite rules
RewriteEngine On
# Custom rules here
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

### CSRF in All Forms

```php
// Setiap form POST memiliki CSRF token
if (!verify_csrf()) {
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

---

## Best Practices

### Untuk Developer

1. **Selalu gunakan Prepared Statements** untuk query database
2. **Sanitasi semua input** POST/GET
3. **Verifikasi CSRF token** di setiap form POST
4. **Jangan percaya user input** — validasi tipe file, ukuran, dan konten
5. **Escape output** dengan `htmlspecialchars()`
6. **Jangan expose error detail** ke user non-admin

### Security Checklist

- [ ] Database credentials hanya di `auth/config.php`
- [ ] Semua `.htaccess` terpasang di direktori sensitif
- [ ] Prepared statements di semua query SQL
- [ ] CSRF token di semua form POST
- [ ] Session timeout aktif (12 jam)
- [ ] IP banning system aktif
- [ ] File upload validation (tipe, ukuran, magic bytes)
- [ ] Role checking sebelum aksi sensitif

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
