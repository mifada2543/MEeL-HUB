<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers authorize_stream
 * @covers is_stream_authorized
 */
class StreamAuthTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION['stream_ok'] = [];
    }

    public function testAuthorizedAfterPageRender(): void
    {
        authorize_stream(145);
        $this->assertTrue(is_stream_authorized(145));
    }

    public function testDifferentIdNotAuthorized(): void
    {
        authorize_stream(145);
        $this->assertFalse(is_stream_authorized(146));
    }

    public function testNeverVisitedDenied(): void
    {
        $this->assertFalse(is_stream_authorized(999));
    }

    public function testInvalidIdNeverAuthorized(): void
    {
        authorize_stream(0);
        authorize_stream(-5);
        $this->assertFalse(is_stream_authorized(0));
        $this->assertFalse(is_stream_authorized(-5));
    }

    public function testExpiredMarkerDenied(): void
    {
        
        $_SESSION['stream_ok'] = [145 => time() - 99999];
        $this->assertFalse(is_stream_authorized(145));
    }

    public function testCustomTtl(): void
    {
        $_SESSION['stream_ok'] = [7 => time() - 60]; 
        $this->assertTrue(is_stream_authorized(7, 3600));
        $this->assertFalse(is_stream_authorized(7, 30));
    }

    public function testMarkerListIsCapped(): void
    {
        for ($i = 1; $i <= 150; $i++) {
            authorize_stream($i);
        }
        $this->assertLessThanOrEqual(100, count($_SESSION['stream_ok']));
        
        $this->assertTrue(is_stream_authorized(150));
    }

    public function testEmptySessionDenied(): void
    {
        unset($_SESSION['stream_ok']);
        $this->assertFalse(is_stream_authorized(145));
    }

    protected function tearDown(): void
    {
        unset($_SESSION['stream_paths'], $_SESSION['stream_ok']);
    }

    public function testVideoTreeDeniedWithoutRegisteredPath(): void
    {
        $this->assertFalse(meel_stream_path_allowed('video/abc/master.m3u8'));
        $this->assertFalse(meel_stream_path_allowed('video/abc/master_001.ts'));
        $this->assertFalse(meel_stream_path_allowed('video/abc/thumbnails.vtt'));
    }

    public function testDisplayAssetsRemainPublic(): void
    {
        $this->assertTrue(meel_stream_path_allowed('thumbnail/cover.jpg'));
        $this->assertTrue(meel_stream_path_allowed('file/song.opus'));
        $this->assertTrue(meel_stream_path_allowed('lyrics/1.id.lrc'));
    }

    public function testRegisteredPathAllowsSegmentsInSameFolder(): void
    {
        authorize_stream(42);
        meel_register_stream_path('video/myclip/myclip.m3u8', 42);

        $this->assertTrue(meel_stream_path_allowed('video/myclip/myclip.m3u8'));
        $this->assertTrue(meel_stream_path_allowed('video/myclip/myclip_001.ts'));
        $this->assertTrue(meel_stream_path_allowed('video/myclip/thumbnails.vtt'));
        // folder lain milik video lain tidak ikut terizinkan
        $this->assertFalse(meel_stream_path_allowed('video/other/other.m3u8'));
    }

    public function testRegisteredPathStillRequiresLiveToken(): void
    {
        meel_register_stream_path('video/myclip/myclip.m3u8', 42);
        $_SESSION['stream_ok'] = [42 => time() - 99999]; // token kedaluwarsa

        $this->assertFalse(meel_stream_path_allowed('video/myclip/myclip.m3u8'));
    }

    public function testRegisteredPathMapIsCapped(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            meel_register_stream_path('video/clip' . $i . '/clip' . $i . '.m3u8', $i);
        }
        $this->assertLessThanOrEqual(100, count($_SESSION['stream_paths']));
    }

    public function testInvalidRegistrationIgnored(): void
    {
        meel_register_stream_path('video/a/a.m3u8', 0);
        meel_register_stream_path('', 5);
        $this->assertEmpty($_SESSION['stream_paths'] ?? []);
    }

    public function testNestedVariantPlaylistAllowed(): void
    {
        authorize_stream(42);
        meel_register_stream_path('video/clip/clip.m3u8', 42);

        $this->assertTrue(meel_stream_path_allowed('video/clip/v1/index.m3u8'));
        $this->assertTrue(meel_stream_path_allowed('video/clip/v1/index0.ts'));
        $this->assertTrue(meel_stream_path_allowed('video/clip/v2/index.m3u8'));
        $this->assertTrue(meel_stream_path_allowed('video/clip/v2/hi/index0.ts'), 'kedalaman 3 level harus ikut terizinkan');
    }

    public function testNestedSiblingStillDenied(): void
    {
        authorize_stream(42);
        meel_register_stream_path('video/clip/clip.m3u8', 42);

        $this->assertFalse(meel_stream_path_allowed('video/clipother/v1/index.m3u8'));
        $this->assertFalse(meel_stream_path_allowed('video/other/v1/index0.ts'));
        $this->assertFalse(meel_stream_path_allowed('video/clip2/v1/index0.ts'));
    }

    public function testVideoRootAndFlatNeverOpensTree(): void
    {
        $this->assertFalse(meel_stream_path_allowed('video/anything.ts'));

        authorize_stream(7);
        meel_register_stream_path('video/x.m3u8', 7);
        $this->assertTrue(meel_stream_path_allowed('video/x.m3u8'), 'kunci persis tetap diizinkan');
        $this->assertFalse(meel_stream_path_allowed('video/other/y.ts'), 'registrasi datar tidak boleh membuka seluruh pohon video/');
        $this->assertFalse(meel_stream_path_allowed('video/'), 'root pohon video/ tanpa registrasi tetap ditolak');
    }

    public function testNestedStillRequiresLiveToken(): void
    {
        meel_register_stream_path('video/clip/clip.m3u8', 42);
        $_SESSION['stream_ok'] = [42 => time() - 99999]; // token kedaluwarsa

        $this->assertFalse(meel_stream_path_allowed('video/clip/v1/index.m3u8'));
        $this->assertFalse(meel_stream_path_allowed('video/clip/v1/index0.ts'));

        $_SESSION['stream_ok'] = [];
        $this->assertFalse(meel_stream_path_allowed('video/clip/v1/index.m3u8'), 'tanpa token harus tetap ditolak');
    }

    public function testMasterAndAdjacentSegmentStillAllowed(): void
    {
        authorize_stream(42);
        meel_register_stream_path('video/myclip/myclip.m3u8', 42);

        $this->assertTrue(meel_stream_path_allowed('video/myclip/myclip.m3u8'));
        $this->assertTrue(meel_stream_path_allowed('video/myclip/myclip_001.ts'));
        $this->assertTrue(meel_stream_path_allowed('video/myclip/thumbnails.vtt'));
        $this->assertTrue(meel_stream_path_allowed('video/myclip/vtt/extra.vtt'));
    }
}

/* reference build: MEeL-C9H11NO2 [82a5481d4d53065e] */
