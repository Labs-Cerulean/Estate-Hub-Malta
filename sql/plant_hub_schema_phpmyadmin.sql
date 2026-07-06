-- Plant Hub manual schema updates (run in phpMyAdmin)
-- Run each statement once against your production database.
-- Preferred deployment path: run this file during release.
-- api/plant_actions.php will only auto-apply these if sentinel columns are missing.

CREATE TABLE IF NOT EXISTS `plant_job_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT NOT NULL,
  `punch_in` DATETIME NOT NULL,
  `punch_out` DATETIME NOT NULL,
  `hours` DECIMAL(10,2) NOT NULL,
  `mode_name` VARCHAR(100) DEFAULT NULL,
  `addons_used` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE `plants` ADD COLUMN `setup_fee` DECIMAL(10,2) DEFAULT 0.00;
ALTER TABLE `plants` ADD COLUMN `nom_code_setup` VARCHAR(50) DEFAULT NULL;
ALTER TABLE `plants` ADD COLUMN `requires_driver` TINYINT(1) DEFAULT 1;
ALTER TABLE `plants` ADD COLUMN `lifecycle_type` VARCHAR(50) DEFAULT 'Standard';
ALTER TABLE `plants` ADD COLUMN `has_configurations` TINYINT(1) DEFAULT 0;
ALTER TABLE `plants` ADD COLUMN `configurations` TEXT DEFAULT NULL;
ALTER TABLE `plants` ADD COLUMN `billing_unit` VARCHAR(50) DEFAULT 'Hourly';

ALTER TABLE `plant_bookings` ADD COLUMN `apply_setup_fee` TINYINT(1) DEFAULT 0;
ALTER TABLE `plant_bookings` ADD COLUMN `final_setup_fee` DECIMAL(10,2) DEFAULT NULL;
ALTER TABLE `plant_bookings` ADD COLUMN `final_rate_fixed` DECIMAL(10,2) DEFAULT NULL;
ALTER TABLE `plant_bookings` ADD COLUMN `final_rate_var` DECIMAL(10,2) DEFAULT NULL;
ALTER TABLE `plant_bookings` ADD COLUMN `final_discount_pct` DECIMAL(5,2) DEFAULT 0.00;
ALTER TABLE `plant_bookings` ADD COLUMN `end_date` DATE DEFAULT NULL;
ALTER TABLE `plant_bookings` ADD COLUMN `active_mode` VARCHAR(100) DEFAULT NULL;
ALTER TABLE `plant_bookings` ADD COLUMN `active_addons` TEXT DEFAULT NULL;

ALTER TABLE `plant_job_sessions` ADD COLUMN `mode_name` VARCHAR(100) DEFAULT NULL;
ALTER TABLE `plant_job_sessions` ADD COLUMN `addons_used` TEXT DEFAULT NULL;

-- Add 'daily' pricing type (required for daily-rate plant assets)
ALTER TABLE `plants`
  MODIFY COLUMN `pricing_type` ENUM('hourly','fixed_then_hourly','per_trip','quote_per_meter','daily') NOT NULL DEFAULT 'hourly';
