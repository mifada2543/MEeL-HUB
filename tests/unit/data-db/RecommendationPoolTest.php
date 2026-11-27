<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/tests/unit/support/MysqlFake.php';

/** @covers meel_recommendation_ids */
class RecommendationPoolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/helpers/media.php';
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function conn(array $pool, array $extra = []): FakeMysqli
    {
        $conn = new FakeMysqli();
        $poolStmt = new FakeMysqliStmt();
        $poolStmt->result = fake_result_of_ids($pool);
        $extraStmt = new FakeMysqliStmt();
        $extraStmt->result = fake_result_of_ids($extra);
        $conn->onPrepare = static function (string $sql) use ($poolStmt, $extraStmt) {
            return stripos($sql, 'ORDER BY id DESC') !== false ? $extraStmt : $poolStmt;
        };
        return $conn;
    }

    public function testPagesFromSamePoolDoNotOverlap(): void
    {
        $conn = $this->conn(range(1, 60));
        $first = meel_recommendation_ids($conn, 'music', 100, 15, 0);
        $second = meel_recommendation_ids($conn, 'music', 100, 15, 15);

        $this->assertCount(15, $first);
        $this->assertCount(15, $second);
        $this->assertSame([], array_intersect($first, $second), 'halaman berikutnya tidak boleh mengulang halaman pertama');
        $this->assertNotContains(100, $first);
        $this->assertNotContains(100, $second);
    }

    public function testPoolIsStableAcrossCallsAndRebuildsForNewVideo(): void
    {
        $conn = $this->conn(range(1, 40));
        meel_recommendation_ids($conn, 'music', 5, 10, 0);
        $pool = $_SESSION['rec_pool_music'];

        meel_recommendation_ids($conn, 'music', 5, 10, 10);
        $this->assertSame($pool, $_SESSION['rec_pool_music'], 'pool tidak boleh diacak ulang tiap scroll');

        meel_recommendation_ids($conn, 'music', 9, 10, 0);
        $this->assertSame(9, $_SESSION['rec_pool_owner_music'], 'pindah video harus membuat pool baru');
    }

    public function testShortPoolIsFilledWithOtherDistinctIds(): void
    {
        $conn = $this->conn([2, 3], [4, 5, 6, 7, 8, 9, 10, 11, 12]);
        $ids = meel_recommendation_ids($conn, 'music', 1, 10, 0);

        $this->assertCount(10, $ids, 'pool pendek tetap harus terisi sampai limit');
        $this->assertSame(count($ids), count(array_unique($ids)), 'tidak boleh ada id duplikat');
        $this->assertNotContains(1, $ids);
    }

    public function testNeverUsesSqlRand(): void
    {
        $conn = $this->conn(range(1, 30));
        meel_recommendation_ids($conn, 'music', 1, 10, 0);
        foreach ($conn->createdStmts as $stmt) {
            $this->assertStringNotContainsString('RAND()', $stmt->sql);
            $this->assertMatchesRegularExpression('/\bLIMIT\b/i', $stmt->sql);
        }
    }
}
