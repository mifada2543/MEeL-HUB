<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/core/MeelCoin.php';
require_once MEEL_ROOT . '/modules/core/QueueReconciler.php';

/**
 * Test integrasi MEeLCoin (butuh MySQL; memakai database uji MEeL-test).
 *
 * Catatan cakupan: harness ini memakai satu koneksi, jadi skenario *interleaving*
 * dua request paralel tidak bisa disimulasikan literal. Yang diuji di sini adalah
 * kontrak yang menjamin kebenaran saldo: guard saldo di statement UPDATE tunggal,
 * nilai tambah/kurang yang eksak, serta semantik siklus refill.
 *
 * @requires extension mysqli
 * @group integration
 * @covers MeelCoin
 */
class MeelCoinIntegrationTest extends TestCase
{
    private DbTestHelper $dbHelper;
    private mysqli $conn;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbHelper = new DbTestHelper();
        $this->conn     = $this->dbHelper->getConnection();
        $this->userId   = DbTestHelper::REGULAR_USER_ID;

        MeelCoin::clearCache();

        // Baseline deterministik (schema.sql juga sudah menyisipkan nilai ini).
        $this->setSetting('meelcoin_enabled', '1');
        $this->setSetting('meelcoin_advanced_cost', '10');
        $this->setSetting('meelcoin_upload_cost', '5');
        $this->setSetting('meelcoin_user_max', '25');
        $this->setSetting('meelcoin_user_refill', '15');
        $this->setSetting('meelcoin_refill_hours', '5');
    }

    protected function tearDown(): void
    {
        MeelCoin::clearCache();
        $this->dbHelper->rollback();
        $this->dbHelper->close();
        parent::tearDown();
    }

    // Helper

    private function setBalance(int $userId, int $balance, ?string $lastRefill = null): void
    {
        $stmt = $this->conn->prepare("UPDATE users SET meelcoin = ?, meelcoin_last_refill = ? WHERE id = ?");
        $stmt->bind_param("isi", $balance, $lastRefill, $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function lastRefill(int $userId): ?string
    {
        $stmt = $this->conn->prepare("SELECT meelcoin_last_refill FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['meelcoin_last_refill'] ?? null;
    }

    private function setSetting(string $key, string $value): void
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
        $stmt->close();
        MeelCoin::clearCache();
    }

    /** @return array<int, array{amount:int, balance_after:int, reason:string}> */
    private function coinLogs(int $userId, ?string $reason = null): array
    {
        $rows = [];
        if ($reason === null) {
            $stmt = $this->conn->prepare("SELECT amount, balance_after, reason FROM meelcoin_log WHERE user_id = ? ORDER BY id ASC");
            $stmt->bind_param("i", $userId);
        } else {
            $stmt = $this->conn->prepare("SELECT amount, balance_after, reason FROM meelcoin_log WHERE user_id = ? AND reason = ? ORDER BY id ASC");
            $stmt->bind_param("is", $userId, $reason);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'amount'        => (int)$row['amount'],
                'balance_after' => (int)$row['balance_after'],
                'reason'        => (string)$row['reason'],
            ];
        }
        $stmt->close();
        return $rows;
    }

    // spend()

    public function testSpendDeductsBalanceAndWritesLog(): void
    {
        $this->setBalance($this->userId, 20);

        [$ok, $err] = MeelCoin::spend($this->conn, $this->userId, 10, 'upload_advanced');

        $this->assertTrue($ok);
        $this->assertSame('', $err);
        $this->assertSame(10, MeelCoin::getBalance($this->conn, $this->userId));

        $logs = $this->coinLogs($this->userId, 'upload_advanced');
        $this->assertCount(1, $logs);
        $this->assertSame(-10, $logs[0]['amount']);
        $this->assertSame(10, $logs[0]['balance_after']);
    }

    public function testSpendRejectsInsufficientBalanceWithoutGoingNegative(): void
    {
        $this->setBalance($this->userId, 3);

        [$ok, $err] = MeelCoin::spend($this->conn, $this->userId, 10, 'upload_advanced');

        $this->assertFalse($ok);
        $this->assertStringContainsString('tidak cukup', $err);
        // Guard saldo: saldo tetap, tidak pernah minus, dan tidak ada log debit.
        $this->assertSame(3, MeelCoin::getBalance($this->conn, $this->userId));
        $this->assertCount(0, $this->coinLogs($this->userId, 'upload_advanced'));
    }

    public function testSpendGuardUsesCurrentDbValueNotStaleRead(): void
    {
        $this->setBalance($this->userId, 20);

        // Perubahan saldo "dari request lain" setelah nilai awal diketahui.
        $this->setBalance($this->userId, 8);

        // spend() dinilai dari saldo di DB saat UPDATE (8), bukan 20.
        [$ok, $err] = MeelCoin::spend($this->conn, $this->userId, 10, 'upload_advanced');
        $this->assertFalse($ok);
        $this->assertStringContainsString('tersedia: 8', $err);
        $this->assertSame(8, MeelCoin::getBalance($this->conn, $this->userId));

        // Dan saat saldo cukup, pengurangan dihitung dari nilai DB terkini.
        [$ok2] = MeelCoin::spend($this->conn, $this->userId, 5, 'upload_advanced');
        $this->assertTrue($ok2);
        $this->assertSame(3, MeelCoin::getBalance($this->conn, $this->userId));
    }

    public function testSpendWithZeroAmountIsNoop(): void
    {
        $this->setBalance($this->userId, 12);

        [$ok, $err] = MeelCoin::spend($this->conn, $this->userId, 0, 'upload_advanced');

        $this->assertTrue($ok);
        $this->assertSame('', $err);
        $this->assertSame(12, MeelCoin::getBalance($this->conn, $this->userId));
        $this->assertCount(0, $this->coinLogs($this->userId, 'upload_advanced'), 'Biaya 0 tidak boleh menulis log');
    }

    // refund()

    public function testRefundAddsCoinsAndWritesLog(): void
    {
        $this->setBalance($this->userId, 5);

        $this->assertTrue(MeelCoin::refund($this->conn, $this->userId, 10, 'upload_advanced_download_refund'));

        $this->assertSame(15, MeelCoin::getBalance($this->conn, $this->userId));
        $logs = $this->coinLogs($this->userId, 'upload_advanced_download_refund');
        $this->assertCount(1, $logs);
        $this->assertSame(10, $logs[0]['amount']);
        $this->assertSame(15, $logs[0]['balance_after']);
    }

    public function testRefundWithZeroAmountIsNoop(): void
    {
        $this->setBalance($this->userId, 5);

        $this->assertTrue(MeelCoin::refund($this->conn, $this->userId, 0, 'upload_advanced_download_refund'));

        $this->assertSame(5, MeelCoin::getBalance($this->conn, $this->userId));
        $this->assertCount(0, $this->coinLogs($this->userId, 'upload_advanced_download_refund'));
    }

    // refill()

    public function testRefillGrantsCappedAmountWithExactLogDelta(): void
    {
        // max 25, refill 15 → dari 23 hanya boleh nambah 2 (cap di DB).
        $this->setBalance($this->userId, 23, date('Y-m-d H:i:s', time() - 6 * 3600));

        $this->assertTrue(MeelCoin::refill($this->conn, $this->userId, 'user'));

        $this->assertSame(25, MeelCoin::getBalance($this->conn, $this->userId));
        $logs = $this->coinLogs($this->userId, 'refill');
        $this->assertCount(1, $logs);
        $this->assertSame(2, $logs[0]['amount'], 'Delta log harus nilai yang benar-benar ditambahkan');
        $this->assertSame(25, $logs[0]['balance_after']);
    }

    public function testRefillSkipsWhenCycleHasNotElapsed(): void
    {
        $this->setBalance($this->userId, 5, date('Y-m-d H:i:s', time() - 60));

        $this->assertFalse(MeelCoin::refill($this->conn, $this->userId, 'user'));
        $this->assertSame(5, MeelCoin::getBalance($this->conn, $this->userId));
    }

    public function testRefillResetsTimerWhenBalanceIsAtMax(): void
    {
        // Kondisi awal bug: saldo penuh, timer refill sudah lama lewat.
        $this->setBalance($this->userId, 25, date('Y-m-d H:i:s', time() - 6 * 3600));

        $this->assertFalse(MeelCoin::refill($this->conn, $this->userId, 'user'));
        $this->assertSame(25, MeelCoin::getBalance($this->conn, $this->userId));

        // Timer harus di-reset → tidak ada "refill tertunda" yang bisa menutup
        // potongan coin berikutnya.
        $reset = strtotime((string)$this->lastRefill($this->userId));
        $this->assertGreaterThan(time() - 60, $reset, 'Timer refill harus di-reset saat saldo penuh');
        $this->assertLessThanOrEqual(time() + 5, $reset);
    }

    /**
     * Regresi inti: upload berhasil, coin harus TETAP terpotong di request
     * berikutnya (dulu hilang karena refill tertunda langsung menutupnya).
     */
    public function testSpendIsNotMaskedByPendingRefillOnNextRequest(): void
    {
        $this->setBalance($this->userId, 25, date('Y-m-d H:i:s', time() - 6 * 3600));

        // Request 1: halaman upload dimuat (refill dievaluasi) lalu user upload.
        MeelCoin::refill($this->conn, $this->userId, 'user');
        [$ok] = MeelCoin::spend($this->conn, $this->userId, 10, 'upload_advanced');
        $this->assertTrue($ok);
        $this->assertSame(15, MeelCoin::getBalance($this->conn, $this->userId));

        // Request 2: buka halaman upload lagi → saldo tidak boleh melompat naik.
        MeelCoin::refill($this->conn, $this->userId, 'user');
        $this->assertSame(15, MeelCoin::getBalance($this->conn, $this->userId));

        // Dan upload kedua tetap terpotong normal.
        [$ok2] = MeelCoin::spend($this->conn, $this->userId, 10, 'upload_advanced');
        $this->assertTrue($ok2);
        $this->assertSame(5, MeelCoin::getBalance($this->conn, $this->userId));
    }

    public function testRefillIsNoopForAdmin(): void
    {
        $this->setBalance(DbTestHelper::ADMIN_USER_ID, 1, date('Y-m-d H:i:s', time() - 10 * 3600));

        $this->assertTrue(MeelCoin::refill($this->conn, DbTestHelper::ADMIN_USER_ID, 'admin'));
        $this->assertSame(1, MeelCoin::getBalance($this->conn, DbTestHelper::ADMIN_USER_ID));
    }

    public function testRefillUsesRoleSpecificMaxAndAmount(): void
    {
        $this->setSetting('meelcoin_member_max', '50');
        $this->setSetting('meelcoin_member_refill', '25');

        $this->setBalance(DbTestHelper::MEMBER_USER_ID, 30, date('Y-m-d H:i:s', time() - 6 * 3600));

        $this->assertTrue(MeelCoin::refill($this->conn, DbTestHelper::MEMBER_USER_ID, 'member'));
        $this->assertSame(50, MeelCoin::getBalance($this->conn, DbTestHelper::MEMBER_USER_ID));
    }

    // getRefillCountdown()

    public function testGetRefillCountdownFollowsPerUserTimestamp(): void
    {
        $user   = $this->userId;
        $member = DbTestHelper::MEMBER_USER_ID;

        $this->setBalance($user, 10, date('Y-m-d H:i:s', time() - 2 * 3600)); // siklus 5 jam
        $this->setBalance($member, 10, date('Y-m-d H:i:s'));                  // baru saja

        $userCountdown   = MeelCoin::getRefillCountdown($this->conn, $user, 'user');
        $memberCountdown = MeelCoin::getRefillCountdown($this->conn, $member, 'member');

        // ~3 jam tersisa vs ~5 jam — dulu (siklus global) keduanya identik.
        $this->assertEqualsWithDelta(3 * 3600, $userCountdown, 60);
        $this->assertEqualsWithDelta(5 * 3600, $memberCountdown, 60);
        $this->assertNotSame($userCountdown, $memberCountdown);
    }

    public function testGetRefillCountdownIsZeroWhenNeverRefilled(): void
    {
        $this->setBalance($this->userId, 10, null);

        $this->assertSame(0, MeelCoin::getRefillCountdown($this->conn, $this->userId, 'user'));
    }

    public function testGetRefillCountdownIsZeroForAdmin(): void
    {
        $this->assertSame(0, MeelCoin::getRefillCountdown($this->conn, DbTestHelper::ADMIN_USER_ID, 'admin'));
    }

    // QueueReconciler::refundIfNeeded()

    public function testQueueReconcilerRefundsOncePerQueue(): void
    {
        $this->setBalance($this->userId, 7);

        $reconciler = new QueueReconciler($this->conn);
        $method     = new ReflectionMethod(QueueReconciler::class, 'refundIfNeeded');
        $method->setAccessible(true);

        $queueId = 4242;

        // video → biaya 'advanced' = 10
        $this->assertTrue($method->invoke($reconciler, $this->userId, 'video', $queueId));
        $this->assertSame(17, MeelCoin::getBalance($this->conn, $this->userId));

        // Reconcile kedua untuk queue yang sama tidak boleh refund dobel.
        $this->assertFalse($method->invoke($reconciler, $this->userId, 'video', $queueId));
        $this->assertSame(17, MeelCoin::getBalance($this->conn, $this->userId));

        $logs = $this->coinLogs($this->userId, 'reconcile_refund_q' . $queueId);
        $this->assertCount(1, $logs);
        $this->assertSame(10, $logs[0]['amount']);
    }

    public function testQueueReconcilerSkipsWhenMeelCoinDisabled(): void
    {
        $this->setSetting('meelcoin_enabled', '0');
        $this->setBalance($this->userId, 7);

        $reconciler = new QueueReconciler($this->conn);
        $method     = new ReflectionMethod(QueueReconciler::class, 'refundIfNeeded');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($reconciler, $this->userId, 'music', 77));
        $this->assertSame(7, MeelCoin::getBalance($this->conn, $this->userId));
    }
}

/* reference build: MEeL-C9H11NO2 [1738fa77212209fc] */
