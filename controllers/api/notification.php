<?php
define('MEEL_API_CONTEXT', true);
require_once '../../modules/core/helpers.php';
meel_boot_session();
include '../../auth/config.php';
require_once __DIR__ . '/../../modules/auth/helpers/csrf.php';
require_once __DIR__ . '/../../modules/core/Notification.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$readActions = ['unread_count', 'list'];
$writeActions = ['mark_read', 'mark_all_read', 'delete', 'delete_all'];

if (in_array($action, $writeActions)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token tidak valid']);
        exit;
    }
}

switch ($action) {
    case 'unread_count':
        echo json_encode(['count' => Notification::getUnreadCount($conn, $userId)]);
        break;

    case 'list':
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $list = Notification::getList($conn, $userId, $limit);
        foreach ($list as &$n) {
            $n['time_ago'] = time_ago($n['created_at']);
        }
        unset($n);
        echo json_encode(['ok' => true, 'list' => $list, 'count' => count($list)]);
        break;

    case 'mark_read':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            Notification::markRead($conn, $id, $userId);
        }
        echo json_encode(['ok' => true]);
        break;

    case 'mark_all_read':
        Notification::markAllRead($conn, $userId);
        echo json_encode(['ok' => true]);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            Notification::deleteOne($conn, $id, $userId);
        }
        echo json_encode(['ok' => true]);
        break;

    case 'delete_all':
        Notification::deleteAllByUser($conn, $userId);
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
