<?php
require_once __DIR__ . '/../core/helpers/upload.php';
require_once __DIR__ . '/../core/helpers/media_binary.php';
class ThumbnailRepair
{
    private string $backupDir;
    public int $scanned = 0;
    public function __construct(string $backupDir)
    {
        $this->backupDir = $this->absolute($backupDir);
        $this->assertBackupSafe($this->backupDir);
    }
    public function inventory(array $dirs): array
    {
        $this->scanned = 0;
        $entries = [];
        foreach (array_unique($dirs) as $dir) {
            if (!is_dir($dir)) {
                throw new RuntimeException('Direktori tidak ditemukan: ' . $dir);
            }
            foreach (new DirectoryIterator($dir) as $file) {
                if ($file->isLink() || !$file->isFile() || !preg_match('/\.webp$/i', $file->getFilename())) {
                    continue;
                }
                $this->scanned++;
                $path = $file->getRealPath();
                if (!meel_is_valid_webp($path)) {
                    $entries[] = ['path' => $path, 'name' => basename($path), 'dir' => dirname($path),
                        'mime' => meel_image_mime_of($path), 'size' => filesize($path), 'mtime' => filemtime($path)];
                }
            }
        }
        return $entries;
    }
    public function backupPathFor(string $path): string
    {
        $path = $this->absolute($path);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/avif' => 'avif', 'image/bmp' => 'bmp', 'image/webp' => 'webp'][meel_image_mime_of($path)] ?? 'bin';
        return $this->backupDir . '/originals/' . ltrim(dirname($path), '/') . '/' . basename($path) . '.' . $ext;
    }
    public function repair(string $path): array
    {
        if (is_link($path)) {
            return ['status' => 'failed', 'path' => $path, 'mime_before' => '', 'mime_after' => '',
                'size_before' => 0, 'size_after' => 0, 'backup' => '', 'error' => 'Symlink tidak boleh diperbaiki.'];
        }
        $path = $this->absolute($path);
        $result = ['status' => 'failed', 'path' => $path, 'mime_before' => '', 'mime_after' => '',
            'size_before' => 0, 'size_after' => 0, 'backup' => '', 'error' => ''];
        $tmp = '';
        $lock = null;
        try {
            if (is_link($path) || !is_file($path) || !preg_match('/\.webp$/i', $path)) {
                throw new RuntimeException('Bukan file thumbnail biasa: ' . $path);
            }
            clearstatcache(true, $path);
            $result['mime_before'] = $result['mime_after'] = meel_image_mime_of($path);
            $result['size_before'] = $result['size_after'] = filesize($path);
            if (meel_is_valid_webp($path)) {
                $result['status'] = 'skipped';
                return $result;
            }
            $this->assertBackupSafe($this->backupDir);
            if (str_starts_with($this->backupDir . '/', dirname($path) . '/')) {
                throw new RuntimeException('Cadangan tidak boleh berada dalam direktori sumber.');
            }
            $hash = hash_file('sha256', $path);
            $mtime = filemtime($path);
            $tmp = $this->temporary($path);
            $width = preg_match('/_sm\.webp$/i', $path) ? 256 : 1280;
            if (!meel_convert_image_to_webp($path, $tmp, $width, $width, false) || !$this->fullyDecoded($tmp)) {
                throw new RuntimeException('Konversi atau dekode penuh WebP gagal.');
            }
            $this->stage($tmp, $mtime, fileperms($path) & 0777);
            $lock = $this->lock();
            $manifest = $this->manifest($this->backupDir);
            $backup = $this->backupPathFor($path);
            $this->mkdir(dirname($backup));
            if (!str_starts_with($this->absolute($backup), $this->backupDir . '/')) {
                throw new RuntimeException('Path cadangan keluar dari direktori cadangan.');
            }
            if (isset($manifest[$path]) && is_file($manifest[$path]['backup'])) {
                $backup = $manifest[$path]['backup'];
                if (hash_file('sha256', $backup) !== $hash) {
                    throw new RuntimeException('Cadangan asli berbeda; gunakan direktori cadangan baru.');
                }
            } elseif (file_exists($backup)) {
                if (is_link($backup) || hash_file('sha256', $backup) !== $hash) {
                    throw new RuntimeException('Cadangan tanpa manifes berbeda; berkas dipertahankan.');
                }
                if (!chmod($backup, 0600)) {
                    throw new RuntimeException('Gagal membatasi izin cadangan.');
                }
                $this->sync($backup);
            } else {
                $backupTmp = $backup . '.' . bin2hex(random_bytes(8)) . '.tmp';
                try {
                    if (!copy($path, $backupTmp) || !chmod($backupTmp, 0600)) {
                        throw new RuntimeException('Gagal menyimpan cadangan asli.');
                    }
                    $this->sync($backupTmp);
                    if (hash_file('sha256', $backupTmp) !== $hash || !rename($backupTmp, $backup)) {
                        throw new RuntimeException('Gagal menyimpan cadangan atomik.');
                    }
                } finally {
                    if (is_file($backupTmp)) {
                        unlink($backupTmp);
                    }
                }
            }
            if (hash_file('sha256', $backup) !== $hash || hash_file('sha256', $path) !== $hash) {
                throw new RuntimeException('Sumber berubah selama perbaikan.');
            }
            $manifest[$path] = ['backup' => $backup, 'original_hash' => $hash, 'repaired_hash' => hash_file('sha256', $tmp)];
            $this->writeManifest($manifest);
            if (hash_file('sha256', $path) !== $hash || !rename($tmp, $path)) {
                throw new RuntimeException('Sumber berubah atau penggantian atomik gagal.');
            }
            clearstatcache(true, $path);
            $result['status'] = 'repaired';
            $result['backup'] = $backup;
            $result['mime_after'] = meel_image_mime_of($path);
            $result['size_after'] = filesize($path);
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        } finally {
            if ($tmp !== '' && is_file($tmp)) {
                unlink($tmp);
            }
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
        return $result;
    }
    public function restore(string $backupDir): array
    {
        $other = new self($backupDir);
        $lock = $other->lock();
        try {
            $results = [];
            foreach ($other->manifest($other->backupDir) as $path => $entry) {
                $tmp = '';
                $result = ['path' => $path, 'backup' => $entry['backup'], 'status' => 'failed', 'error' => ''];
                try {
                    $backup = $this->absolute($entry['backup']);
                    if (!str_starts_with($backup, $other->backupDir . '/') || is_link($path)
                        || !is_file($path) || hash_file('sha256', $backup) !== $entry['original_hash']) {
                        throw new RuntimeException('Cadangan atau tujuan pemulihan tidak aman.');
                    }
                    $hash = hash_file('sha256', $path);
                    if ($hash === $entry['original_hash']) {
                        $result['status'] = 'skipped';
                    } else {
                        if ($hash !== $entry['repaired_hash']) {
                            throw new RuntimeException('Tujuan berubah; pemulihan ditolak.');
                        }
                        $tmp = $this->temporary($path);
                        if (!copy($backup, $tmp)) {
                            throw new RuntimeException('Gagal menyalin cadangan.');
                        }
                        $this->stage($tmp, filemtime($path), fileperms($path) & 0777);
                        if (hash_file('sha256', $path) !== $hash || !rename($tmp, $path)) {
                            throw new RuntimeException('Tujuan berubah atau pemulihan atomik gagal.');
                        }
                        clearstatcache(true, $path);
                        $result['status'] = 'restored';
                    }
                } catch (Throwable $e) {
                    $result['error'] = $e->getMessage();
                } finally {
                    if ($tmp !== '' && is_file($tmp)) {
                        unlink($tmp);
                    }
                }
                $results[] = $result;
            }
            return $results;
        } finally {
            fclose($lock);
        }
    }
    private function fullyDecoded(string $path): bool
    {
        if (!meel_is_valid_webp($path)) {
            return false;
        }
        exec(escapeshellarg(meel_ffmpeg_bin()) . ' -v error -xerror -i ' . escapeshellarg($path)
            . ' -c:v png -f null - 2>&1', $output, $code);
        return $code === 0;
    }
    private function stage(string $path, int $oldMtime, int $mode): void
    {
        $mtime = time();
        if ($mtime === $oldMtime) {
            $mtime++;
        }
        if (!chmod($path, $mode) || !touch($path, $mtime)) {
            throw new RuntimeException('Gagal menyiapkan izin atau versi cache baru.');
        }
        clearstatcache(true, $path);
        if (filemtime($path) === $oldMtime) {
            throw new RuntimeException('Versi cache tidak berubah.');
        }
        $this->sync($path);
    }
    private function temporary(string $path): string
    {
        return dirname($path) . '/.' . basename($path) . '.repair-' . getmypid() . '-' . bin2hex(random_bytes(8)) . '.webp';
    }
    private function absolute(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('Path kosong.');
        }
        if ($path[0] !== '/') {
            $path = getcwd() . '/' . $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        $path = '/' . implode('/', $parts);
        $ancestor = $path;
        $suffix = '';
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $suffix = '/' . basename($ancestor) . $suffix;
            $ancestor = dirname($ancestor);
        }
        return rtrim(realpath($ancestor) ?: $ancestor, '/') . $suffix;
    }
    private function assertBackupSafe(string $path): void
    {
        $root = realpath(dirname(__DIR__, 2));
        $webRoots = [dirname($root)];
        if (!empty($_SERVER['DOCUMENT_ROOT']) && is_dir($_SERVER['DOCUMENT_ROOT'])) {
            $webRoots[] = realpath($_SERVER['DOCUMENT_ROOT']);
        }
        foreach ($webRoots as $webRoot) {
            if ($path === $webRoot || str_starts_with($path, rtrim($webRoot, '/') . '/')) {
                throw new InvalidArgumentException('Cadangan harus di luar document root.');
            }
        }
        if ($path === $root || str_starts_with($path, $root . '/') || preg_match('~/(?:video|music|books)/upload(?:/|$)~i', $path)) {
            throw new InvalidArgumentException('Cadangan harus di luar pohon yang dilayani web.');
        }
        if (function_exists('meel_media_base_path')) {
            foreach (['video', 'music', 'books'] as $module) {
                $base = $this->absolute(meel_media_base_path($module));
                if ($path === $base || str_starts_with($path, $base . '/')) {
                    throw new InvalidArgumentException('Cadangan tidak boleh berada dalam upload modul.');
                }
            }
        }
    }
    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new RuntimeException('Gagal membuat direktori cadangan: ' . $dir);
        }
    }
    private function lock()
    {
        $this->assertBackupSafe($this->backupDir);
        $this->mkdir($this->backupDir);
        $lock = fopen($this->backupDir . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Gagal mengunci cadangan.');
        }
        return $lock;
    }
    private function manifest(string $dir): array
    {
        if (!file_exists($dir . '/manifest.json')) {
            return [];
        }
        return json_decode(file_get_contents($dir . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private function writeManifest(array $manifest): void
    {
        $path = $this->backupDir . '/manifest.json';
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($tmp, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Gagal menulis manifes.');
            }
            if (!chmod($tmp, 0600)) {
                throw new RuntimeException('Gagal membatasi izin manifes.');
            }
            $this->sync($tmp);
            if (!rename($tmp, $path)) {
                throw new RuntimeException('Gagal menyimpan manifes.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
    private function sync(string $path): void
    {
        $handle = fopen($path, 'rb');
        try {
            if (!$handle || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Gagal memastikan cadangan tersimpan.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
/* reference build: MEeL-C10H16N [ad239c81] */
