<?php
use PHPUnit\Framework\TestCase;
/* reference build: MEeL-C1H1N1O2 [2f6a0d5b8c41e937] */
class ActivityLoggerTest extends TestCase
{
    private array $origServer = [];
    private array $origSession = [];
    private bool $sessionStarted = false;
    protected function setUp(): void
    {
        parent::setUp();
        $this->origServer = $_SERVER;
        $this->origSession = $_SESSION ?? [];
        $this->sessionStarted = false;
    }
    protected function tearDown(): void
    {
        $_SERVER = $this->origServer;
        $_SESSION = $this->origSession;
        if ($this->sessionStarted && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->sessionStarted = false;
        parent::tearDown();
    }
    private function requireLogger(): void
    {
        if (!class_exists('ActivityLogger')) {
            require_once MEEL_ROOT . '/modules/core/activity_logger.php';
        }
    }
    private function resetStaticGuard(): void
    {
        $prop = new ReflectionProperty(ActivityLogger::class, 'hasRun');
        $prop->setAccessible(true);
        $prop->setValue(null, false);
    }
    public function testSkipTelemetryForHtmxRequest(): void
    {
        $this->requireLogger();
        $m = new ReflectionMethod(ActivityLogger::class, 'shouldSkipTelemetry');
        $m->setAccessible(true);
        $saved = $_SERVER;
        $_SERVER['HTTP_HX_REQUEST'] = 'true';
        $this->assertTrue($m->invoke(null), 'partial htmx tidak perlu telemetry');
        $_SERVER = $saved;
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->assertTrue($m->invoke(null), 'respons JSON tidak perlu telemetry');
        $_SERVER = $saved;
        $_SERVER['HTTP_ACCEPT'] = 'application/vnd.apple.mpegurl,application/x-mpegURL';
        $this->assertTrue($m->invoke(null), 'segment HLS tidak perlu telemetry');
        $_SERVER = $saved;
        $_SERVER['PHP_SELF'] = '/MEeL/video/stream.php';
        $this->assertTrue($m->invoke(null), 'endpoint streaming binary tidak perlu telemetry');
        $_SERVER = $saved;
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
        $_SERVER['PHP_SELF'] = '/MEeL/video/index.php';
        $this->assertFalse($m->invoke(null), 'halaman HTML biasa tetap perlu telemetry');
    }
    public function testThrottleWindowIsOneMinute(): void
    {
        $this->requireLogger();
        $this->assertSame(60, ActivityLogger::TOUCH_THROTTLE_SECONDS);
    }
    public function testIsThrottledOnlyForIdenticalSignatureInsideWindow(): void
    {
        $this->requireLogger();
        $is = new ReflectionMethod(ActivityLogger::class, 'isThrottled');
        $is->setAccessible(true);
        $sig = new ReflectionMethod(ActivityLogger::class, 'signature');
        $sig->setAccessible(true);
        $mark = new ReflectionMethod(ActivityLogger::class, 'markThrottled');
        $mark->setAccessible(true);
        $key = (new ReflectionClass(ActivityLogger::class))->getConstant('SESSION_KEY');
        $payload = [
            'last_page'  => 'Browsing Video Library',
            'device'     => 'Linux PC',
            'access_via' => 'Direct',
            'ip_address' => '127.0.0.1',
        ];
        unset($_SESSION[$key]);
        $this->assertFalse($is->invoke(null, $payload), 'payload pertama tidak pernah throttle');
        $mark->invoke(null, $payload);
        $this->assertTrue($is->invoke(null, $payload), 'payload identik di dalam window harus throttle');
        $moved = $payload;
        $moved['last_page'] = 'Menonton: Contoh';
        $this->assertFalse(
            $is->invoke(null, $moved),
            'perpindahan halaman harus langsung tercatat, tidak menunggu window habis'
        );
        $otherIp = $payload;
        $otherIp['ip_address'] = '203.0.113.9';
        $this->assertFalse($is->invoke(null, $otherIp), 'pergantian IP harus langsung tercatat');
        $_SESSION[$key] = ['sig' => $sig->invoke(null, $payload), 'ts' => time() - (ActivityLogger::TOUCH_THROTTLE_SECONDS + 1)];
        $this->assertFalse(
            $is->invoke(null, $payload),
            'di luar jendela throttle payload identik tetap ditulis (last_activity harus tetap segar)'
        );
    }
    public function testDetectDevice(): void
    {
        $this->requireLogger();
        $m = new ReflectionMethod(ActivityLogger::class, 'detectDevice');
        $m->setAccessible(true);
        $this->assertSame('Smartphone', $m->invoke(null, 'Mozilla/5.0 (Linux; Android 14)'));
        $this->assertSame('Linux PC', $m->invoke(null, 'Mozilla/5.0 (X11; Linux x86_64)'));
        $this->assertSame('Windows PC', $m->invoke(null, 'Mozilla/5.0 (Windows NT 10.0)'));
        $this->assertSame('Mac', $m->invoke(null, 'Mozilla/5.0 (Macintosh; Intel)'));
        $this->assertSame('iPhone', $m->invoke(null, 'Mozilla/5.0 (iPhone; CPU iPhone OS)'));
        $this->assertSame('Unknown', $m->invoke(null, 'curl/8.0'));
    }
    public function testOnRequestIsIdempotentAcrossRepeatedCalls(): void
    {
        $this->requireLogger();
        $this->resetStaticGuard();
        $_SESSION = [];
        $_SERVER['PHP_SELF'] = '/MEeL/video/index.php';
        $conn = $this->createMock(mysqli::class);
        $conn->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn(false);
        ActivityLogger::onRequest($conn);
        ActivityLogger::onRequest($conn);
        $this->addToAssertionCount(1);
    }
    public function testAnonymousRequestEntersGuestTouchPath(): void
    {
        $this->requireLogger();
        $this->resetStaticGuard();
        $_SESSION = [];
        $_SERVER['PHP_SELF'] = '/MEeL/video/index.php';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64)';
        unset($_SERVER['HTTP_HX_REQUEST'], $_SERVER['HTTP_ACCEPT']);
        $statements = [];
        $conn = $this->createMock(mysqli::class);
        $conn->method('prepare')->willReturnCallback(
            static function (string $query) use (&$statements) {
                $statements[] = $query;
                return false;
            }
        );
        ActivityLogger::onRequest($conn);
        $guestInserts = array_values(array_filter(
            $statements,
            static fn (string $s): bool => stripos($s, 'INSERT INTO users') !== false
        ));
        $this->assertCount(1, $guestInserts, 'tamu anonim harus masuk ke touchGuest()');
        $this->assertSame(
            0,
            count(array_filter($statements, static fn (string $s): bool => stripos($s, 'UPDATE users') !== false)),
            'tamu tidak boleh memakai jalur touchUser()'
        );
        $insert = $guestInserts[0];
        $this->assertMatchesRegularExpression(
            "/VALUES \(\?, \?, 'guest', 1, \?, \?, \?, \?, \?\)/",
            $insert,
            'tamu harus ditulis dengan is_active = 1'
        );
        $this->assertSame(
            7,
            substr_count($insert, '?'),
            'jumlah placeholder harus sama dengan jumlah nilai yang di-bind (7)'
        );
        $this->assertStringContainsString('is_active = 1', $insert, 'tamu yang kembali online harus diaktifkan lagi');
    }
    public function testHasRunGuardIsSetAfterFirstCall(): void
    {
        $this->requireLogger();
        $this->resetStaticGuard();
        $prop = new ReflectionProperty(ActivityLogger::class, 'hasRun');
        $prop->setAccessible(true);
        $this->assertFalse($prop->getValue(), 'guard harus false sebelum request pertama');
        $_SESSION = [];
        $_SERVER['PHP_SELF'] = '/MEeL/video/index.php';
        $conn = $this->createMock(mysqli::class);
        $conn->method('prepare')->willReturn(false);
        ActivityLogger::onRequest($conn);
        $this->assertTrue($prop->getValue(), 'guard harus true setelah request pertama');
    }
}
