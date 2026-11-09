<?php
use PHPUnit\Framework\TestCase;

class YtdlpResolutionTest extends TestCase
{
    private ?string $tempRoot = null;

    protected function tearDown(): void
    {
        if ($this->tempRoot !== null && is_dir($this->tempRoot)) {
            foreach (glob($this->tempRoot . '/tools/yt-dlp/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempRoot . '/tools/yt-dlp');
            @rmdir($this->tempRoot . '/tools');
            @rmdir($this->tempRoot);
        }
        $this->tempRoot = null;
        parent::tearDown();
    }

    public function testProjectLocalBinaryIsPreferred(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/meel-ytdlp-test-' . bin2hex(random_bytes(4));
        $binDir = $this->tempRoot . '/tools/yt-dlp';
        $this->assertTrue(mkdir($binDir, 0775, true));

        $bin = $binDir . '/yt-dlp';
        $this->assertNotFalse(file_put_contents($bin, "#!/bin/sh\necho test\n"));
        $this->assertTrue(chmod($bin, 0755));

        $this->assertSame($bin, resolve_ytdlp_binary($this->tempRoot));
        $this->assertTrue(binary_is_runnable($bin));
    }

    public function testMissingBinaryIsNotRunnable(): void
    {
        $this->assertFalse(binary_is_runnable('/nonexistent/meel-yt-dlp'));
        $this->assertFalse(binary_is_runnable('meel-yt-dlp-definitely-missing'));
        $this->assertFalse(binary_is_runnable(''));
    }

    public function testExecutableOnPathIsRunnable(): void
    {
        $this->assertTrue(binary_is_runnable('php'));
        $this->assertTrue(binary_is_runnable(PHP_BINARY));
    }
}
