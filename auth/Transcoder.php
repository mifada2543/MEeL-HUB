<?php
// File: auth/Transcoder.php

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

    public function __construct($db_connection, $session_user_id)
    {
        $this->conn         = $db_connection;
        $this->user_id      = (int)$session_user_id;
        $this->base_path    = "/opt/lampp/htdocs/MEeL";
        $this->cookies_path = $this->base_path . "/cookies.txt";
        $this->user_agent   = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";
        $this->ffmpeg_bin   = $this->resolveBinary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);
        $this->ffprobe_bin  = $this->resolveBinary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);
        $this->base_cmd = "export PATH=/usr/local/bin:/usr/bin:/bin; export LC_ALL=en_US.UTF-8; /usr/local/bin/yt-dlp --js-runtime node --no-warnings --restrict-filenames"
            . " --ffmpeg-location " . escapeshellarg(dirname($this->ffmpeg_bin))
            . " --user-agent " . escapeshellarg($this->user_agent)
            . " --cookies "    . escapeshellarg($this->cookies_path) . " ";

        $stmt = $this->conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $this->user_role = $stmt->get_result()->fetch_assoc()['role'] ?? 'user';
    }

    public function getUserRole(): string
    {
        return $this->user_role;
    }

    private function resolveBinary(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (strpos($candidate, '/') !== false) {
                if (is_executable($candidate)) {
                    return $candidate;
                }
                continue;
            }

            $resolved = trim((string)shell_exec("command -v " . escapeshellarg($candidate) . " 2>/dev/null"));
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return $candidates[0];
    }

    public function checkServerBusy(): ?array
    {
        // Deprecated: Gunakan System->getActiveQueues() atau System->isServerBusy()
        $res = $this->conn->query("SELECT q.*, u.username FROM upload_queue q 
                                   JOIN users u ON q.user_id = u.id 
                                   WHERE q.status = 'processing' LIMIT 1");
        return $res ? $res->fetch_assoc() : null;
    }

    private function lockQueue(string $url, string $type): int
    {
        $stmt = $this->conn->prepare("INSERT INTO upload_queue (url, media_type, user_id, status) VALUES (?, ?, ?, 'processing')");
        $stmt->bind_param("ssi", $url, $type, $this->user_id);
        $stmt->execute();
        return (int)$this->conn->insert_id;
    }

    private function releaseQueue(int $queue_id, string $status = 'completed'): void
    {
        $allowed = ['completed', 'failed'];
        $status  = in_array($status, $allowed, true) ? $status : 'failed';
        $stmt = $this->conn->prepare("UPDATE upload_queue SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $queue_id);
        $stmt->execute();
    }

    private function fetchMetadata(string $url): ?array
    {
        // 1. Tambahkan 2>&1 untuk memastikan pesan error sistem ikut tertangkap
        $cmd = $this->base_cmd . "--skip-download --print-json " . escapeshellarg($url) . " 2>&1";

        // 2. Jalankan perintah
        exec($cmd, $output_array, $return_var);
        $output = implode("\n", $output_array);

        // 3. Cari posisi awal JSON
        $start = strpos($output, '{');
        $end   = strrpos($output, '}');

        if ($start !== false && $end !== false) {
            $json_string = substr($output, $start, ($end - $start) + 1);
            $data = json_decode($json_string, true);

            if (json_last_error() === JSON_ERROR_NONE && !empty($data)) {
                return $data;
            }
        }

        // --- BAGIAN DEBUG DIMULAI ---
        // Jika gagal sampai sini, kita "telanjangi" error-nya agar terlihat di browser
        echo "<div style='background:#1a1a1a; color:#ff4444; padding:20px; border:2px solid red; font-family:monospace; margin:20px; border-radius:10px;'>";
        echo "<h2 style='color:white; border-bottom:1px solid #333;'>⚠️ DEBUG: GAGAL PARSING METADATA</h2>";

        echo "<strong>URL yang dicoba:</strong> " . htmlspecialchars($url) . "<br><br>";

        echo "<strong>Perintah Shell:</strong><br>";
        echo "<code style='background:#000; color:#00ff00; padding:5px; display:block; margin:5px 0;'>" . htmlspecialchars($cmd) . "</code><br>";

        echo "<strong>Status Return Code:</strong> " . $return_var . " (Jika bukan 0, berarti sistem error)<br><br>";

        echo "<strong>Output Mentah dari yt-dlp:</strong>";
        echo "<pre style='background:#000; color:#ccc; padding:15px; overflow:auto; max-height:400px; border:1px solid #444;'>" . htmlspecialchars($output) . "</pre>";

        // Cek ketersediaan binary
        $check_node = shell_exec("which node");
        $check_ytdlp = shell_exec("which yt-dlp");
        echo "<strong>Cek Path System:</strong><br>";
        echo "- Path Node: " . ($check_node ?: "<span style='color:yellow;'>Tidak ditemukan</span>") . "<br>";
        echo "- Path yt-dlp: " . ($check_ytdlp ?: "<span style='color:yellow;'>Tidak ditemukan</span>") . "<br>";
        echo "- Path ffmpeg: " . htmlspecialchars($this->ffmpeg_bin) . "<br>";
        echo "- Path ffprobe: " . htmlspecialchars($this->ffprobe_bin) . "<br>";

        echo "</div>";
        die("--- PROSES DIHENTIKAN UNTUK DEBUG ---");
        // --- BAGIAN DEBUG SELESAI ---

        return null;
    }

    private function resolveVideoFormat(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtu.be') !== false) {
            return "bestvideo[height<=1080][vcodec^=avc1]+bestaudio[ext=m4a]/best[height<=1080][vcodec^=avc1]";
        } elseif (strpos($host, 'nicovideo.jp') !== false || strpos($host, 'nico.ms') !== false) {
            return "bestvideo[height<=1080]+bestaudio/best";
        } elseif (strpos($host, 'tiktok.com') !== false) {
            return "bestvideo1+bestaudio/best";
        }
        return "bestvideo[height<=1080]+bestaudio/best";
    }

    // ─── OVERLAY SYSTEM ───────────────────────────────────────────────────────

    private function showMEeLOverlay(string $initial_phase = 'download'): void
    {
        // Pastikan tidak ada buffer yang tertahan
        while (ob_get_level()) ob_end_clean();

        // Header penting untuk streaming
        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');

        $ui_file = $this->base_path . "/partials/ui.php";
        if (file_exists($ui_file)) {
            include $ui_file;
        }

        // Kirim padding agar browser segera merender (PENTING)
        echo str_repeat(' ', 65536);

        echo "<script>meelPhase('" . htmlspecialchars($initial_phase) . "');</script>";
        flush();
    }

    public function processDownload(string $url, string $type): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
            echo "<script>meelError(" . json_encode("URL tidak valid atau protokol tidak didukung.") . ");</script>";
            flush();
            return "";
        }
        if (!in_array($type, ['video', 'music'], true)) {
            echo "<script>meelError(" . json_encode("Tipe media tidak valid.") . ");</script>";
            flush();
            return "";
        }
        if (strlen($url) > 500) {
            echo "<script>meelError(" . json_encode("URL terlalu panjang.") . ");</script>";
            flush();
            return "";
        }

        $queue_id = $this->lockQueue($url, $type);

        $meta = $this->fetchMetadata($url);
        if (!$meta) {
            $this->releaseQueue($queue_id, 'failed');
            echo "<script>meelError(" . json_encode("Gagal ambil metadata.") . ");</script>";
            flush();
            return "";
        }

        $title    = $meta['title'] ?? "Upload_" . time();
        $artist   = $meta['artist'] ?? ($meta['uploader'] ?? 'Unknown Artist');
        $album    = $meta['album'] ?? 'Single';
        $duration = (int)($meta['duration'] ?? 0);
        $clean    = getRomajiName($title);

        // =========================
        // PREPARE COMMAND
        // =========================
        // Inisialisasi variabel dengan nilai default
        $temp_id    = null;
        $staging_dir = null;
        $basename    = null;

        if ($type === 'music') {
            $temp_id   = "raw_" . time();
            $temp_path = "{$this->base_path}/temp/$temp_id.%(ext)s";
            $cmd_dl    = $this->base_cmd . "-f bestaudio -o " . escapeshellarg($temp_path)
                . " --write-thumbnail --convert-thumbnails jpg --embed-thumbnail"
                . " --newline " . escapeshellarg($url) . " 2>&1";
        } else {
            // [DIUBAH] Download video ke temp/ dulu, bukan langsung ke HDD
            $staging_dir = "{$this->base_path}/temp/";
            $basename    = $clean;

            if (file_exists($staging_dir . $basename . ".mp4")) {
                $basename .= "-" . substr(md5(time()), -4);
            }

            $output_tpl = $staging_dir . $basename . ".%(ext)s";
            $format     = $this->resolveVideoFormat($url);

            $cmd_dl = $this->base_cmd . "-f " . escapeshellarg($format) . " --merge-output-format mp4 -o " . escapeshellarg($output_tpl)
                . " --write-thumbnail --convert-thumbnails jpg --newline " . escapeshellarg($url) . " 2>&1";
        }

        // =========================
        // EXECUTION
        // =========================
        $this->showMEeLOverlay('download');

        $error_log = "";
        $start     = time();
        $timeout   = 900; // 15 menit

        $full_cmd = "export PATH=/usr/local/bin:/usr/bin:/bin; timeout 900 $cmd_dl";
        $handle   = @popen($full_cmd, 'r');

        if (!$handle) {
            $this->releaseQueue($queue_id, 'failed');
            echo "<script>meelError(" . json_encode("Gagal menjalankan yt-dlp. Cek permission atau install yt-dlp.") . ");</script>";
            flush();
            return "";
        }

        echo str_repeat(' ', 1024);

        while (!feof($handle)) {
            if (time() - $start > $timeout) {
                $error_log .= "\n[ERROR] Timeout exceeded";
                break;
            }

            $line = fgets($handle);
            if ($line === false) break;

            $error_log .= $line;

            // Progress parsing lebih robust
            if (preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%/', $line, $m)) {
                $pct = (int)$m[1];
                echo "<script>meelDlPct($pct);</script>";
                flush();
            }
        }

        $exit_code = pclose($handle);

        // =========================
        // VALIDASI HASIL (IMPORTANT)
        // =========================
        $is_success = false;

        if ($type === 'music') {
            $files = glob("{$this->base_path}/temp/$temp_id.*");
            $is_success = !empty($files);
        } else {
            // staging_dir sudah menunjuk ke temp/ sejak perubahan di atas
            $expected = $staging_dir . $basename . ".mp4";
            $is_success = file_exists($expected) && filesize($expected) > 0;
        }

        // =========================
        // HANDLE FAILURE
        // =========================
        if (!$is_success) {
            $this->releaseQueue($queue_id, 'failed');
            file_put_contents('/tmp/ytdlp_error.log', $error_log);
            
            $error_msg = "Download gagal. Detail disimpan di server.";
            $last_lines = array_slice(explode("\n", $error_log), -3);
            $detail = trim(implode(" | ", $last_lines));
            if ($detail) $error_msg = substr($detail, 0, 200);
            
            echo "<script>meelError(" . json_encode($error_msg) . ");</script>";
            flush();
            return "";
        }

        // =========================
        // SUCCESS
        // =========================
        $this->releaseQueue($queue_id, 'completed');

        if ($type === 'music') {
            return $this->finalizeMusic($temp_id, $title, $artist, $album, $duration);
        }

        return $this->finalizeVideo($basename, $basename . ".jpg", $title, $artist, $duration);
    }

    private function finalizeMusic(string $temp_id, string $title, string $artist, string $album, int $duration): string
    {
        $found    = glob("{$this->base_path}/temp/$temp_id.*");
        $raw_file = "";
        foreach ($found as $f) {
            if (pathinfo($f, PATHINFO_EXTENSION) !== 'jpg') {
                $raw_file = basename($f);
                break;
            }
        }

        if ($raw_file) {
            $params = http_build_query(['temp_file' => $raw_file, 'title' => $title, 'artist' => $artist, 'album' => $album, 'duration' => $duration]);
            echo "<script>window.location.href = 'post_encode.php?$params';</script>";
            exit;
        }
        return $this->msgError("File audio tidak ditemukan setelah download.");
    }

    private function finalizeVideo(string $basename, string $db_thumb, string $title, string $artist, int $duration): string
    {
        // [DIUBAH] MP4 sekarang ada di temp/ (bukan video/upload/file/)
        $staging_mp4  = "{$this->base_path}/temp/{$basename}.mp4";
        $dl_thumb_src = "{$this->base_path}/temp/{$basename}.jpg";

        // ── 1. VALIDASI MP4 STAGING ───────────────────────────────────────────
        if (!file_exists($staging_mp4)) {
            echo "<script>meelError(" . json_encode("File MP4 staging tidak ditemukan: $staging_mp4") . ");</script>";
            flush();
            return "";
        }

        // Transisi ke fase transcode
        echo "<script>meelPhase('transcode');</script>";
        flush();

        // ── 2. TENTUKAN NAMA FOLDER (cek konflik di HDD tujuan) ──────────────
        $hdd_base      = "/media/muhammaddaffa/MEeL/media/video/upload/";
        $hdd_video_dir = $hdd_base . "video/";
        $hdd_thumb_dir = $hdd_base . "thumbnail/";

        $folder_name = $basename;
        $counter     = 1;
        while (is_dir($hdd_video_dir . $folder_name . "/")) {
            $folder_name = $basename . "-" . $counter;
            $counter++;
        }

        $db_filename = "video/{$folder_name}/{$folder_name}.m3u8";

        // ── 3. DIREKTORI KERJA DI temp/ ───────────────────────────────────────
        $work_folder = "{$this->base_path}/temp/{$folder_name}/";
        if (!is_dir($work_folder)) mkdir($work_folder, 0755, true);

        // ── 4. THUMBNAIL ──────────────────────────────────────────────────────
        // Thumbnail dari yt-dlp sudah ada di temp/ — kompres ke work_folder
        $work_thumb = $work_folder . $db_thumb;

        if (file_exists($dl_thumb_src)) {
            $cmd_compress = "export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffmpeg_bin) . " -y -i " . escapeshellarg($dl_thumb_src)
                . " -vf " . escapeshellarg("scale='min(1280,iw)':-1") . " -q:v 5 "
                . escapeshellarg($work_thumb) . " 2>&1";
            shell_exec($cmd_compress);

            // Fallback: copy langsung kalau ffmpeg gagal kompres
            if (!file_exists($work_thumb) || filesize($work_thumb) === 0) {
                copy($dl_thumb_src, $work_thumb);
            }
            @unlink($dl_thumb_src);
        }

        $thumb_generated = file_exists($work_thumb) && filesize($work_thumb) > 0;
        if (!$thumb_generated) {
            $db_thumb = "default_thumb.jpg";
        }

        // ── 5. TRANSCODE KE HLS (output ke work_folder) ──────────────────────
        $dur_cmd  = "export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffprobe_bin) . " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($staging_mp4);
        $file_dur = (float)trim(shell_exec($dur_cmd));

        $work_m3u8 = $work_folder . $folder_name . ".m3u8";

        $cmd_hls = "export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffmpeg_bin) . " -i " . escapeshellarg($staging_mp4)
            . " -codec copy"
            . " -start_number 0 -hls_time 10 -hls_list_size 0"
            . " -hls_segment_filename " . escapeshellarg($work_folder . $folder_name . "_%03d.ts")
            . " -f hls " . escapeshellarg($work_m3u8) . " 2>&1";

        $handle = @popen($cmd_hls, 'r');
        if (!$handle) {
            foreach (glob($work_folder . "*") as $f) @unlink($f);
            @rmdir($work_folder);
            echo "<script>meelError(" . json_encode("Gagal menjalankan ffmpeg untuk transcode HLS. Cek instalasi ffmpeg.") . ");</script>";
            flush();
            return "";
        }

        if ($handle) {
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
        }

        // Verifikasi output HLS terbentuk
        if (!file_exists($work_m3u8) || filesize($work_m3u8) === 0) {
            foreach (glob($work_folder . "*") as $f) @unlink($f);
            @rmdir($work_folder);
            @unlink($staging_mp4);
            echo "<script>meelError(" . json_encode("Transcode HLS gagal. File .m3u8 tidak terbentuk.") . ");</script>";
            flush();
            return "";
        }

        // ── 6. SPRITE & VTT (ke work_folder) ─────────────────────────────────
        echo "<script>meelPhase('sprite');meelSpPct(0,'Membuat thumbnail.vtt...');</script>";
        flush();
        $this->generateSpriteAndVTT($staging_mp4, $work_folder);
        echo "<script>meelSpPct(100,'Sprite & VTT selesai.');</script>";
        flush();

        // Hapus MP4 staging — semua output sudah ada di work_folder
        @unlink($staging_mp4);

        // ── 7. PINDAHKAN KE HDD ───────────────────────────────────────────────
        $hdd_target_folder = $hdd_video_dir . $folder_name . "/";
        if (!is_dir($hdd_target_folder)) mkdir($hdd_target_folder, 0755, true);

        $move_failed = false;
        foreach (glob($work_folder . "*") as $work_file) {
            $filename = basename($work_file);

            // Thumbnail ke folder thumbnail/
            if ($thumb_generated && $filename === $db_thumb) {
                if (!rename($work_file, $hdd_thumb_dir . $filename)) {
                    $move_failed = true;
                    break;
                }
                continue;
            }

            // HLS files ke folder video/
            if (!rename($work_file, $hdd_target_folder . $filename)) {
                $move_failed = true;
                break;
            }
        }

        @rmdir($work_folder);

        if ($move_failed) {
            foreach (glob($hdd_target_folder . "*") as $f) @unlink($f);
            @rmdir($hdd_target_folder);
            @unlink($hdd_thumb_dir . $db_thumb);
            echo "<script>meelError(" . json_encode("Gagal memindahkan file ke storage. Cek permission HDD.") . ");</script>";
            flush();
            return "";
        }

        // ── 8. SIMPAN KE DATABASE ─────────────────────────────────────────────
        // Verifikasi file benar-benar ada di HDD sebelum insert
        $hdd_m3u8_full = $hdd_base . $db_filename;
        $hdd_thumb_full = $hdd_thumb_dir . $db_thumb;
        
        if (!file_exists($hdd_m3u8_full) || filesize($hdd_m3u8_full) === 0) {
            echo "<script>meelError(" . json_encode("File M3U8 tidak ditemukan di HDD: $hdd_m3u8_full") . ");</script>";
            flush();
            return "";
        }

        if ($thumb_generated && (!file_exists($hdd_thumb_full) || filesize($hdd_thumb_full) === 0)) {
            echo "<script>meelError(" . json_encode("Thumbnail tidak ditemukan di HDD: $hdd_thumb_full") . ");</script>";
            flush();
            return "";
        }

        $romaji   = getRomajiName($title);
        $metadata = mb_strtolower("$title $artist $romaji", 'UTF-8');
        $desc     = "Advanced Upload from URL";
        $views    = 0;

        $stmt = $this->conn->prepare(
            "INSERT INTO video (title, description, filename, thumbnail, duration, views, user_id, search_metadata, upload_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        
        if (!$stmt) {
            echo "<script>meelError(" . json_encode("Database prepare error: " . $this->conn->error) . ");</script>";
            flush();
            return "";
        }

        $stmt->bind_param("ssssiiss", $title, $desc, $db_filename, $db_thumb, $duration, $views, $this->user_id, $metadata);
        
        if (!$stmt->execute()) {
            echo "<script>meelError(" . json_encode("Database insert error: " . $stmt->error) . ");</script>";
            flush();
            return "";
        }

        $stmt->close();

        // Tampilkan fase selesai dengan judul + tombol navigasi
        echo "<script>meelDone(" . json_encode($title) . ", 'index.php');</script>";
        flush();
        return "";
    }

    // =========================================================
    // BAGIAN 2: POST ENCODE (post_encode.php)
    // =========================================================

    public function encodeMusic(string $temp_file, string $title, string $artist, string $album, int $duration): array
    {
        putenv("LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu:/usr/local/lib");
        putenv("PATH=/usr/local/bin:/usr/bin:/bin");

        $input_path  = "{$this->base_path}/temp/$temp_file";
        $clean       = getRomajiName($title);

        // [DIUBAH] Hilangkan _time(), cek file bentrok secara langsung
        $final_fname = $clean . ".ogg";
        $counter = 1;
        while (file_exists("{$this->base_path}/music/upload/file/$final_fname")) {
            $final_fname = $clean . "-" . $counter . ".ogg";
            $counter++;
        }

        $final_path  = "{$this->base_path}/music/upload/file/$final_fname";
        $thumb_name  = str_replace('.ogg', '.jpg', $final_fname);

        $cmd = escapeshellarg($this->ffmpeg_bin) . " -y -i " . escapeshellarg($input_path)
            . " -c:a libopus -vbr on -compression_level 10"
            . " -metadata title="  . escapeshellarg($title)
            . " -metadata artist=" . escapeshellarg($artist)
            . " " . escapeshellarg($final_path) . " 2>&1";
        $log = shell_exec($cmd);

        if (!file_exists($final_path) || filesize($final_path) === 0) {
            return ['status' => 'error', 'msg' => $log];
        }

        // ─── EXTRACT THUMBNAIL (ROBUST) ────────────────────────────────────────
        $temp_base = pathinfo($temp_file, PATHINFO_FILENAME);
        $temp_dir = "{$this->base_path}/temp";
        $thumb_result = $this->extractMusicThumbnail($input_path, $temp_dir, $temp_base, $thumb_name);
        
        if (is_file($input_path)) unlink($input_path);

        // Cleanup sisa file temporary (dari yt-dlp yg tidak ter-process)
        foreach (glob("$temp_dir/$temp_base.*") as $leftover) {
            @unlink($leftover);
        }

        $romaji_title  = getRomajiName($title);
        $romaji_artist = getRomajiName($artist);
        $metadata      = mb_strtolower("$title $artist $album $romaji_title $romaji_artist", 'UTF-8');

        $stmt = $this->conn->prepare("INSERT INTO music (title, artist, album, search_metadata, filename, thumbnail, duration, user_id, upload_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssii", $title, $artist, $album, $metadata, $final_fname, $thumb_result, $duration, $this->user_id);

        if ($stmt->execute()) {
            return ['status' => 'success', 'filename' => $final_fname];
        }
        return ['status' => 'error', 'msg' => 'Database error: ' . $this->conn->error];
    }

    // ─── HELPER: EXTRACT MUSIC THUMBNAIL (ROBUST) ──────────────────────────────
    private function extractMusicThumbnail(string $audio_file, string $temp_dir, string $temp_base, string $target_name): string
    {
        $thumb_dir = "{$this->base_path}/music/upload/thumbnail";
        if (!is_dir($thumb_dir)) mkdir($thumb_dir, 0755, true);

        // STRATEGI 1: Cari thumbnail dari yt-dlp (semua format: .jpg, .webp, .png)
        $image_patterns = [
            "$temp_dir/$temp_base.jpg",
            "$temp_dir/$temp_base.webp",
            "$temp_dir/$temp_base.png",
            "$temp_dir/$temp_base.jpeg",
        ];

        foreach ($image_patterns as $pattern) {
            if (file_exists($pattern) && filesize($pattern) > 0) {
                return $this->convertAndSaveThumbnail($pattern, $thumb_dir, $target_name);
            }
        }

        // STRATEGI 2: Ekstrak thumbnail dari ID3/VORBIS metadata di audio file
        $extracted_thumb = $this->extractThumbnailFromAudio($audio_file, $thumb_dir, $target_name);
        if ($extracted_thumb !== 'music_default.png') {
            return $extracted_thumb;
        }

        // STRATEGI 3: Gunakan default thumbnail
        return 'music_default.png';
    }

    // ─── HELPER: CONVERT DAN SIMPAN THUMBNAIL ──────────────────────────────────
    private function convertAndSaveThumbnail(string $source_image, string $target_dir, string $target_name): string
    {
        $target_path = "$target_dir/$target_name";

        // Jika sudah jpg, copy langsung
        if (strtolower(pathinfo($source_image, PATHINFO_EXTENSION)) === 'jpg') {
            if (copy($source_image, $target_path)) {
                @unlink($source_image);
                return $target_name;
            }
        }

        // Convert ke jpg menggunakan ffmpeg
        $cmd = "export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffmpeg_bin) 
            . " -y -i " . escapeshellarg($source_image)
            . " -vf " . escapeshellarg("scale='min(500,iw)':-1")
            . " -q:v 6 " . escapeshellarg($target_path) . " 2>&1";
        
        @shell_exec($cmd);

        // Verifikasi hasil konversi
        if (file_exists($target_path) && filesize($target_path) > 0) {
            @unlink($source_image);
            return $target_name;
        }

        // Fallback: copy original jika convert gagal
        if (copy($source_image, $target_path)) {
            @unlink($source_image);
            return $target_name;
        }

        // Jika semua gagal, cleanup dan return default
        @unlink($source_image);
        return 'music_default.png';
    }

    // ─── HELPER: EKSTRAK THUMBNAIL DARI AUDIO METADATA ─────────────────────────
    private function extractThumbnailFromAudio(string $audio_file, string $target_dir, string $target_name): string
    {
        if (!file_exists($audio_file) || filesize($audio_file) === 0) {
            return 'music_default.png';
        }

        $temp_extracted = "{$target_dir}/.temp_thumb_" . time() . "_" . random_int(1000, 9999) . ".jpg";

        // Ekstrak cover art dari audio file (bekerja untuk MP3, OGG, M4A, FLAC, dll)
        $cmd = "export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffmpeg_bin)
            . " -y -i " . escapeshellarg($audio_file)
            . " -an -vframes 1"
            . " -vf " . escapeshellarg("scale='min(500,iw)':-1")
            . " -q:v 6 " . escapeshellarg($temp_extracted) . " 2>&1";
        
        @shell_exec($cmd);

        // Verifikasi hasil ekstraksi
        if (file_exists($temp_extracted) && filesize($temp_extracted) > 1000) {
            $final_path = "{$target_dir}/$target_name";
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
        $res = $this->conn->query("SELECT COUNT(*) as total FROM transcode_queue WHERE status = 'processing'");
        return $res->fetch_assoc()['total'] >= 2;
    }

    public function transcodeVideo(int $video_id, string $format = 'mp3'): array
    {
        $temp_dir = $this->base_path . "/temp/";
        if (!is_dir($temp_dir)) mkdir($temp_dir, 0755, true);

        // 1. PEMBERSIHAN FILE TEMP (Lama > 2 Jam)
        foreach (glob($temp_dir . "transcode_*") as $file) {
            if (time() - filemtime($file) >= 7200) @unlink($file);
        }

        // Bersihkan antrean yang macet (> 15 menit)
        $this->conn->query("DELETE FROM transcode_queue WHERE created_at < NOW() - INTERVAL 15 MINUTE");

        // 2. AMBIL DATA VIDEO DARI DB
        $stmt = $this->conn->prepare("SELECT title, filename, thumbnail FROM video WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $video_id);
        $stmt->execute();
        $res = $stmt->get_result();

        if (!$res || $res->num_rows === 0) {
            return ['status' => 'error', 'msg' => 'ID Video tidak ditemukan!'];
        }

        $v_data  = $res->fetch_assoc();
        $db_file = $v_data['filename'];

        $hls_base   = "/media/muhammaddaffa/MEeL/media/video/upload/";
        $m3u8_path  = $hls_base . $db_file;
        $hls_folder = dirname($m3u8_path) . "/";

        if (!file_exists($m3u8_path)) {
            return ['status' => 'error', 'msg' => "File HLS tidak ditemukan di: $m3u8_path"];
        }

        // Kumpulkan segmen .ts
        $ts_files = glob($hls_folder . "*.ts");
        if (empty($ts_files)) {
            return ['status' => 'error', 'msg' => 'File segmen HLS (.ts) tidak ditemukan!'];
        }
        natsort($ts_files);

        // 3. VALIDASI DURASI & UKURAN
        $dur_cmd  = "export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffprobe_bin) . " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($m3u8_path);
        $file_dur = (float)trim(shell_exec($dur_cmd));
        $total_size = array_sum(array_map('filesize', $ts_files));

        if ($this->user_role !== 'admin') {
            if ($total_size > 200 * 1024 * 1024) return ['status' => 'error', 'msg' => 'File terlalu besar! Maks 200MB.'];
            if ($file_dur > 600)                 return ['status' => 'error', 'msg' => 'Durasi terlalu panjang! Maks 10 menit.'];
        }

        // 4. CACHE / REUSE FILE
        $clean_title = getRomajiName($v_data['title']);
        $output_filename = $clean_title . "." . $format;
        $output_path     = $temp_dir . $output_filename;

        if (file_exists($output_path)) {
            $download_link = "temp/" . $output_filename;
            // Panggil fungsi khusus transcode agar overlay hilang/berubah
            echo "<script>meelDoneTranscode(" . json_encode($clean_title) . ", " . json_encode($download_link) . ");</script>";
            flush();
            return ['status' => 'success', 'download_link' => $download_link];
        }

        // 5. BATASAN SERVER BUSY MENGGUNAKAN SYSTEM.PHP
        require_once __DIR__ . '/System.php';
        $sys = new System($this->conn);
        if ($sys->isServerBusy()) {
            return ['status' => 'error', 'msg' => 'Silahkan Menunggu. Server sedang sibuk memproses antrean lain.'];
        }

        // Catat antrean — [FIX] gunakan prepared statement, bukan raw query
        $stmt_q = $this->conn->prepare("INSERT INTO transcode_queue (user_id, status, created_at) VALUES (?, 'processing', NOW())");
        $stmt_q->bind_param("i", $this->user_id);
        $stmt_q->execute();
        $queue_id = $this->conn->insert_id;

        // Persiapan Concat List
        $concat_list_path = $temp_dir . "concat_{$video_id}_" . time() . ".txt";
        $concat_content   = "";
        foreach ($ts_files as $ts) {
            $concat_content .= "file '" . addslashes($ts) . "'\n";
        }
        file_put_contents($concat_list_path, $concat_content);

        // Path Thumbnail sesuai folder di Uploader.php
        $thumb_path = $hls_base . "thumbnail/" . $v_data['thumbnail'];
        $use_thumb  = file_exists($thumb_path) && !empty($v_data['thumbnail']);

        // 6. KONFIGURASI FFMPEG
        switch ($format) {
            case 'ogg':
                $codec = "libopus";
                $bitrate = "-b:a 128k";
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

        $cmd = "export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffmpeg_bin) . " -y -f concat -safe 0 -i " . escapeshellarg($concat_list_path);
        if ($use_thumb) $cmd .= " -i " . escapeshellarg($thumb_path);
        $cmd .= " -map 0:a";
        if ($use_thumb) {
            $cmd .= " -map 1:v -c:v copy -disposition:v:0 attached_pic";
            if ($format === 'mp3') $cmd .= " -id3v2_version 3";
        }
        $cmd .= " -c:a $codec $bitrate -metadata title=" . escapeshellarg($v_data['title']) . " -metadata artist='MEeL Transcoder' " . escapeshellarg($output_path) . " 2>&1";

        // 7. TAMPILKAN OVERLAY & PROSES
        $this->showMEeLOverlay('transcode');

        $handle = popen($cmd, 'r');
        if ($handle) {
            while (!feof($handle)) {
                $line = fgets($handle);
                if (preg_match('/time=((\d+):(\d+):(\d+)\.(\d+))/', $line, $m) && $file_dur > 0) {
                    $cur = ($m[2] * 3600) + ($m[3] * 60) + $m[4];
                    $pct = min(100, round(($cur / $file_dur) * 100));
                    $fmt = strtoupper($format);
                    $label = "$pct% — CONVERTING TO $fmt";
                    echo "<script>meelTcPct($pct, " . json_encode($label) . ");</script>";
                    flush();
                }
            }
            pclose($handle);
        }

        // 8. FINALISASI
        @unlink($concat_list_path);
        $this->conn->query("UPDATE transcode_queue SET status = 'completed' WHERE id = $queue_id");

        if (!file_exists($output_path) || filesize($output_path) === 0) {
            echo "<script>meelError(" . json_encode("FFmpeg gagal menghasilkan file output.") . ");</script>";
            flush();
            return ['status' => 'error', 'msg' => 'FFmpeg gagal menghasilkan file.'];
        }

        // [FIX] Tampilkan fase done menggunakan fungsi khusus Transcode
        $download_link = "temp/" . $output_filename;
        echo "<script>meelDoneTranscode(" . json_encode($v_data['title']) . ", " . json_encode($download_link) . ");</script>";
        flush();

        return [
            'status'          => 'success',
            'download_link'   => $download_link,
            'output_filename' => $output_filename,
        ];
    }

    // =========================================================
    // HELPER PRIVATE
    // =========================================================

    private function msgError(string $msg): string
    {
        return "<div class='bg-red-500/10 text-red-500 p-4 rounded-xl border border-red-500/20 mb-6 font-bold text-sm'>✕ " . htmlspecialchars($msg) . "</div>";
    }
    // ─── MESIN PEMBUAT THUMBNAIL SPRITE & VTT (VERSI TRANSCODER) ──────────────
    private function generateSpriteAndVTT(string $video_path, string $target_folder)
    {
        $interval = 10;      // Ambil frame setiap 10 detik
        $width    = 160;     // Lebar per thumbnail
        $height   = 90;      // Tinggi per thumbnail
        $cols     = 5;       // 5 kolom menyamping

        // 1. Dapatkan durasi video memakai ffprobe
        $probe_cmd = "export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffprobe_bin) . " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($video_path);
        $duration = (float) shell_exec($probe_cmd);

        if ($duration > 0) {
            $total_frames = ceil($duration / $interval);
            $rows         = ceil($total_frames / $cols);
            if ($rows < 1) $rows = 1;

            $sprite_file = $target_folder . 'thumb_sprite.jpg';
            $vtt_file    = $target_folder . 'thumbnails.vtt';

            // 2. Buat Sprite Image (Tiled)
            $filter = "fps=1/$interval,scale=$width:$height,tile={$cols}x{$rows}";
            $cmd_sprite = "export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffmpeg_bin) . " -y -i " . escapeshellarg($video_path) . " -vf " . escapeshellarg($filter) . " " . escapeshellarg($sprite_file) . " 2>&1";
            exec($cmd_sprite);

            // 3. Tulis file .vtt
            if (file_exists($sprite_file)) {
                $vtt_content = "WEBVTT\n\n";
                for ($i = 0; $i < $total_frames; $i++) {
                    $start = $i * $interval;
                    $end   = ($i + 1) * $interval;
                    $start_time = gmdate("H:i:s", $start) . ".000";
                    $end_time   = gmdate("H:i:s", $end) . ".000";
                    $x = ($i % $cols) * $width;
                    $y = floor($i / $cols) * $height;

                    $vtt_content .= "$start_time --> $end_time\n";
                    $vtt_content .= "thumb_sprite.jpg#xywh=$x,$y,$width,$height\n\n";
                }
                file_put_contents($vtt_file, $vtt_content);
            }
        }
    }
}
