<?php
use PHPUnit\Framework\TestCase;

/** @coversNothing */
class MusicArtistDefaultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/helpers/metadata.php';
    }

    public function testMissingArtistUsesDefault(): void
    {
        $this->assertSame('By Uploader', meel_music_artist(null));
        $this->assertSame('By Uploader', meel_music_artist(''));
        $this->assertSame('By Uploader', meel_music_artist('   '));
    }

    public function testExistingArtistIsPreservedAndTrimmed(): void
    {
        $this->assertSame('Queen', meel_music_artist('  Queen  '));
    }
}
