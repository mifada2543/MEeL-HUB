<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class BanGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/proxy.php';
        require_once MEEL_ROOT . '/modules/auth/helpers/ban_guard.php';
    }

    /**
     * @dataProvider loopbackProvider
     */
    public function testLoopbackAddressesAreRecognized(string $ip): void
    {
        $this->assertTrue(meel_is_loopback_ip($ip), $ip . ' harus terdeteksi sebagai alamat server');
        $this->assertFalse(meel_ban_target_allowed($ip), $ip . ' tidak boleh menjadi target ban');
    }

    public static function loopbackProvider(): array
    {
        return [
            'ipv4' => ['127.0.0.1'],
            'ipv4 rentang' => ['127.25.3.4'],
            'ipv6' => ['::1'],
            'ipv4-mapped' => ['::ffff:127.0.0.1'],
            'localhost' => ['localhost'],
            'lo' => ['LO'],
            'unspecified' => ['0.0.0.0'],
            'unspecified mapped' => ['::ffff:0.0.0.0'],
        ];
    }

    /**
     * @dataProvider publicIpProvider
     */
    public function testPublicIpsRemainBannable(string $ip): void
    {
        $this->assertFalse(meel_is_loopback_ip($ip));
        $this->assertTrue(meel_ban_target_allowed($ip));
    }

    public static function publicIpProvider(): array
    {
        return [
            'ipv4' => ['203.0.113.9'],
            'ipv6' => ['2001:db8::1'],
        ];
    }

    /**
     * @dataProvider invalidTargetProvider
     */
    public function testInvalidTargetsAreRejected(string $target): void
    {
        $this->assertFalse(meel_ban_target_allowed($target));
    }

    public static function invalidTargetProvider(): array
    {
        return [
            'kosong' => [''],
            'hostname' => ['example.com'],
            'ip rusak' => ['999.1.1.1'],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLogoutForBanClearsLoggedInSession(): void
    {
        require_once MEEL_ROOT . '/modules/core/activity_logger.php';
        session_start();
        $_SESSION['user_id'] = 42;
        $_SESSION['role'] = 'user';
        $oldId = session_id();

        ActivityLogger::logoutForBan();

        $this->assertSame([], $_SESSION, 'sesi user harus dibersihkan menjadi guest');
        $this->assertNotSame($oldId, session_id(), 'guest harus mendapat sesi baru');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLogoutForBanKeepsGuestSession(): void
    {
        require_once MEEL_ROOT . '/modules/core/activity_logger.php';
        session_start();
        $_SESSION['guest_key'] = 'g_1';
        $oldId = session_id();

        ActivityLogger::logoutForBan();

        $this->assertSame('g_1', $_SESSION['guest_key'] ?? null);
        $this->assertSame($oldId, session_id());
    }
}
