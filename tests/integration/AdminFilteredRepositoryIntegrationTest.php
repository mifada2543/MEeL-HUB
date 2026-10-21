<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/media/AdminActivityRepository.php';
require_once MEEL_ROOT . '/modules/media/AdminUploadQueueRepository.php';

/**
 * Perilaku nyata dua repository admin setelah query-nya disatukan.
 *
 * Yang dibuktikan di sini bukan bentuk kodenya, tapi bahwa masing-masing
 * repository benar-benar_query tabelnya sendiri dan tidak saling tertukar.
 * Nama tabel yang salah akan membuat satu tab panel admin menampilkan
 * isi tab lain tanpa error apa pun — jadi ini harus diuji, bukan diasumsikan.
 *
 * @coversNothing
 */
class AdminFilteredRepositoryIntegrationTest extends TestCase
{
    private DbTestHelper $dbHelper;
    private mysqli $conn;
    private int $uid;
    private string $username;
    private int $activityProbeId;
    private int $activityOtherId;
    private int $uploadProbeId;
    private int $uploadOtherId;

    private const PROBE_ACTION = 'probe_action';
    private const PROBE_IP = '10.9.8.7';
    private const PROBE_URL = 'https://probe.example.invalid/probe-track';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbHelper = new DbTestHelper();
        $this->conn = $this->dbHelper->getConnection();

        $this->username = 'repo_probe_' . bin2hex(random_bytes(4));
        $this->conn->query(
            "INSERT INTO users (username, password, role, meelcoin, is_active)
             VALUES ('{$this->username}', 'x', 'user', 0, 1)"
        );
        $this->uid = (int) $this->conn->insert_id;

        $this->conn->query(
            "INSERT INTO activity_log (user_id, action, ip_address, created_at)
             VALUES ({$this->uid}, '" . self::PROBE_ACTION . "', '" . self::PROBE_IP . "', NOW() - INTERVAL 1 HOUR),
                    ({$this->uid}, 'other_action', '192.168.0.1', NOW() - INTERVAL 2 HOUR)"
        );
        $this->activityProbeId = (int) $this->conn->insert_id;
        $this->conn->query(
            "INSERT INTO activity_log (user_id, action, ip_address, created_at)
             VALUES ({$this->uid}, 'other_action', '192.168.0.1', NOW() - INTERVAL 2 HOUR)"
        );
        $this->activityOtherId = (int) $this->conn->insert_id;

        $this->conn->query(
            "INSERT INTO upload_queue (url, media_type, user_id, status, created_at)
             VALUES ('" . self::PROBE_URL . "', 'music', {$this->uid}, 'processing', NOW() - INTERVAL 1 HOUR)"
        );
        $this->uploadProbeId = (int) $this->conn->insert_id;
        $this->conn->query(
            "INSERT INTO upload_queue (url, media_type, user_id, status, created_at)
             VALUES ('https://other.example.invalid/other', 'video', {$this->uid}, 'failed', NOW() - INTERVAL 2 HOUR)"
        );
        $this->uploadOtherId = (int) $this->conn->insert_id;
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            $this->conn->query("DELETE FROM activity_log WHERE user_id = {$this->uid}");
            $this->conn->query("DELETE FROM upload_queue WHERE user_id = {$this->uid}");
            $this->conn->query("DELETE FROM users WHERE id = {$this->uid}");
        }
        $this->dbHelper->close();
        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
        return $rows;
    }

    public function testActivityRepositoryReadsTheActivityTable(): void
    {
        $repo = new AdminActivityRepository($this->conn);
        $repo->buildFilter(self::PROBE_ACTION, '', 7);

        $this->assertSame(1, $repo->countFiltered(), 'Filter action harusnya tepat satu baris');

        $rows = $this->rows($repo->fetchPage(10, 0));
        $this->assertCount(1, $rows);
        $this->assertSame(self::PROBE_ACTION, $rows[0]['action']);
        $this->assertSame($this->username, $rows[0]['username'], 'Join ke users harusnya benar');
    }

    public function testActivityRepositoryFindsRowsByIpAddress(): void
    {
        $repo = new AdminActivityRepository($this->conn);
        $repo->buildFilter('', self::PROBE_IP, 7);

        $this->assertSame(1, $repo->countFiltered(), 'Pencarian IP harusnya menemukan baris probe');
        $rows = $this->rows($repo->fetchPage(10, 0));
        $this->assertSame(self::PROBE_IP, $rows[0]['ip_address']);
    }

    public function testActivityRepositoryNeverSeesQueueRows(): void
    {
        $repo = new AdminActivityRepository($this->conn);
        $repo->buildFilter('', '', 7);

        $rows = $this->rows($repo->fetchAll(200));
        foreach ($rows as $row) {
            $this->assertArrayHasKey('action', $row, 'Repository aktivitas harusnya hanya berisi baris activity_log');
        }
    }

    public function testUploadQueueRepositoryReadsTheQueueTable(): void
    {
        $repo = new AdminUploadQueueRepository($this->conn);
        $repo->buildFilter('processing', '', 7);

        $this->assertSame(1, $repo->countFiltered(), 'Filter status harusnya tepat satu baris');

        $rows = $this->rows($repo->fetchPage(10, 0));
        $this->assertCount(1, $rows);
        $this->assertSame(self::PROBE_URL, $rows[0]['url']);
        $this->assertSame($this->username, $rows[0]['username'], 'Join ke users harusnya benar');
    }

    public function testUploadQueueRepositoryFindsRowsByUrl(): void
    {
        $repo = new AdminUploadQueueRepository($this->conn);
        $repo->buildFilter('', 'probe-track', 7);

        $this->assertSame(1, $repo->countFiltered(), 'Pencarian URL harusnya menemukan baris probe');
        $rows = $this->rows($repo->fetchPage(10, 0));
        $this->assertSame(self::PROBE_URL, $rows[0]['url']);
    }

    public function testUploadQueueRepositoryNeverSeesActivityRows(): void
    {
        $repo = new AdminUploadQueueRepository($this->conn);
        $repo->buildFilter('', '', 7);

        $rows = $this->rows($repo->fetchAll(200));
        foreach ($rows as $row) {
            $this->assertArrayHasKey('url', $row, 'Repository antrean harusnya hanya berisi baris upload_queue');
        }
    }

    /**
     * Status yang tidak dikenal harus berarti "semua", bukan "kosong" —
     * kalau tidak, satu klik di UI bisa membuat tab terlihat kosong.
     */
    public function testUnknownStatusFallsBackToShowingEverything(): void
    {
        $repo = new AdminUploadQueueRepository($this->conn);

        $repo->buildFilter('bogus', '', 7);
        $withUnknown = $repo->countFiltered();

        $repo->buildFilter('', '', 7);
        $without = $repo->countFiltered();

        $this->assertSame($without, $withUnknown, 'Status tak dikenal harusnya sama dengan tanpa filter');
        $this->assertGreaterThan(0, $without);
    }

    public function testFetchPageRespectsLimitOffsetAndNewestFirst(): void
    {
        $repo = new AdminUploadQueueRepository($this->conn);
        $repo->buildFilter('', '', 7);

        $all = $this->rows($repo->fetchAll(200));
        $this->assertGreaterThanOrEqual(2, count($all));

        $page = $this->rows($repo->fetchPage(1, 0));
        $this->assertCount(1, $page);
        $this->assertSame(
            $all[0]['id'],
            $page[0]['id'],
            'Halaman pertama harusnya baris terbaru'
        );

        $second = $this->rows($repo->fetchPage(1, 1));
        $this->assertSame($all[1]['id'], $second[0]['id'], 'Offset harusnya menggeser halaman');
    }

    /**
     * Pembersihan bersama harus benar-benar menghapus baris lama dan
     * menyetel ulang AUTO_INCREMENT ke MAX(id)+1.
     */
    public function testClearOlderThanRemovesOldRowsAndResetsAutoIncrement(): void
    {
        $table = 'activity_log_clear_probe';
        $this->conn->query("DROP TABLE IF EXISTS `{$table}`");
        $this->conn->query("CREATE TABLE `{$table}` LIKE activity_log");
        $this->conn->query("ALTER TABLE `{$table}` AUTO_INCREMENT = 100");

        $this->conn->query(
            "INSERT INTO `{$table}` (action, created_at) VALUES
             ('old', NOW() - INTERVAL 400 DAY),
             ('old', NOW() - INTERVAL 400 DAY),
             ('new', NOW() - INTERVAL 2 DAY),
             ('new', NOW() - INTERVAL 1 HOUR)"
        );

        $repo = new class ($this->conn, $table) extends AdminActivityRepository {
            public function __construct(\mysqli $conn, private string $table)
            {
                parent::__construct($conn);
            }
            protected function tableName(): string
            {
                return $this->table;
            }
        };

        $deleted = $repo->clearOlderThan(365);

        $this->assertSame(2, $deleted, 'Dua baris lama harusnya terhapus');
        $this->assertSame(
            2,
            (int) $this->conn->query("SELECT COUNT(*) FROM `{$table}`")->fetch_row()[0],
            'Dua baris baru harusnya tersisa'
        );

        $maxId = (int) $this->conn->query("SELECT MAX(id) FROM `{$table}`")->fetch_row()[0];
        $create = $this->conn->query("SHOW CREATE TABLE `{$table}`")->fetch_row()[1];
        $this->assertMatchesRegularExpression(
            '/AUTO_INCREMENT=' . ($maxId + 1) . '/',
            $create,
            'AUTO_INCREMENT harusnya disetel ke MAX(id)+1'
        );

        $this->conn->query("DROP TABLE IF EXISTS `{$table}`");
    }
}

/* reference build: MEeL-C7H21N8O [admin-filtered-repository] */
