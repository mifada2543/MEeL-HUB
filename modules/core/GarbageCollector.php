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
    }
    /** @return array{scanned:int, failed:int, refunded:int}  */
    public static function sweepStuckQueues(\mysqli $conn, ?int $staleMinutes = null): array
    {
        $minutes = $staleMinutes ?? self::QUEUE_STUCK_MINUTES;
        $result = ['scanned' => 0, 'failed' => 0, 'refunded' => 0];
        $canRefund = class_exists('MeelCoin');
        foreach ([['upload_queue', 'upload'], ['transcode_queue', 'transcode']] as [$table, $reason]) {
            $stmt = $conn->prepare(
                "SELECT id, user_id, coin_cost FROM {$table}
                 WHERE status IN ('pending', 'processing')
                   AND coin_refunded = 0
                   AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
            );
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param("i", $minutes);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($rows as $row) {
                $result['scanned']++;

                $id = (int)$row['id'];
                $userId = (int)$row['user_id'];
                $cost = (int)($row['coin_cost'] ?? 0);
                $refunded = false;
                if ($canRefund && $cost > 0 && $userId > 0) {
                    $conn->begin_transaction();
                    try {
                        $lock = $conn->prepare(
                            "SELECT id, coin_refunded FROM {$table} WHERE id = ? FOR UPDATE"
                        );
                        if ($lock) {
                            $lock->bind_param("i", $id);
                            $lock->execute();
                            $rowLock = $lock->get_result()->fetch_assoc();
                            $lock->close();
                            if ($rowLock && (int)$rowLock['coin_refunded'] === 0) {
                                if (MeelCoin::refund($conn, $userId, $cost, 'upload_failed_refund')) {
                                    $mark = $conn->prepare(
                                        "UPDATE {$table} SET coin_refunded = 1 WHERE id = ?"
                                    );
                                    if ($mark) {
                                        $mark->bind_param("i", $id);
                                        $mark->execute();
                                        $mark->close();
                                        $refunded = true;
                                    }
                                }
                            }
                        }
                        $conn->commit();
                    } catch (\Throwable $e) {
                        $conn->rollback();
                        error_log(
                            '[MEeL] GarbageCollector: gagal refund queue #' . $id
                                . ' pada ' . $table . ': ' . $e->getMessage()
                        );
                    }
                } elseif ($cost > 0 && !$canRefund) {
                    error_log(
                        '[MEeL] GarbageCollector: kelas MeelCoin tidak termuat, '
                            . 'refund queue #' . $id . ' dilewati.'
                    );
                }
                $upd = $conn->prepare("UPDATE {$table} SET status = 'failed' WHERE id = ?");
                if ($upd) {
                    $upd->bind_param("i", $id);
                    $upd->execute();
                    if ($upd->affected_rows > 0) {
                        $result['failed']++;
                    }
                    $upd->close();
                }
                if ($refunded) {
                    $result['refunded']++;
                }
            }
        }
        if ($result['scanned'] > 0) {
            error_log(
                sprintf(
                    '[MEeL] GarbageCollector: sweeper antrean — %d baris macet, %d ditandai failed, %d direfund',
                    $result['scanned'],
                    $result['failed'],
                    $result['refunded']
                )
            );
        }
        return $result;
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
        $ttlMap = [
            'media_stats_'       => 30,
            'admin_orphans'      => 120,
            'dirsize_'           => 300,
            'server_stats_info'  => 300,
            'xsendfile_roots'    => 86400,
        ];
        $now = time();
        $deleted = 0;
        $items = glob(rtrim($cacheDir, '/') . '/*');
        if (empty($items)) {
            return 0;
        }
        foreach ($items as $item) {
            $base = basename($item);
            if (preg_match('/\.tmp\.\d+\.[0-9a-f]{12}$/', $base)) {
                $mtime = @filemtime($item);
                if ($mtime !== false && ($now - $mtime) > 300) {
                    if (is_file($item) && @unlink($item)) {
                        $deleted++;
                    }
                }
                continue;
            }
            $expired = false;
            $prefixMatched = false;
            foreach ($ttlMap as $prefix => $ttl) {
                if (str_starts_with($base, $prefix)) {
                    $prefixMatched = true;
                    $expired = self::isCacheExpired($item, $ttl);
                    break;
                }
            }
            if (!$prefixMatched) {
                $mtime = @filemtime($item);
                if ($mtime !== false && ($now - $mtime) > 86400) {
                    $expired = true;
                }
            }
            if ($expired && is_file($item) && @unlink($item)) {
                $deleted++;
            }
        }
        self::cleanEmptyCacheDirs($cacheDir);
        return $deleted;
    }
    private static function isCacheExpired(string $path, int $ttl): bool
    {
        if (function_exists('meel_read_cache_file')) {
            $data = @meel_read_cache_file($path, 0, [], '');
            if ($data !== null) {
                return false;
            }
            return true;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return true;
        }
        $decoded = @json_decode($raw, true);
        if (!is_array($decoded)) {
            return true;
        }
        $timeKey = '';
        foreach (['ts', 'time', 'checked_at'] as $k) {
            if (isset($decoded[$k])) {
                $timeKey = $k;
                break;
            }
        }
        if ($timeKey === '') {
            return false;
        }
        $age = time() - (int)$decoded[$timeKey];
        return $age >= $ttl;
    }
    private static function getMediaCacheDir(): string
    {
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
/* reference build: MEeL-C8H11NO2 [65c57d32da4d271e] */
