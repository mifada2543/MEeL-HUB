<?php
/**
 * URL thumbnail & gambar profil.
 *
 * Versi `?v=<mtime>` membuat cache-busting instan: saat file diganti,
 * URL berubah sehingga browser/service worker tidak menyajikan gambar lama
 * walau Cache-Control 7 hari masih berlaku.
 */
if (!function_exists('meel_media_file_version')) {
    function meel_media_file_version(string $path): string
    {
        static $cache = [];
        if (array_key_exists($path, $cache)) {
            return $cache[$path];
        }
        $mtime = @filemtime($path);
        return $cache[$path] = $mtime ? '?v=' . (int)$mtime : '';
    }
}
if (!function_exists('video_thumbnail_url')) {
    function video_thumbnail_url(
        ?string $thumbnail,
        string $urlPrefix = 'upload/thumbnail/',
        bool $small = true
    ): string {
        $thumbnail = basename(trim((string)$thumbnail));
        if ($thumbnail === '') {
            return '';
        }
        $dir = meel_media_base_path('video') . '/thumbnail/';
        $name = $small ? video_thumbnail_sm_name($thumbnail) : $thumbnail;
        $version = meel_media_file_version($dir . $name);
        if ($version === '' && $name !== $thumbnail) {
            $name = $thumbnail;
            $version = meel_media_file_version($dir . $name);
        }
        if ($version === '') {
            return '';
        }
        return rtrim($urlPrefix, '/') . '/' . rawurlencode($name) . $version;
    }
}
if (!function_exists('book_thumbnail_url')) {
    function book_thumbnail_url(
        ?string $thumbnail,
        string $urlPrefix = 'upload/thumbnail/'
    ): string {
        $thumbnail = basename(trim((string)$thumbnail));
        if ($thumbnail === '') {
            return '';
        }
        $path = meel_media_base_path('books') . '/thumbnail/' . $thumbnail;
        return rtrim($urlPrefix, '/') . '/' . rawurlencode($thumbnail) . meel_media_file_version($path);
    }
}
if (!function_exists('meel_profile_image_url')) {
    function meel_profile_image_url(
        ?string $name,
        string $urlPrefix = 'profile/upload/'
    ): string {
        $name = basename(trim((string)$name));
        $dir = dirname(__DIR__, 3) . '/profile/upload/';
        $version = $name !== '' ? meel_media_file_version($dir . $name) : '';
        if ($version === '') {
            $name = 'default_avatar.png';
            $version = meel_media_file_version($dir . $name);
        }
        return rtrim($urlPrefix, '/') . '/' . rawurlencode($name) . $version;
    }
}
