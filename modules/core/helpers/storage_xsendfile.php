<?php
if (!function_exists('meel_xsendfile_enabled')) {
    function meel_xsendfile_enabled(): bool
    {
        if (!defined('MEEL_USE_XSENDFILE') || MEEL_USE_XSENDFILE !== true) {
            return false;
        }
        if (!function_exists('apache_get_modules')) {
            return false;
        }
        return in_array('mod_xsendfile', apache_get_modules(), true);
    }
}
if (!function_exists('meel_xsendfile_config_files')) {
    function meel_xsendfile_config_files(): array
    {
        static $files = null;
        if ($files !== null) {
            return $files;
        }
        $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $candidates = [];
        if ($docRoot !== '') {
            $candidates[] = dirname($docRoot) . '/etc/httpd.conf';
        }
        $candidates[] = '/opt/lampp/etc/httpd.conf';
        $candidates[] = '/etc/apache2/apache2.conf';
        $candidates[] = '/etc/httpd/conf/httpd.conf';
        $main = '';
        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                $main = $candidate;
                break;
            }
        }
        if ($main === '') {
            $files = [];
            return $files;
        }
        $found = [];
        $queue = [[$main, 0]];
        while ($queue !== []) {
            [$file, $depth] = array_shift($queue);
            $real = realpath($file);
            if ($real === false || isset($found[$real]) || !is_readable($real) || count($found) > 60) {
                continue;
            }
            $found[$real] = true;
            if ($depth >= 4) {
                continue;
            }
            $content = @file_get_contents($real);
            if ($content === false) {
                continue;
            }
            $dir = dirname($real);
            foreach (preg_split('/\R/', $content) ?: [] as $line) {
                if (!preg_match('/^\s*Include(?:Optional)?\s+(.+?)\s*$/i', $line, $m)) {
                    continue;
                }
                $target = trim($m[1], " \t\"'");
                if ($target === '' || $target[0] === '#') {
                    continue;
                }
                if ($target[0] === '/') {
                    $resolved = [$target];
                } else {
                    $resolved = [$dir . '/' . $target, dirname($dir) . '/' . $target];
                }
                foreach ($resolved as $candidate) {
                    $matches = (strpbrk($target, '*?[') !== false) ? (glob($candidate) ?: []) : [$candidate];
                    foreach ($matches as $match) {
                        $queue[] = [$match, $depth + 1];
                    }
                }
            }
        }
        $files = array_keys($found);
        return $files;
    }
}
if (!function_exists('meel_xsendfile_flag_from_text')) {
    function meel_xsendfile_flag_from_text(string $content): ?bool
    {
        $flag = null;
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (!preg_match('/^\s*XSendFile\s+(on|off)\b/i', $line, $m)) {
                continue;
            }
            $flag = strtolower($m[1]) === 'on';
        }
        return $flag;
    }
}
if (!function_exists('meel_xsendfile_config_data')) {
    function meel_xsendfile_config_data(): array
    {
        static $data = null;
        if ($data !== null) {
            return $data;
        }
        $confFiles = meel_xsendfile_config_files();
        $sig = '';
        foreach ($confFiles as $confFile) {
            $sig .= $confFile . ':' . (int) @filemtime($confFile) . ';';
        }
        $sig = md5($sig);
        $cacheFile = dirname(__DIR__, 3) . '/temp/xsendfile_roots.cache';
        if (is_readable($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (
                is_array($cached)
                && ($cached['sig'] ?? '') === $sig
                && array_key_exists('flag', $cached)
                && (int) ($cached['time'] ?? 0) > time() - 3600
            ) {
                $data = [
                    'roots' => array_values((array) ($cached['roots'] ?? [])),
                    'flag' => (bool) $cached['flag'],
                ];
                return $data;
            }
        }
        $parsed = [];
        $flag = false;
        foreach ($confFiles as $confFile) {
            $content = @file_get_contents($confFile);
            if ($content === false) {
                continue;
            }
            $fileFlag = meel_xsendfile_flag_from_text($content);
            if ($fileFlag !== null) {
                $flag = $fileFlag;
            }
            foreach (preg_split('/\R/', $content) ?: [] as $line) {
                if (!preg_match('/^\s*XSendFilePath\s+(.+?)\s*$/i', $line, $m)) {
                    continue;
                }
                $path = trim($m[1], " \t\"'");
                if ($path === '' || $path[0] === '#') {
                    continue;
                }
                $parsed[] = rtrim(realpath($path) ?: $path, '/');
            }
        }
        $roots = array_values(array_unique(array_filter($parsed)));
        meel_write_cache_file($cacheFile, json_encode([
            'sig' => $sig,
            'time' => time(),
            'roots' => $roots,
            'flag' => $flag,
        ]));
        $data = ['roots' => $roots, 'flag' => $flag];
        return $data;
    }
}
if (!function_exists('meel_xsendfile_roots')) {
    function meel_xsendfile_roots(): array
    {
        return meel_xsendfile_config_data()['roots'];
    }
}
if (!function_exists('meel_xsendfile_server_flag')) {
    function meel_xsendfile_server_flag(): bool
    {
        return meel_xsendfile_config_data()['flag'];
    }
}
if (!function_exists('meel_xsendfile_htaccess_dirs')) {
    function meel_xsendfile_htaccess_dirs(): array
    {
        $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($docRoot === '' || $docRoot === '/' || !is_dir($docRoot)) {
            return [];
        }
        $paths = [];
        $uri = (string) ($_SERVER['REDIRECT_URL'] ?? '');
        if ($uri === '') {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        }
        if ($uri !== '') {
            $path = parse_url($uri, PHP_URL_PATH);
            if (is_string($path)) {
                $paths[] = $path;
            }
        }
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script !== '') {
            $paths[] = $script;
        }
        $dirs = [$docRoot];
        foreach ($paths as $path) {
            $decoded = rawurldecode($path);
            if (str_contains($decoded, "\0")) {
                continue;
            }
            $current = $docRoot;
            foreach (explode('/', str_replace('\\', '/', $decoded)) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    break;
                }
                $next = $current . '/' . $segment;
                if (!is_dir($next)) {
                    break;
                }
                $current = $next;
                $dirs[] = $current;
            }
        }
        $dirs = array_values(array_unique($dirs));
        usort($dirs, static fn(string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));
        return $dirs;
    }
}
if (!function_exists('meel_xsendfile_merge_flag')) {
    function meel_xsendfile_merge_flag(bool $base, array $contents): bool
    {
        $flag = $base;
        foreach ($contents as $content) {
            if (!is_string($content)) {
                continue;
            }
            $value = meel_xsendfile_flag_from_text($content);
            if ($value !== null) {
                $flag = $value;
            }
        }
        return $flag;
    }
}
if (!function_exists('meel_xsendfile_effective_flag')) {
    function meel_xsendfile_effective_flag(): bool
    {
        static $flag = null;
        if ($flag !== null) {
            return $flag;
        }
        $contents = [];
        foreach (meel_xsendfile_htaccess_dirs() as $dir) {
            $file = $dir . '/.htaccess';
            if (!is_readable($file)) {
                $contents[] = null;
                continue;
            }
            $content = @file_get_contents($file);
            $contents[] = ($content === false) ? null : $content;
        }
        $flag = meel_xsendfile_merge_flag(meel_xsendfile_server_flag(), $contents);
        return $flag;
    }
}
if (!function_exists('meel_xsendfile_ready')) {
    function meel_xsendfile_ready(string $realPath): bool
    {
        if (!meel_xsendfile_enabled()) {
            return false;
        }
        if (!meel_xsendfile_effective_flag()) {
            return false;
        }
        $real = realpath($realPath);
        if ($real === false) {
            return false;
        }
        foreach (meel_xsendfile_roots() as $root) {
            if ($root !== '' && str_starts_with($real, $root . '/')) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('meel_xsendfile_header')) {
    function meel_xsendfile_header(string $realPath): string
    {
        return str_replace('%', '%25', $realPath);
    }
}

/* reference build: MEeL-C8H4N3 [storage-split] */
