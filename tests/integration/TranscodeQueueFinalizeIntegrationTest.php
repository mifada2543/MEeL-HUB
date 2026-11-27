<?php
use PHPUnit\Framework\TestCase;

/** @covers TranscodeService::finalizeStaleQueue */
class TranscodeQueueFinalizeIntegrationTest extends TestCase
{
    private DbTestHelper $dbHelper;
    private mysqli $conn;
    private int $uid;
    private string $markerFile;

    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/transcoder/TranscodeService.php';
        $this->dbHelper = new DbTestHelper();
        $this->conn = $this->dbHelper->getConnection();
        $username = 'tc_finalize_' . bin2hex(random_bytes(4));
        $this->conn->query(
            "INSERT INTO users (username, password, role, is_active) VALUES ('{$username}', 'x', 'user', 1)"
        );
        $this->uid = (int) $this->conn->insert_id;
        $this->markerFile = sys_get_temp_dir() . '/meel_tc_marker_' . bin2hex(random_bytes(4)) . '.processing';
        file_put_contents($this->markerFile, 'x');
    }

    protected function tearDown(): void
    {
        if (is_file($this->markerFile)) {
            @unlink($this->markerFile);
        }
        $this->conn->query("DELETE FROM transcode_queue WHERE user_id = {$this->uid}");
        $this->conn->query("DELETE FROM users WHERE id = {$this->uid}");
        $this->dbHelper->close();
        parent::tearDown();
    }

    private function insertQueue(string $status): int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO transcode_queue (user_id, status, created_at) VALUES (?, ?, NOW())"
        );
        $stmt->bind_param('is', $this->uid, $status);
        $stmt->execute();
        $id = (int) $this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    private function status(int $id): string
    {
        $stmt = $this->conn->prepare("SELECT status FROM transcode_queue WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $status = (string) $stmt->get_result()->fetch_assoc()['status'];
        $stmt->close();
        return $status;
    }

    private function serviceWithActiveQueue(int $id): TranscodeService
    {
        $rc = new ReflectionClass(TranscodeService::class);
        $service = $rc->newInstanceWithoutConstructor();
        $connProp = $rc->getParentClass()->getProperty('conn');
        $connProp->setAccessible(true);
        $connProp->setValue($service, $this->conn);
        foreach (['activeQueueId' => $id, 'activeMarkerFile' => $this->markerFile] as $name => $value) {
            $prop = $rc->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }
        return $service;
    }

    public function testProcessingQueueIsMarkedFailedAndMarkerRemoved(): void
    {
        $id = $this->insertQueue('processing');
        $service = $this->serviceWithActiveQueue($id);

        $service->finalizeStaleQueue();

        $this->assertSame('failed', $this->status($id));
        $this->assertFileDoesNotExist($this->markerFile);
    }

    public function testCompletedQueueIsNotOverwritten(): void
    {
        $id = $this->insertQueue('completed');
        $service = $this->serviceWithActiveQueue($id);

        $service->finalizeStaleQueue();

        $this->assertSame('completed', $this->status($id));
    }

    public function testFinalizeIsIdempotent(): void
    {
        $id = $this->insertQueue('processing');
        $service = $this->serviceWithActiveQueue($id);

        $service->finalizeStaleQueue();
        $service->finalizeStaleQueue();

        $this->assertSame('failed', $this->status($id));
    }
}
