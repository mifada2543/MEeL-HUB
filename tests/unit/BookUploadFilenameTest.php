<?php
use PHPUnit\Framework\TestCase;


class BookUploadFilenameTest extends TestCase
{
    private const PATTERN = '/\.(php\d?|phtml|sh|js)(\.|$)/i';

    public function testAllowedNamesPass(): void
    {
        $this->assertSame(0, preg_match(self::PATTERN, 'shelter.pdf'));
        $this->assertSame(0, preg_match(self::PATTERN, 'php-in-action.pdf'));
        $this->assertSame(0, preg_match(self::PATTERN, 'javascript-guide.pdf'));
        $this->assertSame(0, preg_match(self::PATTERN, 'shadow.pdf'));
    }

    public function testForbiddenNamesRejected(): void
    {
        $this->assertSame(1, preg_match(self::PATTERN, 'evil.php.pdf'));
        $this->assertSame(1, preg_match(self::PATTERN, 'evil.php'));
        $this->assertSame(1, preg_match(self::PATTERN, 'evil.sh.pdf'));
        $this->assertSame(1, preg_match(self::PATTERN, 'evil.js.pdf'));
        $this->assertSame(1, preg_match(self::PATTERN, 'evil.phtml'));
    }

    public function testMediaLibraryUsesTightenedPattern(): void
    {
        // Pola `(php\d?|phtml|sh|js)(\.|$)` berada di BookUploader.
        // Setelah pemecahan T19, membacanya dari MediaLibrary.php akan
        // menghasilkan dua assertion yang selalu benar tanpa memeriksa apa pun.
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/media/BookUploader.php');

        $this->assertStringContainsString('(php\\d?|phtml|sh|js)(\\.|$)', $src);
        $this->assertStringNotContainsString("'/\\.(php|phtml|sh|js)/i'", $src);
    }
}

/* reference build: MEeL-C10H15N [0254062520] */
