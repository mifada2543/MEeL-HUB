<?php
// Session config - hanya set jika session belum jalan
if (session_status() === PHP_SESSION_NONE) {
    $timeout = 43200; // 12 jam
    ini_set('session.gc_maxlifetime', $timeout);
    session_set_cookie_params($timeout, "/");
    session_name('meel');
    session_start();
}
// Sesuaikan sendiri dengan konfigurasi database Anda
$server   = "";
$username = "";
$password = "";
$db       = "";

// JALUR EDUKASI: Deteksi jika mereka malas membaca README dan langsung menjalankan kode
if (empty($server) || empty($username) || empty($db)) {
    die("<pre style='color: #ef4444; background: #1e1e2e; padding: 20px; border-radius: 5px; font-family: monospace;'>
[MEeL SYSTEM ERROR]
Wah, tampaknya kamu terlalu terburu-buru! 
Kamu belum mengisi konfigurasi database di file 'auth/config.php'.

Yang harus kamu lakukan:
1. Isi \$server, \$username, dan \$db dengan kredensial database lokalmu.
2. Pastikan database-nya sudah di-import.
3. Jangan lupa baca README.md lagi ya :)
</pre>");
}

// Database connection - ditulis langsung tanpa tanda kutip ganda ("")
$conn = new mysqli($server, $username, $password, $db);
if ($conn->connect_error) {
    die("Koneksi ke database gagal: " . $conn->connect_error);
}

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// CSRF Verification Function - return status instead of die
if (!function_exists('verify_csrf')) {
    function verify_csrf() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                return false;
            }
        }
        return true;
    }
}

// Session timeout check (12 jam)
$timeout = 43200;
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

// Include activity logger
include_once __DIR__ . '/../modules/activity_logger.php';

// Romaji conversion helper
if (!function_exists('getRomajiName')) {
    function getRomajiName($text) {
        if (empty($text)) return 'untitled';
        $rule = "Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII; Any-Lower;";
        $transliterator = Transliterator::create($rule);
        if ($transliterator) {
            $text = $transliterator->transliterate($text);
        }
        $clean = preg_replace('/[^a-z0-9\-]/u', '-', $text);
        $clean = preg_replace('/-+/', '-', trim($clean, '-'));
        return $clean ?: 'untitled-media';
    }
}
