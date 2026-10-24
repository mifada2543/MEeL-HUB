<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers dir_size
 * @covers invalidate_dir_size_cache_path
 * @covers invalidate_dir_size_cache
 */
class StorageCacheInvalidationTest extends TestCase
{
    private string $dir;
    /** @var string[] */
    private array $cacheFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = MEEL_ROOT . '/temp/test-dirsize-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/sample.txt', str_repeat('x', 1024));
        $this->trackCache($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->cacheFiles) as $cacheFile) {
            @unlink($cacheFile);
        }
        @unlink($this->dir . '/sample.txt');
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function testDirSizeCacheIsRemovedByPathInvalidator(): void
    {
        $this->assertGreaterThan(0, dir_size($this->dir, 300));

        $cacheFile = $this->cacheFileFor($this->dir);
        $this->assertFileExists($cacheFile);

        invalidate_dir_size_cache_path($this->dir);

        $this->assertFileDoesNotExist($cacheFile);
    }

    public function testMediaStatsInvalidationClearsMediaDirSizeCache(): void
    {
        $base = meel_media_base_path('video');
        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            $this->fail('Gagal membuat direktori media uji.');
        }

        $probe = $base . '/__cache_invalidation_probe_' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($probe, str_repeat('m', 32));
        $this->trackCache($base);

        try {
            $this->assertGreaterThan(0, dir_size($base, 300));

            $cacheFile = $this->cacheFileFor($base);
            $this->assertFileExists($cacheFile);

            meel_invalidate_media_stats_cache('video');

            $this->assertFileDoesNotExist($cacheFile);
        } finally {
            @unlink($probe);
        }
    }

    public function testDriveInvalidatorClearsUserAndAggregateCaches(): void
    {
        if (!defined('MEEL_HDD_DRIVE') || (string) MEEL_HDD_DRIVE === '') {
            $this->markTestSkipped('MEEL_HDD_DRIVE tidak didefinisikan.');
        }

        $drive = rtrim((string) MEEL_HDD_DRIVE, '/\\');
        $username = 'testuser_' . bin2hex(random_bytes(4));
        $userDir = $drive . '/private_admins/' . $username;
        $aggregateDir = $drive . '/private_admins';

        if (!is_dir($userDir) && !mkdir($userDir, 0775, true) && !is_dir($userDir)) {
            $this->fail('Gagal membuat direktori uji drive.');
        }
        file_put_contents($userDir . '/file.bin', str_repeat('y', 64));

        $this->trackCache($userDir);
        $this->trackCache($aggregateDir);

        try {
            $this->assertGreaterThan(0, dir_size($userDir, 300));
            $this->assertGreaterThan(0, dir_size($aggregateDir, 300));

            $userCache = $this->cacheFileFor($userDir);
            $aggregateCache = $this->cacheFileFor($aggregateDir);
            $this->assertFileExists($userCache);
            $this->assertFileExists($aggregateCache);

            invalidate_dir_size_cache($username);

            $this->assertFileDoesNotExist($userCache);
            $this->assertFileDoesNotExist($aggregateCache);
        } finally {
            @unlink($userDir . '/file.bin');
            @rmdir($userDir);
            @rmdir($aggregateDir);
        }
    }

    private function trackCache(string $path): void
    {
        $this->cacheFiles[] = $this->cacheFileFor($path);
        $real = realpath($path);
        if ($real !== false) {
            $this->cacheFiles[] = $this->cacheFileFor($real);
        }
    }

    private function cacheFileFor(string $path): string
    {
        return MEEL_ROOT . '/temp/dirsize_' . md5($path) . '.cache';
    }
}
