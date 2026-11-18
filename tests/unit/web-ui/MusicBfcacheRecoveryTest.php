<?php
use PHPUnit\Framework\TestCase;

/**
 * State recovery musik harus dibersihkan saat tab masuk/keluar bfcache.
 *
 * @coversNothing
 */
class MusicBfcacheRecoveryTest extends TestCase
{
    private function runHarness(string $scenario): array
    {
        exec('node --version 2>&1', $versionOut, $versionCode);
        if ($versionCode !== 0) {
            $this->markTestSkipped('Node.js tidak tersedia di lingkungan ini.');
        }
        $harness = MEEL_ROOT . '/tests/js/music-bfcache.harness.js';
        $this->assertFileExists($harness, 'Harness bfcache musik tidak ditemukan.');
        $output = [];
        $exit = 0;
        exec('node ' . escapeshellarg($harness) . ' ' . escapeshellarg($scenario) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, 'Harness gagal: ' . implode("\n", $output));
        $decoded = json_decode(end($output), true);
        $this->assertIsArray($decoded, 'Output harness bukan JSON: ' . implode("\n", $output));
        return $decoded;
    }
    public function testPagehideCleansUpRecoveryState(): void
    {
        $r = $this->runHarness('pagehide');
        $this->assertTrue($r['detectorStopped'], 'pagehide harus menghentikan stuck detector.');
        $this->assertTrue($r['overlayHidden'], 'pagehide harus menutup overlay reconnect.');
    }
    public function testBfcacheRestoreResetsStaleRecoveryState(): void
    {
        $r = $this->runHarness('bfcache-persisted');
        $this->assertTrue($r['detectorStopped'], 'Restore bfcache harus menghentikan detector lama.');
        $this->assertTrue($r['overlayHidden'], 'Restore bfcache harus menutup overlay usang.');
        $this->assertTrue($r['detectorRestarted'], 'Detector harus aktif lagi bila audio masih berjalan.');
    }
    public function testPendingRecoveryCallbackIsCancelledOnReset(): void
    {
        $r = $this->runHarness('pending-recovery-cancelled');
        $this->assertTrue($r['recoveryStarted'], 'Scenario harus benar-benar memulai recovery.');
        $this->assertTrue($r['listenerRemoved'], 'Reset harus melepas listener loadedmetadata recovery.');
        $this->assertTrue($r['noLatePlay'], 'Callback lama tidak boleh memutar audio setelah reset.');
    }
    public function testNormalPageshowDoesNotTouchPlayer(): void
    {
        $r = $this->runHarness('bfcache-normal');
        $this->assertTrue($r['noSideEffect'], 'pageshow biasa tidak boleh mengubah state player.');
    }
}
/* reference build: MEeL-C10H17N [d4e8a1c6b7f2] */
