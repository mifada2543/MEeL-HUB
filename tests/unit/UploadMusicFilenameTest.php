<?php
use PHPUnit\Framework\TestCase;

class UploadMusicFilenameTest extends TestCase
{
    private const PATTERN = '/\.(php\d?|phtml|sh)(\.|$)/i';

    public function testAllowedNamesPass(): void
    {
        $this->assertSame(0, preg_match(self::PATTERN, 'song.shine.mp3'));
        $this->assertSame(0, preg_match(self::PATTERN, 'sunshine.mp3'));
        $this->assertSame(0, preg_match(self::PATTERN, 'shadow.ogg'));
    }

    public function testForbiddenNamesRejected(): void
    {
        $this->assertSame(1, preg_match(self::PATTERN, 'evil.php.mp3'));
        $this->assertSame(1, preg_match(self::PATTERN, 'evil.php'));
        $this->assertSame(1, preg_match(self::PATTERN, 'evil.php5'));
        $this->assertSame(1, preg_match(self::PATTERN, 'evil.phtml.mp3'));
        $this->assertSame(1, preg_match(self::PATTERN, 'evil.sh.mp3'));
        $this->assertSame(1, preg_match(self::PATTERN, 'evil.sh'));
    }

    public function testUploaderUsesTightenedPattern(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/core/Uploader.php');

        $this->assertStringContainsString(
            '(php\\d?|phtml|sh)(\\.|$)',
            $src,
            'processMusic harus memakai pola segmen penuh'
        );
        $this->assertStringNotContainsString(
            "'/\\.(php|phtml|sh)/i'",
            $src,
            'Pola lama yang longgar tidak boleh tersisa'
        );
    }
}

/* reference build: MEeL-C10H15N [50202506205] */