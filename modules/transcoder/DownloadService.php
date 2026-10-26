<?php
require_once __DIR__ . '/../core/TranscoderBase.php';
require_once __DIR__ . '/../core/MeelCoin.php';
require_once __DIR__ . '/../core/UploadAttempt.php';
class DownloadService extends TranscoderBase
{
    private int $currentHoldId = 0;
    private int $currentAttemptId = 0;
    private int $lastQueueId = 0;

    private function lockQueue(string $url, string $type): int
    {
        $holdId = self::$pendingHoldId > 0 ? self::$pendingHoldId : 0;
        $attemptId = self::$pendingAttemptId > 0 ? self::$pendingAttemptId : 0;
        $stmt = $this->conn->prepare(
            "INSERT INTO upload_queue (url, media_type, user_id, status, coin_cost, hold_id)
             VALUES (?, ?, ?, 'processing', ?, NULLIF(?, 0))"
        );
        $stmt->bind_param("ssiii", $url, $type, $this->user_id, self::$pendingCoinCost, $holdId);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        $this->currentHoldId = $holdId;
        $this->currentAttemptId = $attemptId;
        $this->lastQueueId = $id;
        self::$pendingCoinCost = 0;
        self::$pendingHoldId = 0;
        self::$pendingAttemptId = 0;
        if ($this->currentAttemptId > 0) {
            UploadAttempt::setStage($this->conn, $this->currentAttemptId, 'queue_processing');
        }
        return $id;
    }

    public function getLastQueueId(): int
    {
        return $this->lastQueueId;
    }

    private static int $pendingCoinCost = 0;
    private static int $pendingHoldId = 0;
    private static int $pendingAttemptId = 0;

    public static function setPendingCoinCost(int $amount): void
    {
        self::$pendingCoinCost = $amount > 0 ? $amount : 0;
    }

    public static function setPendingCoinHold(int $holdId): void
    {
        self::$pendingHoldId = $holdId > 0 ? $holdId : 0;
    }

    public static function setPendingAttemptId(int $attemptId): void
    {
        self::$pendingAttemptId = $attemptId > 0 ? $attemptId : 0;
    }

    private function releaseQueue(int $queue_id, string $status = 'failed'): void
    {
        $allowed = ['completed', 'failed'];
        $status = in_array($status, $allowed, true) ? $status : 'failed';
        $stmt = $this->conn->prepare("UPDATE upload_queue SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $queue_id);
        $stmt->execute();
        $stmt->close();
    }

    private function failQueue(int $queue_id): void
    {
        if ($this->currentHoldId > 0) {
            $release = MeelCoin::releaseHold($this->conn, $this->currentHoldId, 'queue_failed', 'released');
            if ($release['ok'] && (string)($release['status'] ?? '') === 'captured') {
                // Media sudah sah dan coin memang dipakai. Jangan tandai gagal.
                $this->releaseQueue($queue_id, 'completed');
                $this->currentHoldId = 0;
                return;
            }
            if ($release['ok']) {
                $stmt = $this->conn->prepare(
                    "UPDATE upload_queue SET coin_refunded = 1 WHERE id = ? AND hold_id = ?"
                );
                $stmt->bind_param("ii", $queue_id, $this->currentHoldId);
                $stmt->execute();
                $stmt->close();
            }
            $this->currentHoldId = 0;
        }
        $this->releaseQueue($queue_id, 'failed');
    }

    private function touchAccounting(string $stage = ''): void
    {
        if ($stage !== '' && $this->currentAttemptId > 0) {
            UploadAttempt::setStage($this->conn, $this->currentAttemptId, $stage);
        }
        if ($this->currentHoldId > 0) {
            MeelCoin::touchHold($this->conn, $this->currentHoldId, $this->currentAttemptId > 0 ? $this->currentAttemptId : null);
        }
    }
    private function ensureDownloadProxy(): string
    {
        if ($this->proxyArgs !== '') {
            return $this->proxyArgs;
        }
        $this->validatingProxy = new ValidatingProxy();
        $this->proxyArgs = '--proxy ' . escapeshellarg($this->validatingProxy->url()) . ' ';
        return $this->proxyArgs;
    }
    private function fetchMetadata(string $url, string $extraArgs = ''): ?array
    {
        try {
            (new SsrfGuard())->validate($url);
        } catch (\RuntimeException $e) {
            throw new DownloadException($e->getMessage(), $url, 'validation');
        }
        $proxyArgs = str_contains($extraArgs, '--proxy') ? '' : $this->ensureDownloadProxy();
        $cmd = $this->base_cmd . $proxyArgs . $extraArgs . "--skip-download --print-json " . escapeshellarg($url) . " 2>&1";
        exec($cmd, $output_array, $return_var);
        $output = implode("\n", $output_array);
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start !== false && $end !== false) {
            $json_string = substr($output, $start, ($end - $start) + 1);
            $data = json_decode($json_string, true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($data)) {
                return $data;
            }
        }
        $detail = trim($output);
        $detail = preg_replace('/\s+/', ' ', $detail);
        $detail = mb_substr($detail, 0, 500);
        throw new ProcessException(
            "yt-dlp gagal mengambil metadata (exit $return_var): " . ($detail ?: '(no output)'),
            $cmd,
            $return_var,
            $output
        );
    }
    private function resolveVideoFormat(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtu.be') !== false) {
            return "bestvideo[height<=1080][vcodec^=avc1]+bestaudio[ext=m4a]/best[height<=1080][vcodec^=avc1]";
        }
        if (strpos($host, 'nicovideo.jp') !== false || strpos($host, 'nico.ms') !== false) {
            return "bestvideo[height<=1080]+bestaudio/best";
        }
        if (strpos($host, 'tiktok.com') !== false) {
            return "bestvideo+bestaudio/best";
        }
        return "bestvideo[height<=1080]+bestaudio/best";
    }
    private function assertWithinDownloadLimits(array $meta, string $url): void
    {
        $maxSeconds = defined('MEEL_MAX_DOWNLOAD_SECONDS') ? (int) MEEL_MAX_DOWNLOAD_SECONDS : 7200;
        $maxBytes = defined('MEEL_MAX_DOWNLOAD_BYTES') ? (int) MEEL_MAX_DOWNLOAD_BYTES : (2 * 1024 * 1024 * 1024);

        $duration = (int) ($meta['duration'] ?? 0);
        if ($duration > $maxSeconds) {
            throw new DownloadException(
                sprintf('Durasi %d detik melebihi batas %d detik.', $duration, $maxSeconds),
                $url,
                'limits'
            );
        }

        $filesize = (int) ($meta['filesize'] ?? ($meta['filesize_approx'] ?? 0));
        if ($filesize > $maxBytes) {
            throw new DownloadException(
                sprintf('Ukuran %s melebihi batas %s.', self::formatBytes($filesize), self::formatBytes($maxBytes)),
                $url,
                'limits'
            );
        }
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return round($value, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }

    public function processDownload(string $url, string $type): string
    {
        $url = trim($url);
        $this->assertDownloadRequest($url, $type);
        $this->currentHoldId = 0;
        $this->currentAttemptId = 0;

        [$dl_url, $dl_extra] = $this->prepareDownloadTarget($url);
        $this->assertDownloadDiskSpace();

        $queue_id = $this->lockQueue($url, $type);

        try {
            $meta = $this->fetchAndValidateMetadata($dl_url, $dl_extra, $url);
        } catch (Throwable $e) {
            $this->failQueue($queue_id);
            throw $e;
        }

        try {
            $track = $this->resolveTrackMetadata($meta);
            $ctx = $this->buildDownloadContext($type, $track, $url, $dl_url, $dl_extra);

            $this->touchAccounting('downloading');
            $this->emit('download_start', ['url' => $url]);
            $outcome = $this->runDownloadProcess($ctx, $queue_id);

            if ($outcome['status'] === 'spawn_failed') {
                $this->failQueue($queue_id);
                $this->emit('error', ['message' => 'Gagal menjalankan yt-dlp. Cek permission atau install yt-dlp.']);
                return "";
            }

            if ($outcome['status'] !== 'ok') {
                $this->failQueue($queue_id);
                file_put_contents('/tmp/ytdlp_error.log', $outcome['error_log']);
                $this->emit('error', ['message' => $outcome['message']]);
                return $outcome['disconnected'] ? "DISCONNECTED" : "";
            }

            if ($type === 'music') {
                $result = $this->finalizeMusic(
                    $ctx['temp_id'],
                    $track['title'],
                    $track['artist'],
                    $track['album'],
                    $track['duration'],
                    $track['description']
                );
                $finalized_ok = str_starts_with($result, 'ENCODE_MUSIC:');
                // Untuk musik, jangan tandai queue completed di sini -- EncodeService
                // yang akan menandai completed/failed setelah encode selesai.
                if (!$finalized_ok) {
                    $this->failQueue($queue_id);
                }
            } else {
                $result = $this->finalizeVideo(
                    $ctx['basename'],
                    $ctx['basename'] . ".webp",
                    $track['title'],
                    $track['duration'],
                    $track['description'],
                    $queue_id
                );
                $finalized_ok = str_starts_with($result, 'DONE:');
                if ($finalized_ok) {
                    $this->releaseQueue($queue_id, 'completed');
                } else {
                    $this->failQueue($queue_id);
                }
            }
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->failQueue($queue_id);
            } catch (\Throwable $ignore) {
            }
            throw $e;
        }
    }

    private function assertDownloadRequest(string $url, string $type): void
    {
        if (!in_array($type, ['video', 'music'], true)) {
            throw new DownloadException("Tipe media tidak valid.", $url, 'validation');
        }
        if (strlen($url) > 500) {
            throw new DownloadException("URL terlalu panjang.", $url, 'validation');
        }
    }

    private function prepareDownloadTarget(string $url): array
    {
        try {
            $ssrf = new SsrfGuard();
            $ssrf->validate($url);
            [$dl_url, $dl_extra] = $ssrf->pinHttpUrl($url);
        } catch (\RuntimeException $e) {
            throw new DownloadException("URL tidak diizinkan: " . $e->getMessage(), $url, 'validation');
        }

        try {
            $dl_extra = $this->ensureDownloadProxy() . $dl_extra;
        } catch (\RuntimeException $e) {
            throw new DownloadException(
                "Layanan keamanan download tidak tersedia. Coba lagi nanti.",
                $url,
                'proxy'
            );
        }

        return [$dl_url, $dl_extra];
    }

    private function assertDownloadDiskSpace(): void
    {
        require_disk_space(512 * 1024 * 1024, $this->getShmTempPath(), 'RAM disk staging');
        $hdd_path = defined('MEEL_HDD_BASE') ? MEEL_HDD_BASE : dirname(__DIR__, 2);
        require_disk_space(2 * 1024 * 1024 * 1024, $hdd_path, 'HDD storage');
    }

    private function fetchAndValidateMetadata(string $dl_url, string $dl_extra, string $url): array
    {
        $meta = $this->fetchMetadata($dl_url, $dl_extra);
        if (!$meta) {
            throw new DownloadException("Gagal ambil metadata dari yt-dlp.", $url, 'metadata');
        }
        $this->assertWithinDownloadLimits($meta, $url);

        return $meta;
    }

    private function resolveTrackMetadata(array $meta): array
    {
        $title_candidates = array_values(array_filter(
            [$meta['title'] ?? '', $meta['fulltitle'] ?? '', $meta['alt_title'] ?? '', $meta['track'] ?? ''],
            fn($t) => $t !== '' && mb_substr(trim($t), -3) !== '...'
        ));
        usort($title_candidates, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return [
            'title' => $title_candidates[0] ?? $meta['title'] ?? "Upload_" . time(),
            'artist' => $meta['artist'] ?? ($meta['uploader'] ?? 'Unknown Artist'),
            'album' => $meta['album'] ?? 'Single',
            'duration' => (int)($meta['duration'] ?? 0),
            'description' => !empty($meta['description']) ? $meta['description'] : 'Upload by MEeL Engine',
        ];
    }

    private function buildDownloadContext(string $type, array $track, string $url, string $dl_url, string $dl_extra): array
    {
        $clean = getRomajiName($track['title']);

        $ctx = [
            'type' => $type,
            'shm_temp' => null,
            'temp_id' => null,
            'staging_dir' => null,
            'basename' => null,
            'cmd' => '',
        ];

        if ($type === 'music') {
            $ctx['shm_temp'] = $this->getShmTempPath();
            $ctx['temp_id'] = "raw_" . time() . "_" . substr(md5(uniqid('', true)), 0, 4);
            $temp_path = $ctx['shm_temp'] . "/" . $ctx['temp_id'] . ".%(ext)s";
            $ctx['cmd'] = $this->base_cmd . $dl_extra
                . "-f bestaudio -o " . escapeshellarg($temp_path)
                . " --write-thumbnail --embed-thumbnail"
                . " --newline " . escapeshellarg($dl_url) . " 2>&1";

            return $ctx;
        }

        $ctx['staging_dir'] = $this->getShmTempPath() . '/';
        $ctx['basename'] = $clean;
        if (file_exists($ctx['staging_dir'] . $ctx['basename'] . ".mp4")) {
            $ctx['basename'] .= "-" . substr(md5(uniqid('', true)), -4);
        }
        $output_tpl = $ctx['staging_dir'] . $ctx['basename'] . ".%(ext)s";
        $format = $this->resolveVideoFormat($url);
        $ctx['cmd'] = $this->base_cmd . $dl_extra
            . "-f " . escapeshellarg($format)
            . " --merge-output-format mp4 -o " . escapeshellarg($output_tpl)
            . " --write-thumbnail --newline "
            . escapeshellarg($dl_url) . " 2>&1";

        return $ctx;
    }

    private function runDownloadProcess(array $ctx, int $queue_id): array
    {
        $error_log = "";
        $start = time();
        putenv('PATH=/usr/local/bin:/usr/bin:/bin');
        putenv('LC_ALL=en_US.UTF-8');
        $full_cmd = "exec setsid timeout " . self::DOWNLOAD_TIMEOUT
            . " sh -c " . escapeshellarg($ctx['cmd']);
        $dl_desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $dl_pipes = [];
        $dl_proc = proc_open($full_cmd, $dl_desc, $dl_pipes, null, null);
        if (!is_resource($dl_proc)) {
            return ['status' => 'spawn_failed', 'error_log' => '', 'message' => '', 'disconnected' => false];
        }

        fclose($dl_pipes[0]);
        fclose($dl_pipes[2]);
        $dl_status = proc_get_status($dl_proc);
        $dl_pgid = (int)($dl_status['pid'] ?? 0);
        $dl_label = ($ctx['type'] === 'music') ? ($ctx['temp_id'] ?? 'music') : ($ctx['basename'] ?? 'video');
        $this->trackChildProcess($dl_pgid, true, 'yt-dlp download (' . $dl_label . ')');
        $this->writePidFile('download', $queue_id, $dl_pgid);

        $frag_retry_abort = false;
        $php_timeout_abort = false;
        $client_disconnected = false;
        $frag_total = 0;
        $lastTouch = 0;
        $dl_out = $dl_pipes[1];

        while (!feof($dl_out)) {
            if (time() - $start > self::DOWNLOAD_TIMEOUT) {
                $error_log .= "\n[ERROR] Timeout exceeded";
                $php_timeout_abort = true;
                break;
            }
            if (time() - $lastTouch >= 30) {
                $lastTouch = time();
                $this->touchAccounting();
            }
            $line = fgets($dl_out);
            if ($line === false) {
                break;
            }
            if (strlen($error_log) < 65536) {
                $error_log .= $line;
            }
            if (preg_match('/Retrying\s+fragment[s]?\b/i', $line)) {
                $frag_total++;
                if ($frag_total >= self::FRAGMENT_RETRY_LIMIT) {
                    $error_log .= "\n[ERROR] Fragment retry timeout (yt-dlp retrying repeatedly)";
                    $frag_retry_abort = true;
                    break;
                }
            }
            if (!$this->isClientConnected()) {
                $client_disconnected = true;
                $error_log .= "\n[ERROR] Client disconnected";
                error_log("[MEeL] DownloadService: client disconnected; download dibatalkan (queue_id=$queue_id)");
                break;
            }
            $this->reportDownloadProgress($line);
        }

        if ($frag_retry_abort || $php_timeout_abort || $client_disconnected) {
            $this->terminateChildProcess(
                $dl_pgid,
                'yt-dlp ' . ($frag_retry_abort ? 'fragment retry abort' : ($php_timeout_abort ? 'PHP timeout abort' : 'client disconnected')),
                true
            );
            $this->cleanStagedArtifacts($ctx);
        }
        fclose($dl_out);
        proc_close($dl_proc);
        $this->untrackChildProcess($dl_pgid);
        $this->removePidFile('download', $queue_id);

        if ($this->stagedArtifactsExist($ctx)) {
            return ['status' => 'ok', 'error_log' => $error_log, 'message' => '', 'disconnected' => $client_disconnected];
        }

        return [
            'status' => 'download_failed',
            'error_log' => $error_log,
            'message' => $this->describeDownloadFailure($error_log, $frag_retry_abort),
            'disconnected' => $client_disconnected,
        ];
    }

    private function reportDownloadProgress(string $line): void
    {
        if (preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%\s+of\s+([\d.]+\s*\S+)\s+at\s+([\d.]+\s*\S+\/s)(?:\s+ETA\s+([\d:]+))?(?:\s+\(frag\s+(\d+)\/(\d+)\))?/', $line, $m)) {
            $this->emit('download_progress', [
                'pct' => (int) $m[1],
                'eta' => isset($m[4]) ? 'ETA ' . $m[4] : '',
                'speed' => $m[3] ?? '',
                'size' => $m[2] ?? '',
                'frag' => (isset($m[5], $m[6]) && $m[6]) ? $m[5] . ' / ' . $m[6] : '',
            ]);
            return;
        }

        if (preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%/', $line, $m)) {
            $this->emit('download_progress', ['pct' => (int) $m[1]]);
        }
    }

    private function cleanStagedArtifacts(array $ctx): void
    {
        if ($ctx['type'] === 'music' && !empty($ctx['temp_id'])) {
            foreach (glob($ctx['shm_temp'] . "/" . $ctx['temp_id'] . ".*") ?: [] as $f) {
                $this->removeFile($f);
            }
            return;
        }

        if ($ctx['type'] === 'video' && !empty($ctx['basename'])) {
            foreach (glob($ctx['staging_dir'] . $ctx['basename'] . ".*") ?: [] as $f) {
                $this->removeFile($f);
            }
        }
    }

    private function stagedArtifactsExist(array $ctx): bool
    {
        if ($ctx['type'] === 'music') {
            return !empty(glob($ctx['shm_temp'] . "/" . $ctx['temp_id'] . ".*"));
        }

        $expected = $ctx['staging_dir'] . $ctx['basename'] . ".mp4";
        return file_exists($expected) && filesize($expected) > 0;
    }

    private function describeDownloadFailure(string $error_log, bool $frag_retry_abort): string
    {
        if ($frag_retry_abort) {
            return "Timeout: yt-dlp gagal mengunduh fragment berulang kali (retry 1/10, 2/10, dst). "
                . "Proses dihentikan otomatis. Coba lagi nanti atau gunakan URL lain.";
        }

        $lines = array_filter(explode("\n", $error_log), fn($l) => $l !== '');
        $lines = array_slice($lines, -10);
        $detail = trim(implode("\n", $lines));

        return $detail !== ''
            ? "Download gagal:\n" . $detail
            : 'Download gagal tanpa detail error.';
    }
    private function finalizeMusic(
        string $temp_id,
        string $title,
        string $artist,
        string $album,
        int $duration,
        string $description = 'Upload by MEeL Engine'
    ): string {
        $found = glob($this->getShmTempPath() . "/$temp_id.*") ?: [];
        if (class_exists('UploadAttempt') && $this->currentAttemptId > 0 && !empty($found)) {
            UploadAttempt::addCleanupPaths($this->conn, $this->currentAttemptId, $found);
        }
        $this->touchAccounting('music_downloaded');
        if (!$this->isClientConnected()) {
            foreach ($found as $f) {
                $this->removeFile($f);
            }
            return "Koneksi upload terputus; file sementara dibersihkan.";
        }
        $raw_file = "";
        foreach ($found as $f) {
            $ext = pathinfo($f, PATHINFO_EXTENSION);
            if (!in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $raw_file = basename($f);
                break;
            }
        }
        if ($raw_file) {
            $meta_key = pathinfo($raw_file, PATHINFO_FILENAME);
            if (!isset($_SESSION['meel_pending_music']) || !is_array($_SESSION['meel_pending_music'])) {
                $_SESSION['meel_pending_music'] = [];
            }
            foreach ($_SESSION['meel_pending_music'] as $k => $v) {
                if (($v['ts'] ?? 0) < time() - 3600) unset($_SESSION['meel_pending_music'][$k]);
            }
            $_SESSION['meel_pending_music'][$meta_key] = [
                'ts' => time(),
                'title' => $title,
                'artist' => $artist,
                'album' => $album,
                'duration' => $duration,
                'description' => $description,
            ];
            return 'ENCODE_MUSIC:' . $raw_file;
        }
        return "File audio tidak ditemukan setelah download.";
    }

    private function finalizeVideo(
        string $basename,
        string $db_thumb,
        string $title,
        int $duration,
        string $description = 'Upload by MEeL Engine',
        int $queue_id = 0
    ): string {
        $shm_temp = $this->getShmTempPath();
        $staging_mp4 = "$shm_temp/{$basename}.mp4";
        $dl_thumb_src = $this->findDownloadedThumbnail($shm_temp, $basename);

        if (!file_exists($staging_mp4)) {
            $this->emit('error', ['message' => "File MP4 staging tidak ditemukan: $staging_mp4"]);
            return "";
        }
        $this->emit('phase', ['phase' => 'transcode']);

        $work = $this->prepareWorkFolder($basename);
        $folder_name = $work['folder_name'];
        $db_filename = $work['db_filename'];
        $work_folder = $work['work_folder'];
        if (class_exists('UploadAttempt') && $this->currentAttemptId > 0) {
            UploadAttempt::addCleanupPaths($this->conn, $this->currentAttemptId, [$work_folder]);
        }

        $thumb = $this->stageThumbnail($dl_thumb_src, $work_folder, $db_thumb);
        $db_thumb = $thumb['db_thumb'];
        $thumb_generated = $thumb['generated'];
        if ($thumb_generated && class_exists('UploadAttempt') && $this->currentAttemptId > 0) {
            UploadAttempt::addCleanupPaths($this->conn, $this->currentAttemptId, [
                $work_folder . $db_thumb,
                MEEL_HDD_THUMB_DIR . $db_thumb,
            ]);
        }

        $file_dur = $this->probeDuration($staging_mp4);
        $work_m3u8 = $work_folder . $folder_name . ".m3u8";

        $sprite = $this->prepareSpriteFolder();
        $ram_folder = $sprite['folder'];
        $sprite_file = $sprite['sprite'];
        $vtt_file = $sprite['vtt'];
        if (class_exists('UploadAttempt') && $this->currentAttemptId > 0) {
            UploadAttempt::addCleanupPaths($this->conn, $this->currentAttemptId, [$ram_folder]);
        }

        $sprite_data = $this->buildSpriteCommand($staging_mp4, $sprite_file);

        $this->touchAccounting('transcoding');
        $this->emit('phase', ['phase' => 'transcode']);
        $this->emit('sprite_progress', ['pct' => 0, 'label' => 'Memproses HLS & sprite...']);

        $paralel = $this->runParallelFfmpeg(
            $this->buildParallelJobs($folder_name, $queue_id, $work_folder, $staging_mp4, $work_m3u8, $sprite_data),
            max(120, (int)($file_dur * 2)),
            $file_dur
        );

        // Jika timeout atau client disconnect, bersihkan dan gagal.
        if (!empty($paralel['_timed_out']) || !empty($paralel['_client_disconnected'])) {
            $this->removeDir($ram_folder);
            $this->removeDir($work_folder);
            $this->removeFile($staging_mp4);
            $reason = !empty($paralel['_timed_out']) ? 'Transcode timeout' : 'Client disconnected';
            $this->emit('error', ['message' => $reason]);
            return "";
        }

        if (!$paralel['hls']['started']) {
            $this->removeDir($ram_folder);
            $this->removeDir($work_folder);
            $this->emit('error', ['message' => 'Gagal menjalankan ffmpeg untuk transcode HLS. Cek instalasi ffmpeg.']);
            return "";
        }
        if (array_key_exists('sprite', $paralel) && !$paralel['sprite']['started']) {
            error_log("[MEeL] finalizeVideo: gagal jalankan sprite ffmpeg — melanjutkan tanpa sprite");
            $sprite_data = null;
        }

        $hls_exit = $paralel['hls']['exit'] ?? null;
        $sprite_exit = $paralel['sprite']['exit'] ?? null;
        if (!file_exists($work_m3u8) || filesize($work_m3u8) === 0) {
            $this->removeDir($ram_folder);
            $this->removeDir($work_folder);
            $this->removeFile($staging_mp4);
            $this->emit('error', ['message' => 'Transcode HLS gagal. File .m3u8 tidak terbentuk.']);
            return "";
        }
        if ($hls_exit !== null && $hls_exit !== 0) {
            error_log("[MEeL] finalizeVideo: ffmpeg HLS exit=$hls_exit — m3u8 tetap dipakai karena terbentuk dan tidak kosong");
        }
        if ($sprite_data && $sprite_exit !== 0) {
            error_log("[MEeL] finalizeVideo: sprite ffmpeg exit=$sprite_exit — video tetap lanjut tanpa sprite");
        }

        $this->emit('phase', ['phase' => 'sprite']);
        $this->collectSpriteArtifacts($sprite_data, $sprite_file, $vtt_file, $work_folder);

        $this->removeDir($ram_folder);
        $this->emit('sprite_progress', ['pct' => 100, 'label' => 'Sprite & VTT selesai.']);
        $this->removeFile($staging_mp4);

        $hdd_target_folder = MEEL_HDD_VIDEO_DIR . $folder_name . "/";
        if (class_exists('UploadAttempt') && $this->currentAttemptId > 0) {
            UploadAttempt::addCleanupPaths($this->conn, $this->currentAttemptId, [$hdd_target_folder]);
        }
        if (!$this->isClientConnected()) {
            $this->removeDir($ram_folder);
            $this->removeDir($work_folder);
            $this->removeFile($staging_mp4);
            $this->emit('error', ['message' => 'Koneksi upload terputus; proses dibatalkan sebelum commit.']);
            return "";
        }
        if (!$this->persistVideoToHdd(
            $hdd_target_folder,
            $work_folder,
            $db_filename,
            $db_thumb,
            $thumb_generated,
            $title,
            $description,
            $duration
        )) {
            return "";
        }
        $this->emit('done', ['title' => $title, 'url' => 'index.php']);
        return "DONE:" . $title;
    }

    private function findDownloadedThumbnail(string $shm_temp, string $basename): ?string
    {
        foreach (glob("$shm_temp/{$basename}.*") as $f) {
            $ext = pathinfo($f, PATHINFO_EXTENSION);
            if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                return $f;
            }
        }

        return null;
    }

    private function prepareWorkFolder(string $basename): array
    {
        $flock_path = sys_get_temp_dir() . '/meel_transcode_folder.lock';
        $lock_fp = fopen($flock_path, 'c');
        $locked = $lock_fp !== false && flock($lock_fp, LOCK_EX);
        $folder_name = meel_allocate_unique_dir(MEEL_HDD_VIDEO_DIR, $basename);
        $db_filename = "video/{$folder_name}/{$folder_name}.m3u8";
        $shm_temp = $this->getShmTempPath();
        $work_folder = "$shm_temp/{$folder_name}/";
        if (!is_dir($work_folder)) {
            $this->ensureDir($work_folder);
        }
        if ($locked) {
            flock($lock_fp, LOCK_UN);
            fclose($lock_fp);
        }

        return [
            'folder_name' => $folder_name,
            'db_filename' => $db_filename,
            'work_folder' => $work_folder,
        ];
    }

    private function stageThumbnail(?string $dl_thumb_src, string $work_folder, string $db_thumb): array
    {
        $work_thumb = $work_folder . $db_thumb;
        if ($dl_thumb_src && file_exists($dl_thumb_src)) {
            $thumb_ok = meel_ffmpeg_thumbnail_webp($this->ffmpeg_bin, $dl_thumb_src, $work_thumb, 1280, '', self::ENV_PREFIX, 1);
            if (!$thumb_ok) {
                copy($dl_thumb_src, $work_thumb);
            }
            $this->removeFile($dl_thumb_src);
        }

        $thumb_generated = file_exists($work_thumb) && filesize($work_thumb) > 0;
        if (!$thumb_generated) {
            $db_thumb = "default_thumb.webp";
        }

        return ['db_thumb' => $db_thumb, 'generated' => $thumb_generated];
    }

    private function prepareSpriteFolder(): array
    {
        $shm_base = (is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir());
        $ram_folder = $shm_base . '/meel_sprite_' . uniqid() . '/';
        if (!is_dir($ram_folder)) {
            $this->ensureDir($ram_folder, 0777);
        }

        return [
            'folder' => $ram_folder,
            'sprite' => $ram_folder . 'thumb_sprite.webp',
            'vtt' => $ram_folder . 'thumbnails.vtt',
        ];
    }

    private function buildParallelJobs(
        string $folder_name,
        int $queue_id,
        string $work_folder,
        string $staging_mp4,
        string $work_m3u8,
        ?array $sprite_data
    ): array {
        $lib_path = '/usr/lib/x86_64-linux-gnu:/usr/local/lib';
        $hls_env = ['LD_LIBRARY_PATH' => $lib_path, 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'en_US.UTF-8'];
        $hls_cmd = [
            $this->ffmpeg_bin,
            '-threads',
            (string)self::FFMPEG_THREADS,
            '-i',
            $staging_mp4,
            '-codec',
            'copy',
            '-start_number',
            '0',
            '-hls_time',
            (string)self::HLS_SEGMENT_DURATION,
            '-hls_list_size',
            '0',
            '-hls_segment_filename',
            $work_folder . $folder_name . '_%03d.ts',
            '-f',
            'hls',
            $work_m3u8,
        ];

        $jobs = [
            'hls' => [
                'cmd' => $hls_cmd,
                'env' => $hls_env,
                'label' => 'ffmpeg HLS (' . $folder_name . ')',
                'task_type' => 'transcode_dl',
                'queue_id' => $queue_id,
                'pid_file' => true,
                'required' => true,
                'progress' => true,
                'max_pct' => 99,
            ],
        ];

        if ($sprite_data) {
            $jobs['sprite'] = [
                'cmd' => $sprite_data['cmd'],
                'env' => $sprite_data['env'],
                'label' => 'ffmpeg sprite (' . $folder_name . ')',
                'task_type' => '',
                'queue_id' => 0,
                'pid_file' => false,
                'required' => false,
                'progress' => false,
                'max_pct' => 99,
            ];
        }

        return $jobs;
    }

    private function collectSpriteArtifacts(?array $sprite_data, string $sprite_file, string $vtt_file, string $work_folder): void
    {
        if ($sprite_data && file_exists($sprite_file) && filesize($sprite_file) > 0) {
            $this->generateVTT(
                $vtt_file,
                $sprite_data['total_frames'],
                $sprite_data['interval'],
                $sprite_data['duration'],
                $sprite_data['w'],
                $sprite_data['cols']
            );
        } else {
            error_log("[MEeL] WARN: sprite webp tidak terbentuk — skip VTT generation");
        }

        if (file_exists($sprite_file)) {
            if (!$this->moveFile($sprite_file, $work_folder . 'thumb_sprite.webp')) {
                error_log("[MEeL] WARN: Gagal move thumb_sprite.webp dari RAM ke: $work_folder");
            }
        }
        if (file_exists($vtt_file)) {
            if (!$this->moveFile($vtt_file, $work_folder . 'thumbnails.vtt')) {
                error_log("[MEeL] WARN: Gagal move thumbnails.vtt dari RAM ke: $work_folder");
            }
        }
    }
    /**
     * Jalankan beberapa proses ffmpeg secara paralel, awasi sampai
     * semuanya selesai, lalu rapikan.
     *
     * Tetap metode, bukan kelas baru: semua hook yang dibutuhkan ber-`protected`
     * di `TranscoderBase`, dan daftar anak proses adalah state per-instance
     * yang dipindai `terminateAllProcesses()` saat shutdown. Kelas terpisah
     * tidak bisa menjangkau itu tanpa melebarkannya jadi `public`.
     *
     * @param array<string, array{
     *     cmd: array<int, string>,      // argumen untuk proc_open
     *     env: array<string, string>,   // environment untuk proc_open
     *     label: string,                // untuk log dan terminateAllProcesses()
     *     task_type: string,            // prefix PID file
     *     queue_id: int,                // id antrean untuk PID file
     *     pid_file: bool,               // tulis PID file atau tidak
     *     required: bool,               // gagal start = pekerjaan selesai
     *     progress: bool,               // emit transcode_progress dari stderr
     *     max_pct: int                  // plafon persentase progres
     * }> $jobs
     * @param int $timeoutSeconds batas waktu total semua proses
     * @param int $fileDur durasi media, untuk meng-hitung persen progres
     * @return array<string, array{started: bool, pid: int, exit: int|null}>
     * @see docs/{id,en}/modules.md
     */
    private function runParallelFfmpeg(array $jobs, int $timeoutSeconds, int $fileDur): array
    {
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $state = [];
        $timedOut = false;
        $clientDisconnected = false;

        foreach ($jobs as $key => $job) {
            $pipes = [];
            $proc = proc_open($job['cmd'], $desc, $pipes, null, $job['env']);

            if (!is_resource($proc)) {
                error_log("[MEeL] runParallelFfmpeg: gagal menjalankan {$job['label']}");
                $state[$key] = ['started' => false, 'pid' => 0, 'exit' => null, 'done' => true];
                if ($job['required']) {
                    break;
                }
                continue;
            }

            fclose($pipes[0]);
            fclose($pipes[1]);
            $stream = $pipes[2];
            stream_set_timeout($stream, 5);

            $pid = (int) (proc_get_status($proc)['pid'] ?? 0);
            $this->trackChildProcess($pid, false, $job['label']);
            if ($job['pid_file']) {
                $this->writePidFile($job['task_type'], $job['queue_id'], $pid);
            }

            $state[$key] = [
                'started' => true,
                'proc' => $proc,
                'pipes' => $pipes,
                'stream' => $stream,
                'pid' => $pid,
                'exit' => null,
                'done' => false,
            ];
        }

        $startAll = time();
        $lastTouch = 0;
        while (true) {
            if (time() - $lastTouch >= 30) {
                $lastTouch = time();
                $this->touchAccounting();
                if (PHP_SAPI !== 'cli' && !$this->keepConnectionAlive()) {
                    error_log('[MEeL] runParallelFfmpeg: client disconnected; transcode dibatalkan');
                    $clientDisconnected = true;
                    foreach ($state as $s) {
                        if (!$s['started'] || $s['done']) {
                            continue;
                        }
                        $this->closePipes($s);
                        $this->terminateChildProcess($s['pid'], $s['label'] . ' client disconnected', false);
                    }
                    break;
                }
            }
            $pending = [];
            foreach ($state as $key => $s) {
                if ($s['started'] && !$s['done']) {
                    $pending[$key] = $s;
                }
            }
            if ($pending === []) {
                break;
            }

            if (time() - $startAll > $timeoutSeconds) {
                error_log("[MEeL] runParallelFfmpeg: parallel processing timeout setelah {$timeoutSeconds}s");
                $timedOut = true;
                foreach ($state as $s) {
                    if (!$s['started'] || $s['done']) {
                        continue;
                    }
                    $this->closePipes($s);
                    $this->terminateChildProcess($s['pid'], $s['label'] . ' timeout', false);
                }
                break;
            }

            $streamToKey = [];
            $read = [];
            foreach ($pending as $key => $s) {
                $streamToKey[(int) $s['stream']] = $key;
                $read[] = $s['stream'];
            }
            $write = $except = null;
            $changed = @stream_select($read, $write, $except, 1);

            if ($changed === false || $changed === 0) {
                foreach ($pending as $key => $s) {
                    if (!$this->isProcessAlive($s['pid'])) {
                        $state[$key]['done'] = true;
                    }
                }
                continue;
            }

            foreach ($read as $stream) {
                $key = $streamToKey[(int) $stream] ?? null;
                if ($key === null) {
                    continue;
                }
                $line = fgets($stream);
                if ($line === false) {
                    $state[$key]['done'] = true;
                    continue;
                }
                if ($jobs[$key]['progress'] ?? false) {
                    $this->emitFfmpegProgress($line, $fileDur, $jobs[$key]['max_pct']);
                }
            }
        }

        $result = [];
        foreach ($state as $key => $s) {
            if (!$s['started']) {
                $result[$key] = ['started' => false, 'pid' => 0, 'exit' => null];
                continue;
            }
            $this->closePipes($s);
            $result[$key] = [
                'started' => true,
                'pid' => $s['pid'],
                'exit' => proc_close($s['proc']),
            ];
            $this->untrackChildProcess($s['pid']);
            if ($jobs[$key]['pid_file']) {
                $this->removePidFile($jobs[$key]['task_type'], $jobs[$key]['queue_id']);
            }
        }

        foreach ($jobs as $key => $unused) {
            $result[$key] ??= ['started' => false, 'pid' => 0, 'exit' => null];
        }

        $result['_timed_out'] = $timedOut;
        $result['_client_disconnected'] = $clientDisconnected;
        return $result;
    }

    /** Tutup semua pipe proses anak yang masih terbuka. */
    private function closePipes(array $s): void
    {
        foreach ($s['pipes'] as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
    }

    private function emitFfmpegProgress(string $line, int $fileDur, int $maxPct): void
    {
        if ($fileDur <= 0) {
            return;
        }
        if (!preg_match('/time=((\d+):(\d+):(\d+)\.(\d+))/', $line, $m)) {
            return;
        }
        $cur = ($m[2] * 3600) + ($m[3] * 60) + $m[4];
        $pct = min($maxPct, round(($cur / $fileDur) * 100));
        $this->emit('transcode_progress', ['pct' => $pct]);
    }
    /**
     * Pindahkan hasil kerja dari folder kerja ke HDD, lalu catat ke
     * database dalam satu transaksi.
     *
     * Kalau ada apa pun yang gagal di tengah, sistem tidak boleh tertinggal
     * setengah jadi -- file sudah pindah tapi baris database belum ada, atau
     * sebaliknya. Karena itu `moveFile()` melempar, bukan mengembalikan false,
     * dan `catch` melakukan rollback database DITAMBAH pembatalan efek
     * filesystem.
     *
     * Urutan pembatalannya penting dan tidak boleh dibalik:
     *   1. rollback transaksi (baris database tidak jadi ada)
     *   2. `rollbackFinalizeVideo()` (hapus folder target + thumbnail)
     *   3. hapus folder kerja
     *
     * @return bool true bila sudah commit, false bila sudah di-rollback
     * @see docs/{id,en}/modules.md
     */
    private function persistVideoToHdd(
        string $hdd_target_folder,
        string $work_folder,
        string $db_filename,
        string $db_thumb,
        bool $thumb_generated,
        string $title,
        string $description,
        int $duration
    ): bool {
        $this->touchAccounting('persisting');
        if (!$this->isClientConnected()) {
            $this->emit('error', ['message' => 'Koneksi upload terputus sebelum commit.']);
            return false;
        }
        $this->conn->begin_transaction();
        try {
            if (!is_dir($hdd_target_folder)) {
                $this->ensureDir($hdd_target_folder);
            }
            if (!is_dir(MEEL_HDD_THUMB_DIR)) {
                $this->ensureDir(MEEL_HDD_THUMB_DIR);
            }
            foreach (glob($work_folder . "*") as $work_file) {
                $filename = basename($work_file);
                if ($thumb_generated && $filename === $db_thumb) {
                    $dest = MEEL_HDD_THUMB_DIR . $filename;
                } else {
                    $dest = $hdd_target_folder . $filename;
                }
                if (!$this->moveFile($work_file, $dest)) {
                    throw new \RuntimeException(
                        "Gagal memindahkan file ke storage: {$work_file} → {$dest}"
                    );
                }
            }
            $this->removeDir($work_folder);
            
            // Verifikasi storage masih ter-mount setelah pindah file
            if (!meel_storage_ready()) {
                throw new \RuntimeException('Storage tidak siap (HDD terlepas?) setelah memindahkan file.');
            }

            $hdd_m3u8_full = MEEL_HDD_VIDEO_UPLOAD . $db_filename;
            $hdd_thumb_full = MEEL_HDD_THUMB_DIR . $db_thumb;
            if (!file_exists($hdd_m3u8_full) || filesize($hdd_m3u8_full) === 0) {
                throw new \RuntimeException("File M3U8 tidak ditemukan di HDD setelah dipindahkan: $hdd_m3u8_full");
            }
            $segments = glob($hdd_target_folder . basename($db_filename, '.m3u8') . '_*.ts') ?: [];
            if (empty($segments)) {
                throw new \RuntimeException("Segmen .ts tidak ditemukan di HDD setelah dipindahkan.");
            }
            foreach ($segments as $segment) {
                if (!is_file($segment) || filesize($segment) < 1) {
                    throw new \RuntimeException("Segmen .ts rusak/kosong: " . basename($segment));
                }
            }
            if ($thumb_generated && (!file_exists($hdd_thumb_full) || filesize($hdd_thumb_full) === 0)) {
                throw new \RuntimeException("Thumbnail tidak ditemukan di HDD setelah dipindahkan: $hdd_thumb_full");
            }
            $metadata = generate_search_metadata($title);
            $views = 0;
            $stmt = $this->conn->prepare(
                "INSERT INTO video (title, description, filename, thumbnail, duration, views, user_id, search_metadata, upload_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            if (!$stmt) {
                throw new \RuntimeException("Database prepare error: " . $this->conn->error);
            }
            $stmt->bind_param("ssssiiss", $title, $description, $db_filename, $db_thumb, $duration, $views, $this->user_id, $metadata);
            if (!$stmt->execute()) {
                throw new \RuntimeException("Database insert error: " . $stmt->error);
            }
            $videoId = (int)$this->conn->insert_id;
            $stmt->close();

            if ($this->currentHoldId > 0) {
                $capture = MeelCoin::captureHoldInTransaction(
                    $this->conn,
                    $this->currentHoldId,
                    $this->user_id,
                    'upload_advanced',
                    $this->currentAttemptId > 0 ? $this->currentAttemptId : null,
                    'video',
                    $videoId
                );
                if (!$capture['ok']) {
                    throw new \RuntimeException($capture['error']);
                }
            }

            $this->conn->commit();
            if (function_exists('meel_invalidate_media_stats_cache')) {
                meel_invalidate_media_stats_cache('video');
            }
            return true;
        } catch (\Throwable $e) {
            $this->conn->rollback();
            $this->rollbackFinalizeVideo($hdd_target_folder, $db_thumb, $thumb_generated);
            $this->removeDir($work_folder);
            error_log("[MEeL] finalizeVideo GAGAL: " . $e->getMessage());
            $this->emit('error', ['message' => $e->getMessage()]);
            return false;
        }
    }
    private function rollbackFinalizeVideo(
        string $hdd_target_folder,
        string $db_thumb,
        bool $thumb_generated
    ): void {
        if (is_dir($hdd_target_folder)) {
            $this->removeDir($hdd_target_folder);
        }
        if ($thumb_generated && $db_thumb !== 'default_thumb.webp') {
            $this->removeFile(MEEL_HDD_THUMB_DIR . $db_thumb);
        }
    }
}

/* reference build: MEeL-C2H5NO2 [ffd332a] */