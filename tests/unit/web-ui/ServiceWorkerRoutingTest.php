<?php
use PHPUnit\Framework\TestCase;

/**
 * Service worker tidak boleh meng-cache playlist dan segmen HLS.
 *
 * @coversNothing
 */
class ServiceWorkerRoutingTest extends TestCase
{
    private static function nodeAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            exec('node --version 2>&1', $output, $code);
            $available = $code === 0;
        }
        return $available;
    }
    private function runHarness(): array
    {
        if (!self::nodeAvailable()) {
            $this->markTestSkipped('Node.js tidak tersedia di lingkungan ini.');
        }
        $harness = MEEL_ROOT . '/tests/js/sw-routing.harness.js';
        $this->assertFileExists($harness, 'Harness routing SW tidak ditemukan.');
        $output = [];
        $exit = 0;
        exec('node ' . escapeshellarg($harness) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, 'Harness gagal: ' . implode("\n", $output));
        $decoded = json_decode(end($output), true);
        $this->assertIsArray($decoded, 'Output harness bukan JSON: ' . implode("\n", $output));
        return $decoded;
    }
    public function testHlsTrafficBypassesServiceWorkerCache(): void
    {
        $r = $this->runHarness();
        $this->assertTrue($r['hlsManifestPassthrough'], 'Playlist .m3u8 tidak boleh melewati cache SW.');
        $this->assertTrue($r['hlsSegmentPassthrough'], 'Segmen .ts tidak boleh melewati cache SW.');
        $this->assertTrue($r['hlsVttPassthrough'], 'VTT sprite tidak boleh melewati cache SW.');
        $this->assertTrue($r['noMediaInCache'], 'Tidak boleh ada entri m3u8/ts di cache SW.');
    }
    public function testImagesAndPagesStillUseTheirCaches(): void
    {
        $r = $this->runHarness();
        $this->assertTrue($r['thumbnailCached'], 'Thumbnail tetap memakai cache gambar.');
        $this->assertTrue($r['documentCached'], 'Halaman tetap memakai cache halaman.');
        $this->assertTrue($r['staticCached'], 'Aset statis tetap memakai cache statis.');
    }
    public function testCachePutFailureIsHandled(): void
    {
        $r = $this->runHarness();
        $this->assertTrue($r['safeCachePut'], 'Kegagalan cache.put tidak boleh membuat handler menolak.');
        $this->assertTrue($r['cacheFailureWarned'], 'Kegagalan cache.put harus dicatat, bukan unhandled rejection.');
    }
    public function testServiceWorkerSourceIsPlainJavaScript(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/sw.js.php');
        $this->assertStringNotContainsString('<script', $src, 'sw.js.php disajikan sebagai application/javascript, bukan HTML.');
        $this->assertStringNotContainsString('</script>', $src, 'Tag penutup script tidak boleh ikut terkirim ke browser.');
    }

    public function testServiceWorkerVersionTracksItsOwnSource(): void
    {
        require_once MEEL_ROOT . '/modules/core/SwPrecache.php';
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/core/SwPrecache.php');
        $this->assertStringContainsString('md5_file($swFile)', $src, 'Versi SW harus ikut berubah saat sw.js.php berubah.');
        $this->assertNotSame('', SwPrecache::version());
    }
}

/* reference build: MEeL-C10H17N [b1f2c4d7e9a0] */
