<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class VideoLoopRecoveryTest extends TestCase
{
    private static function nodeAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            $output = [];
            $code = 0;
            exec('node --version 2>&1', $output, $code);
            $available = ($code === 0);
        }
        return $available;
    }

    private function runHarness(string $scenario): array
    {
        if (!self::nodeAvailable()) {
            $this->markTestSkipped('Node.js tidak tersedia di lingkungan ini.');
        }
        $harness = MEEL_ROOT . '/tests/js/video-loop-recovery.harness.js';
        $this->assertFileExists($harness, 'Harness tidak ditemukan.');

        $output = [];
        $exit = 0;
        exec('node ' . escapeshellarg($harness) . ' ' . escapeshellarg($scenario) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, 'Harness gagal: ' . implode("\n", $output));

        $decoded = json_decode(end($output), true);
        $this->assertIsArray($decoded, 'Output harness bukan JSON: ' . implode("\n", $output));
        return $decoded;
    }

    public function testDecodeErrorSelfHealsWithoutRecoveryWhenPlaybackResumes(): void
    {
        $r = $this->runHarness('selfheal-success');

        $this->assertTrue(
            $r['recoverCalledImmediately'],
            'Error decode harus langsung memanggil hls.recoverMediaError()'
        );
        $this->assertFalse(
            $r['recoveryDuringGrace'],
            'Recovery TIDAK boleh naik selama grace period self-heal'
        );
        $this->assertTrue(
            $r['stuckBlockedDuringGrace'],
            'Pemicu lain (stuck detector) harus ditahan selama grace period'
        );
        $this->assertFalse(
            $r['recoveryAfterSuccess'],
            'Playback maju -> tidak ada full recovery sama sekali'
        );
        $this->assertTrue($r['selfHealedLogged'], 'Harus ada log konfirmasi self-heal berhasil');
    }

    public function testDecodeErrorEscalatesWhenPlaybackStaysFrozen(): void
    {
        $r = $this->runHarness('selfheal-escalate');

        $this->assertFalse(
            $r['recoveryDuringGrace'],
            'Recovery harus ditahan selama grace period'
        );
        $this->assertTrue(
            $r['escalatedAfterGrace'],
            'Kalau playback beku, recovery HARUS tetap jalan setelah grace habis'
        );
        $this->assertTrue($r['escalationLogged'], 'Eskalasi harus ada jejak log');
    }

    public function testGracePeriodBlocksTriggersFromOtherSources(): void
    {
        $r = $this->runHarness('grace-blocks-stuck');

        $this->assertTrue(
            $r['recoveryBlocked'],
            'triggerPlayerRecovery() harus menepi saat selfHealUntil masih aktif'
        );
        $this->assertTrue($r['graceLogPresent'], 'Harus ada log saat recovery ditahan grace period');
    }

    public function testRepeatedErrorsOpenOnlyOneGraceWindow(): void
    {
        $r = $this->runHarness('selfheal-double-error');

        $this->assertTrue(
            $r['singleHealAttempt'],
            'Error beruntun tidak boleh menumpuk grace window / recoverMediaError'
        );
    }

    public function testUnsupportedSourceStillTriggersFullRecovery(): void
    {
        $r = $this->runHarness('unsupported-src-still-recovers');

        $this->assertTrue(
            $r['recoveryForCode4'],
            'Kode 4 (SRC_NOT_SUPPORTED) tidak bisa di-heal -> recovery tetap wajib'
        );
        $this->assertTrue(
            $r['noSelfHealForCode4'],
            'Kode 4 tidak bolehdicoba self-heal'
        );
    }

    public function testNetworkErrorUsesStartLoadInsteadOfRecoverMediaError(): void
    {
        $r = $this->runHarness('network-error-heals');

        $this->assertTrue(
            $r['usedStartLoad'],
            'Kode 2 harus memuat ulang segmen via hls.startLoad()'
        );
        $this->assertTrue(
            $r['noRecoverForCode2'],
            'Kode 2 tidak boleh memakai recoverMediaError() (itu khusus decode)'
        );
    }
}

/* reference build: MEeL-C10H15N [video-loop-recovery-test] */