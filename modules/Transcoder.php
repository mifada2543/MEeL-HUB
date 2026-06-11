<?php
// File: auth/Transcoder.php
// Optimized for: Intel Core i3-1220P (10 core / 12 thread), Dual-Channel RAM, USB HDD storage
// VA-API: Intel iHD 24.1.0 — H264/HEVC/VP9 encode+decode tersedia, tapi tidak dipakai di HLS
//         karena pipeline ini sudah pakai -codec copy (stream copy, tanpa re-encode)

class Transcoder
{
    private $conn;
    private $user_id;
    private $user_role;
    private $base_path;
    private $cookies_path;
    private $user_agent;
    private $base_cmd;
    private $ffmpeg_bin;
    private $ffprobe_bin;

    // ─── KONSTANTA HARDWARE ───────────────────────────────────────────────────
    private const FFMPEG_THREADS        = 8;

    // Sprite thumbnail: lebar & tinggi tiap tile, jumlah kolom
    private const SPRITE_TILE_W         = 160;
    private const SPRITE_TILE_H         = 90;
    private const SPRITE_COLS           = 5;

    // HLS: durasi tiap segment (detik)
    private const HLS_SEGMENT_DURATION  = 10;

    // Download timeout (detik)
    private const DOWNLOAD_TIMEOUT      = 900;

    // ─── PATH STORAGE ─────────────────────────────────────────────────────────
    private const HDD_BASE      = "/media/muhammaddaffa/MEeL/media/video/upload/";
    private const HDD_VIDEO_DIR = self::HDD_BASE . "video/";
    private const HDD_THUMB_DIR = self::HDD_BASE . "thumbnail/";

    // ─── ENV PREFIX ───────────────────────────────────────────────────────────
    private const ENV_PREFIX = "export LD_LIBRARY_PATH=''; export PATH=/usr/local/bin:/usr/bin:/bin; export LC_ALL=en_US.UTF-8; ";

    public function __construct($db_connection, $session_user_id)
    {
        $this->conn         = $db_connection;
        $this->user_id      = (int)$session_user_id;
        $this->base_path    = "/opt/lampp/htdocs/MEeL";
        $this->cookies_path = $this->base_path . "/cookies.txt";
        $this->user_agent   = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";
        $this->ffmpeg_bin   = $this->resolveBinary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);
        $this->ffprobe_bin  = $this->resolveBinary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);

        // base_cmd untuk yt-dlp — ENV_PREFIX tidak dipakai di sini karena
        // yt-dlp membutuhkan PATH yang luas; ffmpeg sudah di-pass via --ffmpeg-location
        $this->base_cmd = "export PATH=/usr/local/bin:/usr/bin:/bin; export LC_ALL=en_US.UTF-8;"
            . " /usr/local/bin/yt-dlp --js-runtime node --no-warnings --restrict-filenames"
            . " --ffmpeg-location " . escapeshellarg(dirname($this->ffmpeg_bin))
            . " --user-agent "      . escapeshellarg($this->user_agent)
            . " --cookies "         . escapeshellarg($this->cookies_path) . " ";

        $stmt = $this->conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $this->user_role = $stmt->get_result()->fetch_assoc()['role'] ?? 'user';
        $stmt->close();
    }

    public function getUserRole(): string
    {
        return $this->user_role;
    }

    // ─── BINARY RESOLVER ──────────────────────────────────────────────────────

    private function resolveBinary(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (strpos($candidate, '/') !== false) {
                if (is_executable($candidate)) return $candidate;
                continue;
            }
            $resolved = trim((string)shell_exec("command -v " . escapeshellarg($candidate) . " 2>/dev/null"));
            if ($resolved !== '') return $resolved;
        }
        return $candidates[0];
    }

    // ─── QUEUE MANAGEMENT ─────────────────────────────────────────────────────

    /**
     * @deprecated Gunakan System->getActiveQueues() atau System->isServerBusy()
     */
    public function checkServerBusy(): ?array
    {
        $res = $this->conn->query(
            "SELECT q.*, u.username FROM upload_queue q
             JOIN users u ON q.user_id = u.id
             WHERE q.status = 'processing' LIMIT 1"
        );
        return $res ? $res->fetch_assoc() : null;
    }

    private function lockQueue(string $url, string $type): int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO upload_queue (url, media_type, user_id, status) VALUES (?, ?, ?, 'processing')"
        );
        $stmt->bind_param("ssi", $url, $type, $this->user_id);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    private function releaseQueue(int $queue_id, string $status = 'completed'): void
    {
        $allowed = ['completed', 'failed'];
        $status  = in_array($status, $allowed, true) ? $status : 'failed';
        $stmt = $this->conn->prepare("UPDATE upload_queue SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $queue_id);
        $stmt->execute();
        $stmt->close();
    }

    // ─── METADATA ─────────────────────────────────────────────────────────────

    private function fetchMetadata(string $url): ?array
    {
        $cmd    = $this->base_cmd . "--skip-download --print-json " . escapeshellarg($url) . " 2>&1";
        exec($cmd, $output_array, $return_var);
        $output = implode("\n", $output_array);

        $start = strpos($output, '{');
        $end   = strrpos($output, '}');

        if ($start !== false && $end !== false) {
            $json_string = substr($output, $start, ($end - $start) + 1);
            $data        = json_decode($json_string, true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($data)) {
                return $data;
            }
        }

        // DEBUG MODE — sengaja aktif untuk keperluan development / pindah env
        // Untuk menonaktifkan di production: set konstanta DEBUG_METADATA = false
        $this->renderMetadataDebug($url, $cmd, $return_var, $output);
        return null;
    }

    /**
     * Tampilkan informasi debug saat fetchMetadata gagal.
     * Pisahkan dari fetchMetadata agar mudah di-toggle atau di-log ke file.
     */
    private function renderMetadataDebug(string $url, string $cmd, int $return_var, string $output): void
    {
        $check_node  = trim((string)shell_exec("which node 2>/dev/null"));
        $check_ytdlp = trim((string)shell_exec("which yt-dlp 2>/dev/null"));

        echo "<div style='background:#1a1a1a;color:#ff4444;padding:20px;border:2px solid red;font-family:monospace;margin:20px;border-radius:10px;'>";
        echo "<h2 style='color:white;border-bottom:1px solid #333;'>⚠️ DEBUG: GAGAL PARSING METADATA</h2>";
        echo "<strong>URL:</strong> "        . htmlspecialchars($url)    . "<br><br>";
        echo "<strong>Perintah Shell:</strong><br>";
        echo "<code style='background:#000;color:#00ff00;padding:5px;display:block;margin:5px 0;'>" . htmlspecialchars($cmd) . "</code><br>";
        echo "<strong>Return Code:</strong> " . $return_var . " (0 = sukses)<br><br>";
        echo "<strong>Output yt-dlp:</strong>";
        echo "<pre style='background:#000;color:#ccc;padding:15px;overflow:auto;max-height:400px;border:1px solid #444;'>" . htmlspecialchars($output) . "</pre>";
        echo "<strong>Cek Path System:</strong><br>";
        echo "- Node:    " . ($check_node  ?: "<span style='color:yellow;'>Tidak ditemukan</span>") . "<br>";
        echo "- yt-dlp:  " . ($check_ytdlp ?: "<span style='color:yellow;'>Tidak ditemukan</span>") . "<br>";
        echo "- ffmpeg:  " . htmlspecialchars($this->ffmpeg_bin)  . "<br>";
        echo "- ffprobe: " . htmlspecialchars($this->ffprobe_bin) . "<br>";
        echo "</div>";

        die("--- PROSES DIHENTIKAN UNTUK DEBUG ---");
    }

    // ─── FORMAT RESOLVER ──────────────────────────────────────────────────────

    private function resolveVideoFormat(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtu.be') !== false) {
            // YouTube: preferensikan H.264 + AAC/M4A agar bisa stream-copy ke HLS tanpa re-encode
            return "bestvideo[height<=1080][vcodec^=avc1]+bestaudio[ext=m4a]/best[height<=1080][vcodec^=avc1]";
        }
        if (strpos($host, 'nicovideo.jp') !== false || strpos($host, 'nico.ms') !== false) {
            return "bestvideo[height<=1080]+bestaudio/best";
        }
        if (strpos($host, 'tiktok.com') !== false) {
            // [FIX] 'bestvideo1' bukan format string yang valid — diganti ke format standar
            return "bestvideo+bestaudio/best";
        }
        return "bestvideo[height<=1080]+bestaudio/best";
    }

    // ─── OVERLAY SYSTEM ───────────────────────────────────────────────────────

    private function showMEeLOverlay(string $initial_phase = 'download'): void
    {
        while (ob_get_level()) ob_end_clean();

        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');

        $ui_file = $this->base_path . "/partials/ui.php";
        if (file_exists($ui_file)) include $ui_file;

        // Padding agar browser langsung flush dan render
        echo str_repeat(' ', 65536);
        echo "<script>meelPhase('" . htmlspecialchars($initial_phase) . "');</script>";
        flush();
    }

    // =========================================================
    // BAGIAN 1: DOWNLOAD & FINALISASI (processDownload)
    // =========================================================

    public function processDownload(string $url, string $type): string
    {
        // Validasi input
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
            $this->jsError("URL tidak valid atau protokol tidak didukung.");
            return "";
        }
        if (!in_array($type, ['video', 'music'], true)) {
            $this->jsError("Tipe media tidak valid.");
            return "";
        }
        if (strlen($url) > 500) {
            $this->jsError("URL terlalu panjang.");
            return "";
        }

        $queue_id = $this->lockQueue($url, $type);

        $meta = $this->fetchMetadata($url);
        if (!$meta) {
            $this->releaseQueue($queue_id, 'failed');
            $this->jsError("Gagal ambil metadata.");
            return "";
        }

        $title       = $meta['title']                                     ?? "Upload_" . time();
        $artist      = $meta['artist']      ?? ($meta['uploader']         ?? 'Unknown Artist');
        $album       = $meta['album']                                     ?? 'Single';
        $duration    = (int)($meta['duration']                            ?? 0);
        $clean       = getRomajiName($title);
        $description = !empty($meta['description']) ? $meta['description'] : 'Upload by MEeL Engine';

        // Siapkan perintah download sesuai tipe
        $temp_id     = null;
        $staging_dir = null;
        $basename    = null;

        if ($type === 'music') {
            $temp_id   = "raw_" . time();
            $temp_path = "{$this->base_path}/temp/$temp_id.%(ext)s";
            $cmd_dl    = $this->base_cmd
                . "-f bestaudio -o " . escapeshellarg($temp_path)
                . " --write-thumbnail --convert-thumbnails jpg --embed-thumbnail"
                . " --newline " . escapeshellarg($url) . " 2>&1";
        } else {
            $staging_dir = "{$this->base_path}/temp/";
            $basename    = $clean;

            // Hindari konflik nama file di staging
            if (file_exists($staging_dir . $basename . ".mp4")) {
                $basename .= "-" . substr(md5(uniqid('', true)), -4);
            }

            $output_tpl = $staging_dir . $basename . ".%(ext)s";
            $format     = $this->resolveVideoFormat($url);

            $cmd_dl = $this->base_cmd
                . "-f " . escapeshellarg($format)
                . " --merge-output-format mp4 -o " . escapeshellarg($output_tpl)
                . " --write-thumbnail --convert-thumbnails jpg --newline "
                . escapeshellarg($url) . " 2>&1";
        }

        // Tampilkan overlay sebelum eksekusi
        $this->showMEeLOverlay('download');

        // Kirim URL ke overlay
        echo "<script>meelDlInfo(" . json_encode($url) . ");</script>";
        flush();

        $error_log = "";
        $start     = time();
        // Tambahkan -N 4 (4 koneksi paralel untuk mempercepat download, aman untuk server single-user)
        $full_cmd  = "export PATH=/usr/local/bin:/usr/bin:/bin; timeout " . self::DOWNLOAD_TIMEOUT . " $cmd_dl";
        $handle    = @popen($full_cmd, 'r');

        if (!$handle) {
            $this->releaseQueue($queue_id, 'failed');
            $this->jsError("Gagal menjalankan yt-dlp. Cek permission atau install yt-dlp.");
            return "";
        }

        echo str_repeat(' ', 1024);

        // Contoh output yt-dlp:
        // [download]  63.2% of   45.23MiB at    4.20MiB/s ETA 00:42 (frag 3/5)
        $frag_total = 0;
        while (!feof($handle)) {
            if (time() - $start > self::DOWNLOAD_TIMEOUT) {
                $error_log .= "\n[ERROR] Timeout exceeded";
                break;
            }
            $line = fgets($handle);
            if ($line === false) break;

            $error_log .= $line;

            if (preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%\s+of\s+([\d.]+\s*\S+)\s+at\s+([\d.]+\s*\S+\/s)(?:\s+ETA\s+([\d:]+))?(?:\s+\(frag\s+(\d+)\/(\d+)\))?/', $line, $m)) {
                $pct   = (int)$m[1];
                $size  = $m[2]  ?? '';
                $speed = $m[3]  ?? '';
                $eta   = isset($m[4]) ? 'ETA ' . $m[4] : '';
                $frag  = (isset($m[5], $m[6]) && $m[6]) ? $m[5] . ' / ' . $m[6] : '';
                $args  = json_encode($pct) . ',' . json_encode($eta) . ',' . json_encode($speed) . ',' . json_encode($size) . ',' . json_encode($frag);
                echo "<script>meelDlPct($args);</script>";
                flush();
            } elseif (preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%/', $line, $m)) {
                // Fallback: hanya persentase
                $pct  = (int)$m[1];
                echo "<script>meelDlPct($pct);</script>";
                flush();
            }
        }

        $exit_code = pclose($handle);

        // Validasi hasil download
        $is_success = false;
        if ($type === 'music') {
            $files      = glob("{$this->base_path}/temp/$temp_id.*");
            $is_success = !empty($files);
        } else {
            $expected   = $staging_dir . $basename . ".mp4";
            $is_success = file_exists($expected) && filesize($expected) > 0;
        }

        if (!$is_success) {
            $this->releaseQueue($queue_id, 'failed');
            file_put_contents('/tmp/ytdlp_error.log', $error_log);

            $error_msg  = "Download gagal. Detail disimpan di server.";
            $last_lines = array_slice(explode("\n", $error_log), -3);
            $detail     = trim(implode(" | ", $last_lines));
            if ($detail) $error_msg = substr($detail, 0, 200);

            $this->jsError($error_msg);
            return "";
        }

        $this->releaseQueue($queue_id, 'completed');

        if ($type === 'music') {
            return $this->finalizeMusic($temp_id, $title, $artist, $album, $duration, $description);
        }
        return $this->finalizeVideo($basename, $basename . ".jpg", $title, $artist, $duration, $description);
    }

    // ─── FINALIZE MUSIC ───────────────────────────────────────────────────────

    private function finalizeMusic(
        string $temp_id,
        string $title,
        string $artist,
        string $album,
        int $duration,
        string $description = 'Upload by MEeL Engine'
    ): string {
        $found    = glob("{$this->base_path}/temp/$temp_id.*");
        $raw_file = "";
        foreach ($found as $f) {
            if (pathinfo($f, PATHINFO_EXTENSION) !== 'jpg') {
                $raw_file = basename($f);
                break;
            }
        }

        if ($raw_file) {
            $params = http_build_query([
                'temp_file'   => $raw_file,
                'title'       => $title,
                'artist'      => $artist,
                'album'       => $album,
                'duration'    => $duration,
                'description' => $description,
            ]);
            echo "<script>window.location.href = 'controllers/post_encode.php?$params';</script>";
            exit;
        }

        return $this->msgError("File audio tidak ditemukan setelah download.");
    }

    // ─── FINALIZE VIDEO (HLS) ─────────────────────────────────────────────────

    private function finalizeVideo(
        string $basename,
        string $db_thumb,
        string $title,
        string $artist,
        int    $duration,
        string $description = 'Upload by MEeL Engine'
    ): string {
        $staging_mp4  = "{$this->base_path}/temp/{$basename}.mp4";
        $dl_thumb_src = "{$this->base_path}/temp/{$basename}.jpg";

        if (!file_exists($staging_mp4)) {
            $this->jsError("File MP4 staging tidak ditemukan: $staging_mp4");
            return "";
        }

        echo "<script>meelPhase('transcode');</script>";
        flush();

        // ── Tentukan nama folder unik di HDD ──────────────────────────────────
        $folder_name = $basename;
        $counter     = 1;
        while (is_dir(self::HDD_VIDEO_DIR . $folder_name . "/")) {
            $folder_name = $basename . "-" . $counter;
            $counter++;
        }

        $db_filename = "video/{$folder_name}/{$folder_name}.m3u8";
        $work_folder = "{$this->base_path}/temp/{$folder_name}/";

        if (!is_dir($work_folder)) mkdir($work_folder, 0755, true);

        // ── Kompres thumbnail ─────────────────────────────────────────────────
        $work_thumb = $work_folder . $db_thumb;
        if (file_exists($dl_thumb_src)) {
            // Thumbnail: scale ke max 1280px, quality 5 (lebih cepat dari quality 2)
            // -threads 1 cukup untuk operasi ringan ini — hemat core untuk HLS di bawah
            $cmd_compress = self::ENV_PREFIX . escapeshellarg($this->ffmpeg_bin)
                . " -y -threads 1"
                . " -i " . escapeshellarg($dl_thumb_src)
                . " -vf " . escapeshellarg("scale='min(1280,iw)':-1")
                . " -q:v 5 " . escapeshellarg($work_thumb) . " 2>&1";
            shell_exec($cmd_compress);

            if (!file_exists($work_thumb) || filesize($work_thumb) === 0) {
                copy($dl_thumb_src, $work_thumb); // fallback
            }
            @unlink($dl_thumb_src);
        }

        $thumb_generated = file_exists($work_thumb) && filesize($work_thumb) > 0;
        if (!$thumb_generated) $db_thumb = "default_thumb.jpg";

        // ── Dapatkan durasi video ─────────────────────────────────────────────
        $file_dur = $this->probeDuration($staging_mp4);

        // ── Transcode ke HLS ──────────────────────────────────────────────────
        // -codec copy: stream copy (tidak re-encode), sehingga QSV tidak relevan.
        // -threads hanya berlaku untuk muxer/demuxer I/O; tetap set untuk konsistensi.
        $work_m3u8  = $work_folder . $folder_name . ".m3u8";
        $cmd_hls = self::ENV_PREFIX . escapeshellarg($this->ffmpeg_bin)
            . " -threads " . self::FFMPEG_THREADS
            . " -i " . escapeshellarg($staging_mp4)
            . " -codec copy"
            . " -start_number 0"
            . " -hls_time "             . self::HLS_SEGMENT_DURATION
            . " -hls_list_size 0"
            . " -hls_segment_filename " . escapeshellarg($work_folder . $folder_name . "_%03d.ts")
            . " -f hls " . escapeshellarg($work_m3u8) . " 2>&1";

        $handle = @popen($cmd_hls, 'r');
        if (!$handle) {
            $this->cleanupDir($work_folder);
            $this->jsError("Gagal menjalankan ffmpeg untuk transcode HLS. Cek instalasi ffmpeg.");
            return "";
        }

        while (!feof($handle)) {
            $line = fgets($handle);
            if (preg_match('/time=((\d+):(\d+):(\d+)\.(\d+))/', $line, $m) && $file_dur > 0) {
                $cur = ($m[2] * 3600) + ($m[3] * 60) + $m[4];
                $pct = min(99, round(($cur / $file_dur) * 100));
                echo "<script>meelTcPct($pct);</script>";
                flush();
            }
        }
        pclose($handle);

        if (!file_exists($work_m3u8) || filesize($work_m3u8) === 0) {
            $this->cleanupDir($work_folder);
            @unlink($staging_mp4);
            $this->jsError("Transcode HLS gagal. File .m3u8 tidak terbentuk.");
            return "";
        }

        // ── Sprite & VTT ──────────────────────────────────────────────────────
        echo "<script>meelPhase('sprite');meelSpPct(0,'Membuat thumbnail.vtt...');</script>";
        flush();
        $this->generateSpriteAndVTT($staging_mp4, $work_folder);
        echo "<script>meelSpPct(100,'Sprite & VTT selesai.');</script>";
        flush();

        @unlink($staging_mp4);

        // ── Pindahkan ke USB HDD ──────────────────────────────────────────────
        // Wajib pakai moveFile() karena USB HDD = filesystem berbeda dari /tmp/work
        // rename() cross-device akan selalu gagal diam-diam di Linux
        $hdd_target_folder = self::HDD_VIDEO_DIR . $folder_name . "/";
        if (!is_dir($hdd_target_folder)) mkdir($hdd_target_folder, 0755, true);

        $move_failed = false;
        foreach (glob($work_folder . "*") as $work_file) {
            $filename = basename($work_file);

            if ($thumb_generated && $filename === $db_thumb) {
                $dest = self::HDD_THUMB_DIR . $filename;
            } else {
                $dest = $hdd_target_folder . $filename;
            }

            if (!$this->moveFile($work_file, $dest)) {
                $move_failed = true;
                break;
            }
        }

        @rmdir($work_folder);

        if ($move_failed) {
            // Rollback: hapus file yang sudah terlanjur dipindahkan
            $this->cleanupDir($hdd_target_folder);
            @unlink(self::HDD_THUMB_DIR . $db_thumb);
            $this->jsError("Gagal memindahkan file ke storage. Cek permission USB HDD.");
            return "";
        }

        // ── Simpan ke database ────────────────────────────────────────────────
        $hdd_m3u8_full  = self::HDD_BASE . $db_filename;
        $hdd_thumb_full = self::HDD_THUMB_DIR . $db_thumb;

        if (!file_exists($hdd_m3u8_full) || filesize($hdd_m3u8_full) === 0) {
            $this->jsError("File M3U8 tidak ditemukan di HDD setelah dipindahkan: $hdd_m3u8_full");
            return "";
        }
        if ($thumb_generated && (!file_exists($hdd_thumb_full) || filesize($hdd_thumb_full) === 0)) {
            $this->jsError("Thumbnail tidak ditemukan di HDD setelah dipindahkan: $hdd_thumb_full");
            return "";
        }

        $romaji   = getRomajiName($title);
        $metadata = mb_strtolower("$title $artist $romaji", 'UTF-8');
        $views    = 0;

        $stmt = $this->conn->prepare(
            "INSERT INTO video (title, description, filename, thumbnail, duration, views, user_id, search_metadata, upload_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) {
            $this->jsError("Database prepare error: " . $this->conn->error);
            return "";
        }
        $stmt->bind_param("ssssiiss", $title, $description, $db_filename, $db_thumb, $duration, $views, $this->user_id, $metadata);
        if (!$stmt->execute()) {
            $this->jsError("Database insert error: " . $stmt->error);
            return "";
        }
        $stmt->close();

        echo "<script>meelDone(" . json_encode($title) . ", 'index.php');</script>";
        flush();
        return "";
    }

    // =========================================================
    // BAGIAN 2: POST ENCODE (post_encode.php)
    // =========================================================

    public function encodeMusic(
        string $temp_file,
        string $title,
        string $artist,
        string $album,
        int    $duration,
        string $description = 'Upload by MEeL Engine'
    ): array {
        putenv("LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu:/usr/local/lib");
        putenv("PATH=/usr/local/bin:/usr/bin:/bin");

        $input_path = "{$this->base_path}/temp/$temp_file";
        $clean      = getRomajiName($title);

        // Cek konflik nama file
        $final_fname = $clean . ".ogg";
        $counter     = 1;
        while (file_exists("{$this->base_path}/music/upload/file/$final_fname")) {
            $final_fname = $clean . "-" . $counter . ".ogg";
            $counter++;
        }

        $final_path = "{$this->base_path}/music/upload/file/$final_fname";
        $thumb_name = str_replace('.ogg', '.jpg', $final_fname);

        // Encode ke Opus/OGG
        // -compression_level 10: kualitas encoding terbaik (libopus default = 10, eksplisit untuk kejelasan)
        // -vbr on: Variable Bitrate, lebih efisien dari CBR
        // -threads: libopus adalah single-threaded per stream, tapi ffmpeg bisa paralel demuxer
        $cmd = escapeshellarg($this->ffmpeg_bin)
            . " -y -threads " . self::FFMPEG_THREADS
            . " -i "                 . escapeshellarg($input_path)
            . " -c:a libopus -vbr on -compression_level 10"
            . " -metadata title="    . escapeshellarg($title)
            . " -metadata artist="   . escapeshellarg($artist)
            . " " . escapeshellarg($final_path) . " 2>&1";
        $log = shell_exec($cmd);

        if (!file_exists($final_path) || filesize($final_path) === 0) {
            return ['status' => 'error', 'msg' => $log];
        }

        // Ekstrak thumbnail (3 strategi: yt-dlp file → audio metadata → default)
        $temp_base    = pathinfo($temp_file, PATHINFO_FILENAME);
        $temp_dir     = "{$this->base_path}/temp";
        $thumb_result = $this->extractMusicThumbnail($input_path, $temp_dir, $temp_base, $thumb_name);

        @unlink($input_path);

        // Bersihkan sisa file temporary dari yt-dlp
        foreach (glob("$temp_dir/$temp_base.*") as $leftover) {
            @unlink($leftover);
        }

        $romaji_title  = getRomajiName($title);
        $romaji_artist = getRomajiName($artist);
        $metadata      = mb_strtolower("$title $artist $album $romaji_title $romaji_artist", 'UTF-8');

        $stmt = $this->conn->prepare(
            "INSERT INTO music (title, artist, album, description, search_metadata, filename, thumbnail, duration, user_id, upload_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param("sssssssii", $title, $artist, $album, $description, $metadata, $final_fname, $thumb_result, $duration, $this->user_id);

        if ($stmt->execute()) {
            $stmt->close();
            return ['status' => 'success', 'filename' => $final_fname];
        }
        $err = $this->conn->error;
        $stmt->close();
        return ['status' => 'error', 'msg' => 'Database error: ' . $err];
    }

    // ─── THUMBNAIL HELPERS ────────────────────────────────────────────────────

    private function extractMusicThumbnail(
        string $audio_file,
        string $temp_dir,
        string $temp_base,
        string $target_name
    ): string {
        $thumb_dir = "{$this->base_path}/music/upload/thumbnail";
        if (!is_dir($thumb_dir)) mkdir($thumb_dir, 0755, true);

        // Strategi 1: Cari thumbnail dari yt-dlp
        foreach (['.jpg', '.webp', '.png', '.jpeg'] as $ext) {
            $pattern = "$temp_dir/$temp_base$ext";
            if (file_exists($pattern) && filesize($pattern) > 0) {
                return $this->convertAndSaveThumbnail($pattern, $thumb_dir, $target_name);
            }
        }

        // Strategi 2: Ekstrak dari ID3/VORBIS metadata audio
        $extracted = $this->extractThumbnailFromAudio($audio_file, $thumb_dir, $target_name);
        if ($extracted !== 'music_default.png') return $extracted;

        // Strategi 3: Gunakan default
        return 'music_default.png';
    }

    private function convertAndSaveThumbnail(
        string $source_image,
        string $target_dir,
        string $target_name
    ): string {
        $target_path = "$target_dir/$target_name";

        // Kalau sudah jpg, langsung copy
        if (strtolower(pathinfo($source_image, PATHINFO_EXTENSION)) === 'jpg') {
            if (copy($source_image, $target_path)) {
                @unlink($source_image);
                return $target_name;
            }
        }

        // Convert ke JPG via ffmpeg — scale ke max 500px, -threads 1 cukup untuk gambar kecil
        $cmd = self::ENV_PREFIX . escapeshellarg($this->ffmpeg_bin)
            . " -y -threads 1"
            . " -i "  . escapeshellarg($source_image)
            . " -vf " . escapeshellarg("scale='min(500,iw)':-1")
            . " -q:v 6 " . escapeshellarg($target_path) . " 2>&1";
        @shell_exec($cmd);

        if (file_exists($target_path) && filesize($target_path) > 0) {
            @unlink($source_image);
            return $target_name;
        }

        // Fallback: copy original
        if (copy($source_image, $target_path)) {
            @unlink($source_image);
            return $target_name;
        }

        @unlink($source_image);
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

        $temp_extracted = "$target_dir/.temp_thumb_" . time() . "_" . random_int(1000, 9999) . ".jpg";

        $cmd = self::ENV_PREFIX . escapeshellarg($this->ffmpeg_bin)
            . " -y -threads 1"
            . " -i " . escapeshellarg($audio_file)
            . " -an -vframes 1"
            . " -vf " . escapeshellarg("scale='min(500,iw)':-1")
            . " -q:v 6 " . escapeshellarg($temp_extracted) . " 2>&1";
        @shell_exec($cmd);

        if (file_exists($temp_extracted) && filesize($temp_extracted) > 1000) {
            $final_path = "$target_dir/$target_name";
            if (rename($temp_extracted, $final_path)) {
                return $target_name;
            }
            @unlink($temp_extracted);
        }

        @unlink($temp_extracted);
        return 'music_default.png';
    }

    // =========================================================
    // BAGIAN 3: TRANSCODE VIDEO → AUDIO (transcode.php)
    // =========================================================

    public function checkTranscodeQueue(): bool
    {
        $res = $this->conn->query(
            "SELECT COUNT(*) as total FROM transcode_queue WHERE status = 'processing'"
        );
        return $res->fetch_assoc()['total'] >= 2;
    }

    public function transcodeVideo(int $video_id, string $format = 'mp3'): array
    {
        $temp_dir = $this->base_path . "/temp/";
        if (!is_dir($temp_dir)) mkdir($temp_dir, 0755, true);

        // Bersihkan file temp lama (> 2 jam)
        foreach (glob($temp_dir . "transcode_*") as $file) {
            if (time() - filemtime($file) >= 7200) @unlink($file);
        }

        // Bersihkan antrean macet (> 15 menit) — gunakan prepared statement
        $stmt_clean = $this->conn->prepare(
            "DELETE FROM transcode_queue WHERE created_at < NOW() - INTERVAL 15 MINUTE"
        );
        $stmt_clean->execute();
        $stmt_clean->close();

        // Ambil data video
        $stmt = $this->conn->prepare(
            "SELECT title, filename, thumbnail FROM video WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $video_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        if (!$res || $res->num_rows === 0) {
            return ['status' => 'error', 'msg' => 'ID Video tidak ditemukan!'];
        }

        $v_data  = $res->fetch_assoc();
        $db_file = $v_data['filename'];

        $hls_base   = self::HDD_BASE;
        $m3u8_path  = $hls_base . $db_file;
        $hls_folder = dirname($m3u8_path) . "/";

        if (!file_exists($m3u8_path)) {
            return ['status' => 'error', 'msg' => "File HLS tidak ditemukan di: $m3u8_path"];
        }

        $ts_files = glob($hls_folder . "*.ts");
        if (empty($ts_files)) {
            return ['status' => 'error', 'msg' => 'File segmen HLS (.ts) tidak ditemukan!'];
        }
        natsort($ts_files);
        $ts_files = array_values($ts_files);

        // Validasi durasi & ukuran
        $file_dur   = $this->probeDuration($m3u8_path);
        $total_size = array_sum(array_map('filesize', $ts_files));

        if ($this->user_role !== 'admin') {
            if ($total_size > 200 * 1024 * 1024) {
                return ['status' => 'error', 'msg' => 'File terlalu besar! Maks 200MB.'];
            }
            if ($file_dur > 600) {
                return ['status' => 'error', 'msg' => 'Durasi terlalu panjang! Maks 10 menit.'];
            }
        }

        // Cache / reuse output
        $clean_title     = getRomajiName($v_data['title']);
        $output_filename = $clean_title . "." . $format;
        $output_path     = $temp_dir . $output_filename;

        if (file_exists($output_path) && filesize($output_path) > 0) {
            $download_link = "temp/" . $output_filename;
            echo "<script>meelDoneTranscode(" . json_encode($clean_title) . ", " . json_encode($download_link) . ");</script>";
            flush();
            return ['status' => 'success', 'download_link' => $download_link];
        }

        // Cek server busy
        require_once __DIR__ . '/System.php';
        $sys = new System($this->conn);
        if ($sys->isServerBusy()) {
            return ['status' => 'error', 'msg' => 'Silahkan Menunggu. Server sedang sibuk memproses antrean lain.'];
        }

        // Catat ke queue dengan prepared statement
        $stmt_q = $this->conn->prepare(
            "INSERT INTO transcode_queue (user_id, status, created_at) VALUES (?, 'processing', NOW())"
        );
        $stmt_q->bind_param("i", $this->user_id);
        $stmt_q->execute();
        $queue_id = (int)$this->conn->insert_id;
        $stmt_q->close();

        // Buat concat list
        $concat_list_path = $temp_dir . "concat_{$video_id}_" . time() . ".txt";
        $concat_content   = "";
        foreach ($ts_files as $ts) {
            // Gunakan single quote yang di-escape untuk path ffmpeg concat
            $safe_ts        = str_replace("'", "'\\''", $ts);
            $concat_content .= "file '$safe_ts'\n";
        }
        file_put_contents($concat_list_path, $concat_content);

        $thumb_path = $hls_base . "thumbnail/" . $v_data['thumbnail'];
        $use_thumb  = file_exists($thumb_path) && !empty($v_data['thumbnail']);

        // Konfigurasi codec per format
        switch ($format) {
            case 'ogg':
                // libopus: single-threaded per stream, -threads tidak berdampak pada codec
                // tapi tetap set untuk ffmpeg I/O
                $codec     = "libopus";
                $bitrate   = "-b:a 128k -vbr on";
                $use_thumb = false; // OGG/Opus tidak support embedded picture
                break;
            case 'm4a':
                $codec   = "copy"; // Stream copy audio dari HLS AAC
                $bitrate = "";
                break;
            default: // mp3
                // libmp3lame mendukung multi-thread via -threads
                $codec   = "libmp3lame";
                $bitrate = "-q:a 2";
                break;
        }

        $cmd = self::ENV_PREFIX . escapeshellarg($this->ffmpeg_bin)
            . " -y -threads " . self::FFMPEG_THREADS
            . " -f concat -safe 0 -i " . escapeshellarg($concat_list_path);
        if ($use_thumb) $cmd .= " -i " . escapeshellarg($thumb_path);
        $cmd .= " -map 0:a";
        if ($use_thumb) {
            $cmd .= " -map 1:v -c:v copy -disposition:v:0 attached_pic";
            if ($format === 'mp3') $cmd .= " -id3v2_version 3";
        }
        $cmd .= " -c:a $codec $bitrate"
            . " -metadata title="  . escapeshellarg($v_data['title'])
            . " -metadata artist='MEeL Transcoder'"
            . " " . escapeshellarg($output_path) . " 2>&1";

        $this->showMEeLOverlay('transcode');

        $handle = popen($cmd, 'r');
        if ($handle) {
            while (!feof($handle)) {
                $line = fgets($handle);
                if (preg_match('/time=((\d+):(\d+):(\d+)\.(\d+))/', $line, $m) && $file_dur > 0) {
                    $cur   = ($m[2] * 3600) + ($m[3] * 60) + $m[4];
                    $pct   = min(100, round(($cur / $file_dur) * 100));
                    $fmt   = strtoupper($format);
                    $label = "$pct% — CONVERTING TO $fmt";
                    echo "<script>meelTcPct($pct, " . json_encode($label) . ");</script>";
                    flush();
                }
            }
            pclose($handle);
        }

        @unlink($concat_list_path);

        // Update queue status via prepared statement (bukan raw query)
        $stmt_upd = $this->conn->prepare(
            "UPDATE transcode_queue SET status = 'completed' WHERE id = ?"
        );
        $stmt_upd->bind_param("i", $queue_id);
        $stmt_upd->execute();
        $stmt_upd->close();

        if (!file_exists($output_path) || filesize($output_path) === 0) {
            $this->jsError("FFmpeg gagal menghasilkan file output.");
            return ['status' => 'error', 'msg' => 'FFmpeg gagal menghasilkan file.'];
        }

        $download_link = "temp/" . $output_filename;
        echo "<script>meelDoneTranscode(" . json_encode($v_data['title']) . ", " . json_encode($download_link) . ");</script>";
        flush();

        return [
            'status'          => 'success',
            'download_link'   => $download_link,
            'output_filename' => $output_filename,
        ];
    }

    // ─── SPRITE & VTT ─────────────────────────────────────────────────────────

    private function generateSpriteAndVTT(string $video_path, string $target_folder): void
    {
        $w    = self::SPRITE_TILE_W;
        $h    = self::SPRITE_TILE_H;
        $cols = self::SPRITE_COLS;

        $duration = $this->probeDuration($video_path);
        if ($duration <= 0) return;

        // Tentukan interval dinamis berdasarkan durasi
        if ($duration > 3600) $interval = 300;   // > 1 jam   → tiap 5 menit
        elseif ($duration > 1800) $interval = 180;   // > 30 menit → tiap 3 menit
        elseif ($duration > 300)  $interval = 60;    // > 5 menit  → tiap 1 menit
        else                       $interval = 10;    // ≤ 5 menit  → tiap 10 detik

        $total_frames = (int)ceil($duration / $interval);
        $rows         = max(1, (int)ceil($total_frames / $cols));

        $sprite_file = $target_folder . 'thumb_sprite.jpg';
        $vtt_file    = $target_folder . 'thumbnails.vtt';

        // Buat sprite — fps filter + scale + tile
        // -threads FFMPEG_THREADS: tile filter memanfaatkan multi-thread untuk decode frame
        $filter    = "fps=1/$interval,scale=$w:$h,tile={$cols}x{$rows}";
        $cmd_sprite = self::ENV_PREFIX . escapeshellarg($this->ffmpeg_bin)
            . " -y -threads " . self::FFMPEG_THREADS
            . " -i " . escapeshellarg($video_path)
            . " -vf " . escapeshellarg($filter)
            . " " . escapeshellarg($sprite_file) . " 2>&1";
        exec($cmd_sprite);

        if (!file_exists($sprite_file) || filesize($sprite_file) === 0) return;

        // Tulis VTT
        $vtt_content = "WEBVTT\n\n";
        for ($i = 0; $i < $total_frames; $i++) {
            $start = $i * $interval;
            $end   = min(($i + 1) * $interval, $duration);

            $start_time = gmdate("H:i:s", (int)$start) . ".000";
            $end_time   = gmdate("H:i:s", (int)$end)   . ".000";

            $x = ($i % $cols) * $w;
            $y = (int)floor($i / $cols) * $h;

            $vtt_content .= "$start_time --> $end_time\n";
            $vtt_content .= "thumb_sprite.jpg#xywh=$x,$y,$w,$h\n\n";
        }
        file_put_contents($vtt_file, $vtt_content);
    }

    // =========================================================
    // HELPER PRIVATE
    // =========================================================

    /**
     * Dapatkan durasi media (detik) via ffprobe.
     */
    private function probeDuration(string $file_path): float
    {
        $cmd = self::ENV_PREFIX . escapeshellarg($this->ffprobe_bin)
            . " -v error -show_entries format=duration"
            . " -of default=noprint_wrappers=1:nokey=1 "
            . escapeshellarg($file_path);
        return (float)trim((string)shell_exec($cmd));
    }

    /**
     * Pindahkan file lintas filesystem (untuk USB HDD).
     * PHP rename() tidak bisa lintas device — wajib copy() + unlink().
     */
    private function moveFile(string $src, string $dst): bool
    {
        // Coba rename dulu (cepat, jika sama filesystem)
        if (@rename($src, $dst)) return true;

        // Fallback: copy + unlink (untuk USB/cross-device)
        if (copy($src, $dst)) {
            @unlink($src);
            return true;
        }

        return false;
    }

    /**
     * Hapus semua isi direktori tanpa rekursif (flat directory).
     */
    private function cleanupDir(string $dir): void
    {
        foreach (glob(rtrim($dir, '/') . "/*") as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /**
     * Emit JavaScript error ke browser.
     */
    private function jsError(string $msg): void
    {
        echo "<script>meelError(" . json_encode($msg) . ");</script>";
        flush();
    }

    /**
     * Render pesan error HTML (untuk return value, bukan echo langsung).
     */
    private function msgError(string $msg): string
    {
        return "<div class='bg-red-500/10 text-red-500 p-4 rounded-xl border border-red-500/20 mb-6 font-bold text-sm'>✕ "
            . htmlspecialchars($msg) . "</div>";
    }
}
