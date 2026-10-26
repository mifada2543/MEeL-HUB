<?php
require_once __DIR__ . '/MeelCoin.php';

class UploadAttempt
{
    public const STALE_SECONDS = 900;
    public const TTL_SECONDS = 21600;

    private static array $shutdownGuards = [];

    public static function create(\mysqli $conn, int $userId, string $mediaType): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        if (class_exists('MeelCoin') && !MeelCoin::isEligibleUser($conn, $userId)) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $mediaType = substr($mediaType, 0, 20);
        $ttl = self::TTL_SECONDS;
        $stmt = $conn->prepare(
            "INSERT INTO upload_attempts
                (token, user_id, media_type, status, stage, last_heartbeat, expires_at)
             VALUES (?, ?, ?, 'active', 'created', NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("sisi", $token, $userId, $mediaType, $ttl);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        if ($id <= 0) {
            return null;
        }

        $row = self::get($conn, $id);
        return $row === null ? null : [
            'id' => $id,
            'token' => $token,
            'expires_at' => (string)($row['expires_at'] ?? ''),
        ];
    }

    public static function get(\mysqli $conn, int $attemptId): ?array
    {
        if ($attemptId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("SELECT * FROM upload_attempts WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $attemptId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public static function findActiveByToken(\mysqli $conn, string $token, int $userId): ?array
    {
        if ($token === '' || $userId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT * FROM upload_attempts
              WHERE token = ? AND user_id = ? AND status = 'active' AND expires_at > NOW()
              LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("si", $token, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public static function canAbortFromClient(array $attempt): bool
    {
        $mediaType = strtolower((string)($attempt['media_type'] ?? ''));
        $holdId = (int)($attempt['hold_id'] ?? 0);
        return !($mediaType === 'advanced' && $holdId > 0);
    }

    public static function attachHold(\mysqli $conn, int $attemptId, int $holdId, int $userId): bool
    {
        if ($attemptId <= 0 || $holdId <= 0 || $userId <= 0) {
            return false;
        }
        $hold = MeelCoin::getHold($conn, $holdId);
        if ($hold === null || (int)$hold['user_id'] !== $userId || (string)$hold['status'] !== 'held') {
            return false;
        }

        $owns = MeelCoin::databaseInTransaction($conn);
        if (!$owns) {
            $conn->begin_transaction();
        }
        try {
            $stmt = $conn->prepare(
                "UPDATE upload_attempts
                    SET hold_id = ?, client_heartbeat_at = NOW(), last_heartbeat = NOW()
                  WHERE id = ? AND user_id = ? AND status = 'active' AND hold_id IS NULL"
            );
            $stmt->bind_param("iii", $holdId, $attemptId, $userId);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $stmt->close();
            if (!$ok) {
                throw new RuntimeException('Sesi upload tidak aktif.');
            }

            $stmt = $conn->prepare(
                "UPDATE coin_holds SET upload_attempt_id = ?
                  WHERE id = ? AND user_id = ? AND status = 'held'"
            );
            $stmt->bind_param("iii", $attemptId, $holdId, $userId);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                $stmt->close();
                throw new RuntimeException('Reservasi MEeLCoin tidak dapat diikat ke sesi upload.');
            }
            $stmt->close();

            if (!$owns) {
                $conn->commit();
            }
            return true;
        } catch (Throwable $e) {
            if (!$owns) {
                $conn->rollback();
            }
            error_log('[MEeL] UploadAttempt::attachHold: ' . $e->getMessage());
            return false;
        }
    }

    public static function setStage(\mysqli $conn, int $attemptId, string $stage): void
    {
        if ($attemptId <= 0) {
            return;
        }
        $stage = substr($stage, 0, 50);
        $stmt = $conn->prepare(
            "UPDATE upload_attempts SET stage = ?, last_heartbeat = NOW()
              WHERE id = ? AND status = 'active'"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("si", $stage, $attemptId);
        $stmt->execute();
        $stmt->close();
    }

    public static function addCleanupPaths(\mysqli $conn, int $attemptId, array $paths): void
    {
        if ($attemptId <= 0 || empty($paths)) {
            return;
        }
        $row = self::get($conn, $attemptId);
        if ($row === null || (string)$row['status'] !== 'active') {
            return;
        }

        $existing = [];
        if (!empty($row['cleanup_json'])) {
            $decoded = json_decode((string)$row['cleanup_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_string($item) && $item !== '') {
                        $existing[] = $item;
                    }
                }
            }
        }
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
                continue;
            }
            $existing[] = $path;
        }
        $existing = array_values(array_unique($existing));
        if (count($existing) > 30) {
            $existing = array_slice($existing, -30);
        }
        $json = json_encode($existing, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $stmt = $conn->prepare(
            "UPDATE upload_attempts SET cleanup_json = ?, last_heartbeat = NOW()
              WHERE id = ? AND status = 'active'"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("si", $json, $attemptId);
        $stmt->execute();
        $stmt->close();
    }

    public static function touchByToken(\mysqli $conn, string $token, int $userId): array
    {
        $result = ['ok' => false, 'error' => '', 'status' => '', 'hold_id' => 0, 'attempt' => null];
        $row = self::findActiveByToken($conn, $token, $userId);
        if ($row === null) {
            $result['status'] = 'not_found';
            $result['error'] = 'Sesi upload tidak ditemukan atau sudah kedaluwarsa.';
            return $result;
        }

        $stmt = $conn->prepare(
            "UPDATE upload_attempts
                SET last_heartbeat = NOW(), client_heartbeat_at = NOW()
              WHERE id = ? AND user_id = ? AND status = 'active'"
        );
        $attemptId = (int)$row['id'];
        $stmt->bind_param("ii", $attemptId, $userId);
        $stmt->execute();
        $stmt->close();

        $holdId = (int)($row['hold_id'] ?? 0);
        if ($holdId > 0) {
            $hold = MeelCoin::getHold($conn, $holdId);
            if ($hold === null || (string)$hold['status'] !== 'held') {
                self::fail($conn, $attemptId, 'hold_not_held', true);
                $result['status'] = ($hold !== null && (string)$hold['status'] === 'captured')
                    ? 'completed'
                    : 'not_found';
                $result['error'] = 'Sesi upload sudah selesai.';
                return $result;
            }
            MeelCoin::touchHold($conn, $holdId, $attemptId);
        }
        $result['ok'] = true;
        $result['status'] = 'active';
        $result['hold_id'] = $holdId;
        $result['attempt'] = $row;
        return $result;
    }

    public static function complete(\mysqli $conn, int $attemptId): void
    {
        if ($attemptId <= 0) {
            return;
        }
        $stmt = $conn->prepare(
            "UPDATE upload_attempts
                SET status = 'completed', finished_at = NOW(),
                    stage = 'completed', cleanup_json = NULL, last_heartbeat = NOW()
              WHERE id = ? AND status = 'active'"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("i", $attemptId);
        $stmt->execute();
        $stmt->close();
    }

    public static function fail(\mysqli $conn, int $attemptId, string $reason = 'upload_failed', bool $cleanup = true): array
    {
        $result = ['ok' => false, 'released' => 0, 'captured' => false, 'status' => ''];
        if ($attemptId <= 0) {
            return $result;
        }
        $row = self::get($conn, $attemptId);
        if ($row === null) {
            return $result;
        }
        if ((string)$row['status'] !== 'active') {
            $result['ok'] = true;
            $result['status'] = (string)$row['status'];
            return $result;
        }

        $holdId = (int)($row['hold_id'] ?? 0);
        if ($holdId > 0) {
            $hold = MeelCoin::getHold($conn, $holdId);
            if ($hold !== null && (string)$hold['status'] === 'captured') {
                $result['captured'] = true;
                self::markSettledWithoutCleanup($conn, $attemptId, 'settled_after_error');
                $result['ok'] = true;
                $result['status'] = 'completed';
                return $result;
            }

            $release = MeelCoin::releaseHold($conn, $holdId, $reason, 'released');
            if (!$release['ok']) {
                error_log('[MEeL] UploadAttempt::fail release hold #' . $holdId . ': ' . $release['error']);
                return $result;
            }
            if ((string)($release['status'] ?? '') === 'captured') {
                $result['captured'] = true;
                self::markSettledWithoutCleanup($conn, $attemptId, 'settled_after_error');
                $result['ok'] = true;
                $result['status'] = 'completed';
                return $result;
            }
            $result['released'] = (int)$release['released'];
        }

        $stmt = $conn->prepare(
            "UPDATE upload_attempts
                SET status = 'failed', finished_at = NOW(), stage = ?,
                    cleanup_json = NULL, last_heartbeat = NOW()
              WHERE id = ? AND status = 'active'"
        );
        $stage = substr($reason !== '' ? $reason : 'failed', 0, 50);
        $stmt->bind_param("si", $stage, $attemptId);
        $stmt->execute();
        $stmt->close();

        if ($cleanup) {
            self::cleanupPaths($row);
        }
        $result['ok'] = true;
        $result['status'] = 'failed';
        return $result;
    }

    private static function markSettledWithoutCleanup(\mysqli $conn, int $attemptId, string $stage): void
    {
        $stmt = $conn->prepare(
            "UPDATE upload_attempts
                SET status = 'completed', finished_at = NOW(), stage = ?,
                    cleanup_json = NULL, last_heartbeat = NOW()
              WHERE id = ? AND status = 'active'"
        );
        $stage = substr($stage, 0, 50);
        $stmt->bind_param("si", $stage, $attemptId);
        $stmt->execute();
        $stmt->close();
    }

    public static function sweepStale(\mysqli $conn, ?int $staleSeconds = null, int $limit = 100): array
    {
        $stale = max(60, $staleSeconds ?? self::STALE_SECONDS);
        $limit = max(1, min(500, $limit));
        $stmt = $conn->prepare(
            "SELECT id FROM upload_attempts
              WHERE status = 'active'
                AND (expires_at < NOW() OR last_heartbeat < DATE_SUB(NOW(), INTERVAL ? SECOND))
              ORDER BY id ASC LIMIT {$limit}"
        );
        if (!$stmt) {
            return ['failed' => 0, 'completed' => 0];
        }
        $stmt->bind_param("i", $stale);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $failed = 0;
        $completed = 0;
        foreach ($rows as $row) {
            $res = self::fail($conn, (int)$row['id'], 'stale_upload', true);
            if ($res['captured']) {
                $completed++;
            } elseif ($res['ok']) {
                $failed++;
            }
        }
        return ['failed' => $failed, 'completed' => $completed];
    }

    public static function registerShutdownGuard(\mysqli $conn, int $attemptId): void
    {
        if ($attemptId <= 0 || isset(self::$shutdownGuards[$attemptId])) {
            return;
        }
        self::$shutdownGuards[$attemptId] = true;
        register_shutdown_function(static function () use ($conn, $attemptId): void {
            try {
                $row = self::get($conn, $attemptId);
                if ($row === null || (string)$row['status'] !== 'active') {
                    return;
                }
                if (MeelCoin::databaseInTransaction($conn)) {
                    $conn->rollback();
                }
                self::fail($conn, $attemptId, 'request_shutdown', true);
            } catch (Throwable $e) {
                error_log('[MEeL] UploadAttempt shutdown guard: ' . $e->getMessage());
            }
        });
    }

    public static function cleanupPaths(array $attempt): void
    {
        if (empty($attempt['cleanup_json'])) {
            return;
        }
        $decoded = json_decode((string)$attempt['cleanup_json'], true);
        if (!is_array($decoded)) {
            return;
        }

        $roots = [];
        $projectRoot = defined('MEEL_ROOT') ? (string)MEEL_ROOT : dirname(__DIR__, 2);
        foreach ([
            defined('MEEL_HDD_BASE') ? (string)MEEL_HDD_BASE : '',
            $projectRoot . '/temp',
            '/dev/shm/meel',
            sys_get_temp_dir(),
        ] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $real = realpath($candidate);
            if ($real !== false) {
                $roots[] = rtrim($real, DIRECTORY_SEPARATOR);
            }
        }
        $roots = array_values(array_unique($roots));

        foreach ($decoded as $path) {
            if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
                continue;
            }
            $real = realpath($path);
            if ($real === false) {
                continue;
            }
            $allowed = false;
            foreach ($roots as $root) {
                if ($real === $root) {
                    continue 2;
                }
                if (str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                error_log('[MEeL] UploadAttempt: menolak cleanup path di luar root aman: ' . $real);
                continue;
            }
            if (is_dir($real) && !is_link($real)) {
                self::removeDirectory($real);
            } elseif (is_file($real) || is_link($real)) {
                @unlink($real);
            }
        }
    }

    private static function removeDirectory(string $dir): void
    {
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

/* reference build: MEeL-C5H9NO2 [ffd332a] */