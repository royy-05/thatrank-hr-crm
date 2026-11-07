-- ThatRank HR Database Migration 005
-- Payroll & My Finances Module
-- Tables: salary_components, salary_structures, payroll_runs, payslips, and default payroll settings

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

-- Seed default components
INSERT INTO `salary_components` (`id`, `name`, `type`, `calc_type`, `default_value`, `is_statutory`, `applies_to_all`, `show_on_payslip`, `sort_order`, `active`) VALUES
(1, 'Basic', 'earning', 'fixed', 30000.00, 'none', 1, 1, 1, 1),
(2, 'House rent allowance', 'earning', 'percent_of_basic', 50.00, 'none', 1, 1, 2, 1),
(3, 'Special allowance', 'earning', 'fixed', 13400.00, 'none', 1, 1, 3, 1),
(4, 'Internet allowance', 'earning', 'fixed', 1600.00, 'none', 1, 1, 4, 1),
(5, 'Provident fund', 'deduction', 'percent_of_basic', 12.00, 'pf', 1, 1, 5, 1),
(6, 'ESI', 'deduction', 'percent_of_gross', 0.75, 'esi', 1, 1, 6, 1),
(7, 'Professional tax', 'deduction', 'fixed', 200.00, 'pt', 1, 1, 7, 1),
(8, 'TDS', 'deduction', 'fixed', 0.00, 'tds', 1, 1, 8, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

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

-- Insert default payroll settings
INSERT IGNORE INTO `company_settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('company_legal_name', 'ThatRank Digital Marketing Solutions Pvt. Ltd.', 'payroll', 'Company registered legal name for payslips'),
('company_address', 'Plot 42, Cyber City, Chandigarh 160101, India', 'payroll', 'Registered office address printed on payslips'),
('payroll_logo', '', 'payroll', 'Custom logo base64 or path for payslips'),
('payslip_footer_text', 'This is a computer-generated payslip and does not require a physical signature.', 'payroll', 'Footer disclaimer on payslips'),
('payroll_payday', '30', 'payroll', 'Day of the month for salary payout'),
('financial_year_start_month', '4', 'payroll', 'Month when financial year begins (1 for January, 4 for April)'),
('pf_rate', '12.0', 'payroll', 'Employee provident fund deduction percentage'),
('pf_wage_ceiling_enabled', '1', 'payroll', 'Cap provident fund wage calculation to statutory ceiling'),
('pf_wage_ceiling', '15000', 'payroll', 'Statutory PF wage ceiling amount in rupees'),
('esi_rate', '0.75', 'payroll', 'Employee State Insurance deduction percentage'),
('esi_gross_threshold', '21000', 'payroll', 'Gross salary threshold under which ESI applies'),
('default_professional_tax', '200', 'payroll', 'Default monthly professional tax in rupees'),
('lop_divisor', 'calendar_days', 'payroll', 'Divisor for daily rate LOP calculation (calendar_days or working_days)'),
('payroll_rounding', 'nearest_rupee', 'payroll', 'Rounding mode for net pay (none or nearest_rupee)'),
('currency_symbol', '₹', 'payroll', 'Currency symbol used across finances and payroll'),
('payslip_visibility', 'on_finalize', 'payroll', 'When employees can view their payslip (on_finalize or on_paid)'),
('tds_label', 'TDS', 'payroll', 'Tax deduction at source display label');

