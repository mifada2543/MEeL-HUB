<?php

use PHPUnit\Framework\TestCase;

final class AdminChatApiTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = '/tmp/opencode/chat-api-' . bin2hex(random_bytes(8));
        foreach (['controllers/api', 'auth', 'modules/auth/helpers', 'modules/core'] as $dir) {
            mkdir($this->root . '/' . $dir, 0700, true);
        }
        $repo = dirname(__DIR__, 3);
        copy($repo . '/controllers/api/chat.php', $this->root . '/controllers/api/chat.php');
        copy($repo . '/modules/core/AdminChatStore.php', $this->root . '/modules/core/AdminChatStore.php');
        copy($repo . '/modules/core/Notification.php', $this->root . '/modules/core/Notification.php');
        file_put_contents($this->root . '/auth/config.php', <<<'PHP'
<?php
class mysqli {
    public function prepare($sql) { return new ChatFixtureStatement($sql); }
}
class ChatFixtureStatement {
    private bool $fetched = false;
    private string $sql;
    private array $values = [];
    public function __construct($sql) { $this->sql = $sql; }
    public function bind_param($types, &...$values) { $this->values = &$values; }
    public function execute() {
        $file = __DIR__ . '/../notifications.json';
        $rows = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        if (strpos($this->sql, 'SELECT') !== 0) {
            $lock = fopen(__DIR__ . '/../storage/chats/7/.lock', 'c');
            $outsideLock = flock($lock, LOCK_EX | LOCK_NB);
            fclose($lock);
            if ($outsideLock) { throw new RuntimeException('Notification ran outside conversation lock'); }
        }
        if (strpos($this->sql, 'INSERT INTO user_notifications') === 0) {
            file_put_contents(__DIR__ . '/../modules/core/notification-called', 'yes');
            if ($GLOBALS['input']['notification_warning'] ?? false) { trigger_error('notification warning', E_USER_WARNING); }
            if ($GLOBALS['input']['notification_failure'] ?? false) { throw new RuntimeException('notification failed'); }
            if ($GLOBALS['input']['notification_execute_failure'] ?? false) { return false; }
            $rows[] = array_combine(['user_id', 'type', 'title', 'message', 'related_id', 'related_slug', 'actor_user_id'], $this->values);
        } elseif (strpos($this->sql, 'DELETE FROM user_notifications') === 0) {
            $conditions = [];
            $parameter = 0;
            foreach (explode(' AND ', substr($this->sql, strpos($this->sql, ' WHERE ') + 7)) as $clause) {
                if (!preg_match("/^([a-z_]+) = (\\?|'.*')$/", $clause, $match)) { throw new RuntimeException('Unsupported fixture predicate'); }
                $conditions[$match[1]] = $match[2] === '?' ? $this->values[$parameter++] : substr($match[2], 1, -1);
            }
            $rows = array_values(array_filter($rows, static function ($row) use ($conditions) {
                foreach ($conditions as $key => $value) {
                    if (($row[$key] ?? null) !== $value) { return true; }
                }
                return false;
            }));
        } else { return true; }
        file_put_contents($file, json_encode($rows));
        return true;
    }
    public function get_result() { return $this; }
    public function fetch_assoc() {
        if ($this->fetched || ($GLOBALS['input']['missing_user'] ?? false)) { return null; }
        $this->fetched = true;
        return ['id' => 7, 'username' => 'target', 'role' => 'user', 'profile_picture' => null, 'is_active' => $GLOBALS['input']['active'] ?? 1];
    }
    public function close() {}
}
$conn = new mysqli;
$_SESSION['user_id'] = $GLOBALS['input']['actor'] ?? 1;
function meel_base_url_path() { return ''; }
PHP
        );
        file_put_contents($this->root . '/modules/auth/helpers/authz.php', '<?php function is_admin($conn) { return $GLOBALS["input"]["admin"] ?? true; }');
        file_put_contents($this->root . '/modules/auth/helpers/csrf.php', '<?php function verify_csrf_token($token) { return $token === "valid"; }');
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function request(array $get, array $post = [], string $method = 'GET', array $options = []): array
    {
        $input = $options + ['get' => $get, 'post' => $post, 'method' => $method];
        $script = '$input=unserialize(base64_decode(' . var_export(base64_encode(serialize($input)), true) . '));'
            . '$_GET=$input["get"];$_POST=$input["post"];$_SERVER["REQUEST_METHOD"]=$input["method"];$_SERVER["DOCUMENT_ROOT"]="/invalid-document-root";'
            . 'ob_start(); register_shutdown_function(function(){ $body=ob_get_clean(); echo json_encode(["status"=>http_response_code()?:200,"body"=>json_decode($body,true),"raw"=>$body]); });'
            . 'require ' . var_export($this->root . '/controllers/api/chat.php', true) . ';';
        $pipes = [];
        // No extensions: the fixture doubles only mysqli, while real Notification runs.
        $process = proc_open([PHP_BINARY, '-n', '-d', 'extension=ctype', '-d', 'display_errors=1', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $result = json_decode($stdout, true);
        $this->assertIsArray($result, $stderr . $stdout);
        $this->assertIsArray($result['body'], $stderr . $result['raw']);
        $result['stderr'] = $stderr;
        return $result;
    }

    public function testGetReturnsRevisionAndIgnoresDocumentRoot(): void
    {
        $response = $this->request(['action' => 'get', 'user_id' => 7]);
        $this->assertSame(200, $response['status']);
        $this->assertSame([], $response['body']['messages']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response['body']['revision'] ?? '');
    }

    public function testAuthMethodAndCsrfAreEnforcedAsJson(): void
    {
        $denied = $this->request(['action' => 'get', 'user_id' => 7], [], 'GET', ['admin' => false]);
        $this->assertSame(403, $denied['status']);
        $method = $this->request(['action' => 'send']);
        $this->assertSame(405, $method['status']);
        $csrf = $this->request([], ['action' => 'send', 'user_id' => 7, 'message' => 'hello'], 'POST');
        $this->assertSame(403, $csrf['status']);
    }

    public function testMalformedRequestsReturnJsonClientErrors(): void
    {
        foreach ([['action' => ['get']], ['action' => 'get', 'user_id' => '7junk'], ['action' => 'get', 'user_id' => [7]], ['action' => 'unknown']] as $get) {
            $result = $this->request($get);
            $this->assertSame(400, $result['status']);
            $this->assertFalse($result['body']['ok']);
        }
        $result = $this->request([], ['action' => 'send', 'csrf_token' => ['valid']], 'POST');
        $this->assertSame(403, $result['status']);
    }

    public function testUsersAndRecentListsRemainAvailable(): void
    {
        $users = $this->request(['action' => 'users', 'q' => 'target']);
        $this->assertSame('target', $users['body']['list'][0]['username']);
        $empty = $this->request(['action' => 'recent']);
        $this->assertSame([], $empty['body']['list']);
        $this->request([], ['action' => 'send', 'user_id' => 7, 'message' => 'latest', 'csrf_token' => 'valid'], 'POST');
        $recent = $this->request(['action' => 'recent']);
        $this->assertSame(7, $recent['body']['list'][0]['user_id']);
        $this->assertSame('latest', $recent['body']['list'][0]['last_message']);
        $this->assertSame(1, $recent['body']['list'][0]['count']);
        $inactive = $this->request(['action' => 'recent'], [], 'GET', ['active' => 0]);
        $this->assertSame([], $inactive['body']['list']);
    }

    public function testInvalidInputAndInactiveTargetAreRejected(): void
    {
        foreach (['', str_repeat('あ', 5001), ['bad'], "\xff"] as $message) {
            $result = $this->request([], ['action' => 'send', 'user_id' => 7, 'message' => $message, 'csrf_token' => 'valid'], 'POST');
            $this->assertSame(400, $result['status']);
            $this->assertFalse($result['body']['ok']);
        }
        $result = $this->request([], ['action' => 'send', 'user_id' => 7, 'message' => 'hello', 'csrf_token' => 'valid'], 'POST', ['active' => 0]);
        $this->assertSame(403, $result['status']);
        $missing = $this->request([], ['action' => 'send', 'user_id' => 7, 'message' => 'hello', 'csrf_token' => 'valid'], 'POST', ['missing_user' => true]);
        $this->assertSame(403, $missing['status']);
        $this->assertFileDoesNotExist($this->root . '/modules/core/notification-called');
    }

    public function testSavedMessageRemainsSuccessfulWhenNotificationFails(): void
    {
        $result = $this->request([], ['action' => 'send', 'user_id' => 7, 'message' => str_repeat('あ', 5000), 'csrf_token' => 'valid'], 'POST', ['notification_failure' => true]);
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['ok']);
        $history = json_decode(file_get_contents($this->root . '/storage/chats/7/isipesan.json'), true);
        $this->assertSame(str_repeat('あ', 5000), $history[0]['message']);
    }

    public function testPersistenceFailureDoesNotNotify(): void
    {
        mkdir($this->root . '/storage/chats/7', 0700, true);
        file_put_contents($this->root . '/storage/chats/7/isipesan.json', '{broken');
        $result = $this->request([], ['action' => 'send', 'user_id' => 7, 'message' => 'hello', 'csrf_token' => 'valid'], 'POST');
        $this->assertSame(500, $result['status']);
        $this->assertFalse($result['body']['ok']);
        $this->assertFileDoesNotExist($this->root . '/modules/core/notification-called');
        $this->assertSame('{broken', file_get_contents($this->root . '/storage/chats/7/isipesan.json'));
    }

    public function testNotificationWarningDoesNotCorruptSuccessfulJsonResponse(): void
    {
        $result = $this->request([], ['action' => 'send', 'user_id' => 7, 'message' => 'hello', 'csrf_token' => 'valid'], 'POST', ['notification_warning' => true]);
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['ok']);
    }

    public function testNotificationExecuteFailureIsLoggedWithoutMisreportingSavedMessage(): void
    {
        $result = $this->request([], ['action' => 'send', 'user_id' => 7, 'message' => 'hello', 'csrf_token' => 'valid'], 'POST', ['notification_execute_failure' => true]);
        $this->assertTrue($result['body']['ok']);
        $this->assertStringContainsString('notification callback failed', $result['stderr']);
        $loaded = $this->request(['action' => 'get', 'user_id' => 7]);
        $this->assertCount(1, $loaded['body']['messages']);
    }

    public function testDeleteRequiresCurrentRevision(): void
    {
        $post = ['action' => 'send', 'user_id' => 7, 'message' => 'hello', 'csrf_token' => 'valid'];
        $this->request([], $post, 'POST');
        $loaded = $this->request(['action' => 'get', 'user_id' => 7]);
        $delete = ['action' => 'delete', 'user_id' => 7, 'index' => 0, 'csrf_token' => 'valid'];
        $missing = $this->request([], $delete, 'POST');
        $this->assertSame(400, $missing['status']);
        $this->request([], $post, 'POST');
        $stale = $this->request([], $delete + ['revision' => $loaded['body']['revision']], 'POST');
        $this->assertSame(409, $stale['status']);
        $loaded = $this->request(['action' => 'get', 'user_id' => 7]);
        $saved = $this->request([], $delete + ['revision' => $loaded['body']['revision']], 'POST');
        $this->assertTrue($saved['body']['ok']);
        $loaded = $this->request(['action' => 'get', 'user_id' => 7]);
        $this->assertCount(1, $loaded['body']['messages']);
    }

    public static function deletingActors(): array
    {
        return ['same admin' => [1], 'different admin' => [2]];
    }

    /** @dataProvider deletingActors */
    public function testDuplicateTextNotificationsAreDeletedByMessageIdentityAcrossAdmins(int $actor): void
    {
        $send = ['action' => 'send', 'user_id' => 7, 'message' => 'duplicate', 'csrf_token' => 'valid'];
        $this->request([], $send, 'POST', ['actor' => 1]);
        $this->request([], $send, 'POST', ['actor' => 1]);
        $loaded = $this->request(['action' => 'get', 'user_id' => 7]);
        $messages = $loaded['body']['messages'];
        $rows = json_decode(file_get_contents($this->root . '/notifications.json'), true);
        $this->assertSame('chat:' . ($messages[0]['id'] ?? ''), $rows[0]['related_slug']);
        $this->assertSame('chat:' . ($messages[1]['id'] ?? ''), $rows[1]['related_slug']);
        $this->assertNotSame($rows[0]['related_slug'], $rows[1]['related_slug']);
        $otherUser = $rows[0];
        $otherUser['user_id'] = 8;
        $otherType = $rows[0];
        $otherType['type'] = 'reply';
        file_put_contents($this->root . '/notifications.json', json_encode([...$rows, $otherUser, $otherType]));
        $deleted = $this->request([], ['action' => 'delete', 'user_id' => 7, 'index' => 0, 'revision' => $loaded['body']['revision'], 'csrf_token' => 'valid'], 'POST', ['actor' => $actor]);
        $this->assertTrue($deleted['body']['ok']);
        $remaining = json_decode(file_get_contents($this->root . '/notifications.json'), true);
        $this->assertSame([$rows[1], $otherUser, $otherType], $remaining);
    }

    public function testDeletingLegacyHistoryLeavesAmbiguousNotificationsUntouched(): void
    {
        mkdir($this->root . '/storage/chats/7', 0700, true);
        $message = ['from' => 'admin', 'message' => 'duplicate', 'created_at' => '2020-01-01 00:00:00'];
        file_put_contents($this->root . '/storage/chats/7/isipesan.json', json_encode([$message, $message]));
        $rows = [['user_id' => 7, 'type' => 'admin_chat', 'message' => 'duplicate', 'related_slug' => null, 'actor_user_id' => 1]];
        file_put_contents($this->root . '/notifications.json', json_encode($rows));
        $loaded = $this->request(['action' => 'get', 'user_id' => 7]);
        $deleted = $this->request([], ['action' => 'delete', 'user_id' => 7, 'index' => 0, 'revision' => $loaded['body']['revision'], 'csrf_token' => 'valid'], 'POST');
        $this->assertTrue($deleted['body']['ok']);
        $this->assertSame($rows, json_decode(file_get_contents($this->root . '/notifications.json'), true));
        $loaded = $this->request(['action' => 'get', 'user_id' => 7]);
        $this->assertSame([$message], $loaded['body']['messages']);
    }
}
