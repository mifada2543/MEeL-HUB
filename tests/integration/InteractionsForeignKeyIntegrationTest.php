<?php
use PHPUnit\Framework\TestCase;

class InteractionsForeignKeyIntegrationTest extends TestCase
{
    private ?DbTestHelper $db = null;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function fkDeleteRule(mysqli $conn, string $constraint): ?string
    {
        $stmt = $conn->prepare(
            "SELECT rc.DELETE_RULE
               FROM information_schema.REFERENTIAL_CONSTRAINTS rc
              WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
                AND rc.TABLE_NAME = 'interactions'
                AND rc.CONSTRAINT_NAME = ?"
        );
        $stmt->bind_param('s', $constraint);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['DELETE_RULE'] ?? null;
    }

    public function testInteractionsHasCascadeForeignKeys(): void
    {
        $conn = $this->db->getConnection();

        foreach (['fk_interactions_user', 'fk_interactions_video', 'fk_interactions_music'] as $constraint) {
            $this->assertSame(
                'CASCADE',
                $this->fkDeleteRule($conn, $constraint),
                $constraint . ' harus ada dengan ON DELETE CASCADE'
            );
        }
    }

    public function testDeletingVideoCascadesInteractions(): void
    {
        $conn = $this->db->getConnection();
        $videoId = DbTestHelper::VIDEO_ID_2;
        $userId = DbTestHelper::ADMIN2_USER_ID;

        $stmt = $conn->prepare(
            "INSERT INTO interactions (user_id, video_id, type) VALUES (?, ?, 'like')"
        );
        $stmt->bind_param('ii', $userId, $videoId);
        $stmt->execute();
        $stmt->close();

        $conn->query("DELETE FROM video WHERE id = " . (int) $videoId);

        $this->assertNull(
            $conn->query("SELECT id FROM interactions WHERE video_id = " . (int) $videoId)->fetch_assoc(),
            'Interaksi harus ikut terhapus saat video dihapus (cascade)'
        );
    }

    public function testDeletingUserCascadesInteractions(): void
    {
        $conn = $this->db->getConnection();

        $conn->query("INSERT INTO users (id, username, role, password, is_active) VALUES (9001, 'cascade_user', 'user', 'x', 1)");
        $conn->query("INSERT INTO interactions (user_id, video_id, type) VALUES (9001, " . DbTestHelper::VIDEO_ID_3 . ", 'dislike')");

        $conn->query("DELETE FROM users WHERE id = 9001");

        $this->assertNull(
            $conn->query("SELECT id FROM interactions WHERE user_id = 9001")->fetch_assoc(),
            'Interaksi harus ikut terhapus saat user dihapus (cascade)'
        );
    }
}

/* reference build: MEeL-C10H15N [8753979234] */
