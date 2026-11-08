<?php
use PHPUnit\Framework\TestCase;
/**
 * @coversNothing
 */
class ShareModalPolishTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = MEEL_ROOT . $rel;
        $this->assertFileExists($path, "Berkas tidak ditemukan: $rel");
        return (string) file_get_contents($path);
    }
    public function testFiturContextMenuDihapusSesuaiPermintaan(): void
    {
        $this->assertFileDoesNotExist(
            MEEL_ROOT . '/assets/js/video/watch/context-menu.js',
            'context-menu.js harus dihapus (permintaan user).'
        );
    }
    public function testWatchMemilikiPreviewShare(): void
    {
        $src = $this->read('/video/watch.php');
        $this->assertStringContainsString(
            'meel-share-thumb',
            $src,
            'Modal Bagikan harus menampilkan preview thumbnail video.'
        );
        $this->assertStringContainsString(
            'meel-share-avatar',
            $src,
            'Modal Bagikan harus menampilkan avatar uploader.'
        );
        $this->assertStringContainsString(
            'uploaderPfp',
            $src,
            'playerConfig harus membawa uploaderPfp agar preview bisa disinkronkan.'
        );
    }
    public function testShareModalJsMenyinkronkanPreview(): void
    {
        $src = $this->read('/assets/js/shared/share-modal.js');
        $this->assertStringContainsString(
            'meel-share-thumb',
            $src,
            'share-modal.js harus mengisi preview thumbnail dari playerConfig.'
        );
    }
    public function testShareModalMendukungLightTheme(): void
    {
        $src = $this->read('/assets/css/shared/share-modal.css');
        $this->assertMatchesRegularExpression(
            '/\[\s*data-theme\s*=\s*["\']?light["\']?\s*\]/',
            $src,
            'share-modal.css harus punya override light theme [data-theme="light"].'
        );
    }
}
/* reference build: MEeL-T10N8KD8 [share-polish] */
