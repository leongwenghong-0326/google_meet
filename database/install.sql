-- ConnectMeet Database Schema (Local Development)
CREATE DATABASE IF NOT EXISTS `connectmeet_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `connectmeet_db`;

-- 1. Users Table (Optional account support)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `display_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(191) NULL UNIQUE,
    `password_hash` VARCHAR(255) NULL,
    `role` ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Meetings Table
CREATE TABLE IF NOT EXISTS `meetings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `meeting_code` VARCHAR(32) NOT NULL UNIQUE,
    `host_user_id` INT UNSIGNED NULL,
    `host_token_hash` VARCHAR(64) NOT NULL,
    `title` VARCHAR(255) NOT NULL DEFAULT 'ConnectMeet Session',
    `status` ENUM('scheduled', 'active', 'ended') NOT NULL DEFAULT 'active',
    `scheduled_at` DATETIME NULL,
    `expires_at` DATETIME NULL,
    `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
    `participant_limit` INT UNSIGNED NOT NULL DEFAULT 8,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_meetings_code` (`meeting_code`),
    INDEX `idx_meetings_status` (`status`),
    CONSTRAINT `fk_meetings_host_user` FOREIGN KEY (`host_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Meeting Participants Table
CREATE TABLE IF NOT EXISTS `meeting_participants` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `meeting_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `participant_token_hash` VARCHAR(64) NOT NULL,
    `display_name` VARCHAR(100) NOT NULL,
    `role` ENUM('host', 'participant') NOT NULL DEFAULT 'participant',
    `audio_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `video_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `screen_sharing` TINYINT(1) NOT NULL DEFAULT 0,
    `hand_raised` TINYINT(1) NOT NULL DEFAULT 0,
    `is_online` TINYINT(1) NOT NULL DEFAULT 1,
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `left_at` DATETIME NULL,
    INDEX `idx_participants_meeting` (`meeting_id`),
    INDEX `idx_participants_online` (`meeting_id`, `is_online`),
    INDEX `idx_participants_last_seen` (`last_seen_at`),
    CONSTRAINT `fk_participants_meeting` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_participants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Meeting Messages Table (Real-time chat)
CREATE TABLE IF NOT EXISTS `meeting_messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `meeting_id` INT UNSIGNED NOT NULL,
    `participant_id` INT UNSIGNED NOT NULL,
    `message_type` ENUM('text', 'system') NOT NULL DEFAULT 'text',
    `message_content` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_messages_meeting_id` (`meeting_id`, `id`),
    CONSTRAINT `fk_messages_meeting` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_messages_participant` FOREIGN KEY (`participant_id`) REFERENCES `meeting_participants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. WebRTC Signals Table (Peer-to-peer mesh signaling)
CREATE TABLE IF NOT EXISTS `meeting_signals` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `meeting_id` INT UNSIGNED NOT NULL,
    `from_participant_id` INT UNSIGNED NOT NULL,
    `to_participant_id` INT UNSIGNED NOT NULL,
    `signal_type` ENUM('offer', 'answer', 'candidate') NOT NULL,
    `signal_payload` LONGTEXT NOT NULL,
    `is_delivered` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_signals_target` (`meeting_id`, `to_participant_id`, `is_delivered`, `id`),
    CONSTRAINT `fk_signals_meeting` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Meeting Events Table (Reactions, presence, captions)
CREATE TABLE IF NOT EXISTS `meeting_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `meeting_id` INT UNSIGNED NOT NULL,
    `participant_id` INT UNSIGNED NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `event_payload` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_events_meeting` (`meeting_id`, `id`),
    CONSTRAINT `fk_events_meeting` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
