<?php
// File: auth/MediaLibrary.php

class MediaLibrary {
    private $conn;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    // ── HUB ──────────────────────────────────────────────────────────────────
    public function getCounts(): array {
        $counts = ['music' => 0, 'video' => 0, 'books' => 0];
        // Query ini aman karena tidak ada variabel input dari user
        $sql = "SELECT 'music' AS type, COUNT(*) AS total FROM music
                UNION ALL
                SELECT 'video', COUNT(*) FROM video
                UNION ALL
                SELECT 'books', COUNT(*) FROM books";
        $res = $this->conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $counts[$row['type']] = (int)$row['total'];
            }
        }
        return $counts;
    }

    // ── VIDEO ─────────────────────────────────────────────────────────────────
    public function getVideos(int $limit = 8, int $offset = 0) {
        $stmt = $this->conn->prepare("SELECT * FROM video ORDER BY upload_date DESC LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function countVideos(): int {
        // Aman karena query statis
        $res = $this->conn->query("SELECT COUNT(*) AS total FROM video");
        return (int)$res->fetch_assoc()['total'];
    }

    public function searchVideo(string $q, int $exclude = 0, bool $sidebar = false) {
        if (empty($q)) {
            if ($sidebar) {
                $stmt = $this->conn->prepare(
                    "SELECT v.*, u.username AS uploader_name FROM video v
                     JOIN users u ON v.user_id = u.id
                     WHERE v.id != ? ORDER BY RAND() LIMIT 15"
                );
                $stmt->bind_param("i", $exclude);
            } else {
                $stmt = $this->conn->prepare("SELECT * FROM video ORDER BY upload_date DESC LIMIT 8");
            }
        } else {
            $like = "%$q%";
            $prefix = "$q%";
            $stmt = $this->conn->prepare(
                "SELECT v.*, u.username AS uploader_name,
                 (CASE WHEN v.title LIKE ? THEN 8 WHEN v.search_metadata LIKE ? THEN 5 ELSE 0 END) AS rank
                 FROM video v
                 JOIN users u ON v.user_id = u.id
                 WHERE (v.title LIKE ? OR v.search_metadata LIKE ?) AND v.id != ?
                 ORDER BY rank DESC, v.upload_date DESC LIMIT 20"
            );
            // DIUBAH: Ditambahkan satu placeholder '?' di query asli yang tadinya kurang
            $stmt->bind_param("ssssi", $prefix, $like, $like, $like, $exclude);
        }
        $stmt->execute();
        return $stmt->get_result();
    }

    // ── MUSIC ─────────────────────────────────────────────────────────────────

    // DIUBAH: Fungsi ini sekarang mengirim parameter ke buildMusicWhere untuk prepared statement
    public function getMusicList(string $format = 'all', string $artist = 'all', int $limit = 10, int $offset = 0) {
        $data = $this->buildMusicWhere($format, $artist);
        $stmt = $this->conn->prepare("SELECT * FROM music WHERE {$data['where']} ORDER BY id DESC LIMIT ? OFFSET ?");
        
        // Gabungkan parameter dari buildMusicWhere dengan limit & offset
        $params = array_merge($data['params'], [$limit, $offset]);
        $types = $data['types'] . "ii";
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function countMusic(string $format = 'all', string $artist = 'all'): int {
        $data = $this->buildMusicWhere($format, $artist);
        $stmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM music WHERE {$data['where']}");
        
        if (!empty($data['params'])) {
            $stmt->bind_param($data['types'], ...$data['params']);
        }
        
        $stmt->execute();
        $res = $stmt->get_result();
        return (int)$res->fetch_assoc()['total'];
    }

    public function getArtists() {
        return $this->conn->query("SELECT DISTINCT artist FROM music WHERE artist != '' ORDER BY artist ASC");
    }

    public function getUserPlaylists(int $user_id) {
        $stmt = $this->conn->prepare("SELECT * FROM playlists WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function searchMusic(string $q, int $exclude = 0, bool $sidebar = false) {
        if (empty($q)) {
            if ($sidebar) {
                $stmt = $this->conn->prepare(
                    "SELECT m.*, u.username AS uploader FROM music m
                     JOIN users u ON m.user_id = u.id
                     WHERE m.id != ? ORDER BY RAND() LIMIT 15"
                );
                $stmt->bind_param("i", $exclude);
            } else {
                $stmt = $this->conn->prepare("SELECT * FROM music ORDER BY id DESC LIMIT 10");
            }
        } else {
            $like   = "%$q%";
            $prefix = "$q%";
            $stmt   = $this->conn->prepare(
                "SELECT m.*, u.username AS uploader,
                 (CASE WHEN m.title LIKE ? THEN 10 WHEN m.artist LIKE ? THEN 8 ELSE 0 END) AS rank
                 FROM music m
                 JOIN users u ON m.user_id = u.id
                 WHERE (m.title LIKE ? OR m.artist LIKE ? OR m.search_metadata LIKE ?) AND m.id != ?
                 ORDER BY rank DESC, m.title ASC LIMIT 20"
            );
            $stmt->bind_param("sssssi", $prefix, $prefix, $like, $like, $like, $exclude);
        }
        $stmt->execute();
        return $stmt->get_result();
    }

    // ── PRIVATE HELPER ────────────────────────────────────────────────────────

    // DIUBAH: Sekarang mengembalikan array berisi string WHERE, parameter, dan tipe data
    private function buildMusicWhere(string $format, string $artist): array {
        $allowed_formats = ['mp3', 'ogg', 'm4a', 'opus', 'flac', 'wav'];
        $parts = ["1=1"];
        $params = [];
        $types = "";

        if ($format !== 'all' && in_array($format, $allowed_formats, true)) {
            $parts[] = "filename LIKE ?";
            $params[] = "%.$format";
            $types .= "s";
        }

        if ($artist !== 'all' && !empty($artist)) {
            $parts[] = "artist = ?";
            $params[] = $artist;
            $types .= "s";
        }

        return [
            'where' => implode(' AND ', $parts),
            'params' => $params,
            'types' => $types
        ];
    }
}