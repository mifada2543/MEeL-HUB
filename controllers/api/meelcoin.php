<?php
define('MEEL_API_CONTEXT', true);
require_once __DIR__ . '/../../modules/core/helpers.php';
meel_boot_session();
include __DIR__ . '/../../auth/config.php';
require_once __DIR__ . '/../../modules/core/MeelCoin.php';
require_once __DIR__ . '/../../modules/auth/helpers/user.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userRole = get_user_role($conn, $userId);
$isOwner = !empty($_GET['user_id']) && (int)$_GET['user_id'] === $userId;

if (!$isOwner) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$enabled = MeelCoin::isEnabled($conn);
$isAdmin = ($userRole === 'admin');

if ($enabled && !$isAdmin) {
    MeelCoin::refill($conn, $userId, $userRole);
}

$summary = $isAdmin
    ? ['total' => -1, 'held' => 0, 'available' => -1]
    : MeelCoin::getBalanceSummary($conn, $userId);
$balance = $isAdmin ? -1 : $summary['total'];
$max = $isAdmin ? -1 : MeelCoin::getMax($conn, $userRole);
$status = MeelCoin::getRefillStatus($conn, $userId, $userRole);
$refillH = MeelCoin::getRefillHours($conn);

echo json_encode([
    'enabled' => $enabled,
    'is_admin' => $isAdmin,
    'balance' => $balance,
    'total' => $balance,
    'held' => $isAdmin ? 0 : $summary['held'],
    'available' => $isAdmin ? -1 : $summary['available'],
    'max' => $max,
    'countdown' => $status['countdown'],
    'refill_hours' => $refillH,
    'cycle_seconds' => $status['cycle_seconds'],
    'server_time' => $status['server_time'],
    'next_refill_at' => $status['next_refill_at'],
]);

/* reference build: MEeL-C5H9NO2 [3d4c077888676f32] */