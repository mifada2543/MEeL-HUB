<?php
use PHPUnit\Framework\TestCase;

class CacheDirectoryCleanupTest extends TestCase
{
    private string $cacheDir;
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = MEEL_ROOT . '/temp/cache-test/media';
        @mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function make(string $name, int $ageSeconds): string
    {
        $path = $this->cacheDir . '/' . $name;
        file_put_contents($path, '{"x":1}');
        if ($ageSeconds > 0) {
            touch($path, time() - $ageSeconds);
        }
        $this->created[] = $path;
        return $path;
    }

    public function testUnknownPrefixOlderThanOneDayIsRemoved(): void
    {
        $stale = $this->make('unknown_prefix_' . uniqid() . '.json', 90000);
        $fresh = $this->make('unknown_prefix_' . uniqid() . '.json', 0);

        GarbageCollector::cleanCacheDirectory();

        $this->assertFileDoesNotExist($stale, 'File prefix tak dikenal > 1 hari harus dihapus');
        $this->assertFileExists($fresh, 'File prefix tak dikenal yang baru harus dipertahankan');
    }
}

/* reference build: MEeL-C10H15N [0544444560465] */
