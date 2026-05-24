<?php

class Uploader
{
    private $conn;
    private $user_id;
    private $username;
    private $user_role;
    private $base_dir;
    private $ffmpeg_bin;
    private $ffprobe_bin;

    public function __construct($db_connection, $session_user_id, $session_username)
    {
        $this->conn      = $db_connection;
        $this->user_id   = (int)$session_user_id;
        $this->username  = $session_username;
        $this->base_dir  = "/media/muhammaddaffa/MEeL/media/video/upload/";
        $this->ffmpeg_bin  = $this->resolveBinary(['/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg', 'ffmpeg']);
        $this->ffprobe_bin = $this->resolveBinary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);

        $stmt = $this->conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $this->user_role = $res['role'] ?? 'user';
    }

    // ─── PRIVATE HELPERS ──────────────────────────────────────────────────────

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

    private function checkRateLimit(string $table): array
    {
        require_once __DIR__ . '/System.php';
        $sys = new System($this->conn);
        return $sys->checkRateLimit($this->user_id, $table, $this->user_role);
    }

    private function generateMetadata(string $title, string $artist = "", string $album = ""): string
    {
        $original = trim("$title $artist $album");
        $romaji   = getRomajiName($original);
        return mb_strtolower($original . " " . $romaji, 'UTF-8');
    }

    private function getUniqueFilename(string $clean_name, string $ext, string $target_dir): string
    {
        $file_name = $clean_name . "." . $ext;
        $counter = 1;
        
        while (file_exists($target_dir . $file_name)) {
            $file_name = $clean_name . "-" . $counter . "." . $ext;
            $counter++;
        }
        
        return $file_name;
    }

    // ─── MUSIC ────────────────────────────────────────────────────────────────

    public function processMusic(array $post, array $files, string $base_dir): array
    {
        $limit = $this->checkRateLimit('music');
        if (!$limit['allowed']) {
            return ['status' => 'error', 'msg' => "Batas upload tercapai! Tunggu {$limit['minutes']} menit lagi.", 'alert' => true];
        }

        $title  = trim($post['title'] ?? '');
        $artist = trim($post['artist'] ?? 'Unknown Artist');
        $album  = trim($post['album']  ?? 'Single');

        if (empty($files['media']['name'])) return ['status' => 'no_file'];

        $raw_filename = pathinfo($files['media']['name'], PATHINFO_FILENAME);
        $ext          = strtolower(pathinfo($files['media']['name'], PATHINFO_EXTENSION));
        $clean_name   = getRomajiName($raw_filename);

        $allowed_ext = ['mp3', 'opus', 'ogg', 'm4a', 'wav', 'flac'];
        if (!in_array($ext, $allowed_ext, true) || preg_match('/\.(php|phtml|sh)/i', $files['media']['name'])) {
            return ['status' => 'error', 'msg' => "Security Error / Format ditolak!"];
        }

        $file_name   = $this->getUniqueFilename($clean_name, $ext, $base_dir . "upload/file/");
        $target_file = $base_dir . "upload/file/" . $file_name;

        if (!move_uploaded_file($files['media']['tmp_name'], $target_file)) {
            return ['status' => 'upload_failed'];
        }

        $max_size = ($this->user_role === 'admin') ? 200 * 1024 * 1024 : 50 * 1024 * 1024;
        if (filesize($target_file) > $max_size) {
            unlink($target_file);
            return ['status' => 'error', 'msg' => "File terlalu besar!", 'alert' => true];
        }

        $dur_cmd  = "export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffprobe_bin) . " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($target_file);
        $duration = (float)trim(shell_exec($dur_cmd));
        $max_dur  = ($this->user_role === 'admin') ? 3600 : 300;
        if ($duration > $max_dur) {
            unlink($target_file);
            return ['status' => 'error', 'msg' => "Durasi maksimal 5 menit!", 'alert' => true];
        }

        $thumb_name    = "music_default.png";
        $thumb_base    = getRomajiName(pathinfo($file_name, PATHINFO_FILENAME));
        $thumb_dir     = $base_dir . "upload/thumbnail/";

        if (!empty($files['thumbnail']['name']) && !empty($files['thumbnail']['tmp_name'])) {
            // ── PRIORITAS 1: Cover art manual dari form upload ────────────────
            $t_clean         = getRomajiName(pathinfo($files['thumbnail']['name'], PATHINFO_FILENAME));
            $thumb_candidate = $this->getUniqueFilename($t_clean, "jpg", $thumb_dir);
            $abs_out         = $thumb_dir . $thumb_candidate;

            shell_exec("export LD_LIBRARY_PATH=''; /usr/bin/ffmpeg -y -i " . escapeshellarg($files['thumbnail']['tmp_name']) . " -vf \"scale='min(1280,iw)':-1\" -q:v 5 " . escapeshellarg($abs_out) . " 2>&1");

            if (file_exists($abs_out) && filesize($abs_out) > 0) {
                $thumb_name = $thumb_candidate;
            }
        }

        if ($thumb_name === "music_default.png") {
            // ── PRIORITAS 2: Embedded thumbnail di dalam file audio (ID3/FLAC) ─
            // FFmpeg: -an abaikan audio, -vframes 1 ambil 1 frame video (= cover art)
            $thumb_candidate = $this->getUniqueFilename($thumb_base, "jpg", $thumb_dir);
            $abs_out         = $thumb_dir . $thumb_candidate;

            shell_exec("export LD_LIBRARY_PATH=''; /usr/bin/ffmpeg -y -i " . escapeshellarg($target_file) . " -an -vframes 1 " . escapeshellarg($abs_out) . " 2>&1");

            if (file_exists($abs_out) && filesize($abs_out) > 0) {
                $thumb_name = $thumb_candidate;
            }
        }

        $skip_transcode = (isset($post['skip_transcode']) && $this->user_role === 'admin');
        if (!$skip_transcode) {
            $opus_file = pathinfo($file_name, PATHINFO_FILENAME) . ".ogg";
            $opus_path = $base_dir . "upload/file/" . $opus_file;
            exec("export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffmpeg_bin) . " -y -i " . escapeshellarg($target_file) . " -c:a libopus -vbr on -compression_level 10 " . escapeshellarg($opus_path), $out, $ret);
            if ($ret === 0 && file_exists($opus_path)) {
                unlink($target_file);
                $file_name = $opus_file;
            }
        }

        $meta = $this->generateMetadata($title, $artist, $album);
        $sql  = "INSERT INTO music (title, artist, search_metadata, album, filename, thumbnail, user_id, upload_date) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssssssi", $title, $artist, $meta, $album, $file_name, $thumb_name, $this->user_id);

        return ($stmt->execute()) ? ['status' => 'success'] : ['status' => 'error', 'msg' => "Database error!"];
    }

    // ─── VIDEO ────────────────────────────────────────────────────────────────
    public function processVideo(array $post, array $files, string $upload_dir = ""): array
    {
        $limit = $this->checkRateLimit('video');
        if (!$limit['allowed']) {
            return ['status' => 'error', 'msg' => "Batas upload tercapai! Tunggu {$limit['minutes']} menit lagi.", 'alert' => true];
        }

        if (empty($files['video']['tmp_name']) || !is_uploaded_file($files['video']['tmp_name'])) {
            return ['status' => 'error', 'msg' => 'Tidak ada file video yang diterima.', 'alert' => true];
        }

        $title           = trim($post['title'] ?? 'Untitled Video');
        $temp_video      = $files['video']['tmp_name'];
        $video_name_orig = $files['video']['name'];

        // Validasi ekstensi
        $ext = strtolower(pathinfo($video_name_orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'webm', 'mkv'], true)) {
            return ['status' => 'error', 'msg' => "Format video tidak didukung! Gunakan MP4, WebM, atau MKV.", 'alert' => true];
        }

        $clean_name = preg_replace('/[^A-Za-z0-9\-]/', '_', pathinfo($video_name_orig, PATHINFO_FILENAME));

        // ── TENTUKAN NAMA FOLDER (cek konflik di HDD tujuan) ─────────────────
        $folder_name   = $clean_name;
        $hdd_video_dir = $this->base_dir . "video/";
        $counter       = 1;

        while (is_dir($hdd_video_dir . $folder_name . "/")) {
            $folder_name = $clean_name . "-" . $counter;
            $counter++;
        }

        // ── DIREKTORI KERJA DI temp/ ──────────────────────────────────────────
        // Semua FFmpeg output ditulis di sini dulu
        $meel_temp_base = "/opt/lampp/htdocs/MEeL/temp/";
        $work_folder    = $meel_temp_base . $folder_name . "/";

        if (!is_dir($work_folder)) {
            mkdir($work_folder, 0755, true);
        }

        // Stage file upload ke work_folder agar ekstensi tersedia untuk FFmpeg
        $staged_video = $work_folder . $clean_name . "_staged." . $ext;
        if (!copy($temp_video, $staged_video)) {
            @rmdir($work_folder);
            return ['status' => 'error', 'msg' => 'Gagal menyalin file upload ke staging area.', 'alert' => true];
        }

        // ── THUMBNAIL (ditulis ke work_folder dulu) ───────────────────────────
        $thumb_name        = $clean_name . "_thumb.jpg";
        $work_thumb        = $work_folder . $thumb_name;

        $cmd_thumb = "export LD_LIBRARY_PATH=; " . escapeshellarg($this->ffmpeg_bin) . " -y -i "
            . escapeshellarg($staged_video)
            . " -ss 00:00:05 -vframes 1 -vf \"scale='min(1280,iw)':-1\" -q:v 5 "
            . escapeshellarg($work_thumb) . " 2>&1";
        exec($cmd_thumb);

        // Fallback: ambil frame ke-1 kalau video < 5 detik
        if (!file_exists($work_thumb) || filesize($work_thumb) === 0) {
            $cmd_thumb_fallback = "export LD_LIBRARY_PATH=; " . escapeshellarg($this->ffmpeg_bin) . " -y -i "
                . escapeshellarg($staged_video)
                . " -ss 00:00:01 -vframes 1 -vf \"scale='min(1280,iw)':-1\" -q:v 5 "
                . escapeshellarg($work_thumb) . " 2>&1";
            exec($cmd_thumb_fallback);
        }

        $thumb_generated = file_exists($work_thumb) && filesize($work_thumb) > 0;
        if (!$thumb_generated) {
            $thumb_name = "default_thumb.jpg";
        }

        // ── TRANSCODE KE HLS (output ke work_folder) ─────────────────────────
        $work_m3u8 = $work_folder . $folder_name . ".m3u8";
        $db_filename = "video/" . $folder_name . "/" . $folder_name . ".m3u8";

        $cmd = "export LD_LIBRARY_PATH=; " . escapeshellarg($this->ffmpeg_bin) . " -i " . escapeshellarg($staged_video)
            . " -codec copy"
            . " -start_number 0 -hls_time 20 -hls_list_size 0"
            . " -hls_segment_filename " . escapeshellarg($work_folder . $folder_name . "_%03d.ts")
            . " -f hls " . escapeshellarg($work_m3u8) . " 2>&1";

        exec($cmd, $output, $result);

        // Generate sprite & VTT ke work_folder
        if ($result === 0) {
            $this->generateSpriteAndVTT($staged_video, $work_folder);
        }

        // Hapus staged video setelah FFmpeg selesai
        @unlink($staged_video);

        if ($result !== 0) {
            // Bersihkan work_folder jika FFmpeg gagal
            foreach (glob($work_folder . "*") as $f) @unlink($f);
            @rmdir($work_folder);
            return ['status' => 'error', 'msg' => 'FFmpeg Error: ' . implode("\n", $output)];
        }

        // ── PINDAHKAN KE HDD ──────────────────────────────────────────────────
        // Semua file di work_folder sudah terbuat (.m3u8, .ts, sprite.jpg, .vtt)
        // Sekarang baru pindahkan ke direktori final di HDD.

        $hdd_target_folder = $hdd_video_dir . $folder_name . "/";
        $hdd_thumb_dir     = $this->base_dir . "thumbnail/";

        if (!is_dir($hdd_target_folder)) {
            mkdir($hdd_target_folder, 0755, true);
        }

        $move_failed = false;

        foreach (glob($work_folder . "*") as $work_file) {
            $filename = basename($work_file);

            // Thumbnail dipindahkan ke folder thumbnail/ terpisah
            if ($thumb_generated && $filename === $thumb_name) {
                if (!rename($work_file, $hdd_thumb_dir . $filename)) {
                    $move_failed = true;
                    break;
                }
                continue;
            }

            // Semua file HLS (.m3u8, .ts, sprite, .vtt) ke folder video/
            if (!rename($work_file, $hdd_target_folder . $filename)) {
                $move_failed = true;
                break;
            }
        }

        // Bersihkan work_folder (seharusnya sudah kosong)
        @rmdir($work_folder);

        if ($move_failed) {
            // Rollback: hapus file yang sudah terlanjur dipindahkan
            foreach (glob($hdd_target_folder . "*") as $f) @unlink($f);
            @rmdir($hdd_target_folder);
            @unlink($hdd_thumb_dir . $thumb_name);
            return ['status' => 'error', 'msg' => 'Gagal memindahkan file ke storage. Cek permission HDD.', 'alert' => true];
        }

        // ── INSERT DATABASE ───────────────────────────────────────────────────
        $title = trim($post['title'] ?? 'Untitled Video');
        $meta  = $this->generateMetadata($title);
        $stmt  = $this->conn->prepare(
            "INSERT INTO video (title, filename, thumbnail, search_metadata, user_id, upload_date)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param("ssssi", $title, $db_filename, $thumb_name, $meta, $this->user_id);

        if ($stmt->execute()) {
            return ['status' => 'success'];
        }
        return ['status' => 'error', 'msg' => 'Database error'];
    }
    // ─── MESIN PEMBUAT THUMBNAIL SPRITE & VTT ──────────────────────────────────
    private function generateSpriteAndVTT(string $staged_video, string $target_folder)
    {
        $width    = 160;     // Lebar per thumbnail
        $height   = 90;      // Tinggi per thumbnail (16:9)
        $cols     = 5;       // Jumlah kolom menyamping dalam sprite
        // Command FFPROBE
        $probe_cmd = "export LD_LIBRARY_PATH=; " . escapeshellarg($this->ffprobe_bin) . " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($staged_video);
        $duration = (float) shell_exec($probe_cmd);
        // Duration
        if ($duration > 0) {
            if ($duration > 3600) {         // Jika lebih dari 1 jam
                $interval = 300;            // Ambil frame tiap 5 menit
            } elseif ($duration > 1800) {   // Jika lebih dari 30 menit
                $interval = 180;            // Ambil frame tiap 3 menit
            } elseif ($duration > 300) {    // Jika lebih dari 5 menit
                $interval = 60;             // Ambil frame tiap 1 menit
            } else {                        // Jika 5 menit ke bawah
                $interval = 20;             // Ambil frame tiap 20 detik
            }
            // Hasil Frame
            $total_frames = ceil($duration / $interval);
            $rows         = ceil($total_frames / $cols);
            if ($rows < 1) $rows = 1;

            $sprite_file = $target_folder . 'thumb_sprite.jpg';
            $vtt_file    = $target_folder . 'thumbnails.vtt';
            // Command FFMPEG
            $cmd_sprite = "export LD_LIBRARY_PATH=; " . escapeshellarg($this->ffmpeg_bin) . " -y -i " . escapeshellarg($staged_video) . " -vf \"fps=1/$interval,scale=$width:$height,tile={$cols}x{$rows}\" " . escapeshellarg($sprite_file) . " 2>&1";
            exec($cmd_sprite);
            if (file_exists($sprite_file)) {
                $vtt_content = "WEBVTT\n\n";
                for ($i = 0; $i < $total_frames; $i++) {
                    $start = $i * $interval;
                    $end   = ($i + 1) * $interval;
                    if ($end > $duration) {
                        $end = $duration;
                    }
                    // Perhitungan waktu
                    $start_time = gmdate("H:i:s", $start) . ".000";
                    $end_time   = gmdate("H:i:s", $end) . ".000";
                    // Perhitungan Kolom dan Baris(X dan Y)
                    $col = $i % $cols;
                    $row = floor($i / $cols);
                    $x   = $col * $width;
                    $y   = $row * $height;
                    // Konten VTT
                    $vtt_content .= "$start_time --> $end_time\n";
                    $vtt_content .= "thumb_sprite.jpg#xywh=$x,$y,$width,$height\n\n";
                }
                // Taruh Konten
                file_put_contents($vtt_file, $vtt_content);
            }
        }
    }
}
