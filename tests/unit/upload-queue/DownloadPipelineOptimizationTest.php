<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class DownloadPipelineOptimizationTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = MEEL_ROOT . $rel;
        $this->assertFileExists($path, "Berkas tidak ditemukan: $rel");
        return (string) file_get_contents($path);
    }
    private function methodBody(string $src, string $method, int $length = 4000): string
    {
        $start = strpos($src, 'function ' . $method);
        $this->assertNotFalse($start, "function $method() tidak ditemukan");
        return substr($src, (int) $start, $length);
    }
    public function testMetadataCacheIsReadBeforeInvokingYtdlp(): void
    {
        $src = $this->read('/modules/transcoder/DownloadService.php');
        $body = $this->methodBody($src, 'fetchAndValidateMetadata');
        $cache = strpos($body, 'readCachedMetadata');
        $fetch = strpos($body, 'fetchMetadata(');
        $this->assertNotFalse($cache, 'fetchAndValidateMetadata() harus membaca cache lebih dulu');
        $this->assertNotFalse($fetch, 'fetchAndValidateMetadata() harus tetap memanggil fetchMetadata()');
        $this->assertLessThan($fetch, $cache, 'cache harus dibaca sebelum yt-dlp dijalankan');
        $this->assertStringContainsString(
            'assertWithinDownloadLimits',
            $body,
            'limit harus tetap dievaluasi untuk data dari cache'
        );
    }
    public function testMetadataCacheIsBoundedAndExpires(): void
    {
        $src = $this->read('/modules/transcoder/DownloadService.php');
        $this->assertMatchesRegularExpression(
            '/METADATA_CACHE_TTL\s*=\s*\d+/',
            $src,
            'cache metadata harus punya TTL eksplisit'
        );
        $this->assertMatchesRegularExpression(
            '/METADATA_CACHE_MAX_BYTES\s*=\s*\d+/',
            $src,
            'cache metadata harus dibatasi ukurannya'
        );
        $body = $this->methodBody($src, 'writeCachedMetadata');
        $this->assertStringContainsString('rename(', $body, 'penulisan cache harus atomik (tmp + rename)');
        $this->assertStringContainsString("\$dir, 0700", $body, 'direktori cache harus 0700');
    }
    public function testSinglePassIsOffByDefaultAndWritesInfoJson(): void
    {
        $src = $this->read('/modules/transcoder/DownloadService.php');
        $enabled = $this->methodBody($src, 'singlePassEnabled', 600);
        $this->assertStringContainsString("getenv('MEEL_SINGLE_PASS_DOWNLOAD')", $enabled);
        $this->assertStringContainsString('MEEL_SINGLE_PASS_DOWNLOAD', $enabled);
        $this->assertStringContainsString(
            '=== \'1\'',
            $enabled,
            'flag harus opt-in eksplisit, bukan default aktif'
        );
        $this->assertStringContainsString(
            '--write-info-json',
            $this->methodBody($src, 'buildDownloadContext', 5000),
            'single-pass harus meminta yt-dlp menulis .info.json'
        );
        $this->assertStringContainsString(
            'readDownloadInfoJson',
            $this->methodBody($src, 'processDownloadSinglePass', 5000),
            'single-pass harus membaca metadata dari .info.json'
        );
    }
    public function testSinglePassFallsBackToMetadataOnlyWhenInfoJsonMissing(): void
    {
        $body = $this->methodBody($this->read('/modules/transcoder/DownloadService.php'), 'processDownloadSinglePass', 5000);
        $this->assertStringContainsString(
            'fetchAndValidateMetadata',
            $body,
            'tanpa .info.json, unduhan yang sudah berhasil jangan digagalkan: pakai jalur metadata lama'
        );
    }
    public function testJsonIsNeverTreatedAsEncodableAudio(): void
    {
        $src = $this->read('/modules/transcoder/DownloadService.php');
        $body = $this->methodBody($src, 'finalizeMusic', 3000);
        $this->assertStringContainsString(
            "'json'",
            $body,
            'finalizeMusic() harus mengabaikan .info.json agar tidak dipilih sebagai file audio'
        );
    }
    public function testNoDebugLogOutsideProject(): void
    {
        $src = $this->read('/modules/transcoder/DownloadService.php');
        $this->assertStringNotContainsString(
            "file_put_contents('/tmp/",
            $src,
            'jangan menulis file apa pun ke /tmp (tumbuh tanpa batas, di luar proyek)'
        );
        $this->assertMatchesRegularExpression(
            '/error_log\(\s*\'\[MEeL\] DownloadService/',
            $src,
            'kegagalan unduhan harus dicatat lewat error_log() server'
        );
    }
    public function testStagedArtifactsUseDownloadStem(): void
    {
        $body = $this->methodBody($this->read('/modules/transcoder/DownloadService.php'), 'stagedArtifactsExist', 1200);
        $this->assertStringContainsString(
            "download_stem",
            $body,
            'pemeriksaan artefak harus memakai nama unduhan (stem), bukan basename hasil rename'
        );
    }
}
/* reference build: MEeL-T9N1DO3 [download-pipeline-optimization] */
