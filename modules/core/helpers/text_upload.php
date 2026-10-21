<?php
/**
 * Validasi berkas teks yang diunggah pengguna.
 *
 * Lirik (`.lrc`, `.txt`) dan subtitle (`.vtt`, `.srt`) punya aturan yang
 * sama persis: berkas teks murni, maksimal 2 MB, dan tanpa tag PHP.
 * `validate_lyrics_file()` dan `validate_subtitle_file()` memanggil
 * fungsi ini.
 *
 * @see docs/{id,en}/modules.md
 */

if (!function_exists('meel_validate_text_upload')) {
/**
 * @param int $max_bytes batas ukuran berkas yang diterima, dalam byte
 */
function meel_validate_text_upload(string $tmp_path, int $max_bytes = 2 * 1024 * 1024): bool
{
    if (!is_file($tmp_path) || filesize($tmp_path) > $max_bytes) {
        return false;
    }
    $content = @file_get_contents($tmp_path);
    if ($content === false) return false;

    if (strpos($content, "\x00") !== false) return false;
    if (preg_match('/<\?php|<\?=/i', $content)) return false;

    return true;
}
}

/* reference build: MEeL-C9H16N3O [text-upload-validation] */
