<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/core/MeelCoin.php';

/**
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
        $this->conn = $this->dbHelper->getConnection();
        $this->userId = DbTestHelper::REGULAR_USER_ID;

        MeelCoin::clearCache();

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
                'amount' => (int)$row['amount'],
                'balance_after' => (int)$row['balance_after'],
                'reason' => (string)$row['reason'],
            ];
        }
        $stmt->close();
        return $rows;
    }

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
        $this->assertSame(3, MeelCoin::getBalance($this->conn, $this->userId));
        $this->assertCount(0, $this->coinLogs($this->userId, 'upload_advanced'));
    }

    public function testSpendGuardUsesCurrentDbValueNotStaleRead(): void
    {
        $this->setBalance($this->userId, 20);

        $this->setBalance($this->userId, 8);

        [$ok, $err] = MeelCoin::spend($this->conn, $this->userId, 10, 'upload_advanced');
        $this->assertFalse($ok);
        $this->assertStringContainsString('tersedia: 8', $err);
        $this->assertSame(8, MeelCoin::getBalance($this->conn, $this->userId));

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

    public function testRefillGrantsCappedAmountWithExactLogDelta(): void
    {
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

    /**
     * DULU: `refill()` me-reset timer_refill setiap kali saldo sedang
     * penuh. Efeknya, countdown di UI selalu kembali ke 5 jam begitu
     * halamannya di-refresh — jadi hitung mundurnya tidak pernah turun.
     *
     * SEKARANG: timer hanya bergerak saat isi ulang benar-benar terjadi,
     * atau saat saldo turun dari maksimum (lewat spend). Membuka halaman
     * tidak mengubah apa pun.
     */
    public function testRefillDoesNotMoveTheTimerWhileBalanceIsAtMax(): void
    {
        $anchor = date('Y-m-d H:i:s', time() - 6 * 3600);
        $this->setBalance($this->userId, 25, $anchor);

        $this->assertFalse(MeelCoin::refill($this->conn, $this->userId, 'user'));
        $this->assertSame(25, MeelCoin::getBalance($this->conn, $this->userId));

        $this->assertSame(
            $anchor,
            $this->lastRefill($this->userId),
            'Timer refill tidak boleh bergerak hanya karena halaman dibuka — '
            . 'itulah yang membuat countdown mundur ke 5 jam tiap refresh'
        );
    }

    /**
     * Jendela isi ulang dimulai saat saldo turun dari maksimum, bukan
     * saat halaman dibuka. Ini yang menahan penumpukan refill.
     */
    public function testSpendingFromFullBalanceStartsTheRefillWindow(): void
    {
        $this->setBalance($this->userId, 25, date('Y-m-d H:i:s', time() - 6 * 3600));

        [$ok] = MeelCoin::spend($this->conn, $this->userId, 10, 'upload_advanced');
        $this->assertTrue($ok);
        $this->assertSame(15, MeelCoin::getBalance($this->conn, $this->userId));

        $reset = strtotime((string) $this->lastRefill($this->userId));
        $this->assertGreaterThan(time() - 60, $reset, 'Menurun dari maksimum harus memulai jendela refill baru');
        $this->assertLessThanOrEqual(time() + 5, $reset);
    }

    /**
     * Countdown MEeLCoin tidak boleh pernah mundur.
     *
     * Gejala yang dilaporkan: setiap refresh, hitung mundur kembali ke 5
     * jam — jadi tidak pernah turun sama sekali. Di versi lama, timer
     * ditulis ulang setiap kali halaman dibuka, sehingga countdown
     * terkunci permanen di siklus penuh.
     */
    public function testRefillCountdownSurvivesARefreshUnchanged(): void
    {
        $this->setBalance($this->userId, 25, date('Y-m-d H:i:s', time() - 2 * 3600));

        MeelCoin::refill($this->conn, $this->userId, 'user');
        $first = MeelCoin::getRefillCountdown($this->conn, $this->userId, 'user');

        MeelCoin::refill($this->conn, $this->userId, 'user');
        $second = MeelCoin::getRefillCountdown($this->conn, $this->userId, 'user');

        $this->assertEqualsWithDelta(
            3 * 3600,
            $first,
            60,
            'Countdown harus 3 jam (siklus 5 jam dikurangi 2 jam), bukan 5 jam penuh'
        );
        $this->assertEqualsWithDelta($first, $second, 5, 'Refresh tidak boleh mengubah countdown');
        $this->assertLessThanOrEqual($second, $first, 'Countdown tidak boleh mundur');
    }

    /**
     * Countdown diukur terhadap jam database, jadi nilainya benar walau
     * zona waktu PHP dan MySQL berbeda.
     */
    public function testRefillCountdownIsMeasuredAgainstTheDatabaseClock(): void
    {
        $this->setBalance($this->userId, 10, date('Y-m-d H:i:s', time() - 90 * 60));

        $countdown = MeelCoin::getRefillCountdown($this->conn, $this->userId, 'user');

        $this->assertEqualsWithDelta(
            (5 * 3600) - (90 * 60),
            $countdown,
            60,
            'Countdown harus dihitung dari jam database'
        );
    }

    public function testSpendIsNotMaskedByPendingRefillOnNextRequest(): void
    {
        $this->setBalance($this->userId, 25, date('Y-m-d H:i:s', time() - 6 * 3600));

        MeelCoin::refill($this->conn, $this->userId, 'user');
        [$ok] = MeelCoin::spend($this->conn, $this->userId, 10, 'upload_advanced');
        $this->assertTrue($ok);
        $this->assertSame(15, MeelCoin::getBalance($this->conn, $this->userId));

        MeelCoin::refill($this->conn, $this->userId, 'user');
        $this->assertSame(15, MeelCoin::getBalance($this->conn, $this->userId));

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

    public function testGetRefillCountdownFollowsPerUserTimestamp(): void
    {
        $user = $this->userId;
        $member = DbTestHelper::MEMBER_USER_ID;

        $this->setBalance($user, 10, date('Y-m-d H:i:s', time() - 2 * 3600));
        $this->setBalance($member, 10, date('Y-m-d H:i:s'));

        $userCountdown = MeelCoin::getRefillCountdown($this->conn, $user, 'user');
        $memberCountdown = MeelCoin::getRefillCountdown($this->conn, $member, 'member');

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
}

/* reference build: MEeL-C9H11NO2 [1738fa77212209fc] */