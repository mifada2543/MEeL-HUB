<?php
include '../auth/config.php'; // sudah handle session_start() & $conn

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../update.php");
    exit;
}

$imp = trim($_POST['important']    ?? '');
$ann = trim($_POST['announcement'] ?? '');

$stmt = $conn->prepare("UPDATE sidebar_settings SET important_content = ?, announcement_content = ? WHERE id = 1");
$stmt->bind_param("ss", $imp, $ann);
$stmt->execute();

header("Location: ../update.php");
exit;