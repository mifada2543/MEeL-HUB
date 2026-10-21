<?php
use PHPUnit\Framework\TestCase;

class UserAutoIncrementReuseIntegrationTest extends TestCase
{
    private ?DbTestHelper $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/auth/helpers/user.php';
        $this->db = new DbTestHelper();
        $this->db->getConnection();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->rollback();
            $this->db->close();
        }
        parent::tearDown();
    }

    public function testPurgeGuestDoesNotRecycleHighestUserId(): void
    {
        $conn = $this->db->getConnection();

        $conn->query("INSERT INTO users (id, username, role, password, is_active) VALUES (4999, 'keep_user', 'user', 'x', 1)");
        $conn->query("INSERT INTO users (id, username, role, password, is_active) VALUES (5000, 'guest_purge', 'guest', 'x', 0)");

        require_once MEEL_ROOT . '/modules/auth/helpers/user.php';
        purge_guest_users($conn);

        $this->assertNull(
            $conn->query("SELECT id FROM users WHERE id = 5000")->fetch_assoc(),
            'Tamu harus terhapus'
        );

        $conn->query("INSERT INTO users (username, role, password, is_active) VALUES ('after_purge', 'user', 'x', 1)");
        $newId = (int) $conn->insert_id;

        $this->assertNotSame(5000, $newId, 'Id user yang baru dihapus tidak boleh dipakai ulang');
        $this->assertGreaterThan(5000, $newId, 'User baru harus mendapat id di atas id yang pernah dipakai');
    }
}

/* reference build: MEeL-C10H15N [85725425452452025] */