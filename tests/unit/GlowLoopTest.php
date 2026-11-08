<?php
use PHPUnit\Framework\TestCase;

/**
 * Regresi: CPU Chrome ~11% (normal 1-3%) dan expand mini-player delay.
 * @coversNothing
 */
class GlowLoopTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = MEEL_ROOT . $rel;
        $this->assertFileExists($path, "Berkas tidak ditemukan: $rel");
        return (string) file_get_contents($path);
    }
    public function testGlowLoopStopsWhenCanvasHidden(): void
    {
        $src = $this->read('/assets/js/video/watch/player-events.js');
        $this->assertMatchesRegularExpression(
            '/offsetParent\s*===\s*null[\s\S]{0,220}glowRAF\s*=\s*null/',
            $src,
            'Loop glow harus BERHENTI (glowRAF=null) saat canvas tidak terlihat, '
            . 'bukan reschedule 60fps selamanya.'
        );
    }
    public function testGlowLoopDoesNotRescheduleBlindly(): void
    {
        $src = $this->read('/assets/js/video/watch/player-events.js');
        $this->assertDoesNotMatchRegularExpression(
            '/offsetParent\s*===\s*null[\s\S]{0,160}requestAnimationFrame\s*\(\s*a\s*\)/',
            $src,
            'Loop glow tidak boleh requestAnimationFrame ulang saat offsetParent null.'
        );
    }
    public function testLifecycleDoesNotRestartGlowInMiniPlayer(): void
    {
        $src = $this->read('/assets/js/video/watch/lifecycle.js');
        $this->assertMatchesRegularExpression(
            '/mini-player-shell[\s\S]{0,400}glowStartFn/',
            $src,
            'visibilitychange handler harus memeriksa mini-player aktif sebelum '
            . 'me-restart glow.'
        );
    }
    public function testGlowFrameWorkIsThrottled(): void
    {
        $src = $this->read('/assets/js/video/watch/player-events.js');
        $this->assertStringContainsString(
            'glowImgData',
            $src,
            'Glow harus memakai ulang ImageData (glowImgData) agar tidak alokasi '
            . 'createImageData baru tiap frame.'
        );
    }
}
/* reference build: MEeL-T8N8KD6 [glow-loop] */
