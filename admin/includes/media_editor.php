<?php

/**
 * @see docs/{id,en}/modules.md
 */

if (!function_exists('meel_editor_media_config')) {
    /**
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
/* reference build: ch2892sa2 [21321343nfrey8] */
if (!function_exists('meel_editor_context_redirect')) {
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

        $upload_mime = meel_image_mime_of($files['thumbnail']['tmp_name']);
        if (!in_array($upload_mime, meel_image_mime_allowlist(), true)) {
            return ['url' => $current, 'error' => 'File ' . $cfg['label'] . ' harus berupa gambar (' . meel_image_format_label() . ').'];
        }
        if (!meel_image_source_ok($files['thumbnail']['tmp_name'])) {
            return ['url' => $current, 'error' => 'File ' . $cfg['label'] . ' tidak terbaca sebagai gambar yang valid.'];
        }

        $target_dir = meel_media_base_path($cfg['type']) . '/thumbnail/';
        if (!is_dir($target_dir)) {
            @mkdir($target_dir, 0755, true);
        }
        if (!is_writable($target_dir)) {
            return ['url' => $current, 'error' => 'Direktori thumbnail tidak dapat ditulis.'];
        }

        $clean_title = getRomajiName($title);
        if (empty($clean_title)) {
            $clean_title = $cfg['default_clean'];
        }

        $new_name = meel_reserve_unique_filename($target_dir, $clean_title . $cfg['suffix'], 'webp', 200, '_');
        if ($new_name === null) {
            return ['url' => $current, 'error' => 'Gagal menyiapkan nama file unik.'];
        }
        $upload_path = $target_dir . $new_name;

        // Konversi ke WebP (ffmpeg-lite -> system ffmpeg -> ImageMagick).
        // Tidak ada fallback yang menyalin byte asli ke nama .webp.
        if (meel_convert_image_to_webp($files['thumbnail']['tmp_name'], $upload_path, 500, 500, true)) {
            return ['url' => $new_name, 'error' => ''];
        }

        if (is_file($upload_path)) {
            @unlink($upload_path);
        }
        return ['url' => $current, 'error' => $cfg['upload_error'] . ' Konversi gambar gagal.'];
    }
}
