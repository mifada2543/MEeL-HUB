<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Access denied. Jalankan dari terminal: php arcade/migrate.php');
}

require_once __DIR__ . '/../auth/config.php';

if (!isset($conn) || !$conn instanceof \mysqli || $conn->connect_error) {
    echo "[MEeL Arcade] ❌ Gagal terhubung ke database. Periksa auth/config.php.\n";
    exit(1);
}

function meel_arc_mig_has_column(\mysqli $conn, string $table, string $col): bool
{
    $sql = "SHOW COLUMNS FROM `" . $conn->real_escape_string($table)
         . "` LIKE '" . $conn->real_escape_string($col) . "'";
    $r = $conn->query($sql);
    return $r && $r->num_rows > 0;
}

function meel_arc_mig_has_index(\mysqli $conn, string $table, string $index): bool
{
    $sql = "SHOW INDEX FROM `" . $conn->real_escape_string($table)
         . "` WHERE Key_name = '" . $conn->real_escape_string($index) . "'";
    $r = $conn->query($sql);
    return $r && $r->num_rows > 0;
}

// =============================================================================
// Migrasi Arcade
// =============================================================================
// Setiap migrasi adalah array berisi:
//   'description' => deskripsi singkat
//   'sql'         => array of closure($conn) atau SQL string
//
// Semua step bersifat idempotent (aman dijalankan berulang kali).
// =============================================================================

$migrations = [
    1 => [
        'description' => 'Schema awal arcade — rooms (chess), moves (chess), arcade_song (rhythm), arcade_score (rhythm)',
        'sql' => [
            function ($conn) {
                $conn->query("CREATE TABLE IF NOT EXISTS `rooms` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `room_code` varchar(10) NOT NULL,
                    `white_user_id` int(11) DEFAULT NULL,
                    `black_user_id` int(11) DEFAULT NULL,
                    `black_joined` tinyint(1) NOT NULL DEFAULT 0,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `room_code` (`room_code`),
                    KEY `idx_rooms_white_user` (`white_user_id`),
                    KEY `idx_rooms_black_user` (`black_user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            },
            function ($conn) {
                $conn->query("CREATE TABLE IF NOT EXISTS `moves` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `room_code` varchar(10) NOT NULL,
                    `from_r` tinyint(3) unsigned NOT NULL,
                    `from_c` tinyint(3) unsigned NOT NULL,
                    `to_r` tinyint(3) unsigned NOT NULL,
                    `to_c` tinyint(3) unsigned NOT NULL,
                    `piece` char(1) NOT NULL,
                    `color` char(1) NOT NULL,
                    `captured` char(1) DEFAULT NULL,
                    `promoted_piece_type` char(1) DEFAULT NULL,
                    `move_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`move_data`)),
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `idx_room_id` (`room_code`, `id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            },
            function ($conn) {
                $conn->query("CREATE TABLE IF NOT EXISTS `arcade_song` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL,
                    `title` varchar(120) NOT NULL,
                    `artist` varchar(100) NOT NULL DEFAULT 'Unknown Artist',
                    `bpm` int(11) NOT NULL DEFAULT 120,
                    `difficulty` tinyint(4) NOT NULL DEFAULT 2,
                    `difficulty_label` varchar(20) NOT NULL DEFAULT 'Normal',
                    `duration` int(11) NOT NULL DEFAULT 60,
                    `note_count` int(11) NOT NULL DEFAULT 0,
                    `audio_file` varchar(255) NOT NULL,
                    `audio_mime` varchar(50) NOT NULL,
                    `audio_bitrate` int(11) NOT NULL DEFAULT 0,
                    `cover_file` varchar(255) DEFAULT NULL,
                    `beatmap_path` varchar(255) NOT NULL COMMENT 'Path ke beatmap.json di filesystem',
                    `color_primary` varchar(7) NOT NULL DEFAULT '#ec4899',
                    `color_secondary` varchar(7) NOT NULL DEFAULT '#a855f7',
                    `is_active` tinyint(1) NOT NULL DEFAULT 1,
                    `play_count` int(11) NOT NULL DEFAULT 0,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `idx_user_id` (`user_id`),
                    KEY `idx_is_active` (`is_active`),
                    CONSTRAINT `fk_arcade_song_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            },
            function ($conn) {
                $conn->query("CREATE TABLE IF NOT EXISTS `arcade_score` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL,
                    `song_id` int(11) NOT NULL,
                    `score` int(11) NOT NULL DEFAULT 0,
                    `max_combo` int(11) NOT NULL DEFAULT 0,
                    `accuracy` decimal(5,2) NOT NULL DEFAULT 0.00,
                    `rank` char(1) NOT NULL DEFAULT 'D',
                    `perfect` int(11) NOT NULL DEFAULT 0,
                    `great` int(11) NOT NULL DEFAULT 0,
                    `good` int(11) NOT NULL DEFAULT 0,
                    `bad` int(11) NOT NULL DEFAULT 0,
                    `miss` int(11) NOT NULL DEFAULT 0,
                    `speed_mult` decimal(3,1) NOT NULL DEFAULT 1.5,
                    `played_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `idx_user_song` (`user_id`, `song_id`),
                    KEY `idx_song_score` (`song_id`, `score` DESC),
                    CONSTRAINT `fk_arcade_score_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_arcade_score_song` FOREIGN KEY (`song_id`) REFERENCES `arcade_song` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            },
        ],
    ],
];

// =============================================================================
// Runner — mirip database/migrate.php tapi pakai tabel `arcade_db_version`
// =============================================================================

$conn->query("CREATE TABLE IF NOT EXISTS arcade_db_version (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version INT NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$result = $conn->query("SELECT MAX(version) AS current_version FROM arcade_db_version");
$row = $result->fetch_assoc();
$current_version = (int)($row['current_version'] ?? 0);

echo "═══════════════════════════════════════════════════════\n";
echo "  MEeL Arcade Migration Runner\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  Versi saat ini: {$current_version}\n";
echo "  Total migrasi : " . count($migrations) . "\n";
echo "═══════════════════════════════════════════════════════\n\n";

$new_migrations = 0;

foreach ($migrations as $version => $migration) {
    if ($version > $current_version) {
        echo "[MEeL Arcade] Menjalankan migrasi v{$version}: {$migration['description']}...\n";

        foreach ($migration['sql'] as $migration_step) {
            if (is_callable($migration_step)) {
                try {
                    $migration_step($conn);
                    $err = $conn->error;
                    if ($err && !str_contains($err, 'Duplicate key name') && !str_contains($err, 'already exists')) {
                        echo "[MEeL Arcade] ⚠ Warning: {$err}\n";
                    }
                } catch (\Throwable $e) {
                    echo "[MEeL Arcade] ⚠ Warning: " . $e->getMessage() . "\n";
                }
            } else {
                $sql = $migration_step;
                try {
                    if ($conn->query($sql) === false) {
                        $err = $conn->error;
                        if ($err && !str_contains($err, 'Duplicate key name') && !str_contains($err, 'already exists')) {
                            echo "[MEeL Arcade] ⚠ Warning: {$err}\n";
                        }
                    }
                } catch (\Throwable $e) {
                    echo "[MEeL Arcade] ⚠ Warning: " . $e->getMessage() . "\n";
                }
            }
        }

        $stmt = $conn->prepare("INSERT INTO arcade_db_version (version) VALUES (?)");
        $stmt->bind_param("i", $version);
        $stmt->execute();
        $stmt->close();

        $new_migrations++;
        echo "[MEeL Arcade] ✓ Migrasi v{$version} selesai.\n\n";
    }
}

if ($new_migrations === 0) {
    echo "[MEeL Arcade] Database arcade sudah up-to-date (versi {$current_version}). Tidak ada migrasi baru.\n";
} else {
    echo "[MEeL Arcade] ✓ {$new_migrations} migrasi berhasil dijalankan. Versi sekarang: " . ($current_version + $new_migrations) . "\n";
}

echo "\nTabel yang dibuat/diperiksa:\n";
echo "  • rooms         — Ruang permainan catur multiplayer\n";
echo "  • moves         — Riwayat langkah permainan catur\n";
echo "  • arcade_song   — Metadata lagu/beatmap MEeL!Mania\n";
echo "  • arcade_score  — Skor permainan rhythm\n";

$conn->close();
