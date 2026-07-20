<?php
/**
 * pdf.php — PDF raw binary server
 * 
 * Menyajikan file PDF mentah dengan header HTTP eksplisit.
 * Mendukung X-Sendfile (mod_xsendfile Apache) untuk akselerasi.
 * Digunakan oleh <embed> di books/read.php (desktop) dan oleh books/read_pdf.php (HTML wrapper).
 * 
 * Aman dari path traversal karena ID buku divalidasi dari database.
 */

error_reporting(0);
@ini_set('display_errors', 0);

require_once '../../auth/auth.php';
require_once '../../auth/config.php';
require_once '../../modules/media/MediaLibrary.php';

// Lepas session lock agar request tidak terblokir
session_write_close();

// ── Validasi ID ──────────────────────────────────────────────────────────────
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1) {
    http_response_code(400);
    die('Invalid request');
}
// ── Ambil data buku dari database ────────────────────────────────────────────
$repo = new BookRepository($conn);
$book = $repo->getBookById($id);

if (!$book || $book['type'] !== 'pdf') {
    http_response_code(404);
    die('File not found');
}

// ── Tentukan path fisik file PDF ─────────────────────────────────────────────
$file_name = basename($book['path_folder']);
$file_path = __DIR__ . '/../../books/upload/pdf/' . $file_name;

if (!file_exists($file_path) || !is_readable($file_path)) {
    http_response_code(404);
    die('File not found');
}

$file_size = filesize($file_path);

// ── Cegah timeout untuk file besar ───────────────────────────────────────────
set_time_limit(0);
ignore_user_abort(false);

// ── Bersihkan output buffering — file besar tidak boleh ditahan di RAM ─────
while (@ob_get_level()) {
    @ob_end_clean();
}
@ob_implicit_flush(true);

// ── Kirim header eksplisit ───────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $book['title']) . '.pdf"');
header('Content-Length: ' . $file_size);
header('Cache-Control: public, max-age=86400');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
header('Pragma: public');
header('Accept-Ranges: bytes');

// ═══════════════════════════════════════════════════════════════════════════
// 🚀 X-Sendfile — Apache kirim file langsung dari disk (zero-copy)
//     PHP tidak perlu baca file → RAM hemat, response 2-3x lebih cepat
// ═══════════════════════════════════════════════════════════════════════════
// Aktivasi:
//   1. Install mod_xsendfile (https://github.com/nmaier/mod_xsendfile)
//   2. Tambahkan di httpd.conf:
//        XSendFile on
//        XSendFilePath "/opt/lampp/htdocs/MEeL/books/upload/pdf"
//   3. Set define('MEEL_USE_XSENDFILE', true) di auth/config.php
if (defined('MEEL_USE_XSENDFILE') && MEEL_USE_XSENDFILE === true) {
    header("X-Sendfile: " . $file_path);
    header("Content-Length: " . $file_size);
    exit;
}

// ── Fallback: kirim via PHP chunked read (512KB per chunk) ───────────────────
$chunkSize = 524288; // 512KB
$fp = @fopen($file_path, 'rb');
if (!$fp) {
    http_response_code(500);
    die('Tidak bisa membaca file.');
}

while (!@feof($fp)) {
    if (connection_aborted()) break;
    $buf = @fread($fp, $chunkSize);
    if ($buf === false || $buf === '') break;
    echo $buf;
    @ob_flush();
    @flush();
}
@fclose($fp);
exit();
