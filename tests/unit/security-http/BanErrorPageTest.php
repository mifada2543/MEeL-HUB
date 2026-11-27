<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class BanErrorPageTest extends TestCase
{
    private function renderErrPage(string $code, string $reason): string
    {
        $_GET['code'] = $code;
        $_GET['reason'] = $reason;
        $_SERVER['HTTP_HOST'] = 'localhost';
        unset($_SERVER['HTTP_REFERER']);
        ob_start();
        require MEEL_ROOT . '/err/index.php';
        return (string) ob_get_clean();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testBanCodeRendersBannedPageWithReason(): void
    {
        $html = $this->renderErrPage('ban', 'Alasan uji ban');

        $this->assertStringContainsString('403_Banned', $html);
        $this->assertStringNotContainsString('404_Not_Found', $html);
        $this->assertStringContainsString('Alasan uji ban', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLegacyBannedCodeStillWorks(): void
    {
        $html = $this->renderErrPage('banned', 'URL lama');

        $this->assertStringContainsString('403_Banned', $html);
        $this->assertStringContainsString('URL lama', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUnknownCodeStillFallsBackToNotFound(): void
    {
        $html = $this->renderErrPage('ban_typo', 'abaikan');

        $this->assertStringContainsString('404_Not_Found', $html);
        $this->assertStringNotContainsString('403_Banned', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testBannedUrlUsesCanonicalCodeAndEncodedReason(): void
    {
        require_once MEEL_ROOT . '/modules/core/activity_logger.php';
        $method = new ReflectionMethod(ActivityLogger::class, 'bannedUrl');
        $method->setAccessible(true);

        $url = (string) $method->invoke(null, 'Alasan uji & spasi');

        $this->assertStringContainsString('/err/?code=ban&reason=', $url);
        $this->assertStringNotContainsString('code=banned', $url);
        $this->assertStringContainsString(rawurlencode('Alasan uji & spasi'), $url);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testBannedUrlUsesFallbackReason(): void
    {
        require_once MEEL_ROOT . '/modules/core/activity_logger.php';
        $method = new ReflectionMethod(ActivityLogger::class, 'bannedUrl');
        $method->setAccessible(true);

        $url = (string) $method->invoke(null, '   ');

        $this->assertStringContainsString(rawurlencode('Pelanggaran kebijakan'), $url);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testErrRequestDetectionWorksWithCleanUrls(): void
    {
        require_once MEEL_ROOT . '/modules/core/activity_logger.php';
        $base = rtrim(defined('MEEL_BASE_URL') ? (string) MEEL_BASE_URL : '', '/');
        $method = new ReflectionMethod(ActivityLogger::class, 'isErrRequest');
        $method->setAccessible(true);

        $_SERVER['REQUEST_URI'] = $base . '/router.php';
        $this->assertFalse($method->invoke(null), 'guard harus tetap jalan saat err lewat router');
        $_SERVER['REQUEST_URI'] = $base . '/video/watch?v=350';
        $this->assertFalse($method->invoke(null));
        $_SERVER['REQUEST_URI'] = $base . '/err/?code=ban&reason=x';
        $this->assertTrue($method->invoke(null));
        $_SERVER['REQUEST_URI'] = $base . '/err';
        $this->assertTrue($method->invoke(null));
    }
}
