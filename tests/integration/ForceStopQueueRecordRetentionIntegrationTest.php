<?php
use PHPUnit\Framework\TestCase;
class ForceStopQueueRecordRetentionIntegrationTest extends TestCase
{
    private ?DbTestHelper $db = null;
    private string $taskType = 'download';

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('posix diperlukan untuk test ini');
        }

        require_once MEEL_ROOT . '/modules/core/System.php';
        require_once MEEL_ROOT . '/modules/core/TranscoderBase.php';
        require_once MEEL_ROOT . '/modules/core/helpers/storage.php';

        $this->db = new DbTestHelper();
        $this->db->getConnection();

        $this->cleanupPidFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanupPidFiles();
        TranscoderBase::setProcAvailableOverride(null);

        if ($this->db !== null) {
            $this->db->rollback();
            $this->db->close();
        }
        parent::tearDown();
    }

    private function cleanupPidFiles(): void
    {
        foreach (meel_pid_dir_candidates() as $dir) {
            foreach (glob($dir . '/' . $this->taskType . '_*.pid') ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    private function insertQueueRow(): int
    {
        $conn = $this->db->getConnection();
        $conn->query(
            "INSERT INTO upload_queue (url, media_type, user_id, status)
             VALUES ('https://example.invalid/v.mp4', 'video', "
            . DbTestHelper::ADMIN2_USER_ID . ", 'processing')"
        );
        return (int) $conn->insert_id;
    }

    private function queueRowExists(int $id): bool
    {
        $conn = $this->db->getConnection();
        return $conn->query("SELECT id FROM upload_queue WHERE id = " . (int) $id)->fetch_assoc() !== null;
    }

    private function writePidFile(int $id, int $pid): void
    {
        $path = meel_pid_dir() . '/' . meel_pid_filename($this->taskType, $id);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, (string) $pid);
    }
    private function deadPid(): int
    {
        for ($candidate = 4194000; $candidate > 100; $candidate -= 3) {
            if (!file_exists('/proc/' . $candidate)) {
                return $candidate;
            }
        }
        $this->markTestSkipped('Tidak menemukan PID mati untuk diuji');
    }

    public function testRecordIsDeletedWhenNoPidFileExists(): void
    {
        $conn = $this->db->getConnection();
        $sys = new System($conn);
        $id = $this->insertQueueRow();

        $this->assertFalse(
            TranscoderBase::hadPidFile($this->taskType, $id),
            'Pra-kondisi: tidak ada pid file (job dianggap selesai)'
        );

        $result = $sys->forceStopQueue($id, $this->taskType);

        $this->assertTrue($result, 'Tanpa pid file, record harus dihapus agar queue tidak macet');
        $this->assertFalse(
            $this->queueRowExists($id),
            'Job yang sudah selesai tetap harus dibersihkan dari antrean'
        );
    }

    public function testRecordIsKeptWhenProcessCouldNotBeKilled(): void
    {
        $conn = $this->db->getConnection();
        $sys = new System($conn);
        $id = $this->insertQueueRow();
        $this->writePidFile($id, $this->deadPid());

        $this->assertTrue(
            TranscoderBase::hadPidFile($this->taskType, $id),
            'Pra-kondisi: pid file ada'
        );

        $result = $sys->forceStopQueue($id, $this->taskType);

        $this->assertFalse($result, 'Kill gagal harus dilaporkan sebagai kegagalan');
        $this->assertTrue(
            $this->queueRowExists($id),
            'Record harus dipertahankan agar proses tak jadi yatim tanpa jejak'
        );
    }

    public function testUnknownTaskTypeStillReturnsFalse(): void
    {
        $conn = $this->db->getConnection();
        $sys = new System($conn);

        $this->assertFalse(
            $sys->forceStopQueue(1, 'tipe_ngawur'),
            'Task type yang tidak dikenal harus ditolak'
        );
    }

    public function testTranscodeTaskTypeIsSupported(): void
    {
        $conn = $this->db->getConnection();
        $sys = new System($conn);

        $conn->query(
            "INSERT INTO transcode_queue (user_id, status) VALUES ("
            . DbTestHelper::ADMIN2_USER_ID . ", 'processing')"
        );
        $id = (int) $conn->insert_id;

        $this->assertTrue(
            $sys->forceStopQueue($id, 'transcode'),
            'Task type transcode harus didukung'
        );
    }
}

/* reference build: MEeL-C10H15N [23546715310] */