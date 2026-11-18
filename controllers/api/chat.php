<?php
define('MEEL_API_CONTEXT', true);
header('Content-Type: application/json; charset=utf-8');

$respond = static function (array $body, int $status = 200): void {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    http_response_code($status);
    echo $json;
};
$integer = static function ($value, int $minimum = 1): int {
    $number = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
    if ($number === false || $number < $minimum) {
        throw new RuntimeException('Invalid input', 400);
    }
    return $number;
};

// Keep PHP/driver warnings out of JSON, including best-effort notifications.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

try {
    include __DIR__ . '/../../auth/config.php';
    require_once __DIR__ . '/../../modules/auth/helpers/authz.php';
    require_once __DIR__ . '/../../modules/auth/helpers/csrf.php';
    require_once __DIR__ . '/../../modules/core/Notification.php';
    require_once __DIR__ . '/../../modules/core/AdminChatStore.php';

    if (!is_admin($conn)) {
        throw new RuntimeException('Akses ditolak', 403);
    }
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    if (!is_string($action)) {
        throw new RuntimeException('Invalid action', 400);
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (in_array($action, ['send', 'delete'], true)) {
        if ($method !== 'POST') {
            header('Allow: POST');
            throw new RuntimeException('Method not allowed', 405);
        }
        if (!is_string($_POST['csrf_token'] ?? null) || !verify_csrf_token($_POST['csrf_token'])) {
            throw new RuntimeException('CSRF token tidak valid', 403);
        }
    } elseif (in_array($action, ['get', 'recent', 'users'], true) && $method !== 'GET') {
        header('Allow: GET');
        throw new RuntimeException('Method not allowed', 405);
    }

    $chatDir = dirname(__DIR__, 2) . '/storage/chats';
    $store = new AdminChatStore($chatDir);
    switch ($action) {
        case 'recent':
            $recent = [];
            $dirs = is_dir($chatDir) ? glob($chatDir . '/*', GLOB_ONLYDIR) : [];
            if ($dirs === false) {
                throw new RuntimeException('Cannot list chat history', 500);
            }
            foreach ($dirs as $dir) {
                $name = basename($dir);
                if (!ctype_digit($name) || (int)$name <= 0 || !is_file($dir . '/isipesan.json')) {
                    continue;
                }
                $userId = (int)$name;
                $messages = $store->get($userId)['messages'];
                if ($messages === []) {
                    continue;
                }
                $last = end($messages);
                $stmt = $conn->prepare('SELECT id, username, profile_picture, is_active FROM users WHERE id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$user || (int)($user['is_active'] ?? 0) !== 1) {
                    continue;
                }
                $recent[] = [
                    'user_id' => $userId,
                    'username' => $user['username'],
                    'profile_picture' => $user['profile_picture'] ?? null,
                    'last_message' => $last['message'],
                    'last_from' => $last['from'],
                    'last_at' => $last['created_at'],
                    'count' => count($messages),
                ];
            }
            usort($recent, fn($a, $b) => strtotime($b['last_at']) - strtotime($a['last_at']));
            $respond(['ok' => true, 'list' => $recent]);
            break;

        case 'get':
            $snapshot = $store->get($integer($_GET['user_id'] ?? null));
            $respond(['ok' => true] + $snapshot);
            break;

        case 'send':
            $userId = $integer($_POST['user_id'] ?? null);
            $message = $_POST['message'] ?? null;
            if (!is_string($message)) {
                throw new RuntimeException('Invalid message', 400);
            }
            $message = trim($message);
            $length = preg_match_all('/./us', $message);
            if ($message === '' || $length === false || $length > 5000) {
                throw new RuntimeException('Invalid message (maximum 5000 characters)', 400);
            }
            $stmt = $conn->prepare('SELECT is_active FROM users WHERE id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$user || (int)$user['is_active'] !== 1) {
                throw new RuntimeException('Tidak bisa mengirim pesan ke pengguna pending', 403);
            }
            $snapshot = $store->send($userId, $message, static function (array $saved) use ($conn, $userId): void {
                Notification::create($conn, $userId, 'admin_chat', 'Pesan dari Admin', $saved['message'], null, 'chat:' . $saved['id'], $_SESSION['user_id']);
            });
            $respond(['ok' => true, 'revision' => $snapshot['revision']]);
            break;

        case 'delete':
            $userId = $integer($_POST['user_id'] ?? null);
            $index = $integer($_POST['index'] ?? null, 0);
            $revision = $_POST['revision'] ?? null;
            if (!is_string($revision)) {
                throw new RuntimeException('Revision required', 400);
            }
            $snapshot = $store->delete($userId, $index, $revision, static function (array $deleted) use ($conn, $userId): void {
                // Legacy rows have no unambiguous correlation; let retention prune them.
                if (!isset($deleted['id']) || !is_string($deleted['id']) || !preg_match('/^[a-f0-9]{32}$/D', $deleted['id'])) {
                    return;
                }
                if (!Notification::deleteByChatId($conn, $userId, $deleted['id'])) {
                    throw new RuntimeException('Notification cleanup returned false');
                }
            });
            $respond(['ok' => true, 'revision' => $snapshot['revision']]);
            break;

        case 'users':
            $q = $_GET['q'] ?? '';
            if (!is_string($q)) {
                throw new RuntimeException('Invalid query', 400);
            }
            $users = [];
            if ($q !== '') {
                $like = '%' . $q . '%';
                $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE role NOT IN ('admin', 'guest') AND is_active = 1 AND username LIKE ? ORDER BY username ASC LIMIT 20");
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $users[] = $row;
                }
                $stmt->close();
            }
            $respond(['ok' => true, 'list' => $users]);
            break;

        default:
            throw new RuntimeException('Invalid action', 400);
    }
} catch (Throwable $e) {
    $status = $e instanceof RuntimeException && in_array($e->getCode(), [400, 403, 404, 405, 409], true) ? $e->getCode() : 500;
    if ($status === 500) {
        error_log('[admin chat] Request failed: ' . $e->getMessage());
    }
    $respond(['ok' => false, 'error' => $status === 500 ? 'Chat storage or service unavailable' : $e->getMessage()], $status);
} finally {
    restore_error_handler();
}
