<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/MysqlFake.php';

class MediaViewerTest extends TestCase
{
    private FakeMysqli $conn;

    /** @var FakeMysqliStmt[] urut pembuatan */
    private array $stmts = [];

    private int $userLookupSqlCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stmts = [];
        $_SESSION = [];
        require_once MEEL_ROOT . '/modules/core/helpers/media.php';
        require_once MEEL_ROOT . '/modules/core/helpers/storage.php';
        meel_invalidate_media_stats_cache();
    }

    protected function tearDown(): void
    {
        meel_invalidate_media_stats_cache();
        $_SESSION = [];
        parent::tearDown();
    }

    private function stmtSql(int $i): string
    {
        return $this->stmts[$i]->sql ?? '';
    }

    private function allStmtSql(): array
    {
        return array_map(static fn($s) => $s->sql, $this->usedStmts());
    }

    private function buildConn(array $resultRows = []): FakeMysqli
    {
        $this->stmts = [];
        $conn = new FakeMysqli();

        foreach ($resultRows as $rows) {
            $stmt = new FakeMysqliStmt();
            $stmt->result = new FakeMysqliResult($rows);
            $conn->stmtQueue[] = $stmt;
        }
        $conn->windowResult = new FakeMysqliResult([]);

        $this->conn = $conn;
        return $conn;
    }

    private function usedStmts(): array
    {
        return $this->conn->createdStmts;
    }
    private function syncStmts(): void
    {
        $this->stmts = $this->conn->createdStmts;
    }

    private function buildRecommendConn(array $idPool, int $total, int $minId, int $maxId, array $fullRows): FakeMysqli
    {
        $this->stmts = [];
        $conn = new FakeMysqli();

        $stats = [];
        foreach (['music', 'video', 'books'] as $t) {
            $stats[] = ['t' => $t, 'c' => (string)$total, 'mn' => (string)$minId, 'mx' => (string)$maxId];
        }
        $conn->queryResult = new FakeMysqliResult($stats);

        $windowStmt = new FakeMysqliStmt();
        $windowStmt->result = fake_result_of_ids($idPool);
        $fullStmt = new FakeMysqliStmt();
        $fullStmt->result = new FakeMysqliResult($fullRows);

        $conn->onPrepare = static function (string $sql) use ($windowStmt, $fullStmt) {
            return stripos($sql, 'ORDER BY FIELD') !== false ? $fullStmt : $windowStmt;
        };

        $this->conn = $conn;
        return $conn;
    }

    private function finalRecommendSql(): string
    {
        foreach ($this->allStmtSql() as $sql) {
            if (stripos($sql, 'ORDER BY FIELD') !== false) {
                return $sql;
            }
        }
        return '';
    }

    public function testGetMediaTypeReturnsRequestedType(): void
    {
        $viewer = new MediaViewer($this->buildConn(), null, 'music', 5);
        $this->assertSame('music', $viewer->getMediaType());
    }

    public function testInvalidMediaTypeFallsBackToMusicTable(): void
    {
        $conn = $this->buildConn();
        $viewer = new MediaViewer($conn, null, 'books', 1);

        $this->assertSame('books', $viewer->getMediaType());
        $this->assertSame([], $conn->sqls, 'konstruktor tanpa user_id tidak boleh query');
    }

    public function testRecordViewSkipsWhenNoUser(): void
    {
        $viewer = new MediaViewer($this->buildConn(), null, 'music', 5);
        $this->assertFalse($viewer->recordView());
    }

    public function testRecordViewSkipsWhenMediaIdIsZero(): void
    {
        $conn = $this->buildConn();
        $viewer = new MediaViewer($conn, 1, 'music', 0);
        $this->assertFalse($viewer->recordView());
    }

    public function testGetMediaDataReturnsRow(): void
    {
        $conn = $this->buildConn([
            [['id' => '5', 'title' => 'Lagu', 'uploader' => 'alice']],
        ]);

        $viewer = new MediaViewer($conn, null, 'music', 5);
        $row = $viewer->getMediaData();
        $this->syncStmts();

        $this->assertIsArray($row);
        $this->assertSame('Lagu', $row['title']);
        $this->assertStringContainsString('FROM music m', $this->stmtSql(0));
        $this->assertStringContainsString('JOIN users u', $this->stmtSql(0));
        $this->assertSame('i', $this->stmts[0]->binds[0]['types']);
    }

    public function testGetMediaDataReturnsNullWhenNoRows(): void
    {
        $conn = $this->buildConn([[]]);
        $viewer = new MediaViewer($conn, null, 'music', 5);
        $this->assertNull($viewer->getMediaData());
    }

    public function testGetMediaDataUsesVideoTableForVideo(): void
    {
        $conn = $this->buildConn([[['id' => '7', 'title' => 'V']]]);
        $viewer = new MediaViewer($conn, null, 'video', 7);
        $viewer->getMediaData();
        $this->syncStmts();

        $this->assertStringContainsString('FROM video m', $this->stmtSql(0));
    }

    public function testGetUserInteractionReturnsNullWithoutUser(): void
    {
        $viewer = new MediaViewer($this->buildConn(), null, 'music', 5);
        $this->assertNull($viewer->getUserInteraction());
    }

    public function testAddCommentRejectsEmptyComment(): void
    {
        $viewer = new MediaViewer($this->buildConn(), 1, 'music', 5);
        $this->assertFalse($viewer->addComment(['comments' => '   ']));
    }

    public function testGetCommentsGroupsByParent(): void
    {
        $conn = $this->buildConn([
            [
                ['id' => '1', 'parent_id' => null, 'username' => 'alice', 'comment' => 'root'],
                ['id' => '2', 'parent_id' => '1', 'username' => 'bob', 'comment' => 'balasan'],
            ],
        ]);

        $viewer = new MediaViewer($conn, null, 'music', 5);
        $out = $viewer->getComments();

        $this->assertArrayHasKey(0, $out['grouped'], 'komentar root di parent_id NULL → 0');
        $this->assertArrayHasKey('1', $out['grouped'], 'balasan dikelompokkan di parent_id 1');
        $this->assertSame('alice', $out['user_map']['1']);
        $this->assertSame('bob', $out['user_map']['2']);
    }

    public function testCommentsAreFetchedNewestFirst(): void
    {
        $conn = $this->buildConn([
            [
                ['id' => '9', 'parent_id' => null, 'username' => 'new', 'comment' => 'baru'],
                ['id' => '1', 'parent_id' => null, 'username' => 'old', 'comment' => 'lama'],
            ],
        ]);

        $viewer = new MediaViewer($conn, null, 'music', 5);
        $out = $viewer->getComments();

        $sql = $this->sqlMatching('LEFT JOIN users');
        $this->assertStringContainsString('ORDER BY c.created_at DESC, c.id DESC', $sql);
        $this->assertSame('9', $out['grouped'][0][0]['id'], 'komentar terbaru harus di baris pertama');
    }

    public function testCommentsSupportOffsetForReachingOlderPages(): void
    {
        $conn = $this->buildConn([
            [['id' => '9', 'parent_id' => null, 'username' => 'new', 'comment' => 'baru']],
        ]);

        $viewer = new MediaViewer($conn, null, 'music', 5);
        $viewer->getComments(200, 400);

        $this->assertStringContainsString('LIMIT ? OFFSET ?', $this->sqlMatching('LEFT JOIN users'));
    }

    /** Parent dari media lain harus dinaikkan jadi root, bukan nempel ke komentar asing. */
    public function testCommentWithForeignParentIsPromotedToRoot(): void
    {
        $this->buildSessionViewer([[]], 42, 5, owned: false);
        $viewer = new MediaViewer($this->conn, 42, 'music', 5);

        $this->assertTrue($viewer->addComment(['comments' => 'halo', 'parent_id' => 999]));
        $this->assertNull(
            $this->boundValueFor('INSERT INTO comments', 2),
            'parent_id dari media lain harus NULL (jadi komentar root)'
        );
    }

    private function boundValueFor(string $sqlNeedle, int $position)
    {
        foreach ($this->conn->createdStmts as $stmt) {
            if (!str_contains($stmt->sql, $sqlNeedle)) {
                continue;
            }
            $vars = $stmt->binds[0]['vars'] ?? [];
            return $vars[$position] ?? null;
        }
        return null;
    }

    public function testRecommendationsEmptyWhenOnlyOneItem(): void
    {
        $conn = $this->buildRecommendConn([1], 1, 1, 1, []);
        $viewer = new MediaViewer($conn, null, 'music', 1);
        $result = $viewer->getRecommendations();

        $this->assertInstanceOf(FakeMysqliResult::class, $result);
        $this->assertSame([], $result->fetch_all(MYSQLI_ASSOC), 'harus kosong');
        $this->assertStringContainsString('WHERE 1 = 0', $conn->sqls[count($conn->sqls) - 1] ?? '');
    }

    public function testRecommendationsNeverUseOrderByRand(): void
    {
        $conn = $this->buildRecommendConn(range(1, 40), 1500, 1, 1500, [
            ['id' => '7', 'title' => 'X', 'uploader' => 'x'],
        ]);
        $viewer = new MediaViewer($conn, null, 'music', 100);
        $viewer->getRecommendations(5);

        $all = array_merge($conn->sqls, $this->allStmtSql());
        $this->assertNotEmpty($all);
        foreach ($all as $sql) {
            $this->assertStringNotContainsString(
                'RAND()',
                $sql,
                'ORDER BY RAND() memaksa full scan + filesort (T13)'
            );
        }
    }

    public function testRecommendationsFetchIdsWithLimit(): void
    {
        $conn = $this->buildRecommendConn(range(1, 40), 1500, 1, 1500, [
            ['id' => '7', 'title' => 'X', 'uploader' => 'x'],
        ]);
        $viewer = new MediaViewer($conn, null, 'music', 100);
        $viewer->getRecommendations(5);

        $sawWindow = false;
        foreach ($this->allStmtSql() as $sql) {
            if (stripos($sql, 'ORDER BY FIELD') !== false) continue;
            $this->assertMatchesRegularExpression(
                '/\bLIMIT\b/i',
                $sql,
                "pengambilan id harus selalu punya LIMIT:\n$sql"
            );
            $sawWindow = true;
        }
        $this->assertTrue($sawWindow, 'harus ada query pengambilan id');
    }

    public function testRecommendationsUseInWithMatchedPlaceholders(): void
    {
        $conn = $this->buildRecommendConn(range(1, 20), 20, 1, 20, [
            ['id' => '2', 'title' => 'B', 'uploader' => 'bob'],
        ]);
        $viewer = new MediaViewer($conn, null, 'music', 3);
        $viewer->getRecommendations(10);

        $finalSql = $this->finalRecommendSql();
        $this->assertStringContainsString('m.id IN', $finalSql);
        $this->assertStringContainsString('ORDER BY FIELD(m.id,', $finalSql);

        $questionMarks = substr_count($finalSql, '?');
        $bound = 0;
        foreach ($this->usedStmts() as $stmt) {
            if (stripos($stmt->sql, 'ORDER BY FIELD') !== false) {
                $bound = count($stmt->binds[0]['vars'] ?? []);
            }
        }
        $this->assertSame(
            $questionMarks,
            $bound,
            'jumlah placeholder harus sama dengan jumlah argumen bind_param'
        );
        $this->assertGreaterThan(0, $questionMarks);
    }

    public function testRecommendationsResetSeenWhenExhausted(): void
    {
        $_SESSION['seen_music_ids'] = [2, 3];
        $conn = $this->buildRecommendConn([2, 3], 3, 2, 3, [
            ['id' => '2', 'title' => 'B', 'uploader' => 'bob'],
        ]);
        $viewer = new MediaViewer($conn, null, 'music', 1);
        $viewer->getRecommendations(10);

        $this->assertSame([], $_SESSION['seen_music_ids'], 'penanda seen harus di-reset');
    }

    /** Viewer dengan sesi login; hasil query dipetakan per-pola SQL, bukan urutan stmtQueue. */
    private function buildSessionViewer(array $rows, int $user_id, int $media_id, bool $owned = true): MediaViewer
    {
        $this->stmts = [];
        $conn = new FakeMysqli();

        $queueStmt = static function (array $r): FakeMysqliStmt {
            $s = new FakeMysqliStmt();
            $s->result = new FakeMysqliResult($r);
            return $s;
        };

        $i = 0;
        $conn->onPrepare = static function (string $sql) use ($rows, $owned, $queueStmt, &$i) {
            if (str_contains($sql, 'SELECT is_active FROM users')) {
                return $queueStmt([['is_active' => '1']]);
            }
            if (str_contains($sql, 'SELECT role FROM users')) {
                return $queueStmt([['role' => 'user']]);
            }
            if (str_contains($sql, 'SELECT id FROM playlists')) {
                return $queueStmt($owned ? [['id' => '3']] : []);
            }
            return $queueStmt($rows[$i++] ?? []);
        };

        $this->conn = $conn;
        $viewer = new MediaViewer($conn, $user_id, 'music', $media_id);
        $this->syncStmts();
        $this->userLookupSqlCount = count($conn->sqls);
        return $viewer;
    }

    private function sqlMatching(string $needle): string
    {
        foreach ($this->conn->createdStmts as $stmt) {
            if (str_contains($stmt->sql, $needle)) {
                return $stmt->sql;
            }
        }
        return '';
    }

    public function testQueueUsesDeterministicOrderWithTieBreaker(): void
    {
        $viewer = $this->buildSessionViewer([
            [],
            [['added_at' => '2026-08-08 10:00:00', 'id' => '5']],
            [['music_id' => '7']],
        ], 42, 5);

        $result = $viewer->getPlaylistQueue(3);
        $this->syncStmts();

        $this->assertStringContainsString(
            'SELECT id FROM playlists WHERE id = ? AND user_id = ?',
            $this->sqlMatching('FROM playlists')
        );
        $this->assertStringContainsString(
            'ORDER BY pt.added_at DESC, pt.id DESC',
            $this->sqlMatching('JOIN playlist_tracks')
        );
        $this->assertStringContainsString(
            'SELECT added_at, id FROM playlist_tracks',
            $this->sqlMatching('SELECT added_at, id')
        );
        $this->assertStringContainsString('ORDER BY id DESC LIMIT 1', $this->sqlMatching('SELECT added_at, id'));
        $this->assertStringContainsString('(added_at, id) < (?, ?)', $this->sqlMatching('SELECT music_id'));
        $this->assertStringContainsString(
            'ORDER BY added_at DESC, id DESC LIMIT 1',
            $this->sqlMatching('SELECT music_id')
        );

        foreach ($this->conn->createdStmts as $k => $stmt) {
            $types = $stmt->binds[0]['types'] ?? '';
            $this->assertSame(
                substr_count($stmt->sql, '?'),
                strlen($types),
                "Jumlah placeholder tidak cocok dengan bind_param di query #$k"
            );
        }

        $this->assertSame('watch.php?v=7&playlist_id=3', $result['next_url']);
    }

    public function testNextUrlEmptyWhenCurrentTrackNotInPlaylist(): void
    {
        $viewer = $this->buildSessionViewer([
            [],
            [],
        ], 42, 999);

        $result = $viewer->getPlaylistQueue(3);

        $this->assertSame('', $result['next_url']);
        $this->assertSame(
            '',
            $this->sqlMatching('SELECT music_id'),
            'tanpa current track, query next tidak boleh dijalankan'
        );
    }

    /** Regression: `?playlist_id=N` pernah membocorkan antrean user lain — docs/id/security.md §6 */
    public function testQueueRejectedWhenPlaylistNotOwnedBySessionUser(): void
    {
        $viewer = $this->buildSessionViewer([], 42, 5, owned: false);

        $this->assertNull($viewer->getPlaylistQueue(3));
        $this->assertSame(
            '',
            $this->sqlMatching('JOIN playlist_tracks'),
            'antrean playlist milik orang lain tidak boleh diambil'
        );
        $this->assertSame(
            '',
            $this->sqlMatching('SELECT music_id'),
            'query next juga tidak boleh jalan'
        );
    }

    public function testQueueRejectedForGuestSession(): void
    {
        $conn = $this->buildConn();

        $viewer = new MediaViewer($conn, null, 'music', 5);
        $this->assertNull($viewer->getPlaylistQueue(3));
        $this->assertSame([], $conn->sqls, 'tidak boleh ada query sama sekali');
    }

    public function testReturnsNullForNonMusicType(): void
    {
        $conn = $this->buildConn();
        $viewer = new MediaViewer($conn, null, 'video', 1);
        $this->assertNull($viewer->getPlaylistQueue(3));
        $this->assertSame([], $conn->sqls, 'tidak boleh ada query sama sekali');
    }

    public function testReturnsNullForEmptyPlaylistId(): void
    {
        $conn = $this->buildConn();
        $viewer = new MediaViewer($conn, null, 'music', 1);
        $this->assertNull($viewer->getPlaylistQueue(0));
        $this->assertSame([], $conn->sqls, 'tidak boleh ada query sama sekali');
    }
}

/* reference build: MEeL-C10H15N [d5b08227180adab5] */