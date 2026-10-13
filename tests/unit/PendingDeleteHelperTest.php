<?php
use PHPUnit\Framework\TestCase;


class PendingDeleteHelperTest extends TestCase
{
    private string $pendingFile;
    private bool $hadPendingFile = false;
    private string $pendingBackup = '';

    protected function setUp(): void
    {
        parent::setUp();

        require_once MEEL_ROOT . '/modules/core/helpers/pending_delete.php';
        require_once MEEL_ROOT . '/modules/core/GarbageCollector.php';

        $this->pendingFile = MEEL_ROOT . '/temp/pending_delete.json';
        if (is_file($this->pendingFile)) {
            $this->hadPendingFile = true;
            $this->pendingBackup = (string) file_get_contents($this->pendingFile);
        }

        self::resetHasRun();
        self::clearRunThrottle();
        self::clearPendingThrottle();
    }

    protected function tearDown(): void
    {
        @unlink($this->pendingFile);
        if ($this->hadPendingFile) {
            @file_put_contents($this->pendingFile, $this->pendingBackup);
        }
        self::clearPendingThrottle();
        parent::tearDown();
    }

    private static function resetHasRun(): void
    {
        $ref = new ReflectionClass(GarbageCollector::class);
        $prop = $ref->getProperty('hasRun');
        $prop->setAccessible(true);
        $prop->setValue(false);
    }

    private static function throttlePath(string $method): string
    {
        $ref = new ReflectionClass(GarbageCollector::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return (string) $m->invoke(null);
    }

    private static function clearRunThrottle(): void
    {
        @unlink(self::throttlePath('runThrottleFile'));
    }

    private static function clearPendingThrottle(): void
    {
        @unlink(MEEL_ROOT . '/temp/gc_pending_delete_last_run.txt');
    }

    private function pendingThrottleExists(): bool
    {
        return is_file(MEEL_ROOT . '/temp/gc_pending_delete_last_run.txt');
    }

    public function testPendingDeleteHelperHasNoDirectAccessGuard(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/core/helpers/pending_delete.php');

        $this->assertStringNotContainsString(
            'exit(',
            $src,
            'Helper antrean hapus tidak boleh memanggil exit() — dipakai dari CLI GarbageCollector'
        );
        $this->assertStringNotContainsString(
            'MEEL_MANAGE_ACCESS',
            $src,
            'Helper antrean hapus tidak boleh bergantung pada konstanta handler halaman'
        );
    }

    public function testGarbageCollectorRequiresGuardFreeHelper(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/core/GarbageCollector.php');

        $this->assertStringContainsString(
            "helpers/pending_delete.php",
            $src,
            'GarbageCollector harus memuat helper tanpa guard, bukan fun-manage.php'
        );
        $this->assertStringNotContainsString(
            "controllers/profile/fun-manage.php",
            $src,
            'GarbageCollector tidak boleh lagi memuat file handler ber-guard'
        );
    }

    public function testRunCleansExpiredPendingDeletionWithoutExiting(): void
    {
        $victim = MEEL_ROOT . '/temp/pd_victim_' . uniqid() . '.bin';
        file_put_contents($victim, 'x');

        $payload = json_encode([
            ['timestamp' => time() - 3600, 'files' => [$victim]],
        ]);
        file_put_contents($this->pendingFile, $payload);

        GarbageCollector::run();

        $this->assertFileDoesNotExist($victim, 'Entri kedaluwarsa harus dihapus tanpa exit()');
        $this->assertFileDoesNotExist($this->pendingFile, 'Antrean kosong harus dibuang');
        $this->assertTrue($this->pendingThrottleExists(), 'Throttle GC harus ditulis setelah cleanup');
    }

    public function testRunKeepsFreshPendingDeletionEntry(): void
    {
        $future = json_encode([
            ['timestamp' => time(), 'files' => ['/nonexistent/keep.me']],
        ]);
        file_put_contents($this->pendingFile, $future);

        GarbageCollector::run();

        $this->assertFileExists($this->pendingFile, 'Entri yang belum kedaluwarsa harus dipertahankan');
        $decoded = json_decode((string) file_get_contents($this->pendingFile), true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
    }
}

/* reference build: MEeL-C10H15N [02315643253] */
