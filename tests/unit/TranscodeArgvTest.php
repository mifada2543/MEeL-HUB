<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/autoload.php';

/**
 * Mengikat perilaku perakitan argumen FFmpeg di `TranscodeService`.
 *
 * Builders argumen FFmpeg dulu hidup di dalam `transcodeVideo()` dan tidak
 * pernah diuji. Saat fungsi itu dipecah, penyimpangan kecil tidak terlihat
 * di gerbang: `use_thumb` hanya diinisialisasi di cabang `ogg`, sehingga
 * `mp3`/`m4a`/`flac` kehilangan thumbnail dan PHP melempar "Undefined
 * variable" tanpa error yang terlihat karena DisplayErrors dimatikan di
 * produksi.
 *
 * Test ini memeriksa INVARIANT, bukan menyalin implementasi.
 *
 * @covers TranscodeService
 */
class TranscodeArgvTest extends TestCase
{
    private $service;
    private $thumbDir;

    protected function setUp(): void
    {
        $this->service = (new ReflectionClass(TranscodeService::class))->newInstanceWithoutConstructor();

        $binary = new ReflectionProperty(TranscoderBase::class, 'ffmpeg_bin');
        $binary->setAccessible(true);
        $binary->setValue($this->service, '/usr/bin/ffmpeg');

        $this->thumbDir = sys_get_temp_dir() . '/meel_argv_' . bin2hex(random_bytes(6));
        mkdir($this->thumbDir . '/thumbnail', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->thumbDir . '/thumbnail/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->thumbDir . '/thumbnail');
        @rmdir($this->thumbDir);
    }

    private function argv(string $format, array $video): array
    {
        $resolve = new ReflectionMethod(TranscodeService::class, 'resolveAudioFormat');
        $resolve->setAccessible(true);
        $build = new ReflectionMethod(TranscodeService::class, 'buildFfmpegArgv');
        $build->setAccessible(true);

        $audio = $resolve->invoke($this->service, $format, $this->thumbDir . '/', $video);

        return $build->invoke($this->service, $audio, '/shm/concat.txt', '/shm/out.bin', $format);
    }

    private function valueAfter(array $argv, string $flag): ?string
    {
        $i = array_search($flag, $argv, true);

        return $i === false ? null : ($argv[$i + 1] ?? null);
    }

    private function hasFlag(array $argv, string $flag): bool
    {
        return in_array($flag, $argv, true);
    }

    private function valuesAfter(array $argv, string $flag): array
    {
        $found = [];
        foreach ($argv as $i => $token) {
            if ($token === $flag) {
                $found[] = $argv[$i + 1] ?? '';
            }
        }

        return $found;
    }

    private function mappedStreams(array $argv): array
    {
        return $this->valuesAfter($argv, '-map');
    }

    private function video(array $overrides = []): array
    {
        return $overrides + [
            'thumbnail' => 't.jpg',
            'title' => 'Laguku',
            'username' => 'Uploader',
            'upload_date' => '2026-01-15 10:00:00',
            'description' => 'Deskripsi',
        ];
    }

    public function testExistingThumbnailIsAttachedForEveryFormatExceptOgg(): void
    {
        file_put_contents($this->thumbDir . '/thumbnail/t.jpg', 'x');

        foreach (['mp3', 'm4a', 'flac', 'wav'] as $format) {
            $argv = $this->argv($format, $this->video());

            $this->assertSame(
                ['0:a', '1:v'],
                $this->mappedStreams($argv),
                "{$format} harus memetakan audio 0 dan thumbnail sebagai video 1"
            );
            $this->assertTrue($this->hasFlag($argv, '-disposition:v:0'), "{$format} harus menandai thumbnail sebagai attached_pic");
            $this->assertContains(
                $this->thumbDir . '/thumbnail/t.jpg',
                $this->valuesAfter($argv, '-i'),
                "{$format} harus menyertakan berkas thumbnail"
            );
            $this->assertContains(
                '/shm/concat.txt',
                $this->valuesAfter($argv, '-i'),
                "{$format} harus tetap membaca concat list"
            );
        }
    }

    public function testOggNeverAttachesThumbnail(): void
    {
        file_put_contents($this->thumbDir . '/thumbnail/t.jpg', 'x');

        $argv = $this->argv('ogg', $this->video());

        $this->assertFalse($this->hasFlag($argv, '-disposition:v:0'), 'ogg tidak boleh memakai thumbnail');
        $this->assertSame(['0:a'], $this->mappedStreams($argv));
    }

    public function testMissingThumbnailFileDisablesImageWithoutDroppingAudio(): void
    {
        $argv = $this->argv('mp3', $this->video());

        $this->assertFalse($this->hasFlag($argv, '-disposition:v:0'), 'thumbnail tidak ada di disk');
        $this->assertSame(['0:a'], $this->mappedStreams($argv), 'audio tetap dipetakan');
    }

    public function testEmptyThumbnailNameDisablesImage(): void
    {
        file_put_contents($this->thumbDir . '/thumbnail/t.jpg', 'x');

        $argv = $this->argv('mp3', $this->video(['thumbnail' => '']));

        $this->assertFalse($this->hasFlag($argv, '-disposition:v:0'), 'nama thumbnail kosong');
    }

    public function testCodecAndBitratePerFormat(): void
    {
        file_put_contents($this->thumbDir . '/thumbnail/t.jpg', 'x');

        $expected = [
            'mp3' => ['libmp3lame', ['-q:a', '2']],
            'ogg' => ['libopus', ['-b:a', '128k', '-vbr', 'on']],
            'm4a' => ['copy', []],
            'flac' => ['libmp3lame', ['-q:a', '2']],
        ];

        $bitrateFlags = ['-q:a', '-b:a', '-vbr'];

        foreach ($expected as $format => [$codec, $bitrate]) {
            $argv = $this->argv($format, $this->video());

            $this->assertSame($codec, $this->valueAfter($argv, '-c:a'), "codec untuk {$format}");

            foreach ($bitrate as $flag) {
                $this->assertTrue($this->hasFlag($argv, $flag), "{$format} harus memuat {$flag}");
            }

            foreach ($bitrateFlags as $flag) {
                if (!in_array($flag, $bitrate, true)) {
                    $this->assertFalse($this->hasFlag($argv, $flag), "{$format} tidak boleh memuat {$flag}");
                }
            }
        }
    }

    public function testAttachedPictureIsReencodedToMjpegNotCopied(): void
    {
        file_put_contents($this->thumbDir . '/thumbnail/t.jpg', 'x');

        $argv = $this->argv('mp3', $this->video());

        $this->assertSame('mjpeg', $this->valueAfter($argv, '-c:v'));
        $this->assertFalse(
            in_array('copy', $argv, true),
            'thumbnail harus di-encode ulang ke mjpeg; builder string yang lama memakai -c:v copy'
        );
    }

    public function testId3VersionOnlyForMp3WithThumbnail(): void
    {
        file_put_contents($this->thumbDir . '/thumbnail/t.jpg', 'x');

        $this->assertSame('3', $this->valueAfter($this->argv('mp3', $this->video()), '-id3v2_version'));

        foreach (['m4a', 'flac'] as $format) {
            $this->assertFalse(
                $this->hasFlag($this->argv($format, $this->video()), '-id3v2_version'),
                "-id3v2_version hanya untuk mp3, bukan {$format}"
            );
        }
    }

    public function testMetadataKeysKeepStableOrder(): void
    {
        file_put_contents($this->thumbDir . '/thumbnail/t.jpg', 'x');

        $argv = $this->argv('mp3', $this->video());

        $keys = [];
        foreach ($argv as $i => $token) {
            if ($token === '-metadata') {
                $keys[] = explode('=', $argv[$i + 1] ?? '', 2)[0];
            }
        }

        $this->assertSame(
            ['title', 'artist', 'album', 'date', 'comment', 'album_artist'],
            $keys
        );
    }

    public function testMetadataFallsBackWhenFieldsAreMissing(): void
    {
        $argv = $this->argv('mp3', ['title' => 'Tanpa Metadata', 'thumbnail' => '']);

        $meta = [];
        foreach ($argv as $i => $token) {
            if ($token === '-metadata') {
                $meta[explode('=', $argv[$i + 1] ?? '', 2)[0]] = $argv[$i + 1] ?? '';
            }
        }

        $this->assertSame('title=Tanpa Metadata', $meta['title']);
        $this->assertSame('artist=MEeL Transcoder', $meta['artist']);
        $this->assertSame('comment=Transcoded by MEeL', $meta['comment']);
    }

    public function testOutputPathIsAlwaysLastArgument(): void
    {
        $argv = $this->argv('mp3', $this->video());

        $this->assertSame('/shm/out.bin', end($argv));
    }

    public function testDescriptionIsTruncatedTo256Bytes(): void
    {
        $argv = $this->argv('mp3', $this->video(['description' => str_repeat('z', 900)]));

        foreach ($argv as $i => $token) {
            if ($token === '-metadata' && str_starts_with($argv[$i + 1] ?? '', 'comment=')) {
                $this->assertSame(256, mb_strlen(substr($argv[$i + 1], strlen('comment='))));
                return;
            }
        }

        $this->fail('metadata comment tidak ditemukan');
    }

    public function testConcatListEscapesSingleQuotes(): void
    {
        $method = new ReflectionMethod(TranscodeService::class, 'writeConcatList');
        $method->setAccessible(true);
        $outDir = $this->thumbDir . '/';
        $segments = ["/hdd/seg'1.ts", '/hdd/seg 2.ts', '/hdd/seg`x`$(y).ts'];

        $path = $method->invoke($this->service, $segments, $outDir, 42);
        $content = (string) file_get_contents($path);
        @unlink($path);

        $this->assertSame(
            "file '/hdd/seg'\\''1.ts'\nfile '/hdd/seg 2.ts'\nfile '/hdd/seg`x`\$(y).ts'\n",
            $content
        );
        $this->assertMatchesRegularExpression('/^concat_42_\d+\.txt$/', basename($path));
    }

    public function testTranscodeEnvPinsLibraryPathAndLocale(): void
    {
        $envMethod = new ReflectionMethod(TranscodeService::class, 'transcodeEnv');
        $envMethod->setAccessible(true);
        $env = $envMethod->invoke($this->service);

        $libPath = (new ReflectionClassConstant(TranscoderBase::class, 'FFMPEG_LIB_PATH'))->getValue();
        $this->assertSame($libPath, $env['LD_LIBRARY_PATH']);
        $this->assertSame('/usr/local/bin:/usr/bin:/bin', $env['PATH']);
        $this->assertSame('en_US.UTF-8', $env['LC_ALL']);
    }
}