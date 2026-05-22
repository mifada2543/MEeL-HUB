<?php
include 'auth/config.php';
include 'auth/MediaInteraction.php';

// Get comment ID
$comment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

error_log("DELETE_COMMENT.PHP - ID: $comment_id");

// Gunakan MediaInteraction class
$interaction = new MediaInteraction($conn, $_SESSION['user_id'] ?? null);
$result = $interaction->deleteComment($comment_id);

// Log result
error_log("DELETE_COMMENT.PHP - Result: " . json_encode($result));

// Handle response
if (!$result['success']) {
    error_log("DELETE_COMMENT - ERROR: {$result['message']}");
    $_SESSION['error'] = $result['message'];
} else {
    $_SESSION['success'] = $result['message'];
}

// Redirect back
header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
exit;
