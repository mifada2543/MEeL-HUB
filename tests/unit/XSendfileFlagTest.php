<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class XSendfileFlagTest extends TestCase
{
    private array $savedServer = [];
    private string $fixtureRoot = '';

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
        if ($this->fixtureRoot !== '') {
            $this->removeFixture($this->fixtureRoot);
            $this->fixtureRoot = '';
        }
    }

    private function makeFixture(): string
    {
        $base = rtrim(sys_get_temp_dir(), '/') . '/meel_xsendfile_' . str_replace('.', '', uniqid('', true));
        mkdir($base . '/MEeL/music/upload', 0777, true);
        return $this->fixtureRoot = $base;
    }

    private function removeFixture(string $dir): void
    {
        if ($dir === '' || strpos(basename($dir), 'meel_xsendfile_') !== 0 || !is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
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
        $docRoot = $this->makeFixture();
        $_SERVER['DOCUMENT_ROOT'] = $docRoot;
        $_SERVER['REQUEST_URI']   = '/MEeL/music/upload/file/song.ogg';
        $_SERVER['SCRIPT_NAME']   = '/MEeL/music/file.php';
        unset($_SERVER['REDIRECT_URL']);

        $dirs = meel_xsendfile_htaccess_dirs();

        $this->assertNotEmpty($dirs, 'fixture docroot harus ada di daftar');
        $this->assertSame($docRoot, $dirs[0]);
        $this->assertContains($docRoot . '/MEeL', $dirs);
        $this->assertContains($docRoot . '/MEeL/music', $dirs);
        $this->assertContains($docRoot . '/MEeL/music/upload', $dirs);

        $depths = array_map(static fn (string $d): int => substr_count($d, '/'), $dirs);
        $sorted = $depths;
        sort($sorted);
        $this->assertSame($sorted, $depths, 'daftar harus diurutkan dari docroot ke paling spesifik');
    }

    public function testHtaccessDirsNeverEscapesDocroot(): void
    {
        $docRoot = $this->makeFixture();
        $_SERVER['DOCUMENT_ROOT'] = $docRoot;
        $_SERVER['REQUEST_URI']   = '/../../etc';
        $_SERVER['SCRIPT_NAME']   = '/index.php';
        unset($_SERVER['REDIRECT_URL']);

        $dirs = meel_xsendfile_htaccess_dirs();

        $this->assertNotEmpty($dirs, 'docroot fixture selalu menghasilkan minimal [docroot]');
        foreach ($dirs as $dir) {
            $this->assertSame($docRoot, $dir);
        }
    }

    public function testHtaccessDirsHandlesPercentEncodedPath(): void
    {
        $docRoot = $this->makeFixture();
        $_SERVER['DOCUMENT_ROOT'] = $docRoot;
        $_SERVER['REQUEST_URI']   = '/MEeL/music/upload/file/a%20b.ogg';
        $_SERVER['SCRIPT_NAME']   = '/MEeL/music/file.php';
        unset($_SERVER['REDIRECT_URL']);

        $this->assertContains($docRoot . '/MEeL/music/upload', meel_xsendfile_htaccess_dirs());
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
