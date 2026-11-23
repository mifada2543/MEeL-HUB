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
    public function testPlainTextProbeIsNeverReportedAsOnline(): void
    {
        $r = $this->runHarness('probe-stale-text-rejected');

        $this->assertTrue($r['noOnlineLog'], 'Teks biasa (mis. respons 503 buatan SW) tidak boleh dianggap media online.');
        $this->assertTrue($r['notResetRetry'], 'Probe palsu tidak boleh mereset penghitung retry.');
        $this->assertTrue($r['retryScheduled'], 'Probe gagal harus menjadwalkan percobaan ulang.');
    }
    public function testServerErrorDoesNotResetRecoveryBudget(): void
    {
        $r = $this->runHarness('probe-503-not-online');

        $this->assertTrue($r['noOnlineLog'], '503 tidak boleh dianggap media online.');
        $this->assertTrue($r['notResetRetry'], '503 tidak boleh mereset penghitung retry.');
        $this->assertTrue($r['statusLogged'], 'Status 503 harus tercatat di log untuk diagnosis.');
    }
    public function testValidPlaylistStillStartsRecovery(): void
    {
        $r = $this->runHarness('probe-valid-manifest');

        $this->assertTrue($r['onlineLog'], 'Manifest #EXTM3U yang valid tetap memicu recovery.');
        $this->assertTrue($r['retryReset'], 'Recovery yang sah boleh mereset penghitung retry.');
    }
    public function testPendingProbeIsAbortedWhenPlayerWrapperIsSwapped(): void
    {
        $r = $this->runHarness('probe-aborted-on-swap');

        $this->assertTrue($r['abortOnSwap'], 'Probe yang masih berjalan harus di-abort saat wrapper diganti.');
        $this->assertTrue($r['timersCleared'], 'Timer retry harus dibersihkan saat wrapper diganti.');
    }
    public function testHiddenTabStopsProbeRetries(): void
    {
        $r = $this->runHarness('probe-hidden-no-retry');
        $this->assertTrue($r['noRetryWhileHidden'], 'Tab tersembunyi tidak boleh terus membanjiri server dengan probe.');
    }
    public function testSlowResponseBodyCanStillBeAborted(): void
    {
        $r = $this->runHarness('probe-slow-body-cancel');

        $this->assertTrue($r['abortDuringBody'], 'Badan yang mandek harus bisa di-abort.');
        $this->assertTrue($r['noRetryAfterCancel'], 'Probe yang dibatalkan tidak boleh menjadwalkan retry.');
        $this->assertTrue($r['staleResultIgnored'], 'Hasil probe lama harus diabaikan.');
    }
    public function testStaleProbeRejectionDoesNotEraseNewProbe(): void
    {
        $r = $this->runHarness('probe-stale-rejection');
        $this->assertTrue($r['newProbeAborted'], 'Callback probe lama tidak boleh menghapus controller probe baru.');
    }
    public function testPlainVideoStillUsesContentTypeValidation(): void
    {
        $r = $this->runHarness('probe-mp4-video-online');

        $this->assertTrue($r['onlineLog'], 'Video non-HLS dengan tipe video/mp4 harus tetap dianggap online.');
        $this->assertTrue($r['retryReset'], 'Probe video biasa yang valid boleh mereset retry.');
    }
    public function testBfcacheMiniPlayerRebuildsHlsWithoutFullReload(): void
    {
        $r = $this->runHarness('bfcache-mini-rebuild');

        $this->assertTrue($r['hlsRebuilt'], 'Mini-player harus membangun Hls/worker baru.');
        $this->assertTrue($r['playerKept'], 'Plyr yang masih sehat tidak perlu dihancurkan.');
        $this->assertTrue($r['noReload'], 'Mini-player tidak perlu memuat ulang halaman.');
    }
    public function testAuthorizationFailureReloadsOnceInsteadOfRetrying(): void
    {
        $r = $this->runHarness('probe-403-auth');
        $this->assertTrue($r['reloadedOnce'], '403 harus memicu reload penuh untuk memperbarui token stream.');
        $this->assertTrue($r['noRetryTimer'], '403 tidak boleh masuk loop retry "media offline".');
        $this->assertTrue($r['overlayHidden'], 'Overlay reconnect harus ditutup sebelum reload.');
        $this->assertTrue($r['staleHlsDestroyed'], 'Hls/worker blob usang harus dilepas sebelum reload.');
        $this->assertTrue($r['markerSet'], 'Penanda reload sekali pakai harus dicatat.');
    }
    public function testRepeatedAuthorizationFailureStopsWithVisibleError(): void
    {
        $r = $this->runHarness('probe-403-repeat');

        $this->assertTrue($r['noSecondReload'], 'Reload kedua tidak boleh terjadi (anti reload loop).');
        $this->assertTrue($r['failedShown'], 'Pengguna harus melihat pesan gagal bila otorisasi tetap ditolak.');
    }
    public function testSuccessfulProbeClearsAuthorizationMarker(): void
    {
        $r = $this->runHarness('probe-403-cleared');
        $this->assertTrue($r['markerCleared'], 'Penanda harus dihapus setelah media valid kembali.');
    }
    public function testBlockedStorageStillGuardsReloadLoop(): void
    {
        $r = $this->runHarness('probe-403-storage-blocked');
        $this->assertTrue($r['firstReload'], 'Storage diblokir tidak boleh menghalangi reload pertama.');
        $this->assertTrue($r['nameMarkerSet'], 'Fallback window.name harus dipakai bila sessionStorage diblokir.');
    }
    public function testNameMarkerAlsoStopsSecondReload(): void
    {
        $r = $this->runHarness('probe-403-name-marker');
        $this->assertTrue($r['noSecondReload'], 'Penanda di window.name juga harus mencegah reload kedua.');
        $this->assertTrue($r['failedShown'], 'Pesan gagal tetap harus muncul.');
    }
    public function testAuthorizationMarkerFollowsCurrentVideo(): void
    {
        $r = $this->runHarness('probe-403-key-follows-video');
        $this->assertTrue($r['noSecondReload'], 'Penanda harus mengikuti videoSrc saat transisi video.');
        $this->assertTrue($r['failedShown'], 'Video baru dengan token usang tidak boleh memicu reload beruntun.');
    }
    public function testPlaybackClearsBothMarkerStores(): void
    {
        $r = $this->runHarness('clear-marker-call');
        $this->assertTrue($r['storageCleared'], 'Penanda sessionStorage harus dihapus saat playback normal.');
        $this->assertTrue($r['nameCleared'], 'Penanda window.name juga harus dihapus tanpa merusak nilai lain.');
    }
    public function testBfcacheRestoreTearsDownStalePlayer(): void
    {
        $r = $this->runHarness('bfcache-teardown');

        $this->assertTrue($r['playerDestroyed'], 'Restore bfcache harus menghancurkan player lama sebelum reload.');
        $this->assertTrue($r['locationReloaded'], 'Restore bfcache tanpa mini-player harus memuat ulang halaman.');
    }
}
/* reference build: MEeL-C10H15N [video-loop-recovery-test] */