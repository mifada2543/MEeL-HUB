<?php
use PHPUnit\Framework\TestCase;

/**
 * Cache thumbnail & gambar profil: header 7 hari + versi URL (?v=mtime).
 *
 * @covers meel_media_cache_control
 * @covers meel_media_file_version
 * @covers video_thumbnail_url
 * @covers book_thumbnail_url
 * @covers music_thumbnail_url
 * @covers meel_profile_image_url
 */
class MediaCacheTest extends TestCase
{
    /** @var string[] */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/helpers/storage.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            @unlink($file);
        }
        $this->created = [];
        clearstatcache();
        parent::tearDown();
    }

    private function makeFile(string $dir, string $name, int $mtime): string
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = rtrim($dir, '/') . '/' . $name;
        file_put_contents($path, 'x');
        touch($path, $mtime);
        $this->created[] = $path;
        clearstatcache(true, $path);
        return $path;
    }

    public function testImageMediaGetsSevenDayPublicCache(): void
    {
        $expected = 'public, max-age=604800, stale-while-revalidate=86400';
        $this->assertSame($expected, meel_media_cache_control('webp'));
        $this->assertSame($expected, meel_media_cache_control('jpg'));
        $this->assertSame($expected, meel_media_cache_control('jpeg'));
        $this->assertSame($expected, meel_media_cache_control('png'));
        $this->assertSame($expected, meel_media_cache_control('gif'));
        $this->assertSame($expected, meel_media_cache_control('avif'));
    }

    public function testNonImageMediaKeepsPrivateCache(): void
    {
        $this->assertSame('private, no-cache', meel_media_cache_control('m3u8'));
        $this->assertStringContainsString('private', meel_media_cache_control('mp4'));
        $this->assertStringContainsString('immutable', meel_media_cache_control('mp3'));
    }

    public function testVideoThumbnailUrlUsesSmVariantAndVersion(): void
    {
        $thumbDir = meel_media_base_path('video') . '/thumbnail/';
        $full = 'cache-test-thumb.webp';
        $sm = video_thumbnail_sm_name($full);
        $mtime = 1700000123;

        $this->makeFile($thumbDir, $full, $mtime - 10);
        $this->makeFile($thumbDir, $sm, $mtime);

        $url = video_thumbnail_url($full);
        $this->assertSame('upload/thumbnail/' . $sm . '?v=' . $mtime, $url);

        $fullUrl = video_thumbnail_url($full, 'upload/thumbnail/', false);
        $this->assertSame('upload/thumbnail/' . $full . '?v=' . ($mtime - 10), $fullUrl);

        $prefixed = video_thumbnail_url($full, '../video/upload/thumbnail/');
        $this->assertSame('../video/upload/thumbnail/' . $sm . '?v=' . $mtime, $prefixed);
    }

    public function testVideoThumbnailUrlFallsBackToFullVariant(): void
    {
        $thumbDir = meel_media_base_path('video') . '/thumbnail/';
        $full = 'cache-test-no-sm.webp';
        $mtime = 1700000456;
        $this->makeFile($thumbDir, $full, $mtime);

        $this->assertSame(
            'upload/thumbnail/' . $full . '?v=' . $mtime,
            video_thumbnail_url($full)
        );
    }

    public function testVideoThumbnailUrlEmptyWhenThumbnailMissing(): void
    {
        $this->assertSame('', video_thumbnail_url('tidak-ada-thumb.webp'));
        $this->assertSame('', video_thumbnail_url(''));
    }

    public function testMusicThumbnailUrlGetsVersionAndSupportsPrefix(): void
    {
        $thumbDir = meel_media_base_path('music') . '/thumbnail/';
        $name = 'cache-test-song.thumb.webp';
        $mtime = 1700000789;
        $this->makeFile($thumbDir, $name, $mtime);

        $this->assertSame('upload/thumbnail/' . $name . '?v=' . $mtime, music_thumbnail_url($name));
        $this->assertSame(
            '../music/upload/thumbnail/' . $name . '?v=' . $mtime,
            music_thumbnail_url($name, '../music/upload/thumbnail/')
        );
    }

    public function testBookThumbnailUrlGetsVersion(): void
    {
        $thumbDir = meel_media_base_path('books') . '/thumbnail/';
        $name = 'cache-test-book.webp';
        $mtime = 1700001011;
        $this->makeFile($thumbDir, $name, $mtime);

        $this->assertSame('upload/thumbnail/' . $name . '?v=' . $mtime, book_thumbnail_url($name));
    }

    public function testProfileImageUrlFallsBackToDefaultAndGetsVersion(): void
    {
        $default = MEEL_ROOT . '/profile/upload/default_avatar.png';
        $this->assertFileExists($default);
        $this->assertSame(
            'profile/upload/default_avatar.png?v=' . (int) @filemtime($default),
            meel_profile_image_url('tidak-ada-avatar.png')
        );
    }

    public function testServiceWorkerCachesMediaImagesWithTtl(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/sw.js.php');
        $this->assertStringContainsString('THUMB_CACHE', $src);
        $this->assertStringContainsString('THUMB_TTL', $src);
        $this->assertStringContainsString('cacheFirstWithTtl', $src);
        $this->assertStringContainsString('isThumbnail', $src);
    }

    public function testProfileImagesUseSevenDayCacheNotNoCache(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/profile/.htaccess');
        $this->assertStringContainsString(
            'Header set Cache-Control "public, max-age=604800, stale-while-revalidate=86400"',
            $src
        );
        $this->assertStringNotContainsString('no-cache, must-revalidate', $src);
    }

    public function testApacheServesVideoAndMusicThumbnailsStaticallyWhenAvailable(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/.htaccess');
        $this->assertStringContainsString(
            'RewriteCond %{REQUEST_FILENAME} -f',
            $src
        );
        $this->assertStringContainsString(
            'RewriteRule ^(video|music)/upload/thumbnail/.+\\.(webp|jpe?g|png|gif|avif)$ - [END,NC]',
            $src
        );
        $this->assertStringContainsString('MEEL_MEDIA_THUMB', $src);
        $this->assertStringContainsString('MEEL_MEDIA_PUBLIC_CACHE', $src);
        $this->assertStringContainsString(
            'public, max-age=604800, stale-while-revalidate=86400',
            $src
        );
        $this->assertStringContainsString('Header always set Content-Type "image/webp"', $src);
        $this->assertStringContainsString('AddType image/webp .webp', $src);
    }
}
