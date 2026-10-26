<?php
use PHPUnit\Framework\TestCase;

class GuestActivityTrackingIntegrationTest extends TestCase
{
    private ?DbTestHelper $db = null;
    private array $origServer = [];
    private array $origSession = [];
    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/activity_logger.php';
        require_once MEEL_ROOT . '/modules/auth/helpers/user.php';
        $this->origServer = $_SERVER;
        $this->origSession = $_SESSION ?? [];
        $this->db = new DbTestHelper();
        $this->db->getConnection();
        $_SERVER['PHP_SELF'] = '/MEeL/video/index.php';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64)';
        unset($_SERVER['HTTP_HX_REQUEST'], $_SERVER['HTTP_ACCEPT']);
        $_SESSION = [];
    }
    protected function tearDown(): void
    {
        $_SERVER = $this->origServer;
        $_SESSION = $this->origSession;
        if ($this->db !== null) {
            $this->db->close();
        }
        parent::tearDown();
    }
    private function resetHasRunGuard(): void
    {
        $prop = new ReflectionProperty(ActivityLogger::class, 'hasRun');
        $prop->setAccessible(true);
        $prop->setValue(null, false);
    }
    private function forgetThrottle(): void
    {
        $key = (new ReflectionClass(ActivityLogger::class))->getConstant('SESSION_KEY');
        unset($_SESSION[$key]);
    }
    public function testAnonymousVisitorIsRecordedAsActiveGuest(): void
    {
        $conn = $this->db->getConnection();
        $this->resetHasRunGuard();
        ActivityLogger::onRequest($conn);
        $guest = $conn->query("SELECT * FROM users WHERE role = 'guest'")->fetch_assoc();
        $this->assertNotNull($guest, 'tamu anonim harus tercatat di tabel users');
        $this->assertSame(1, (int)$guest['is_active'], 'tamu online harus is_active = 1 agar tidak langsung dipurge');
        $this->assertSame('Browsing Video Library', $guest['last_page']);
        $this->assertSame('Linux PC', $guest['user_agent']);
        $this->assertSame('203.0.113.9', $guest['ip_address']);
        $this->assertStringStartsWith('g_', (string)$guest['username']);
        $this->assertNotEmpty((string)$guest['password'], 'baris tamu tetap butuh password acak (kolom NOT NULL)');
        $monitor = $conn->query(
            "SELECT username FROM users
              WHERE role = 'guest' AND last_activity >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        )->fetch_assoc();
        $this->assertNotNull($monitor, 'tamu aktif harus muncul di query monitor aktivitas admin');
    }
    public function testReturningVisitorUpdatesTheSameGuestRow(): void
    {
        $conn = $this->db->getConnection();
        $this->resetHasRunGuard();
        ActivityLogger::onRequest($conn);
        $first = $conn->query("SELECT id, last_page FROM users WHERE role = 'guest'")->fetch_assoc();
        $this->assertNotNull($first);
        $this->forgetThrottle();
        $_SERVER['PHP_SELF'] = '/MEeL/music/index.php';
        $this->resetHasRunGuard();
        ActivityLogger::onRequest($conn);
        $rows = $conn->query("SELECT id, last_page FROM users WHERE role = 'guest'");
        $this->assertSame(1, $rows->num_rows, 'kunjungan berikutnya harus UPDATE, bukan menambah baris tamu');
        $updated = $rows->fetch_assoc();
        $this->assertSame((int)$first['id'], (int)$updated['id']);
        $this->assertNotSame($first['last_page'], $updated['last_page']);
        $this->assertSame('Browsing Music Library', $updated['last_page']);
    }
    public function testActiveGuestSurvivesGuestPurge(): void
    {
        $conn = $this->db->getConnection();
        $this->resetHasRunGuard();
        ActivityLogger::onRequest($conn);
        $this->assertGreaterThan(0, $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'guest'")->fetch_assoc()['c'] ?? 0);
        purge_guest_users($conn);
        $remaining = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'guest'")->fetch_assoc()['c'];
        $this->assertSame(1, $remaining, 'tamu yang masih online tidak boleh dihapus oleh purge_guest_users()');
    }
}

/* reference build: MEeL-C2H5NO2 [a0d28a942a0fe642] */
