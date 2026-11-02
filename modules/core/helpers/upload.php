<?php
if (!defined('MEEL_MAX_BOOK_FILE_BYTES')) {
    define('MEEL_MAX_BOOK_FILE_BYTES', 500 * 1024 * 1024);
}
if (!defined('MEEL_MAX_THUMBNAIL_BYTES')) {
    define('MEEL_MAX_THUMBNAIL_BYTES', 25 * 1024 * 1024);
}
if (!defined('MEEL_MAX_DOWNLOAD_BYTES')) {
    define('MEEL_MAX_DOWNLOAD_BYTES', 2 * 1024 * 1024 * 1024);
}
if (!defined('MEEL_MAX_DOWNLOAD_SECONDS')) {
    define('MEEL_MAX_DOWNLOAD_SECONDS', 7200);
}
if (!function_exists('meel_read_magic_bytes')) {
    function meel_read_magic_bytes(string $path, int $length = 16): string
    {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return '';
        }
        $bytes = (string) fread($fp, $length);
        fclose($fp);
        return $bytes;
    }
}
if (!function_exists('meel_upload_error_message')) {
    /**
     * Terjemahkan kode error PHP upload menjadi pesan yang bisa ditampilkan.
     * Tanpa ini, UPLOAD_ERR_INI_SIZE / FORM_SIZE / PARTIAL hanya menghasilkan
     * error kosong dan pengguna tidak tahu mengapa thumbnail tidak berubah.
     */
    function meel_upload_error_message(int $code, string $label = 'File'): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
                $limit = (string) ini_get('upload_max_filesize');
                return $label . ' terlalu besar untuk batas upload server'
                    . ($limit !== '' ? ' (upload_max_filesize = ' . $limit . ')' : '') . '.';
            case UPLOAD_ERR_FORM_SIZE:
                return $label . ' terlalu besar untuk batas ukuran form (MAX_FILE_SIZE).';
            case UPLOAD_ERR_PARTIAL:
                return $label . ' hanya terunggah sebagian. Silakan coba lagi.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Folder sementara upload tidak tersedia di server.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Server gagal menulis file upload ke disk.';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload ' . $label . ' dihentikan oleh ekstensi PHP.';
            default:
                return 'Upload ' . $label . ' gagal (kode ' . $code . ').';
        }
    }
}
if (!function_exists('meel_post_upload_too_large')) {
    /**
     * Deteksi POST yang dibuang PHP karena melebihi post_max_size.
     * Pada kasus ini $_POST dan $_FILES kosong, sehingga tanpa pengecekan
     * halaman edit hanya reload tanpa pesan error.
     */
    function meel_post_upload_too_large(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) <= 0) {
            return false;
        }
        return empty($_POST) && empty($_FILES);
    }
}
if (!function_exists('meel_magic_extension_ok')) {
    function meel_magic_extension_ok(string $path, string $ext, string $mediaKind = 'audio'): string
    {
        if (!is_file($path) || filesize($path) < 4) {
            return 'File tidak valid atau terlalu kecil.';
        }
        $head = meel_read_magic_bytes($path, 16);
        $ext = strtolower($ext);
        if ($mediaKind === 'video') {
            $ok = str_starts_with($head, "\x1A\x45\xDF\xA3")
                || (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp');
            return $ok ? '' : 'File tidak valid sebagai video (magic bytes mismatch).';
        }
        if ($mediaKind === 'audio') {
            if (str_starts_with($head, "OggS")) return '';
            if (str_starts_with($head, "fLaC")) return '';
            if (str_starts_with($head, "RIFF") && substr($head, 8, 4) === 'WAVE') return '';
            if (str_starts_with($head, "ID3")) return '';
            if (strlen($head) >= 2 && ((ord($head[0]) === 0xFF) && (ord($head[1]) & 0xE0) === 0xE0)) return '';
            if (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp') return '';
            return 'File tidak valid sebagai audio (magic bytes mismatch).';
        }
        if ($mediaKind === 'image') {
            $ok = str_starts_with($head, "\xFF\xD8\xFF")
                || str_starts_with($head, "\x89PNG\x0D\x0A\x1A\x0A")
                || (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP')
                || str_starts_with($head, 'GIF8')
                || str_starts_with($head, 'BM')
                || (strlen($head) >= 4 && substr($head, 0, 4) === "\x00\x00\x01\x00")
                || (strlen($head) >= 4 && substr($head, 0, 4) === "\x00\x00\x02\x00")
                || (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp');
            return $ok ? '' : 'File tidak valid sebagai gambar (magic bytes mismatch).';
        }
        if ($mediaKind === 'pdf') {
            $ok = str_starts_with($head, '%PDF');
            return $ok ? '' : 'File tidak valid sebagai PDF (magic bytes mismatch).';
        }
        if ($mediaKind === 'archive') {
            $ok = str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06");
            return $ok ? '' : 'File tidak valid sebagai arsip ZIP.';
        }
        return '';
    }
}
if (!function_exists('meel_validate_video_codec')) {
    function meel_validate_video_codec(
        string $file_path,
        string $ffprobe_bin = '',
        string $env_prefix = ''
    ): string {
        if ($ffprobe_bin === '') {
            $ffprobe_bin = function_exists('meel_ffprobe_bin')
                ? meel_ffprobe_bin()
                : (defined('MEEL_FFPROBE_PATH') && MEEL_FFPROBE_PATH !== '' ? MEEL_FFPROBE_PATH : 'ffprobe');
        }
        if (!is_file($file_path) || filesize($file_path) < 4) {
            return 'File tidak valid atau terlalu kecil.';
        }
        $allowed_codecs = ['h264', 'hevc', 'h265'];
        $cmd = $env_prefix . escapeshellarg($ffprobe_bin)
            . ' -v error -select_streams v:0'
            . ' -show_entries stream=codec_name'
            . ' -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($file_path);
        $output = [];
        $ret = -1;
        @exec($cmd, $output, $ret);
        if ($ret !== 0 || empty($output)) {
            return 'Gagal membaca codec video. Pastikan ffprobe terinstall.';
        }
        $codec = strtolower(trim($output[0]));
        if (!in_array($codec, $allowed_codecs, true)) {
            return "Codec video \"{$codec}\" tidak didukung. Hanya codec H.264 (AVC) dan H.265 (HEVC) yang diizinkan.";
        }
        return '';
    }
}
if (!function_exists('meel_validate_audio_codec')) {
    function meel_validate_audio_codec(
        string $file_path,
        string $ffprobe_bin = '',
        string $env_prefix = ''
    ): array {
        if ($ffprobe_bin === '') {
            $ffprobe_bin = function_exists('meel_ffprobe_bin')
                ? meel_ffprobe_bin()
                : (defined('MEEL_FFPROBE_PATH') && MEEL_FFPROBE_PATH !== '' ? MEEL_FFPROBE_PATH : 'ffprobe');
        }
        if (!is_file($file_path) || filesize($file_path) < 4) {
            return ['error' => 'File tidak valid atau terlalu kecil.', 'has_audio' => false, 'needs_audio_transcode' => false];
        }
        $allowed_codecs = ['aac', 'mp3', 'ac3', 'eac3'];
        $max_audio_duration = 300;
        $cmd = $env_prefix . escapeshellarg($ffprobe_bin)
            . ' -v error -select_streams a:0'
            . ' -show_entries stream=codec_name,duration'
            . ' -of csv=p=0 '
            . escapeshellarg($file_path);
        $output = [];
        $ret = -1;
        @exec($cmd, $output, $ret);
        if ($ret !== 0 || empty($output) || trim($output[0]) === '') {
            return ['error' => '', 'has_audio' => false, 'needs_audio_transcode' => false];
        }
        $parts = array_map('trim', explode(',', $output[0]));
        $codec = strtolower($parts[0] ?? '');
        $duration = (float)($parts[1] ?? 0);
        if (in_array($codec, $allowed_codecs, true)) {
            return ['error' => '', 'has_audio' => true, 'needs_audio_transcode' => false];
        }
        if ($duration > $max_audio_duration) {
            $minutes = round($duration / 60, 1);
            return [
                'error' => "Audio menggunakan codec \"{$codec}\" yang tidak kompatibel dengan HLS. "
                    . "Durasi audio {$minutes} menit melebihi batas 5 menit untuk transcode otomatis.",
                'has_audio' => true,
                'needs_audio_transcode' => false,
            ];
        }
        return ['error' => '', 'has_audio' => true, 'needs_audio_transcode' => true];
    }
}
if (!function_exists('meel_sanitize_upload_filename')) {
    function meel_sanitize_upload_filename(string $original, string $fallback = 'file'): string
    {
        $original = str_replace("\0", '', $original);
        $name = str_replace(["\\", '/'], '_', $original);
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name) ?: $fallback;
        $name = preg_replace('/\.{2,}/', '_', $name);
        $name = trim($name, '._');
        return $name !== '' ? $name : $fallback;
    }
}
if (!function_exists('meel_upload_allowed_table')) {
    function meel_upload_allowed_table(string $table): string
    {
        return in_array($table, ['music', 'video'], true) ? $table : '';
    }
}
if (!function_exists('get_hourly_upload_count')) {
    function get_hourly_upload_count(\mysqli $conn, int $user_id, string $table): int
    {
        $table = meel_upload_allowed_table($table);
        if ($table === '') return 0;
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE user_id = ? AND upload_date > NOW() - INTERVAL 1 HOUR");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        return $count;
    }
}
if (!function_exists('get_total_upload_count')) {
    function get_total_upload_count(\mysqli $conn, int $user_id, string $table): int
    {
        $table = meel_upload_allowed_table($table);
        if ($table === '') return 0;
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        return $count;
    }
}
if (!function_exists('get_upload_hourly_limit')) {
    function get_upload_hourly_limit(string $user_role): int
    {
        require_once __DIR__ . '/../../auth/RateLimiter.php';
        return RateLimiter::getRoleLimit(2, $user_role);
    }
}
if (!function_exists('meel_sanitize_clean_name')) {
    function meel_sanitize_clean_name(string $raw, int $max_len = 120): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $raw);
        if ($clean === '') {
            return '';
        }
        return substr($clean, 0, $max_len);
    }
}
if (!function_exists('meel_reserve_unique_filename')) {
    function meel_reserve_unique_filename(string $dir, string $clean_name, string $ext, int $max_attempts = 1000, string $suffix_sep = '-'): ?string
    {
        $dir = rtrim($dir, '/\\') . '/';
        $candidate = $clean_name . '.' . $ext;
        for ($i = 0; $i < $max_attempts; $i++) {
            $reserved = @fopen($dir . $candidate, 'x');
            if ($reserved !== false) {
                fclose($reserved);
                return $candidate;
            }
            $candidate = $clean_name . $suffix_sep . ($i + 1) . '.' . $ext;
        }
        return null;
    }
}
if (!function_exists('meel_ffmpeg_thumbnail_webp')) {
    function meel_ffmpeg_thumbnail_webp(
        string $ffmpeg_bin,
        string $src,
        string $dst,
        int $max_width,
        string $extra = '',
        string $env_prefix = '',
        int $threads = 0
    ): bool {
        $cmd = $env_prefix . escapeshellarg($ffmpeg_bin) . ' -y';
        if ($threads > 0) {
            $cmd .= ' -threads ' . $threads;
        }
        $cmd .= ' -i ' . escapeshellarg($src);
        if ($extra !== '') {
            $cmd .= ' ' . $extra;
        }
        $cmd .= ' -vf ' . escapeshellarg("scale='min({$max_width},iw)':-1")
            . ' -c:v libwebp -q:v 78 ' . escapeshellarg($dst) . ' 2>&1';
        @shell_exec($cmd);
        // Verifikasi hasil: file yang hanya setengah tertulis tidak dianggap sukses.
        return meel_is_valid_webp($dst);
    }
}
if (!function_exists('meel_image_mime_allowlist')) {
    /**
     * Satu-satunya sumber kebenaran format gambar yang boleh diunggah
     * sebagai thumbnail/cover (upload musik, video, buku, dan halaman edit).
     *
     * @return string[]
     */
    function meel_image_mime_allowlist(): array
    {
        return [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/avif',
            'image/bmp',
            'image/x-icon',
            'image/vnd.microsoft.icon',
        ];
    }
}
if (!function_exists('meel_image_format_label')) {
    function meel_image_format_label(): string
    {
        return 'JPEG, PNG, WebP, GIF, AVIF, BMP, atau ICO';
    }
}
if (!function_exists('meel_image_mime_of')) {
    function meel_image_mime_of(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }
        $info = @getimagesize($path);
        if (is_array($info) && !empty($info['mime'])) {
            return strtolower((string) $info['mime']);
        }
        // getimagesize() tidak mengenali semua format (mis. AVIF pada PHP lama).
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            return '';
        }
        $mime = @finfo_file($finfo, $path);
        finfo_close($finfo);
        return is_string($mime) ? strtolower($mime) : '';
    }
}
if (!function_exists('meel_image_source_ok')) {
    /**
     * Validasi file gambar sumber (upload) memakai getimagesize(), bukan
     * hanya magic bytes, supaya ICO/BMP/AVIF ikut tervalidasi.
     */
    function meel_image_source_ok(string $path): bool
    {
        if (!is_file($path) || filesize($path) < 16) {
            return false;
        }
        $mime = meel_image_mime_of($path);
        if ($mime === '') {
            return meel_magic_extension_ok($path, 'img', 'image') === '';
        }
        return in_array($mime, meel_image_mime_allowlist(), true);
    }
}
if (!function_exists('meel_is_valid_webp')) {
    /**
     * Pastikan file hasil konversi benar-benar WebP dengan dimensi valid,
     * supaya tidak ada byte PNG/JPEG yang tersimpan dengan ekstensi .webp.
     */
    function meel_is_valid_webp(string $path): bool
    {
        if (!is_file($path) || filesize($path) < 16) {
            return false;
        }
        $info = @getimagesize($path);
        if (!is_array($info)) {
            return false;
        }
        return strtolower((string) ($info['mime'] ?? '')) === 'image/webp'
            && (int) ($info[0] ?? 0) > 0
            && (int) ($info[1] ?? 0) > 0;
    }
}
if (!function_exists('meel_convert_image_to_webp')) {
    /**
     * Convert image to WebP using the configured FFmpeg binary.
     * System ffmpeg is an explicit opt-in via MEEL_FFMPEG_ALLOW_SYSTEM, not a
     * silent fallback when the bundled binary is broken. ImageMagick remains
     * the last image-only fallback.
     */
    function meel_convert_image_to_webp(string $src, string $dst, int $max_w, int $max_h, bool $pad = false, string $ffmpeg_bin = '', string $env_prefix = ''): bool
    {
        $vf = $pad
            ? "scale={$max_w}:{$max_h}:force_original_aspect_ratio=decrease,pad={$max_w}:{$max_h}:(ow-iw)/2:(oh-ih)/2:color=black"
            : "scale='min({$max_w},iw)':-1";

        $bins = [];
        if ($ffmpeg_bin !== '') {
            $bins[] = $ffmpeg_bin;
        }
        if (function_exists('meel_ffmpeg_bin')) {
            $bins[] = meel_ffmpeg_bin();
        } elseif (defined('MEEL_FFMPEG_PATH') && MEEL_FFMPEG_PATH !== '') {
            $bins[] = MEEL_FFMPEG_PATH;
        }
        if (function_exists('meel_media_system_allowed') && meel_media_system_allowed()) {
            $bins = array_merge($bins, ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg']);
        }
        $bins = array_unique(array_filter($bins));

        foreach ($bins as $bin) {
            $cmd = $env_prefix . escapeshellarg($bin) . " -y -i " . escapeshellarg($src)
                . " -vf " . escapeshellarg($vf)
                . " -c:v libwebp -q:v 78 " . escapeshellarg($dst) . " 2>&1";
            $out = [];
            $ret = -1;
            exec($cmd, $out, $ret);
            if ($ret === 0 && meel_is_valid_webp($dst)) {
                return true;
            }
            if (is_file($dst)) {
                @unlink($dst);
            }
        }

        if (is_executable('/usr/bin/convert')) {
            $im_cmd = '/usr/bin/convert ' . escapeshellarg($src)
                . ' -resize ' . escapeshellarg("{$max_w}x{$max_h}>")
                . ' -quality 78';
            if ($pad) {
                $im_cmd .= ' -background black -gravity center -extent ' . escapeshellarg("{$max_w}x{$max_h}");
            }
            $im_cmd .= ' ' . escapeshellarg($dst) . ' 2>&1';
            $out = [];
            $ret = -1;
            exec($im_cmd, $out, $ret);
            if ($ret === 0 && meel_is_valid_webp($dst)) {
                return true;
            }
            if (is_file($dst)) {
                @unlink($dst);
            }
        }

        return false;
    }
}
if (!function_exists('meel_allocate_unique_dir')) {
    function meel_allocate_unique_dir(string $parent, string $base): string
    {
        $parent = rtrim($parent, '/\\') . '/';
        $folder = $base;
        $counter = 1;
        while (is_dir($parent . $folder . '/')) {
            $folder = $base . '-' . $counter;
            $counter++;
        }
        return $folder;
    }
}
if (!function_exists('meel_ffmpeg_encode_opus')) {
    function meel_ffmpeg_encode_opus(
        string $ffmpeg_bin,
        string $input,
        string $output,
        string $env_prefix = '',
        int $threads = 0,
        array $metadata = []
    ): array {
        $cmd = $env_prefix . escapeshellarg($ffmpeg_bin) . ' -y';
        if ($threads > 0) {
            $cmd .= ' -threads ' . $threads;
        }
        $cmd .= ' -i ' . escapeshellarg($input)
            . ' -c:a libopus -vbr on -compression_level 10';
        foreach ($metadata as $key => $value) {
            $cmd .= ' -metadata ' . escapeshellarg($key . '=' . $value);
        }
        $cmd .= ' ' . escapeshellarg($output) . ' 2>&1';

        $out = [];
        $ret = -1;
        @exec($cmd, $out, $ret);

        return [(int)$ret, implode("\n", $out)];
    }
}
if (!function_exists('meel_insert_music_row')) {
    function meel_insert_music_row(
        \mysqli $conn,
        int $user_id,
        string $title,
        string $artist,
        string $album,
        string $description,
        string $search_metadata,
        string $filename,
        string $thumbnail,
        ?int $duration = null
    ): array {
        if ($duration !== null) {
            $sql = "INSERT INTO music (title, artist, album, description, search_metadata, filename, thumbnail, duration, user_id, upload_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        } else {
            $sql = "INSERT INTO music (title, artist, album, description, search_metadata, filename, thumbnail, user_id, upload_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $err = $conn->error !== '' ? $conn->error : 'Prepare gagal.';
            return [false, $err];
        }
        if ($duration !== null) {
            $stmt->bind_param("sssssssii", $title, $artist, $album, $description, $search_metadata, $filename, $thumbnail, $duration, $user_id);
        } else {
            $stmt->bind_param("sssssssi", $title, $artist, $album, $description, $search_metadata, $filename, $thumbnail, $user_id);
        }
        if (!$stmt->execute()) {
            $err = $stmt->error !== '' ? $stmt->error : $conn->error;
            $stmt->close();
            return [false, $err];
        }
        $stmt->close();
        if (function_exists('meel_invalidate_media_stats_cache')) {
            meel_invalidate_media_stats_cache('music');
        }
        return [true, ''];
    }
}
/* reference build: MEeL-C4H9NO2 [78a1c65c4d60c8d8] */

if (!function_exists('meel_handle_upload')) {
    function meel_handle_upload(\mysqli $conn, string $media_type, callable $process_fn, string $log_action): array
    {
        $user_id = (int)($_SESSION['user_id'] ?? 0);
        $is_admin = (get_user_role($conn, $user_id) === 'admin');
        $result = ['status' => '', 'alert_message' => '', 'extra' => []];
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            $result['alert_message'] = 'CSRF token tidak valid.';
            return $result;
        }

        $meelcoin_enabled = MeelCoin::isEnabled($conn);
        $coin_cost = 0;
        if ($meelcoin_enabled && !$is_admin) {
            $coin_cost = MeelCoin::getCost($conn, 'upload');
        }
        $coin_active = $meelcoin_enabled && !$is_admin && $coin_cost > 0;

        $coin_hold_id = 0;
        $attempt_id = 0;
        if ($coin_active) {
            $summary = MeelCoin::getBalanceSummary($conn, $user_id);
            if ($summary['available'] < $coin_cost) {
                $result['alert_message'] = "MEeLCoin tersedia tidak cukup! Dibutuhkan {$coin_cost} coin, tersedia: {$summary['available']} (saldo total {$summary['total']}, ditahan {$summary['held']}).";
                return $result;
            }

            $token = trim((string)($_POST['upload_attempt'] ?? ''));
            $attempt = UploadAttempt::findActiveByToken($conn, $token, $user_id);
            if ($attempt === null) {
                $newAttempt = UploadAttempt::create($conn, $user_id, $media_type);
                if ($newAttempt === null) {
                    $result['alert_message'] = 'Sesi upload tidak valid atau akun tidak aktif. Muat ulang halaman lalu coba lagi.';
                    return $result;
                }
                $attempt_id = (int)$newAttempt['id'];
            } else {
                $attempt_id = (int)$attempt['id'];
            }

            $hold = MeelCoin::hold($conn, $user_id, $coin_cost, 'upload', $media_type);
            if (!$hold['ok']) {
                UploadAttempt::fail($conn, $attempt_id, 'hold_failed');
                $result['alert_message'] = $hold['error'];
                return $result;
            }
            $coin_hold_id = (int)$hold['hold_id'];

            if (!UploadAttempt::attachHold($conn, $attempt_id, $coin_hold_id, $user_id)) {
                MeelCoin::releaseHold($conn, $coin_hold_id, 'attempt_bind_failed');
                UploadAttempt::fail($conn, $attempt_id, 'attempt_bind_failed');
                $result['alert_message'] = 'Gagal mengikat sesi upload ke reservasi MEeLCoin. Coba lagi.';
                return $result;
            }
            UploadAttempt::registerShutdownGuard($conn, $attempt_id);
            UploadAttempt::setStage($conn, $attempt_id, 'processing');
        }

        try {
            // Lepaskan session lock sebelum proses panjang supaya heartbeat
            // browser tidak menunggu sampai upload selesai.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $process_result = $process_fn($_POST, $_FILES, $coin_hold_id, $attempt_id);
        } catch (Throwable $e) {
            error_log('[MEeL] Upload exception: ' . $e->getMessage());
            $process_result = ['status' => 'error', 'msg' => 'Upload gagal diproses: ' . $e->getMessage()];
        }
        if (!is_array($process_result)) {
            $process_result = ['status' => 'error', 'msg' => 'Upload gagal: hasil proses tidak valid.'];
        }

        if (($process_result['status'] ?? '') === 'success') {
            if ($coin_active) {
                $hold = MeelCoin::getHold($conn, $coin_hold_id);
                if ($hold === null || (string)$hold['status'] !== 'captured') {
                    UploadAttempt::fail($conn, $attempt_id, 'coin_not_captured');
                    $result['alert_message'] = 'Upload tidak dapat diverifikasi; MEeLCoin tidak dipotong.';
                    return $result;
                }
                UploadAttempt::complete($conn, $attempt_id);
                try {
                    $new_balance = MeelCoin::getBalance($conn, $user_id);
                    if (class_exists('Notification')) {
                        Notification::create(
                            $conn,
                            $user_id,
                            'meelcoin',
                            'Penggunaan MEeLCoin',
                            'Upload ' . $media_type . ' "' . htmlspecialchars(trim((string)($_POST['title'] ?? ''))) . '" — Biaya: ' . $coin_cost . ' MEeLCoin (Sisa: ' . $new_balance . ')'
                        );
                    }
                } catch (Throwable $e) {
                    error_log('[MEeL] Gagal membuat notifikasi MEeLCoin: ' . $e->getMessage());
                }
            }

            $result['status'] = 'success';
            if ($meelcoin_enabled && !$is_admin) {
                $summary = MeelCoin::getBalanceSummary($conn, $user_id);
                $result['extra']['coin_balance'] = $summary['total'];
                $result['extra']['coin_held'] = $summary['held'];
                $result['extra']['coin_available'] = $summary['available'];
            } else {
                $result['extra']['hour_count'] = get_hourly_upload_count($conn, $user_id, $media_type);
            }
            $result['extra']['total_uploads'] = get_total_upload_count($conn, $user_id, $media_type);
            try {
                MediaLibrary::clearCountsCache();
                log_activity($conn, $user_id, $log_action, $media_type, (int)($process_result['id'] ?? 0));
            } catch (Throwable $e) {
                error_log('[MEeL] Upload sukses tetapi logging gagal: ' . $e->getMessage());
            }
        } else {
            $result['alert_message'] = (string)($process_result['msg'] ?? 'Upload gagal.');
            if ($coin_active) {
                UploadAttempt::fail($conn, $attempt_id, 'upload_failed');
                $summary = MeelCoin::getBalanceSummary($conn, $user_id);
                $result['extra']['coin_balance'] = $summary['total'];
                $result['extra']['coin_held'] = $summary['held'];
                $result['extra']['coin_available'] = $summary['available'];
            }
        }
        return $result;
    }
}
