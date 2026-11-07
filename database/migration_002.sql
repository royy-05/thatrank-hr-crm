-- ThatRank HR Database Migration 002
-- Scope compliance: user_sessions, teams, events, notifications_queue, users columns, community_posts recipient

SET FOREIGN_KEY_CHECKS = 0;

-- 1. User Sessions (Active devices, device tracking, sign out other devices)
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `session_id` VARCHAR(128) NOT NULL,
  `browser` VARCHAR(100) NULL,
  `os` VARCHAR(100) NULL,
  `screen` VARCHAR(50) NULL,
  `ip` VARCHAR(45) NULL,
  `is_new` TINYINT(1) NOT NULL DEFAULT 1,
  `last_seen_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sessions_user` (`user_id`),
  INDEX `idx_sessions_id` (`session_id`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Teams table
CREATE TABLE IF NOT EXISTS `teams` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `manager_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_teams_manager` (`manager_id`),
  CONSTRAINT `fk_teams_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Events table
CREATE TABLE IF NOT EXISTS `events` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `event_date` DATE NOT NULL,
  `event_time` TIME NULL,
  `link` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_events_date` (`event_date`),
  CONSTRAINT `fk_events_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Notifications Queue table
CREATE TABLE IF NOT EXISTS `notifications_queue` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event` VARCHAR(100) NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `payload` JSON NOT NULL,
  `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `next_try_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME NULL,
  `error_message` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_queue_status_next` (`status`, `next_try_at`),
  INDEX `idx_queue_user` (`user_id`),
  CONSTRAINT `fk_queue_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Add columns to users table
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `phone` VARCHAR(30) NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `team_id` INT UNSIGNED NULL AFTER `manager_id`,
  ADD COLUMN IF NOT EXISTS `date_of_birth` DATE NULL AFTER `joined_date`,
  ADD COLUMN IF NOT EXISTS `work_anniversary` DATE NULL AFTER `date_of_birth`;

-- 6. Add recipient_id to community_posts table
ALTER TABLE `community_posts`
  ADD COLUMN IF NOT EXISTS `recipient_id` INT UNSIGNED NULL AFTER `user_id`;

SET FOREIGN_KEY_CHECKS = 1;
