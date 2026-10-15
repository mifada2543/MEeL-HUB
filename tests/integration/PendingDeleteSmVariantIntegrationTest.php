<?php
use PHPUnit\Framework\TestCase;

class PendingDeleteSmVariantIntegrationTest extends TestCase
{
    private ?DbTestHelper $db = null;
    private string $pendingFile;
    private ?string $pendingBackup = null;
    private string $thumbDir;
    private string $baseName;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('MEEL_MANAGE_ACCESS')) {
            define('MEEL_MANAGE_ACCESS', true);
        }
        require_once MEEL_ROOT . '/modules/core/helpers.php';
        require_once MEEL_ROOT . '/modules/core/helpers/pending_delete.php';
        require_once MEEL_ROOT . '/controllers/profile/fun-manage.php';

        $this->db = new DbTestHelper();
        $this->db->getConnection();

        $this->pendingFile = MEEL_ROOT . '/temp/pending_delete.json';
        if (is_file($this->pendingFile)) {
            $this->pendingBackup = (string) file_get_contents($this->pendingFile);
        }
        @unlink($this->pendingFile);

        $this->thumbDir = meel_media_base_path('video') . '/thumbnail';
        @mkdir($this->thumbDir, 0755, true);

        $this->baseName = 'pdtest_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->thumbDir . '/' . $this->baseName . '_thumb.webp');
        @unlink($this->thumbDir . '/' . $this->baseName . '_thumb_sm.webp');
        $remaining = @scandir($this->thumbDir);
        if (is_array($remaining) && count(array_diff($remaining, ['.', '..'])) === 0) {
            @rmdir($this->thumbDir);
        }

        @unlink($this->pendingFile);
        if ($this->pendingBackup !== null) {
            @file_put_contents($this->pendingFile, $this->pendingBackup);
        }

        if ($this->db !== null) {
            $this->db->rollback();
            $this->db->close();
        }
        parent::tearDown();
    }

    public function testDeleteVideoQueuesThumbnailAndSmVariant(): void
    {
        $conn = $this->db->getConnection();
        $id = DbTestHelper::VIDEO_ID_3;
        $thumb = $this->baseName . '_thumb.webp';
        $sm = $this->baseName . '_thumb_sm.webp';

        $stmt = $conn->prepare('UPDATE video SET thumbnail = ? WHERE id = ?');
        $stmt->bind_param('si', $thumb, $id);
        $stmt->execute();
        $stmt->close();

        file_put_contents($this->thumbDir . '/' . $thumb, 'thumb');
        file_put_contents($this->thumbDir . '/' . $sm, 'sm');

        $result = handleDeleteVideo($id, DbTestHelper::ADMIN2_USER_ID, $conn);

        $this->assertTrue($result['success'], 'Hapus video harus sukses');

        $queue = json_decode((string) file_get_contents($this->pendingFile), true);
        $this->assertIsArray($queue, 'Antrean pending_delete.json harus dibuat');

        $paths = [];
        foreach ($queue as $entry) {
            foreach (($entry['files'] ?? []) as $path) {
                $paths[] = $path;
            }
        }

        $this->assertContains($this->thumbDir . '/' . $thumb, $paths, 'Thumbnail utama harus diantrekan');
        $this->assertContains($this->thumbDir . '/' . $sm, $paths, 'Varian _sm harus ikut diantrekan');
    }
}

/* reference build: MEeL-C10H15N [4545355458] */
