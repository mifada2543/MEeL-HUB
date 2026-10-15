<?php
if (!function_exists('meel_cache_is_fresh')) {
    function meel_cache_is_fresh(array $server, string $etag, int $mtime): bool
    {
        $ifNoneMatch = trim((string)($server['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatch === '*') {
            return true;
        }
        if ($ifNoneMatch !== '') {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === $etag || $candidate === 'W/' . $etag) {
                    return true;
                }
            }
        }
        $ifModifiedSince = trim((string)($server['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ifModifiedSince !== '') {
            $since = strtotime($ifModifiedSince);
            if ($since !== false && $mtime <= $since) {
                return true;
            }
        }
        return false;
    }
}
if (!function_exists('meel_media_etag')) {
    function meel_media_etag(string $realPath, int $size, int $mtime): string
    {
        return '"' . substr(md5($realPath . '|' . $size . '|' . $mtime), 0, 32) . '"';
    }
}
if (!function_exists('meel_media_cache_control')) {
    function meel_media_cache_control(string $ext): string
    {
        return $ext === 'm3u8'
            ? 'private, no-cache'
            : 'private, max-age=31536000, immutable';
    }
}
if (!function_exists('meel_serve_media_file')) {
    function meel_serve_media_file(string $module, string $relPath, array $opts = []): void
    {
        meel_storage_guard('binary');
        $base = meel_media_base_path($module);
        $baseReal = realpath($base);
        if ($baseReal === false) {
            http_response_code(503);
            exit('Storage tidak tersedia.');
        }
        $relPath = str_replace('\\', '/', (string) $relPath);
        $relPath = ltrim($relPath, '/');
        if ($relPath === '' || str_contains($relPath, '..') || str_contains($relPath, "\0")) {
            http_response_code(403);
            exit('Akses ditolak.');
        }
        $full = $base . '/' . $relPath;
        $realFull = realpath($full);
        if ($realFull === false || !str_starts_with($realFull, $baseReal . DIRECTORY_SEPARATOR)) {
            http_response_code(404);
            exit('File tidak ditemukan.');
        }
        if (!is_file($realFull) || !is_readable($realFull)) {
            http_response_code(404);
            exit('File tidak ditemukan.');
        }
        $ext = strtolower(pathinfo($realFull, PATHINFO_EXTENSION));
        $allowed = [
            'm3u8',
            'ts',
            'vtt',
            'mp4',
            'webm',
            'mkv',
            'jpg',
            'jpeg',
            'png',
            'webp',
            'gif',
            'pdf',
            'mp3',
            'ogg',
            'm4a',
            'flac',
            'wav',
            'opus',
        ];
        if (!in_array($ext, $allowed, true)) {
            http_response_code(403);
            exit('Tipe file tidak diizinkan.');
        }
        $mimeMap = [
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts' => 'video/mp2t',
            'vtt' => 'text/vtt',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
            'opus' => 'audio/ogg',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
        $size = (int) @filesize($realFull);
        $mtime = (int) @filemtime($realFull);
        if ($mtime <= 0) {
            $mtime = time();
        }
        $etag = meel_media_etag($realFull, $size, $mtime);
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $cacheControl = meel_media_cache_control($ext);
        if (!empty($opts['hls_gate']) && (str_starts_with($relPath, 'video/') || str_contains($relPath, '/video/'))) {
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $refOk = false;
            if ($referer !== '' && $host !== '') {
                $parts = parse_url($referer);
                $hostNorm = strtolower(parse_url('http://' . $host, PHP_URL_HOST) ?: $host);
                if ($parts && isset($parts['host']) && strtolower($parts['host']) === $hostNorm) {
                    $refPath = $parts['path'] ?? '';
                    if (preg_match('#/video(?:/(?:watch(?:\.php)?|index(?:\.php)?|beranda))?(?:[?\#]|/?$)#i', $refPath)) {
                        $refOk = true;
                    }
                }
            }
            if (!$refOk) {
                $script = $_SERVER['SCRIPT_NAME'] ?? '';
                $basePath = rtrim(dirname(dirname($script)), '/');
                header('Location: ' . $basePath . '/err/?code=denied');
                exit;
            }
        }
        if (meel_xsendfile_ready($realFull)) {
            header('Content-Type: ' . $mime);
            header('Accept-Ranges: bytes');
            header('Cache-Control: ' . $cacheControl);
            header('ETag: ' . $etag);
            header('Last-Modified: ' . $lastModified);
            header('X-Sendfile: ' . meel_xsendfile_header($realFull));
            exit;
        }
        $start = 0;
        $end = $size - 1;
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        $isPartial = false;
        if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            $rStart = $m[1] !== '' ? (int) $m[1] : null;
            $rEnd = $m[2] !== '' ? (int) $m[2] : null;
            if ($rStart === null && $rEnd === null) {
                $rStart = 0;
            }
            if ($rStart !== null) {
                $start = max(0, $rStart);
                $end = ($rEnd !== null && $rEnd < $size) ? $rEnd : ($size - 1);
                if ($start > $end) {
                    header('HTTP/1.1 416 Requested Range Not Satisfiable');
                    header('Content-Range: bytes */' . $size);
                    exit;
                }
                $isPartial = true;
            } elseif ($rEnd !== null) {
                $start = max(0, $size - $rEnd);
                $end = $size - 1;
                $isPartial = true;
            }
        }
        if (!$isPartial && meel_cache_is_fresh($_SERVER, $etag, $mtime)) {
            http_response_code(304);
            header('ETag: ' . $etag);
            header('Last-Modified: ' . $lastModified);
            header('Cache-Control: ' . $cacheControl);
            exit;
        }
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $lastModified);
        header('Cache-Control: ' . $cacheControl);
        if ($isPartial) {
            header('HTTP/1.1 206 Partial Content');
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        set_time_limit(0);
        ignore_user_abort(false);
        while (@ob_get_level()) {
            @ob_end_clean();
        }
        @ob_implicit_flush(true);
        $fp = @fopen($realFull, 'rb');
        if ($fp === false) {
            http_response_code(500);
            exit('Gagal membuka file.');
        }
        if ($start > 0) {
            fseek($fp, $start);
        }
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = fread($fp, min(8192, $remaining));
            if ($chunk === false) break;
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($fp);
        exit;
    }
}

/* reference build: MEeL-C9H5N2 [storage-split] */