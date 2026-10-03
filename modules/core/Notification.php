<?php
class Notification
{
    private const COALESCE_MINUTES = ['like' => 60];

    private const PRUNE_KEEP = 50;

    private const PRUNE_MAX_AGE_DAYS = 15;

    public static function create(\mysqli $conn, int $userId, string $type, string $title, string $message, ?int $relatedId = null, ?string $relatedSlug = null, ?int $actorUserId = null): void
    {
        if (self::isCoalesced($conn, $userId, $type)) {
            return;
        }

        $stmt = $conn->prepare("INSERT INTO user_notifications (user_id, type, title, message, related_id, related_slug, actor_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssisi", $userId, $type, $title, $message, $relatedId, $relatedSlug, $actorUserId);
        $stmt->execute();
        $stmt->close();
    }

    private static function isCoalesced(\mysqli $conn, int $userId, string $type): bool
    {
        $minutes = self::COALESCE_MINUTES[$type] ?? null;
        if ($minutes === null) {
            return false;
        }

        $window = date('Y-m-d H:i:s', time() - ($minutes * 60));
        $stmt = $conn->prepare(
            "SELECT id FROM user_notifications
              WHERE user_id = ? AND type = ? AND created_at >= ?
              LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("iss", $userId, $type, $window);
        $stmt->execute();
        $hit = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (bool)$hit;
    }

    public static function pruneUser(\mysqli $conn, int $userId, int $keep = self::PRUNE_KEEP, int $maxAgeDays = self::PRUNE_MAX_AGE_DAYS): void
    {
        $keep = max(1, $keep);
        $maxAgeDays = max(1, $maxAgeDays);

        $stmt = $conn->prepare(
            "DELETE FROM user_notifications
              WHERE user_id = ?
                AND (
                    created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                    OR id NOT IN (
                        SELECT id FROM (
                            SELECT id FROM user_notifications
                             WHERE user_id = ?
                             ORDER BY created_at DESC, id DESC
                             LIMIT ?
                        ) AS keep_rows
                    )
                )"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("iiii", $userId, $maxAgeDays, $userId, $keep);
        $stmt->execute();
        $stmt->close();
    }

    public static function pruneAll(\mysqli $conn, int $keep = self::PRUNE_KEEP, int $maxAgeDays = self::PRUNE_MAX_AGE_DAYS): int
    {
        $keep = max(1, $keep);
        $maxAgeDays = max(1, $maxAgeDays);

        $res = $conn->query("SELECT DISTINCT user_id FROM user_notifications");
        $userIds = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $userIds[] = (int)$row['user_id'];
            }
            $res->free();
        }

        foreach ($userIds as $uid) {
            self::pruneUser($conn, $uid, $keep, $maxAgeDays);
        }

        return count($userIds);
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

/* reference build: MEeL-C5H9NO2 [96c3a8f9a49fb533] */
