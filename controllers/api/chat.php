<?php
define('MEEL_API_CONTEXT', true);
include __DIR__ . '/../../auth/config.php';
require_once __DIR__ . '/../../modules/auth/helpers/authz.php';
require_once __DIR__ . '/../../modules/auth/helpers/csrf.php';
require_once __DIR__ . '/../../modules/core/Notification.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin($conn)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Akses ditolak']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$chatDir = $_SERVER['DOCUMENT_ROOT'] . meel_base_url_path() . '/storage/chats';

$writeActions = ['send', 'delete'];
if (in_array($action, $writeActions)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF token tidak valid']);
        exit;
    }
}

switch ($action) {
    case 'recent':
        $recent = [];
        if (is_dir($chatDir)) {
            $dirs = glob($chatDir . '/*', GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                $userId = basename($dir);
                $jsonFile = $dir . '/isipesan.json';
                if (!is_file($jsonFile)) continue;
                $msgs = json_decode(file_get_contents($jsonFile), true);
                if (!is_array($msgs) || empty($msgs)) continue;
                $last = end($msgs);
                $stmt = $conn->prepare("SELECT id, username, profile_picture, is_active FROM users WHERE id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$user) continue;
                if ((int)($user['is_active'] ?? 0) !== 1) continue;
                $recent[] = [
                    'user_id' => (int)$userId,
                    'username' => $user['username'],
                    'profile_picture' => $user['profile_picture'] ?? null,
                    'last_message' => $last['message'] ?? '',
                    'last_from' => $last['from'] ?? '',
                    'last_at' => $last['created_at'] ?? '',
                    'count' => count($msgs),
                ];
            }
            usort($recent, fn($a, $b) => strtotime($b['last_at']) - strtotime($a['last_at']));
        }
        echo json_encode(['ok' => true, 'list' => $recent]);
        break;

    case 'get':
        $userId = (int)($_GET['user_id'] ?? 0);
        if ($userId <= 0) { echo json_encode(['ok' => false]); break; }
        $jsonFile = $chatDir . '/' . $userId . '/isipesan.json';
        $msgs = [];
        if (is_file($jsonFile)) {
            $msgs = json_decode(file_get_contents($jsonFile), true);
            if (!is_array($msgs)) $msgs = [];
        }
        echo json_encode(['ok' => true, 'messages' => $msgs]);
        break;

    case 'send':
        $userId = (int)($_POST['user_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        if ($userId <= 0 || $message === '') {
            echo json_encode(['ok' => false, 'error' => 'Invalid input']);
            break;
        }
        $chk = $conn->prepare("SELECT is_active FROM users WHERE id = ?");
        $chk->bind_param("i", $userId);
        $chk->execute();
        $userStatus = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$userStatus || (int)$userStatus['is_active'] !== 1) {
            echo json_encode(['ok' => false, 'error' => 'Tidak bisa mengirim pesan ke pengguna pending']);
            break;
        }
        $dir = $chatDir . '/' . $userId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $jsonFile = $dir . '/isipesan.json';
        $msgs = [];
        if (is_file($jsonFile)) {
            $msgs = json_decode(file_get_contents($jsonFile), true);
            if (!is_array($msgs)) $msgs = [];
        }
        $msgs[] = [
            'from' => 'admin',
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        file_put_contents($jsonFile, json_encode($msgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        Notification::create($conn, $userId, 'admin_chat', 'Pesan dari Admin',
            $message, null, null, $_SESSION['user_id']);

        echo json_encode(['ok' => true]);
        break;

    case 'delete':
        $userId = (int)($_POST['user_id'] ?? 0);
        $index = (int)($_POST['index'] ?? -1);
        if ($userId <= 0 || $index < 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid input']);
            break;
        }
        $jsonFile = $chatDir . '/' . $userId . '/isipesan.json';
        if (!is_file($jsonFile)) {
            echo json_encode(['ok' => false, 'error' => 'Chat tidak ditemukan']);
            break;
        }
        $msgs = json_decode(file_get_contents($jsonFile), true);
        if (!is_array($msgs) || !isset($msgs[$index])) {
            echo json_encode(['ok' => false, 'error' => 'Pesan tidak ditemukan']);
            break;
        }
        if ($msgs[$index]['from'] !== 'admin') {
            echo json_encode(['ok' => false, 'error' => 'Hanya bisa hapus pesan admin']);
            break;
        }
        $deletedMsg = $msgs[$index]['message'];
        array_splice($msgs, $index, 1);
        file_put_contents($jsonFile, json_encode($msgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        Notification::deleteByChat($conn, $userId, $deletedMsg, $_SESSION['user_id']);
        echo json_encode(['ok' => true]);
        break;

    case 'users':
        $q = $_GET['q'] ?? '';
        $users = [];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE role NOT IN ('admin', 'guest') AND is_active = 1 AND username LIKE ? ORDER BY username ASC LIMIT 20");
            $stmt->bind_param("s", $like);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $stmt->close();
        }
        echo json_encode(['ok' => true, 'list' => $users]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
