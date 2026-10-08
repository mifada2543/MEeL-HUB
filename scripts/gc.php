<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Akses ditolak. Jalankan dari terminal: php scripts/gc.php\n");
}

require_once __DIR__ . '/../modules/core/GarbageCollector.php';
require_once __DIR__ . '/../modules/core/Notification.php';

$argv = $_SERVER['argv'] ?? [];

$runOnly   = in_array('--run-only', $argv, true);
$cleanCache = in_array('--clean-cache', $argv, true);
$yes        = in_array('-y', $argv, true) || in_array('--yes', $argv, true);

function showHelp(): void
{
    $self = basename($_SERVER['argv'][0] ?? 'gc.php');
    echo <<<EOT
MEeL Garbage Collector — Housekeeping Otomatis

Penggunaan:
  php $self                 # Interaktif: minta konfirmasi, lalu jalankan GC normal
  php $self -y              # Non-interaktif: jalankan GC normal langsung
  php $self --clean-cache   # Interaktif: bersihkan cache + GC normal
  php $self --clean-cache -y # Non-interaktif: bersihkan cache + GC normal
  php $self --run-only      # Hanya ringan (tanpa DB), skip housekeeping berat
  php $self --help          # Tampilkan ini

Modul/Flag yang tersedia:
  --clean-cache     Bersihkan file cache expired + .tmp.* yatim (temp/cache/)
  --run-only        Hanya ringan: scan storage + rate-limit, skip DB maintenance
  -y, --yes         Lewati konfirmasi interaktif (mode default/otomatis)

Contoh:
  php $self --clean-cache -y   # Bersihkan cache + GC normal, tanpa tanya
  php $self -y                 # GC normal (storage + DB), tanpa tanya

EOT;
}

if (in_array('--help', $argv, true)) {
    showHelp();
    exit(0);
}

function confirm(string $message, bool $default = true): bool
{
    if (!isset($_SERVER['argv']) || !is_resource(STDIN)) {
        return $default;
    }
    $suffix = $default ? ' [T/y]' : ' [t/Y]';
    while (true) {
        echo $message . $suffix . ' ';
        $input = trim(fgets(STDIN) ?: '');
        if ($input === '') return $default;
        $input = strtolower($input);
        if (in_array($input, ['t', 'y', 'yes', 'ya', 'true', '1'], true)) return true;
        if (in_array($input, ['n', 'no', 'tidak', 'false', '0'], true)) return false;
        echo "  Masukkan 't' (ya) atau 'y' (tidak).\n";
    }
}

echo "[MEeL gc] mulai (" . date('c') . ")\n";

if (!$yes) {
    echo "\n";
    echo "  Akan dijalankan:\n";
    echo "    • GC normal: scan storage, rate-limit, pending delete\n";
    if ($cleanCache) echo "    • Bersihkan cache expired + .tmp.* yatim\n";
    if ($runOnly)    echo "    • --run-only: HANYA ringan (tanpa DB maintenance)\n";
    echo "\n";

    $go = confirm("Lanjut?");
    if (!$go) {
        echo "[MEeL gc] dibatalkan.\n";
        exit(0);
    }
    echo "\n";
}

GarbageCollector::run();
echo "[MEeL gc] scan storage + rate-limit selesai\n";

if ($cleanCache) {
    $cleaned = GarbageCollector::cleanCacheDirectory();
    echo "[MEeL gc] cache dibersihkan: {$cleaned} file\n";
}

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
