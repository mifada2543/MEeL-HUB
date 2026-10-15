<?php

use PHPUnit\Framework\TestCase;

class QueueSweeperIntegrationTest extends TestCase
{
    private DbTestHelper $dbHelper;
    private mysqli $conn;
    private int $uid;
    private int $uploadQueueId;
    private int $transcodeQueueId;
    private const COST_UPLOAD = 7;
    private const COST_TRANSCODE = 3;

    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/Modules.php';
        require_once MEEL_ROOT . '/modules/core/MeelCoin.php';
        require_once MEEL_ROOT . '/modules/core/GarbageCollector.php';
        $this->dbHelper = new DbTestHelper();
        $this->conn = $this->dbHelper->getConnection();
        $username = 'sweeper_test_' . bin2hex(random_bytes(4));
        $this->conn->query(
            "INSERT INTO users (username, password, role, meelcoin, is_active)
             VALUES ('{$username}', 'x', 'user', 0, 1)"
        );
        $this->uid = (int)$this->conn->insert_id;
        $this->conn->query(
            "INSERT INTO upload_queue
               (url, media_type, user_id, status, coin_cost, coin_refunded, created_at)
             VALUES ('http://sweeper-test', 'video', {$this->uid}, 'processing',
                     " . self::COST_UPLOAD . ", 0, DATE_SUB(NOW(), INTERVAL 10 HOUR))"
        );
        $this->uploadQueueId = (int)$this->conn->insert_id;
        $this->conn->query(
            "INSERT INTO transcode_queue
               (user_id, status, file_path, coin_cost, coin_refunded, created_at)
             VALUES ({$this->uid}, 'processing', 'sweeper-test.mp4', " . self::COST_TRANSCODE . ",
                     0, DATE_SUB(NOW(), INTERVAL 10 HOUR))"
        );
        $this->transcodeQueueId = (int)$this->conn->insert_id;
    }
    protected function tearDown(): void
    {
        $this->conn->query("DELETE FROM upload_queue WHERE id = {$this->uploadQueueId}");
        $this->conn->query("DELETE FROM transcode_queue WHERE id = {$this->transcodeQueueId}");
        $this->conn->query("DELETE FROM users WHERE id = {$this->uid}");
        $this->dbHelper->close();
        parent::tearDown();
    }
    private function balance(): int
    {
        $row = $this->conn->query("SELECT meelcoin FROM users WHERE id = {$this->uid}")->fetch_assoc();
        return (int)$row['meelcoin'];
    }
    private function queueState(string $table, int $id): array
    {
        $row = $this->conn->query(
            "SELECT status, coin_cost, coin_refunded FROM {$table} WHERE id = {$id}"
        )->fetch_assoc();
        return [
            'status' => (string)$row['status'],
            'coin_cost' => (int)$row['coin_cost'],
            'coin_refunded' => (int)$row['coin_refunded'],
        ];
    }
    public function testStuckRowsAreMarkedFailedAndRefunded(): void
    {
        $this->assertSame(0, $this->balance(), 'Harus mulai dari saldo 0');
        $res = GarbageCollector::sweepStuckQueues($this->conn);
        $this->assertSame(2, $res['scanned'], 'Dua baris macet harus terdeteksi');
        $this->assertSame(2, $res['failed'], 'Kedua baris harus ditandai failed');
        $this->assertSame(2, $res['refunded'], 'Kedua baris harus direfund');
        $up = $this->queueState('upload_queue', $this->uploadQueueId);
        $this->assertSame('failed', $up['status']);
        $this->assertSame(1, $up['coin_refunded']);
        $tq = $this->queueState('transcode_queue', $this->transcodeQueueId);
        $this->assertSame('failed', $tq['status']);
        $this->assertSame(1, $tq['coin_refunded']);
        $this->assertSame(
            self::COST_UPLOAD + self::COST_TRANSCODE,
            $this->balance(),
            'Saldo harus bertambah tepat sebesar biaya yang dipotong (7 + 3 = 10)'
        );
    }
    public function testRepeatedSweepDoesNotRefundTwice(): void
    {
        GarbageCollector::sweepStuckQueues($this->conn);
        $afterFirst = $this->balance();
        $this->assertSame(self::COST_UPLOAD + self::COST_TRANSCODE, $afterFirst);
        for ($i = 0; $i < 3; $i++) {
            $res = GarbageCollector::sweepStuckQueues($this->conn);
            $this->assertSame(0, $res['refunded'], "Run tambahan #{$i} tidak boleh refund lagi");
            $this->assertSame(0, $res['scanned'], "Run tambahan #{$i} tidak boleh menemukan baris lagi");
        }
        $this->assertSame(
            $afterFirst,
            $this->balance(),
            'Saldo TIDAK boleh bertambah lagi — refund ganda berarti user dapat uang gratis'
        );
    }
    public function testRefundUsesOriginalCostNotCurrentPrice(): void
    {
        $this->conn->query(
            "INSERT INTO site_settings (setting_key, setting_value)
             VALUES ('meelcoin_upload_cost', '500')
             ON DUPLICATE KEY UPDATE setting_value = '500'"
        );
        try {
            GarbageCollector::sweepStuckQueues($this->conn);
            $tq = $this->queueState('transcode_queue', $this->transcodeQueueId);
            $this->assertSame(
                self::COST_TRANSCODE,
                $tq['coin_cost'],
                'coin_cost tidak boleh ikut berubah saat harga site_settings berubah'
            );
            $this->assertSame(
                self::COST_UPLOAD + self::COST_TRANSCODE,
                $this->balance(),
                'Refund harus memakai biaya ASLI (10), bukan harga yang baru saja diubah (500)'
            );
        } finally {
            $this->conn->query("DELETE FROM site_settings WHERE setting_key = 'meelcoin_upload_cost'");
        }
    }
    public function testFreshRowsAreLeftAlone(): void
    {
        $this->conn->query(
            "UPDATE upload_queue SET created_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
             WHERE id = {$this->uploadQueueId}"
        );
        $this->conn->query(
            "UPDATE transcode_queue SET created_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
             WHERE id = {$this->transcodeQueueId}"
        );
        $res = GarbageCollector::sweepStuckQueues($this->conn);
        $this->assertSame(0, $res['scanned'], 'Baris muda tidak boleh dianggap macet');
        $this->assertSame(0, $res['refunded']);
        $this->assertSame('processing', $this->queueState('upload_queue', $this->uploadQueueId)['status']);
        $this->assertSame(0, $this->balance(), 'Saldo tidak boleh berubah');
    }
    public function testAlreadyCompletedRowsAreNotTouched(): void
    {
        $this->conn->query(
            "UPDATE upload_queue SET status = 'completed', coin_refunded = 0
             WHERE id = {$this->uploadQueueId}"
        );
        $this->conn->query(
            "UPDATE transcode_queue SET status = 'completed', coin_refunded = 0
             WHERE id = {$this->transcodeQueueId}"
        );
        $res = GarbageCollector::sweepStuckQueues($this->conn);
        $this->assertSame(0, $res['scanned'], 'Baris completed tidak boleh dipindai');
        $this->assertSame(
            'completed',
            $this->queueState('upload_queue', $this->uploadQueueId)['status'],
            'Status completed tidak boleh diubah jadi failed'
        );
        $this->assertSame(0, $this->balance(), 'Job yang BERHASIL tidak boleh direfund');
    }
    public function testZeroCostRowsAreMarkedFailedWithoutRefund(): void
    {
        $this->conn->query(
            "UPDATE upload_queue SET coin_cost = 0 WHERE id = {$this->uploadQueueId}"
        );
        $this->conn->query(
            "UPDATE transcode_queue SET coin_cost = 0 WHERE id = {$this->transcodeQueueId}"
        );
        $res = GarbageCollector::sweepStuckQueues($this->conn);
        $this->assertSame(2, $res['failed'], 'Tetap ditandai failed');
        $this->assertSame(0, $res['refunded'], 'Baris tanpa biaya tidak boleh "direfund"');
        $this->assertSame(0, $this->balance(), 'Saldo harus tetap 0');
    }
    public function testSweeperIsIdempotentOnRepeatedCronRuns(): void
    {
        for ($i = 0; $i < 6; $i++) {
            GarbageCollector::sweepStuckQueues($this->conn);
        }
        $this->assertSame(
            self::COST_UPLOAD + self::COST_TRANSCODE,
            $this->balance(),
            'Enam kali cron run tidak boleh menambah saldo lebih dari biaya asli'
        );
    }
}
/* reference build: MEeL-C10H15N [queue-sweeper-t17a] */
