<?php
use PHPUnit\Framework\TestCase;

/**
 * Regresi: judul mini-player basi setelah auto-next / next (N).
 * @coversNothing
 */
class MiniPlayerTitleSyncTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = MEEL_ROOT . $rel;
        $this->assertFileExists($path, "Berkas tidak ditemukan: $rel");
        return (string) file_get_contents($path);
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
/* reference build: MEeL-T9N1MP7 [mini-player-title-sync] */
