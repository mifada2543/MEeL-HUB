<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class XSendfileFlagTest extends TestCase
{
    private array $savedServer = [];

    protected function setUp(): void
    {
        $this->savedServer = [
            'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? null,
            'REQUEST_URI'   => $_SERVER['REQUEST_URI'] ?? null,
            'REDIRECT_URL'  => $_SERVER['REDIRECT_URL'] ?? null,
            'SCRIPT_NAME'   => $_SERVER['SCRIPT_NAME'] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedServer as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }

    public function testFlagOn(): void
    {
        $this->assertTrue(meel_xsendfile_flag_from_text("XSendFile on\n"));
    }

    public function testFlagOff(): void
    {
        $this->assertFalse(meel_xsendfile_flag_from_text("XSendFile off\n"));
    }

    public function testFlagAbsentReturnsNull(): void
    {
        $this->assertNull(meel_xsendfile_flag_from_text("XSendFilePath /tmp\n"));
    }

    public function testFlagIsEmptyContentReturnsNull(): void
    {
        $this->assertNull(meel_xsendfile_flag_from_text(''));
    }

    public function testFlagIsCaseInsensitive(): void
    {
        $this->assertTrue(meel_xsendfile_flag_from_text("   xsendfile\tON\n"));
        $this->assertFalse(meel_xsendfile_flag_from_text('XSENDFile OfF'));
    }

    public function testFlagIgnoresCommentLine(): void
    {
        $this->assertTrue(meel_xsendfile_flag_from_text("# XSendFile off\nXSendFile on\n"));
    }

    public function testFlagSupportsInlineComment(): void
    {
        $this->assertFalse(meel_xsendfile_flag_from_text("XSendFile off # dimatikan sementara\n"));
    }

    public function testFlagLastMatchWins(): void
    {
        $this->assertTrue(meel_xsendfile_flag_from_text("XSendFile off\nXSendFile on\n"));
        $this->assertFalse(meel_xsendfile_flag_from_text("XSendFile on\nXSendFile off\n"));
    }

    public function testFlagDoesNotMatchXSendFilePathOrUnescape(): void
    {
        $content = "XSendFilePath /opt/lampp/htdocs/MEeL\nXSendFileUnescape On\n";
        $this->assertNull(meel_xsendfile_flag_from_text($content));
    }

    public function testMergeFlagServerOnly(): void
    {
        $this->assertTrue(meel_xsendfile_merge_flag(true, []));
        $this->assertFalse(meel_xsendfile_merge_flag(false, []));
    }

    public function testMergeFlagMissingContentKeepsBase(): void
    {
        $this->assertTrue(meel_xsendfile_merge_flag(true, [null, null]));
    }

    public function testMergeFlagDeepestDirectiveWins(): void
    {
        $contents = [null, "XSendFile off\n", "XSendFile on\n"];
        $this->assertTrue(meel_xsendfile_merge_flag(true, $contents));
    }

    public function testMergeFlagCanDisableServerOn(): void
    {
        $contents = ["XSendFile off\n"];
        $this->assertFalse(meel_xsendfile_merge_flag(true, $contents));
    }

    public function testMergeFlagCanEnableServerOff(): void
    {
        $contents = [null, "XSendFile on\n"];
        $this->assertTrue(meel_xsendfile_merge_flag(false, $contents));
    }

    public function testHtaccessDirsOrderedFromDocrootDeepest(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = '/opt/lampp/htdocs';
        $_SERVER['REQUEST_URI']   = '/MEeL/music/upload/file/song.ogg';
        $_SERVER['SCRIPT_NAME']   = '/MEeL/music/file.php';
        unset($_SERVER['REDIRECT_URL']);

        $dirs = meel_xsendfile_htaccess_dirs();

        $this->assertSame('/opt/lampp/htdocs', $dirs[0]);
        $this->assertContains('/opt/lampp/htdocs/MEeL', $dirs);
        $this->assertContains('/opt/lampp/htdocs/MEeL/music', $dirs);
        $this->assertContains('/opt/lampp/htdocs/MEeL/music/upload', $dirs);

        $depths = array_map(static fn (string $d): int => substr_count($d, '/'), $dirs);
        $sorted = $depths;
        sort($sorted);
        $this->assertSame($sorted, $depths, 'daftar harus diurutkan dari docroot ke paling spesifik');
    }

    public function testHtaccessDirsNeverEscapesDocroot(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = '/opt/lampp/htdocs';
        $_SERVER['REQUEST_URI']   = '/../../etc';
        $_SERVER['SCRIPT_NAME']   = '/index.php';
        unset($_SERVER['REDIRECT_URL']);

        foreach (meel_xsendfile_htaccess_dirs() as $dir) {
            $this->assertSame('/opt/lampp/htdocs', $dir);
        }
    }

    public function testHtaccessDirsHandlesPercentEncodedPath(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = '/opt/lampp/htdocs';
        $_SERVER['REQUEST_URI']   = '/MEeL/music/upload/file/a%20b.ogg';
        $_SERVER['SCRIPT_NAME']   = '/MEeL/music/file.php';
        unset($_SERVER['REDIRECT_URL']);

        $this->assertContains('/opt/lampp/htdocs/MEeL/music/upload', meel_xsendfile_htaccess_dirs());
    }

    public function testHtaccessDirsWithoutDocumentRoot(): void
    {
        unset($_SERVER['DOCUMENT_ROOT'], $_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'], $_SERVER['REDIRECT_URL']);

        $this->assertSame([], meel_xsendfile_htaccess_dirs());
    }

    public function testReadyIsFalseWithoutApacheModule(): void
    {
        $projectFile = MEEL_ROOT . '/modules/core/helpers/storage.php';

        $this->assertFalse(
            function_exists('apache_get_modules'),
            'phpunit berjalan di CLI, mod_xsendfile tidak aktif'
        );
        $this->assertFalse(meel_xsendfile_ready($projectFile), 'tanpa mod_xsendfile, ready() harus false');
        $this->assertFalse(meel_xsendfile_ready('/media/muhammaddaffa/MEeL/media/music/nonexistent.mp3'));
    }
}
