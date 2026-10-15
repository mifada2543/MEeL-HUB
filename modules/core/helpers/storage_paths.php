<?php
if (!function_exists('meel_media_base_path')) {
    function meel_media_base_path(string $module): string
    {
        $const = [
            'video' => 'MEEL_HDD_VIDEO_UPLOAD',
            'music' => 'MEEL_HDD_MUSIC_UPLOAD',
            'books' => 'MEEL_HDD_BOOKS_UPLOAD',
        ][$module] ?? null;
        if ($const !== null && defined($const)) {
            $v = (string) constant($const);
            if ($v !== '') {
                return rtrim($v, '/\\');
            }
        }
        return dirname(__DIR__, 3) . '/' . $module . '/upload';
    }
}
if (!function_exists('video_thumbnail_sm_name')) {
    function video_thumbnail_sm_name(?string $thumbnail): string
    {
        $thumbnail = basename(trim((string)$thumbnail));
        if ($thumbnail === '') {
            return '';
        }
        $stem = pathinfo($thumbnail, PATHINFO_FILENAME);
        if ($stem === '') {
            return $thumbnail;
        }
        return $stem . '_sm.webp';
    }
}
if (!function_exists('video_thumbnail_sm')) {
    function video_thumbnail_sm(?string $thumbnail): string
    {
        $thumbnail = basename(trim((string)$thumbnail));
        if ($thumbnail === '') {
            return '';
        }
        $sm = video_thumbnail_sm_name($thumbnail);
        return $sm !== '' && is_file(meel_media_base_path('video') . '/thumbnail/' . $sm)
            ? $sm
            : $thumbnail;
    }
}
if (!function_exists('meel_is_default_thumbnail')) {
    function meel_is_default_thumbnail(string $name): bool
    {
        $name = strtolower(basename(trim($name)));
        return $name === 'default_thumb.jpg' || $name === 'default_thumb.webp';
    }
}
if (!function_exists('music_thumbnail_url')) {
    function music_thumbnail_url(?string $thumbnail): string
    {
        $thumbnail = trim((string)$thumbnail);
        $thumb_dir = meel_media_base_path('music') . '/thumbnail/';
        $fallback = '../assets/img/music0.webp';
        static $default_thumb = null;
        if ($thumbnail === '') {
            if ($default_thumb === null) {
                $default_thumb = is_file($thumb_dir . 'default.thumb.webp') ? 'upload/thumbnail/default.thumb.webp'
                    : (is_file($thumb_dir . 'default.webp') ? 'upload/thumbnail/default.webp'
                        : (is_file($thumb_dir . 'default.png') ? 'upload/thumbnail/default.png' : $fallback));
            }
            return $default_thumb;
        }
        $thumbnail = basename($thumbnail);
        if (str_ends_with($thumbnail, '.thumb.webp') && is_file($thumb_dir . $thumbnail)) {
            return 'upload/thumbnail/' . rawurlencode($thumbnail);
        }
        $base = preg_replace('/\\.thumb$/', '', pathinfo($thumbnail, PATHINFO_FILENAME)) ?: pathinfo($thumbnail, PATHINFO_FILENAME);
        $candidates = [
            $base . '.thumb.webp',
            $base . '.webp',
            $thumbnail
        ];
        foreach ($candidates as $candidate) {
            if (is_file($thumb_dir . $candidate)) {
                return 'upload/thumbnail/' . rawurlencode($candidate);
            }
        }
        if ($default_thumb === null) {
            $default_thumb = is_file($thumb_dir . 'default.thumb.webp') ? 'upload/thumbnail/default.thumb.webp'
                : (is_file($thumb_dir . 'default.webp') ? 'upload/thumbnail/default.webp'
                    : (is_file($thumb_dir . 'default.png') ? 'upload/thumbnail/default.png' : $fallback));
        }
        return $default_thumb;
    }
}
if (!function_exists('meel_storage_base_path')) {
    function meel_storage_base_path(): string
    {
        if (!defined('MEEL_HDD_BASE')) {
            return '';
        }
        return rtrim((string) MEEL_HDD_BASE, '/\\');
    }
}
if (!function_exists('meel_storage_marker_path')) {
    function meel_storage_marker_path(): string
    {
        $base = meel_storage_base_path();
        return $base === '' ? '' : $base . '/.meel_mount';
    }
}
if (!function_exists('meel_storage_ready')) {
    function meel_storage_ready(): bool
    {
        $base = meel_storage_base_path();
        if ($base === '') {
            return true;
        }
        if (!is_dir($base)) {
            return false;
        }
        if (is_file($base . '/.meel_mount')) {
            return true;
        }
        $entries = @scandir($base);
        if (!is_array($entries) || count(array_diff($entries, ['.', '..'])) === 0) {
            error_log('[MEeL] Folder storage kosong tanpa penanda .meel_mount, diasumsikan belum ter-mount: ' . $base);
            return false;
        }

        error_log('[MEeL] Penanda .meel_mount belum ada di ' . $base . ' — jalankan: touch "' . $base . '/.meel_mount"');
        return true;
    }
}
if (!function_exists('meel_storage_path_in_volume')) {
    function meel_storage_path_in_volume(string $path): bool
    {
        $base = meel_storage_base_path();
        if ($base === '') {
            return false;
        }
        $cleanPath = rtrim(str_replace('\\', '/', $path), '/');
        $cleanBase = str_replace('\\', '/', $base);
        return $cleanPath === $cleanBase || str_starts_with($cleanPath, $cleanBase . '/');
    }
}
if (!function_exists('meel_pid_dir_candidates')) {
    function meel_pid_dir_candidates(): array
    {
        return [
            '/tmp/meel_pids',
            dirname(__DIR__, 3) . '/temp/meel_pids',
        ];
    }
}
if (!function_exists('meel_pid_dir')) {
    function meel_pid_dir(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }
        foreach (meel_pid_dir_candidates() as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
                @chmod($dir, 01777);
            }
            if (is_writable($dir)) {
                $resolved = $dir;
                return $dir;
            }
            @chmod($dir, 01777);
            if (is_writable($dir)) {
                $resolved = $dir;
                return $dir;
            }
        }
        $resolved = meel_pid_dir_candidates()[1];
        return $resolved;
    }
}
if (!function_exists('meel_pid_filename')) {
    function meel_pid_filename(string $task_type, int $queue_id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $task_type)) {
            return '';
        }
        return $task_type . '_' . $queue_id . '.pid';
    }
}
if (!function_exists('meel_storage_guard')) {
    function meel_storage_guard(string $mode = 'html'): void
    {
        if (meel_storage_ready()) {
            return;
        }
        $message = 'Penyimpanan media belum ter-mount. Silakan coba lagi nanti.';
        if (PHP_SAPI === 'cli') {
            throw new RuntimeException('Penyimpanan media belum ter-mount: ' . meel_storage_base_path());
        }
        if (!headers_sent()) {
            header('Cache-Control: no-store');
        }
        if ($mode === 'html') {
            if (!headers_sent()) {
                require_once __DIR__ . '/../base_url.php';
                header(
                    'Location: ' . meel_base_url_path()
                        . '/err/?code=storage&back=beranda&reason=' . rawurlencode($message),
                    true,
                    303
                );
            }
            exit;
        }
        http_response_code(503);
        if ($mode === 'json') {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['status' => 'error', 'code' => 'storage_unmounted', 'message' => $message]);
            exit;
        }
        exit;
    }
}

if (PHP_SAPI !== 'cli' && !defined('MEEL_HDD_CHECKED')) {
    define('MEEL_HDD_CHECKED', true);
    if (!meel_storage_ready()) {
        error_log('[MEeL] Peringatan: MEEL_HDD_BASE tidak dapat diakses: ' . (meel_storage_base_path() ?: '(tidak didefinisikan)'));
    }
}
if (!function_exists('meel_drive_base_path')) {
    function meel_drive_base_path(?string $hddDriveOverride = null): string
    {
        $hddDrive = $hddDriveOverride ?? (defined('MEEL_HDD_DRIVE') ? (string) MEEL_HDD_DRIVE : '');
        if ($hddDrive !== '') {
            return rtrim($hddDrive, '/\\');
        }
        return dirname(__DIR__, 3) . '/data_drive';
    }
}

/* reference build: MEeL-C6H5N3 [storage-split] */