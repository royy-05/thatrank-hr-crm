-- ThatRank HR Database Migration 008
-- Per-Employee Punch Requirements & Location Anomaly Threshold Settings
-- Columns: users.require_camera, users.require_location
-- Setting: anomaly_distance_threshold_km

-- 1. Add require_camera and require_location to users table if they do not exist
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `require_camera` TINYINT(1) NOT NULL DEFAULT 1 AFTER `consent_accepted_at`,
  ADD COLUMN IF NOT EXISTS `require_location` TINYINT(1) NOT NULL DEFAULT 1 AFTER `require_camera`;

-- 2. Ensure all existing users default to 1 (both required)
UPDATE `users`
SET `require_camera` = 1, `require_location` = 1
WHERE `require_camera` IS NULL OR `require_location` IS NULL;

-- 3. Add default anomaly distance threshold setting (default 100 km)
INSERT INTO `company_settings` (`setting_key`, `setting_value`, `setting_group`, `description`)
VALUES ('anomaly_distance_threshold_km', '100', 'privacy', 'Distance threshold in km to flag punch location anomaly.')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

