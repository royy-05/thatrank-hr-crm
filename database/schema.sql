-- ThatRank HR Database Schema
-- Compatible with MySQL 8.0+ and MariaDB 10.4+
-- Timezone default: Asia/Kolkata (+05:30)

SET FOREIGN_KEY_CHECKS = 0;

-- 0. Teams table
CREATE TABLE IF NOT EXISTS `teams` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `manager_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_teams_manager` (`manager_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1. Users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `phone` VARCHAR(30) NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(50) NOT NULL DEFAULT 'employee',
  `job_title` VARCHAR(100) DEFAULT NULL,
  `department` VARCHAR(100) DEFAULT NULL,
  `team_id` INT UNSIGNED NULL DEFAULT NULL,
  `manager_id` INT UNSIGNED NULL DEFAULT NULL,
  `avatar_initials` VARCHAR(10) DEFAULT NULL,
  `avatar_color` VARCHAR(50) DEFAULT NULL,
  `joined_date` DATE NOT NULL,
  `date_of_birth` DATE NULL DEFAULT NULL,
  `work_anniversary` DATE NULL DEFAULT NULL,
  `status` ENUM('active', 'inactive', 'offboarded') NOT NULL DEFAULT 'active',
  `consent_accepted_at` DATETIME NULL DEFAULT NULL,
  `require_camera` TINYINT(1) NOT NULL DEFAULT 1,
  `require_location` TINYINT(1) NOT NULL DEFAULT 1,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_manager` (`manager_id`),
  INDEX `idx_users_team` (`team_id`),
  INDEX `idx_users_status` (`status`),
  CONSTRAINT `fk_users_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1b. User Sessions (Active devices, device tracking, sign out other devices)
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

-- 2. Daily Attendance Records
CREATE TABLE IF NOT EXISTS `attendance_records` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `status` ENUM('present', 'absent', 'on_leave', 'half_day', 'holiday', 'weekly_off') NOT NULL DEFAULT 'present',
  `first_in` TIME NULL DEFAULT NULL,
  `last_out` TIME NULL DEFAULT NULL,
  `work_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `break_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `gross_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `current_status` ENUM('clocked_in', 'on_break', 'clocked_out') NOT NULL DEFAULT 'clocked_out',
  `last_status_time` DATETIME NULL DEFAULT NULL,
  `is_late` TINYINT(1) NOT NULL DEFAULT 0,
  `late_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
  `overtime_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `auto_clocked_out` TINYINT(1) NOT NULL DEFAULT 0,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_attendance_user_date` (`user_id`, `date`),
  INDEX `idx_attendance_date` (`date`),
  INDEX `idx_attendance_status` (`status`),
  CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Punch Logs (audit and details for each clock in, break, out)
CREATE TABLE IF NOT EXISTS `punch_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attendance_record_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `type` ENUM('in', 'break_start', 'break_end', 'out') NOT NULL,
  `punched_at` DATETIME NOT NULL,
  `selfie_path` VARCHAR(255) NULL DEFAULT NULL,
  `latitude` DECIMAL(10, 7) NULL DEFAULT NULL,
  `longitude` DECIMAL(10, 7) NULL DEFAULT NULL,
  `location_name` VARCHAR(255) NULL DEFAULT NULL,
  `device_info` VARCHAR(255) NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_punch_user_time` (`user_id`, `punched_at`),
  INDEX `idx_punch_record` (`attendance_record_id`),
  CONSTRAINT `fk_punch_record` FOREIGN KEY (`attendance_record_id`) REFERENCES `attendance_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_punch_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Heartbeats (Live board status and quick polling under 5 KB)
CREATE TABLE IF NOT EXISTS `heartbeats` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL UNIQUE,
  `last_beat_at` DATETIME NOT NULL,
  `active_status` ENUM('working', 'break', 'idle', 'off') NOT NULL DEFAULT 'off',
  `active_note` VARCHAR(255) NULL DEFAULT NULL,
  `is_idle` TINYINT(1) NOT NULL DEFAULT 0,
  `idle_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
  `device_info` VARCHAR(255) NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_heartbeat_last_beat` (`last_beat_at`),
  CONSTRAINT `fk_heartbeat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Leave Balances
-- 5a. Leave Types
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

CREATE TABLE IF NOT EXISTS `leave_balances` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `year` INT UNSIGNED NOT NULL,
  `casual_total` DECIMAL(4,1) NOT NULL DEFAULT 12.0,
  `casual_used` DECIMAL(4,1) NOT NULL DEFAULT 0.0,
  `casual_accrued` DECIMAL(4,1) NOT NULL DEFAULT 12.0,
  `sick_total` DECIMAL(4,1) NOT NULL DEFAULT 6.0,
  `sick_used` DECIMAL(4,1) NOT NULL DEFAULT 0.0,
  `sick_accrued` DECIMAL(4,1) NOT NULL DEFAULT 6.0,
  `earned_total` DECIMAL(4,1) NOT NULL DEFAULT 15.0,
  `earned_used` DECIMAL(4,1) NOT NULL DEFAULT 0.0,
  `earned_carried` DECIMAL(4,1) NOT NULL DEFAULT 0.0,
  `comp_off_total` DECIMAL(4,1) NOT NULL DEFAULT 0.0,
  `comp_off_used` DECIMAL(4,1) NOT NULL DEFAULT 0.0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_leave_balance_user_year` (`user_id`, `year`),
  CONSTRAINT `fk_leave_balance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Leave Requests
CREATE TABLE IF NOT EXISTS `leave_requests` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `leave_type` ENUM('casual', 'sick', 'earned', 'comp_off', 'wfh') NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `days_count` DECIMAL(4,1) NOT NULL DEFAULT 1.0,
  `duration_type` ENUM('full_day', 'half_day', 'short_leave') NOT NULL DEFAULT 'full_day',
  `reason` TEXT NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected', 'withdrawn', 'draft') NOT NULL DEFAULT 'pending',
  `actioned_by` INT UNSIGNED NULL DEFAULT NULL,
  `actioned_at` DATETIME NULL DEFAULT NULL,
  `rejection_reason` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_leave_req_user` (`user_id`),
  INDEX `idx_leave_req_status` (`status`),
  INDEX `idx_leave_req_dates` (`start_date`, `end_date`),
  CONSTRAINT `fk_leave_req_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leave_req_actioner` FOREIGN KEY (`actioned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6a. Comp-off Credit Requests
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

-- 7. Attendance Corrections
CREATE TABLE IF NOT EXISTS `attendance_corrections` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `requested_in` TIME NULL DEFAULT NULL,
  `requested_out` TIME NULL DEFAULT NULL,
  `reason` TEXT NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected', 'withdrawn') NOT NULL DEFAULT 'pending',
  `actioned_by` INT UNSIGNED NULL DEFAULT NULL,
  `actioned_at` DATETIME NULL DEFAULT NULL,
  `rejection_reason` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_corrections_user` (`user_id`),
  INDEX `idx_corrections_status` (`status`),
  INDEX `idx_corrections_date` (`date`),
  CONSTRAINT `fk_corrections_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_corrections_actioner` FOREIGN KEY (`actioned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Community Posts
CREATE TABLE IF NOT EXISTS `community_posts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `recipient_id` INT UNSIGNED NULL DEFAULT NULL,
  `type` ENUM('post', 'announcement', 'poll', 'praise', 'celebration') NOT NULL DEFAULT 'post',
  `title` VARCHAR(255) NULL DEFAULT NULL,
  `content` TEXT NOT NULL,
  `image_path` VARCHAR(255) NULL DEFAULT NULL,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `likes_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `comments_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_posts_user` (`user_id`),
  INDEX `idx_posts_recipient` (`recipient_id`),
  INDEX `idx_posts_pinned` (`is_pinned`),
  INDEX `idx_posts_created` (`created_at`),
  CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_posts_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Community Post Reactions
CREATE TABLE IF NOT EXISTS `community_post_reactions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `post_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `reaction` VARCHAR(20) NOT NULL DEFAULT 'heart',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_post_user_reaction` (`post_id`, `user_id`, `reaction`),
  CONSTRAINT `fk_reactions_post` FOREIGN KEY (`post_id`) REFERENCES `community_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Community Comments
CREATE TABLE IF NOT EXISTS `community_comments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `post_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `comment` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_comments_post` (`post_id`),
  CONSTRAINT `fk_comments_post` FOREIGN KEY (`post_id`) REFERENCES `community_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Community Polls
CREATE TABLE IF NOT EXISTS `community_polls` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `post_id` INT UNSIGNED NOT NULL,
  `question` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_polls_post` (`post_id`),
  CONSTRAINT `fk_polls_post` FOREIGN KEY (`post_id`) REFERENCES `community_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Community Poll Options
CREATE TABLE IF NOT EXISTS `community_poll_options` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `poll_id` INT UNSIGNED NOT NULL,
  `option_text` VARCHAR(255) NOT NULL,
  `votes_count` INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX `idx_poll_options_poll` (`poll_id`),
  CONSTRAINT `fk_poll_options_poll` FOREIGN KEY (`poll_id`) REFERENCES `community_polls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Community Poll Votes
CREATE TABLE IF NOT EXISTS `community_poll_votes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `poll_id` INT UNSIGNED NOT NULL,
  `option_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_poll_user` (`poll_id`, `user_id`),
  CONSTRAINT `fk_votes_poll` FOREIGN KEY (`poll_id`) REFERENCES `community_polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_votes_option` FOREIGN KEY (`option_id`) REFERENCES `community_poll_options` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_votes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Company Settings
CREATE TABLE IF NOT EXISTS `company_settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT NOT NULL,
  `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Holidays
CREATE TABLE IF NOT EXISTS `holidays` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `date` DATE NOT NULL,
  `year` INT UNSIGNED NOT NULL,
  `is_optional` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_holidays_date` (`date`),
  INDEX `idx_holidays_year` (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Audit Log (every change to punches, corrections, leave, settings, employees)
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `target_type` VARCHAR(50) NOT NULL,
  `target_id` INT UNSIGNED NULL DEFAULT NULL,
  `old_values` JSON NULL DEFAULT NULL,
  `new_values` JSON NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_action` (`action`),
  INDEX `idx_audit_target` (`target_type`, `target_id`),
  INDEX `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Password Resets
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(150) NOT NULL,
  `token` VARCHAR(100) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pw_resets_token` (`token`),
  INDEX `idx_pw_resets_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Events
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

-- 19. Notifications Queue
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

-- 20. Salary Components
CREATE TABLE IF NOT EXISTS `salary_components` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('earning', 'deduction') NOT NULL,
  `calc_type` ENUM('fixed', 'percent_of_basic', 'percent_of_gross') NOT NULL DEFAULT 'fixed',
  `default_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `is_statutory` VARCHAR(20) NOT NULL DEFAULT 'none',
  `applies_to_all` TINYINT(1) NOT NULL DEFAULT 1,
  `show_on_payslip` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_sal_comp_type` (`type`),
  INDEX `idx_sal_comp_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Salary Structures
CREATE TABLE IF NOT EXISTS `salary_structures` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `effective_from` DATE NOT NULL,
  `component_values` JSON NOT NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sal_struct_user_eff` (`user_id`, `effective_from`),
  CONSTRAINT `fk_struct_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_struct_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. Payroll Runs
CREATE TABLE IF NOT EXISTS `payroll_runs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `month` INT NOT NULL,
  `year` INT NOT NULL,
  `status` ENUM('draft', 'finalized', 'paid') NOT NULL DEFAULT 'draft',
  `payday_date` DATE NULL,
  `created_by` INT UNSIGNED NULL,
  `finalized_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_payroll_month_year` (`month`, `year`),
  CONSTRAINT `fk_runs_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. Payslips
CREATE TABLE IF NOT EXISTS `payslips` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `run_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `month` INT NOT NULL,
  `year` INT NOT NULL,
  `working_days` DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  `paid_days` DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  `lop_days` DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  `lop_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `earnings` JSON NOT NULL,
  `deductions` JSON NOT NULL,
  `adjustments` JSON NULL,
  `gross` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_deductions` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `net_pay` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME NULL,
  `paid_on` DATE NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_payslip_run_user` (`run_id`, `user_id`),
  INDEX `idx_payslips_user_my` (`user_id`, `year`, `month`),
  CONSTRAINT `fk_payslips_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payslips_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Role Permissions (configurable permissions per role)
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `role` VARCHAR(50) NOT NULL,
  `permission_key` VARCHAR(100) NOT NULL,
  `granted` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_role_perm` (`role`, `permission_key`),
  INDEX `idx_role_perm_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. Roles (system and custom user roles)
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

SET FOREIGN_KEY_CHECKS = 1;

