<?php
use PHPUnit\Framework\TestCase;

/**
 * Regresi: mini-player video lag + overlay reconnect merah terus-menerus
 * setelah kembali ke tab (pindah tab/workspace) dari background, dan judul
 * mini-player basi setelah auto-next / next (N).
 * @coversNothing
 */
class MiniPlayerRecoveryTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = MEEL_ROOT . $rel;
        $this->assertFileExists($path, "Berkas tidak ditemukan: $rel");
        return (string) file_get_contents($path);
    }
    public function testRecoveryResolvesWatchUrl(): void
    {
        $src = $this->read('/assets/js/video/watch/recovery.js');
        $this->assertStringContainsString(
            '__meelWatchUrl',
            $src,
            'Recovery harus memakai URL watch asli (__meelWatchUrl), bukan window.location.href '
            . 'yang saat mini-player menunjuk ke "beranda" (tanpa #main-video-wrapper).'
        );
    }
    public function testRecoverySkipsHtmxSwapInMiniPlayer(): void
    {
        $src = $this->read('/assets/js/video/watch/recovery.js');
        $this->assertStringContainsString(
            'isMiniPlayerActive',
            $src,
            'Recovery harus menyadari mini-player (isMiniPlayerActive) dan memakai '
            . 'soft-restart hls.startLoad alih-alih HTMX swap yang tidak pernah cocok di "beranda".'
        );
    }
    public function testRecoveryRetriesPausedWhenHidden(): void
    {
        $src = $this->read('/assets/js/video/watch/recovery.js');
        $this->assertStringContainsString(
            'document.hidden',
            $src,
            'Penjadwalan ulang recovery (retry offline) harus berhenti saat tab hidden '
            . 'agar tidak churn di background.'
        );
    }
    public function testPageshowDoesNotForceReloadInMiniPlayer(): void
    {
        $src = $this->read('/assets/js/video/watch/lifecycle.js');
        $this->assertStringContainsString('persisted', $src);
        $this->assertMatchesRegularExpression(
            '/pageshow[\s\S]{0,600}(mini-player-shell|isMiniPlayerActive)/',
            $src,
            'pageshow persisted tidak boleh memaksa location.reload() saat mini-player aktif; '
            . 'harus resume in-place.'
        );
    }
    public function testServiceWorkerReloadIsDeferredWhilePlayingOrHidden(): void
    {
        $src = $this->read('/partials/head.php');
        $this->assertStringContainsString('controllerchange', $src);
        $this->assertStringContainsString(
            'document.hidden',
            $src,
            'Reload Service Worker harus ditunda saat tab hidden.'
        );
        $this->assertMatchesRegularExpression(
            '/controllerchange[\s\S]{0,800}paused/',
            $src,
            'Reload Service Worker harus ditunda saat video sedang diputar (paused check).'
        );
    }
    public function testMiniPlayerPublishesWatchUrl(): void
    {
        $src = $this->read('/assets/js/video/watch/mini-player.js');
        $this->assertStringContainsString(
            '__meelWatchUrl',
            $src,
            'Mini-player harus mempublikasikan URL watch asli ke window.__meelWatchUrl '
            . 'agar recovery tidak memakai URL "beranda".'
        );
    }

    public function testSeamlessNextAlwaysSyncsMiniInfo(): void
    {
        $src = $this->read('/assets/js/video/watch/player-events.js');
        $this->assertStringContainsString(
            'updateMiniPlayerInfo(videoTitle, videoUploader)',
            $src,
            'skipToNextVideo wajib menyegarkan info mini-player.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/isMiniPlayerActive\s*&&\s*updateMiniPlayerInfo\s*\(/',
            $src,
            'Update info mini-player tidak boleh di-gate isMiniPlayerActive: shell yang '
            . 'sedang di-hide tetap harus ikut diperbarui saat video berganti di mode penuh.'
        );
    }

    public function testMiniEntryRefreshesCachedShellInfo(): void
    {
        $src = $this->read('/assets/js/video/watch/mini-player.js');
        $this->assertMatchesRegularExpression(
            '/getMiniShell\(\);[\s\S]{0,400}updateMiniPlayerInfo\(videoTitle, videoUploader\)/',
            $src,
            'Saat mini-player dibuka, info shell yang di-cache harus disegarkan dari '
            . 'videoTitle/videoUploader, bukan hanya dari buildMiniInfo() saat shell dibuat.'
        );
    }

    public function testMiniInfoWriterIsIdempotent(): void
    {
        $src = $this->read('/assets/js/video/watch/mini-player.js');
        $this->assertMatchesRegularExpression(
            '/function updateMiniPlayerInfo\([\s\S]{0,500}getElementById\("mini-info-title"\)/',
            $src,
            'updateMiniPlayerInfo harus tetap aman dipanggil walau shell belum ada.'
        );
    }
}
/* reference build: MEeL-T7N8KD5 [mini-player-recovery] */
