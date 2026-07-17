<?php
/**
 * MEeL-HUB — Database Migration System
 * 
 * Simple versioned migration tanpa library eksternal.
 * Jalankan: php database/migrate.php
 * Atau include dari halaman admin.
 * 
 * Cara menambah migrasi baru:
 *   1. Tambah entry baru di array $migrations dengan key versi berikutnya
 *   2. Jalankan ulang migrate.php
 */

// ─── Bootstrap ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../auth/config.php';

// Validasi koneksi database
if (!isset($conn) || !$conn instanceof \mysqli || $conn->connect_error) {
    echo "[MEeL] ❌ Gagal terhubung ke database. Periksa auth/config.php.\n";
    exit(1);
}

// ─── Migration Registry ──────────────────────────────────────────────────────
// Key = versi integer, Value = SQL query(s)
// Hanya tambah di AKHIR array, jangan pernah mengubah migrasi yang sudah ada!
$migrations = [
    1 => [
        'description' => 'Tambah FULLTEXT index untuk pencarian',
        'sql' => [
            function($conn) {
                $conn->query("ALTER TABLE video ADD FULLTEXT INDEX ft_video_search (title, search_metadata)");
            },
            function($conn) {
                $conn->query("ALTER TABLE music ADD FULLTEXT INDEX ft_music_search (title, artist, search_metadata)");
            },
            function($conn) {
                $conn->query("ALTER TABLE books ADD FULLTEXT INDEX ft_books_search (title, author)");
            },
        ],
    ],
    2 => [
        'description' => 'Tambah index pada kolom upload_date',
        'sql' => [
            function($conn) {
                $conn->query("ALTER TABLE video ADD INDEX idx_video_upload_date (upload_date)");
            },
            function($conn) {
                $conn->query("ALTER TABLE music ADD INDEX idx_music_upload_date (upload_date)");
            },
            function($conn) {
                $conn->query("ALTER TABLE books ADD INDEX idx_books_upload_date (upload_date)");
            },
            function($conn) {
                $conn->query("ALTER TABLE drive_files ADD INDEX idx_drive_upload_date (upload_date)");
            },
        ],
    ],
    3 => [
        'description' => 'Catatan: db_version dibuat otomatis oleh runner',
        'sql' => [], // runner sudah buat db_version otomatis
    ],
    4 => [
        'description' => 'Tambah FK constraint untuk tabel tanpa referensi',
        'sql' => [
            function($conn) {
                // Bersihkan orphaned rows dulu sebelum tambah FK — cegah error "cannot add foreign key constraint"
                $conn->query("DELETE FROM upload_queue WHERE user_id NOT IN (SELECT id FROM users)");
                $result = $conn->query("ALTER TABLE upload_queue ADD CONSTRAINT fk_upload_queue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
                        echo "[MEeL] ⚠ Warning: {$err}\n";
                    }
                }
            },
            function($conn) {
                $conn->query("DELETE FROM transcode_queue WHERE user_id NOT IN (SELECT id FROM users)");
                $result = $conn->query("ALTER TABLE transcode_queue ADD CONSTRAINT fk_transcode_queue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
                        echo "[MEeL] ⚠ Warning: {$err}\n";
                    }
                }
            },
            function($conn) {
                $conn->query("DELETE FROM drive_files WHERE user_id NOT IN (SELECT id FROM users)");
                $result = $conn->query("ALTER TABLE drive_files ADD CONSTRAINT fk_drive_files_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
                        echo "[MEeL] ⚠ Warning: {$err}\n";
                    }
                }
            },
        ],
    ],
    5 => [
        'description' => 'Ubah kolom title dari varchar(255) ke TEXT — cegah silent truncation title panjang',
        'sql' => [
            function($conn) {
                $result = $conn->query("ALTER TABLE video MODIFY COLUMN title TEXT NOT NULL");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (video.title): {$err}\n";
                    }
                }
            },
            function($conn) {
                $result = $conn->query("ALTER TABLE music MODIFY COLUMN title TEXT NOT NULL");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (music.title): {$err}\n";
                    }
                }
            },
            function($conn) {
                $result = $conn->query("ALTER TABLE books MODIFY COLUMN title TEXT NOT NULL");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (books.title): {$err}\n";
                    }
                }
            },
        ],
    ],
];

// ─── Runner ───────────────────────────────────────────────────────────────────

// Pastikan tabel db_version ada
$conn->query("CREATE TABLE IF NOT EXISTS db_version (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version INT NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Ambil versi terakhir yang sudah dijalankan
$result = $conn->query("SELECT MAX(version) AS current_version FROM db_version");
$row = $result->fetch_assoc();
$current_version = (int)($row['current_version'] ?? 0);

$new_migrations = 0;

foreach ($migrations as $version => $migration) {
    if ($version > $current_version) {
        echo "[MEeL] Menjalankan migrasi v{$version}: {$migration['description']}...\n";
        
        foreach ($migration['sql'] as $migration_step) {
            if (is_callable($migration_step)) {
                // Closure-based migration — try/catch untuk kompatibilitas luas
                try {
                    $migration_step($conn);
                    $err = $conn->error;
                    if ($err && !str_contains($err, 'Duplicate key name') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning: {$err}\n";
                    }
                } catch (\Throwable $e) {
                    echo "[MEeL] ⚠ Warning: " . $e->getMessage() . "\n";
                }
            } else {
                // Raw SQL string
                $sql = $migration_step;
                try {
                    if ($conn->query($sql) === false) {
                        $err = $conn->error;
                        if ($err && !str_contains($err, 'Duplicate key name') && !str_contains($err, 'already exists')) {
                            echo "[MEeL] ⚠ Warning: {$err}\n";
                        }
                    }
                } catch (\Throwable $e) {
                    echo "[MEeL] ⚠ Warning: " . $e->getMessage() . "\n";
                }
            }
        }
        
        // Catat migrasi sukses
        $stmt = $conn->prepare("INSERT INTO db_version (version) VALUES (?)");
        $stmt->bind_param("i", $version);
        $stmt->execute();
        $stmt->close();
        
        $new_migrations++;
        echo "[MEeL] ✓ Migrasi v{$version} selesai.\n";
    }
}

if ($new_migrations === 0) {
    echo "[MEeL] Database sudah up-to-date (versi {$current_version}). Tidak ada migrasi baru.\n";
} else {
    echo "[MEeL] ✓ {$new_migrations} migrasi berhasil dijalankan. Versi sekarang: " . ($current_version + $new_migrations) . "\n";
}

$conn->close();
