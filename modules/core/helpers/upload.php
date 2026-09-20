<?php

// Batas upload generik (bisa dioverride via settings.php).
if (!defined('MEEL_MAX_BOOK_FILE_BYTES')) {
    define('MEEL_MAX_BOOK_FILE_BYTES', 500 * 1024 * 1024); // 500MB
}
if (!defined('MEEL_MAX_THUMBNAIL_BYTES')) {
    define('MEEL_MAX_THUMBNAIL_BYTES', 25 * 1024 * 1024); // 25MB
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

if (!function_exists('meel_magic_extension_ok')) {
/**
 * Cocokkan magic bytes file upload dengan extension & jenis media yang
 * diizinkan. Jangan pernah hanya mempercayai $_FILES['type'] / extension.
 *
 * @return string kosong jika cocok; pesan error jika tidak.
 */
function meel_magic_extension_ok(string $path, string $ext, string $mediaKind = 'audio'): string
{
    if (!is_file($path) || filesize($path) < 4) {
        return 'File tidak valid atau terlalu kecil.';
    }
    $head = meel_read_magic_bytes($path, 16);
    $ext  = strtolower($ext);

    if ($mediaKind === 'video') {
        $ok = str_starts_with($head, "\x1A\x45\xDF\xA3")   // Matroska/WebM
            || (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp'); // MP4/MOV/M4A
        return $ok ? '' : 'File tidak valid sebagai video (magic bytes mismatch).';
    }

    if ($mediaKind === 'audio') {
        // Ogg/Opus, FLAC, WAV/RIFF, MP3 (ID3 atau frame sync), MP4/M4A (ftyp)
        if (str_starts_with($head, "OggS")) return '';
        if (str_starts_with($head, "fLaC")) return '';
        if (str_starts_with($head, "RIFF") && substr($head, 8, 4) === 'WAVE') return '';
        if (str_starts_with($head, "ID3")) return '';
        if (strlen($head) >= 2 && ((ord($head[0]) === 0xFF) && (ord($head[1]) & 0xE0) === 0xE0)) return '';
        if (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp') return '';
        return 'File tidak valid sebagai audio (magic bytes mismatch).';
    }

    if ($mediaKind === 'image') {
        $ok = str_starts_with($head, "\xFF\xD8\xFF")            // JPEG
            || str_starts_with($head, "\x89PNG\x0D\x0A\x1A\x0A") // PNG
            || (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP')
            || str_starts_with($head, 'GIF8');                     // GIF
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

    return ''; // generic kind: no magic check
}
}

if (!function_exists('meel_validate_video_codec')) {
/**
 * Validasi codec video menggunakan ffprobe. Hanya mengizinkan H.264 (AVC)
 * dan H.265 (HEVC) karena kedua codec tersebut kompatibel dengan
 * MPEG-2 Transport Stream (HLS versi lama).
 *
 * @param string $file_path  Path file video.
 * @param string $ffprobe_bin Path biner ffprobe.
 * @param string $env_prefix Prefix env (mis. 'export LD_LIBRARY_PATH=''; ').
 *
 * @return string kosong jika codec valid; pesan error jika tidak.
 */
function meel_validate_video_codec(
    string $file_path,
    string $ffprobe_bin = '/usr/bin/ffprobe',
    string $env_prefix = ''
): string {
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
    $ret    = -1;
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
/**
 * Validasi codec audio menggunakan ffprobe. Memeriksa apakah codec audio
 * kompatibel dengan MPEG-2 Transport Stream (HLS versi lama).
 *
 * Codec yang kompatibel (bisa di-copy langsung): AAC, MP3, AC3, E-AC3.
 * Codec yang tidak kompatibel (perlu transcode): Opus, Vorbis, DTS, FLAC, dll.
 *
 * Jika codec tidak kompatibel dan durasi audio > 5 menit, proses ditolak
 * karena transcode audio berdurasi panjang terlalu membebani server.
 *
 * @param string $file_path   Path file video.
 * @param string $ffprobe_bin Path biner ffprobe.
 * @param string $env_prefix  Prefix env (mis. 'export LD_LIBRARY_PATH=''; ').
 *
 * @return array ['error' => string, 'has_audio' => bool, 'needs_audio_transcode' => bool]
 *               error: pesan error jika harus ditolak; '' jika lolos.
 *               has_audio: true jika video memiliki audio stream.
 *               needs_audio_transcode: true jika audio harus di-transcode ke AAC.
 */
function meel_validate_audio_codec(
    string $file_path,
    string $ffprobe_bin = '/usr/bin/ffprobe',
    string $env_prefix = ''
): array {
    if (!is_file($file_path) || filesize($file_path) < 4) {
        return ['error' => 'File tidak valid atau terlalu kecil.', 'has_audio' => false, 'needs_audio_transcode' => false];
    }

    $allowed_codecs    = ['aac', 'mp3', 'ac3', 'eac3'];
    $max_audio_duration = 300; // 5 menit (detik)

    $cmd = $env_prefix . escapeshellarg($ffprobe_bin)
        . ' -v error -select_streams a:0'
        . ' -show_entries stream=codec_name,duration'
        . ' -of csv=p=0 '
        . escapeshellarg($file_path);

    $output = [];
    $ret    = -1;
    @exec($cmd, $output, $ret);

    // Tidak ada audio stream.
    if ($ret !== 0 || empty($output) || trim($output[0]) === '') {
        return ['error' => '', 'has_audio' => false, 'needs_audio_transcode' => false];
    }

    $parts    = array_map('trim', explode(',', $output[0]));
    $codec    = strtolower($parts[0] ?? '');
    $duration = (float)($parts[1] ?? 0);

    // Audio codec kompatibel → bisa di-copy langsung ke MPEG-TS.
    if (in_array($codec, $allowed_codecs, true)) {
        return ['error' => '', 'has_audio' => true, 'needs_audio_transcode' => false];
    }

    // Audio tidak kompatibel + durasi > 5 menit → tolak.
    if ($duration > $max_audio_duration) {
        $minutes = round($duration / 60, 1);
        return [
            'error' => "Audio menggunakan codec \"{$codec}\" yang tidak kompatibel dengan HLS. "
                     . "Durasi audio {$minutes} menit melebihi batas 5 menit untuk transcode otomatis.",
            'has_audio' => true,
            'needs_audio_transcode' => false,
        ];
    }

    // Audio tidak kompatibel + durasi <= 5 menit → akan di-transcode ke AAC.
    return ['error' => '', 'has_audio' => true, 'needs_audio_transcode' => true];
}
}

if (!function_exists('meel_sanitize_upload_filename')) {
/**
 * Sanitasi nama file upload → nama file fisik aman (hanya [a-z0-9._-],
 * tanpa separators path, tanpa null byte, tanpa residue traversal).
 * Nama asli user tetap bisa disimpan sebagai metadata bila perlu.
 */
function meel_sanitize_upload_filename(string $original, string $fallback = 'file'): string
{
    $original = str_replace("\0", '', $original);
    $name     = str_replace(["\\", '/'], '_', $original);
    $name     = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name) ?: $fallback;
    $name     = preg_replace('/\.{2,}/', '_', $name); // hilangkan '..' traversal
    $name     = trim($name, '._');
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
    $stmt  = $conn->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE user_id = ? AND upload_date > NOW() - INTERVAL 1 HOUR");
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
    $stmt  = $conn->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE user_id = ?");
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
/**
 * Sanitasi nama dasar (tanpa ekstensi) menjadi karakter aman untuk nama
 * file media. Versi terpusat dari logika yang dulu diduplikasi di
 * Uploader::getUniqueFilename(), EncodeService::encodeMusic(), dll.
 *
 * @return string nama bersih; '' jika hasil kosong (pemanggil boleh fallback).
 */
function meel_sanitize_clean_name(string $raw, int $max_len = 120): string
{
    // Semantik SAMA dengan kode inline asli (Uploader::getUniqueFilename &
    // EncodeService::encodeMusic): ganti karakter non-aman jadi '_' lalu
    // potong. Tanpa trim/collapse tambahan — menjaga nama file yang sudah
    // ada. Fallback saat hasil kosong dilakukan oleh pemanggil.
    $clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $raw);
    if ($clean === '') {
        return '';
    }
    return substr($clean, 0, $max_len);
}
}

if (!function_exists('meel_reserve_unique_filename')) {
/**
 * Alokasi nama file unik secara ATOMIK memakai fopen(..., 'x') (O_EXCL):
 * placeholder kosong dibuat lebih dulu, lalu pemanggil menimpanya dengan
 * move_uploaded_file / ffmpeg -y. Dua request bersamaan tidak mungkin
 * memilih nama yang sama (anti race condition).
 *
 * @return string|null nama file (tanpa direktori) yang berhasil di-reserve,
 *                     atau null bila semua percobaan gagal (folder penuh/
 *                     tidak writable).
 */
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
/**
 * Satu-satunya jalur konversi gambar/frame menjadi WebP via ffmpeg.
 * Menggantikan 8+ duplikasi inline ffmpeg -vf scale libwebp di Uploader,
 * EncodeService, DownloadService, MediaLibrary, dan admin editors.
 *
 * @param string $ffmpeg_bin  Path biner ffmpeg.
 * @param string $src         File sumber (gambar, audio, video).
 * @param string $dst         Path output .webp (placeholder boleh sudah ada,
 *                            ffmpeg -y akan menimpanya).
 * @param int    $max_width   Lebar maksimal (min(scale, iw)).
 * @param string $extra       Argumen tambahan sebelum -vf, mis. '-ss 00:00:05'
 *                            atau '-an -vframes 1' (boleh kosong).
 * @param string $env_prefix  Prefix env (mis. 'export LD_LIBRARY_PATH=''; ').
 * @param int    $threads     Nilai -threads ffmpeg (0 = tanpa flag, perilaku
 *                            default ffmpeg). Pemanggil yang dulu eksplisit
 *                            '-threads 1' bisa meneruskan 1.
 *
 * @return bool true bila file output terbentuk dan berisi data.
 */
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
    return is_file($dst) && filesize($dst) > 0;
}
}

if (!function_exists('meel_allocate_unique_dir')) {
/**
 * Alokasi nama folder unik (suffix -1, -2, ...) di dalam $parent.
 * Dipakai untuk folder kerja video (Uploader & DownloadService) — versi
 * terpusat dari loop while(is_dir()) yang diduplikasi.
 *
 * @return string nama folder TANPA slash trailing.
 */
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
/**
 * Satu-satunya jalur encoding audio → Opus/Ogg via ffmpeg
 * (dipakai Uploader::processMusic & EncodeService::encodeMusic).
 * Duplikasi command-construction inline dihapus dari kedua pemanggil;
 * opsi yang berbeda (threads, metadata) diteruskan sebagai parameter.
 *
 * @param string $ffmpeg_bin  Path biner ffmpeg.
 * @param string $input       File sumber audio.
 * @param string $output      Path output .ogg (placeholder boleh sudah ada,
 *                            ffmpeg -y akan menimpanya).
 * @param string $env_prefix  Prefix env (mis. 'export LD_LIBRARY_PATH=''; ').
 *                            Pemanggil yang mengandalkan putenv() cukup
 *                            meneruskan ''.
 * @param int    $threads     Nilai -threads ffmpeg (0 = tanpa flag, perilaku
 *                            default ffmpeg).
 * @param array  $metadata    Pasangan key=>value tag metadata (mis.
 *                            ['title' => ..., 'artist' => ...]).
 *
 * @return array [int exit_code, string log] — log berisi gabungan stdout+
 *               stderr ffmpeg untuk pesan error yang ramah.
 */
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
/**
 * Satu-satunya jalur INSERT baris musik (dipakai Uploader::processMusic &
 * EncodeService::encodeMusic). Kolom duration hanya disertakan bila != null
 * — jalur upload file langsung memang tidak menyimpan duration (perilaku
 * lama dijaga; kolom default NULL).
 *
 * @return array [bool ok, string error] — error = pesan mysqli bila gagal.
 */
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
    return [true, ''];
}
}

/* reference build: MEeL-C4H9NO2 [78a1c65c4d60c8d8] */

if (!function_exists('meel_handle_upload')) {
/**
 * Handle upload POST flow: CSRF, MeelCoin spend/try/refund, process, log.
 * Dipakai video/upload.php dan music/upload.php untuk menghilangkan duplikasi.
 *
 * @param string $media_type   'video' | 'music'
 * @param callable $process_fn fn($_POST, $_FILES, $extra) → ['status'=>'success'|'error', 'id'=>int?, 'msg'=>string]
 * @param string $log_action   nama action untuk log_activity()
 *
 * @return array ['status'=>'success'|'error'|'', 'alert_message'=>string, 'extra'=>mixed]
 *   extra: data tambahan (coin_balance, hour_count, total_uploads) yang perlu dikembalikan ke caller.
 */
function meel_handle_upload(string $media_type, callable $process_fn, string $log_action): array
{
    global $conn;

    $user_id  = $_SESSION['user_id'];
    $is_admin = (get_user_role($conn, $user_id) === 'admin');

    $result = ['status' => '', 'alert_message' => '', 'extra' => []];

    // CSRF check
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $result['alert_message'] = 'CSRF token tidak valid.';
        return $result;
    }

    $meelcoin_enabled = MeelCoin::isEnabled($conn);
    $coin_cost = 0;

    if ($meelcoin_enabled && !$is_admin) {
        $coin_cost   = MeelCoin::getCost($conn, 'upload');
        $coin_balance = MeelCoin::getBalance($conn, $user_id);
        if (!MeelCoin::canAfford($conn, $user_id, $coin_cost)) {
            $result['alert_message'] = "MEeLCoin tidak cukup! Dibutuhkan {$coin_cost} coin, saldo Anda: {$coin_balance}.";
            return $result;
        }
    }

    $coin_deducted = false;
    if ($meelcoin_enabled && !$is_admin) {
        [$spent_ok, $spent_err] = MeelCoin::spend($conn, $user_id, $coin_cost, 'upload');
        if (!$spent_ok) {
            $result['alert_message'] = $spent_err;
            return $result;
        }
        $coin_deducted = true;
        $new_balance = MeelCoin::getBalance($conn, $user_id);
        Notification::create($conn, $user_id, 'meelcoin',
            'Penggunaan MEeLCoin',
            'Upload ' . $media_type . ' "' . htmlspecialchars(trim($_POST['title'] ?? '')) . '" — Biaya: ' . $coin_cost . ' MEeLCoin (Sisa: ' . $new_balance . ')'
        );
    }

    // Process upload
    $process_result = $process_fn($_POST, $_FILES);

    if ($process_result['status'] === 'success') {
        $result['status'] = 'success';
        if ($meelcoin_enabled && !$is_admin) {
            $result['extra']['coin_balance'] = MeelCoin::getBalance($conn, $user_id);
        } else {
            $result['extra']['hour_count'] = get_hourly_upload_count($conn, $user_id, $media_type);
        }
        $result['extra']['total_uploads'] = get_total_upload_count($conn, $user_id, $media_type);
        MediaLibrary::clearCountsCache();
        log_activity($conn, $user_id, $log_action, $media_type, (int)($process_result['id'] ?? 0));
    } else {
        $result['alert_message'] = $process_result['msg'];
        if ($coin_deducted) {
            MeelCoin::refund($conn, $user_id, $coin_cost, 'upload_failed_refund');
        }
    }

    return $result;
}
}
