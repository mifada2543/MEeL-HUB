<?php
use PHPUnit\Framework\TestCase;
require_once MEEL_ROOT . '/modules/transcoder/DownloadService.php';
/**
 * @coversNothing
 */
class VideoThumbnailFallbackTest extends TestCase
{
    private string $workDir;
    private bool $fallbackExisted;
    protected function setUp(): void
    {
        parent::setUp();
        $this->fallbackExisted = is_file(meel_default_thumbnail_path());
        $this->workDir = MEEL_ROOT . '/temp/thumb_fallback_' . bin2hex(random_bytes(4));
        if (!mkdir($this->workDir, 0777, true) && !is_dir($this->workDir)) {
            $this->fail('Gagal membuat direktori uji: ' . $this->workDir);
        }
    }
    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
        if (!$this->fallbackExisted) {
            @unlink(meel_default_thumbnail_path());
        }
        parent::tearDown();
    }
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
    private function serviceSource(): string
    {
        return (string) file_get_contents(MEEL_ROOT . '/modules/transcoder/DownloadService.php');
    }
    private function service(?string $ffmpeg = null): DownloadService
    {
        $service = (new ReflectionClass(DownloadService::class))->newInstanceWithoutConstructor();
        $binary = new ReflectionProperty(TranscoderBase::class, 'ffmpeg_bin');
        $binary->setAccessible(true);
        $binary->setValue($service, $ffmpeg ?? meel_ffmpeg_bin());
        return $service;
    }
    /**
     * @return array{db_thumb:string,generated:bool}
     */
    private function stageThumbnail(?string $src, string $workFolder, string $name, string $stagedVideo, ?string $ffmpeg = null): array
    {
        $method = new ReflectionMethod(DownloadService::class, 'stageThumbnail');
        $method->setAccessible(true);
        return $method->invoke($this->service($ffmpeg), $src, $workFolder, $name, $stagedVideo);
    }
    private function buildJpeg(): string
    {
        $path = $this->workDir . '/sumber.jpg';
        $output = [];
        $code = 1;
        exec(escapeshellarg(meel_ffmpeg_bin()) . ' -v error -y -i '
            . escapeshellarg(MEEL_ROOT . '/assets/img/video0.webp')
            . ' -vf scale=32:32 -frames:v 1 -c:v mjpeg '
            . escapeshellarg($path) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame('image/jpeg', getimagesize($path)['mime']);
        return $path;
    }
    public function testFailedJpegConversionNeverPublishesRawBytes(): void
    {
        $image = $this->buildJpeg();
        $result = $this->stageThumbnail($image, $this->workDir . '/', 'hasil.webp', '', '/bin/false');
        $this->assertFalse($result['generated']);
        $this->assertFileDoesNotExist($this->workDir . '/hasil.webp');
        $this->assertFileDoesNotExist($image);
        $this->assertSame(meel_default_thumbnail_name(), $result['db_thumb']);
        $this->assertTrue(is_file(meel_default_thumbnail_path()));
        $this->assertTrue(meel_is_valid_webp(meel_default_thumbnail_path()));
    }
    public function testServiceConvertsValidJpegToWebp(): void
    {
        $image = $this->buildJpeg();
        $result = $this->stageThumbnail($image, $this->workDir . '/', 'hasil.webp', '');
        $this->assertSame(['db_thumb' => 'hasil.webp', 'generated' => true], $result);
        $this->assertTrue(meel_is_valid_webp($this->workDir . '/hasil.webp'));
        $this->assertSame('image/webp', getimagesize($this->workDir . '/hasil.webp')['mime']);
        $this->assertFileDoesNotExist($image);
    }
    private function buildShortClip(): string
    {
        $clip = $this->workDir . '/klip.mp4';
        // image2 + MJPEG tersedia di bundled lite; lavfi/libx264 tidak.
        $cmd = escapeshellarg(meel_ffmpeg_bin())
            . ' -v error -y -f image2 -loop 1 -framerate 25 -i '
            . escapeshellarg(MEEL_ROOT . '/assets/img/video0.webp')
            . ' -t 0.4 -vf scale=320:240 -c:v mjpeg -pix_fmt yuvj420p '
            . escapeshellarg($clip)
            . ' 2>&1';
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertFileExists($clip);
        return $clip;
    }
    public function testDownloaderNoLongerCopiesRawBytesIntoWebp(): void
    {
        $src = $this->serviceSource();
        $this->assertStringNotContainsString(
            'copy($dl_thumb_src',
            $src,
            'byte mentah gambar tidak boleh disalin ke berkas .webp'
        );
        $this->assertStringNotContainsString(
            '$db_thumb = "default_thumb.webp";',
            $src,
            'nama fallback tidak boleh ditulis mati di source'
        );
        $this->assertStringContainsString(
            'meel_resolve_fallback_thumbnail()',
            $src,
            'fallback harus lewat helper yang memverifikasi berkas fallback benar-benar ada'
        );
    }
    public function testSuccessCheckUsesWebpValidationNotFileSize(): void
    {
        $src = $this->serviceSource();
        $this->assertStringContainsString(
            'meel_is_valid_webp($work_thumb)',
            $src,
            'sukses thumbnail harus divalidasi sebagai WebP'
        );
        $this->assertStringNotContainsString(
            'filesize($work_thumb) > 0',
            $src,
            'stub 8 byte dari ffmpeg tidak boleh dianggap thumbnail yang sah'
        );
    }
    public function testFailedConversionLeavesNoFileAtDestination(): void
    {
        $bad = $this->workDir . '/rusak.jpg';
        file_put_contents($bad, 'ini bukan gambar sama sekali');
        $dst = $this->workDir . '/gagal.webp';
        file_put_contents($dst, "RIFF\0\0\0\0");
        $this->assertFalse(
            meel_ffmpeg_thumbnail_webp(meel_ffmpeg_bin(), $bad, $dst, 1280, '', ''),
            'konversi berkas rusak harus gagal'
        );
        $result = $this->stageThumbnail($bad, $this->workDir . '/', 'gagal.webp', '');
        $this->assertFalse($result['generated'], 'tidak ada thumbnail yang bisa dipakai');
        $this->assertFileDoesNotExist(
            $this->workDir . '/gagal.webp',
            'percobaan yang gagal tidak boleh meninggalkan berkas di tujuan'
        );
        $this->assertContains(
            $result['db_thumb'],
            ['', meel_default_thumbnail_name()],
            'fallback hanya boleh memakai nama default atau string kosong'
        );
    }
    public function testShortClipYieldsThumbnailAtTimeZero(): void
    {
        $clip = $this->buildShortClip();
        $zero = $this->workDir . '/nol.webp';
        $this->assertTrue(
            meel_ffmpeg_thumbnail_webp(meel_ffmpeg_bin(), $clip, $zero, 1280, '-ss 00:00:00 -vframes 1', ''),
            'ekstraksi pada detik 0 harus berhasil untuk klip pendek'
        );
        $this->assertTrue(
            meel_is_valid_webp($zero),
            'hasil ekstraksi harus WebP yang sah, bukan stub'
        );
        foreach (['-ss 00:00:05 -vframes 1', '-ss 00:00:01 -vframes 1'] as $seek) {
            $stub = $this->workDir . '/stub.webp';
            $this->assertFalse(
                meel_ffmpeg_thumbnail_webp(meel_ffmpeg_bin(), $clip, $stub, 1280, $seek, ''),
                'seek "' . $seek . '" harus gagal pada klip 0,4 detik'
            );
        }
    }
    public function testServiceExtractsFrameFromShortClipWhenThumbnailImageFails(): void
    {
        $clip = $this->buildShortClip();
        $bad = $this->workDir . '/rusak.jpg';
        file_put_contents($bad, 'bukan gambar');
        $result = $this->stageThumbnail($bad, $this->workDir . '/', 'klip.webp', $clip);
        $this->assertFileDoesNotExist($bad);
        $bytes = (string) file_get_contents($this->workDir . '/klip.webp');
        $this->assertStringNotContainsString('ANIM', $bytes, 'thumbnail harus satu frame, bukan animasi');
        $this->assertTrue($result['generated'], 'frame dari MP4 staging harus jadi thumbnail');
        $this->assertSame('klip.webp', $result['db_thumb'], 'nama thumbnail hasil ekstraksi harus dipertahankan');
        $this->assertFileExists($this->workDir . '/klip.webp');
        $this->assertTrue(
            meel_is_valid_webp($this->workDir . '/klip.webp'),
            'thumbnail hasil ekstraksi harus WebP yang sah, bukan stub 8 byte'
        );
        $this->assertGreaterThan(
            16,
            (int) filesize($this->workDir . '/klip.webp'),
            'stub 8 byte tidak boleh lolos sebagai thumbnail'
        );
    }
    public function testServiceAcceptsConvertedImageWithoutVideo(): void
    {
        $image = $this->workDir . '/sumber.webp';
        copy(meel_default_thumbnail_asset(), $image);
        $result = $this->stageThumbnail($image, $this->workDir . '/', 'hasil.webp', '');
        $this->assertSame(['db_thumb' => 'hasil.webp', 'generated' => true], $result);
        $this->assertTrue(meel_is_valid_webp($this->workDir . '/hasil.webp'));
        $this->assertFileDoesNotExist($image);
    }
    public function testServiceRemovesInvalidOutputWithoutAvailableSources(): void
    {
        $dst = $this->workDir . '/sisa.webp';
        file_put_contents($dst, "RIFF\0\0\0\0");
        $result = $this->stageThumbnail(null, $this->workDir . '/', 'sisa.webp', $this->workDir . '/tidak-ada.mp4');
        $this->assertFalse($result['generated']);
        $this->assertFileDoesNotExist($dst);
        if ($result['db_thumb'] !== '') {
            $this->assertTrue(meel_is_valid_webp(meel_default_thumbnail_path()));
        }
    }
    public function testFallbackNameAlwaysResolvesToExistingFile(): void
    {
        $name = meel_resolve_fallback_thumbnail();
        if ($name === '') {
            $this->assertFalse(
                meel_is_valid_webp(meel_default_thumbnail_path()),
                'nama fallback kosong hanya sah kalau memang tidak ada WebP fallback yang valid'
            );
            return;
        }
        $this->assertSame(
            meel_default_thumbnail_name(),
            $name,
            'nama fallback harus sama dengan nama default'
        );
        $this->assertFileExists(
            meel_default_thumbnail_path(),
            'nama fallback tidak boleh menunjuk ke berkas yang tidak ada'
        );
        $this->assertTrue(
            meel_is_valid_webp(meel_default_thumbnail_path()),
            'berkas fallback harus WebP yang sah'
        );
    }
    public function testEmptyThumbnailNameNeverTargetsThumbnailDirectory(): void
    {
        $method = new ReflectionMethod(DownloadService::class, 'safeCleanupPaths');
        $method->setAccessible(true);
        $paths = $method->invoke($this->service(), [
            MEEL_HDD_THUMB_DIR,
            MEEL_HDD_THUMB_DIR . 'uji.webp',
            '',
        ]);
        $this->assertNotContains(
            MEEL_HDD_THUMB_DIR,
            $paths,
            'nama thumbnail kosong tidak boleh membuat jalur cleanup menunjuk ke direktori thumbnail'
        );
        $this->assertNotContains('', $paths, 'jalur kosong tidak boleh masuk daftar cleanup');
        $this->assertContains(MEEL_HDD_THUMB_DIR . 'uji.webp', $paths);
    }
    public function testPersistedThumbnailPathsAreGuarded(): void
    {
        $src = $this->serviceSource();
        $this->assertStringContainsString(
            'safeCleanupPaths',
            $src,
            'pendaftaran jalur cleanup harus melewati penyaring direktori'
        );
        $this->assertStringContainsString(
            "!meel_is_default_thumbnail(\$db_thumb)",
            $src,
            'rollback tidak boleh menghapus berkas fallback yang sudah disemai'
        );
        $this->assertStringContainsString(
            "\$db_thumb !== ''",
            $src,
            'nama thumbnail kosong harus dicekal sebelum digabung dengan path storage'
        );
    }
}
/* reference build: MEeL-C10H16N [7b41c0de] */
