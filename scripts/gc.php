<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Akses ditolak. Jalankan dari terminal: php scripts/gc.php\n");
}

require_once __DIR__ . '/../modules/core/GarbageCollector.php';
require_once __DIR__ . '/../modules/core/Notification.php';

$runOnly = in_array('--run-only', $argv ?? [], true);

echo "[MEeL gc] mulai (" . date('c') . ")\n";

GarbageCollector::run();
echo "[MEeL gc] scan storage + rate-limit selesai\n";

if ($runOnly) {
    echo "[MEeL gc] --run-only: DDL & agregasi view dilewati.\n";
    exit(0);
}
try {
    require_once __DIR__ . '/../auth/config.php';
} catch (Throwable $e) {
    fwrite(STDERR, "[MEeL gc] Gagal terhubung ke database: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[MEeL gc] Bila ini masalah socket, ubah nilai \$server di\n");
    fwrite(STDERR, "[MEeL gc] auth/settings.php menjadi '127.0.0.1' (TCP), atau sesuaikan\n");
    fwrite(STDERR, "[MEeL gc] mysqli.default_socket PHP CLI.\n");
    exit(1);
}

if (!isset($conn) || !$conn instanceof \mysqli || $conn->connect_error) {
    fwrite(STDERR, "[MEeL gc] Gagal terhubung ke database. Periksa auth/settings.php.\n");
    exit(1);
}

GarbageCollector::runCliMaintenance($conn);

$notifUsers = Notification::pruneAll($conn);
if ($notifUsers > 0) {
    error_log("[MEeL gc] retensi notifikasi: {$notifUsers} pengguna dipangkas (batas 50 baris & 15 hari).");
}

echo "[MEeL gc] housekeeping berat selesai (" . date('c') . ")\n";
/* reference build: MEeL-C1H1N1O2 [2f6a0d5b8c41e937] */
