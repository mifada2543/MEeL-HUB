<?php
// File: auth/MediaLibrary.php

class MediaLibrary
{
    private $conn;

    public function __construct($db_connection)
    {
        $this->conn = $db_connection;
    }

    // ── HUB ──────────────────────────────────────────────────────────────────
    public function getCounts(): array
    {
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
    public function getVideos(int $limit = 15, int $offset = 0)
    {
        $stmt = $this->conn->prepare("SELECT * FROM video ORDER BY upload_date DESC LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function countVideos(): int
    {
        // Aman karena query statis
        $res = $this->conn->query("SELECT COUNT(*) AS total FROM video");
        return (int)$res->fetch_assoc()['total'];
    }

    public function searchVideo(string $q, int $exclude = 0, bool $sidebar = false, int $offset = 0)
    {
        $limit = 20; // selaraskan dengan $limit di search_video.php

        if (empty($q)) {
            if ($sidebar) {
                $stmt = $this->conn->prepare(
                    "SELECT v.*, u.username AS uploader_name FROM video v
                     JOIN users u ON v.user_id = u.id
                     WHERE v.id != ? ORDER BY RAND() LIMIT 15"
                );
                $stmt->bind_param("i", $exclude);
            } else {
                $stmt = $this->conn->prepare("SELECT * FROM video WHERE id != ? ORDER BY upload_date DESC LIMIT ? OFFSET ?");
                $stmt->bind_param("iii", $exclude, $limit, $offset);
            }
        } else {
            $like = "%$q%";
            $prefix = "$q%";
            $stmt = $this->conn->prepare(
                "SELECT v.*, u.username AS uploader_name,
                 (CASE WHEN v.title LIKE ? THEN 15 WHEN v.search_metadata LIKE ? THEN 5 ELSE 0 END) AS rank
                 FROM video v
                 JOIN users u ON v.user_id = u.id
                 WHERE (v.title LIKE ? OR v.search_metadata LIKE ?) AND v.id != ?
                 ORDER BY rank DESC, v.upload_date DESC LIMIT ? OFFSET ?"
            );
            // DIUBAH: Ditambahkan placeholder limit & offset untuk paginasi/load-more
            $stmt->bind_param("ssssiii", $prefix, $like, $like, $like, $exclude, $limit, $offset);
        }
        $stmt->execute();
        return $stmt->get_result();
    }

    // ── MUSIC ─────────────────────────────────────────────────────────────────

    // DIUBAH: Fungsi ini sekarang mengirim parameter ke buildMusicWhere untuk prepared statement
    public function getMusicList(string $format = 'all', string $artist = 'all', int $limit = 10, int $offset = 0)
    {
        $data = $this->buildMusicWhere($format, $artist);
        $stmt = $this->conn->prepare("SELECT * FROM music WHERE {$data['where']} ORDER BY id DESC LIMIT ? OFFSET ?");

        // Gabungkan parameter dari buildMusicWhere dengan limit & offset
        $params = array_merge($data['params'], [$limit, $offset]);
        $types = $data['types'] . "ii";

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function countMusic(string $format = 'all', string $artist = 'all'): int
    {
        $data = $this->buildMusicWhere($format, $artist);
        $stmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM music WHERE {$data['where']}");

        if (!empty($data['params'])) {
            $stmt->bind_param($data['types'], ...$data['params']);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        return (int)$res->fetch_assoc()['total'];
    }

    public function getArtists()
    {
        return $this->conn->query("SELECT DISTINCT artist FROM music WHERE artist != '' ORDER BY artist ASC");
    }

    public function getUserPlaylists(int $user_id)
    {
        $stmt = $this->conn->prepare("SELECT * FROM playlists WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function searchMusic(string $q, int $exclude = 0, bool $sidebar = false)
    {
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
                 (CASE WHEN m.title LIKE ? THEN 10 WHEN m.artist LIKE ? THEN 15 ELSE 0 END) AS rank
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
    private function buildMusicWhere(string $format, string $artist): array
    {
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

// ═══════════════════════════════════════════════════════════════════════════════
// BookRepository — Query layer untuk tabel `books`
// ═══════════════════════════════════════════════════════════════════════════════
class BookRepository
{
    private $conn;

    public function __construct($db_connection)
    {
        $this->conn = $db_connection;
    }

    /**
     * Ambil semua buku, atau filter berdasarkan tipe ('manga' / 'pdf' / 'all').
     */
    public function getBooks(string $filter = 'all')
    {
        $allowed = ['manga', 'pdf'];

        if (in_array($filter, $allowed, true)) {
            $stmt = $this->conn->prepare(
                "SELECT * FROM books WHERE type = ? ORDER BY upload_date DESC"
            );
            $stmt->bind_param("s", $filter);
        } else {
            // 'all' — query statis, tidak ada input user
            $stmt = $this->conn->prepare(
                "SELECT * FROM books ORDER BY upload_date DESC"
            );
        }

        $stmt->execute();
        return $stmt->get_result();
    }

    /**
     * Ambil satu buku berdasarkan ID.
     * Return array buku, atau null jika tidak ditemukan.
     */
    public function getBookById(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM books WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
    }

    /**
     * Ambil role user berdasarkan ID.
     * Return string role ('admin' / 'member'), atau null jika user tidak ada.
     */
    public function getUserRole(int $user_id): ?string
    {
        $stmt = $this->conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        return $row ? $row['role'] : null;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// BookUploader — Menangani validasi, file handling, dan insert DB untuk buku
// ═══════════════════════════════════════════════════════════════════════════════
class BookUploader
{
    private $conn;
    private $base_path; // Absolute / relative base path ke direktori upload books

    // $base_path: path ke folder books/ (misal: __DIR__ . '/../books')
    public function __construct($db_connection, string $base_path)
    {
        $this->conn = $db_connection;
        $this->base_path = rtrim($base_path, '/');
    }

    /**
     * Entry point upload buku.
     * Return array ['success' => bool, 'message' => string]
     */
    public function handleUpload(array $post, array $files): array
    {
        $title    = trim($post['title'] ?? '');
        $author   = trim($post['author'] ?? 'Unknown');
        $type     = $post['type'] ?? '';
        $category = trim($post['category'] ?? '');
        $user_id  = (int)($post['user_id'] ?? 0);

        if (empty($title) || !in_array($type, ['manga', 'pdf'], true)) {
            return ['success' => false, 'message' => 'Error: Data tidak lengkap atau tipe tidak valid.'];
        }

        // 1. Thumbnail
        $thumb_name = $this->handleThumbnail($files['thumbnail'] ?? []);

        // 2. File konten
        $content = ($type === 'pdf')
            ? $this->handlePdf($files['book_file'] ?? [], $title)
            : $this->handleManga($files['book_file'] ?? [], $title);

        if (!$content['success']) {
            return $content; // Kembalikan pesan error dari handler
        }

        // 3. Jika manga sudah ada di DB (re-upload chapter), tidak perlu insert ulang
        if (isset($content['existing']) && $content['existing'] === true) {
            return ['success' => true, 'message' => $content['message']];
        }

        // 4. Insert ke database
        return $this->insertBook($title, $author, $type, $content['has_chapters'], $category, $content['path_result'], $thumb_name, $user_id);
    }

    // ── PRIVATE HELPERS ──────────────────────────────────────────────────────

    private function handleThumbnail(array $file): string
    {
        if (!empty($file['name'])) {
            $name = time() . '_' . bin2hex(random_bytes(4)) . '.webp';
            $target_path = $this->base_path . '/upload/thumbnail/' . $name;
            // Konversi ke WebP — lebih kecil dari JPG/PNG asli
            $cmd = "/usr/bin/ffmpeg -y -i " . escapeshellarg($file['tmp_name'])
                . " -vf \"scale='min(500,iw)':-1\" -c:v libwebp -q:v 78 "
                . escapeshellarg($target_path) . " 2>&1";
            exec($cmd, $out, $ret);
            if ($ret === 0 && file_exists($target_path) && filesize($target_path) > 0) {
                return $name;
            }
            // Fallback: simpan file asli jika ffmpeg gagal
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                return $name;
            }
        }
        return 'default_cover.webp';
    }

    private function handlePdf(array $file, string $title): array
    {
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            return ['success' => false, 'message' => 'Error: File harus berformat PDF!'];
        }

        $clean = preg_replace('/[^a-zA-Z0-9]/', '_', $title);
        $final = $clean . '_' . time() . '.pdf';

        if (!move_uploaded_file($file['tmp_name'], $this->base_path . '/upload/pdf/' . $final)) {
            return ['success' => false, 'message' => 'Error: Gagal memindahkan file PDF!'];
        }

        return ['success' => true, 'has_chapters' => 0, 'path_result' => $final];
    }

    private function handleManga(array $file, string $title): array
    {
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['zip', 'cbz'], true)) {
            return ['success' => false, 'message' => 'Error: Harap upload file ZIP atau CBZ!'];
        }

        $clean        = preg_replace('/[^a-zA-Z0-9]/', '_', $title);
        $manga_folder = $this->base_path . '/upload/manga/' . $clean;
        $has_chapters = 0;

        // Cek apakah entry sudah ada di DB (re-upload / tambah chapter)
        $check = $this->conn->prepare("SELECT id FROM books WHERE path_folder = ? LIMIT 1");
        $check->bind_param("s", $clean);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;

        if (!is_dir($manga_folder)) {
            mkdir($manga_folder, 0777, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            return ['success' => false, 'message' => 'Error: Gagal membuka file ZIP!'];
        }

        $zip->extractTo($manga_folder);
        $first_entry = $zip->getNameIndex(0);

        // Jika entry pertama mengandung '/', berarti ada subfolder (chapter)
        if (strpos($first_entry, '/') !== false) {
            $has_chapters = 1;
        }
        $zip->close();

        // Manga sudah ada → hanya update flag has_chapters, tidak insert ulang
        if ($exists) {
            $stmt = $this->conn->prepare(
                "UPDATE books SET has_chapters = 1 WHERE path_folder = ?"
            );
            $stmt->bind_param("s", $clean);
            $stmt->execute();
            return [
                'success'  => true,
                'existing' => true,
                'message'  => 'Success: Chapter tambahan berhasil digabungkan!'
            ];
        }

        return ['success' => true, 'has_chapters' => $has_chapters, 'path_result' => $clean];
    }

    private function insertBook(string $title, string $author, string $type, int $has_chapters, string $category, string $path_folder, string $thumbnail, int $user_id): array
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO books (title, author, type, has_chapters, category, path_folder, thumbnail, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sssisssi", $title, $author, $type, $has_chapters, $category, $path_folder, $thumbnail, $user_id);

        if ($stmt->execute()) {
            $label = ($type === 'manga') ? 'Manga' : 'Buku';
            return ['success' => true, 'message' => "Success: $label berhasil ditambahkan!"];
        }

        return ['success' => false, 'message' => 'Error: Gagal menyimpan ke database.'];
    }
}
