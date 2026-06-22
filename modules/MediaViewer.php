<?php
class MediaViewer
{
    private $conn;
    private $user_id;
    private $user_role; // Ditambahkan untuk efisiensi
    private $is_user_active; // Ditambahkan untuk efisiensi
    private $media_type;
    private $media_id;
    private $table;
    private $media_col; // Menyimpan nama kolom id media agar DRY

    // OPTIMASI: Oper data user langsung dari Session/Auth handler agar tidak query berulang
    public function __construct($db_connection, $session_user, $media_type, $media_id)
    {
        $this->conn = $db_connection;

        // Asumsi $session_user adalah array berisi data user dari session login
        $this->user_id = $session_user['id'] ?? null;
        $this->user_role = $session_user['role'] ?? 'guest';
        $this->is_user_active = $session_user['is_active'] ?? 0;

        $this->media_type = $media_type;
        $this->media_id = (int)$media_id;

        $this->table = ($media_type === 'video') ? 'video' : 'music';
        $this->media_col = ($media_type === 'video') ? 'video_id' : 'music_id';
    }

    // --- 1. LOGIKA VIEWS (Lebih Ringan & Tanpa Query User) ---
    public function recordView()
    {
        // Validasi instan tanpa sentuh database
        if (!$this->user_id || !$this->media_id || !$this->is_user_active || $this->user_role === 'guest') {
            return false;
        }

        // Jalankan INSERT LOG
        $stmt_log = $this->conn->prepare("INSERT IGNORE INTO view_logs (user_id, {$this->media_col}) VALUES (?, ?)");
        $stmt_log->bind_param("ii", $this->user_id, $this->media_id);
        $stmt_log->execute();

        // Hanya update views jika baris baru benar-benar masuk (bukan duplikat)
        if ($stmt_log->affected_rows > 0) {
            $stmt_upd = $this->conn->prepare("UPDATE {$this->table} SET views = views + 1 WHERE id = ?");
            $stmt_upd->bind_param("i", $this->media_id);
            $stmt_upd->execute();
            return true;
        }

        return false;
    }

    // --- 2. AMBIL DATA MEDIA UTAMA ---
    public function getMediaData()
    {
        $sql = "SELECT m.*, u.username as uploader, u.profile_picture as uploader_pfp 
                FROM {$this->table} m 
                JOIN users u ON m.user_id = u.id 
                WHERE m.id = ? LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->media_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?: null;
    }

    // --- 3. LOGIKA INTERAKSI ---
    public function getUserInteraction()
    {
        if (!$this->user_id) return null;

        $stmt = $this->conn->prepare("SELECT type FROM interactions WHERE user_id = ? AND {$this->media_col} = ?");
        $stmt->bind_param("ii", $this->user_id, $this->media_id);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($row = $res->fetch_assoc()) ? $row['type'] : null;
    }

    // --- 4. MANAJEMEN KOMENTAR (Bebas Query User Tambahan) ---
    public function addComment($post_data)
    {
        // Validasi instan dari property class
        if (!$this->user_id || !$this->is_user_active || $this->user_role === 'guest' || empty(trim($post_data['comments']))) {
            return false;
        }

        $raw = trim($post_data['comments']);
        $parent_id = !empty($post_data['parent_id']) ? (int)$post_data['parent_id'] : null;

        $stmt = $this->conn->prepare("INSERT INTO comments ({$this->media_col}, user_id, parent_id, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiis", $this->media_id, $this->user_id, $parent_id, $raw);
        return $stmt->execute();
    }

    public function getComments()
    {
        $col = ($this->media_type === 'video') ? 'video_id' : 'music_id';
        $stmt = $this->conn->prepare("SELECT c.*, u.username FROM comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.$col = ? ORDER BY c.created_at ASC");
        $stmt->bind_param("i", $this->media_id);
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

    // --- 5. REKOMENDASI ---
    public function getRecommendations($limit = 10)
    {
        // DIUBAH: Menggunakan prepared statement untuk menghindari manipulasi limit & id
        $limit = (int)$limit;
        $stmt = $this->conn->prepare("SELECT m.*, u.username as uploader 
                                      FROM {$this->table} m 
                                      JOIN users u ON m.user_id = u.id 
                                      WHERE m.id != ? 
                                      ORDER BY RAND() LIMIT ?");
        $stmt->bind_param("ii", $this->media_id, $limit);
        $stmt->execute();
        return $stmt->get_result();
    }

    // --- 6. KHUSUS MUSIC: PLAYLIST QUEUE ---
    public function getPlaylistQueue($playlist_id)
    {
        if ($this->media_type !== 'music' || !$playlist_id) return null;
        $playlist_id = (int)$playlist_id;

        // DIUBAH: Semua query di bawah menggunakan Prepared Statements
        // 1. Ambil list antrean
        $stmt_q = $this->conn->prepare("SELECT m.*, pt.added_at FROM music m JOIN playlist_tracks pt ON m.id = pt.music_id WHERE pt.playlist_id = ? ORDER BY pt.added_at DESC");
        $stmt_q->bind_param("i", $playlist_id);
        $stmt_q->execute();
        $queue = $stmt_q->get_result();

        // 2. Ambil waktu track saat ini
        $stmt_curr = $this->conn->prepare("SELECT added_at FROM playlist_tracks WHERE playlist_id = ? AND music_id = ? LIMIT 1");
        $stmt_curr->bind_param("ii", $playlist_id, $this->media_id);
        $stmt_curr->execute();
        $current = $stmt_curr->get_result()->fetch_assoc();

        $next_url = "";
        if ($current) {
            // 3. Cari lagu berikutnya
            $stmt_next = $this->conn->prepare("SELECT music_id FROM playlist_tracks WHERE playlist_id = ? AND added_at < ? ORDER BY added_at DESC LIMIT 1");
            $stmt_next->bind_param("is", $playlist_id, $current['added_at']);
            $stmt_next->execute();
            $next_q = $stmt_next->get_result();

            if ($next_d = $next_q->fetch_assoc()) {
                $next_url = "watch.php?id=" . $next_d['music_id'] . "&playlist_id=" . $playlist_id;
            }
        }
        return ['queue' => $queue, 'next_url' => $next_url];
    }
}