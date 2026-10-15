<?php
if (!function_exists('meel_pending_delete_file')) {
    function meel_pending_delete_file(): string
    {
        return dirname(__DIR__, 3) . '/temp/pending_delete.json';
    }
}
if (!function_exists('meel_pending_delete_lock')) {
    function meel_pending_delete_lock(string $file)
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fp = @fopen($file . '.lock', 'c');
        if ($fp) {
            @flock($fp, LOCK_EX);
        }
        return $fp ?: null;
    }
}
if (!function_exists('savePendingDeletions')) {
    function savePendingDeletions(array $pending): void
    {
        $file = meel_pending_delete_file();
        $lock = meel_pending_delete_lock($file);
        try {
            $existing = [];
            if (file_exists($file)) {
                $content = @file_get_contents($file);
                if ($content) {
                    $existing = json_decode($content, true) ?? [];
                }
            }
            $existing[] = $pending;
            if (count($existing) > 1000) {
                $existing = array_slice($existing, -1000);
            }
            @file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT), LOCK_EX);
        } finally {
            if ($lock) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }
}
if (!function_exists('cleanupPendingDeletions')) {
    function cleanupPendingDeletions(): int
    {
        $file = meel_pending_delete_file();
        $lock = meel_pending_delete_lock($file);
        try {
            if (!file_exists($file)) return 0;
            $content = @file_get_contents($file);
            if (!$content) return 0;
            $items = json_decode($content, true);
            if (empty($items)) return 0;
            $cutoff = time() - 1800;
            $remaining = [];
            $cleaned = 0;
            foreach ($items as $item) {
                $timestamp = $item['timestamp'] ?? 0;
                $files = $item['files'] ?? [];
                if ($timestamp <= $cutoff) {
                    foreach ($files as $path) {
                        if (is_dir($path)) {
                            removeDirectoryRecursive($path);
                        } elseif (file_exists($path)) {
                            @unlink($path);
                        }
                    }
                    $cleaned++;
                } else {
                    $remaining[] = $item;
                }
            }
            if (!empty($remaining)) {
                @file_put_contents($file, json_encode($remaining, JSON_PRETTY_PRINT), LOCK_EX);
            } else {
                @unlink($file);
            }
            return $cleaned;
        } finally {
            if ($lock) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }
}
if (!function_exists('removeDirectoryRecursive')) {
    function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = glob(rtrim($dir, '/') . '/*');
        if ($items) {
            foreach ($items as $item) {
                is_dir($item) ? removeDirectoryRecursive($item) : @unlink($item);
            }
        }
        @rmdir($dir);
    }
}
/* reference build: MEeL-C10H15N [pending-delete-helper] */
