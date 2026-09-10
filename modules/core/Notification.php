<?php
class Notification
{
    public static function create(\mysqli $conn, int $userId, string $type, string $title, string $message, ?int $relatedId = null, ?string $relatedSlug = null, ?int $actorUserId = null): void
    {
        $stmt = $conn->prepare("INSERT INTO user_notifications (user_id, type, title, message, related_id, related_slug, actor_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssisi", $userId, $type, $title, $message, $relatedId, $relatedSlug, $actorUserId);
        $stmt->execute();
        $stmt->close();
    }

    public static function getUnreadCount(\mysqli $conn, int $userId): int
    {
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM user_notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['cnt'] ?? 0);
    }

    public static function getList(\mysqli $conn, int $userId, int $limit = 20, ?string $type = null): array
    {
        if ($type) {
            $stmt = $conn->prepare("SELECT id, type, title, message, is_read, related_id, related_slug, actor_user_id, created_at FROM user_notifications WHERE user_id = ? AND type = ? ORDER BY created_at DESC LIMIT ?");
            $stmt->bind_param("isi", $userId, $type, $limit);
        } else {
            $stmt = $conn->prepare("SELECT id, type, title, message, is_read, related_id, related_slug, actor_user_id, created_at FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
            $stmt->bind_param("ii", $userId, $limit);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $list = [];
        while ($row = $result->fetch_assoc()) {
            $list[] = $row;
        }
        $stmt->close();
        return $list;
    }

    public static function markRead(\mysqli $conn, int $notifId, int $userId): bool
    {
        $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $notifId, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public static function markAllRead(\mysqli $conn, int $userId): bool
    {
        $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public static function deleteByChat(\mysqli $conn, int $userId, string $message, int $actorUserId): bool
    {
        $stmt = $conn->prepare("DELETE FROM user_notifications WHERE user_id = ? AND type = 'admin_chat' AND message = ? AND actor_user_id = ?");
        $stmt->bind_param("isi", $userId, $message, $actorUserId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public static function deleteOne(\mysqli $conn, int $notifId, int $userId): bool
    {
        $stmt = $conn->prepare("DELETE FROM user_notifications WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $notifId, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public static function deleteAllByUser(\mysqli $conn, int $userId): bool
    {
        $stmt = $conn->prepare("DELETE FROM user_notifications WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
