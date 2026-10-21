<?php
require_once __DIR__ . '/../core/TranscoderBase.php';

class TranscodeService extends TranscoderBase
{
    private const SHM_MIN_BYTES = 256 * 1024 * 1024;
    private const STALE_OUTPUT_SECONDS = 7200;
    private const STALE_QUEUE_MINUTES = 15;
    private const SOURCE_SIZE_LIMIT = 200 * 1024 * 1024;
    private const SOURCE_DURATION_LIMIT = 600;
    private const CACHE_MIN_BYTES = 10240;
    private const CACHE_UNPROBED_BYTES = 50000;
    private const MARKER_STALE_SECONDS = 600;

    public function transcodeVideo(int $video_id, string $format = 'mp3'): array
    {
        $output_dir = $this->getShmTranscodePath() . '/';
        if (($err = $this->prepareOutputDirectory($output_dir)) !== null) {
            return $err;
        }
        $this->purgeStaleArtifacts($output_dir);

        $source = $this->loadTranscodeSource($video_id);
        if (isset($source['error'])) {
            return $source['error'];
        }

        if (($err = $this->enforceSourceQuota($source['duration'], $source['total_bytes'])) !== null) {
            return $err;
        }

        $output_filename = $this->sanitizeFilename($source['video']['title']) . '.' . $format;
        $output_path = $output_dir . $output_filename;
        $marker_file = $output_path . '.processing';
        $queue_id = 0;

        $slot = $this->claimTranscodeSlot(
            $output_filename,
            $output_path,
            $marker_file,
            $source['duration'],
            $source['video'],
            $queue_id
        );
        if ($slot !== null) {
            return $slot;
        }

        $concat_list_path = $this->writeConcatList($source['ts_files'], $output_dir, $video_id);
        $audio = $this->resolveAudioFormat($format, $source['hls_base'], $source['video']);
        $tc_cmd = $this->buildFfmpegArgv($audio, $concat_list_path, $output_path, $format);
        $tc_env = $this->transcodeEnv();

        $exit = $this->runFfmpeg($tc_cmd, $tc_env, $source['duration'], $format, $output_filename, $queue_id);
        if ($exit === null) {
            $this->removeFile($concat_list_path);
            return $this->failJob(
                $queue_id,
                'FFmpeg gagal dijalankan.',
                ['status' => 'error', 'msg' => 'FFmpeg gagal dijalankan.'],
                [$concat_list_path, $marker_file]
            );
        }
        if ($exit !== 0) {
            $this->removeFile($concat_list_path);
            return $this->failJob(
                $queue_id,
                'FFmpeg gagal memproses audio (exit code: ' . $exit . ').',
                ['status' => 'error', 'msg' => 'FFmpeg gagal memproses audio (exit code: ' . $exit . ').'],
                [$output_path, $concat_list_path, $marker_file]
            );
        }

        $this->removeFile($concat_list_path);
        $this->removeFile($marker_file);

        if (!file_exists($output_path) || filesize($output_path) === 0) {
            $this->setQueueStatus($queue_id, 'failed');
            $this->emit('error', ['message' => 'FFmpeg gagal menghasilkan file output.']);
            return ['status' => 'error', 'msg' => 'FFmpeg gagal menghasilkan file.'];
        }

        $this->setQueueStatus($queue_id, 'completed');
        return $this->completeTranscode($output_filename, $source['video']);
    }

    private function prepareOutputDirectory(string $output_dir): ?array
    {
        if (!is_dir($output_dir)) {
            $this->ensureDir($output_dir);
        }

        $shm_free = disk_free_space($output_dir);
        if ($shm_free !== false && $shm_free < self::SHM_MIN_BYTES) {
            return [
                'status' => 'error',
                'msg' => 'RAM disk tidak mencukupi untuk transcode. Hanya tersedia '
                    . sprintf('%.1f', $shm_free / (1024 ** 3)) . ' GB.',
            ];
        }

        return null;
    }

    private function purgeStaleArtifacts(string $output_dir): void
    {
        foreach (glob($output_dir . "*") as $file) {
            if (is_file($file) && time() - filemtime($file) >= self::STALE_OUTPUT_SECONDS) {
                $this->removeFile($file);
            }
        }

        $stmt_clean = $this->conn->prepare(
            'DELETE FROM transcode_queue WHERE created_at < NOW() - INTERVAL '
            . self::STALE_QUEUE_MINUTES . ' MINUTE'
        );
        $stmt_clean->execute();
        $stmt_clean->close();
    }

    private function loadTranscodeSource(int $video_id): array
    {
        $stmt = $this->conn->prepare(
            "SELECT v.title, v.filename, v.thumbnail, v.description, v.upload_date, u.username
             FROM video v
             LEFT JOIN users u ON v.user_id = u.id
             WHERE v.id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $video_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        if (!$res || $res->num_rows === 0) {
            return ['error' => ['status' => 'error', 'msg' => 'ID Video tidak ditemukan!']];
        }

        $v_data = $res->fetch_assoc();
        $hls_base = MEEL_HDD_VIDEO_UPLOAD;
        $m3u8_path = $hls_base . $v_data['filename'];

        if (!file_exists($m3u8_path)) {
            return ['error' => ['status' => 'error', 'msg' => "File HLS tidak ditemukan di: $m3u8_path"]];
        }

        $ts_files = glob(dirname($m3u8_path) . "/" . "*.ts");
        if (empty($ts_files)) {
            return ['error' => ['status' => 'error', 'msg' => 'File segmen HLS (.ts) tidak ditemukan!']];
        }
        natsort($ts_files);
        $ts_files = array_values($ts_files);

        return [
            'video' => $v_data,
            'hls_base' => $hls_base,
            'm3u8_path' => $m3u8_path,
            'ts_files' => $ts_files,
            'duration' => $this->probeDuration($m3u8_path),
            'total_bytes' => array_sum(array_map('filesize', $ts_files)),
        ];
    }

    private function enforceSourceQuota(float $file_dur, int $total_size): ?array
    {
        if ($this->user_role === 'admin') {
            return null;
        }

        $reasons = [];
        if ($total_size > self::SOURCE_SIZE_LIMIT) {
            $reasons[] = 'ukuran ' . round($total_size / (1024 * 1024), 1) . 'MB (maks 200MB)';
        }
        if ($file_dur > self::SOURCE_DURATION_LIMIT) {
            $reasons[] = 'durasi ' . round($file_dur / 60, 1) . ' menit (maks 10 menit)';
        }

        if ($reasons === []) {
            return null;
        }

        return [
            'status' => 'error',
            'msg' => 'File tidak memenuhi syarat: ' . implode(' dan ', $reasons) . '.',
        ];
    }

    private function isCacheUsable(string $output_path, float $file_dur): bool
    {
        $cache_size = (int) filesize($output_path);
        if ($cache_size <= self::CACHE_MIN_BYTES) {
            return false;
        }

        $cache_dur = $this->probeDuration($output_path);
        if ($cache_dur > 0 && $file_dur > 0) {
            return $cache_dur >= $file_dur * 0.5;
        }

        return $cache_dur <= 0 && $cache_size > self::CACHE_UNPROBED_BYTES;
    }

    private function claimTranscodeSlot(
        string $output_filename,
        string $output_path,
        string $marker_file,
        float $file_dur,
        array $video,
        int &$queue_id
    ): ?array {
        $mtx_fp = fopen(sys_get_temp_dir() . '/meel_transcode_marker.lock', 'c');
        $mtx_locked = $mtx_fp !== false && flock($mtx_fp, LOCK_EX);

        try {
            if (file_exists($output_path)) {
                if ($this->isCacheUsable($output_path, $file_dur)) {
                    $this->emit('transcode_start');
                    return $this->completeTranscode($output_filename, $video);
                }
                $reason = filesize($output_path) <= self::CACHE_MIN_BYTES
                    ? 'too small (' . filesize($output_path) . ' bytes)'
                    : 'duration mismatch';
                error_log("[MEeL] transcodeVideo: hapus cache invalid ({$output_path}, {$reason})");
                $this->removeFile($output_path);
            }

            if (file_exists($marker_file) && time() - filemtime($marker_file) < self::MARKER_STALE_SECONDS) {
                return ['status' => 'error', 'msg' => 'Output sedang diproses oleh antrean lain. Tunggu beberapa saat.'];
            }

            if (!touch($marker_file)) {
                error_log("[MEeL] Gagal membuat marker file: {$marker_file}");
            }
        } finally {
            if ($mtx_locked) {
                flock($mtx_fp, LOCK_UN);
                fclose($mtx_fp);
            }
        }

        require_once __DIR__ . '/../core/System.php';
        $sys = new System($this->conn);
        if (!$sys->claimQueueSlot('transcode_queue', $this->user_id)) {
            $this->removeFile($marker_file);
            return ['status' => 'error', 'msg' => 'Silahkan Menunggu. Server sedang sibuk memproses antrean lain.'];
        }
        $queue_id = (int) $this->conn->insert_id;

        return null;
    }

    private function writeConcatList(array $ts_files, string $output_dir, int $video_id): string
    {
        $concat_list_path = $output_dir . "concat_{$video_id}_" . time() . ".txt";

        $lines = '';
        foreach ($ts_files as $ts) {
            $lines .= "file '" . str_replace("'", "'\\''", $ts) . "'\n";
        }
        file_put_contents($concat_list_path, $lines);

        return $concat_list_path;
    }

    private function resolveAudioFormat(string $format, string $hls_base, array $video): array
    {
        $use_thumb = true;
        switch ($format) {
            case 'ogg':
                $codec = "libopus";
                $bitrate = "-b:a 128k -vbr on";
                $use_thumb = false;
                break;
            case 'm4a':
                $codec = "copy";
                $bitrate = "";
                break;
            default:
                $codec = "libmp3lame";
                $bitrate = "-q:a 2";
                break;
        }

        $thumb_path = $hls_base . "thumbnail/" . $video['thumbnail'];

        return [
            'codec' => $codec,
            'bitrate' => $bitrate,
            'thumb_path' => $thumb_path,
            'use_thumb' => $use_thumb && file_exists($thumb_path) && !empty($video['thumbnail']),
            'meta' => [
                'title' => $video['title'] ?? 'Untitled',
                'artist' => $video['username'] ?? 'MEeL Transcoder',
                'album' => 'MEeL',
                'date' => !empty($video['upload_date'])
                    ? date('Y-m-d', strtotime($video['upload_date']))
                    : date('Y-m-d'),
                'comment' => !empty($video['description'])
                    ? mb_substr($video['description'], 0, 256)
                    : 'Transcoded by MEeL',
                'album_artist' => 'MEeL',
            ],
        ];
    }

    private function buildFfmpegArgv(array $audio, string $concat_list_path, string $output_path, string $format): array
    {
        $tc_cmd = [
            $this->ffmpeg_bin,
            '-y',
            '-threads',
            (string) self::FFMPEG_THREADS,
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            $concat_list_path,
        ];

        if ($audio['use_thumb']) {
            $tc_cmd[] = '-i';
            $tc_cmd[] = $audio['thumb_path'];
        }

        $tc_cmd[] = '-map';
        $tc_cmd[] = '0:a';

        if ($audio['use_thumb']) {
            $tc_cmd[] = '-map';
            $tc_cmd[] = '1:v';
            $tc_cmd[] = '-c:v';
            $tc_cmd[] = 'mjpeg';
            $tc_cmd[] = '-disposition:v:0';
            $tc_cmd[] = 'attached_pic';
            if ($format === 'mp3') {
                $tc_cmd[] = '-id3v2_version';
                $tc_cmd[] = '3';
            }
        }

        $tc_cmd[] = '-c:a';
        $tc_cmd[] = $audio['codec'];
        foreach (array_filter(explode(' ', $audio['bitrate'])) as $part) {
            $tc_cmd[] = $part;
        }

        foreach ($audio['meta'] as $key => $value) {
            $tc_cmd[] = '-metadata';
            $tc_cmd[] = $key . '=' . $value;
        }

        $tc_cmd[] = $output_path;

        return $tc_cmd;
    }

    private function transcodeEnv(): array
    {
        return [
            'LD_LIBRARY_PATH' => self::FFMPEG_LIB_PATH,
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'LC_ALL' => 'en_US.UTF-8',
        ];
    }

    private function runFfmpeg(
        array $tc_cmd,
        array $tc_env,
        float $file_dur,
        string $format,
        string $output_filename,
        int $queue_id
    ): ?int {
        $this->emit('transcode_start');

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $tc_pipes = [];
        $tc_proc = proc_open($tc_cmd, $desc, $tc_pipes, null, $tc_env);
        if (!is_resource($tc_proc)) {
            error_log("[MEeL] transcodeVideo: proc_open gagal untuk {$output_filename}");
            return null;
        }

        fclose($tc_pipes[0]);
        fclose($tc_pipes[1]);
        $tc_out = $tc_pipes[2];

        $tc_status = proc_get_status($tc_proc);
        $tc_pid = (int) ($tc_status['pid'] ?? 0);
        $this->trackChildProcess($tc_pid, false, 'ffmpeg transcode audio (' . $output_filename . ')');
        $this->writePidFile('transcode', $queue_id, $tc_pid);

        stream_set_timeout($tc_out, 30);
        $stderr = [];
        $started = time();
        $fmt = strtoupper($format);

        while (!feof($tc_out)) {
            if (time() - $started > self::TRANSCODE_AUDIO_TIMEOUT) {
                error_log("[MEeL] transcodeVideo: timeout setelah " . self::TRANSCODE_AUDIO_TIMEOUT . "s");
                $this->terminateChildProcess($tc_pid, 'ffmpeg transcode audio timeout', false);
                break;
            }
            $line = fgets($tc_out);
            if ($line === false) {
                break;
            }
            $stderr[] = $line;
            $this->reportProgress($line, $file_dur, $fmt, $started);
        }

        fclose($tc_pipes[2]);
        $tc_exit = proc_close($tc_proc);
        $this->untrackChildProcess($tc_pid);
        $this->removePidFile('transcode', $queue_id);

        if ($tc_exit !== 0) {
            error_log("[MEeL] transcodeVideo: ffmpeg exit=$tc_exit | $output_filename | stderr: "
                . implode('', array_slice($stderr, -15)));
        }

        return $tc_exit;
    }

    private function reportProgress(string $line, float $file_dur, string $fmt, int $started): void
    {
        if (!preg_match('/time=((\d+):(\d+):(\d+)\.(\d+))/', $line, $m)) {
            return;
        }

        if ($file_dur > 0) {
            $cur = ($m[2] * 3600) + ($m[3] * 60) + $m[4];
            $pct = min(100, round(($cur / $file_dur) * 100));
            $this->emit('transcode_progress', ['pct' => $pct, 'label' => "$pct% — CONVERTING TO $fmt"]);
            return;
        }

        $elapsed = time() - $started;
        $pct = min(95, (int) round(($elapsed / self::TRANSCODE_AUDIO_TIMEOUT) * 100));
        $this->emit('transcode_progress', ['pct' => $pct, 'label' => "$pct% — CONVERTING TO $fmt (estimasi)"]);
    }

    private function setQueueStatus(int $queue_id, string $status): void
    {
        if ($queue_id <= 0) {
            return;
        }

        $stmt = $this->conn->prepare("UPDATE transcode_queue SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $queue_id);
        $stmt->execute();
        $stmt->close();
    }

    private function failJob(int $queue_id, string $emit_message, array $result, array $files): array
    {
        $this->setQueueStatus($queue_id, 'failed');
        $this->emit('error', ['message' => $emit_message]);
        foreach ($files as $file) {
            $this->removeFile($file);
        }

        return $result;
    }

    private function completeTranscode(string $output_filename, array $video): array
    {
        $this->grantTranscodeOwnership($output_filename);
        $download_link = "api/download-transcode?file=" . rawurlencode($output_filename)
            . "&title=" . rawurlencode($video['title']);

        $this->emit('done_transcode', ['title' => $video['title'], 'download_link' => $download_link]);

        return [
            'status' => 'success',
            'download_link' => $download_link,
            'output_filename' => $output_filename,
            'title' => $video['title'],
        ];
    }

    private function grantTranscodeOwnership(string $outputFilename): void
    {
        if ($outputFilename === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!is_array($_SESSION['meel_transcode_files'] ?? null)) {
            $_SESSION['meel_transcode_files'] = [];
        }
        foreach ($_SESSION['meel_transcode_files'] as $f => $ts) {
            if ((int)$ts < time() - 4 * 3600) {
                unset($_SESSION['meel_transcode_files'][$f]);
            }
        }
        $_SESSION['meel_transcode_files'][$outputFilename] = time();
    }

    public static function ownsTranscodeFile(string $outputFilename): bool
    {
        if ($outputFilename === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $files = $_SESSION['meel_transcode_files'] ?? [];
        if (!is_array($files) || !isset($files[$outputFilename])) {
            return false;
        }
        if ((int)$files[$outputFilename] < time() - 4 * 3600) {
            unset($_SESSION['meel_transcode_files'][$outputFilename]);
            return false;
        }
        return true;
    }
}

/* reference build: MEeL-C5H9NO2 [b5be2ff70da5affd] */