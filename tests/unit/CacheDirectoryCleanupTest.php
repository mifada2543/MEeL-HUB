<?php
use PHPUnit\Framework\TestCase;

/**
 * Pembersihan direktori cache.
 *
 * `GarbageCollector::cleanCacheDirectory()` membuang berkas cache yang
 * sudah lewat TTL. Berkas cache datang dari beberapa keluarga, masing-masing
 * dengan key waktu yang berbeda: `media_stats_` dan `server_stats_info`
 * memakai `ts`, `dirsize_` dan `xsendfile_roots` memakai `time`,
 * `admin_orphans` memakai `checked_at`.
 *
 * DULU
 * ----
 * Pemeriksaan kedaluwarsa memanggil `meel_read_cache_file($path, 0, [], '')`,
 * dan fungsi itu mengembalikan `null` begitu saja saat TTL-nya `0` — yang
 * artinya "belum kedaluwarsa" dibaca sebagai "sudah kedaluwarsa". Akibatnya
 * **setiap** berkas cache ber-prefix yang dikenal dihapus di setiap sweep,
 * termasuk yang baru saja ditulis. Cache tidak pernah berlaku.
 *
 * SEKARANG
 * --------
 * Key waktu dicari berurutan (`ts`, `time`, `checked_at`), dan berkas tanpa
 * key waktu apa pun jatuh ke mtime.
 *
 * @coversNothing
 */
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
        $this->created = [];
        parent::tearDown();
    }

    private function make(string $name, int $ageSeconds): string
    {
        return $this->makeWithBody($name, '{"x":1}', $ageSeconds);
    }

    private function makeWithBody(string $name, string $body, int $ageSeconds): string
    {
        $path = $this->cacheDir . '/' . $name;
        file_put_contents($path, $body);
        if ($ageSeconds > 0) {
            touch($path, time() - $ageSeconds);
        }
        $this->created[] = $path;
        return $path;
    }

    private function sweep(): int
    {
        return GarbageCollector::cleanCacheDirectory();
    }

    public function testUnknownPrefixOlderThanOneDayIsRemoved(): void
    {
        $stale = $this->make('unknown_prefix_' . uniqid() . '.json', 90000);
        $fresh = $this->make('unknown_prefix_' . uniqid() . '.json', 0);

        $this->sweep();

        $this->assertFileDoesNotExist($stale, 'File prefix tak dikenal > 1 hari harus dihapus');
        $this->assertFileExists($fresh, 'File prefix tak dikenal yang baru harus dipertahankan');
    }

    /**
     * Inti perbaikannya: berkas cache yang baru ditulis harus bertahan
     * sampai TTL-nya habis.
     */
    public function testFreshCacheFileSurvivesTheSweep(): void
    {
        $fresh = $this->makeWithBody(
            'media_stats_fresh_' . uniqid() . '.json',
            json_encode(['total' => 1, 'ts' => time() - 5]),
            0
        );

        $this->sweep();

        $this->assertFileExists(
            $fresh,
            'Cache yang baru ditulis (5 detik, TTL 30) harus bertahan — '
            . 'kalau hilang, cache tidak pernah berlaku sama sekali'
        );
    }

    /**
     * @dataProvider stalePrefixProvider
     */
    public function testStaleCacheFileIsRemoved(string $prefix, string $timeKey): void
    {
        $body = json_encode(['x' => 1, $timeKey => time() - 100000]);
        $stale = $this->makeWithBody($prefix . uniqid() . '.json', $body, 0);

        $this->sweep();

        $this->assertFileDoesNotExist($stale, "Cache {$prefix} yang lewat TTL harus dihapus");
    }

    public static function stalePrefixProvider(): array
    {
        return [
            'media_stats_ (ts)' => ['media_stats_', 'ts'],
            'server_stats_info (ts)' => ['server_stats_info', 'ts'],
            'dirsize_ (time)' => ['dirsize_', 'time'],
            'xsendfile_roots (time)' => ['xsendfile_roots', 'time'],
            'admin_orphans (checked_at)' => ['admin_orphans', 'checked_at'],
        ];
    }

    /**
     * @dataProvider freshPrefixProvider
     */
    public function testEachTimeKeyIsHonoured(string $prefix, string $timeKey, int $ttl): void
    {
        $body = json_encode(['x' => 1, $timeKey => time()]);
        $fresh = $this->makeWithBody($prefix . uniqid() . '.json', $body, 0);

        $this->sweep();

        $this->assertFileExists(
            $fresh,
            "Cache {$prefix} dengan key waktu '{$timeKey}' harus dipahami sebagai segar"
        );
    }

    public static function freshPrefixProvider(): array
    {
        return [
            'media_stats_' => ['media_stats_', 'ts', 30],
            'server_stats_info' => ['server_stats_info', 'ts', 300],
            'dirsize_' => ['dirsize_', 'time', 300],
            'xsendfile_roots' => ['xsendfile_roots', 'time', 86400],
            'admin_orphans' => ['admin_orphans', 'checked_at', 120],
        ];
    }

    /**
     * Berkas sementara dari proses yang mati di tengah menulis dibuang
     * setelah 5 menit.
     */
    public function testTemporaryFileIsRemovedAfterFiveMinutes(): void
    {
        $old = $this->make('leftover.tmp.1700000000.abcdef012345', 400);
        $new = $this->make('recent.tmp.1700000000.abcdef012345', 0);

        $this->sweep();

        $this->assertFileDoesNotExist($old, 'Berkas sementara > 5 menit harus dibuang');
        $this->assertFileExists($new, 'Berkas sementara yang baru harus dipertahankan');
    }

    /**
     * Nama yang mirip berkas sementara tapi tidak formatsinya harus
     * diperlakukan sebagai berkas biasa.
     */
    public function testMalformedTemporaryNameIsNotTreatedAsTemporary(): void
    {
        $file = $this->make('almost.tmp.1700000000.tooshort', 0);

        $this->sweep();

        $this->assertFileExists($file, 'Nama tmp yang salah format bukan berkas sementara');
    }

    /**
     * Cache dengan isi rusak tidak boleh menggantung selamanya.
     */
    public function testUnreadableCacheIsRemoved(): void
    {
        $broken = $this->makeWithBody('media_stats_broken_' . uniqid() . '.json', 'bukan json', 0);

        $this->sweep();

        $this->assertFileDoesNotExist($broken, 'Cache yang isinya rusak harus dibuang');
    }

    /**
     * Cache tanpa key waktu apa pun harus tetap kedaluwarsa lewat mtime,
     * bukan menggantung selamanya.
     */
    public function testCacheWithoutTimeKeyFallsBackToModificationTime(): void
    {
        $stale = $this->makeWithBody('media_stats_nokey_' . uniqid() . '.json', '{"x":1}', 100000);
        $fresh = $this->makeWithBody('media_stats_nokey_' . uniqid() . '.json', '{"x":1}', 0);

        $this->sweep();

        $this->assertFileDoesNotExist($stale, 'Cache tanpa key waktu harus kadaluwarsa lewat mtime');
        $this->assertFileExists($fresh, 'Cache tanpa key waktu yang baru harus dipertahankan');
    }

    /**
     * Lokasi cache harus sama dengan yang dipakai aplikasi —
     * `meel_media_cache_dir()` — supaya sweeper membersihkan tempat yang
     * memang dipakai, termasuk ketika `MEEL_MEDIA_CACHE_DIR` diset lewat
     * environment variable.
     */
    public function testCollectorUsesTheSameCacheDirectoryAsTheApplication(): void
    {
        $expected = meel_media_cache_dir();

        $method = new ReflectionMethod(GarbageCollector::class, 'getMediaCacheDir');
        $method->setAccessible(true);

        $this->assertSame(
            rtrim($expected, '/'),
            rtrim((string) $method->invoke(null), '/'),
            'GarbageCollector dan aplikasi harus membersihkan direktori cache yang sama'
        );
    }

    /**
     * Pada saat konstanta terdefinisi, kedua cara itu menghasilkan path
     * yang sama, jadi perbandingan di atas tidak bisa membedakan. Yang
     * membedakan adalah delegation-nya: helper aplikasi melihat konstanta
     * **dan** environment variable, sedangkan salinan lama hanya melihat
     * konstanta.
     */
    public function testCollectorDelegatesToTheApplicationCacheDirectoryHelper(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/core/GarbageCollector.php');

        $this->assertMatchesRegularExpression(
            '/function getMediaCacheDir\(\).*?meel_media_cache_dir\(\)/s',
            $src,
            'getMediaCacheDir() harus memakai meel_media_cache_dir() — '
            . 'kalau ia punya salinan sendiri, deployment yang mengatur cache lewat '
            . 'environment variable akan punya sweeper yang membersihkan direktori lain'
        );
    }

    /**
     * Per gamble per-berkas sebaiknya tetap berupa helper bernama, bukan
     * loop panjang di dalam fungsi utama.
     */
    public function testPerItemDecisionLivesInNamedHelpers(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/core/GarbageCollector.php');

        foreach (['deleteCacheItemIfExpired', 'deleteCacheItemOlderThan', 'deleteCacheItemIfStale'] as $helper) {
            $this->assertStringContainsString(
                'function ' . $helper . '(',
                $src,
                "Helper {$helper} harus ada"
            );
        }

        $method = new ReflectionMethod(GarbageCollector::class, 'cleanCacheDirectory');
        $lines = $method->getEndLine() - $method->getStartLine() + 1;

        $this->assertLessThanOrEqual(
            30,
            $lines,
            'cleanCacheDirectory() masih terlalu panjang: ' . $lines . ' baris'
        );
    }
}

/* reference build: MEeL-C10H15N [0544444560465] */
