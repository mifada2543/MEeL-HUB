<?php
use PHPUnit\Framework\TestCase;

/** Pengujian perbaikan tanpa menyentuh penyimpanan produksi. @coversNothing */
class MislabeledThumbnailRepairTest extends TestCase
{
    public function testBackupInServedUploadTreeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ThumbnailRepair('/tmp/opencode/video/upload/thumbnail/backup');
    }

    public function testBackupInSiblingWebDirectoryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ThumbnailRepair(dirname(MEEL_ROOT) . '/other-site/backup');
    }

    public function testRepairAndRestoreAreSafeAndInventoryIsReadOnly(): void
    {
        $dir = '/tmp/opencode/meel-repair-' . bin2hex(random_bytes(4));
        mkdir($dir . '/video/upload/thumbnail', 0700, true);
        try {
            $path = $dir . '/video/upload/thumbnail/broken_sm.webp';
            exec(escapeshellarg(meel_ffmpeg_bin()) . ' -v error -y -i '
                . escapeshellarg(MEEL_ROOT . '/assets/img/video0.webp')
                . ' -vf scale=320:240 -frames:v 1 -c:v mjpeg -pix_fmt yuvj420p '
                . escapeshellarg($dir . '/source.jpg') . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
            copy($dir . '/source.jpg', $path);
            chmod($path, 0444);
            touch($path, time());
            $bytes = file_get_contents($path);
            $mtime = filemtime($path);
            $tool = new ThumbnailRepair($dir . '/backup/run-2020');
            $this->assertCount(1, $tool->inventory([dirname($path)]));
            $this->assertFileDoesNotExist($dir . '/backup');
            $this->assertSame($bytes, file_get_contents($path));
            $this->assertSame($mtime, filemtime($path));
            $orphan = $tool->backupPathFor($path);
            mkdir(dirname($orphan), 0700, true);
            copy($path, $orphan);
            $result = $tool->repair($path);
            $this->assertSame('repaired', $result['status'], $result['error']);
            $this->assertTrue(meel_is_valid_webp($path));
            $this->assertSame(0444, fileperms($path) & 0777);
            $this->assertNotSame($mtime, filemtime($path));
            $this->assertSame(256, getimagesize($path)[0]);
            $this->assertSame($bytes, file_get_contents($result['backup']));
            $this->assertSame(0600, fileperms($result['backup']) & 0777);
            $this->assertSame(0600, fileperms($dir . '/backup/run-2020/manifest.json') & 0777);
            $this->assertStringEndsWith('.jpg', $result['backup']);
            $this->assertStringStartsWith($dir . '/backup/', $result['backup']);
            $this->assertSame([], $tool->inventory([dirname($path)]));
            $converted = file_get_contents($path);
            $this->assertSame('skipped', $tool->repair($path)['status']);
            $this->assertSame($converted, file_get_contents($path));
            mkdir($dir . '/backup/run-9999');
            exec(PHP_BINARY . ' ' . escapeshellarg(MEEL_ROOT . '/scripts/repair-mislabeled-thumbnails.php') . ' --restore --dir=' . escapeshellarg(dirname($path)) . ' --backup-dir=' . escapeshellarg($dir . '/backup') . ' 2>&1', $restoreOutput, $restoreCode);
            $this->assertSame(0, $restoreCode, implode("\n", $restoreOutput));
            $this->assertStringContainsString('dipulihkan:', implode("\n", $restoreOutput));
            $this->assertSame(0444, fileperms($path) & 0777);
            $this->assertSame($bytes, file_get_contents($path));
            $this->assertSame('repaired', $tool->repair($path)['status']);
            chmod($path, 0644);
            file_put_contents($path, 'perubahan lain');
            $this->assertSame('failed', $tool->restore($dir . '/backup/run-2020')[0]['status']);
            $this->assertSame('perubahan lain', file_get_contents($path));
            $this->assertSame('failed', $tool->repair($path)['status']);
            $this->assertSame('perubahan lain', file_get_contents($path));
        } finally {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }

    public function testCliInventoryWritesNothing(): void
    {
        $dir = '/tmp/opencode/meel-cli-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/broken.webp', 'bukan gambar');
            $before = stat($dir . '/broken.webp');
            exec(PHP_BINARY . ' ' . escapeshellarg(MEEL_ROOT . '/scripts/repair-mislabeled-thumbnails.php') . ' --dir=' . escapeshellarg($dir) . ' --backup-dir=' . escapeshellarg($dir . '/backup') . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
            $this->assertSame(['broken.webp'], array_values(array_diff(scandir($dir), ['.', '..'])));
            $this->assertSame('bukan gambar', file_get_contents($dir . '/broken.webp'));
            $this->assertSame($before['mtime'], filemtime($dir . '/broken.webp'));
            mkdir($dir . '/backup/run-9999', 0700, true);
            exec(PHP_BINARY . ' ' . escapeshellarg(MEEL_ROOT . '/scripts/repair-mislabeled-thumbnails.php') . ' --restore --dir=' . escapeshellarg($dir) . ' --backup-dir=' . escapeshellarg($dir . '/backup') . ' 2>&1', $restoreOut, $restoreCode);
            $this->assertSame(1, $restoreCode, implode("\n", $restoreOut));
        } finally {
            if (is_dir($dir . '/backup/run-9999')) {
                foreach (glob($dir . '/backup/run-9999/.*') ?: [] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($dir . '/backup/run-9999');
                rmdir($dir . '/backup');
            }
            unlink($dir . '/broken.webp');
            rmdir($dir);
        }
    }
}
/* reference build: MEeL-C10H16N [9f2c87a1] */
