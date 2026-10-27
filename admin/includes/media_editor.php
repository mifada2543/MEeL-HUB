<?php
/**
 * Logika bersama untuk editor admin musik & video: konfigurasi per jenis
 * media, pemeriksaan konteks admin/profil, dan blok unggah thumbnail.
 *
 * Penulisan ke database dan pembersihan thumbnail lama tetap di halaman
 * masing-masing karena keduanya berbeda antar jenis media.
 *
 * @see docs/{id,en}/modules.md
 */

if (!function_exists('meel_editor_media_config')) {
/**
 * Konfigurasi per jenis media untuk editor admin.
 *
 * @return array{
 *     type: string, label: string, default_clean: string, suffix: string,
 *     upload_error: string, title_empty: string
 * }
 */
function meel_editor_media_config(string $media_type): array
{
    $configs = [
        'music' => [
            'type' => 'music',
            'label' => 'cover',
            'default_clean' => 'music-cover',
            'suffix' => '_cover',
            'upload_error' => 'Gagal mengupload cover thumbnail.',
            'title_empty' => 'Judul lagu tidak boleh kosong.',
        ],
        'video' => [
            'type' => 'video',
            'label' => 'thumbnail',
            'default_clean' => 'video-thumb',
            'suffix' => '_thumb',
            'upload_error' => 'Gagal mengupload thumbnail ke server.',
            'title_empty' => 'Judul video tidak boleh kosong.',
        ],
    ];

    return $configs[$media_type] ?? $configs['video'];
}
}

if (!function_exists('meel_editor_context_redirect')) {
/**
 * Alihkan ke versi halaman yang sesuai dengan konteks pemanggil.
 *
 * Halaman admin hanya boleh dibuka admin; halaman profil hanya boleh dibuka
 * pemilik (kecuali admin, yang memang dialihkan ke versi admin).
 */
function meel_editor_context_redirect(string $media_type, int $edit_id, bool $is_admin, string $context): void
{
    $slug = $media_type === 'music' ? 'edit-music' : 'edit-video';

    if ($context === 'admin') {
        if (!$is_admin) {
            header('Location: ' . base_url('/profile/' . $slug . '?id=' . $edit_id));
            exit;
        }
        return;
    }

    if ($is_admin) {
        header('Location: ' . base_url('/admin/' . $slug . '?id=' . $edit_id));
        exit;
    }
}
}

if (!function_exists('meel_editor_upload_thumbnail')) {
/**
 * Unggah thumbnail untuk editor admin.
 *
 * Mengembalikan nama berkas thumbnail yang berlaku (lama kalau unggahan
 * gagal) beserta pesan error kalau ada, supaya halaman bisa menyimpan
 * judul dan deskripsi tanpa ikut replaces thumbnail yang gagal diunggah.
 * Kalau `$error` sudah terisi sebelum blok ini dipanggil, pemeriksaan MIME
 * dan konversi dilewati.
 *
 * @return array{url:string, error:string}
 */
function meel_editor_upload_thumbnail(array $files, array $cfg, string $title, string $current, string $error = ''): array
{
    if (!isset($files['thumbnail']) || ($files['thumbnail']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['url' => $current, 'error' => $error];
    }

    $max_size = 5 * 1024 * 1024;
    if ((int) ($files['thumbnail']['size'] ?? 0) > $max_size) {
        return ['url' => $current, 'error' => 'Ukuran file ' . $cfg['label'] . ' maksimal 5MB.'];
    }

    if ($error !== '') {
        return ['url' => $current, 'error' => $error];
    }

    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $upload_mime = $finfo ? finfo_file($finfo, $files['thumbnail']['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    if (!in_array($upload_mime, $allowed_mime, true)) {
        return ['url' => $current, 'error' => 'File ' . $cfg['label'] . ' harus berupa gambar (JPEG, PNG, WebP, GIF, atau AVIF).'];
    }

    $target_dir = meel_media_base_path($cfg['type']) . '/thumbnail/';
    if (!is_dir($target_dir)) {
        @mkdir($target_dir, 0755, true);
    }

    $clean_title = getRomajiName($title);
    if (empty($clean_title)) {
        $clean_title = $cfg['default_clean'];
    }

    $new_name = meel_reserve_unique_filename($target_dir, $clean_title . $cfg['suffix'], 'webp', 200, '_');
    $upload_path = $new_name !== null ? $target_dir . $new_name : null;
    $ffmpeg_bin = defined('MEEL_FFMPEG_PATH') && MEEL_FFMPEG_PATH !== ''
        ? MEEL_FFMPEG_PATH
        : resolve_binary([__DIR__ . '/../../tools/ffmpeg-lite/ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);

    $cmd = escapeshellarg($ffmpeg_bin) . " -y -i " . escapeshellarg($files['thumbnail']['tmp_name'])
        . " -vf \"scale=500:500:force_original_aspect_ratio=decrease,pad=500:500:(ow-iw)/2:(oh-ih)/2\" -c:v libwebp -q:v 78 "
        . escapeshellarg($upload_path) . " 2>&1";
    exec($cmd, $out, $ret);
    if ($ret === 0 && file_exists($upload_path) && filesize($upload_path) > 0) {
        return ['url' => $new_name, 'error' => ''];
    }

    if (move_uploaded_file($files['thumbnail']['tmp_name'], $upload_path)) {
        return ['url' => $new_name, 'error' => ''];
    }

    @unlink($upload_path);

    return ['url' => $current, 'error' => $cfg['upload_error']];
}
}
