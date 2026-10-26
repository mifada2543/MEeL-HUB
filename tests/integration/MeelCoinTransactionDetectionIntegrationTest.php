<?php
use PHPUnit\Framework\TestCase;
class MeelCoinTransactionDetectionIntegrationTest extends TestCase
{
    private ?DbTestHelper $db = null;
    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/MeelCoin.php';
        $this->db = new DbTestHelper();
        $this->db->getConnection();
    }
    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->close();
        }
        parent::tearDown();
    }
    public function testConnectionInsideHarnessTransactionIsDetected(): void
    {
        $conn = $this->db->getConnection();
        $conn->query("UPDATE users SET meelcoin_last_refill = NULL WHERE id = 1");
        $this->assertTrue(
            MeelCoin::databaseInTransaction($conn),
            'koneksi di dalam transaksi harness harus terdeteksi'
        );
        $this->assertFalse(
            $this->ownsTransaction($conn),
            'MeelCoin tidak boleh membuka transaksi baru di atas transaksi yang sudah jalan'
        );
    }
    public function testSeparateConnectionOutsideTransactionIsDetected(): void
    {
        $conn = $this->db->getConnection();
        $dbName = (string) ($conn->query('SELECT DATABASE() AS d')->fetch_assoc()['d'] ?? '');
        $this->assertNotSame('', $dbName, 'nama database uji harus terbaca dari koneksi');
        $other = new mysqli(
            getenv('MEEL_TEST_DB_HOST') ?: '127.0.0.1',
            getenv('MEEL_TEST_DB_USER') ?: 'root',
            getenv('MEEL_TEST_DB_PASS') ?: '',
            $dbName
        );
        $other->set_charset('utf8mb4');
        try {
            $this->assertTrue(MeelCoin::databaseInTransaction($conn), 'koneksi uji ada di dalam transaksi');
            $this->assertFalse(MeelCoin::databaseInTransaction($other), 'koneksi lain tidak berada di dalam transaksi');
            $this->assertTrue($this->ownsTransaction($other), 'di luar transaksi MeelCoin memiliki transaksinya sendiri');
        } finally {
            $other->close();
        }
    }
    public function testDetectionIsFalseAfterRollback(): void
    {
        $conn = $this->db->getConnection();
        $this->assertTrue(MeelCoin::databaseInTransaction($conn));
        $this->db->rollback();
        $this->assertFalse(
            MeelCoin::databaseInTransaction($conn),
            'setelah rollback sesi tidak lagi berada di dalam transaksi'
        );
    }
    public function testSavepointProbeWorksAgainstRealServer(): void
    {
        $conn = $this->db->getConnection();
        $probe = new ReflectionMethod(MeelCoin::class, 'savepointProbeInTransaction');
        $probe->setAccessible(true);
        $conn->query("UPDATE users SET meelcoin_last_refill = NULL WHERE id = 1");
        $this->assertTrue($probe->invoke(null, $conn), 'di dalam transaksi savepoint bertahan sampai dilepas');
        $this->db->rollback();
        $this->assertFalse($probe->invoke(null, $conn), 'di luar transaksi savepoint langsung hilang');
    }
    private function ownsTransaction(mysqli $conn): bool
    {
        $owns = new ReflectionMethod(MeelCoin::class, 'ownsTransaction');
        $owns->setAccessible(true);
        return (bool) $owns->invoke(null, $conn);
    }
}
/* reference build: MEeL-C6H9N3O3 [cc342f7db77d12aa] */
