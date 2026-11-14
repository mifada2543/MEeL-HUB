<?php
use PHPUnit\Framework\TestCase;
require_once MEEL_ROOT . '/modules/core/Uploader.php';
/** @covers Uploader */
class MusicTranscodePolicyTest extends TestCase
{
    private string $dir;
    private Uploader $uploader;
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/meel_music_policy_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/upload/file', 0755, true);
        $this->uploader = (new ReflectionClass(Uploader::class))->newInstanceWithoutConstructor();
        $this->property('user_role', 'user');
        $this->property('ffmpeg_bin', MEEL_ROOT . '/tools/ffmpeg-lite/ffmpeg');
        $this->property('ffprobe_bin', MEEL_ROOT . '/tools/ffmpeg-lite/ffprobe');
    }
    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }
    private function property(string $name, string $value): void
    {
        $prop = new ReflectionProperty(Uploader::class, $name);
        $prop->setAccessible(true);
        $prop->setValue($this->uploader, $value);
    }
    private function wav(string $name = 'track.wav'): string
    {
        $pcm = str_repeat("\0", 9600);
        $path = $this->dir . '/upload/file/' . $name;
        file_put_contents($path, 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, 1, 48000, 96000, 2, 16)
            . 'data' . pack('V', strlen($pcm)) . $pcm);
        return $path;
    }
    private function convert(string $path, array $post = []): string
    {
        $method = new ReflectionMethod(Uploader::class, 'maybeTranscodeToOgg');
        $method->setAccessible(true);
        return $method->invoke($this->uploader, $post, basename($path), $path, $this->dir . '/');
    }
    private function probe(string $path): array
    {
        exec(escapeshellarg(MEEL_ROOT . '/tools/ffmpeg-lite/ffprobe')
            . ' -v error -show_entries stream=codec_name -of json ' . escapeshellarg($path), $out, $exit);
        $this->assertSame(0, $exit);
        return json_decode(implode("\n", $out), true)['streams'];
    }
    /** @dataProvider rolesRequiringEncode */
    public function testOnlyAdminOptInCanKeepOriginal(string $role, array $post): void
    {
        $this->property('user_role', $role);
        $source = $this->wav();
        $result = $this->convert($source, $post);
        $this->assertSame('ogg', pathinfo($result, PATHINFO_EXTENSION));
        $this->assertSame([['codec_name' => 'opus']], $this->probe($this->dir . '/upload/file/' . $result));
        $this->assertFileDoesNotExist($source);
    }
    public static function rolesRequiringEncode(): array
    {
        return [['user', []], ['user', ['skip_transcode' => '1']],
            ['premium', ['skip_transcode' => '1']], ['admin', []]];
    }
    public function testAdminOptInPreservesOriginalBytes(): void
    {
        $this->property('user_role', 'admin');
        $source = $this->wav();
        $hash = hash_file('sha256', $source);
        $this->assertSame('track.wav', $this->convert($source, ['skip_transcode' => 'on']));
        $this->assertSame($hash, hash_file('sha256', $source));
    }
    /** @dataProvider opusExtensions */
    public function testVerifiedOpusIsPreservedWithoutEncoder(string $ext): void
    {
        $source = $this->wav();
        $encoded = $this->convert($source);
        $path = $this->dir . '/upload/file/' . $encoded;
        $renamed = $this->dir . '/upload/file/verified.' . $ext;
        rename($path, $renamed);
        $hash = hash_file('sha256', $renamed);
        $this->property('ffmpeg_bin', '/bin/false');
        $this->assertSame(basename($renamed), $this->convert($renamed));
        $this->assertSame($hash, hash_file('sha256', $renamed));
    }
    public static function opusExtensions(): array
    {
        return [['ogg'], ['opus']];
    }
    public function testRenamedWavCannotBypassEncodeAsOgg(): void
    {
        $source = $this->wav('disguised.ogg');
        $this->property('ffmpeg_bin', '/bin/false');
        $this->expectException(RuntimeException::class);
        $this->convert($source);
    }
    public function testFakeOpusHeaderCannotBypassProbe(): void
    {
        $path = $this->dir . '/upload/file/fake.ogg';
        file_put_contents($path, "OggS\0\x02" . str_repeat("\0", 20) . "\x01\x13"
            . 'OpusHead' . "\x01\x01" . pack('vVvC', 312, 48000, 0, 0));
        $this->property('ffmpeg_bin', '/bin/false');
        $this->expectException(RuntimeException::class);
        $this->convert($path);
    }
    public function testProbeFailureCannotAuthorizeOpusPassthrough(): void
    {
        $encoded = $this->convert($this->wav());
        $this->property('ffprobe_bin', '/bin/false');
        $this->property('ffmpeg_bin', '/bin/false');
        $this->expectException(RuntimeException::class);
        $this->convert($this->dir . '/upload/file/' . $encoded);
    }
    public function testNonOpusOutputWithSuccessfulExitIsRejected(): void
    {
        $encoder = $this->dir . '/wrong-encoder';
        file_put_contents($encoder, "#!/bin/sh\nwhile [ \"\$#\" -gt 0 ]; do\n"
            . "  if [ \"\$1\" = '-i' ]; then shift; input=\"\$1\"; fi\n"
            . "  output=\"\$1\"; shift\ndone\ncp \"\$input\" \"\$output\"\n");
        chmod($encoder, 0755);
        $this->property('ffmpeg_bin', $encoder);
        $source = $this->wav();
        $this->expectException(RuntimeException::class);
        try {
            $this->convert($source);
        } finally {
            $this->assertSame(['track.wav'], array_map('basename', glob($this->dir . '/upload/file/*')));
        }
    }
    public function testOggVorbisIsConvertedRatherThanKept(): void
    {
        if (!is_executable('/usr/bin/ffmpeg')) {
            $this->markTestSkipped('FFmpeg sistem diperlukan untuk fixture Vorbis.');
        }
        $source = $this->wav();
        $vorbis = $this->dir . '/upload/file/vorbis.ogg';
        exec('/usr/bin/ffmpeg -v error -y -i ' . escapeshellarg($source)
            . ' -c:a libvorbis ' . escapeshellarg($vorbis) . ' 2>&1', $out, $exit);
        $this->assertSame(0, $exit, implode("\n", $out));
        $result = $this->convert($vorbis);
        $this->assertNotSame('vorbis.ogg', $result);
        $this->assertFileDoesNotExist($vorbis);
        $this->assertSame([['codec_name' => 'opus']], $this->probe($this->dir . '/upload/file/' . $result));
    }
    /** @dataProvider failedEncoders */
    public function testFailedOrEmptyEncodeRejectsInsteadOfReturningOriginal(string $encoder): void
    {
        $this->property('ffmpeg_bin', $encoder);
        $source = $this->wav();
        $error = null;
        try {
            $this->convert($source);
        } catch (RuntimeException $e) {
            $error = $e;
        }
        $this->assertInstanceOf(RuntimeException::class, $error, 'Encode gagal/kosong tidak boleh menyimpan file asli.');
        $this->assertSame(['track.wav'], array_map('basename', glob($this->dir . '/upload/file/*')));
    }
    public static function failedEncoders(): array
    {
        return [['/bin/false'], ['/bin/true']];
    }
}
