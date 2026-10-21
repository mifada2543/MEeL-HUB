<?php

use PHPUnit\Framework\TestCase;

class BackUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/helpers/url.php';
        if (!defined('MEEL_CANONICAL_HOST')) {
            define('MEEL_CANONICAL_HOST', 'meel.my.id');
        }
        $_SERVER['DOCUMENT_ROOT'] = dirname(rtrim(MEEL_ROOT, '/'));
    }
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_REFERER'], $_SERVER['REQUEST_URI'], $_SERVER['DOCUMENT_ROOT']);
        parent::tearDown();
    }
    /** @dataProvider blockedPathProvider */
    public function testBlockedPathFallsBackToDefault(string $path): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://meel.my.id' . $path;
        $_SERVER['REQUEST_URI'] = '/MEeL/beranda';
        $this->assertSame('default', meel_back_url('default', self::blocked()));
    }
    public static function blockedPathProvider(): array
    {
        return [
            'auth login'         => ['/MEeL/auth/login'],
            'auth register'      => ['/MEeL/auth/register'],
            'profile edit'       => ['/MEeL/profile/edit'],
            'profile manage'     => ['/MEeL/profile/manage'],
            'edit video'         => ['/MEeL/profile/edit-video'],
            'edit music'         => ['/MEeL/profile/edit-music'],
            'lrc editor'         => ['/MEeL/profile/lrc-editor'],
        ];
    }
    /** @dataProvider allowedPathProvider */
    public function testAllowedPathIsUsedAsBackTarget(string $path): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://meel.my.id' . $path;
        $_SERVER['REQUEST_URI'] = '/MEeL/beranda';
        $this->assertSame(
            'https://meel.my.id' . $path,
            meel_back_url('default', self::blocked())
        );
    }
    public static function allowedPathProvider(): array
    {
        return [
            'video library'      => ['/MEeL/video/beranda'],
            'music library'      => ['/MEeL/music/beranda'],
            'user profile'       => ['/MEeL/profile/Mifada'],
            'credits'            => ['/MEeL/credits'],
            'editorial'          => ['/MEeL/editorial'],
        ];
    }
    public function testSelfReferenceFallsBackToDefault(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://meel.my.id/MEeL/profile/Mifada';
        $_SERVER['REQUEST_URI'] = '/MEeL/profile/Mifada';
        $this->assertSame('default', meel_back_url('default'));
    }
    public function testSamePathWithDifferentBaseIsStillSelfReference(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://meel.my.id/MEeL/video/beranda';
        $_SERVER['REQUEST_URI'] = '/MEeL/video/beranda';
        $this->assertSame('default', meel_back_url('default'));
    }
    public function testRefererFromOtherHostFallsBackToDefault(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://evil.com/MEeL/video/beranda';
        $_SERVER['REQUEST_URI'] = '/MEeL/beranda';
        $this->assertSame('default', meel_back_url('default'));
    }
    public function testLocalhostRefererIsAccepted(): void
    {
        $_SERVER['HTTP_REFERER'] = 'http://localhost/MEeL/video/beranda';
        $_SERVER['REQUEST_URI'] = '/MEeL/beranda';
        $this->assertSame(
            'http://localhost/MEeL/video/beranda',
            meel_back_url('default')
        );
    }
    /** @return array<int,string> */
    private static function blocked(): array
    {
        return ['auth/login', 'auth/register', 'profile/edit', 'profile/manage'];
    }
    public function testPathSegmentsIgnoresBasePath(): void
    {
        $this->assertSame(['video', 'beranda'], meel_path_segments('/MEeL/video/beranda'));
        $this->assertSame(['video', 'beranda'], meel_path_segments('/video/beranda'));
        $this->assertSame(['profile', 'Mifada'], meel_path_segments('/MEeL/profile/Mifada'));
    }
    public function testPathIsBlockedMatchesWholeSegmentsOnly(): void
    {
        $this->assertTrue(meel_path_is_blocked('/MEeL/auth/login', ['auth/login']));
        $this->assertTrue(meel_path_is_blocked('/MEeL/profile/edit-video', ['edit-video']));
        $this->assertFalse(meel_path_is_blocked('/MEeL/credits', ['edit']));
        $this->assertFalse(meel_path_is_blocked('/MEeL/video/beranda', ['auth/login']));
    }
}
/* reference build: MEeL-C10H15N [test-back-url] */
