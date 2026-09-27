<?php
if (!function_exists('authorize_stream')) {
    
    function authorize_stream(int $id): void
    {
        if ($id <= 0) return;

        if (!isset($_SESSION['stream_ok']) || !is_array($_SESSION['stream_ok'])) {
            $_SESSION['stream_ok'] = [];
        }

        $_SESSION['stream_ok'][$id] = time();

        if (count($_SESSION['stream_ok']) > 100) {
            $oldest = array_search(min($_SESSION['stream_ok']), $_SESSION['stream_ok'], true);
            if ($oldest !== false) {
                unset($_SESSION['stream_ok'][$oldest]);
            }
        }
    }
}

if (!function_exists('is_stream_authorized')) {
    
    function is_stream_authorized(int $id, int $ttl = 7200): bool
    {
        if ($id <= 0) return false;
        if (!is_array($_SESSION['stream_ok'] ?? null)) return false;
        if (empty($_SESSION['stream_ok'][$id])) return false;

        return (time() - (int)$_SESSION['stream_ok'][$id]) <= $ttl;
    }
}

if (!function_exists('meel_register_stream_path')) {
    /**
     * Simpan mapping path file video → media id di sesi (dipanggil halaman
     * watch), agar endpoint stream dapat memverifikasi token tanpa query DB
     * per segmen.
     */
    function meel_register_stream_path(string $filename, int $id): void
    {
        if ($id <= 0 || $filename === '') return;

        if (!isset($_SESSION['stream_paths']) || !is_array($_SESSION['stream_paths'])) {
            $_SESSION['stream_paths'] = [];
        }

        $file = str_replace('\\', '/', $filename);
        $_SESSION['stream_paths'][$file] = $id;
        $dir = dirname($file);
        if ($dir !== '' && $dir !== '.') {
            $_SESSION['stream_paths'][$dir] = $id;
        }

        while (count($_SESSION['stream_paths']) > 100) {
            array_shift($_SESSION['stream_paths']);
        }
    }
}

if (!function_exists('meel_stream_path_allowed')) {
    /**
     * true bila path media boleh diakses sesi ini.
     * - Di luar pohon video/ (thumbnail, lyrics, dsb.) → publik (aset display).
     * - Di pohon video/ → wajib mapping stream_paths + token authorize_stream.
     */
    function meel_stream_path_allowed(string $relPath): bool
    {
        $rel = ltrim(str_replace('\\', '/', $relPath), '/');
        if (!str_starts_with($rel, 'video/') && !str_contains($rel, '/video/')) {
            return true;
        }

        $map = is_array($_SESSION['stream_paths'] ?? null) ? $_SESSION['stream_paths'] : [];
        $key = $map[$rel] ?? null;
        if ($key === null) {
            $dir = $rel;
            while (($pos = strrpos($dir, '/')) !== false) {
                $dir = substr($dir, 0, $pos);
                if ($dir === '' || $dir === 'video') {
                    break;
                }
                if (isset($map[$dir])) {
                    $key = $map[$dir];
                    break;
                }
            }
        }

        return $key !== null && is_stream_authorized((int) $key);
    }
}

/* reference build: MEeL-C5H9NO2 [545d3572e9939d1f] */
