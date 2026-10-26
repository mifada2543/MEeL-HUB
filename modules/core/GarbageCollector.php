<?php
class GarbageCollector
{
    private const STALE_SECONDS = 1200;
    private const GUEST_STALE_HOURS = 2;
    private const GUEST_CLEANUP_INTERVAL = 3600;
    private const ROOM_LOBBY_STALE_HOURS = 24;
    private const ROOM_GAME_STALE_HOURS = 168;
    private const CHESS_CLEANUP_INTERVAL = 3600;
    public const QUEUE_STUCK_MINUTES = 360;
    private const CACHE_TTL_MAP = [
        'media_stats_'       => 30,
        'admin_orphans'      => 120,
        'dirsize_'           => 300,
        'server_stats_info'  => 300,
        'xsendfile_roots'    => 86400,
    ];
    private const CACHE_TMP_TTL = 300;
    private const CACHE_ORPHAN_TTL = 86400;
    private static bool $hasRun = false;
    private static bool $cliOnlyRan = false;
    public static function runCliMaintenance(\mysqli $conn): void
    {
        if (PHP_SAPI !== 'cli' || self::$cliOnlyRan) {
            return;
        }
        self::$cliOnlyRan = true;
        self::syncViews($conn);
        self::resetGuestsAutoIncrement($conn);
        self::sweepStuckQueues($conn);
        self::sweepUploadAccounting($conn);
    }

    /**
     * Bersihkan sesi upload basi dan reservasi coin yang tidak pernah selesai.
     *
     * @return array{attempts_failed:int,attempts_completed:int,holds_released:int}
     */
    public static function sweepUploadAccounting(\mysqli $conn): array
    {
        $out = ['attempts_failed' => 0, 'attempts_completed' => 0, 'holds_released' => 0];
        if (class_exists('UploadAttempt')) {
            $attempts = UploadAttempt::sweepStale($conn);
            $out['attempts_failed'] = (int)$attempts['failed'];
            $out['attempts_completed'] = (int)$attempts['completed'];
        }
        if (class_exists('MeelCoin')) {
            $out['holds_released'] = MeelCoin::releaseExpiredHolds($conn);
        }
        return $out;
    }
    /** @return array{scanned:int, failed:int, refunded:int, completed:int}  */
    public static function sweepStuckQueues(\mysqli $conn, ?int $staleMinutes = null): array
    {
        $minutes = $staleMinutes ?? self::QUEUE_STUCK_MINUTES;
        $result = self::releaseStaleQueueRows($conn, $minutes);
        if ($result['scanned'] > 0) {
            error_log(
                sprintf(
                    '[MEeL] GarbageCollector: sweeper antrean — %d baris macet, %d failed, %d refunded, %d completed',
                    $result['scanned'],
                    $result['failed'],
                    $result['refunded'],
                    $result['completed']
                )
            );
        }
        return $result;
    }

    /**
     * Lepaskan baris antrean yang macet: refund biaya yang tercatat, lalu tandai failed.
     *
     * Dipakai oleh sweeper 6 jam (`sweepStuckQueues()`) dan pembersih 30
     * menit di `upload_advanced.php`. Refund harus lebih dulu, baru status
     * diubah — kalau dibalik, baris `failed` tidak lagi dipilih sweeper
     * sementara coins belum kembali. `coin_cost` dibaca dari baris itu
     * sendiri, bukan dari harga saat ini.
     *
     * @param array<int, string> $tables tabel antrean yang dipindai
     * @return array{scanned:int, failed:int, refunded:int, completed:int}
     * @see docs/{id,en}/security.md
     */
    public static function releaseStaleQueueRows(\mysqli $conn, int $staleMinutes, array $tables = ['upload_queue', 'transcode_queue']): array
    {
        $result = ['scanned' => 0, 'failed' => 0, 'refunded' => 0, 'completed' => 0];
        $canRefund = class_exists('MeelCoin');

        foreach ($tables as $table) {
            foreach (self::fetchStaleQueueRows($conn, $table, $staleMinutes) as $row) {
                $result['scanned']++;

                $id = (int)($row['id'] ?? 0);
                $userId = (int)($row['user_id'] ?? 0);
                $cost = (int)($row['coin_cost'] ?? 0);
                $holdId = (int)($row['hold_id'] ?? 0);

                $settle = self::refundQueueRow($conn, $table, $id, $userId, $cost, $holdId, $canRefund);
                if ($settle === 1) {
                    $result['refunded']++;
                }
                if ($settle === 2) {
                    // Hold sudah captured: media sah, jangan tandai gagal.
                    if (self::markQueueRowCompleted($conn, $table, $id)) {
                        $result['completed']++;
                    }
                    continue;
                }
                if ($settle < 0) {
                    // Refund/release gagal. Jangan tandai failed: baris failed
                    // tidak dipindai lagi dan biaya legacy bisa hilang permanen.
                    error_log(
                        '[MEeL] GarbageCollector: baris ' . $table . ' #' . $id
                        . ' belum terselesaikan; status dibiarkan agar sweeper berikutnya mencoba lagi.'
                    );
                    continue;
                }
                if (self::markQueueRowFailed($conn, $table, $id)) {
                    $result['failed']++;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fetchStaleQueueRows(\mysqli $conn, string $table, int $staleMinutes): array
    {
        $stmt = $conn->prepare(
            "SELECT id, user_id, coin_cost, " . ($table === 'upload_queue' ? 'hold_id' : 'NULL AS hold_id') . " FROM {$table}
             WHERE status IN ('pending', 'processing')
               AND coin_refunded = 0
               AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("i", $staleMinutes);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows ?: [];
    }

    /**
     * Selesaikan satu baris antrean yang macet.
     *
     * `SELECT ... FOR UPDATE` menahan baris itu selama transaksi, jadi dua
     * sweeper yang jalan bersamaan tidak bisa keduanya merefund baris yang
     * sama. Untuk baris ber-hold, hold dilepas tanpa memotong saldo; kalau
     * hold sudah captured, baris ditandai completed (media sudah sah).
     * Untuk baris legacy tanpa hold, refund memakai `coin_cost` yang benar-
     * benar tercatat saat baris dibuat.
     *
     * @return int 0=tidak ada perubahan, 1=refund/release, 2=hold captured,
     *             -1=penyelesaian gagal (baris harus dibiarkan untuk sweep berikutnya)
     */
    private static function refundQueueRow(
        \mysqli $conn,
        string $table,
        int $id,
        int $userId,
        int $cost,
        int $holdId,
        bool $canRefund
    ): int {
        if ($userId <= 0 || ($cost <= 0 && $holdId <= 0)) {
            return 0;
        }
        if (!$canRefund) {
            error_log(
                '[MEeL] GarbageCollector: kelas MeelCoin tidak termuat, '
                    . 'refund queue #' . $id . ' dilewati.'
            );
            return 0;
        }

        $conn->begin_transaction();
        try {
            $rowLock = self::lockQueueRow($conn, $table, $id);
            if ($rowLock === null || (int)$rowLock['coin_refunded'] !== 0) {
                $conn->rollback();
                return 0;
            }

            if ($holdId > 0) {
                $hold = MeelCoin::getHold($conn, $holdId);
                if ($hold !== null && (string)$hold['status'] === 'captured') {
                    $conn->rollback();
                    return 2;
                }
                $release = MeelCoin::releaseHoldInTransaction($conn, $holdId, 'queue_failed', 'released');
                if (!$release['ok']) {
                    $conn->rollback();
                    return -1;
                }
                if ((string)($release['status'] ?? '') === 'captured') {
                    $conn->rollback();
                    return 2;
                }
                // Hold yang dilepas tidak pernah memotong saldo total.
                if (!self::markQueueRowRefunded($conn, $table, $id)) {
                    $conn->rollback();
                    return -1;
                }
                $conn->commit();
                return $release['released'] > 0 ? 1 : 0;
            }

            if ($cost <= 0) {
                $conn->rollback();
                return 0;
            }

            if (!MeelCoin::refund($conn, $userId, $cost, 'upload_failed_refund')) {
                $conn->rollback();
                return -1;
            }

            if (!self::markQueueRowRefunded($conn, $table, $id)) {
                $conn->rollback();
                return -1;
            }

            $conn->commit();
            return 1;
        } catch (\Throwable $e) {
            $conn->rollback();
            error_log(
                '[MEeL] GarbageCollector: gagal refund queue #' . $id
                    . ' pada ' . $table . ': ' . $e->getMessage()
            );
            return -1;
        }
    }

    /**
     * Kunci baris antrean dan baca penanda refund-nya.
     *
     * Mengembalikan null kalau barisnya tidak ada atau statement-nya gagal.
     */
    private static function lockQueueRow(\mysqli $conn, string $table, int $id): ?array
    {
        $holdExpr = $table === 'upload_queue' ? 'hold_id' : 'NULL AS hold_id';
        $lock = $conn->prepare(
            "SELECT id, coin_refunded, {$holdExpr} FROM {$table} WHERE id = ? FOR UPDATE"
        );
        if (!$lock) {
            return null;
        }
        $lock->bind_param("i", $id);
        $lock->execute();
        $row = $lock->get_result()->fetch_assoc();
        $lock->close();
        return $row;
    }

    /**
     * Tandai baris antrean sebagai sudah direfund.
     */
    private static function markQueueRowRefunded(\mysqli $conn, string $table, int $id): bool
    {
        $mark = $conn->prepare(
            "UPDATE {$table} SET coin_refunded = 1 WHERE id = ?"
        );
        if (!$mark) {
            return false;
        }
        $mark->bind_param("i", $id);
        $ok = $mark->execute() && $mark->affected_rows > 0;
        $mark->close();
        return $ok;
    }

    private static function markQueueRowFailed(\mysqli $conn, string $table, int $id): bool
    {
        return self::markQueueRowStatus($conn, $table, $id, 'failed');
    }

    private static function markQueueRowCompleted(\mysqli $conn, string $table, int $id): bool
    {
        return self::markQueueRowStatus($conn, $table, $id, 'completed');
    }

    private static function markQueueRowStatus(\mysqli $conn, string $table, int $id, string $status): bool
    {
        if (!in_array($status, ['completed', 'failed'], true)) {
            return false;
        }
        $upd = $conn->prepare("UPDATE {$table} SET status = ? WHERE id = ?");
        if (!$upd) {
            return false;
        }
        $upd->bind_param("si", $status, $id);
        $ok = $upd->execute() && $upd->affected_rows > 0;
        $upd->close();

        return $ok;
    }
    private static function resetGuestsAutoIncrement(\mysqli $conn): void
    {
        if (!function_exists('purge_guest_users')) {
            error_log('[MEeL] GarbageCollector: purge_guest_users() tidak termuat, reset AUTO_INCREMENT dilewati.');
            return;
        }
        self::cleanGuests($conn);
    }
    public static function cleanGuests(\mysqli $conn): int
    {
        $throttleFile = dirname(__DIR__, 2) . '/temp/gc_guest_last_run.txt';
        if (self::isWithinThrottle($throttleFile, self::GUEST_CLEANUP_INTERVAL)) {
            return 0;
        }
        $totalCleaned = 0;
        $stmt = $conn->prepare(
            "UPDATE users SET is_active = 0 WHERE role = 'guest' AND is_active = 1 AND last_activity < DATE_SUB(NOW(), INTERVAL ? HOUR)"
        );
        if ($stmt) {
            $hours = self::GUEST_STALE_HOURS;
            $stmt->bind_param("i", $hours);
            $stmt->execute();
            $marked = $stmt->affected_rows;
            $stmt->close();
            if ($marked > 0) {
                $totalCleaned += $marked;
            }
        }
        $deleted = purge_guest_users($conn) ?? 0;
        if ($deleted > 0) {
            $totalCleaned += $deleted;
        }
        self::writeThrottleFile($throttleFile);
        return $totalCleaned;
    }
    public static function syncViews(\mysqli $conn): void
    {
        $throttleFile = dirname(__DIR__, 2) . '/temp/gc_views_sync_last_run.txt';
        $interval = 3600;
        if (self::isWithinThrottle($throttleFile, $interval)) return;
        if (!class_exists(MediaViewer::class)) {
            error_log('[MEeL] GarbageCollector::syncViews: kelas MediaViewer tidak termuat, dilewati.');
            return;
        }
        MediaViewer::syncViewsFromLogs($conn);
        self::writeThrottleFile($throttleFile);
    }
    public static function cleanChessRooms(\mysqli $conn): int
    {
        require_once __DIR__ . '/Modules.php';
        if (!Modules::enabled('arcade')) {
            return 0;
        }
        $throttleFile = dirname(__DIR__, 2) . '/temp/gc_chess_last_run.txt';
        if (self::isWithinThrottle($throttleFile, self::CHESS_CLEANUP_INTERVAL)) {
            return 0;
        }
        $totalCleaned = 0;
        $lobbyHours = self::ROOM_LOBBY_STALE_HOURS;
        $stmt = $conn->prepare(
            "DELETE FROM moves WHERE room_code IN (
                SELECT room_code FROM rooms
                WHERE black_joined = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
            )"
        );
        if ($stmt) {
            $stmt->bind_param("i", $lobbyHours);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare(
            "DELETE FROM rooms
             WHERE black_joined = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)"
        );
        if ($stmt) {
            $stmt->bind_param("i", $lobbyHours);
            $stmt->execute();
            $totalCleaned += $stmt->affected_rows;
            $stmt->close();
        }
        $gameHours = self::ROOM_GAME_STALE_HOURS;
        $staleRooms = "SELECT room_code FROM (
                SELECT r.room_code
                FROM rooms r
                LEFT JOIN (
                    SELECT room_code, MAX(created_at) AS last_move_at
                    FROM moves GROUP BY room_code
                ) m ON m.room_code = r.room_code
                WHERE r.black_joined = 1
                  AND NOT EXISTS (
                      SELECT 1 FROM moves t
                      WHERE t.room_code = r.room_code
                        AND JSON_UNQUOTE(JSON_EXTRACT(t.move_data, '$.type'))
                            IN ('resign','draw_accept','disconnect','game_over')
                  )
                  AND COALESCE(m.last_move_at, r.created_at) < DATE_SUB(NOW(), INTERVAL ? HOUR)
            ) AS stale_rooms";
        $stmt = $conn->prepare("DELETE FROM moves WHERE room_code IN ($staleRooms)");
        if ($stmt) {
            $stmt->bind_param("i", $gameHours);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM rooms WHERE room_code IN ($staleRooms)");
        if ($stmt) {
            $stmt->bind_param("i", $gameHours);
            $stmt->execute();
            $totalCleaned += $stmt->affected_rows;
            $stmt->close();
        }
        self::writeThrottleFile($throttleFile);
        return $totalCleaned;
    }
    public static function cleanCacheDirectory(): int
    {
        $cacheDir = self::getMediaCacheDir();
        if (!is_dir($cacheDir)) {
            return 0;
        }
        $items = glob(rtrim($cacheDir, '/') . '/*');
        if (empty($items)) {
            return 0;
        }
        $now = time();
        $deleted = 0;
        foreach ($items as $item) {
            if (self::deleteCacheItemIfExpired($item, $now)) {
                $deleted++;
            }
        }
        self::cleanEmptyCacheDirs($cacheDir);
        return $deleted;
    }

    /**
     * Apakah satu berkas cache sudah kadaluarsa dan boleh dihapus.
     *
     * Tiga kelompok berkas, urut dari yang paling spesifik:
     *  - berkas sementara (`*.tmp.<unix>.<hash>`) — dibuang setelah 5 menit
     *  - berkas dengan prefix yang dikenal — kadaluarsa menurut isinya
     *  - berkas lain — dibuang setelah 1 hari
     */
    private static function deleteCacheItemIfExpired(string $item, int $now): bool
    {
        $base = basename($item);

        if (preg_match('/\.tmp\.\d+\.[0-9a-f]{12}$/', $base)) {
            return self::deleteCacheItemOlderThan($item, self::CACHE_TMP_TTL, $now);
        }

        foreach (self::CACHE_TTL_MAP as $prefix => $ttl) {
            if (str_starts_with($base, $prefix)) {
                return self::deleteCacheItemIfStale($item, $ttl);
            }
        }

        return self::deleteCacheItemOlderThan($item, self::CACHE_ORPHAN_TTL, $now);
    }

    private static function deleteCacheItemOlderThan(string $item, int $seconds, int $now): bool
    {
        $mtime = @filemtime($item);
        if ($mtime === false || ($now - $mtime) <= $seconds) {
            return false;
        }
        return is_file($item) && @unlink($item);
    }

    private static function deleteCacheItemIfStale(string $item, int $ttl): bool
    {
        if (!self::isCacheExpired($item, $ttl)) {
            return false;
        }
        return is_file($item) && @unlink($item);
    }
    /**
     * Apakah satu berkas cache sudah melewati TTL-nya.
     *
     * Tiap keluarga cache memakai key waktu yang berbeda: `media_stats_`
     * dan `server_stats_info` memakai `ts`, `dirsize_` dan
     * `xsendfile_roots` memakai `time`, `admin_orphans` memakai
     * `checked_at`. Karena itu key-nya dicari berurutan, bukan satu nama
     * tetap. Berkas tanpa key waktu apa pun jatuh ke mtime.
     *
     * @see docs/{id,en}/modules.md
     */
    private static function isCacheExpired(string $path, int $ttl): bool
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return true;
        }
        $decoded = @json_decode($raw, true);
        if (!is_array($decoded)) {
            return true;
        }
        foreach (['ts', 'time', 'checked_at'] as $key) {
            if (isset($decoded[$key])) {
                $age = time() - (int) $decoded[$key];
                return $age < 0 || $age >= $ttl;
            }
        }
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return true;
        }
        return (time() - $mtime) >= $ttl;
    }
    private static function getMediaCacheDir(): string
    {
        if (function_exists('meel_media_cache_dir')) {
            return meel_media_cache_dir();
        }
        if (defined('MEEL_MEDIA_CACHE_DIR')) {
            return MEEL_MEDIA_CACHE_DIR;
        }
        return dirname(__DIR__, 2) . '/temp/cache';
    }
    private static function cleanEmptyCacheDirs(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $item) {
            if (is_dir($item)) {
                self::cleanEmptyCacheDirs($item);
                $rem = glob(rtrim($item, '/') . '/*') ?: [];
                if (empty($rem) && !rmdir($item)) {
                    error_log("[MEeL] GarbageCollector: gagal hapus dir kosong: {$item}");
                }
            }
        }
    }
    public const RUN_INTERVAL_SECONDS = 60;
    private static function isWithinThrottle(string $throttleFile, int $interval): bool
    {
        if (!is_readable($throttleFile)) {
            return false;
        }
        $lastRun = (int) file_get_contents($throttleFile);
        return $lastRun > 0 && (time() - $lastRun) < $interval;
    }
    private static function writeThrottleFile(string $throttleFile): void
    {
        $dir = dirname($throttleFile);
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log("[MEeL] GarbageCollector: throttle file tidak bisa ditulis: {$throttleFile}");
            return;
        }
        if (is_file($throttleFile) && !is_writable($throttleFile)) {
            if (!@unlink($throttleFile)) {
                error_log("[MEeL] GarbageCollector: throttle file tidak writable & gagal dihapus: {$throttleFile}");
                return;
            }
        }
        if (@file_put_contents($throttleFile, time()) === false) {
            error_log("[MEeL] GarbageCollector: gagal menulis throttle file: {$throttleFile}");
        }
    }
    public static function run(): void
    {
        if (self::$hasRun) return;
        self::$hasRun = true;

        $throttleFile = self::runThrottleFile();
        if (self::isWithinThrottle($throttleFile, self::RUN_INTERVAL_SECONDS)) {
            return;
        }
        $directories = self::getTargetDirectories();
        if (!empty($directories)) {
            $timeout = microtime(true) + 3;
            foreach ($directories as $dir) {
                if (microtime(true) >= $timeout) break;
                self::cleanDirectory($dir);
            }
        }
        self::cleanPendingFileDeletions();

        if (class_exists('RateLimiter')) {
            RateLimiter::cleanup();
        }
        self::writeThrottleFile($throttleFile);
    }
    private static function runThrottleFile(): string
    {
        return dirname(__DIR__, 2) . '/temp/gc_run_last_run.txt';
    }
    private static function cleanPendingFileDeletions(): void
    {
        $throttleFile = dirname(__DIR__, 2) . '/temp/gc_pending_delete_last_run.txt';
        $interval = 300;

        if (self::isWithinThrottle($throttleFile, $interval)) return;
        $pendingFile = dirname(__DIR__, 2) . '/temp/pending_delete.json';
        if (!file_exists($pendingFile)) {
            self::writeThrottleFile($throttleFile);
            return;
        }
        require_once __DIR__ . '/helpers/pending_delete.php';
        $cleaned = cleanupPendingDeletions();
        if ($cleaned > 0) {
            error_log("[MEeL] GarbageCollector: cleaned {$cleaned} pending file deletion(s) from queue.");
        }
        self::writeThrottleFile($throttleFile);
    }
    private static function getTargetDirectories(): array
    {
        $dirs = [];
        $project_temp = dirname(__DIR__, 2) . '/temp';
        if (is_dir($project_temp)) {
            $dirs[] = $project_temp;
        }
        if (is_dir('/dev/shm/meel/temp')) {
            $dirs[] = '/dev/shm/meel/temp';
        }
        if (is_dir('/dev/shm/meel/upload')) {
            $dirs[] = '/dev/shm/meel/upload';
        }
        if (is_dir('/dev/shm/meel/transcode')) {
            $dirs[] = '/dev/shm/meel/transcode';
        }
        return $dirs;
    }
    private static function cleanDirectory(string $dir): void
    {
        $cutoff = time() - self::STALE_SECONDS;
        $items = glob(rtrim($dir, '/') . '/*');
        if (empty($items)) return;

        foreach ($items as $item) {
            $basename = basename($item);
            if ($basename === 'ytdlp-cache') continue;
            if (in_array($basename, ['cache', 'ratelimit'], true)) continue;
            if (!file_exists($item)) continue;
            $mtime = filemtime($item);
            if ($mtime === false || $mtime > $cutoff) continue;
            if (is_dir($item)) {
                self::removeDirectory($item);
            } else {
                self::removeFile($item);
            }
        }
    }
    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $parent = dirname($dir);
        if (!is_dir($parent) || !is_writable($parent)) {
            error_log("[MEeL] GarbageCollector: direktori tidak writable, dilewati: {$dir}");
            return;
        }
        foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $item) {
            if (is_dir($item)) {
                self::removeDirectory($item);
            } else {
                self::removeFile($item);
            }
        }
        $remaining = glob(rtrim($dir, '/') . '/*') ?: [];
        if (empty($remaining) && !rmdir($dir)) {
            error_log("[MEeL] GarbageCollector: Gagal menghapus direktori: {$dir}");
        }
    }
    private static function removeFile(string $path): void
    {
        if (!is_file($path) && !is_link($path)) {
            return;
        }
        $parent = dirname($path);
        if (!is_dir($parent) || !is_writable($parent)) {
            error_log("[MEeL] GarbageCollector: direktori tidak writable, file dilewati: {$path}");
            return;
        }
        if (!unlink($path)) {
            error_log("[MEeL] GarbageCollector: Gagal menghapus file: {$path}");
        }
    }
}
/* reference build: MEeL-C8H11NO2 [ffd332a] */
