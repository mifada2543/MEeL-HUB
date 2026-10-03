<?php

use PHPUnit\Framework\TestCase;

class NotificationRetentionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/support/MysqlFake.php';
        require_once MEEL_ROOT . '/modules/core/Notification.php';
    }

    private function callPrivate(string $method, array $args)
    {
        $m = new ReflectionMethod(Notification::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    private function coalesceConfig(): array
    {
        $c = new ReflectionClass(Notification::class);
        return (array)$c->getConstant('COALESCE_MINUTES');
    }

    public function testOnlyLikeIsCoalesced(): void
    {
        $cfg = $this->coalesceConfig();

        $this->assertArrayHasKey('like', $cfg, 'like wajib punya jendela coalescing');
        $this->assertSame(60, $cfg['like'], 'jendela like = 60 menit');

        foreach (['reply', 'admin_chat', 'meelcoin', 'system'] as $type) {
            $this->assertArrayNotHasKey(
                $type,
                $cfg,
                "tipe `$type` tidak boleh di-throttle — percakapan/pesan admin/coin harus selalu sampai"
            );
        }
    }

    public function testIsCoalescedAlwaysFalseForUnthrottledType(): void
    {
        $conn = new FakeMysqli();

        foreach (['reply', 'admin_chat', 'meelcoin', 'system'] as $type) {
            $this->assertFalse(
                $this->callPrivate('isCoalesced', [$conn, 1, $type]),
                "tipe `$type` harus selalu lolos (tidak adaquery sama sekali)"
            );
        }
        $this->assertSame([], $conn->sqls, 'tipe tanpa throttle tidak boleh menyentuh DB');
    }

    public function testIsCoalescedTrueWhenLikeInsideWindow(): void
    {
        $conn = new FakeMysqli();
        $conn->windowResult = new FakeMysqliResult([['id' => '7']]);

        $this->assertTrue(
            $this->callPrivate('isCoalesced', [$conn, 1, 'like']),
            'ada notifikasi like dalam jendela → like berikutnya harus digabung'
        );

        $this->assertSame(1, count($conn->sqls), 'harus tepat satu query lookup');
        $this->assertStringContainsString('type = ?', $conn->sqls[0]);
        $this->assertStringContainsString('created_at >= ?', $conn->sqls[0]);
        $this->assertStringContainsString('LIMIT 1', $conn->sqls[0]);
    }

    public function testIsCoalescedFalseWhenLikeOutsideWindow(): void
    {
        $conn = new FakeMysqli();
        $conn->windowResult = new FakeMysqliResult([]);

        $this->assertFalse(
            $this->callPrivate('isCoalesced', [$conn, 1, 'like']),
            'tidak ada notifikasi like dalam jendela → like baru harus dibuat'
        );
    }

    public function testCoalesceLookupBindsThreeParams(): void
    {
        $conn = new FakeMysqli();
        $conn->windowResult = new FakeMysqliResult([]);
        $this->callPrivate('isCoalesced', [$conn, 42, 'like']);

        $stmt = $conn->createdStmts[0] ?? null;
        $this->assertNotNull($stmt, 'lookup harus memakai prepared statement');
        $this->assertSame('iss', $stmt->binds[0]['types'], 'user_id + type + window');
        $this->assertCount(3, $stmt->binds[0]['vars']);
        $this->assertSame(42, $stmt->binds[0]['vars'][0]);
        $this->assertSame('like', $stmt->binds[0]['vars'][1]);
    }

    public function testPruneUserIssuesDeleteScopedToOneUser(): void
    {
        $conn = new FakeMysqli();

        Notification::pruneUser($conn, 7);

        $this->assertCount(1, $conn->sqls);
        $sql = $conn->sqls[0];
        $this->assertStringContainsString('DELETE FROM user_notifications', $sql);
        $this->assertStringContainsString('user_id = ?', $sql);

        $stmt = $conn->createdStmts[0];
        $this->assertSame('iiii', $stmt->binds[0]['types'], 'user_id, age, user_id, keep');
        $this->assertSame(7, $stmt->binds[0]['vars'][0]);
    }

    public function testPruneUserEnforcesBothKeepCountAndAge(): void
    {
        $conn = new FakeMysqli();

        Notification::pruneUser($conn, 7, 50, 15);

        $sql = $conn->sqls[0];
        $this->assertStringContainsString(
            'created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            $sql,
            'harus memangkas berdasarkan umur'
        );
        $this->assertStringContainsString(
            'ORDER BY created_at DESC, id DESC',
            $sql,
            'harus mempertahankan N terbaru'
        );
        $this->assertStringContainsString('LIMIT ?', $sql);

        $vars = $conn->createdStmts[0]->binds[0]['vars'];
        $this->assertSame(50, $vars[3], 'default keep = 50');
    }

    public function testPruneUserClampsNonsensicalArguments(): void
    {
        $conn = new FakeMysqli();

        Notification::pruneUser($conn, 7, 0, 0);

        $vars = $conn->createdStmts[0]->binds[0]['vars'];
        $this->assertSame(1, $vars[3], 'keep minimal 1 — jangan sampai 0 berarti hapus semua');
        $this->assertSame(1, $vars[1], 'umur minimal 1 hari');
    }

    public function testPruneAllCoversEveryUserWithNotifications(): void
    {
        $conn = new FakeMysqli();
        $conn->queryResult = new FakeMysqliResult([
            ['user_id' => '3'],
            ['user_id' => '9'],
        ]);

        $users = Notification::pruneAll($conn, 50, 15);

        $deletes = array_values(array_filter(
            $conn->sqls,
            fn($s) => str_contains($s, 'DELETE FROM user_notifications')
        ));
        $this->assertCount(2, $deletes, 'harus memangkas tiap user yang punya notifikasi');

        foreach ($deletes as $sql) {
            $this->assertStringContainsString('user_id = ?', $sql);
        }
        $this->assertSame(2, $users, 'pruneAll mengembalikan jumlah user yang dipangkas');
    }

    public function testPruneAllReturnsZeroWhenNoNotifications(): void
    {
        $conn = new FakeMysqli();
        $conn->queryResult = new FakeMysqliResult([]);

        $this->assertSame(0, Notification::pruneAll($conn));
        $this->assertSame([], array_filter($conn->sqls, fn($s) => str_contains($s, 'DELETE')));
    }

    public function testDefaultsMatchDocumentedPolicy(): void
    {
        $c = new ReflectionClass(Notification::class);

        $this->assertSame(50, $c->getConstant('PRUNE_KEEP'), 'batas 50 baris per pengguna');
        $this->assertSame(15, $c->getConstant('PRUNE_MAX_AGE_DAYS'), 'batas umur 15 hari');
    }
}