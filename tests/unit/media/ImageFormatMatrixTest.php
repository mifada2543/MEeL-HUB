<?php
use PHPUnit\Framework\TestCase;

/**
 * Matriks format gambar bersama: satu daftar format untuk upload maupun edit,
 * dan tidak ada lagi file .webp yang isinya byte PNG/JPEG asli.
 *
 * @coversNothing
 */
class ImageFormatMatrixTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/meel_img_' . bin2hex(random_bytes(4));
        if (!mkdir($this->dir, 0777, true) && !is_dir($this->dir)) {
            $this->fail('Gagal membuat direktori uji: ' . $this->dir);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function makeImage(string $format, int $w = 32, int $h = 32): string
    {
        $path = $this->dir . '/src_' . $format;
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 120, 40, 200));
        switch ($format) {
            case 'png':
                imagepng($im, $path);
                break;
            case 'jpg':
                imagejpeg($im, $path, 85);
                break;
            case 'bmp':
                imagebmp($im, $path);
                break;
            case 'webp':
                imagewebp($im, $path, 85);
                break;
            default:
                $this->fail('Format tidak dikenal: ' . $format);
        }
        imagedestroy($im);
        return $path;
    }

    /** ICO berisi satu PNG, seperti ikon Windows modern. */
    private function makeIco(): string
    {
        $png = $this->makeImage('png', 16, 16);
        $data = (string) file_get_contents($png);
        $info = getimagesize($png);
        $header = pack('vvv', 0, 1, 1)
            . pack('CCCCvvVV', $info[0], $info[1], 0, 0, 1, 32, strlen($data), 22);
        $ico = $this->dir . '/src.ico';
        file_put_contents($ico, $header . $data);
        return $ico;
    }

    public function testAllowlistCoversAllDocumentedFormats(): void
    {
        $allow = meel_image_mime_allowlist();
        foreach (['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon'] as $mime) {
            $this->assertContains($mime, $allow, 'matriks harus memuat ' . $mime);
        }
    }

    public function testSourceDetectionAcceptsIcoAndBmp(): void
    {
        $this->assertTrue(meel_image_source_ok($this->makeImage('png')));
        $this->assertTrue(meel_image_source_ok($this->makeImage('jpg')));
        $this->assertTrue(meel_image_source_ok($this->makeImage('bmp')));
        $this->assertTrue(meel_image_source_ok($this->makeIco()));

        $txt = $this->dir . '/catatan.txt';
        file_put_contents($txt, 'ini hanya teks, bukan gambar.');
        $this->assertFalse(meel_image_source_ok($txt));
    }

    public function testFakeWebpIsRejected(): void
    {
        $fake = $this->dir . '/palsu.webp';
        copy($this->makeImage('png'), $fake);
        $this->assertFalse(
            meel_is_valid_webp($fake),
            'file PNG bernama .webp tidak boleh dianggap WebP sah'
        );
    }

    public function testConversionProducesRealWebpForEachFormat(): void
    {
        $probe = $this->dir . '/probe.webp';
        if (!meel_convert_image_to_webp($this->makeImage('png'), $probe, 64, 64)) {
            $this->markTestSkipped('Tidak ada ffmpeg/ImageMagick yang bisa mengonversi gambar di mesin ini.');
        }

        $sources = [
            'png' => $this->makeImage('png'),
            'jpg' => $this->makeImage('jpg'),
            'bmp' => $this->makeImage('bmp'),
            'ico' => $this->makeIco(),
        ];
        foreach ($sources as $label => $src) {
            $dst = $this->dir . '/out_' . $label . '.webp';
            $ok = meel_convert_image_to_webp($src, $dst, 128, 128, false);
            $this->assertTrue($ok, $label . ' harus berhasil dikonversi ke WebP');
            $this->assertTrue(meel_is_valid_webp($dst), $label . ' harus menghasilkan WebP yang sah');
            $info = getimagesize($dst);
            $this->assertGreaterThan(0, (int) ($info[0] ?? 0), $label . ' harus punya lebar > 0');
            $this->assertGreaterThan(0, (int) ($info[1] ?? 0), $label . ' harus punya tinggi > 0');
        }
    }
}
