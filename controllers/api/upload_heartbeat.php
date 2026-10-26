<?php
define('MEEL_API_CONTEXT', true);
require_once __DIR__ . '/../../modules/core/helpers.php';
meel_boot_session();
include __DIR__ . '/../../auth/config.php';
require_once __DIR__ . '/../../modules/core/MeelCoin.php';
require_once __DIR__ . '/../../modules/core/UploadAttempt.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function meel_heartbeat_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    meel_heartbeat_json(405, ['ok' => false, 'status' => 'method_not_allowed', 'error' => 'Gunakan POST.']);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    meel_heartbeat_json(401, ['ok' => false, 'status' => 'unauthorized', 'error' => 'Sesi tidak valid.']);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    meel_heartbeat_json(403, ['ok' => false, 'status' => 'csrf', 'error' => 'CSRF token tidak valid.']);
}

$token = trim((string)($_POST['upload_attempt'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    meel_heartbeat_json(400, ['ok' => false, 'status' => 'invalid_token', 'error' => 'Token upload tidak valid.']);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (!MeelCoin::isEligibleUser($conn, $userId)) {
    meel_heartbeat_json(403, ['ok' => false, 'status' => 'ineligible', 'error' => 'Akun tidak aktif atau guest.']);
}

$abort = isset($_POST['upload_abort']) && (string)$_POST['upload_abort'] === '1';
if ($abort) {
    $attempt = UploadAttempt::findActiveByToken($conn, $token, $userId);
    if ($attempt === null) {
        meel_heartbeat_json(404, [
            'ok' => false,
            'status' => 'not_found',
            'error' => 'Sesi upload tidak ditemukan atau sudah selesai.',
        ]);
    }
    if (!UploadAttempt::canAbortFromClient($attempt)) {
        $summary = MeelCoin::getBalanceSummary($conn, $userId);
        meel_heartbeat_json(200, [
            'ok' => true,
            'status' => 'active',
            'abort' => 'ignored',
            'held' => $summary['held'],
            'available' => $summary['available'],
            'balance' => $summary['total'],
            'server_time' => date('c'),
        ]);
    }
    $failed = UploadAttempt::fail($conn, (int)$attempt['id'], 'client_aborted');
    $summary = MeelCoin::getBalanceSummary($conn, $userId);
    meel_heartbeat_json(200, [
        'ok' => true,
        'status' => !empty($failed['captured']) ? 'completed' : 'aborted',
        'released' => (int)($failed['released'] ?? 0),
        'held' => $summary['held'],
        'available' => $summary['available'],
        'balance' => $summary['total'],
        'server_time' => date('c'),
    ]);
}

$touch = UploadAttempt::touchByToken($conn, $token, $userId);
if (!$touch['ok']) {
    meel_heartbeat_json(404, [
        'ok' => false,
        'status' => $touch['status'] !== '' ? $touch['status'] : 'not_found',
        'error' => $touch['error'],
    ]);
}

$summary = MeelCoin::getBalanceSummary($conn, $userId);
meel_heartbeat_json(200, [
    'ok' => true,
    'status' => 'active',
    'held' => $summary['held'],
    'available' => $summary['available'],
    'balance' => $summary['total'],
    'hold_id' => (int)($touch['hold_id'] ?? 0),
    'server_time' => date('c'),
]);

/* reference build: MEeL-C3H7NO2S [ffd332a] */