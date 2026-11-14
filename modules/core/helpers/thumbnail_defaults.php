<?php
/**
 * Fallback thumbnail yang benar-benar ada di storage.
 * @see docs/{id,en}/modules.md
 */
if (!function_exists('meel_default_thumbnail_name')) {
    function meel_default_thumbnail_name(): string
    {
        return 'default_thumb.webp';
    }
}
if (!function_exists('meel_default_thumbnail_asset')) {
    function meel_default_thumbnail_asset(): string
    {
        return meel_media_project_root() . '/assets/img/video0.webp';
    }
}
if (!function_exists('meel_default_thumbnail_path')) {
    function meel_default_thumbnail_path(string $module = 'video'): string
    {
        return meel_media_base_path($module) . '/thumbnail/' . meel_default_thumbnail_name();
    }
}
if (!function_exists('meel_ensure_default_thumbnail')) {
    /**
     * @return bool true bila file default tersedia setelah pemanggilan.
     */
    function meel_ensure_default_thumbnail(string $module = 'video'): bool
    {
        $path = meel_default_thumbnail_path($module);
        if (meel_is_valid_webp($path)) {
            return true;
        }
        $asset = meel_default_thumbnail_asset();
        if (!is_file($asset) || !meel_is_valid_webp($asset)) {
            error_log('[MEeL] Fallback thumbnail tidak bisa dibuat: sumber ' . $asset . ' tidak valid.');
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log('[MEeL] Fallback thumbnail tidak bisa dibuat: direktori ' . $dir . ' tidak writable.');
            return false;
        }
        $tmp = $path . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!@copy($asset, $tmp)) {
            @unlink($tmp);
            error_log('[MEeL] Fallback thumbnail tidak bisa disalin ke ' . $dir . '.');
            return false;
        }
        if (!meel_is_valid_webp($tmp)) {
            @unlink($tmp);
            error_log('[MEeL] Fallback thumbnail hasil salinan bukan WebP valid.');
            return false;
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            error_log('[MEeL] Fallback thumbnail gagal dipasang: ' . $path);
            return false;
        }
        return true;
    }
}
if (!function_exists('meel_resolve_fallback_thumbnail')) {
    function meel_resolve_fallback_thumbnail(string $module = 'video'): string
    {
        if (!meel_ensure_default_thumbnail($module)) {
            return '';
        }
        $name = meel_default_thumbnail_name();
        return meel_is_valid_webp(meel_default_thumbnail_path($module)) ? $name : '';
    }
}
/* reference build: MEeL-C10H16N [7c1e4a9d2b5f] */
