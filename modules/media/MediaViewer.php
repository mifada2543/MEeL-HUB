<?php
class MediaViewer
{
    private \mysqli $conn;
    private ?int $user_id;
    private ?array $user_data = null;
    private string $media_type;
    private int $media_id;
    private string $table;

    public function __construct(\mysqli $db_connection, ?int $session_user_id, string $media_type, int $media_id)
    {
        $this->conn = $db_connection;
        $this->user_id = $session_user_id;
        $this->media_type = $media_type;
        $this->media_id = (int)$media_id;
        $allowed_tables = ['video', 'music'];
        $this->table = ($media_type === 'video') ? 'video' : 'music';
        if (!in_array($this->table, $allowed_tables, true)) {
            throw new InvalidArgumentException('Invalid media type');
        }

        if ($this->user_id) {
            $stmt_user = $this->conn->prepare("SELECT is_active, role FROM users WHERE id = ? LIMIT 1");
            $stmt_user->bind_param("i", $this->user_id);
            $stmt_user->execute();
            $this->user_data = $stmt_user->get_result()->fetch_assoc();
        }
    }

    public function recordView() {
        if (!$this->user_id || !$this->media_id) return false;

        $user = $this->user_data;

        if ($user && $user['is_active'] == 1 && $user['role'] !== 'guest') {
            $log_column = ($this->media_type === 'video') ? 'video_id' : 'music_id';
            $stmt_log = $this->conn->prepare("INSERT IGNORE INTO view_logs (user_id, $log_column) VALUES (?, ?)");
            $stmt_log->bind_param("ii", $this->user_id, $this->media_id);
            $stmt_log->execute();

            if ($stmt_log->affected_rows > 0) {
                $stmt_upd = $this->conn->prepare("UPDATE {$this->table} SET views = views + 1 WHERE id = ?");
                $stmt_upd->bind_param("i", $this->media_id);
                $stmt_upd->execute();
            }
            return true;
        }
        return false;
    }

    public function getMediaData()
    {
        $sql = "SELECT m.*, u.username as uploader, u.profile_picture as uploader_pfp
                FROM {$this->table} m
                JOIN users u ON m.user_id = u.id
                WHERE m.id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->media_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
    }

    public function getUserInteraction()
    {
        if (!$this->user_id) return null;
        $col = ($this->media_type === 'video') ? 'video_id' : 'music_id';
        $stmt = $this->conn->prepare("SELECT type FROM interactions WHERE user_id = ? AND $col = ?");
        $stmt->bind_param("ii", $this->user_id, $this->media_id);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($row = $res->fetch_assoc()) ? $row['type'] : null;
    }

    public function addComment(array $post_data): bool
    {
        if (!$this->user_id || empty(trim($post_data['comments']))) return false;

        if (!$this->user_data || $this->user_data['is_active'] != 1 || $this->user_data['role'] === 'guest') {
            return false;
        }

        $raw = trim($post_data['comments']);
        $parent_id = !empty($post_data['parent_id']) ? (int)$post_data['parent_id'] : null;
        $col = ($this->media_type === 'video') ? 'video_id' : 'music_id';

        $stmt = $this->conn->prepare("INSERT INTO comments ($col, user_id, parent_id, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiis", $this->media_id, $this->user_id, $parent_id, $raw);
        return $stmt->execute();
    }

    public function getComments(int $limit = 200)
    {
        $col = ($this->media_type === 'video') ? 'video_id' : 'music_id';

        $stmt = $this->conn->prepare("SELECT c.*, u.username, u.role FROM comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.$col = ? ORDER BY c.created_at ASC LIMIT ?");
        $stmt->bind_param("ii", $this->media_id, $limit);
        $stmt->execute();
        $raw_comments = $stmt->get_result();

        $grouped = [];
        $user_map = [];
        while ($row = $raw_comments->fetch_assoc()) {
            $p_id = $row['parent_id'] ?? 0;
            $grouped[$p_id][] = $row;
            $user_map[$row['id']] = $row['username'] ?? 'Guest';
        }
        return ['grouped' => $grouped, 'user_map' => $user_map];
    }

    public function getMediaType(): string
    {
        return $this->media_type;
    }

    public function getRecommendations($limit = 10)
    {
        $limit = (int)$limit;
        $table = $this->table;

        $count_res = $this->conn->query("SELECT COUNT(*) AS total FROM {$table}");
        $total = (int)($count_res ? $count_res->fetch_assoc()['total'] : 0);

        if ($total <= 1) {
            return $this->conn->query(
                "SELECT m.*, u.username AS uploader FROM {$table} m
                 JOIN users u ON m.user_id = u.id WHERE 1 = 0"
            );
        }

        $exclude_ids = $_SESSION["seen_{$table}_ids"] ?? [];
        $exclude_ids[] = $this->media_id;

        if ($total < 1000) {
            $id_result = $this->conn->query("SELECT id FROM {$table} WHERE id != {$this->media_id}");
            $all_ids = [];
            if ($id_result) {
                while ($row = $id_result->fetch_assoc()) {
                    $all_ids[] = (int)$row['id'];
                }
            }
            $available = array_values(array_diff($all_ids, $exclude_ids));
            if (empty($available)) {
                $_SESSION["seen_{$table}_ids"] = [];
                $available = array_values(array_diff($all_ids, [$this->media_id]));
            }
            shuffle($available);
            $picked_ids = array_slice($available, 0, $limit);
        } else {
            $sql = "SELECT id FROM {$table} WHERE id != ? ORDER BY RAND() LIMIT ?";
            $extra = min($limit * 2, 40);
            $stmt_ids = $this->conn->prepare($sql);
            $stmt_ids->bind_param("ii", $this->media_id, $extra);
            $stmt_ids->execute();
            $id_result = $stmt_ids->get_result();
            $candidate_ids = [];
            if ($id_result) {
                while ($row = $id_result->fetch_assoc()) {
                    $candidate_ids[] = (int)$row['id'];
                }
            }
            $available = array_values(array_diff($candidate_ids, $exclude_ids));
            if (empty($available)) {
                $_SESSION["seen_{$table}_ids"] = [];
                $available = array_values(array_diff($candidate_ids, [$this->media_id]));
            }
            shuffle($available);
            $picked_ids = array_slice($available, 0, $limit);
        }

        if (empty($picked_ids)) {
            return $this->conn->query(
                "SELECT m.*, u.username AS uploader FROM {$table} m
                 JOIN users u ON m.user_id = u.id WHERE 1 = 0"
            );
        }

        $placeholders = implode(',', array_fill(0, count($picked_ids), '?'));
        $types = str_repeat('i', count($picked_ids));
        $sql = "SELECT m.*, u.username AS uploader
                FROM {$table} m
                JOIN users u ON m.user_id = u.id
                WHERE m.id IN ({$placeholders})
                ORDER BY FIELD(m.id, {$placeholders})";
        $all_params = array_merge($picked_ids, $picked_ids);
        $all_types = $types . $types;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($all_types, ...$all_params);
        $stmt->execute();
        return $stmt->get_result();
    }

    public static function syncViewsFromLogs(\mysqli $conn): int
    {
        $conn->query("UPDATE video v SET views = (SELECT COUNT(DISTINCT user_id) FROM view_logs WHERE video_id = v.id)");
        $conn->query("UPDATE music m SET views = (SELECT COUNT(DISTINCT user_id) FROM view_logs WHERE music_id = m.id)");
        return $conn->affected_rows;
    }

    public static function getViewStats(\mysqli $conn): array
    {
        $video_logs = $conn->query("SELECT COUNT(*) AS c FROM view_logs WHERE video_id IS NOT NULL")->fetch_assoc()['c'];
        $music_logs = $conn->query("SELECT COUNT(*) AS c FROM view_logs WHERE music_id IS NOT NULL")->fetch_assoc()['c'];
        $total_video_views = $conn->query("SELECT COALESCE(SUM(views),0) AS c FROM video")->fetch_assoc()['c'];
        $total_music_views = $conn->query("SELECT COALESCE(SUM(views),0) AS c FROM music")->fetch_assoc()['c'];

        $chart = [];
        $chart_result = $conn->query("SELECT DATE(viewed_at) AS day, COUNT(*) AS views FROM view_logs WHERE viewed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(viewed_at) ORDER BY day ASC");
        if ($chart_result) {
            while ($row = $chart_result->fetch_assoc()) {
                $chart[] = ['date' => $row['day'], 'views' => (int)$row['views']];
            }
        }

        return [
            'video_log_rows' => (int)$video_logs,
            'music_log_rows' => (int)$music_logs,
            'total_log_rows' => (int)$video_logs + (int)$music_logs,
            'video_views_counter' => (int)$total_video_views,
            'music_views_counter' => (int)$total_music_views,
            'chart' => $chart,
        ];
    }

    public function getPlaylistQueue(int $playlist_id): ?array
    {
        if ($this->media_type !== 'music' || !$playlist_id) return null;
        $playlist_id = (int)$playlist_id;

        $stmt_q = $this->conn->prepare("SELECT m.*, pt.added_at FROM music m JOIN playlist_tracks pt ON m.id = pt.music_id WHERE pt.playlist_id = ? ORDER BY pt.added_at DESC, pt.id DESC");
        $stmt_q->bind_param("i", $playlist_id);
        $stmt_q->execute();
        $queue = $stmt_q->get_result();

        $stmt_curr = $this->conn->prepare("SELECT added_at, id FROM playlist_tracks WHERE playlist_id = ? AND music_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_curr->bind_param("ii", $playlist_id, $this->media_id);
        $stmt_curr->execute();
        $current = $stmt_curr->get_result()->fetch_assoc();

        $next_url = "";
        if ($current) {
            $stmt_next = $this->conn->prepare("SELECT music_id FROM playlist_tracks WHERE playlist_id = ? AND (added_at, id) < (?, ?) ORDER BY added_at DESC, id DESC LIMIT 1");
            $stmt_next->bind_param("isi", $playlist_id, $current['added_at'], $current['id']);
            $stmt_next->execute();
            $next_q = $stmt_next->get_result();

            if ($next_d = $next_q->fetch_assoc()) {
                $next_url = "watch.php?v=" . $next_d['music_id'] . "&playlist_id=" . $playlist_id;
            }
        }
        return ['queue' => $queue, 'next_url' => $next_url];
    }
}
