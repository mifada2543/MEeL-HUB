-- =============================================================================
-- MEeL-HUB — Database Schema
-- Version: 1.0.0
-- Engine: MySQL 5.7+ / MariaDB 10.2+
-- Encoding: utf8mb4
-- =============================================================================
-- Cara import (nama database WAJIB disebut di baris perintah, bukan di sini):
--   mysql -u root -p MEeL < database/schema.sql
--
-- Dari MySQL CLI:
--   mysql> CREATE DATABASE `MEeL` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
--   mysql> USE `MEeL`;
--   mysql> SOURCE /path/to/database/schema.sql;
--
-- kenapa berkas ini SENGAJA tidak memuat CREATE DATABASE / USE
-- -----------------------------------------------------------
-- Dulu ia memuat keduanya, dan itu sebabkan impor mendarat diam-diam di
-- database yang salah: `USE` di dalam BERKAS menang atas nama database yang
-- disebut di baris perintah. `mysql ... meel_ci < schema.sql` tetap mendarat
-- di `MEeL`.
--
-- Perbaikannya harus berlapis, karena ada LIMA berkas yang selama ini menyalin
-- ulang nama itu dengan pola yang rapuh: install.sh (dua kali), ci.yml,
-- scripts/ci/schema-parity-check.php (dua kali), tests/DbTestHelper.php, dan
-- InteractionsForeignKeyMigrationIntegrationTest. Kalau satu saja polanya tidak
-- cocok, impor masuk ke database yang tidak dimaksud dan langkah berikutnya
-- menguji database kosong -- tanpa error sama sekali. arcade/schema.sql punya
-- `USE` yang sama dan bisa mengalihkan impor ke database produksi.
--
-- Menulis ulang salinan-salinan itu hanya memindahkan masalah. Jadi nama
-- database dihapus dari sini, dan tiap pemanggil impor menyebutkannya sendiri.
-- Penjaga: tests/unit/SchemaDatabaseSelectionTest.php + langkah CI
-- "Import schema.sql".

-- =============================================================================
-- TABEL: users
-- Menyimpan data pengguna, peran, dan sesi.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `users` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `username` varchar(50) NOT NULL,
    `role` varchar(20) DEFAULT 'user',
    `ip_address` varchar(45) DEFAULT 'Unknown',
    `password` varchar(255) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    `last_page` varchar(255) DEFAULT 'Index',
    `user_agent` varchar(255) DEFAULT NULL,
    `is_active` tinyint (1) DEFAULT 0,
    `bio` text DEFAULT NULL,
    `profile_picture` varchar(255) DEFAULT 'default_avatar.png',
    `favorite_genre` varchar(100) DEFAULT NULL,
    `custom_theme` varchar(50) DEFAULT 'default',
    `last_session_id` varchar(128) DEFAULT NULL,
    `access_via` varchar(100) DEFAULT NULL,
    `mfa_secret` varchar(64) DEFAULT NULL,
    `mfa_backup_codes` text DEFAULT NULL,
    `mfa_enabled` tinyint (1) DEFAULT 0,
    `mfa_last_step` bigint DEFAULT NULL,
    `meelcoin` int (11) NOT NULL DEFAULT 0,
    `meelcoin_held` int (11) NOT NULL DEFAULT 0 COMMENT 'MEeLCoin yang direservasi untuk upload yang belum terverifikasi',
    `meelcoin_last_refill` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_username_unique` (`username`),
    KEY `idx_meelcoin` (`meelcoin`),
    KEY `idx_users_role_active` (`role`, `is_active`, `last_activity`),
    KEY `idx_users_last_activity` (`last_activity`)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- Admin user dibuat otomatis oleh install.sh atau migrasi.

-- =============================================================================
-- TABEL: coin_holds
-- Reservasi MEeLCoin. Saldo total (users.meelcoin) tidak berubah sampai
-- upload terbukti menghasilkan baris media dan file yang valid.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `coin_holds` (
    `id` bigint (20) NOT NULL AUTO_INCREMENT,
    `user_id` int (11) NOT NULL,
    `amount` int (11) NOT NULL,
    `status` enum ('held', 'captured', 'released', 'expired') NOT NULL DEFAULT 'held',
    `reason` varchar (50) NOT NULL DEFAULT 'upload',
    `media_type` varchar (20) NOT NULL DEFAULT '',
    `upload_attempt_id` bigint (20) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `last_heartbeat` timestamp NOT NULL DEFAULT current_timestamp(),
    `expires_at` timestamp NOT NULL,
    `captured_at` timestamp NULL DEFAULT NULL,
    `released_at` timestamp NULL DEFAULT NULL,
    `release_reason` varchar (50) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_coin_holds_user_status` (`user_id`, `status`),
    KEY `idx_coin_holds_status_heartbeat` (`status`, `last_heartbeat`),
    KEY `idx_coin_holds_expires` (`status`, `expires_at`),
    CONSTRAINT `coin_holds_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: upload_attempts
-- Sesi upload per form GET. Token dipakai untuk heartbeat dan mengikat
-- reservasi coin ke proses upload yang benar.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `upload_attempts` (
    `id` bigint (20) NOT NULL AUTO_INCREMENT,
    `token` varchar (64) NOT NULL,
    `user_id` int (11) NOT NULL,
    `media_type` varchar (20) NOT NULL DEFAULT '',
    `hold_id` bigint (20) DEFAULT NULL,
    `status` enum ('active', 'completed', 'failed', 'expired') NOT NULL DEFAULT 'active',
    `stage` varchar (50) NOT NULL DEFAULT 'created',
    `cleanup_json` text DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `last_heartbeat` timestamp NOT NULL DEFAULT current_timestamp(),
    `client_heartbeat_at` timestamp NULL DEFAULT NULL COMMENT 'Heartbeat terakhir dari browser; dipakai sebelum capture',
    `expires_at` timestamp NOT NULL,
    `finished_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_upload_attempt_token` (`token`),
    KEY `idx_upload_attempt_user_status` (`user_id`, `status`),
    KEY `idx_upload_attempt_status_heartbeat` (`status`, `last_heartbeat`),
    KEY `idx_upload_attempt_expires` (`status`, `expires_at`),
    KEY `idx_upload_attempt_hold` (`hold_id`),
    CONSTRAINT `upload_attempts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `upload_attempts_ibfk_2` FOREIGN KEY (`hold_id`) REFERENCES `coin_holds` (`id`) ON DELETE SET NULL
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: video
-- Metadata video dengan dukungan HLS streaming.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `video` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `title` text NOT NULL,
    `description` text DEFAULT NULL,
    `filename` text NOT NULL,
    `thumbnail` varchar(255) DEFAULT NULL,
    `duration` int (11) DEFAULT 0,
    `views` int (11) DEFAULT 0,
    `likes` int (11) DEFAULT 0,
    `dislikes` int (11) DEFAULT 0,
    `search_metadata` text DEFAULT NULL,
    `user_id` int (11) DEFAULT NULL,
    `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `idx_video_upload_date` (`upload_date`),
    FULLTEXT KEY `ft_video_search` (`title`, `search_metadata`),
    CONSTRAINT `video_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: music
-- Metadata trek audio (MP3, FLAC, OGG, M4A).
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `music` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `title` text NOT NULL,
    `artist` varchar(100) DEFAULT NULL,
    `album` varchar(100) DEFAULT NULL,
    `description` text DEFAULT NULL,
    `filename` text NOT NULL,
    `thumbnail` varchar(255) DEFAULT NULL,
    `duration` int (11) DEFAULT 0,
    `views` int (11) DEFAULT 0,
    `likes` int (11) DEFAULT 0,
    `dislikes` int (11) DEFAULT 0,
    `search_metadata` text DEFAULT NULL,
    `user_id` int (11) DEFAULT NULL,
    `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `idx_music_upload_date` (`upload_date`),
    FULLTEXT KEY `ft_music_search` (`title`, `artist`, `search_metadata`),
    CONSTRAINT `music_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: books
-- Metadata buku digital / manga (ZIP/CBZ & PDF).
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `books` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `title` text NOT NULL,
    `author` varchar(100) DEFAULT NULL,
    `type` enum ('manga', 'pdf') NOT NULL,
    `has_chapters` tinyint (1) DEFAULT 0,
    `category` varchar(50) DEFAULT NULL,
    `path_folder` text DEFAULT NULL,
    `thumbnail` varchar(255) DEFAULT NULL,
    `user_id` int (11) DEFAULT NULL,
    `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `idx_books_upload_date` (`upload_date`),
    FULLTEXT KEY `ft_books_search` (`title`, `author`),
    CONSTRAINT `books_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: comments
-- Sistem komentar bersarang (nested) untuk video & musik.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `comments` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `user_id` int (11) NOT NULL,
    `video_id` int (11) DEFAULT NULL,
    `music_id` int (11) DEFAULT NULL,
    `parent_id` int (11) DEFAULT NULL,
    `comment` text NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `music_id` (`music_id`),
    KEY `fk_parent_comment` (`parent_id`),
    KEY `idx_comments_video_created` (`video_id`, `created_at`),
    KEY `idx_comments_music_created` (`music_id`, `created_at`),
    CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`music_id`) REFERENCES `music` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comments_video` FOREIGN KEY (`video_id`) REFERENCES `video` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_parent_comment` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: interactions
-- Menyimpan status like/dislike per user per konten.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `interactions` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `user_id` int (11) NOT NULL,
    `video_id` int (11) DEFAULT NULL,
    `music_id` int (11) DEFAULT NULL,
    `type` enum ('like', 'dislike') NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    -- Catatan: untuk baris video, music_id = NULL; untuk baris music, video_id = NULL.
    -- Unique key gabungan (user_id, video_id, music_id) TIDAK mencegah duplikat karena
    -- MySQL memperlakukan NULL sebagai nilai berbeda. Karena itu dipisah menjadi
    -- dua unique key per media type.
    UNIQUE KEY `unique_interaction_video` (`user_id`, `video_id`),
    UNIQUE KEY `unique_interaction_music` (`user_id`, `music_id`),
    KEY `user_id` (`user_id`),
    KEY `idx_int_video_type` (`video_id`, `type`),
    KEY `idx_int_music_type` (`music_id`, `type`),
    CONSTRAINT `fk_interactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_interactions_video` FOREIGN KEY (`video_id`) REFERENCES `video` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_interactions_music` FOREIGN KEY (`music_id`) REFERENCES `music` (`id`) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: playlists
-- Daftar putar musik untuk setiap pengguna.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `playlists` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL,
    `user_id` int (11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    CONSTRAINT `playlists_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: playlist_tracks
-- Relasi banyak-ke-banyak antara playlist dan track musik.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `playlist_tracks` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `playlist_id` int (11) NOT NULL,
    `music_id` int (11) NOT NULL,
    `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `playlist_id` (`playlist_id`),
    KEY `music_id` (`music_id`),
    CONSTRAINT `playlist_tracks_ibfk_1` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE,
    CONSTRAINT `playlist_tracks_ibfk_2` FOREIGN KEY (`music_id`) REFERENCES `music` (`id`) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: upload_queue
-- Antrean download dari URL (yt-dlp).
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `upload_queue` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `url` text NOT NULL,
    `media_type` varchar(20) NOT NULL,
    `user_id` int (11) NOT NULL,
    `status` enum ('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    `coin_cost` int (11) NOT NULL DEFAULT 0 COMMENT 'MEeLCoin yang dipotong saat INSERT, 0 = tidak dipotong',
    `coin_refunded` tinyint (1) NOT NULL DEFAULT 0 COMMENT '1 = biaya sudah dikembalikan oleh sweeper',
    `hold_id` bigint (20) DEFAULT NULL COMMENT 'Reservasi MEeLCoin untuk upload ini',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `idx_uq_status_created` (`status`, `created_at`),
    KEY `idx_uq_user_media` (`user_id`, `media_type`, `id`),
    KEY `idx_uq_hold` (`hold_id`),
    CONSTRAINT `upload_queue_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `upload_queue_ibfk_2` FOREIGN KEY (`hold_id`) REFERENCES `coin_holds` (`id`) ON DELETE SET NULL
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: transcode_queue
-- Antrean transcoding video ke audio.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `transcode_queue` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `user_id` int (11) NOT NULL,
    `status` enum ('processing', 'completed', 'failed') DEFAULT 'processing',
    `file_path` varchar(255) DEFAULT NULL,
    `coin_cost` int (11) NOT NULL DEFAULT 0 COMMENT 'MEeLCoin yang dipotong saat INSERT, 0 = tidak dipotong',
    `coin_refunded` tinyint (1) NOT NULL DEFAULT 0 COMMENT '1 = biaya sudah dikembalikan oleh sweeper',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `idx_tq_status_created` (`status`, `created_at`),
    CONSTRAINT `transcode_queue_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: view_logs
-- Mencatat kunjungan user ke konten (encegah view inflation).
--
-- Catatan: untuk baris video, music_id = NULL; untuk baris music,
-- video_id = NULL. UNIQUE key gabungan (user_id, video_id, music_id) TIDAK
-- mencegah duplikat karena MySQL memperlakukan NULL sebagai nilai berbeda,
-- sehingga setiap kunjungan baru tetap lolos sebagai baris baru. Karena itu
-- dipisah menjadi dua unique key per jenis media — sama seperti tabel
-- `interactions`.
-- Kolom yang tidak dipakai HARUS tetap NULL, bukan 0: kalau diisi 0, semua
-- baris musik berbagi video_id=0 dan saling bentrok.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `view_logs` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `user_id` int (11) NOT NULL,
    `video_id` int (11) DEFAULT NULL,
    `music_id` int (11) DEFAULT NULL,
    `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_view_video` (`user_id`, `video_id`),
    UNIQUE KEY `uq_view_music` (`user_id`, `music_id`),
    KEY `idx_vl_viewed_at` (`viewed_at`)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: ip_ban
-- Daftar alamat IP yang diblokir.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `ip_ban` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `ip_address` varchar(45) NOT NULL,
    `reason` varchar(255) DEFAULT NULL,
    `banned_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `ip_address` (`ip_address`)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: updates
-- Catatan changelog / riwayat pembaruan sistem.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `updates` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `version` varchar(20) NOT NULL,
    `content` text NOT NULL,
    `created_at` date NOT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: sidebar_settings
-- Konten pengumuman dan informasi penting di sidebar.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `sidebar_settings` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `important_content` text DEFAULT NULL,
    `announcement_content` text DEFAULT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: activity_log
-- Log aktivitas pengguna untuk audit dan keamanan.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `activity_log` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `user_id` int (11) DEFAULT NULL,
    `action` varchar(50) NOT NULL,
    `media_type` varchar(20) DEFAULT NULL,
    `media_id` int (11) DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT 'Unknown',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_al_created` (`created_at`),
    KEY `idx_al_user_created` (`user_id`, `created_at`),
    KEY `idx_al_action_created` (`action`, `created_at`)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: db_version
-- Migration tracker — mencatat versi skema database yang sudah dijalankan.
-- Dibuat secara otomatis oleh database/migrate.php.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `db_version` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `version` int (11) NOT NULL,
    `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: drive_files
-- Cloud Drive — file publik & privat per pengguna.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `drive_files` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `user_id` int (11) NOT NULL,
    `filename` varchar(255) NOT NULL,
    `file_size` bigint (20) DEFAULT 0,
    `file_type` varchar(50) DEFAULT NULL,
    `scope` enum ('public', 'private') DEFAULT 'private',
    `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `idx_drive_upload_date` (`upload_date`),
    CONSTRAINT `drive_files_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: login_attempts
-- Melacak percobaan login gagal berdasarkan IP untuk mencegah brute force.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `login_attempts` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `ip_address` varchar(45) NOT NULL,
    `attempts` int (11) NOT NULL DEFAULT 1,
    `last_attempt_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `locked_until` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ip_address` (`ip_address`)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: rooms & moves (chess) → dipindah ke arcade/schema.sql
-- Jalankan: php arcade/migrate.php
-- =============================================================================

-- =============================================================================
-- TABEL: site_settings
-- Konfigurasi dinamis sistem (key-value store).
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `site_settings` (
    `setting_key` varchar (50) NOT NULL,
    `setting_value` text NOT NULL,
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`setting_key`)
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- Default MEeLCoin settings
INSERT IGNORE INTO
  `site_settings` (`setting_key`, `setting_value`)
VALUES
  ('meelcoin_enabled', '1'),
  ('meelcoin_upload_cost', '5'),
  ('meelcoin_advanced_cost', '10'),
  ('meelcoin_user_max', '25'),
  ('meelcoin_user_refill', '15'),
  ('meelcoin_member_max', '50'),
  ('meelcoin_member_refill', '25'),
  ('meelcoin_refill_hours', '5');

-- =============================================================================
-- TABEL: meelcoin_log
-- Audit trail untuk transaksi MEeLCoin (credit/debit).
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `meelcoin_log` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `user_id` int (11) NOT NULL,
    `amount` int (11) NOT NULL COMMENT 'Positive=credit, Negative=debit',
    `balance_after` int (11) NOT NULL,
    `reason` varchar (50) NOT NULL COMMENT 'refill, upload, upload_advanced, admin_adjust, init',
    `hold_id` bigint (20) DEFAULT NULL COMMENT 'Reservasi coin yang di-capture untuk transaksi ini',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_mcl_user` (`user_id`),
    KEY `idx_mcl_created` (`created_at`),
    KEY `idx_mcl_hold` (`hold_id`),
    CONSTRAINT `meelcoin_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `meelcoin_log_ibfk_2` FOREIGN KEY (`hold_id`) REFERENCES `coin_holds` (`id`) ON DELETE SET NULL
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

-- =============================================================================
-- TABEL: user_notifications
-- Sistem notifikasi untuk like, reply komentar, MEeLCoin, chat admin.
-- =============================================================================
CREATE TABLE
  IF NOT EXISTS `user_notifications` (
    `id` int (11) NOT NULL AUTO_INCREMENT,
    `user_id` int (11) NOT NULL,
    `type` varchar (30) NOT NULL COMMENT 'like, reply, meelcoin, admin_chat, system',
    `title` varchar (100) NOT NULL,
    `message` text NOT NULL,
    `is_read` tinyint (1) DEFAULT 0,
    `related_id` int (11) DEFAULT NULL,
    `related_slug` varchar (255) DEFAULT NULL,
    `actor_user_id` int (11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_un_user` (`user_id`),
    KEY `idx_un_read` (`user_id`, `is_read`),
    KEY `idx_un_created` (`created_at`),
    KEY `idx_un_user_type_created` (`user_id`, `type`, `created_at`),
    CONSTRAINT `user_notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

COMMIT;