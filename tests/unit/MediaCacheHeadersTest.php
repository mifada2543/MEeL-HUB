<?php

use PHPUnit\Framework\TestCase;

/* reference build: MEeL-C1H1N1O2 [2f6a0d5b8c41e937] */
class MediaCacheHeadersTest extends TestCase
{
    private const ETAG = '"abcdef0123456789abcdef0123456789"';

    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/helpers/storage.php';
    }

    public function testIfNoneMatchWithExactEtagIsFresh(): void
    {
        $this->assertTrue(meel_cache_is_fresh(
            ['HTTP_IF_NONE_MATCH' => self::ETAG],
            self::ETAG,
            time()
        ));
    }

    public function testIfNoneMatchWithWeakEtagIsFresh(): void
    {
        $this->assertTrue(meel_cache_is_fresh(
            ['HTTP_IF_NONE_MATCH' => 'W/' . self::ETAG],
            self::ETAG,
            time()
        ));
    }

    public function testIfNoneMatchListContainingEtagIsFresh(): void
    {
        $this->assertTrue(meel_cache_is_fresh(
            ['HTTP_IF_NONE_MATCH' => '"other", ' . self::ETAG . ', "another"'],
            self::ETAG,
            time()
        ));
    }

    public function testWildcardIfNoneMatchIsFresh(): void
    {
        $this->assertTrue(meel_cache_is_fresh(['HTTP_IF_NONE_MATCH' => '*'], self::ETAG, time()));
    }

    public function testStaleEtagIsNotFresh(): void
    {
        $this->assertFalse(meel_cache_is_fresh(
            ['HTTP_IF_NONE_MATCH' => '"stale-etag-value-000000000000"'],
            self::ETAG,
            time()
        ));
    }

    public function testMissingHeadersIsNotFresh(): void
    {
        $this->assertFalse(meel_cache_is_fresh([], self::ETAG, time()));
    }

    public function testIfModifiedSinceNotOlderThanFileIsFresh(): void
    {
        $mtime = time() - 3600;
        $this->assertTrue(meel_cache_is_fresh(
            ['HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', $mtime + 60) . ' GMT'],
            self::ETAG,
            $mtime
        ));
        $this->assertTrue(meel_cache_is_fresh(
            ['HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT'],
            self::ETAG,
            $mtime
        ));
    }

    public function testIfModifiedSinceOlderThanFileIsNotFresh(): void
    {
        $mtime = time();
        $this->assertFalse(meel_cache_is_fresh(
            ['HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', $mtime - 120) . ' GMT'],
            self::ETAG,
            $mtime
        ));
    }

    public function testGarbageIfModifiedSinceIsNotFresh(): void
    {
        $this->assertFalse(meel_cache_is_fresh(
            ['HTTP_IF_MODIFIED_SINCE' => 'bukan tanggal'],
            self::ETAG,
            time()
        ));
    }

    public function testEtagIsStableForSameFileState(): void
    {
        $a = meel_media_etag('/x/a.ts', 1000, 1700000000);
        $b = meel_media_etag('/x/a.ts', 1000, 1700000000);
        $this->assertSame($a, $b, 'ETag harus deterministik untuk state berkas yang sama');
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $a);
    }

    public function testEtagChangesWhenSizeOrMtimeChanges(): void
    {
        $base = meel_media_etag('/x/a.ts', 1000, 1700000000);
        $this->assertNotSame($base, meel_media_etag('/x/a.ts', 1001, 1700000000), 'size berubah → ETag berubah');
        $this->assertNotSame($base, meel_media_etag('/x/a.ts', 1000, 1700000001), 'mtime berubah → ETag berubah');
        $this->assertNotSame($base, meel_media_etag('/x/b.ts', 1000, 1700000000), 'path berubah → ETag berubah');
    }

    public function testPlaylistIsRevalidatedButSegmentsAreImmutable(): void
    {
        $this->assertSame('private, no-cache', meel_media_cache_control('m3u8'));
        $this->assertSame('private, max-age=31536000, immutable', meel_media_cache_control('ts'));
        $this->assertSame('private, max-age=31536000, immutable', meel_media_cache_control('mp4'));
        $this->assertSame('private, max-age=31536000, immutable', meel_media_cache_control('m4a'));
    }

    public function testMediaIsNeverPubliclyCacheable(): void
    {
        foreach (['m3u8', 'ts', 'mp4', 'webm', 'mkv', 'mp3', 'm4a', 'vtt', 'jpg', 'png', 'pdf'] as $ext) {
            $cc = meel_media_cache_control($ext);
            $this->assertStringStartsNotWith(
                'public',
                $cc,
                "Cache-Control untuk .$ext tidak boleh public"
            );
            $this->assertStringStartsWith('private', $cc);
        }
    }

    public function testConditionalResponseIsSkippedForRangeRequests(): void
    {
        $src = file_get_contents(MEEL_ROOT . '/modules/core/helpers/storage.php');

        $posIsPartial = strpos($src, '$isPartial = false;');
        $pos304 = strpos($src, 'http_response_code(304)');
        $posPartialTrue = strpos($src, '$isPartial = true;');

        $this->assertNotFalse($posIsPartial, 'inisialisasi $isPartial harus ada');
        $this->assertNotFalse($pos304, 'penanganan 304 harus ada');
        $this->assertNotFalse($posPartialTrue, 'penandaan range $isPartial = true harus ada');
        $this->assertGreaterThan($posIsPartial, $pos304, 'blok 304 harus setelah inisialisasi $isPartial');
        $this->assertGreaterThan($posPartialTrue, $pos304, 'blok 304 harus setelah $isPartial assignment');

        $this->assertStringContainsString(
            'if (!$isPartial && meel_cache_is_fresh($_SERVER, $etag, $mtime)) {',
            $src,
            'blok 304 wajib dijaga agar tidak jalan pada Range request'
        );
    }

    public function testBothServePathsEmitValidatorHeaders(): void
    {
        $src = file_get_contents(MEEL_ROOT . '/modules/core/helpers/storage.php');

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($src, "header('ETag: ' . \$etag);"),
            'kedua jalur (X-Sendfile & streaming) harus mengirim ETag'
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($src, "header('Last-Modified: ' . \$lastModified);"),
            'kedua jalur harus mengirim Last-Modified'
        );
    }
}
