<?php
require_once __DIR__ . '/../core/TranscoderBase.php';
require_once __DIR__ . '/../core/MeelCoin.php';
require_once __DIR__ . '/../core/UploadAttempt.php';

class EncodeService extends TranscoderBase
{
    private int $currentHoldId = 0;
    private int $currentAttemptId = 0;
    private int $currentQueueId = 0;
    private const ENCODE_TIMEOUT = 7200; // 2 jam hard cap
    private const ENCODE_KEEPALIVE_INTERVAL = 30; // detik

    public function encodeMusic(
        string $temp_file,
        string $title,
        string $artist,
        string $album,
        int $duration,
        string $description = 'Upload by MEeL Engine',
        int $holdId = 0,
        int $attemptId = 0,
        int $queueId = 0
    ): array {
        $this->currentHoldId = max(0, $holdId);
        $this->currentAttemptId = max(0, $attemptId);
        $this->currentQueueId = max(0, $queueId);
        putenv("LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu:/usr/local/lib");
        putenv("PATH=/usr/local/bin:/usr/bin:/bin");

        $music_dir = meel_media_base_path('music') . '/file';
        if (($err = $this->assertEncodeStorageReady($music_dir)) !== null) {
            return $err;
        }

        if (!$this->isValidSourceToken($temp_file)) {
            error_log("[MEeL] encodeMusic: token input tidak valid ($temp_file)");
            return ['status' => 'error', 'msg' => "Token file sumber audio tidak valid."];
        }

        $shm_temp = $this->getShmTempPath();
        $input_path = $shm_temp . '/' . $temp_file;

        $lock_path = sys_get_temp_dir() . '/meel_encode_' . md5($temp_file) . '.lock';
        $lock_fp = fopen($lock_path, 'c');
        $lock_held = $lock_fp !== false && flock($lock_fp, LOCK_EX);

        try {
            if ($lock_held && !file_exists($input_path)) {
                $recent = $this->findRecentEncodedFile();
                if ($recent !== null) {
                    error_log("[MEeL] encodeMusic: input $temp_file sudah diproses request lain → dianggap sukses (file: {$recent})");
                    if ($this->currentHoldId > 0) {
                        MeelCoin::releaseHold($this->conn, $this->currentHoldId, 'already_processed', 'released');
                    }
                    if ($this->currentQueueId > 0) {
                        $this->settleQueue('completed');
                    }
                    return ['status' => 'success', 'filename' => $recent, 'already_processed' => true];
                }
            }

            $target = $this->reserveEncodeTarget($music_dir, $title);
            $final_fname = $target['filename'];
            $final_path = $target['path'];
            $thumb_name = $target['thumb'];
            if ($this->currentAttemptId > 0 && class_exists('UploadAttempt')) {
                UploadAttempt::addCleanupPaths($this->conn, $this->currentAttemptId, [
                    $final_path,
                    meel_media_base_path('music') . '/thumbnail/' . $thumb_name,
                ]);
            }
            $this->touchAccounting();

            $opus_result = $this->runOpusEncode($input_path, $final_path, [
                'title' => $title,
                'artist' => $artist,
            ]);
            if ($opus_result['exit'] !== 0) {
                $log = $opus_result['log'];
                $friendly = $this->describeEncodeFailure($input_path, $log);
                $this->removeFile($final_path);
                error_log("[MEeL] encodeMusic GAGAL ($temp_file): " . substr(trim($log), 0, 800));
                $this->settleQueue('failed');
                return ['status' => 'error', 'msg' => $friendly];
            }
            if (!$this->isClientConnected()) {
                $this->removeFile($final_path);
                return ['status' => 'error', 'msg' => 'Koneksi upload terputus; encode dibatalkan tanpa memotong coin.'];
            }

            $temp_base = pathinfo($temp_file, PATHINFO_FILENAME);
            $temp_dir = $this->getShmTempPath();
            $thumb_result = $this->extractMusicThumbnail($input_path, $temp_dir, $temp_base, $thumb_name);
            if ($thumb_result !== 'music_default.png') {
                $thumb_path = meel_media_base_path('music') . '/thumbnail/' . $thumb_result;
                if (!is_file($thumb_path) || filesize($thumb_path) < 1) {
                    $this->removeFile($final_path);
                    return ['status' => 'error', 'msg' => 'Thumbnail musik tidak ditemukan setelah encode.'];
                }
            }

            $metadata = generate_search_metadata($title, $artist, $album);
            $this->touchAccounting();
            $this->conn->begin_transaction();
            try {
                $ins = meel_insert_music_row($this->conn, $this->user_id, $title, $artist, $album, $description, $metadata, $final_fname, $thumb_result, $duration);
                if (!$ins[0]) {
                    throw new \RuntimeException($ins[1]);
                }
                $musicId = (int)$this->conn->insert_id;

                if ($this->currentHoldId > 0) {
                    $capture = MeelCoin::captureHoldInTransaction(
                        $this->conn,
                        $this->currentHoldId,
                        $this->user_id,
                        'upload_advanced',
                        $this->currentAttemptId > 0 ? $this->currentAttemptId : null,
                        'music',
                        $musicId
                    );
                    if (!$capture['ok']) {
                        throw new \RuntimeException($capture['error']);
                    }
                }
                $this->conn->commit();
            } catch (\Throwable $e) {
                $this->conn->rollback();
                $this->removeFile($final_path);
                if ($thumb_result !== 'music_default.png') {
                    $this->removeFile(meel_media_base_path('music') . '/thumbnail/' . $thumb_result);
                }
                return ['status' => 'error', 'msg' => 'Upload musik gagal diverifikasi: ' . $e->getMessage()];
            }

            $this->removeFile($input_path);
            foreach (glob("$temp_dir/$temp_base.*") ?: [] as $leftover) {
                $this->removeFile($leftover);
            }

            if ($this->currentQueueId > 0) {
                $this->settleQueue('completed');
            }

            return ['status' => 'success', 'filename' => $final_fname, 'id' => $musicId];
        } finally {
            if ($lock_held) {
                flock($lock_fp, LOCK_UN);
                fclose($lock_fp);
            }
        }
    }

    private function runOpusEncode(string $input, string $output, array $metadata): array
    {
        $timeout = self::ENCODE_TIMEOUT;
        if ($this->currentAttemptId > 0 && class_exists('UploadAttempt')) {
            UploadAttempt::setStage($this->conn, $this->currentAttemptId, 'encoding_music');
        }

        $args = [
            $this->ffmpeg_bin,
            '-y',
            '-i', $input,
            '-c:a', 'libopus',
            '-vbr', 'on',
            '-compression_level', '10',
        ];
        foreach ($metadata as $key => $value) {
            $args[] = '-metadata';
            $args[] = $key . '=' . $value;
        }
        $args[] = $output;

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'LD_LIBRARY_PATH' => '/usr/lib/x86_64-linux-gnu:/usr/local/lib',
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
        ];

        $process = proc_open($args, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            return ['exit' => -1, 'log' => 'Gagal menjalankan FFmpeg.'];
        }

        fclose($pipes[0]);
        $stdout = $pipes[1];
        $stderr = $pipes[2];
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $start = time();
        $lastKeepalive = $start;
        $log = '';
        $exitCode = -1;

        while (true) {
            $now = time();
            if ($now - $start > $timeout) {
                proc_terminate($process);
                proc_close($process);
                return ['exit' => 124, 'log' => $log . "\n[ERROR] Encode timeout ({$timeout}s)"]; 
            }

            if ($now - $lastKeepalive >= self::ENCODE_KEEPALIVE_INTERVAL) {
                $lastKeepalive = $now;
                if (PHP_SAPI !== 'cli') {
                    echo ' ';
                    @flush();
                }
                if (!$this->isClientConnected()) {
                    proc_terminate($process);
                    proc_close($process);
                    return ['exit' => 130, 'log' => $log . "\n[ERROR] Client disconnected during encode"];
                }
                $this->touchAccounting();
            }

            $read = [$stdout, $stderr];
            $write = $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($read as $stream) {
                $data = fread($stream, 8192);
                if ($data === '' || $data === false) {
                    continue;
                }
                $log .= $data;
                if (strlen($log) > 100000) {
                    $log = substr($log, -100000);
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int)$status['exitcode'];
                break;
            }
        }

        if ($exitCode === -1) {
            $exitCode = proc_close($process);
        } else {
            proc_close($process);
        }

        fclose($stdout);
        fclose($stderr);

        return ['exit' => $exitCode, 'log' => $log];
    }

    private function settleQueue(string $status): void
    {
        if ($this->currentQueueId <= 0) {
            return;
        }
        $stmt = $this->conn->prepare(
            "UPDATE upload_queue SET status = ? WHERE id = ? AND status IN ('pending', 'processing')"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("si", $status, $this->currentQueueId);
        $stmt->execute();
        $stmt->close();
    }

    private function touchAccounting(): void
    {
        if ($this->currentHoldId > 0) {
            MeelCoin::touchHold(
                $this->conn,
                $this->currentHoldId,
                $this->currentAttemptId > 0 ? $this->currentAttemptId : null
            );
        }
        if ($this->currentAttemptId > 0 && class_exists('UploadAttempt')) {
            UploadAttempt::setStage($this->conn, $this->currentAttemptId, 'encoding_music');
        }
    }

    private function assertEncodeStorageReady(string $music_dir): ?array
    {
        if (!is_dir($music_dir)) {
            $this->ensureDir($music_dir);
        }

        $hdd_free = disk_free_space($music_dir);
        if ($hdd_free !== false && $hdd_free < 500 * 1024 * 1024) {
            return [
                'status' => 'error',
                'msg' => 'Storage HDD untuk musik tidak mencukupi (hanya '
                    . sprintf('%.1f', $hdd_free / (1024 ** 3)) . ' GB free).',
            ];
        }

        return null;
    }

    private function isValidSourceToken(string $temp_file): bool
    {
        return $temp_file !== ''
            && preg_match('/^[A-Za-z0-9._-]+$/', $temp_file) === 1
            && !str_contains($temp_file, '..');
    }

    private function findRecentEncodedFile(): ?string
    {
        $stmt_recent = $this->conn->prepare(
            "SELECT filename FROM music
             WHERE user_id = ? AND upload_date >= NOW() - INTERVAL 60 SECOND
             ORDER BY id DESC LIMIT 1"
        );
        if (!$stmt_recent) {
            return null;
        }

        $stmt_recent->bind_param("i", $this->user_id);
        $stmt_recent->execute();
        $row = $stmt_recent->get_result()->fetch_assoc();
        $stmt_recent->close();

        if (empty($row['filename'])) {
            return null;
        }

        return (string) $row['filename'];
    }

    private function reserveEncodeTarget(string $music_dir, string $title): array
    {
        $clean = meel_sanitize_clean_name(getRomajiName($title), 120);
        if ($clean === '') {
            $clean = 'track';
        }

        $final_fname = meel_reserve_unique_filename($music_dir, $clean, 'ogg');
        if ($final_fname === null) {
            $final_fname = $clean . "-" . time() . ".ogg";
        }

        return [
            'filename' => $final_fname,
            'path' => $music_dir . "/$final_fname",
            'thumb' => str_replace('.ogg', '.webp', $final_fname),
        ];
    }

    private function describeEncodeFailure(string $input_path, string $log): string
    {
        if (!file_exists($input_path)) {
            return "File sumber audio tidak ditemukan. Media mungkin sudah diproses — periksa library Anda, atau coba upload ulang.";
        }

        if ($log === '') {
            return "Gagal mengonversi audio. Silakan coba lagi nanti.";
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $log))));
        foreach (array_reverse($lines) as $line) {
            if (preg_match('/error|failed|invalid/i', $line)) {
                return "Gagal mengonversi audio: " . substr($line, 0, 160);
            }
        }

        return "Gagal mengonversi audio. Silakan coba lagi nanti.";
    }

    private function extractMusicThumbnail(
        string $audio_file,
        string $temp_dir,
        string $temp_base,
        string $target_name
    ): string {
        $thumb_dir = meel_media_base_path('music') . '/thumbnail';
        if (!is_dir($thumb_dir)) {
            $this->ensureDir($thumb_dir);
        }

        foreach (['.jpg', '.webp', '.png', '.jpeg'] as $ext) {
            $pattern = "$temp_dir/$temp_base$ext";
            if (file_exists($pattern) && filesize($pattern) > 0) {
                return $this->convertAndSaveThumbnail($pattern, $thumb_dir, $target_name);
            }
        }

        $extracted = $this->extractThumbnailFromAudio($audio_file, $thumb_dir, $target_name);
        if ($extracted !== 'music_default.png') return $extracted;

        return 'music_default.png';
    }

    private function convertAndSaveThumbnail(
        string $source_image,
        string $target_dir,
        string $target_name
    ): string {
        $target_path = "$target_dir/$target_name";
        $src_ext = strtolower(pathinfo($source_image, PATHINFO_EXTENSION));

        if ($src_ext === 'webp') {
            if (copy($source_image, $target_path)) {
                $this->removeFile($source_image);
                return $target_name;
            }
        }

        $ok = meel_ffmpeg_thumbnail_webp($this->ffmpeg_bin, $source_image, $target_path, 500, '', self::ENV_PREFIX, 1);

        if ($ok) {
            $this->removeFile($source_image);
            return $target_name;
        }

        if (copy($source_image, $target_path)) {
            $this->removeFile($source_image);
            return $target_name;
        }

        $this->removeFile($source_image);
        return 'music_default.png';
    }

    private function extractThumbnailFromAudio(
        string $audio_file,
        string $target_dir,
        string $target_name
    ): string {
        if (!file_exists($audio_file) || filesize($audio_file) === 0) {
            return 'music_default.png';
        }

        $temp_extracted = "$target_dir/.temp_thumb_" . time() . "_" . random_int(1000, 9999) . ".webp";

        $ok = meel_ffmpeg_thumbnail_webp($this->ffmpeg_bin, $audio_file, $temp_extracted, 500, '-an -vframes 1', self::ENV_PREFIX, 1);

        if ($ok && filesize($temp_extracted) > 1000) {
            $final_path = "$target_dir/$target_name";
            if (rename($temp_extracted, $final_path)) {
                return $target_name;
            }
            $this->removeFile($temp_extracted);
        }

        $this->removeFile($temp_extracted);
        return 'music_default.png';
    }
}

/* reference build: MEeL-C3H7NO2S [ffd332a] */