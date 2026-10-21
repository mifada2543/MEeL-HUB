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
    private function path(string $p): string
    {
        $base = meel_base_url_path();
        return ($base === '' ? '' : $base) . $p;
    }
    /** @return array<int,string> */
    private static function blocked(): array
    {
        return ['auth/login', 'auth/register', 'profile/edit', 'profile/manage'];
    }
    /** @dataProvider blockedPathProvider */
    public function testBlockedPathFallsBackToDefault(string $path): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://meel.my.id' . $this->path($path);
        $_SERVER['REQUEST_URI'] = $this->path('/beranda');
        $this->assertSame('default', meel_back_url('default', self::blocked()));
    }
    public static function blockedPathProvider(): array
    {
        return [
            'auth login'         => ['/auth/login'],
            'auth register'      => ['/auth/register'],
            'profile edit'       => ['/profile/edit'],
            'profile manage'     => ['/profile/manage'],
            'edit video'         => ['/profile/edit-video'],
            'edit music'         => ['/profile/edit-music'],
            'lrc editor'         => ['/profile/lrc-editor'],
        ];
    }
    /** @dataProvider allowedPathProvider */
    public function testAllowedPathIsUsedAsBackTarget(string $path): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://meel.my.id' . $this->path($path);
        $_SERVER['REQUEST_URI'] = $this->path('/beranda');
        $this->assertSame(
            'https://meel.my.id' . $this->path($path),
            meel_back_url('default', self::blocked())
        );
    }
    public static function allowedPathProvider(): array
    {
        return [
            'video library'      => ['/video/beranda'],
            'music library'      => ['/music/beranda'],
            'user profile'       => ['/profile/Mifada'],
            'credits'            => ['/credits'],
            'editorial'          => ['/editorial'],
        ];
    }
    public function testSelfReferenceFallsBackToDefault(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://meel.my.id' . $this->path('/profile/Mifada');
        $_SERVER['REQUEST_URI'] = $this->path('/profile/Mifada');
        $this->assertSame('default', meel_back_url('default'));
    }
    public function testSamePathWithDifferentBaseIsStillSelfReference(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://meel.my.id' . $this->path('/video/beranda');
        $_SERVER['REQUEST_URI'] = $this->path('/video/beranda');
        $this->assertSame('default', meel_back_url('default'));
    }
    public function testRefererFromOtherHostFallsBackToDefault(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://evil.com' . $this->path('/video/beranda');
        $_SERVER['REQUEST_URI'] = $this->path('/beranda');
        $this->assertSame('default', meel_back_url('default'));
    }
    public function testLocalhostRefererIsAccepted(): void
    {
        $_SERVER['HTTP_REFERER'] = 'http://localhost' . $this->path('/video/beranda');
        $_SERVER['REQUEST_URI'] = $this->path('/beranda');
        $this->assertSame(
            'http://localhost' . $this->path('/video/beranda'),
            meel_back_url('default')
        );
    }
    public function testPathSegmentsIgnoresBasePath(): void
    {
        $this->assertSame(['video', 'beranda'], meel_path_segments($this->path('/video/beranda')));
        $this->assertSame(['video', 'beranda'], meel_path_segments('/video/beranda'));
        $this->assertSame(['profile', 'Mifada'], meel_path_segments($this->path('/profile/Mifada')));
    }
    public function testPathIsBlockedMatchesWholeSegmentsOnly(): void
    {
        $this->assertTrue(meel_path_is_blocked($this->path('/auth/login'), ['auth/login']));
        $this->assertTrue(meel_path_is_blocked($this->path('/profile/edit-video'), ['edit-video']));
        $this->assertFalse(meel_path_is_blocked($this->path('/credits'), ['edit']));
        $this->assertFalse(meel_path_is_blocked($this->path('/video/beranda'), ['auth/login']));
    }
    public function testBasePathFollowsInstallDirectoryName(): void
    {
        $this->assertSame(
            '/' . basename(rtrim(MEEL_ROOT, '/')),
            meel_base_url_path(),
            'Base path harus sama dengan nama folder instalasi'
        );
    }
    public function testFilesystemBasePathIsNotUsedAsUrlPrefix(): void
    {
        $saved = $_SERVER['DOCUMENT_ROOT'] ?? null;
        unset($_SERVER['DOCUMENT_ROOT']);
        try {
            $this->assertSame(['video', 'beranda'], meel_path_segments('/video/beranda'));
            $this->assertSame(
                array_merge(explode('/', trim(MEEL_ROOT, '/')), ['video']),
                meel_path_segments(MEEL_ROOT . '/video'),
                'Path filesystem tidak boleh terpotong oleh base path'
            );
        } finally {
            if ($saved !== null) {
                $_SERVER['DOCUMENT_ROOT'] = $saved;
            }
        }
    }
    public function testSamePathDetectedAcrossDifferentBasePaths(): void
    {
        $this->assertTrue(meel_same_path('/video/beranda', $this->path('/video/beranda')));
        $this->assertTrue(meel_same_path('/video/beranda', '/video/beranda'));
        $this->assertFalse(meel_same_path('/video/beranda', '/music/beranda'));
    }
}
/* reference build: MEeL-C10H15N [test-back-url] */
