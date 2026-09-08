<?php
define('MEEL_API_CONTEXT', true);
require_once '../../modules/core/helpers.php';
meel_boot_session();
include '../../auth/config.php';
require_once __DIR__ . '/../../modules/core/MeelCoin.php';
require_once __DIR__ . '/../../modules/auth/helpers/user.php';

header('Content-Type: application/json; charset=utf-8');

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

$balance   = $isAdmin ? -1 : MeelCoin::getBalance($conn, $userId);
$max       = $isAdmin ? -1 : MeelCoin::getMax($conn, $userRole);
$countdown = $isAdmin ? 0 : MeelCoin::getRefillCountdown($conn, $userId, $userRole);
$refillH   = MeelCoin::getRefillHours($conn);

echo json_encode([
    'enabled'     => $enabled,
    'is_admin'    => $isAdmin,
    'balance'     => $balance,
    'max'         => $max,
    'countdown'   => $countdown,
    'refill_hours' => $refillH,
]);
