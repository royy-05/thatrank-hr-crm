-- ThatRank HR Database Reset Script
-- Run this script in phpMyAdmin or via MySQL CLI to wipe all tables and re-apply clean baseline data.

USE `thatrank_hr_software`;

SET FOREIGN_KEY_CHECKS = 0;

-- Wipe all existing rows in reverse dependency order
TRUNCATE TABLE `roles`;
TRUNCATE TABLE `role_permissions`;
TRUNCATE TABLE `payslips`;
TRUNCATE TABLE `payroll_runs`;
TRUNCATE TABLE `salary_structures`;
TRUNCATE TABLE `salary_components`;
TRUNCATE TABLE `notifications_queue`;
TRUNCATE TABLE `events`;
TRUNCATE TABLE `user_sessions`;
TRUNCATE TABLE `community_poll_votes`;
TRUNCATE TABLE `community_poll_options`;
TRUNCATE TABLE `community_polls`;
TRUNCATE TABLE `community_comments`;
TRUNCATE TABLE `community_post_reactions`;
TRUNCATE TABLE `community_posts`;
TRUNCATE TABLE `attendance_corrections`;
TRUNCATE TABLE `leave_requests`;
TRUNCATE TABLE `comp_off_requests`;
TRUNCATE TABLE `leave_balances`;
TRUNCATE TABLE `leave_types`;
TRUNCATE TABLE `punch_logs`;
TRUNCATE TABLE `heartbeats`;
TRUNCATE TABLE `attendance_records`;
TRUNCATE TABLE `holidays`;
TRUNCATE TABLE `company_settings`;
TRUNCATE TABLE `audit_log`;
TRUNCATE TABLE `password_resets`;
TRUNCATE TABLE `teams`;
TRUNCATE TABLE `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. Insert Base Administrator
-- Credentials: admin@thatrank.com / admin123
INSERT INTO `users` (
    `id`, `name`, `email`, `password_hash`, `role`, `job_title`, `department`,
    `manager_id`, `avatar_initials`, `avatar_color`, `joined_date`, `status`,
    `consent_accepted_at`, `require_camera`, `require_location`, `must_change_password`
) VALUES (
    1,
    'System Administrator',
    'admin@thatrank.com',
    '$2y$10$Ws0hIKPLVfeldm2my0bUauQdCNdhEE5dzaM0KDiOjwX9xeRYU8n5e',
    'admin',
    'Administrator',
    'Leadership',
    NULL,
    'AD',
    'var(--ink)',
    CURDATE(),
    'active',
    NULL,
    1,
    1,
    0
);

-- 2. Default Leave Balances for Admin (Casual 12, Sick 6, Earned 15, Comp-off 0)
INSERT INTO `leave_balances` (
    `user_id`, `year`, `casual_total`, `casual_used`, `casual_accrued`,
    `sick_total`, `sick_used`, `sick_accrued`,
    `earned_total`, `earned_used`, `earned_carried`,
    `comp_off_total`, `comp_off_used`
) VALUES (
    1, YEAR(CURDATE()), 12.0, 0.0, 12.0, 6.0, 0.0, 6.0, 15.0, 0.0, 0.0, 0.0, 0.0
);

-- 2a. Default Leave Types
INSERT INTO `leave_types` (`name`, `code`, `days_per_year`, `is_paid`, `is_active`) VALUES
('Casual Leave', 'casual', 12.0, 1, 1),
('Sick Leave', 'sick', 6.0, 1, 1),
('Earned Leave', 'earned', 15.0, 1, 1),
('Comp-off', 'comp_off', 0.0, 1, 1),
('Work from Home', 'wfh', 0.0, 1, 1);

-- 3. Base Company Settings
INSERT INTO `company_settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('work_week', 'Monday to Friday', 'work_rules', 'Days counted as working days.'),
('daily_target_hours', '9', 'work_rules', 'Standard daily hours for overtime and chart.'),
('late_grace_minutes', '15', 'work_rules', 'Clocking in within this window is not marked late.'),
('shift_start_time', '10:00:00', 'work_rules', 'Standard shift start time.'),
('shift_end_time', '19:00:00', 'work_rules', 'Standard shift end time.'),
('late_fine_enabled', '0', 'work_rules', 'Late marks are recorded but never deducted from pay.'),
('auto_clock_out_time', '23:00:00', 'work_rules', 'Close forgotten sessions at this time.'),
('casual_leave_quota', '12', 'leave_policy', 'Casual leave days allowed per year.'),
('sick_leave_quota', '6', 'leave_policy', 'Sick leave days allowed per year.'),
('earned_leave_quota', '15', 'leave_policy', 'Earned leave days allowed per year.'),
('manager_approval_required', '1', 'leave_policy', 'Every request waits for manager approval.'),
('carry_forward_leave', '1', 'leave_policy', 'Carry unused earned leave into next year.'),
('selfie_on_punch', '1', 'privacy', 'Selfie recorded on each punch.'),
('location_on_punch', '1', 'privacy', 'Location coordinates recorded on each punch.'),
('anomaly_distance_threshold_km', '100', 'privacy', 'Distance threshold in km to flag punch location anomaly.'),
('delete_selfies_after_days', '90', 'privacy', 'Selfies automatically deleted after this duration.'),
('consent_screen_required', '1', 'privacy', 'Consent agreement required before first punch.'),
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

-- 3b. Base Roles
INSERT INTO `roles` (`slug`, `name`, `description`, `is_system`) VALUES
('admin', 'Administrator', 'Full system access and company settings management', 1),
('manager', 'Manager', 'Team lead with approval and reporting privileges', 1),
('employee', 'Employee', 'Standard staff member with workspace and punching access', 1);

-- 3c. Base Salary Components
INSERT INTO `salary_components` (`id`, `name`, `type`, `calc_type`, `default_value`, `is_statutory`, `applies_to_all`, `show_on_payslip`, `sort_order`, `active`) VALUES
(1, 'Basic', 'earning', 'fixed', 30000.00, 'none', 1, 1, 1, 1),
(2, 'House rent allowance', 'earning', 'percent_of_basic', 50.00, 'none', 1, 1, 2, 1),
(3, 'Special allowance', 'earning', 'fixed', 13400.00, 'none', 1, 1, 3, 1),
(4, 'Internet allowance', 'earning', 'fixed', 1600.00, 'none', 1, 1, 4, 1),
(5, 'Provident fund', 'deduction', 'percent_of_basic', 12.00, 'pf', 1, 1, 5, 1),
(6, 'ESI', 'deduction', 'percent_of_gross', 0.75, 'esi', 1, 1, 6, 1),
(7, 'Professional tax', 'deduction', 'fixed', 200.00, 'pt', 1, 1, 7, 1),
(8, 'TDS', 'deduction', 'fixed', 0.00, 'tds', 1, 1, 8, 1);

-- 4. Initial Audit Log
INSERT INTO `audit_log` (`user_id`, `action`, `target_type`, `target_id`, `created_at`) VALUES
(1, 'database_reset', 'system', 1, NOW());
