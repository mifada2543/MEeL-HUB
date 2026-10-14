<?php
if (!function_exists('check_disk_space')) {
    function check_disk_space(int $required_bytes, string $path): array
    {
        if (!is_dir($path)) {
            $path = dirname($path);
            $parent = dirname($path);
            while ($parent !== '/' && $parent !== '.' && !is_dir($parent)) {
                $parent = dirname($parent);
            }
            $path = $parent;
        }
        $free_bytes = disk_free_space($path);
        if ($free_bytes === false) {
            return [
                'ok' => false,
                'free' => 0,
                'required' => $required_bytes,
                'path' => $path,
                'error' => 'Tidak dapat membaca kapasitas disk.',
            ];
        }
        return [
            'ok' => ($free_bytes >= $required_bytes),
            'free' => $free_bytes,
            'required' => $required_bytes,
            'path' => $path,
            'error' => null,
        ];
    }
}
if (!function_exists('require_disk_space')) {
    function require_disk_space(int $required_bytes, string $path, string $label): void
    {
        $result = check_disk_space($required_bytes, $path);
        if ($result['ok']) return;

        $free_gb = sprintf('%.1f', $result['free'] / (1024 ** 3));
        $need_gb = sprintf('%.1f', $result['required'] / (1024 ** 3));
        $error_ms = $result['error'] ?? "Hanya tersedia {$free_gb} GB, butuh minimal {$need_gb} GB";

        throw new \RuntimeException("Ruang {$label} tidak mencukupi! {$error_ms}");
    }
}
if (!function_exists('dir_size')) {
    function dir_size(string $path, int $cache_ttl = 300): float
    {
        $cache_key = 'dirsize_' . md5($path);
        $cache_file = dirname(__DIR__, 3) . '/temp/' . $cache_key . '.cache';
        $cached = meel_read_cache_file($cache_file, $cache_ttl, ['size', 'time'], 'time');
        if ($cached !== null) {
            return (float) $cached['size'];
        }
        if (!is_dir($path)) return 0.0;
        $output = shell_exec("du -sb " . escapeshellarg($path) . " 2>/dev/null");
        if ($output && preg_match('/^(\d+)/', $output, $m)) {
            $size = (float)$m[1];
            meel_write_cache_file($cache_file, json_encode(['size' => $size, 'time' => time()]));
            return $size;
        }
        $size = 0.0;
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
            meel_write_cache_file($cache_file, json_encode(['size' => $size, 'time' => time()]));
        } catch (RuntimeException $e) {
            return 0.0;
        }
        return $size;
    }
}
if (!function_exists('invalidate_dir_size_cache')) {
    function invalidate_dir_size_cache(string $username): void
    {
        $userPath = meel_drive_base_path() . '/private_admins/' . $username;
        $cacheFile = dirname(__DIR__, 3) . '/temp/dirsize_' . md5($userPath) . '.cache';
        if (is_file($cacheFile)) {
            if (!is_writable(dirname($cacheFile))) {
                error_log("[MEeL] invalidate_dir_size_cache: direktori tidak writable: " . dirname($cacheFile));
            } elseif (!unlink($cacheFile)) {
                error_log("[MEeL] invalidate_dir_size_cache: gagal menghapus cache: {$cacheFile}");
            }
        }
    }
}
if (!function_exists('meel_sweep_stale_cache_tmp')) {
    function meel_sweep_stale_cache_tmp(string $path, int $max_age = 300): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $now = time();
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if (!preg_match('/\.tmp\.\d+\.[0-9a-f]{12}$/', $entry)) {
                continue;
            }
            $tmp = $dir . '/' . $entry;
            $mtime = @filemtime($tmp);
            if ($mtime !== false && ($now - $mtime) < $max_age) {
                continue;
            }
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
if (!function_exists('meel_write_cache_file')) {
    function meel_write_cache_file(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log("[MEeL] storage.php: cache file tidak bisa ditulis: {$path}");
            return;
        }
        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(6));
        $bytes = @file_put_contents($tmp, $content);
        if ($bytes === false || !@rename($tmp, $path)) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            error_log("[MEeL] storage.php: cache file gagal ditulis: {$path}");
            return;
        }
        meel_sweep_stale_cache_tmp($path);
    }
}
if (!function_exists('meel_read_cache_file')) {
    function meel_read_cache_file(string $path, int $ttl, array $required_keys = [], string $time_key = 'ts'): ?array
    {
        if ($ttl <= 0 || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        foreach ($required_keys as $key) {
            if (!array_key_exists($key, $decoded)) {
                return null;
            }
        }
        if ($time_key === '') {
            $mtime = @filemtime($path);
            if ($mtime === false || (time() - $mtime) >= $ttl) {
                return null;
            }
            return $decoded;
        }
        if (!array_key_exists($time_key, $decoded)) {
            return null;
        }

        $age = time() - (int) $decoded[$time_key];
        if ($age < 0 || $age >= $ttl) {
            return null;
        }

        return $decoded;
    }
}
if (!function_exists('log_drive_operation')) {
    function log_drive_operation(int $userId, string $username, string $operation, string $filename, string $type, string $scope, string $status = 'success'): void
    {
        global $conn;
        $logDir = dirname(__DIR__, 3) . '/logs';
        if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            error_log("[MEeL] log_drive_operation: gagal membuat log dir: {$logDir}");
        }
        $logFile = $logDir . '/drive_audit.log';
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 200);
        $logEntry = json_encode([
            'timestamp' => $timestamp,
            'user_id' => $userId,
            'username' => $username,
            'operation' => $operation,
            'filename' => $filename,
            'type' => $type,
            'scope' => $scope,
            'status' => $status,
            'ip' => $ip,
            'user_agent' => $userAgent
        ]) . "\n";
        if (!is_dir($logDir) || !is_writable($logDir)) {
            error_log("[MEeL] log_drive_operation: log dir tidak writable: {$logDir}");
        } elseif (file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
            error_log("[MEeL] log_drive_operation: gagal menulis log: {$logFile}");
        }
    }
}

/* reference build: MEeL-C7H6N2 [storage-split] */
