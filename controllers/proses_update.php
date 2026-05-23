<?php
include '../auth/config.php'; // sudah handle session_start() & $conn

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../update.php");
    exit;
}

$version = trim($_POST['version'] ?? '');
$content = trim($_POST['content'] ?? '');
$date    = $_POST['created_at'] ?? date('Y-m-d');

if (empty($version) || empty($content)) {
    header("Location: ../update.php");
    exit;
}

$stmt = $conn->prepare("INSERT INTO updates (version, content, created_at) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $version, $content, $date);
$stmt->execute();

header("Location: ../update.php");
exit;