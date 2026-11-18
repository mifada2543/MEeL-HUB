<?php

use PHPUnit\Framework\TestCase;

final class AdminChatStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = '/tmp/opencode/chat-store-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function store(): object
    {
        $this->assertFileExists(__DIR__ . '/../../../modules/core/AdminChatStore.php');
        require_once __DIR__ . '/../../../modules/core/AdminChatStore.php';
        return new AdminChatStore($this->root);
    }

    public function testAppendPreservesLegacyMessagesAndPublishesRevision(): void
    {
        $store = $this->store();
        $empty = $store->get(7);
        $this->assertSame([], $empty['messages']);
        $saved = $store->send(7, '日本語');
        $this->assertSame('admin', $saved['messages'][0]['from']);
        $this->assertSame('日本語', $saved['messages'][0]['message']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $saved['messages'][0]['id'] ?? '');
        $this->assertSame($saved, $store->get(7));
        $this->assertNotSame($empty['revision'], $saved['revision']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $saved['revision']);
    }

    public function testStaleRevisionCannotDeleteAnotherMessage(): void
    {
        $store = $this->store();
        $first = $store->send(7, 'first');
        $second = $store->send(7, 'second');
        $deleted = $store->delete(7, 0, $second['revision']);
        $this->assertSame('first', $deleted['deleted_message']);
        try {
            $store->delete(7, 0, $first['revision']);
            $this->fail('Stale index must be rejected');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertSame('second', $store->get(7)['messages'][0]['message']);
    }

    public function testCorruptHistoryIsNotReplaced(): void
    {
        $store = $this->store();
        mkdir($this->root . '/7');
        file_put_contents($this->root . '/7/isipesan.json', '{broken');
        $called = false;
        try {
            $store->send(7, 'new', static function () use (&$called): void { $called = true; });
            $this->fail('Corrupt history must fail');
        } catch (RuntimeException $e) {
            $this->assertSame(500, $e->getCode());
        }
        $this->assertSame('{broken', file_get_contents($this->root . '/7/isipesan.json'));
        $this->assertFalse($called);
    }

    public function testUnreadableHistoryAndFailedPublicationAreNotSuccess(): void
    {
        $store = $this->store();
        mkdir($this->root . '/7');
        mkdir($this->root . '/7/isipesan.json');
        $this->expectException(RuntimeException::class);
        $store->send(7, 'new');
    }

    public function testUnusableStorageRootIsNotSuccess(): void
    {
        $store = $this->store();
        file_put_contents($this->root . '/7', 'blocked');
        $this->expectException(RuntimeException::class);
        $store->send(7, 'new');
    }

    public function testFailedWritePreservesPublishedHistory(): void
    {
        $store = $this->store();
        $saved = $store->send(7, 'original');
        chmod($this->root . '/7', 0500);
        try {
            if (is_writable($this->root . '/7')) {
                $this->markTestSkipped('Privileged process bypasses directory permissions');
            }
            try {
                $store->send(7, 'must not be saved');
                $this->fail('Unwritable directory must fail publication');
            } catch (RuntimeException $e) {
                $this->assertSame(500, $e->getCode());
            }
            $this->assertSame($saved, $store->get(7));
        } finally {
            chmod($this->root . '/7', 0700);
        }
    }

    public function testUnreadableFileIsNotTreatedAsEmptyHistory(): void
    {
        $store = $this->store();
        $store->send(7, 'original');
        $file = $this->root . '/7/isipesan.json';
        chmod($file, 0000);
        try {
            if (is_readable($file)) {
                $this->markTestSkipped('Privileged process bypasses file permissions');
            }
            $this->expectException(RuntimeException::class);
            $store->get(7);
        } finally {
            chmod($file, 0600);
        }
    }

    public function testInvalidUnicodeCannotBePersisted(): void
    {
        $store = $this->store();
        $this->expectException(RuntimeException::class);
        $store->send(7, "\xff");
    }

    public function testConcurrentAppendsDoNotLoseMessages(): void
    {
        $store = $this->store();
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for concurrent writers');
        }
        $children = [];
        for ($i = 0; $i < 6; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    for ($j = 0; $j < 12; $j++) {
                        $store->send(7, "$i:$j");
                    }
                    exit(0);
                } catch (Throwable $e) {
                    exit(1);
                }
            }
            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        $messages = $store->get(7)['messages'];
        $this->assertCount(72, $messages);
        $this->assertCount(72, array_unique(array_column($messages, 'message')));
    }

    public function testNewIdsAreUniqueAndLegacyHistoryIsNotMigrated(): void
    {
        $store = $this->store();
        mkdir($this->root . '/7');
        $legacy = ['from' => 'admin', 'message' => 'same', 'created_at' => '2020-01-01 00:00:00'];
        file_put_contents($this->root . '/7/isipesan.json', json_encode([$legacy]));
        $store->send(7, 'same');
        $saved = $store->send(7, 'same');
        $this->assertSame($legacy, $saved['messages'][0]);
        $this->assertNotSame($saved['messages'][1]['id'] ?? null, $saved['messages'][2]['id'] ?? null);
    }

    public function testPostPersistCallbacksObserveSavedStateAndFailuresDoNotUndoIt(): void
    {
        $store = $this->store();
        $sent = null;
        $sendHistory = null;
        $saved = $store->send(7, 'hello', function (array $message) use (&$sent, &$sendHistory): void {
            $sent = $message;
            $sendHistory = json_decode(file_get_contents($this->root . '/7/isipesan.json'), true);
            throw new RuntimeException('notification unavailable');
        });
        $this->assertSame($saved['messages'][0], $sent);
        $this->assertSame([$sent], $sendHistory);
        $deleted = null;
        $deleteHistory = null;
        $result = $store->delete(7, 0, $saved['revision'], function (array $message) use (&$deleted, &$deleteHistory): void {
            $deleted = $message;
            $deleteHistory = json_decode(file_get_contents($this->root . '/7/isipesan.json'), true);
            throw new RuntimeException('cleanup unavailable');
        });
        $this->assertSame($sent, $deleted);
        $this->assertSame([], $deleteHistory);
        $this->assertSame([], $result['messages']);
        $this->assertSame([], $store->get(7)['messages']);
    }

    public function testSendNotificationFinishesBeforeConcurrentDeleteNotification(): void
    {
        $store = $this->store();
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required');
        }
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_timeout($sockets[0], 5);
        $sender = pcntl_fork();
        if ($sender === 0) {
            fclose($sockets[0]);
            try {
                $store->send(7, 'hello', function (array $message) use ($sockets): void {
                    fwrite($sockets[1], "persisted\n");
                    if (fgets($sockets[1]) !== "release\n") { throw new RuntimeException('missing release'); }
                    file_put_contents($this->root . '/events', "created\n", FILE_APPEND);
                });
                exit(0);
            } catch (Throwable $e) { exit(1); }
        }
        fclose($sockets[1]);
        $deleter = null;
        try {
            $this->assertSame("persisted\n", fgets($sockets[0]));
            $raw = file_get_contents($this->root . '/7/isipesan.json');
            $lock = fopen($this->root . '/7/.lock', 'c');
            $this->assertFalse(flock($lock, LOCK_EX | LOCK_NB), 'Callback must still hold exclusive lock');
            fclose($lock);
            $deleter = pcntl_fork();
            if ($deleter === 0) {
                fclose($sockets[0]);
                try {
                    file_put_contents($this->root . '/delete-started', 'yes');
                    $store->delete(7, 0, hash('sha256', $raw), function (): void {
                        file_put_contents($this->root . '/events', "deleted\n", FILE_APPEND);
                    });
                    exit(0);
                } catch (Throwable $e) { exit(1); }
            }
            $deadline = microtime(true) + 5;
            while (!is_file($this->root . '/delete-started') && microtime(true) < $deadline) { usleep(1000); }
            $this->assertFileExists($this->root . '/delete-started');
        } finally {
            fwrite($sockets[0], "release\n");
            fclose($sockets[0]);
            pcntl_waitpid($sender, $status);
            if ($deleter > 0) { pcntl_waitpid($deleter, $deleteStatus); }
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame(0, pcntl_wexitstatus($deleteStatus));
        $this->assertSame("created\ndeleted\n", file_get_contents($this->root . '/events'));
        $this->assertSame([], $store->get(7)['messages']);
    }
}
