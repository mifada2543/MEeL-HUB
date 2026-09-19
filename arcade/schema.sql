-- =============================================================================
-- MEeL Arcade — Database Schema
-- Version: 1.0.0
-- Engine: MySQL 5.7+ / MariaDB 10.2+
-- Encoding: utf8mb4
-- =============================================================================
-- Tabel-tabel ini dijalankan terpisah dari schema.sql utama.
-- Jalankan: php arcade/migrate.php
-- =============================================================================
USE `MEeL`;

-- =============================================================================
-- TABEL: rooms
-- Ruang permainan catur online (multiplayer LAN).
-- =============================================================================
CREATE TABLE IF NOT EXISTS `rooms` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- TABEL: moves
-- Riwayat langkah permainan catur.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `moves` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- TABEL: arcade_song
-- Metadata lagu/beatmap custom untuk MEeL!Mania (rhythm game).
-- Beatmap disimpan sebagai file .json, DB hanya menyimpan path-nya.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `arcade_song` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- TABEL: arcade_score
-- Skor permainan untuk lagu custom.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `arcade_score` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
