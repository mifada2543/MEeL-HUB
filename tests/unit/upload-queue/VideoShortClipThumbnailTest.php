<?php
use PHPUnit\Framework\TestCase;
/**
 * @coversNothing
 */
class VideoShortClipThumbnailTest extends TestCase
{
    private string $dir;
    private Uploader $uploader;
    protected function setUp(): void
    {
        require_once MEEL_ROOT . '/modules/core/Uploader.php';
        $this->dir = MEEL_ROOT . '/temp/short_clip_' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir, 0755, true);
        $reflection = new ReflectionClass(Uploader::class);
        $this->uploader = $reflection->newInstanceWithoutConstructor();
        foreach (['base_dir' => $this->dir, 'ffmpeg_bin' => meel_ffmpeg_bin()] as $name => $value) {
            $reflection->getProperty($name)->setValue($this->uploader, $value);
        }
    }
    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }
    private function build(string $source): array
    {
        return (new ReflectionMethod(Uploader::class, 'buildVideoThumbnail'))
            ->invoke($this->uploader, [], 'clip', $this->dir, $source);
    }
    private function makeClip(string $duration): string
    {
        $clip = $this->dir . 'source.mp4';
        $output = [];
        $status = -1;
        exec(escapeshellarg(meel_ffmpeg_bin()) . ' -v error -y -f image2 -loop 1 -framerate 25 -i '
            . escapeshellarg(MEEL_ROOT . '/assets/img/video0.webp')
            . ' -t ' . escapeshellarg($duration)
            . ' -vf scale=320:240 -c:v mjpeg -pix_fmt yuvj420p ' . escapeshellarg($clip) . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        return $clip;
    }
    public function testShortClipRemovesFailedSeekStubAndBuildsValidVariants(): void
    {
        $clip = $this->makeClip('0.4');
        $destination = $this->dir . 'clip_thumb.webp';
        $this->assertFalse(meel_ffmpeg_thumbnail_webp(meel_ffmpeg_bin(), $clip, $destination, 1280, '-ss 00:00:05 -vframes 1', ''));
        clearstatcache(true, $destination);
        $this->assertSame(8, filesize($destination));
        $this->assertFalse(meel_is_valid_webp($destination));
        (new ReflectionMethod(Uploader::class, 'removeFile'))->invoke($this->uploader, $destination);
        $this->assertFileDoesNotExist($destination);
        $result = $this->build($clip);
        $this->assertSame('clip_thumb.webp', $result['thumb_name']);
        $this->assertFalse($result['from_user']);
        $this->assertTrue(meel_is_valid_webp($destination));
        $this->assertStringNotContainsString('ANIM', file_get_contents($destination));
        $this->assertStringNotContainsString('ANMF', file_get_contents($destination));
        $this->assertTrue(meel_is_valid_webp($this->dir . $result['sm_name']));
    }
    public function testNormalVideoStillBuildsValidVariants(): void
    {
        $result = $this->build($this->makeClip('6'));
        $this->assertSame('clip_thumb.webp', $result['thumb_name']);
        $this->assertTrue(meel_is_valid_webp($this->dir . $result['thumb_name']));
        $this->assertTrue(meel_is_valid_webp($this->dir . $result['sm_name']));
    }
    public function testExhaustedAttemptsRemoveStubsAndResolveExistingFallback(): void
    {
        $fallback = meel_default_thumbnail_path();
        $this->assertStringStartsWith(MEEL_ROOT . '/temp/media-test/', $fallback);
        $existed = is_file($fallback);
        try {
            $binary = $this->dir . 'ffmpeg-failure';
            file_put_contents($binary, '#!' . PHP_BINARY . "\n<?php\n"
                . '$destination = $argv[count($argv) - 1];' . "\n"
                . 'file_put_contents(__DIR__ . "/attempts", (is_file($destination) ? "ada" : "bersih") . "\\n", FILE_APPEND);' . "\n"
                . 'file_put_contents($destination, "RIFF\\0\\0\\0\\0");' . "\n");
            chmod($binary, 0755);
            (new ReflectionProperty(Uploader::class, 'ffmpeg_bin'))->setValue($this->uploader, $binary);
            file_put_contents($this->dir . 'clip_thumb.webp', "RIFF\0\0\0\0");
            file_put_contents($this->dir . 'clip_thumb_sm.webp', "RIFF\0\0\0\0");
            $result = $this->build($this->dir . 'missing.mp4');
            $this->assertFileDoesNotExist($this->dir . 'clip_thumb.webp');
            $this->assertFileDoesNotExist($this->dir . 'clip_thumb_sm.webp');
            $this->assertSame("ada\nbersih\nbersih\n", file_get_contents($this->dir . 'attempts'));
            $this->assertSame(meel_resolve_fallback_thumbnail(), $result['thumb_name']);
            if ($result['thumb_name'] !== '') {
                $this->assertFileExists($fallback);
                $this->assertTrue(meel_is_valid_webp($fallback));
            }
        } finally {
            if (!$existed && is_file($fallback)) {
                unlink($fallback);
            }
        }
    }
    public function testEmptyThumbnailDoesNotRejectPlayableHls(): void
    {
        file_put_contents($this->dir . 'clip.m3u8', "#EXTM3U\nclip_000.ts\n");
        file_put_contents($this->dir . 'clip_000.ts', 'segmen');
        $valid = (new ReflectionMethod(Uploader::class, 'verifyHlsArtifacts'))
            ->invoke($this->uploader, $this->dir, 'clip', $this->dir, '');
        $this->assertTrue($valid);
    }
}
/* reference build: MEeL-C10H16N [e8a420f1] */
