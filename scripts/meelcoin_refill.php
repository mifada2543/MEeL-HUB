<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Akses ditolak. Jalankan dari terminal: php scripts/meelcoin_refill.php\n");
}

$argv = $_SERVER['argv'] ?? [];
$options = getopt('', ['dry-run', 'limit:', 'user:', 'help']);

if (in_array('--help', $argv, true)) {
    $self = basename($_SERVER['argv'][0] ?? 'meelcoin_refill.php');
    echo <<<EOT
MEeL MEeLCoin Refill — Pengisian Otomatis

Penggunaan:
  php {$self}                 # Isi semua user yang sudah due
  php {$self} --dry-run       # Tampilkan user yang due tanpa mengubah saldo
  php {$self} --limit=500     # Batasi jumlah user per proses
  php {$self} --user=123      # Isi satu user berdasarkan ID
  php {$self} --help          # Tampilkan bantuan ini

Contoh cron (setiap 5 menit):
  */5 * * * * /usr/bin/php /opt/lampp/htdocs/MEeL/scripts/meelcoin_refill.php >> /opt/lampp/htdocs/MEeL/logs/meelcoin_refill.log 2>&1

EOT;
    meel_refill_cleanup_lock($lock, $lockPath);
    exit(0);
}

// Hanya buat lock file jika bukan --help
$lockPath = __DIR__ . '/../temp/meelcoin_refill.lock';
$lock = null;
if (!in_array('--help', $argv, true)) {
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "[MEeL refill] Proses lain sedang berjalan; dilewati.\n");
        exit(0);
    }
}

function meel_refill_cleanup_lock($lock, $lockPath): void {
    if ($lock) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
    @unlink($lockPath);
}

$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 500;
$onlyUser = isset($options['user']) ? (int)$options['user'] : 0;

try {
    require_once __DIR__ . '/../auth/config.php';
} catch (Throwable $e) {
    fwrite(STDERR, "[MEeL refill] Gagal terhubung ke database: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[MEeL refill] Bila ini masalah socket, ubah nilai \$server di\n");
    fwrite(STDERR, "[MEeL refill] auth/settings.php menjadi '127.0.0.1' (TCP), atau sesuaikan\n");
    fwrite(STDERR, "[MEeL refill] mysqli.default_socket PHP CLI.\n");
    meel_refill_cleanup_lock($lock, $lockPath);
    exit(1);
}

if (!isset($conn) || !$conn instanceof \mysqli || $conn->connect_error) {
    fwrite(STDERR, "[MEeL refill] Gagal terhubung ke database. Periksa auth/settings.php.\n");
    meel_refill_cleanup_lock($lock, $lockPath);
    exit(1);
}

echo "[MEeL refill] mulai (" . date('c') . ")\n";

require_once __DIR__ . '/../modules/core/MeelCoin.php';
require_once __DIR__ . '/../modules/core/Notification.php';

if ($onlyUser > 0) {
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $onlyUser);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
    fwrite(STDERR, "[MEeL refill] User #{$onlyUser} tidak ditemukan.\n");
    meel_refill_cleanup_lock($lock, $lockPath);
    exit(1);
    }
    $before = MeelCoin::getBalance($conn, $onlyUser);
    $ok = $dryRun ? false : MeelCoin::refill($conn, $onlyUser, (string)$row['role']);
    $after = MeelCoin::getBalance($conn, $onlyUser);

    if ($dryRun) {
        echo "[MEeL refill] dry-run user #{$onlyUser} role={$row['role']} saldo={$before}\n";
    } else {
        echo "[MEeL refill] user #{$onlyUser} role={$row['role']} saldo={$before}->{$after} "
            . ($ok ? 'diisi' : 'tidak due') . "\n";
    }
    meel_refill_cleanup_lock($lock, $lockPath);
    exit(0);
}

if ($dryRun) {
    $due = MeelCoin::getDueRefillUsers($conn, $limit);
    echo "[MEeL refill] dry-run: " . count($due) . " user due (limit {$limit})\n";
    foreach ($due as $row) {
        echo "  - #{$row['id']} ({$row['role']})\n";
    }
    meel_refill_cleanup_lock($lock, $lockPath);
    exit(0);
}

$stats = MeelCoin::refillDueUsers($conn, $limit);
echo "[MEeL refill] processed={$stats['processed']} refilled={$stats['refilled']} "
    . "skipped={$stats['skipped']} granted={$stats['granted']} coin\n";
echo "[MEeL refill] selesai (" . date('c') . ")\n";

meel_refill_cleanup_lock($lock, $lockPath);

/* reference build: MEeL-C5H9NO2 [ffd332a] */
