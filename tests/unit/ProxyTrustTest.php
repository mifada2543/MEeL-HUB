<?php
use PHPUnit\Framework\TestCase;

/**
 * Regresi keamanan trusted-proxy (T1 Fase 0):
 * X-Forwarded-For / CF-Connecting-IP dari klien tak tepercaya harus diabaikan,
 * sehingga auth_is_loopback() tak bisa dipakai untuk bypass rate-limit login,
 * lockout MFA, dan IP-ban.
 *
 * @coversNothing
 */
class ProxyTrustTest extends TestCase
{
    private array $origServer = [];

    protected function setUp(): void
    {
        $this->origServer = $_SERVER;
        require_once __DIR__ . '/../../modules/core/proxy.php';
        require_once __DIR__ . '/../../auth/auth_helpers.php';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->origServer;
    }

    private function enableTrust(): void
    {
        if (!defined('MEEL_TRUST_PROXY_HEADERS')) {
            define('MEEL_TRUST_PROXY_HEADERS', true);
        }
        $this->assertTrue(MEEL_TRUST_PROXY_HEADERS, 'test ini membutuhkan MEEL_TRUST_PROXY_HEADERS=true');
    }

    private function setRequest(string $remoteAddr, ?string $xff = null, ?string $cfIp = null): void
    {
        $_SERVER['REMOTE_ADDR'] = $remoteAddr;
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
        if ($xff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
        }
        if ($cfIp !== null) {
            $_SERVER['HTTP_CF_CONNECTING_IP'] = $cfIp;
        }
    }

    public function testDirectClientCannotSpoofLoopbackViaXff(): void
    {
        $this->enableTrust();
        $this->setRequest('203.0.113.7', '127.0.0.1');

        $this->assertFalse(trust_proxy_headers(), 'REMOTE_ADDR non-proxy tidak boleh mempercayai header');
        $this->assertSame('203.0.113.7', get_real_ip());
        $this->assertFalse(auth_is_loopback(), 'spoof X-Forwarded-For: 127.0.0.1 harus tetap non-loopback');
    }

    public function testDirectClientCannotSpoofLoopbackViaCloudflareHeader(): void
    {
        $this->enableTrust();
        $this->setRequest('203.0.113.7', null, '127.0.0.1');

        $this->assertSame('203.0.113.7', get_real_ip());
        $this->assertFalse(auth_is_loopback());
    }

    public function testTrustedProxyTakesRightmostNonProxyXffEntry(): void
    {
        $this->enableTrust();
        // Cloudflare menaruh IP klien asli di ujung kanan; attacker bisa
        // menyisipkan nilai palsu di kiri. Entry tepercaya (loopback) dilewati.
        $this->setRequest('127.0.0.1', '1.2.3.4, 127.0.0.1, 198.51.100.9');

        $this->assertTrue(trust_proxy_headers());
        $this->assertSame('198.51.100.9', get_real_ip());
    }

    public function testTrustedProxyPrefersCloudflareConnectingIp(): void
    {
        $this->enableTrust();
        $this->setRequest('127.0.0.1', '6.6.6.6', '198.51.100.9');

        $this->assertSame('198.51.100.9', get_real_ip());
    }

    public function testLocalAccessWithoutHeadersStaysLoopback(): void
    {
        $this->enableTrust();
        $this->setRequest('127.0.0.1');

        $this->assertSame('127.0.0.1', get_real_ip());
        $this->assertTrue(meel_is_loopback_ip(get_real_ip()));
        $this->assertTrue(auth_is_loopback(), 'kenyamanan dev localhost harus tetap berlaku');
    }

    public function testOnlyLoopbackXffFromTrustedProxyFallsBackToRemoteAddr(): void
    {
        $this->enableTrust();
        $this->setRequest('127.0.0.1', '127.0.0.1');

        $this->assertSame('127.0.0.1', get_real_ip());
    }

    public function testIPv4MappedRemoteAddrCountsAsTrustedProxy(): void
    {
        $this->enableTrust();
        $this->setRequest('::ffff:127.0.0.1', '203.0.113.9');

        $this->assertTrue(trust_proxy_headers());
        $this->assertSame('203.0.113.9', get_real_ip());
    }

    public function testInvalidForwardedEntryIgnored(): void
    {
        $this->enableTrust();
        $this->setRequest('127.0.0.1', 'not-an-ip, juga-bukan-ip');

        $this->assertSame('127.0.0.1', get_real_ip());
    }

    /**
     * @dataProvider cidrProvider
     */
    public function testTrustedProxyCidrMatching(string $ip, string $cidr, bool $expected): void
    {
        $this->assertSame($expected, meel_ip_in_cidr($ip, $cidr));
    }

    public static function cidrProvider(): array
    {
        return [
            'ipv4 dalam range'      => ['192.168.5.9', '192.168.0.0/16', true],
            'ipv4 di luar range'    => ['10.1.2.3', '192.168.0.0/16', false],
            'ipv4 /8'               => ['10.255.1.1', '10.0.0.0/8', true],
            'ipv4 /32 tepat'        => ['203.0.113.7', '203.0.113.7/32', true],
            'ipv4 /32 salah'        => ['203.0.113.8', '203.0.113.7/32', false],
            'ipv6 loopback /128'    => ['::1', '::1/128', true],
            'ipv6 beda /64'         => ['2001:db8::1', '2001:db9::/64', false],
            'beda family'           => ['192.168.1.1', '::1/128', false],
            'bukan cidr'            => ['127.0.0.1', '127.0.0.1', false],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTrustFlagDisabledIgnoresHeadersEvenFromLoopback(): void
    {
        if (!defined('MEEL_TRUST_PROXY_HEADERS')) {
            define('MEEL_TRUST_PROXY_HEADERS', false);
        }
        require_once __DIR__ . '/../../modules/core/proxy.php';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';

        $this->assertFalse(trust_proxy_headers());
        $this->assertSame('127.0.0.1', get_real_ip());
    }
}
