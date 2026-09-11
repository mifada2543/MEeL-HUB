<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers MediaViewer
 */
class MediaViewerTest extends TestCase
{
    
    private array $sqls = [];

    
    private array $bindCalls = [];

    

    private function buildConn(?array $currentRow, ?array $nextRow): array
    {
        $this->bindCalls = [];
        // Param adalah PHPUnit MockObject (createMock), bukan mysqli_stmt asli —
        // tipe native mysqli_stmt membuat analyzer menganggap method() tak ada.
        $recordBind = function (\PHPUnit\Framework\MockObject\MockObject $stmt): void {
            $stmt->method('bind_param')->willReturnCallback(
                function ($types, ...$vars) {
                    $this->bindCalls[] = [
                        'types' => (string)$types,
                        'count' => count($vars),
                    ];
                    return true;
                }
            );
        };

        $resultQ = $this->createMock(mysqli_result::class);
        $stmtQ = $this->createMock(mysqli_stmt::class);
        $recordBind($stmtQ);
        $stmtQ->method('execute')->willReturn(true);
        $stmtQ->method('get_result')->willReturn($resultQ);

        $resultCur = $this->createMock(mysqli_result::class);
        $resultCur->method('fetch_assoc')->willReturn($currentRow);
        $stmtCur = $this->createMock(mysqli_stmt::class);
        $recordBind($stmtCur);
        $stmtCur->method('execute')->willReturn(true);
        $stmtCur->method('get_result')->willReturn($resultCur);

        $resultNext = $this->createMock(mysqli_result::class);
        $resultNext->method('fetch_assoc')->willReturn($nextRow);
        $stmtNext = $this->createMock(mysqli_stmt::class);
        $recordBind($stmtNext);
        $stmtNext->method('execute')->willReturn(true);
        $stmtNext->method('get_result')->willReturn($resultNext);

        $conn = $this->createMock(mysqli::class);
        $this->sqls = [];
        $conn->method('prepare')->willReturnCallback(
            function ($sql) use ($stmtQ, $stmtCur, $stmtNext) {
                $this->sqls[] = $sql;
                $count = count($this->sqls);
                return $count === 1 ? $stmtQ : ($count === 2 ? $stmtCur : $stmtNext);
            }
        );

        return [$conn, $stmtQ, $stmtCur, $stmtNext];
    }

    private function buildRecommendConn(
        int $total,
        array $idRows,
        bool $useRand,
        array $fullRows
    ): \mysqli {
        $conn = $this->createMock(mysqli::class);
        $this->sqls = [];
        $queryCallCount = 0;

        $countResult = $this->createMock(mysqli_result::class);
        $countResult->method('fetch_assoc')->willReturn(['total' => (string)$total]);

        $idAssocRows = array_map(fn($id) => ['id' => (string)$id], $idRows);
        $idResult = $this->createMock(mysqli_result::class);
        $idResult->method('fetch_assoc')->willReturnOnConsecutiveCalls(...array_merge($idAssocRows, [null]));

        $fullResult = $this->createMock(mysqli_result::class);
        $fullResult->method('fetch_assoc')->willReturnOnConsecutiveCalls(...array_merge($fullRows, [null]));

        $fullStmt = $this->createMock(mysqli_stmt::class);
        $fullStmt->method('bind_param')->willReturn(true);
        $fullStmt->method('execute')->willReturn(true);
        $fullStmt->method('get_result')->willReturn($fullResult);

        $conn->method('query')->willReturnCallback(function (string $sql) use (&$queryCallCount, $countResult, $idResult) {
            $this->sqls[] = $sql;
            $queryCallCount++;
            if (stripos($sql, 'COUNT(*)') !== false) {
                return $countResult;
            }
            return $idResult;
        });

        if ($useRand) {
            $randStmt = $this->createMock(mysqli_stmt::class);
            $randStmt->method('bind_param')->willReturn(true);
            $randStmt->method('execute')->willReturn(true);
            $randStmt->method('get_result')->willReturn($idResult);

            $conn->method('prepare')->willReturnCallback(function (string $sql) use ($randStmt, $fullStmt) {
                $this->sqls[] = $sql;
                if (stripos($sql, 'RAND()') !== false) {
                    return $randStmt;
                }
                return $fullStmt;
            });
        } else {
            $conn->method('prepare')->willReturnCallback(function (string $sql) use ($fullStmt) {
                $this->sqls[] = $sql;
                return $fullStmt;
            });
        }

        return $conn;
    }

    public function testRecommendationsReturnsEmptyWhenOnlyOneItem(): void
    {
        $_SESSION = [];
        $conn = $this->buildRecommendConn(1, [], false, []);

        $viewer = new MediaViewer($conn, null, 'music', 1);
        $result = $viewer->getRecommendations();

        $this->assertCount(2, $this->sqls);
        $this->assertStringContainsString('COUNT(*)', $this->sqls[0]);
        $this->assertStringContainsString('WHERE 1 = 0', $this->sqls[1]);
        unset($_SESSION["seen_music_ids"]);
    }

    public function testRecommendationsFetchesAllIdsUnder1000(): void
    {
        $_SESSION = [];
        $conn = $this->buildRecommendConn(5, [1, 2, 3, 4], false, [
            ['id' => '1', 'title' => 'Song A', 'uploader' => 'alice'],
            ['id' => '2', 'title' => 'Song B', 'uploader' => 'bob'],
            ['id' => '3', 'title' => 'Song C', 'uploader' => 'carol'],
            ['id' => '4', 'title' => 'Song D', 'uploader' => 'dave'],
        ]);

        $viewer = new MediaViewer($conn, null, 'music', 5);
        $result = $viewer->getRecommendations(10);

        $this->assertStringNotContainsString('RAND()', $this->sqls[0]);
        $this->assertStringContainsString('COUNT(*)', $this->sqls[0]);
        $this->assertStringContainsString('SELECT id FROM music', $this->sqls[1]);
        $this->assertStringContainsString('m.id IN', $this->sqls[2]);
        $this->assertInstanceOf(mysqli_result::class, $result);
        unset($_SESSION["seen_music_ids"]);
    }

    public function testRecommendationsUsesRandOver1000(): void
    {
        $_SESSION = [];
        $conn = $this->buildRecommendConn(1500, [101, 102, 103], true, [
            ['id' => '101', 'title' => 'Song X', 'uploader' => 'x'],
            ['id' => '102', 'title' => 'Song Y', 'uploader' => 'y'],
            ['id' => '103', 'title' => 'Song Z', 'uploader' => 'z'],
        ]);

        $viewer = new MediaViewer($conn, null, 'music', 100);
        $result = $viewer->getRecommendations(5);

        $this->assertStringContainsString('RAND()', $this->sqls[1]);
        $this->assertStringContainsString('ORDER BY RAND() LIMIT', $this->sqls[1]);
        $this->assertInstanceOf(mysqli_result::class, $result);
        unset($_SESSION["seen_music_ids"]);
    }

    public function testRecommendationsResetsSeenWhenAllItemsAlreadySeen(): void
    {
        $_SESSION["seen_music_ids"] = [2, 3];
        $conn = $this->buildRecommendConn(3, [2, 3], false, [
            ['id' => '2', 'title' => 'Song B', 'uploader' => 'bob'],
            ['id' => '3', 'title' => 'Song C', 'uploader' => 'carol'],
        ]);

        $viewer = new MediaViewer($conn, null, 'music', 1);
        $result = $viewer->getRecommendations(10);

        $this->assertSame([], $_SESSION["seen_music_ids"]);
        $this->assertInstanceOf(mysqli_result::class, $result);
        $this->assertStringContainsString('m.id IN', $this->sqls[2]);
        unset($_SESSION["seen_music_ids"]);
    }

    public function testRecommendationsRespectsLimitParameter(): void
    {
        $_SESSION = [];
        $conn = $this->buildRecommendConn(10, [2, 3, 4, 5, 6, 7, 8, 9, 10], false, [
            ['id' => '2', 'title' => 'Song 2', 'uploader' => 'u2'],
            ['id' => '3', 'title' => 'Song 3', 'uploader' => 'u3'],
            ['id' => '4', 'title' => 'Song 4', 'uploader' => 'u4'],
        ]);

        $viewer = new MediaViewer($conn, null, 'music', 1);
        $result = $viewer->getRecommendations(3);

        $this->assertStringContainsString('m.id IN', $this->sqls[2]);
        $this->assertInstanceOf(mysqli_result::class, $result);
        unset($_SESSION["seen_music_ids"]);
    }

    public function testQueueUsesDeterministicOrderWithTieBreaker(): void
    {
        [$conn] = $this->buildConn(
            ['added_at' => '2026-08-08 10:00:00', 'id' => '5'],
            ['music_id' => '7']
        );

        $viewer = new MediaViewer($conn, null, 'music', 5);
        $result = $viewer->getPlaylistQueue(3);

        
        $this->assertStringContainsString(
            'ORDER BY pt.added_at DESC, pt.id DESC',
            $this->sqls[0]
        );

        
        $this->assertStringContainsString(
            'SELECT added_at, id FROM playlist_tracks',
            $this->sqls[1]
        );
        $this->assertStringContainsString('ORDER BY id DESC LIMIT 1', $this->sqls[1]);

        
        $this->assertStringContainsString('(added_at, id) < (?, ?)', $this->sqls[2]);
        $this->assertStringContainsString(
            'ORDER BY added_at DESC, id DESC LIMIT 1',
            $this->sqls[2]
        );

        
        
        
        $this->assertCount(3, $this->bindCalls);
        $this->assertSame('isi', $this->bindCalls[2]['types']);
        $this->assertSame(3, $this->bindCalls[2]['count']);
        $this->assertSame(strlen($this->bindCalls[2]['types']), $this->bindCalls[2]['count']);

        
        foreach ([0, 1, 2] as $i) {
            $this->assertSame(
                substr_count($this->sqls[$i], '?'),
                strlen($this->bindCalls[$i]['types']),
                "Jumlah placeholder tidak cocok dengan bind_param di query #$i"
            );
        }

        
        $this->assertSame('watch.php?v=7&playlist_id=3', $result['next_url']);
    }

    public function testNextUrlEmptyWhenCurrentTrackNotInPlaylist(): void
    {
        [$conn] = $this->buildConn(null, null);

        $viewer = new MediaViewer($conn, null, 'music', 999);
        $result = $viewer->getPlaylistQueue(3);

        $this->assertSame('', $result['next_url']);
        
        $this->assertCount(2, $this->sqls);
    }

    public function testReturnsNullForNonMusicType(): void
    {
        $conn = $this->createMock(mysqli::class);
        $conn->expects($this->never())->method('prepare');

        $viewer = new MediaViewer($conn, null, 'video', 1);
        $this->assertNull($viewer->getPlaylistQueue(3));
    }

    public function testReturnsNullForEmptyPlaylistId(): void
    {
        $conn = $this->createMock(mysqli::class);
        $conn->expects($this->never())->method('prepare');

        $viewer = new MediaViewer($conn, null, 'music', 1);
        $this->assertNull($viewer->getPlaylistQueue(0));
    }
}
