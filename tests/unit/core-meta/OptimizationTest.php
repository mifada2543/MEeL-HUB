<?php
use PHPUnit\Framework\TestCase;

/**
 * Regresi: optimisasi (precache, bundle, buffer mode, polling) tidak diterapkan di mini-player / tab hidden.
 * @coversNothing
 */
class OptimizationTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = MEEL_ROOT . $rel;
        $this->assertFileExists($path, "Berkas tidak ditemukan: $rel");
        return (string) file_get_contents($path);
    }
    public function testBufferModeApiExists(): void
    {
        $src = $this->read('/assets/js/video/watch/state.js');
        $this->assertStringContainsString('__meelSetBufferMode', $src);
        $this->assertStringContainsString('maxBufferLength', $src);
        $this->assertMatchesRegularExpression(
            '/\?\s*15\s*:\s*45/',
            $src,
            'Buffer adaptif harus menurunkan ke 15 (mini/hidden) dari 45.'
        );
    }
    public function testMiniPlayerSwitchesBufferMode(): void
    {
        $src = $this->read('/assets/js/video/watch/mini-player.js');
        $this->assertStringContainsString(
            '__meelSetBufferMode',
            $src,
            'Mini-player harus memanggil __meelSetBufferMode(true) saat masuk dan '
            . '__meelSetBufferMode(false) saat expand/close.'
        );
        $this->assertMatchesRegularExpression(
            '/__meelSetBufferMode\(\s*(!0|true)\s*\)/',
            $src,
            'Mini-player harus memanggil __meelSetBufferMode(true) saat masuk mini.'
        );
        $this->assertMatchesRegularExpression(
            '/__meelSetBufferMode\(\s*(!1|false)\s*\)/',
            $src,
            'Mini-player harus memanggil __meelSetBufferMode(false) saat expand/close.'
        );
    }
    public function testUpscalerSkipsInMiniPlayer(): void
    {
        $src = $this->read('/assets/js/video/watch/upscaler.js');
        $this->assertStringContainsString(
            'isMiniPlayerActive',
            $src,
            'Upscaler harus skip loop saat mini-player aktif.'
        );
    }
    public function testPollingGuardsHiddenTab(): void
    {
        $notif = $this->read('/assets/js/shared/notification.js');
        $this->assertStringContainsString(
            'document.hidden',
            $notif,
            'Polling notifikasi harus jeda saat tab hidden.'
        );
    }
    public function testWatchLoaderLoadsSourcesInOrder(): void
    {
        $src = $this->read('/assets/js/video/watch/main.js');
        $this->assertDoesNotMatchRegularExpression(
            '/main\.bundle\.js/',
            $src,
            'Loader tidak boleh lagi memuat bundle; source of truth adalah 11 file sumber.'
        );
        $expected = [
            '"state.js"', '"recovery.js"', '"player-init.js"', '"player-events.js"',
            '"lifecycle.js"', '"mini-player.js"', '"gestures.js"', '"vtt-sprites.js"',
            '"seek-indicator.js"', '"misc.js"', '"upscaler.js"',
        ];
        $pos = -1;
        foreach ($expected as $file) {
            $next = strpos($src, $file);
            $this->assertNotFalse($next, "File sumber $file hilang dari loader.");
            $this->assertGreaterThan(
                $pos,
                $next,
                "Urutan loader salah di $file (harus berurutan sesuai daftar)."
            );
            $pos = $next;
        }
    }
    public function testSwPrecacheTrimmed(): void
    {
        $src = $this->read('/modules/core/SwPrecache.php');
        $this->assertStringNotContainsString(
            "'assets/js/compatibilitas/hls.js'",
            $src,
            'hls.js (hanya watch) tidak boleh di-precache.'
        );
        $this->assertStringNotContainsString(
            "'assets/css/introduction.css'",
            $src,
            'CSS hanya-introduction tidak boleh di-precache.'
        );
        $this->assertStringContainsString(
            "err/offline.php",
            $src,
            'Página offline harus tetap di-precache.'
        );
    }
    public function testTempIndexSessionCache(): void
    {
        $src = $this->read('/assets/js/shared/temp-index.js');
        $this->assertStringContainsString(
            'meel:temp-index:html:v1',
            $src,
            'Temp-index harus cache HTML beranda di sessionStorage.'
        );
        $this->assertStringContainsString(
            'sessionStorage',
            $src
        );
    }
    public function testNoCommittedBundleFile(): void
    {
        $this->assertFileDoesNotExist(
            MEEL_ROOT . '/assets/js/video/watch/main.bundle.js',
            'main.bundle.js tidak boleh di-commit (sumber adalah 11 file).'
        );
    }
}

/* reference build: MEeL-T9N8KD7 [optimization] */
