<?php

use PHPUnit\Framework\TestCase;

/** @coversNothing */
class SameOriginRefererTest extends TestCase
{
    private const HOST = 'meel.my.id';

    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/helpers/url.php';
        if (!defined('MEEL_CANONICAL_HOST')) {
            define('MEEL_CANONICAL_HOST', self::HOST);
        }
    }

    /** @dataProvider acceptedProvider */
    public function testSameOriginRefererAccepted(string $ref): void
    {
        $this->assertSame($ref, meel_same_origin_referer($ref));
    }

    public static function acceptedProvider(): array
    {
        return [
            'https same origin'      => ['https://meel.my.id/video/beranda'],
            'http same origin'       => ['http://meel.my.id/music/beranda'],
            'host case insensitive'  => ['https://MEEL.MY.ID/x'],
            'port on host'           => ['https://meel.my.id:8080/x'],
            'path and query'         => ['https://meel.my.id/music/watch?v=1&t=2'],
        ];
    }

    /** @dataProvider rejectedProvider */
    public function testCrossOriginOrMalformedRefererRejected(string $ref): void
    {
        $this->assertNull(meel_same_origin_referer($ref), 'referer harus ditolak: ' . $ref);
    }

    public static function rejectedProvider(): array
    {
        return [
            'cross host'            => ['https://evil.com/steal'],
            'localhost hardcoded'   => ['http://localhost/x'],
            '127.0.0.1 hardcoded'   => ['http://127.0.0.1/x'],
            'protocol relative'     => ['//evil.com/x'],
            'javascript scheme'     => ['javascript:alert(1)'],
            'data scheme'           => ['data:text/html,<script>alert(1)</script>'],
            'suffix trick'          => ['https://meel.my.id.evil.com/x'],
            'subdomain trick'       => ['https://evil.meel.my.id/x'],
            'empty'                 => [''],
            'userinfo trick'        => ['https://meel.my.id@evil.com/x'],
        ];
    }

    public function testBackUrlFallsBackWhenRefererMissing(): void
    {
        $this->assertSame('beranda', meel_back_url('beranda', ['beranda']));
    }

    public function testBackUrlIgnoresBlockedPages(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://meel.my.id/admin/edit-music?id=1';
        $this->assertSame('default', meel_back_url('default'));
        unset($_SERVER['HTTP_REFERER']);
    }
}
