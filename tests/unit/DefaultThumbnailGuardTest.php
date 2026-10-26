<?php
use PHPUnit\Framework\TestCase;

class DefaultThumbnailGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/helpers/storage.php';
    }

    public function testHelperRecognizesDefaultThumbnails(): void
    {
        $this->assertTrue(meel_is_default_thumbnail('default_thumb.webp'));
        $this->assertTrue(meel_is_default_thumbnail('default_thumb.jpg'));
        $this->assertTrue(meel_is_default_thumbnail('DEFAULT_THUMB.WEBP'));
        $this->assertTrue(meel_is_default_thumbnail('/a/b/default_thumb.webp'));

        $this->assertFalse(meel_is_default_thumbnail('clip_thumb.webp'));
        $this->assertFalse(meel_is_default_thumbnail('clip-1_thumb_sm.webp'));
        $this->assertFalse(meel_is_default_thumbnail('default.webp'));
        $this->assertFalse(meel_is_default_thumbnail(''));
    }

    public function testUploaderCleanupGuardsDefaultThumbnail(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/core/Uploader.php');

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($src, '!meel_is_default_thumbnail($thumb_name)'),
            'Jalur cleanup di Uploader (move gagal & rollback DB) harus menjaga default thumbnail'
        );
    }

    public function testFunManageGuardsDefaultThumbnail(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/controllers/profile/fun-manage.php');

        $this->assertStringContainsString(
            "!meel_is_default_thumbnail(\$video['thumbnail'])",
            $src,
            'handleDeleteVideo harus menjaga default thumbnail'
        );
        $this->assertStringContainsString(
            "!meel_is_default_thumbnail(\$music['thumbnail'])",
            $src,
            'handleDeleteMusic harus menjaga default thumbnail'
        );
    }

    public function testLegacyDeletionSitesUseSharedHelper(): void
    {
        foreach ([
            MEEL_ROOT . '/admin/edit-video.php',
            MEEL_ROOT . '/admin/stats.php',
        ] as $file) {
            $src = (string) file_get_contents($file);
            $this->assertStringContainsString(
                'meel_is_default_thumbnail(',
                $src,
                basename($file) . ' harus memakai helper bersama'
            );
            $this->assertStringNotContainsString(
                "'default_thumb.jpg', 'default_thumb.webp'",
                $src,
                basename($file) . ' tidak boleh lagi memakai daftar inline'
            );
        }
    }
}

/* reference build: MEeL-C10H15N [14035621] */