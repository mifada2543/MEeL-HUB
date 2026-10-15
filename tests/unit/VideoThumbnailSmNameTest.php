<?php
use PHPUnit\Framework\TestCase;

class VideoThumbnailSmNameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/helpers/storage.php';
    }

    public function testDerivesSmNameBeforeExtension(): void
    {
        $this->assertSame('clip_thumb_sm.webp', video_thumbnail_sm_name('clip_thumb.webp'));
        $this->assertSame('clip-1_thumb_sm.webp', video_thumbnail_sm_name('clip-1_thumb.webp'));
        $this->assertSame('clip_thumb_sm.webp', video_thumbnail_sm_name('/a/b/clip_thumb.webp'));
    }

    public function testEmptyInputYieldsEmpty(): void
    {
        $this->assertSame('', video_thumbnail_sm_name(''));
        $this->assertSame('', video_thumbnail_sm_name(null));
    }

    public function testFunManageUsesSharedDerivation(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/controllers/profile/fun-manage.php');

        $this->assertStringContainsString(
            'video_thumbnail_sm_name(',
            $src,
            'handleDeleteVideo harus memakai derivasi _sm yang sama dengan video_thumbnail_sm()'
        );
    }

    public function testEditVideoRemovesOldSmVariant(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/admin/edit-video.php');

        $this->assertStringContainsString(
            'video_thumbnail_sm_name($old_thumb_name)',
            $src,
            'Saat mengganti thumbnail, varian _sm lama juga harus dibuang agar tidak yatim'
        );
    }
}

/* reference build: MEeL-C10H15N [0250025] */