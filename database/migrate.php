<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Access denied. Jalankan dari terminal: php database/migrate.php');
}

require_once __DIR__ . '/../auth/config.php';

if (!isset($conn) || !$conn instanceof \mysqli || $conn->connect_error) {
    echo "[MEeL] ❌ Gagal terhubung ke database. Periksa auth/config.php.\n";
    exit(1);
}

function meel_mig_has_column(\mysqli $conn, string $table, string $col): bool
{
    $sql = "SHOW COLUMNS FROM `" . $conn->real_escape_string($table)
         . "` LIKE '" . $conn->real_escape_string($col) . "'";
    $r = $conn->query($sql);
    return $r && $r->num_rows > 0;
}

function meel_mig_has_index(\mysqli $conn, string $table, string $index): bool
{
    $sql = "SHOW INDEX FROM `" . $conn->real_escape_string($table)
         . "` WHERE Key_name = '" . $conn->real_escape_string($index) . "'";
    $r = $conn->query($sql);
    return $r && $r->num_rows > 0;
}

function meel_mig_add_index(\mysqli $conn, string $table, string $index, string $cols): void
{
    if (meel_mig_has_index($conn, $table, $index)) {
        return;
    }
    $result = $conn->query("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$cols})");
    if (!$result) {
        $err = $conn->error;
        if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
            echo "[MEeL] ⚠ Warning ({$table}.{$index}): {$err}\n";
        }
    }
}

function meel_mig_add_fulltext(\mysqli $conn, string $table, string $index, string $cols): void
{
    if (meel_mig_has_index($conn, $table, $index)) {
        return;
    }
    $result = $conn->query("ALTER TABLE `{$table}` ADD FULLTEXT INDEX `{$index}` ({$cols})");
    if (!$result) {
        $err = $conn->error;
        if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
            echo "[MEeL] ⚠ Warning ({$table}.{$index}): {$err}\n";
        }
    }
}

function meel_mig_add_unique(\mysqli $conn, string $table, string $index, string $cols): void
{
    if (meel_mig_has_index($conn, $table, $index)) {
        return;
    }
    $result = $conn->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$index}` ({$cols})");
    if (!$result) {
        $err = $conn->error;
        if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
            echo "[MEeL] ⚠ Warning ({$table}.{$index}): {$err}\n";
        }
    }
}

function meel_mig_add_fk(\mysqli $conn, string $table, string $constraint, string $fk_def): void
{
    $result = $conn->query("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$fk_def}");
    if (!$result) {
        $err = $conn->error;
        if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
            echo "[MEeL] ⚠ Warning ({$table}.{$constraint}): {$err}\n";
        }
    }
}

$migrations = [
    1 => [
        'description' => 'Sync database ke skema terbaru (v1.0.0)',
        'sql' => [
            // ── FULLTEXT indexes (pencarian) ──
            function ($conn) {
                meel_mig_add_fulltext($conn, 'video', 'ft_video_search', 'title, search_metadata');
            },
            function ($conn) {
                meel_mig_add_fulltext($conn, 'music', 'ft_music_search', 'title, artist, search_metadata');
            },
            function ($conn) {
                meel_mig_add_fulltext($conn, 'books', 'ft_books_search', 'title, author');
            },

            // ── Performance indexes (upload_date) ──
            function ($conn) {
                meel_mig_add_index($conn, 'video', 'idx_video_upload_date', 'upload_date');
            },
            function ($conn) {
                meel_mig_add_index($conn, 'music', 'idx_music_upload_date', 'upload_date');
            },
            function ($conn) {
                meel_mig_add_index($conn, 'books', 'idx_books_upload_date', 'upload_date');
            },
            function ($conn) {
                meel_mig_add_index($conn, 'drive_files', 'idx_drive_upload_date', 'upload_date');
            },

            // ── FK constraints (upload_queue, transcode_queue, drive_files) ──
            function ($conn) {
                $conn->query("DELETE FROM upload_queue WHERE user_id NOT IN (SELECT id FROM users)");
                meel_mig_add_fk($conn, 'upload_queue', 'fk_upload_queue_user',
                    'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
            },
            function ($conn) {
                $conn->query("DELETE FROM transcode_queue WHERE user_id NOT IN (SELECT id FROM users)");
                meel_mig_add_fk($conn, 'transcode_queue', 'fk_transcode_queue_user',
                    'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
            },
            function ($conn) {
                $conn->query("DELETE FROM drive_files WHERE user_id NOT IN (SELECT id FROM users)");
                meel_mig_add_fk($conn, 'drive_files', 'fk_drive_files_user',
                    'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
            },

            // ── title TEXT (cegah silent truncation) ──
            function ($conn) {
                $result = $conn->query("ALTER TABLE video MODIFY COLUMN title TEXT NOT NULL");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (video.title): {$err}\n";
                    }
                }
            },
            function ($conn) {
                $result = $conn->query("ALTER TABLE music MODIFY COLUMN title TEXT NOT NULL");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (music.title): {$err}\n";
                    }
                }
            },
            function ($conn) {
                $result = $conn->query("ALTER TABLE books MODIFY COLUMN title TEXT NOT NULL");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (books.title): {$err}\n";
                    }
                }
            },

            // ── activity_log table ──
            function ($conn) {
                $conn->query("CREATE TABLE IF NOT EXISTS activity_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT DEFAULT NULL,
                    action VARCHAR(50) NOT NULL,
                    media_type VARCHAR(20) DEFAULT NULL,
                    media_id INT DEFAULT NULL,
                    ip_address VARCHAR(45) DEFAULT 'Unknown',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            },

            // ── UNIQUE KEY users.username (bersihkan duplikat guest) ──
            function ($conn) {
                $conn->query("DELETE g1 FROM users g1
                    INNER JOIN users g2
                    WHERE g1.id < g2.id
                    AND g1.role = 'guest'
                    AND g2.role = 'guest'
                    AND g1.username = g2.username");
            },
            function ($conn) {
                $result = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 AS new_ai FROM users");
                if ($result) {
                    $row = $result->fetch_assoc();
                    $new_ai = (int)$row['new_ai'];
                    $conn->query("ALTER TABLE users AUTO_INCREMENT = " . (int)$new_ai);
                }
            },
            function ($conn) {
                meel_mig_add_unique($conn, 'users', 'idx_username_unique', 'username');
            },

            // ── role varchar(20) + defaults ──
            function ($conn) {
                $result = $conn->query("ALTER TABLE users MODIFY COLUMN role varchar(20) DEFAULT 'user'");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (users.role): {$err}\n";
                    }
                }
            },
            function ($conn) {
                $check = $conn->query("SHOW INDEX FROM users WHERE Key_name = 'username'");
                if ($check && $check->num_rows > 0) {
                    $conn->query("ALTER TABLE users DROP INDEX username");
                }
            },
            function ($conn) {
                $conn->query("ALTER TABLE users ALTER COLUMN is_active SET DEFAULT 0");
            },
            function ($conn) {
                $conn->query("ALTER TABLE users ALTER COLUMN ip_address SET DEFAULT 'Unknown'");
            },
            function ($conn) {
                $conn->query("ALTER TABLE users ALTER COLUMN last_page SET DEFAULT 'Index'");
            },
            function ($conn) {
                $conn->query("ALTER TABLE activity_log ALTER COLUMN ip_address SET DEFAULT 'Unknown'");
            },

            // ── MFA columns ──
            function ($conn) {
                if (!meel_mig_has_column($conn, 'users', 'mfa_secret')) {
                    $conn->query("ALTER TABLE users ADD COLUMN mfa_secret VARCHAR(64) DEFAULT NULL AFTER last_session_id");
                }
            },
            function ($conn) {
                if (!meel_mig_has_column($conn, 'users', 'mfa_backup_codes')) {
                    $conn->query("ALTER TABLE users ADD COLUMN mfa_backup_codes TEXT DEFAULT NULL AFTER mfa_secret");
                }
            },
            function ($conn) {
                if (!meel_mig_has_column($conn, 'users', 'mfa_enabled')) {
                    $conn->query("ALTER TABLE users ADD COLUMN mfa_enabled TINYINT(1) DEFAULT 0 AFTER mfa_backup_codes");
                }
            },

            // ── Comments composite indexes ──
            function ($conn) {
                meel_mig_add_index($conn, 'comments', 'idx_comments_video_created', 'video_id, created_at');
            },
            function ($conn) {
                meel_mig_add_index($conn, 'comments', 'idx_comments_music_created', 'music_id, created_at');
            },

            // ── Interactions unique key (split per media type) ──
            function ($conn) {
                $conn->query("DELETE i1 FROM interactions i1
                    INNER JOIN interactions i2
                    WHERE i1.id < i2.id
                    AND i1.user_id = i2.user_id
                    AND i1.video_id = i2.video_id
                    AND i1.video_id IS NOT NULL
                    AND i2.video_id IS NOT NULL");
            },
            function ($conn) {
                $conn->query("DELETE i1 FROM interactions i1
                    INNER JOIN interactions i2
                    WHERE i1.id < i2.id
                    AND i1.user_id = i2.user_id
                    AND i1.music_id = i2.music_id
                    AND i1.music_id IS NOT NULL
                    AND i2.music_id IS NOT NULL");
            },
            function ($conn) {
                if (meel_mig_has_index($conn, 'interactions', 'unique_interaction')) {
                    $conn->query("ALTER TABLE interactions DROP INDEX unique_interaction");
                }
            },
            function ($conn) {
                meel_mig_add_unique($conn, 'interactions', 'unique_interaction_video', 'user_id, video_id');
            },
            function ($conn) {
                meel_mig_add_unique($conn, 'interactions', 'unique_interaction_music', 'user_id, music_id');
            },

            // ── MEeLCoin system ──
            function ($conn) {
                if (!meel_mig_has_column($conn, 'users', 'meelcoin')) {
                    $result = $conn->query("ALTER TABLE users ADD COLUMN meelcoin INT(11) NOT NULL DEFAULT 0 AFTER mfa_enabled");
                    if (!$result) {
                        $err = $conn->error;
                        if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                            echo "[MEeL] ⚠ Warning (users.meelcoin): {$err}\n";
                        }
                    }
                }
            },
            function ($conn) {
                if (!meel_mig_has_column($conn, 'users', 'meelcoin_last_refill')) {
                    $result = $conn->query("ALTER TABLE users ADD COLUMN meelcoin_last_refill TIMESTAMP NULL DEFAULT NULL AFTER meelcoin");
                    if (!$result) {
                        $err = $conn->error;
                        if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                            echo "[MEeL] ⚠ Warning (users.meelcoin_last_refill): {$err}\n";
                        }
                    }
                }
            },
            function ($conn) {
                meel_mig_add_index($conn, 'users', 'idx_meelcoin', 'meelcoin');
            },
            function ($conn) {
                $conn->query("CREATE TABLE IF NOT EXISTS site_settings (
                    setting_key VARCHAR(50) NOT NULL,
                    setting_value TEXT NOT NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (setting_key),
                    KEY idx_setting_key (setting_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            },
            function ($conn) {
                $defaults = [
                    'meelcoin_enabled'       => '1',
                    'meelcoin_upload_cost'   => '5',
                    'meelcoin_advanced_cost' => '10',
                    'meelcoin_user_max'      => '25',
                    'meelcoin_user_refill'   => '15',
                    'meelcoin_member_max'    => '50',
                    'meelcoin_member_refill' => '25',
                    'meelcoin_refill_hours'  => '5',
                ];
                $stmt = $conn->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
                foreach ($defaults as $key => $value) {
                    $stmt->bind_param("ss", $key, $value);
                    $stmt->execute();
                }
                $stmt->close();
            },
            function ($conn) {
                $conn->query("CREATE TABLE IF NOT EXISTS meelcoin_log (
                    id INT(11) NOT NULL AUTO_INCREMENT,
                    user_id INT(11) NOT NULL,
                    amount INT(11) NOT NULL COMMENT 'Positive=credit, Negative=debit',
                    balance_after INT(11) NOT NULL,
                    reason VARCHAR(50) NOT NULL COMMENT 'refill, upload, upload_advanced, admin_adjust, init',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_mcl_user (user_id),
                    KEY idx_mcl_created (created_at),
                    CONSTRAINT meelcoin_log_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            },

            // ── view_logs indexes ──
            function ($conn) {
                meel_mig_add_index($conn, 'view_logs', 'idx_vl_video_id', 'video_id');
            },
            function ($conn) {
                meel_mig_add_index($conn, 'view_logs', 'idx_vl_music_id', 'music_id');
            },

            // ── user_notifications ──
            function ($conn) {
                $conn->query("CREATE TABLE IF NOT EXISTS user_notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    type VARCHAR(30) NOT NULL COMMENT 'like, reply, meelcoin, admin_chat, system',
                    title VARCHAR(100) NOT NULL,
                    message TEXT NOT NULL,
                    is_read TINYINT(1) DEFAULT 0,
                    related_id INT DEFAULT NULL,
                    related_slug VARCHAR(255) DEFAULT NULL,
                    actor_user_id INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    KEY idx_un_user (user_id),
                    KEY idx_un_read (user_id, is_read),
                    KEY idx_un_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            },
        ],
    ],
];

$conn->query("CREATE TABLE IF NOT EXISTS db_version (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version INT NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$result = $conn->query("SELECT MAX(version) AS current_version FROM db_version");
$row = $result->fetch_assoc();
$current_version = (int)($row['current_version'] ?? 0);

$new_migrations = 0;

foreach ($migrations as $version => $migration) {
    if ($version > $current_version) {
        echo "[MEeL] Menjalankan migrasi v{$version}: {$migration['description']}...\n";

        foreach ($migration['sql'] as $migration_step) {
            if (is_callable($migration_step)) {
                try {
                    $migration_step($conn);
                    $err = $conn->error;
                    if ($err && !str_contains($err, 'Duplicate key name') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning: {$err}\n";
                    }
                } catch (\Throwable $e) {
                    echo "[MEeL] ⚠ Warning: " . $e->getMessage() . "\n";
                }
            }
        }

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

/* reference build: MEeL-C6H9N3O3 [4c4bfb10e259d5f4] */
