<?php
/**
 * Central FFmpeg/FFprobe resolver and health helpers.
 *
 * Policy:
 * - bundled `tools/ffmpeg-lite` is the default;
 * - an explicit `MEEL_FFMPEG_PATH`/`MEEL_FFPROBE_PATH` always wins;
 * - system PATH fallback is allowed only when `MEEL_FFMPEG_ALLOW_SYSTEM` is true;
 * - a present-but-broken bundled binary never silently falls back to system.
 */

if (!function_exists('meel_media_project_root')) {
    function meel_media_project_root(): string
    {
        if (defined('MEEL_ROOT') && (string) constant('MEEL_ROOT') !== '') {
            return rtrim((string) constant('MEEL_ROOT'), '/');
        }
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('meel_media_bundled_path')) {
    function meel_media_bundled_path(string $name): string
    {
        return meel_media_project_root() . '/tools/ffmpeg-lite/' . $name;
    }
}

if (!function_exists('meel_media_system_allowed')) {
    function meel_media_system_allowed(): bool
    {
        if (!defined('MEEL_FFMPEG_ALLOW_SYSTEM')) {
            return false;
        }
        $value = constant('MEEL_FFMPEG_ALLOW_SYSTEM');
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}

if (!function_exists('meel_media_system_binary')) {
    function meel_media_system_binary(string $name): string
    {
        foreach (['/usr/bin/' . $name, '/usr/local/bin/' . $name] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $found = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        return $found !== '' ? $found : '';
    }
}

if (!function_exists('meel_media_binary_path')) {
    function meel_media_binary_path(string $name): string
    {
        static $cache = [];
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $const = 'MEEL_' . strtoupper($name) . '_PATH';
        $explicit = defined($const) ? trim((string) constant($const)) : '';
        if ($explicit !== '') {
            return $cache[$name] = $explicit;
        }

        $bundled = meel_media_bundled_path($name);
        // file_exists() is false for a broken symlink; is_link() still catches it.
        if (file_exists($bundled) || is_link($bundled)) {
            return $cache[$name] = $bundled;
        }

        if (meel_media_system_allowed()) {
            $system = meel_media_system_binary($name);
            if ($system !== '') {
                return $cache[$name] = $system;
            }
        }

        return $cache[$name] = $bundled;
    }
}

if (!function_exists('meel_ffmpeg_bin')) {
    function meel_ffmpeg_bin(): string
    {
        return meel_media_binary_path('ffmpeg');
    }
}

if (!function_exists('meel_ffprobe_bin')) {
    function meel_ffprobe_bin(): string
    {
        return meel_media_binary_path('ffprobe');
    }
}

if (!function_exists('meel_media_binary_source')) {
    function meel_media_binary_source(string $name, ?string $path = null): string
    {
        $path ??= meel_media_binary_path($name);
        $const = 'MEEL_' . strtoupper($name) . '_PATH';
        $explicit = defined($const) ? trim((string) constant($const)) : '';

        if ($explicit !== '' && $path === $explicit) {
            return 'explicit';
        }

        $bundled = meel_media_bundled_path($name);
        if ($path === $bundled) {
            return 'bundled';
        }
        $pathReal = realpath($path);
        $bundledReal = realpath($bundled);
        if ($pathReal !== false && $bundledReal !== false && $pathReal === $bundledReal) {
            return 'bundled';
        }

        return 'system';
    }
}

if (!function_exists('meel_media_binary_info')) {
    /**
     * @return array{name:string,path:string,source:string,exists:bool,executable:bool,runnable:bool,version:string,error:string}
     */
    function meel_media_binary_info(string $name): array
    {
        $path = meel_media_binary_path($name);
        $info = [
            'name' => $name,
            'path' => $path,
            'source' => meel_media_binary_source($name, $path),
            'exists' => $path !== '' && (is_file($path) || is_link($path)),
            'executable' => $path !== '' && is_file($path) && is_executable($path),
            'runnable' => false,
            'version' => '',
            'error' => '',
        ];

        if (!$info['exists']) {
            $info['error'] = 'binary tidak ditemukan';
            return $info;
        }
        if (!$info['executable']) {
            $info['error'] = 'binary tidak executable';
            return $info;
        }

        $output = [];
        $code = -1;
        @exec(escapeshellarg($path) . ' -version 2>&1', $output, $code);
        $text = trim(implode("\n", array_slice($output, 0, 6)));
        if ($code === 0 && preg_match('/^' . preg_quote($name, '/') . '\s+version\s+(\S+)/i', $text, $m)) {
            $info['runnable'] = true;
            $info['version'] = $m[1];
        } else {
            $info['error'] = $text !== '' ? $text : 'binary gagal dijalankan';
        }

        return $info;
    }
}

if (!function_exists('meel_media_binary_list')) {
    /**
     * Read one FFmpeg component list (`encoders`, `decoders`, ...).
     * Handles comma-separated names (e.g., "mov,mp4,m4a" → ["mov","mp4","m4a"]).
     *
     * @return string[]
     */
    function meel_media_binary_list(string $path, string $list): array
    {
        $allowed = ['encoders', 'decoders', 'muxers', 'demuxers', 'filters'];
        if (!in_array($list, $allowed, true)) {
            return [];
        }

        $output = @shell_exec(escapeshellarg($path) . ' -hide_banner -' . $list . ' 2>/dev/null');
        if (!is_string($output) || $output === '') {
            return [];
        }

        $names = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) < 2 || !preg_match('/^[A-Z.]{1,6}$/', $parts[0])) {
                continue;
            }
            // Split comma-separated names (e.g., "mov,mp4,m4a")
            foreach (explode(',', $parts[1]) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        return array_values(array_unique($names));
    }
}

if (!function_exists('meel_media_binary_capability_issues')) {
    /**
     * Components MEeL actually relies on.
     *
     * @return string[] e.g. ["encoders:libwebp", "filters:pad"]
     */
    function meel_media_binary_capability_issues(string $path): array
    {
        $required = [
            'encoders' => ['aac', 'libopus', 'libmp3lame', 'libwebp', 'mjpeg', 'png'],
            'decoders' => ['h264', 'hevc', 'aac', 'mp3', 'flac', 'vorbis', 'opus', 'libdav1d'],
            'demuxers' => ['mov', 'matroska', 'mpegts', 'hls', 'flac', 'wav', 'ogg', 'mp3', 'aac', 'concat', 'image2', 'ico'],
            'muxers' => ['hls', 'mpegts', 'ogg', 'opus', 'mp3', 'ipod', 'mp4', 'webp', 'image2', 'mjpeg', 'null'],
            'filters' => ['scale', 'pad', 'crop', 'fps', 'tile', 'format', 'aresample', 'anull', 'aformat', 'buffer', 'buffersink'],
        ];

        $issues = [];
        foreach ($required as $list => $wanted) {
            $available = meel_media_binary_list($path, $list);
            foreach ($wanted as $item) {
                if (!in_array($item, $available, true)) {
                    $issues[] = $list . ':' . $item;
                }
            }
        }
        return $issues;
    }
}

if (!function_exists('meel_media_binary_missing_libs')) {
    /**
     * Return lines from `ldd` that contain "not found" for the real binary.
     * Wrapper scripts are resolved to `<name>.bin`.
     *
     * @return string[]
     */
    function meel_media_binary_missing_libs(string $path): array
    {
        $real = $path;
        if (is_file($real . '.bin')) {
            $real .= '.bin';
        }
        if (!is_file($real)) {
            return [];
        }

        $libDir = dirname($real) . '/lib';
        $output = @shell_exec(
            'LD_LIBRARY_PATH=' . escapeshellarg($libDir)
            . ' ldd ' . escapeshellarg($real) . ' 2>&1'
        );
        if (!is_string($output) || $output === '') {
            return [];
        }

        $missing = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (stripos($line, 'not found') !== false) {
                $missing[] = trim($line);
            }
        }
        return $missing;
    }
}
