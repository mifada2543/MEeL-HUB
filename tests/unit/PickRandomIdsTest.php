<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/MysqlFake.php';

/* reference build: MEeL-C1H1N1O2 [2f6a0d5b8c41e937] */

class PickRandomIdsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/helpers/media.php';
        require_once MEEL_ROOT . '/modules/core/helpers/storage.php';
        meel_invalidate_media_stats_cache();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        meel_invalidate_media_stats_cache();
        $_SESSION = [];
        parent::tearDown();
    }

    private function buildConn(array $idPool, int $total, int $minId, int $maxId): FakeMysqli
    {
        $conn = new FakeMysqli();

        $statsResult = new FakeMysqliResult([
            ['t' => 'music', 'c' => (string)$total, 'mn' => (string)$minId, 'mx' => (string)$maxId],
            ['t' => 'video', 'c' => (string)$total, 'mn' => (string)$minId, 'mx' => (string)$maxId],
            ['t' => 'books', 'c' => (string)$total, 'mn' => (string)$minId, 'mx' => (string)$maxId],
        ]);
        $conn->queryResult = $statsResult;

        $conn->windowResult = fake_result_of_ids($idPool);

        return $conn;
    }

    public function testNeverUsesOrderByRand(): void
    {
        $conn = $this->buildConn(range(1, 40), 1500, 1, 1500);
        meel_pick_random_ids($conn, 'music', 10, 7);

        $all = $conn->sqls;
        foreach ($conn->createdStmts as $stmt) {
            $all[] = $stmt->sql;
        }
        $this->assertNotEmpty($all, 'harus ada SQL yang tercatat');

        foreach ($all as $sql) {
            $this->assertStringNotContainsString(
                'RAND()',
                $sql,
                'ORDER BY RAND() memaksa full scan + filesort (T13)'
            );
        }
    }

    public function testNeverScansWholeTableWithoutLimit(): void
    {
        $conn = $this->buildConn(range(1, 40), 1500, 1, 1500);
        meel_pick_random_ids($conn, 'music', 10, 7);

        $checked = 0;
        foreach ($conn->createdStmts as $stmt) {
            $this->assertMatchesRegularExpression(
                '/\bLIMIT\b/i',
                $stmt->sql,
                "pengambilan id harus selalu punya LIMIT:\n{$stmt->sql}"
            );
            $checked++;
        }
        $this->assertGreaterThan(0, $checked, 'harus ada query pengambilan id');
    }

    public function testRespectsLimitAndReturnsUniqueIds(): void
    {
        $conn = $this->buildConn(range(1, 100), 100, 1, 100);
        $picked = meel_pick_random_ids($conn, 'music', 5, 0);

        $this->assertLessThanOrEqual(5, count($picked));
        $this->assertSame(count($picked), count(array_unique($picked)), 'id harus unik');
        foreach ($picked as $id) {
            $this->assertIsInt($id);
        }
    }

    public function testExcludesPrimaryAndSeenIds(): void
    {
        $conn = $this->buildConn(range(1, 20), 20, 1, 20);
        $picked = meel_pick_random_ids($conn, 'music', 10, 3, [5, 7, 11], 'seen_music_ids');

        $this->assertNotContains(3, $picked, 'media yang sedang dibuka tak boleh muncul');
        $this->assertNotContains(5, $picked, 'id yang sudah pernah dilihat tak boleh muncul');
        $this->assertNotContains(7, $picked);
        $this->assertNotContains(11, $picked);
    }

    public function testResetsSeenSessionKeyWhenAllCandidatesExhausted(): void
    {
        $_SESSION['seen_music_ids'] = [2, 3];
        $conn = $this->buildConn([2, 3], 3, 2, 3);
        $picked = meel_pick_random_ids($conn, 'music', 10, 1, [2, 3], 'seen_music_ids');

        $this->assertSame(
            [],
            $_SESSION['seen_music_ids'],
            'penanda seen harus di-reset agar konten tetap bisa tampil'
        );
        $this->assertNotEmpty($picked, 'setelah reset harus ada kandidat');
        $this->assertNotContains(1, $picked, 'media yang sedang dibuka tetap dibuang');
    }

    public function testEmptyResultWhenTableHasAtMostOneRow(): void
    {
        $conn = $this->buildConn([1], 1, 1, 1);
        $this->assertSame([], meel_pick_random_ids($conn, 'music', 10, 0));

        $conn0 = $this->buildConn([], 0, 0, 0);
        $this->assertSame([], meel_pick_random_ids($conn0, 'music', 10, 0));
    }

    public function testZeroOrNegativeLimitReturnsNothing(): void
    {
        $conn = $this->buildConn(range(1, 50), 50, 1, 50);
        $this->assertSame([], meel_pick_random_ids($conn, 'music', 0, 0));
        $this->assertSame([], meel_pick_random_ids($conn, 'music', -5, 0));
    }

    public function testRejectsTableOutsideWhitelist(): void
    {
        $conn = $this->buildConn(range(1, 50), 50, 1, 50);

        foreach (['users', 'ip_ban', 'video; DROP TABLE users', 'video`'] as $bad) {
            $this->assertSame(
                [],
                meel_pick_random_ids($conn, $bad, 10, 0),
                "tabel di luar whitelist harus ditolak: $bad"
            );
        }

        $this->assertSame(['video', 'music', 'books'], meel_media_table_whitelist());
    }

    public function testMediaStatsIsCachedBetweenCalls(): void
    {
        $conn = $this->buildConn(range(1, 10), 10, 1, 10);

        meel_media_stats($conn, 'music');
        $first = $conn->countSqlContaining('COUNT(*)');
        $this->assertSame(1, $first, 'harus satu query agregat');

        meel_media_stats($conn, 'music');
        $second = $conn->countSqlContaining('COUNT(*)');
        $this->assertSame(
            1,
            $second,
            'panggilan kedua dilayani dari cache, bukan query lagi'
        );
    }

    public function testStatsQueryWarmsAllTablesInOneQuery(): void
    {
        $conn = $this->buildConn(range(1, 10), 10, 1, 10);

        meel_media_stats($conn, 'music');
        meel_media_stats($conn, 'video');
        meel_media_stats($conn, 'books');

        $this->assertSame(
            1,
            $conn->countSqlContaining('COUNT(*)'),
            'satu query UNION harus menghangatkan ketiga tabel'
        );
    }

    public function testInvalidateCacheForcesRecount(): void
    {
        $conn = $this->buildConn(range(1, 10), 10, 1, 10);

        meel_media_stats($conn, 'music');
        meel_invalidate_media_stats_cache('music');
        meel_media_stats($conn, 'music');

        $this->assertSame(
            2,
            $conn->countSqlContaining('COUNT(*)'),
            'setelah invalidasi, agregasi harus diulang'
        );
    }

    public function testMediaStatsShapeAndTableGuard(): void
    {
        $conn = $this->buildConn(range(1, 10), 10, 3, 9);
        $stats = meel_media_stats($conn, 'music');

        $this->assertSame(10, $stats['total']);
        $this->assertSame(3, $stats['min_id']);
        $this->assertSame(9, $stats['max_id']);

        $this->assertSame(
            ['total' => 0, 'min_id' => 0, 'max_id' => 0],
            meel_media_stats($conn, 'users'),
            'tabel di luar whitelist tidak boleh dijolok'
        );
        $this->assertSame(
            1,
            $conn->countSqlContaining('COUNT(*)'),
            'tabel terlarang harus ditolak sebelum query apa pun'
        );
    }
}
