<?php

use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/core/MeelCoin.php';
require_once MEEL_ROOT . '/modules/core/UploadAttempt.php';

class UploadAccountingIntegrationTest extends TestCase
{
    private DbTestHelper $dbHelper;
    private mysqli $conn;
    private int $userId;
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbHelper = new DbTestHelper();
        $this->conn = $this->dbHelper->getConnection();
        $this->userId = DbTestHelper::REGULAR_USER_ID;
        MeelCoin::clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            } elseif (is_dir($file)) {
                $this->removeDir($file);
            }
        }
        MeelCoin::clearCache();
        $this->dbHelper->rollback();
        $this->dbHelper->close();
        parent::tearDown();
    }

    private function setBalance(int $userId, int $balance): void
    {
        $stmt = $this->conn->prepare(
            "UPDATE users
                SET meelcoin = ?, meelcoin_held = 0, meelcoin_last_refill = NULL
              WHERE id = ?"
        );
        $stmt->bind_param("ii", $balance, $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @return array{attempt: array<string,mixed>, hold_id: int}
     */
    private function attemptWithHold(int $amount = 5, int $userId = 0): array
    {
        $userId = $userId > 0 ? $userId : $this->userId;
        $attempt = UploadAttempt::create($this->conn, $userId, 'music');
        $this->assertNotNull($attempt, 'Attempt harus dibuat untuk user aktif non-guest');

        $hold = MeelCoin::hold($this->conn, $userId, $amount, 'upload', 'music');
        $this->assertTrue($hold['ok'], $hold['error']);
        $this->assertGreaterThan(0, $hold['hold_id']);
        $this->assertTrue(
            UploadAttempt::attachHold($this->conn, (int)$attempt['id'], (int)$hold['hold_id'], $userId),
            'Hold harus bisa diikat ke attempt user yang sama'
        );

        return ['attempt' => $attempt, 'hold_id' => (int)$hold['hold_id']];
    }

    private function insertMusic(int $userId): int
    {
        $title = 'Accounting ' . bin2hex(random_bytes(4));
        $filename = 'accounting_' . bin2hex(random_bytes(4)) . '.ogg';
        $artist = 'Test';
        $stmt = $this->conn->prepare(
            "INSERT INTO music (title, artist, filename, user_id) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("sssi", $title, $artist, $filename, $userId);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * @return array<int, array{amount:int,balance_after:int,reason:string,hold_id:?int}>
     */
    private function coinLogs(int $userId, ?string $reason = null): array
    {
        $sql = "SELECT amount, balance_after, reason, hold_id
                  FROM meelcoin_log WHERE user_id = ?";
        if ($reason !== null) {
            $sql .= " AND reason = ?";
        }
        $sql .= " ORDER BY id ASC";
        $stmt = $this->conn->prepare($sql);
        if ($reason !== null) {
            $stmt->bind_param("is", $userId, $reason);
        } else {
            $stmt->bind_param("i", $userId);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'amount' => (int)$row['amount'],
                'balance_after' => (int)$row['balance_after'],
                'reason' => (string)$row['reason'],
                'hold_id' => $row['hold_id'] === null ? null : (int)$row['hold_id'],
            ];
        }
        $stmt->close();
        return $rows;
    }

    private function holdStatus(int $holdId): string
    {
        $stmt = $this->conn->prepare("SELECT status FROM coin_holds WHERE id = ?");
        $stmt->bind_param("i", $holdId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (string)($row['status'] ?? '');
    }

    private function attemptRow(int $attemptId): ?array
    {
        return UploadAttempt::get($this->conn, $attemptId);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function makeTempDir(): string
    {
        $dir = MEEL_HDD_BASE . '/upload-accounting-' . bin2hex(random_bytes(5));
        $this->assertTrue(mkdir($dir, 0775, true));
        $this->tempFiles[] = $dir;
        return $dir;
    }

    public function testHoldReservesFundsWithoutChangingTotal(): void
    {
        $this->setBalance($this->userId, 20);

        $hold = MeelCoin::hold($this->conn, $this->userId, 5, 'upload', 'video');
        $this->assertTrue($hold['ok'], $hold['error']);
        $this->assertSame(15, $hold['available']);
        $this->assertSame(5, $hold['held']);

        $summary = MeelCoin::getBalanceSummary($this->conn, $this->userId);
        $this->assertSame(['total' => 20, 'held' => 5, 'available' => 15], $summary);
        $this->assertSame(20, MeelCoin::getBalance($this->conn, $this->userId));

        $row = MeelCoin::getHold($this->conn, (int)$hold['hold_id']);
        $this->assertNotNull($row);
        $this->assertSame('held', (string)$row['status']);
        $this->assertSame(5, (int)$row['amount']);
        $this->assertCount(0, $this->coinLogs($this->userId), 'Reservasi tidak boleh menulis log debit');

        $release = MeelCoin::releaseHold($this->conn, (int)$hold['hold_id'], 'cancelled');
        $this->assertTrue($release['ok'], $release['error']);
        $this->assertSame(5, $release['released']);
        $this->assertSame('released', $this->holdStatus((int)$hold['hold_id']));
        $this->assertSame(
            ['total' => 20, 'held' => 0, 'available' => 20],
            MeelCoin::getBalanceSummary($this->conn, $this->userId)
        );
        $this->assertCount(0, $this->coinLogs($this->userId), 'Release tidak boleh membuat log debit/refund');
    }

    public function testSecondHoldCannotReuseReservedFunds(): void
    {
        $this->setBalance($this->userId, 6);

        $first = MeelCoin::hold($this->conn, $this->userId, 5, 'upload', 'video');
        $this->assertTrue($first['ok'], $first['error']);

        $second = MeelCoin::hold($this->conn, $this->userId, 5, 'upload', 'audio');
        $this->assertFalse($second['ok']);
        $this->assertStringContainsString('tersedia: 1', $second['error']);
        $this->assertSame(
            ['total' => 6, 'held' => 5, 'available' => 1],
            MeelCoin::getBalanceSummary($this->conn, $this->userId)
        );
    }

    public function testCaptureVerifiesMediaAttemptAndFreshHeartbeat(): void
    {
        $this->setBalance($this->userId, 20);
        ['attempt' => $attempt, 'hold_id' => $holdId] = $this->attemptWithHold();
        $musicId = $this->insertMusic($this->userId);

        $capture = MeelCoin::captureHoldInTransaction(
            $this->conn,
            $holdId,
            $this->userId,
            'upload',
            (int)$attempt['id'],
            'music',
            $musicId
        );
        $this->assertTrue($capture['ok'], $capture['error']);
        $this->assertSame(5, $capture['amount']);
        $this->assertSame(15, $capture['balance']);
        $this->assertSame('captured', $this->holdStatus($holdId));
        $this->assertSame(
            ['total' => 15, 'held' => 0, 'available' => 15],
            MeelCoin::getBalanceSummary($this->conn, $this->userId)
        );

        $logs = $this->coinLogs($this->userId);
        $this->assertCount(1, $logs);
        $this->assertSame(-5, $logs[0]['amount']);
        $this->assertSame(15, $logs[0]['balance_after']);
        $this->assertSame($holdId, $logs[0]['hold_id']);

        $attemptAfter = $this->attemptRow((int)$attempt['id']);
        $this->assertSame('active', (string)$attemptAfter['status']);
        $this->assertSame('coin_captured', (string)$attemptAfter['stage']);

        $second = MeelCoin::captureHoldInTransaction(
            $this->conn,
            $holdId,
            $this->userId,
            'upload',
            (int)$attempt['id'],
            'music',
            $musicId
        );
        $this->assertTrue($second['ok']);
        $this->assertSame(0, $second['amount']);
        $this->assertSame('captured', $second['status']);
        $this->assertSame(15, MeelCoin::getBalance($this->conn, $this->userId));
        $this->assertCount(1, $this->coinLogs($this->userId), 'Capture kedua tidak boleh menulis debit lagi');
    }

    public function testCaptureRejectsStaleClientHeartbeat(): void
    {
        $this->setBalance($this->userId, 20);
        ['attempt' => $attempt, 'hold_id' => $holdId] = $this->attemptWithHold();
        $musicId = $this->insertMusic($this->userId);
        $attemptId = (int)$attempt['id'];

        $stmt = $this->conn->prepare(
            "UPDATE upload_attempts
                SET client_heartbeat_at = DATE_SUB(NOW(), INTERVAL 121 SECOND)
              WHERE id = ?"
        );
        $stmt->bind_param("i", $attemptId);
        $stmt->execute();
        $stmt->close();

        $capture = MeelCoin::captureHoldInTransaction(
            $this->conn,
            $holdId,
            $this->userId,
            'upload',
            $attemptId,
            'music',
            $musicId
        );
        $this->assertFalse($capture['ok']);
        $this->assertStringContainsString('Heartbeat', $capture['error']);
        $this->assertSame(
            ['total' => 20, 'held' => 5, 'available' => 15],
            MeelCoin::getBalanceSummary($this->conn, $this->userId)
        );
        $this->assertSame('held', $this->holdStatus($holdId));
        $this->assertCount(0, $this->coinLogs($this->userId));
    }

    public function testCaptureRejectsMediaOwnedByAnotherUser(): void
    {
        $this->setBalance($this->userId, 20);
        ['attempt' => $attempt, 'hold_id' => $holdId] = $this->attemptWithHold();
        $otherMusicId = $this->insertMusic(DbTestHelper::MEMBER_USER_ID);

        $capture = MeelCoin::captureHoldInTransaction(
            $this->conn,
            $holdId,
            $this->userId,
            'upload',
            (int)$attempt['id'],
            'music',
            $otherMusicId
        );
        $this->assertFalse($capture['ok']);
        $this->assertStringContainsString('Baris media', $capture['error']);
        $this->assertSame(20, MeelCoin::getBalance($this->conn, $this->userId));
        $this->assertSame(5, MeelCoin::getHeld($this->conn, $this->userId));
    }

    public function testCaptureRejectsHoldOwnedByAnotherUser(): void
    {
        $this->setBalance($this->userId, 20);
        ['attempt' => $attempt, 'hold_id' => $holdId] = $this->attemptWithHold();

        $capture = MeelCoin::captureHoldInTransaction(
            $this->conn,
            $holdId,
            DbTestHelper::MEMBER_USER_ID,
            'upload',
            (int)$attempt['id']
        );
        $this->assertFalse($capture['ok']);
        $this->assertStringContainsString('bukan milik user', $capture['error']);
        $this->assertSame(20, MeelCoin::getBalance($this->conn, $this->userId));
        $this->assertSame(5, MeelCoin::getHeld($this->conn, $this->userId));
    }

    public function testStaleHoldExpiresWithoutDebit(): void
    {
        $this->setBalance($this->userId, 20);
        $hold = MeelCoin::hold($this->conn, $this->userId, 5, 'upload', 'video');
        $this->assertTrue($hold['ok'], $hold['error']);

        $stmt = $this->conn->prepare(
            "UPDATE coin_holds
                SET last_heartbeat = DATE_SUB(NOW(), INTERVAL 1000 SECOND)
              WHERE id = ?"
        );
        $stmt->bind_param("i", $hold['hold_id']);
        $stmt->execute();
        $stmt->close();

        $this->assertSame(1, MeelCoin::releaseExpiredHolds($this->conn));
        $this->assertSame('expired', $this->holdStatus((int)$hold['hold_id']));
        $this->assertSame(
            ['total' => 20, 'held' => 0, 'available' => 20],
            MeelCoin::getBalanceSummary($this->conn, $this->userId)
        );
        $this->assertCount(0, $this->coinLogs($this->userId));
    }

    public function testHeartbeatRejectsWrongOwnerAndStopsAfterRelease(): void
    {
        $this->setBalance($this->userId, 20);
        ['attempt' => $attempt, 'hold_id' => $holdId] = $this->attemptWithHold();
        $token = (string)$attempt['token'];
        $attemptId = (int)$attempt['id'];

        $wrong = UploadAttempt::touchByToken($this->conn, $token, DbTestHelper::MEMBER_USER_ID);
        $this->assertFalse($wrong['ok']);
        $this->assertSame('not_found', $wrong['status']);

        $ok = UploadAttempt::touchByToken($this->conn, $token, $this->userId);
        $this->assertTrue($ok['ok']);
        $this->assertSame('active', $ok['status']);
        $this->assertSame($holdId, $ok['hold_id']);

        $this->assertTrue(MeelCoin::releaseHold($this->conn, $holdId, 'cancelled')['ok']);

        $after = UploadAttempt::touchByToken($this->conn, $token, $this->userId);
        $this->assertFalse($after['ok']);
        $this->assertSame('not_found', $after['status']);
        $this->assertSame('failed', (string)$this->attemptRow($attemptId)['status']);
        $this->assertSame(20, MeelCoin::getBalance($this->conn, $this->userId));
        $this->assertSame(0, MeelCoin::getHeld($this->conn, $this->userId));
    }

    public function testFailReleasesHoldAndOnlyCleansAllowedPaths(): void
    {
        $this->setBalance($this->userId, 20);
        ['attempt' => $attempt, 'hold_id' => $holdId] = $this->attemptWithHold();
        $attemptId = (int)$attempt['id'];

        $partialDir = $this->makeTempDir();
        $partialFile = $partialDir . '/segment.ts';
        file_put_contents($partialFile, 'partial');
        $outside = MEEL_ROOT . '/upload-accounting-outside-' . bin2hex(random_bytes(5)) . '.txt';
        file_put_contents($outside, 'keep');
        $this->tempFiles[] = $outside;

        UploadAttempt::addCleanupPaths($this->conn, $attemptId, [$partialFile, $outside]);

        $failed = UploadAttempt::fail($this->conn, $attemptId, 'client_abort');
        $this->assertTrue($failed['ok']);
        $this->assertFalse($failed['captured']);
        $this->assertSame(5, $failed['released']);
        $this->assertSame('released', $this->holdStatus($holdId));
        $this->assertSame('failed', (string)$this->attemptRow($attemptId)['status']);
        $this->assertSame(0, MeelCoin::getHeld($this->conn, $this->userId));
        $this->assertSame(20, MeelCoin::getBalance($this->conn, $this->userId));
        $this->assertFileDoesNotExist($partialFile);
        $this->assertFileExists($outside, 'Cleanup tidak boleh menghapus file di luar root aman');

        @unlink($outside);
    }

    public function testSweepStaleAttemptReleasesHold(): void
    {
        $this->setBalance($this->userId, 20);
        ['attempt' => $attempt, 'hold_id' => $holdId] = $this->attemptWithHold();
        $attemptId = (int)$attempt['id'];

        $stmt = $this->conn->prepare(
            "UPDATE upload_attempts
                SET last_heartbeat = DATE_SUB(NOW(), INTERVAL 1000 SECOND)
              WHERE id = ?"
        );
        $stmt->bind_param("i", $attemptId);
        $stmt->execute();
        $stmt->close();

        $sweep = UploadAttempt::sweepStale($this->conn, 60);
        $this->assertSame(1, $sweep['failed']);
        $this->assertSame(0, $sweep['completed']);
        $this->assertSame('failed', (string)$this->attemptRow($attemptId)['status']);
        $this->assertSame('released', $this->holdStatus($holdId));
        $this->assertSame(
            ['total' => 20, 'held' => 0, 'available' => 20],
            MeelCoin::getBalanceSummary($this->conn, $this->userId)
        );
    }

    public function testSweepAfterCaptureDoesNotDeleteValidFiles(): void
    {
        $this->setBalance($this->userId, 20);
        ['attempt' => $attempt, 'hold_id' => $holdId] = $this->attemptWithHold();
        $attemptId = (int)$attempt['id'];
        $musicId = $this->insertMusic($this->userId);

        $capture = MeelCoin::captureHoldInTransaction(
            $this->conn,
            $holdId,
            $this->userId,
            'upload',
            $attemptId,
            'music',
            $musicId
        );
        $this->assertTrue($capture['ok'], $capture['error']);

        $valid = $this->makeTempDir() . '/final.ogg';
        file_put_contents($valid, 'valid-media');
        UploadAttempt::addCleanupPaths($this->conn, $attemptId, [$valid]);

        $stmt = $this->conn->prepare(
            "UPDATE upload_attempts
                SET last_heartbeat = DATE_SUB(NOW(), INTERVAL 1000 SECOND)
              WHERE id = ?"
        );
        $stmt->bind_param("i", $attemptId);
        $stmt->execute();
        $stmt->close();

        $sweep = UploadAttempt::sweepStale($this->conn, 60);
        $this->assertSame(0, $sweep['failed']);
        $this->assertSame(1, $sweep['completed']);
        $this->assertSame('completed', (string)$this->attemptRow($attemptId)['status']);
        $this->assertSame(15, MeelCoin::getBalance($this->conn, $this->userId));
        $this->assertFileExists($valid, 'File media yang sudah capture tidak boleh dihapus sweeper');
    }

    public function testInactiveUserCannotReserveOrCreateAttempt(): void
    {
        $this->setBalance($this->userId, 20);
        $stmt = $this->conn->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $this->userId);
        $stmt->execute();
        $stmt->close();

        $this->assertNull(UploadAttempt::create($this->conn, $this->userId, 'music'));
        $hold = MeelCoin::hold($this->conn, $this->userId, 5, 'upload', 'music');
        $this->assertFalse($hold['ok']);
        $this->assertStringContainsString('tidak aktif', $hold['error']);
        $this->assertSame(20, MeelCoin::getBalance($this->conn, $this->userId));
        $this->assertSame(0, MeelCoin::getHeld($this->conn, $this->userId));
    }

    public function testGuestCannotReserveCoins(): void
    {
        $username = 'guest_acct_' . bin2hex(random_bytes(4));
        $stmt = $this->conn->prepare(
            "INSERT INTO users (username, password, role, meelcoin, is_active)
             VALUES (?, 'x', 'guest', 20, 1)"
        );
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $guestId = (int)$this->conn->insert_id;
        $stmt->close();

        $hold = MeelCoin::hold($this->conn, $guestId, 5, 'upload', 'music');
        $this->assertFalse($hold['ok']);
        $this->assertStringContainsString('Guest', $hold['error']);
        $this->assertSame(20, MeelCoin::getBalance($this->conn, $guestId));
        $this->assertSame(0, MeelCoin::getHeld($this->conn, $guestId));
    }

    public function testAccountInitializationReleasesActiveHolds(): void
    {
        $this->setBalance($this->userId, 20);
        $hold = MeelCoin::hold($this->conn, $this->userId, 5, 'upload', 'video');
        $this->assertTrue($hold['ok'], $hold['error']);

        MeelCoin::initialize($this->conn, $this->userId, 'user');

        $this->assertSame('released', $this->holdStatus((int)$hold['hold_id']));
        $this->assertSame(25, MeelCoin::getBalance($this->conn, $this->userId));
        $this->assertSame(0, MeelCoin::getHeld($this->conn, $this->userId));
        $this->assertSame(25, MeelCoin::getAvailable($this->conn, $this->userId));
    }
}

/* reference build: MEeL-C4H9NO2 [ffd332a] */
