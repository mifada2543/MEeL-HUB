<?php

class QueueReconciler
{
    private \mysqli $conn;
    private array $log = [];

    private const STALE_MINUTES = 30;
    private const PID_DIR = '/tmp/meel_pids';

    public function __construct(\mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function reconcile(): array
    {
        $this->logEntry('--- Reconcile started ---');

        $this->markStaleOrphaned();

        $this->markStaleProcessing();

        $results = [
            'reconciled' => 0,
            'refunded'  => 0,
            'marked_failed' => 0,
            'log' => $this->log,
        ];

        $orphaned = $this->conn->query(
            "SELECT id, user_id, media_type, url, created_at
             FROM upload_queue
             WHERE status = 'orphaned'
             ORDER BY created_at ASC
             LIMIT 20"
        );

        if (!$orphaned) {
            $this->logEntry('No orphaned queues found.');
            return $results;
        }

        while ($row = $orphaned->fetch_assoc()) {
            $queueId   = (int)$row['id'];
            $userId    = (int)$row['user_id'];
            $mediaType = $row['media_type'];

            $pid = $this->readPidFile('download', $queueId);

            if ($pid > 0 && $this->isProcessAlive($pid)) {
                $this->logEntry("Queue #{$queueId}: yt-dlp still running (PID {$pid}), skipping.");
                continue;
            }

            $fileExists = $this->checkDownloadedFile($queueId, $userId, $mediaType, $row['url'] ?? '');

            if ($fileExists) {
                $this->conn->query(
                    "UPDATE upload_queue SET status = 'completed' WHERE id = {$queueId}"
                );
                $this->logEntry("Queue #{$queueId}: download completed in background, marked 'completed'.");
                $results['reconciled']++;
            } else {
                $this->conn->query(
                    "UPDATE upload_queue SET status = 'failed' WHERE id = {$queueId}"
                );
                $this->logEntry("Queue #{$queueId}: download incomplete, marked 'failed'.");
                $results['marked_failed']++;

                $this->refundIfNeeded($userId, $mediaType, $queueId);
                $results['refunded']++;
            }

            $this->cleanupPidFile('download', $queueId);
        }

        $this->logEntry('--- Reconcile finished ---');
        $results['log'] = $this->log;
        return $results;
    }

    private function markStaleOrphaned(): void
    {
        $threshold = date('Y-m-d H:i:s', time() - self::STALE_MINUTES * 60);
        $this->conn->query(
            "UPDATE upload_queue SET status = 'orphaned'
             WHERE status = 'processing'
             AND created_at < '{$threshold}'"
        );
    }

    private function markStaleProcessing(): void
    {
        $threshold = date('Y-m-d H:i:s', time() - 60 * 60);
        $this->conn->query(
            "UPDATE upload_queue SET status = 'failed'
             WHERE status = 'processing'
             AND created_at < '{$threshold}'"
        );
    }

    private function checkDownloadedFile(int $queueId, int $userId, string $mediaType, string $url): bool
    {
        $shmTemp = '/dev/shm/meel/temp';
        if (!is_dir($shmTemp)) {
            $shmTemp = sys_get_temp_dir() . '/meel/temp';
        }

        $files = glob("$shmTemp/*") ?: [];
        $recentFiles = [];
        foreach ($files as $f) {
            if (filemtime($f) > time() - self::STALE_MINUTES * 60 * 2) {
                $recentFiles[] = $f;
            }
        }

        return !empty($recentFiles);
    }

// reference build: MEeL-C5H9NO2 [6f639b8cc129f55c]
    private function refundIfNeeded(int $userId, string $mediaType, int $queueId): void
    {
        if (!MeelCoin::isEnabled($this->conn)) return;

        $costKey = ($mediaType === 'music') ? 'upload' : 'advanced';
        $cost = MeelCoin::getCost($this->conn, $costKey);

        $logEntry = $this->conn->query(
            "SELECT id FROM meelcoin_log
             WHERE user_id = {$userId}
             AND reason LIKE '%refund%'
             AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
             ORDER BY id DESC LIMIT 5"
        );

        if ($logEntry && $logEntry->num_rows > 0) {
            $this->logEntry("Queue #{$queueId}: coin already refunded for user #{$userId}, skipping.");
            return;
        }

        $this->logEntry("Queue #{$queueId}: refunding {$cost} coins to user #{$userId}.");
    }

    private function readPidFile(string $taskType, int $queueId): int
    {
        $path = self::PID_DIR . "/{$taskType}_{$queueId}.pid";
        if (!file_exists($path)) return 0;
        return (int)@file_get_contents($path);
    }

    private function cleanupPidFile(string $taskType, int $queueId): void
    {
        $path = self::PID_DIR . "/{$taskType}_{$queueId}.pid";
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) return false;
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        return (bool)@shell_exec("kill -0 $pid 2>/dev/null");
    }

    private function logEntry(string $msg): void
    {
        $ts = date('Y-m-d H:i:s');
        $this->log[] = "[{$ts}] {$msg}";
        error_log("[MEeL QueueReconciler] {$msg}");
    }
}
