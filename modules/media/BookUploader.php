<?php

class BookUploader
{
    private \mysqli $conn;
    private string $base_path;

    public function __construct(\mysqli $db_connection, string $base_path)
    {
        $this->conn = $db_connection;
        $this->base_path = rtrim($base_path, '/');
    }

    public function handleUpload(array $post, array $files): array
    {
        $title = trim($post['title'] ?? '');
        $author = trim($post['author'] ?? 'Tidak diketahui');
        $type = $post['type'] ?? '';
        $category = trim($post['category'] ?? '');
        $user_id = (int)($post['user_id'] ?? 0);

        if (empty($title) || !in_array($type, ['manga', 'pdf'], true)) {
            return ['success' => false, 'message' => 'Error: Data tidak lengkap atau tipe tidak valid.'];
        }

        $thumb_name = $this->handleThumbnail($files['thumbnail'] ?? []);

        $content = ($type === 'pdf')
            ? $this->handlePdf($files['book_file'] ?? [], $title)
            : $this->handleManga($files['book_file'] ?? [], $title);

        if (!$content['success']) {
            return $content;
        }

        if (isset($content['existing']) && $content['existing'] === true) {
            return ['success' => true, 'message' => $content['message']];
        }

        return $this->insertBook($title, $author, $type, $content['has_chapters'], $category, $content['path_result'], $thumb_name, $user_id);
    }

    private function handleThumbnail(array $file): string
    {
        if (
            !empty($file['name']) && !empty($file['tmp_name'])
            && is_uploaded_file($file['tmp_name'])
            && $file['error'] === UPLOAD_ERR_OK
        ) {
            if ((int)($file['size'] ?? 0) > MEEL_MAX_THUMBNAIL_BYTES) {
                return 'default_cover.webp';
            }

            $name = time() . '_' . bin2hex(random_bytes(4)) . '.webp';
            $target_path = $this->base_path . '/upload/thumbnail/' . $name;
            $ffmpeg_bin = defined('MEEL_FFMPEG_PATH') && MEEL_FFMPEG_PATH !== '' ? MEEL_FFMPEG_PATH : resolve_binary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);
            if (meel_ffmpeg_thumbnail_webp($ffmpeg_bin, $file['tmp_name'], $target_path, 500)) {
                return $name;
            }

            if (meel_magic_extension_ok($file['tmp_name'], 'webp', 'image') === '') {
                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    return $name;
                }
            }
        }
        return 'default_cover.webp';
    }

    private function handlePdf(array $file, string $title): array
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => 'Error: Tidak ada file PDF yang diterima.'];
        }
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext !== 'pdf' || preg_match('/\.(php\d?|phtml|sh|js)(\.|$)/i', $file['name'] ?? '') || str_contains($file['name'] ?? '', "\0")) {
            return ['success' => false, 'message' => 'Error: File harus berformat PDF!'];
        }

        if (meel_magic_extension_ok($file['tmp_name'], 'pdf', 'pdf') !== '') {
            return ['success' => false, 'message' => 'Error: File tidak valid sebagai PDF (magic bytes mismatch).'];
        }

        if ((int)($file['size'] ?? 0) > MEEL_MAX_BOOK_FILE_BYTES) {
            return ['success' => false, 'message' => 'Error: File PDF terlalu besar.'];
        }

        $clean = preg_replace('/[^a-zA-Z0-9]/', '_', $title);
        $clean = trim(substr((string)$clean, 0, 120), '_');
        if ($clean === '') {
            $clean = 'book';
        }

        $final = $clean . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.pdf';

        if (!move_uploaded_file($file['tmp_name'], $this->base_path . '/upload/pdf/' . $final)) {
            return ['success' => false, 'message' => 'Error: Gagal memindahkan file PDF!'];
        }

        return ['success' => true, 'has_chapters' => 0, 'path_result' => $final];
    }

    private function handleManga(array $file, string $title): array
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => 'Error: Tidak ada file arsip yang diterima.'];
        }

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['zip', 'cbz'], true) || str_contains($file['name'] ?? '', "\0")) {
            return ['success' => false, 'message' => 'Error: Harap upload file ZIP atau CBZ!'];
        }

        if (meel_magic_extension_ok($file['tmp_name'], $ext, 'archive') !== '') {
            return ['success' => false, 'message' => 'Error: File tidak valid sebagai arsip ZIP.'];
        }

        if ((int)($file['size'] ?? 0) > MEEL_MAX_BOOK_FILE_BYTES) {
            return ['success' => false, 'message' => 'Error: File arsip terlalu besar.'];
        }

        $clean = preg_replace('/[^a-zA-Z0-9]/', '_', $title);
        $manga_folder = $this->base_path . '/upload/manga/' . $clean;
        $has_chapters = 0;

        $check = $this->conn->prepare("SELECT id FROM books WHERE path_folder = ? LIMIT 1");
        $check->bind_param("s", $clean);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;

        require_once __DIR__ . '/ArchiveGuard.php';
        $guard = new ArchiveGuard($this->base_path . '/upload/manga');
        $result = $guard->extractSafe($file['tmp_name'], $manga_folder);

        if (!$result['ok']) {
            return ['success' => false, 'message' => 'Error: ' . $result['error']];
        }

        $first_entry = $this->firstEntryHasSubdir($file['tmp_name']);
        if ($first_entry) {
            $has_chapters = 1;
        }

        if ($exists) {
            $stmt = $this->conn->prepare(
                "UPDATE books SET has_chapters = 1 WHERE path_folder = ?"
            );
            $stmt->bind_param("s", $clean);
            $stmt->execute();
            return [
                'success' => true,
                'existing' => true,
                'message' => 'Success: Chapter tambahan berhasil digabungkan!'
            ];
        }

        return ['success' => true, 'has_chapters' => $has_chapters, 'path_result' => $clean];
    }

    private function firstEntryHasSubdir(string $archivePath): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            return false;
        }
        $first = $zip->getNameIndex(0);
        $zip->close();
        return is_string($first) && str_contains(str_replace('\\', '/', $first), '/');
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

/* reference build: MEeL-C10H12N2O [cd2f94e2f90c8a6f] */