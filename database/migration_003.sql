-- ThatRank HR Database Migration 003
-- Leave Types & Comp-off Requests tables

CREATE TABLE IF NOT EXISTS `leave_types` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `days_per_year` DECIMAL(4,1) NOT NULL DEFAULT 0.0,
  `is_paid` TINYINT(1) NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `leave_types` (`name`, `code`, `days_per_year`, `is_paid`, `is_active`) VALUES
('Casual Leave', 'casual', 12.0, 1, 1),
('Sick Leave', 'sick', 6.0, 1, 1),
('Earned Leave', 'earned', 15.0, 1, 1),
('Comp-off', 'comp_off', 0.0, 1, 1),
('Work from Home', 'wfh', 0.0, 1, 1);

CREATE TABLE IF NOT EXISTS `comp_off_requests` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `worked_date` DATE NOT NULL,
  `hours_worked` DECIMAL(4,1) NOT NULL DEFAULT 8.0,
  `reason` TEXT NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `actioned_by` INT UNSIGNED NULL DEFAULT NULL,
  `actioned_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_compoff_user` (`user_id`),
  CONSTRAINT `fk_compoff_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

