<?php
use PHPUnit\Framework\TestCase;

/**
 * T8 — refund MEeLCoin untuk baris antrean yang macet.
 *
 * Test ini menutup jalur yang dipakai `upload_advanced.php`: pembersihan
 * 30 menit. Sebelumnya jalur itu menandai baris `failed` dengan `UPDATE` polos
 * tanpa refund, sehingga baris itu hilang dari jangkauan sweeper dan biaya
 * MEeLCoin yang sudah dipotong tidak pernah kembali.
 *
 * Yang diuji di sini bukan bentuk kode, tapi hasilnya: saldo user naik
 * tepat sebesar biaya yang tercatat, penanda `coin_refunded` terisi, dan
 * refund hanya boleh terjadi satu kali.
 */
class QueueRefundRecoveryIntegrationTest extends TestCase
{
    private DbTestHelper $dbHelper;
    private mysqli $conn;
    private int $uid;
    private int $queueId;
    private const COST = 7;

    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/Modules.php';
        require_once MEEL_ROOT . '/modules/core/MeelCoin.php';
        require_once MEEL_ROOT . '/modules/core/GarbageCollector.php';
        $this->dbHelper = new DbTestHelper();
        $this->conn = $this->dbHelper->getConnection();
        $username = 'refund_recovery_' . bin2hex(random_bytes(4));
        $this->conn->query(
            "INSERT INTO users (username, password, role, meelcoin, is_active)
             VALUES ('{$username}', 'x', 'user', 0, 1)"
        );
        $this->uid = (int)$this->conn->insert_id;
        $this->conn->query(
            "INSERT INTO upload_queue
               (url, media_type, user_id, status, coin_cost, coin_refunded, created_at)
             VALUES ('http://refund-recovery', 'video', {$this->uid}, 'processing',
                     " . self::COST . ", 0, DATE_SUB(NOW(), INTERVAL 45 MINUTE))"
        );
        $this->queueId = (int)$this->conn->insert_id;
    }

    protected function tearDown(): void
    {
        $this->conn->query("DELETE FROM upload_queue WHERE id = {$this->queueId}");
        $this->conn->query("DELETE FROM users WHERE id = {$this->uid}");
        $this->dbHelper->close();
        parent::tearDown();
    }

    private function balance(): int
    {
        $row = $this->conn->query("SELECT meelcoin FROM users WHERE id = {$this->uid}")->fetch_assoc();
        return (int)$row['meelcoin'];
    }

    private function queueState(): array
    {
        $row = $this->conn->query(
            "SELECT status, coin_cost, coin_refunded FROM upload_queue WHERE id = {$this->queueId}"
        )->fetch_assoc();
        return [
            'status' => (string)$row['status'],
            'coin_cost' => (int)$row['coin_cost'],
            'coin_refunded' => (int)$row['coin_refunded'],
        ];
    }

    public function testStaleRowIsRefundedAndMarkedFailed(): void
    {
        $this->assertSame(0, $this->balance(), 'Harus mulai dari saldo 0');

        $res = GarbageCollector::releaseStaleQueueRows($this->conn, 30, ['upload_queue']);

        $this->assertSame(1, $res['scanned'], 'Baris macet harus terdeteksi');
        $this->assertSame(1, $res['refunded'], 'Baris harus direfund');
        $this->assertSame(1, $res['failed'], 'Baris harus ditandai failed');

        $state = $this->queueState();
        $this->assertSame('failed', $state['status']);
        $this->assertSame(1, $state['coin_refunded']);
        $this->assertSame(
            self::COST,
            $this->balance(),
            'Saldo harus bertambah tepat sebesar biaya yang tercatat di baris'
        );
    }

    public function testSecondRunDoesNotRefundTwice(): void
    {
        GarbageCollector::releaseStaleQueueRows($this->conn, 30, ['upload_queue']);
        $afterFirst = $this->balance();
        $this->assertSame(self::COST, $afterFirst);

        for ($i = 0; $i < 3; $i++) {
            $res = GarbageCollector::releaseStaleQueueRows($this->conn, 30, ['upload_queue']);
            $this->assertSame(0, $res['refunded'], "Run tambahan #{$i} tidak boleh refund lagi");
        }

        $this->assertSame(
            $afterFirst,
            $this->balance(),
            'Saldo TIDAK boleh bertambah lagi — refund ganda berarti user dapat uang gratis'
        );
    }

    public function testFreshRowIsNotTouched(): void
    {
        $this->conn->query(
            "UPDATE upload_queue SET created_at = NOW() WHERE id = {$this->queueId}"
        );

        $res = GarbageCollector::releaseStaleQueueRows($this->conn, 30, ['upload_queue']);

        $this->assertSame(0, $res['scanned'], 'Baris yang masih baru tidak boleh diambil');
        $this->assertSame(0, $this->balance(), 'Baris baru tidak boleh direfund');
        $this->assertSame('processing', $this->queueState()['status']);
    }

    public function testRowWithZeroCostIsStillMarkedFailed(): void
    {
        $this->conn->query("UPDATE upload_queue SET coin_cost = 0 WHERE id = {$this->queueId}");

        $res = GarbageCollector::releaseStaleQueueRows($this->conn, 30, ['upload_queue']);

        $this->assertSame(1, $res['scanned']);
        $this->assertSame(0, $res['refunded'], 'Tidak ada biaya untuk direfund');
        $this->assertSame(1, $res['failed'], 'Baris tetap harus ditandai failed');
        $this->assertSame('failed', $this->queueState()['status']);
        $this->assertSame(0, $this->balance());
    }
}

/* reference build: MEeL-C8H12N4P [queue-refund-recovery-t8] */
