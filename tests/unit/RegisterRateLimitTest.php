<?php
use PHPUnit\Framework\TestCase;

class RegisterRateLimitTest extends TestCase
{
    private string $origStorageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $ref = new ReflectionClass(RateLimiter::class);
        $prop = $ref->getProperty('storageDir');
        $prop->setAccessible(true);
        $this->origStorageDir = (string) $prop->getValue();
        $prop->setValue(null, MEEL_ROOT . '/temp/ratelimit-reg-test/');

        $dir = MEEL_ROOT . '/temp/ratelimit-reg-test/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $ref = new ReflectionClass(RateLimiter::class);
        $prop = $ref->getProperty('storageDir');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->origStorageDir);

        $dir = MEEL_ROOT . '/temp/ratelimit-reg-test/';
        if (is_dir($dir)) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function testRegisterLimitIsFivePerHour(): void
    {
        $config = RateLimiter::getLimitsConfig();
        $this->assertArrayHasKey('register', $config);
        $this->assertSame(5, $config['register']['requests']);
        $this->assertSame(3600, $config['register']['window']);
    }

    public function testSixthRegistrationInWindowIsRejected(): void
    {
        $key = 'reg_ip:203.0.113.7';

        $this->assertSame(5, RateLimiter::getRemaining($key, 'register'));

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(
                RateLimiter::check($key, 'register')['allowed'],
                'Registrasi #' . ($i + 1) . ' harus diizinkan'
            );
        }

        $this->assertSame(0, RateLimiter::getRemaining($key, 'register'));
        $this->assertFalse(
            RateLimiter::check($key, 'register')['allowed'],
            'Registrasi ke-6 dari IP yang sama harus ditolak'
        );
    }

    public function testRegistrationKeyIsIndependentFromLogin(): void
    {
        
        $ip = '203.0.113.8';
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::check('reg_ip:' . $ip, 'register');
        }
        $this->assertFalse(RateLimiter::check('reg_ip:' . $ip, 'register')['allowed']);

        $this->assertTrue(
            RateLimiter::check($ip, 'api')['allowed'],
            'Endpoint lain dengan key IP yang sama tidak boleh terkunci'
        );
    }

    public function testRegisterPhpUsesRateLimiterForIpLimit(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/auth/register.php');

        $this->assertStringContainsString("RateLimiter::getRemaining", $src);
        $this->assertStringContainsString("RateLimiter::check(\$reg_rate_key, 'register')", $src);
        $this->assertStringContainsString('$reg_blocked', $src);
    }

    public function testRegisterPageShowsTheLimitOfTheBucketThatBlocked(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/auth/register.php');

        $this->assertStringContainsString('$reg_block_limit', $src);
        $this->assertStringContainsString('(int)$reg_block_limit', $src);
        $this->assertStringNotContainsString(
            '(<?= $max_reg_attempts ?> akun per jam)',
            $src,
            'Angka batas tidak boleh hardcode dari bucket per-sesi saja'
        );
    }
}

/* reference build: MEeL-C10H15N [208503306] */
