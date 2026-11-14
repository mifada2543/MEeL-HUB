<?php
use PHPUnit\Framework\TestCase;

/** @covers Uploader */
class UploadThumbnailNamingTest extends TestCase
{
    private string $dir;
    private string $url;
    private $server;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/meel_thumb_names_' . bin2hex(random_bytes(6));
        foreach (['music/upload/thumbnail', 'video/thumbnail', 'work'] as $subdir) {
            mkdir($this->dir . '/' . $subdir, 0755, true);
        }
        $router = <<<'PHP'
<?php
if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo 'ready'; return; }
require_once PROJECT_ROOT . '/modules/core/Uploader.php';
$uploader = (new ReflectionClass(Uploader::class))->newInstanceWithoutConstructor();
foreach (['base_dir' => __DIR__ . '/video/', 'ffmpeg_bin' => ($_POST['fail'] ?? '') === '1' ? '/bin/false' : PROJECT_ROOT . '/tools/ffmpeg-lite/ffmpeg'] as $key => $value) {
    $prop = new ReflectionProperty(Uploader::class, $key);
    $prop->setAccessible(true);
    $prop->setValue($uploader, $value);
}
$video = ($_POST['type'] ?? '') === 'video';
$method = new ReflectionMethod(Uploader::class, $video ? 'buildVideoThumbnail' : 'buildMusicThumbnail');
$method->setAccessible(true);
if ($video) {
    $result = $method->invoke($uploader, $_FILES, 'clip-1', __DIR__ . '/work/', __DIR__ . '/missing.mp4');
    $filename = $result['thumb_name'];
    $small = $result['sm_name'];
    $path = __DIR__ . '/video/thumbnail/' . $filename;
} else {
    $filename = $method->invoke($uploader, $_FILES, 'song-1.flac', __DIR__ . '/missing.flac', __DIR__ . '/music/');
    $path = __DIR__ . '/music/upload/thumbnail/' . $filename;
}
header('Content-Type: application/json');
echo json_encode(['filename' => $filename, 'valid' => is_file($path) && meel_is_valid_webp($path), 'small_valid' => isset($small) && meel_is_valid_webp(__DIR__ . '/video/thumbnail/' . $small)]);
PHP;
        file_put_contents($this->dir . '/router.php', str_replace('PROJECT_ROOT', var_export(MEEL_ROOT, true), $router));
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($socket, $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->url = 'http://' . $address;
        $this->server = proc_open([PHP_BINARY, '-S', $address, $this->dir . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/server.log', 'a'], 2 => ['file', $this->dir . '/server.log', 'a']], $pipes);
        $this->assertIsResource($this->server);
        $deadline = microtime(true) + 5;
        do {
            if (@file_get_contents($this->url) === 'ready') {
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('Server fixture tidak siap: ' . file_get_contents($this->dir . '/server.log'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    private function upload(string $name, string $type = 'music', bool $fail = false): array
    {
        $boundary = 'thumb-' . bin2hex(random_bytes(12));
        $body = '';
        foreach (['type' => $type, 'fail' => $fail ? '1' : '0'] as $key => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
        }
        $image = $fail
            ? "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 1, 1) . "\x08\x02\0\0\0" . pack('N', 0)
            : file_get_contents(MEEL_ROOT . '/assets/img/video0.webp');
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"thumbnail\"; filename=\"$name\"\r\nContent-Type: image/webp\r\n\r\n"
            . $image . "\r\n--$boundary--\r\n";
        $response = file_get_contents($this->url, false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\n", 'content' => $body, 'timeout' => 15,
        ]]));
        $this->assertNotFalse($response);
        $result = json_decode($response, true);
        $this->assertIsArray($result, $response);
        return $result;
    }

    /** @dataProvider coverNames */
    public function testMusicCoverUsesAudioNameInsteadOfBrowserFilename(string $name): void
    {
        $result = $this->upload($name);
        $this->assertSame('song-1.thumb.webp', $result['filename']);
        $this->assertTrue($result['valid']);
    }

    public static function coverNames(): array
    {
        return [['cover-from-metadata.jpg'], ['my-custom-cover.png']];
    }

    public function testMusicCoverCollisionPreservesExistingFile(): void
    {
        $path = $this->dir . '/music/upload/thumbnail/song-1.thumb.webp';
        file_put_contents($path, 'existing-thumbnail');
        $result = $this->upload('cover-from-metadata.jpg');
        $this->assertSame('song-1-1.thumb.webp', $result['filename']);
        $this->assertTrue($result['valid']);
        $this->assertSame('existing-thumbnail', file_get_contents($path));
    }

    public function testFailedCoverConversionLeavesNoReservations(): void
    {
        $result = $this->upload('cover-from-metadata.jpg', 'music', true);
        $this->assertSame('music_default.png', $result['filename']);
        $this->assertSame([], glob($this->dir . '/music/upload/thumbnail/*'));
    }

    public function testVideoMetadataThumbnailUsesUniqueVideoFolder(): void
    {
        $result = $this->upload('thumb-from-metadata.jpg', 'video');
        $this->assertSame('clip-1_thumb.webp', $result['filename']);
        $this->assertTrue($result['valid']);
        $this->assertTrue($result['small_valid']);
    }
}
