-- ThatRank HR Database Migration 007
-- Custom Roles Management System
-- Tables: roles, alter users.role to VARCHAR(50)

CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default system roles
INSERT INTO `roles` (`slug`, `name`, `description`, `is_system`) VALUES
('admin', 'Administrator', 'Full system access and company settings management', 1),
('manager', 'Manager', 'Team lead with approval and reporting privileges', 1),
('employee', 'Employee', 'Standard staff member with workspace and punching access', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- Modify users.role from ENUM to VARCHAR(50) to support custom roles
ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'employee';

