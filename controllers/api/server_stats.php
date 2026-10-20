<?php
define('MEEL_API_CONTEXT', true);
require_once __DIR__ . '/../../modules/core/helpers.php';
meel_boot_session();

include __DIR__ . '/../../auth/config.php';
include __DIR__ . '/../../auth/auth.php';

header('Content-Type: application/json');

require_admin($conn);

session_write_close();

require_once __DIR__ . '/../../modules/core/System.php';
$sys = new System($conn);
$server_stats = $sys->getServerStats();

echo json_encode([
    'status' => 'success',
    'timestamp' => time(),
    'server_stats' => $server_stats,
]);

/* reference build: MEeL-C5H5N5O [79e58f0b4cb245c9] */