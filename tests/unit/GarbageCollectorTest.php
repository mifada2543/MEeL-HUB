<?php
use PHPUnit\Framework\TestCase;

class GarbageCollectorTest extends TestCase
{
    private string $testTempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testTempDir = MEEL_ROOT . '/temp/gc_test_' . uniqid();
        @mkdir($this->testTempDir, 0755, true);

        self::resetHasRun();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testTempDir);
        parent::tearDown();
    }

    private static function resetHasRun(): void
    {
        $ref = new ReflectionClass(GarbageCollector::class);
        $prop = $ref->getProperty('hasRun');
        $prop->setAccessible(true);
        $prop->setValue(false);

        $throttle = $ref->getMethod('runThrottleFile');
        $throttle->setAccessible(true);
        @unlink($throttle->invoke(null));
    }

    private function touchAged(string $path, int $ageSeconds): void
    {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, 'x');
        touch($path, time() - $ageSeconds);
    }

    private function cleanDirectory(string $dir): void
    {
        $ref = new ReflectionClass(GarbageCollector::class);
        $method = $ref->getMethod('cleanDirectory');
        $method->setAccessible(true);
        $method->invoke(null, $dir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    public function testCleanDirectoryRemovesStaleFilesButKeepsFresh(): void
    {
        $stale = $this->testTempDir . '/stale.tmp';
        $fresh = $this->testTempDir . '/fresh.tmp';
        $this->touchAged($stale, 400);
        $this->touchAged($fresh, 10);

        $this->cleanDirectory($this->testTempDir);

        $this->assertFileDoesNotExist($stale, 'File dengan mtime > 5 menit harus dihapus');
        $this->assertFileExists($fresh, 'File dengan mtime < 5 menit harus dipertahankan');
    }

    public function testCleanDirectoryRemovesStaleDirectoryRecursively(): void
    {
        $sub = $this->testTempDir . '/stale-sub';
        $this->touchAged($sub . '/nested/file.bin', 400);
        touch($sub, time() - 400);

        $this->cleanDirectory($this->testTempDir);

        $this->assertDirectoryDoesNotExist($sub, 'Direktori stale harus dihapus beserta isinya');
    }

    public function testCleanDirectoryKeepsFreshDirectory(): void
    {
        $sub = $this->testTempDir . '/fresh-sub';
        $this->touchAged($sub . '/file.bin', 10);
        touch($sub, time() - 10);

        $this->cleanDirectory($this->testTempDir);

        $this->assertDirectoryExists($sub);
        $this->assertFileExists($sub . '/file.bin');
    }

    public function testCleanDirectorySkipsYtdlpPersistentCache(): void
    {
        $cache = $this->testTempDir . '/ytdlp-cache';
        $this->touchAged($cache . '/entries.db', 400);
        touch($cache, time() - 400);

        $this->cleanDirectory($this->testTempDir);

        $this->assertDirectoryExists($cache, 'ytdlp-cache persisten tidak boleh dihapus');
        $this->assertFileExists($cache . '/entries.db');
    }

    public function testCleanDirectoryKeepsActiveCacheAndRateLimitDirs(): void
    {
        $cache = $this->testTempDir . '/cache';
        $this->touchAged($cache . '/server_stats_info.json', 400);
        touch($cache, time() - 400);

        $rate = $this->testTempDir . '/ratelimit';
        $this->touchAged($rate . '/active.cache', 400);
        touch($rate, time() - 400);

        $this->cleanDirectory($this->testTempDir);

        $this->assertDirectoryExists($cache, 'temp/cache dikelola sistem lain, tidak boleh dihapus massal');
        $this->assertFileExists($cache . '/server_stats_info.json');
        $this->assertDirectoryExists($rate, 'temp/ratelimit punya RateLimiter::cleanup(), tidak boleh dihapus massal');
        $this->assertFileExists($rate . '/active.cache');
    }

    private string $origRateStorageDir = '';

    private function isolateRateLimiterDir(): string
    {
        $ref = new ReflectionClass(RateLimiter::class);
        $prop = $ref->getProperty('storageDir');
        $prop->setAccessible(true);
        $this->origRateStorageDir = (string) $prop->getValue();

        $rateDir = $this->testTempDir . '/ratelimit';
        @mkdir($rateDir, 0755, true);
        $prop->setValue($rateDir . '/');
        return $rateDir;
    }

    private function restoreRateLimiterDir(): void
    {
        $ref = new ReflectionClass(RateLimiter::class);
        $prop = $ref->getProperty('storageDir');
        $prop->setAccessible(true);
        $prop->setValue($this->origRateStorageDir);
    }

    public function testRunCleansExpiredRateLimitCacheButKeepsRecent(): void
    {
        $rateDir = $this->isolateRateLimiterDir();

        $expired = $rateDir . '/gc_test_expired.cache';
        file_put_contents($expired, json_encode([
            'count' => 5,
            'window_start' => time() - 7200,
        ]));

        $recent = $rateDir . '/gc_test_recent.cache';
        file_put_contents($recent, json_encode([
            'count' => 2,
            'window_start' => time(),
        ]));

        touch($rateDir, time());

        GarbageCollector::run();

        $this->assertFileDoesNotExist($expired, 'Rate-limit file dengan window_start > 1 jam harus dibersihkan');
        $this->assertFileExists($recent, 'Rate-limit file dalam window harus dipertahankan');

        $this->restoreRateLimiterDir();
    }

    public function testRunSecondCallIsNoOpViaStaticFlag(): void
    {
        $rateDir = $this->isolateRateLimiterDir();
        touch($rateDir, time());

        $expired = $rateDir . '/gc_test_first.cache';
        file_put_contents($expired, json_encode([
            'count' => 5,
            'window_start' => time() - 7200,
        ]));

        GarbageCollector::run();
        $this->assertFileDoesNotExist($expired, 'run() pertama membersihkan file kadaluarsa');

        $late = $rateDir . '/gc_test_late.cache';
        file_put_contents($late, json_encode([
            'count' => 5,
            'window_start' => time() - 7200,
        ]));

        GarbageCollector::run();

        $this->assertFileExists($late, 'run() kedua harus no-op (static $hasRun) — file tidak dibersihkan');

        $this->restoreRateLimiterDir();
    }

    public function testRunIsThrottledAcrossStaticFlagReset(): void
    {
        $rateDir = $this->isolateRateLimiterDir();
        touch($rateDir, time());

        GarbageCollector::run();

        self::assertHasRunThrottleFile(true, 'run() pertama harus menulis throttle file');

        self::resetHasRunFlagOnly();

        $late = $rateDir . '/gc_test_throttled.cache';
        file_put_contents($late, json_encode([
            'count' => 5,
            'window_start' => time() - 7200,
        ]));

        GarbageCollector::run();

        $this->assertFileExists($late, 'run() kedua dalam window throttle harus skip housekeeping');

        $this->restoreRateLimiterDir();
    }

    public function testRunThrottleFileIsStaleOutsideWindowAndRunsAgain(): void
    {
        $rateDir = $this->isolateRateLimiterDir();
        touch($rateDir, time());

        GarbageCollector::run();
        self::resetHasRunFlagOnly();

        self::writeRunThrottleFile(time() - (GarbageCollector::RUN_INTERVAL_SECONDS + 5));

        $expired = $rateDir . '/gc_test_after_window.cache';
        file_put_contents($expired, json_encode([
            'count' => 5,
            'window_start' => time() - 7200,
        ]));

        GarbageCollector::run();

        $this->assertFileDoesNotExist($expired, 'di luar jendela throttle housekeeping harus jalan lagi');

        $this->restoreRateLimiterDir();
    }

    public function testRunCliMaintenanceIsNoOpOutsideCli(): void
    {
        if (PHP_SAPI === 'cli') {
            $this->markTestSkipped('test ini hanya bermakna di SAPI non-CLI');
        }

        $ref = new ReflectionClass(GarbageCollector::class);
        $method = $ref->getMethod('runCliMaintenance');
        $method->setAccessible(true);

        $conn = $this->createMock(mysqli::class);
        $conn->expects($this->never())->method('query');

        $method->invoke(null, $conn);
        $this->addToAssertionCount(1);
    }

    public function testRunCliMaintenanceExistsAndIsPublic(): void
    {
        $ref = new ReflectionClass(GarbageCollector::class);
        $this->assertTrue(
            $ref->hasMethod('runCliMaintenance'),
            'scripts/gc.php bergantung pada entry point ini'
        );
        $this->assertTrue(
            $ref->getMethod('runCliMaintenance')->isPublic(),
            'entry point housekeeping berat harus publik supaya bisa dipanggil scripts/gc.php'
        );
        $this->assertTrue(
            $ref->getMethod('runCliMaintenance')->isStatic(),
            'dipanggil sebagai GarbageCollector::runCliMaintenance($conn)'
        );
    }

    public function testGcScriptRefusesNonCliAndHasCliGuard(): void
    {
        $script = MEEL_ROOT . '/scripts/gc.php';
        $this->assertFileExists($script, 'scripts/gc.php harus ada untuk cron');

        $src = (string)file_get_contents($script);
        $this->assertStringContainsString(
            "PHP_SAPI !== 'cli'",
            $src,
            'scripts/gc.php harus menolak eksekusi via web'
        );
        $this->assertStringContainsString('runCliMaintenance', $src);
        $this->assertStringContainsString('GarbageCollector::run()', $src);

        $this->assertSame(
            0,
            self::lintPhp($script),
            'scripts/gc.php harus lolos php -l'
        );
    }

    public function testNoWebEntryPointTriggersHeavyMaintenance(): void
    {

        $root = realpath(MEEL_ROOT);
        $skip = ['/vendor/', '/.git/', '/tests/', '/logs/', '/temp/'];
        $offenders = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
            $path = str_replace('\\', '/', $f->getPathname());
            foreach ($skip as $s) {
                if (strpos($path, $s) !== false) continue 2;
            }
            if (str_ends_with($path, '/GarbageCollector.php')) continue;
            if (str_ends_with($path, '/scripts/gc.php')) continue;

            $src = (string) file_get_contents($path);
            if (preg_match('/GarbageCollector::syncViews\s*\(/', $src)) {
                $offenders[] = str_replace(realpath(MEEL_ROOT) . '/', '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "syncViews() adalah pekerjaan berat (agregasi ulang dari activity_log) dan hanya boleh\n"
            . "dipanggil lewat scripts/gc.php. Pemanggil langsung dari halaman web:\n"
            . implode("\n", $offenders)
        );
    }

    private static function lintPhp(string $file): int
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1';
        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);
        return $rc;
    }

    private static function resetHasRunFlagOnly(): void
    {
        $ref = new ReflectionClass(GarbageCollector::class);
        $prop = $ref->getProperty('hasRun');
        $prop->setAccessible(true);
        $prop->setValue(false);
    }

    private static function runThrottleFilePath(): string
    {
        $ref = new ReflectionClass(GarbageCollector::class);
        $m = $ref->getMethod('runThrottleFile');
        $m->setAccessible(true);
        return (string)$m->invoke(null);
    }

    private static function writeRunThrottleFile(int $timestamp): void
    {
        $path = self::runThrottleFilePath();
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, (string)$timestamp);
    }

    private static function assertHasRunThrottleFile(bool $exists, string $message): void
    {
        self::assertSame($exists, is_file(self::runThrottleFilePath()), $message);
    }
}

/* reference build: MEeL-C5H5N5O [d28f874860db2b26] */
