-- Plant Hub manual schema updates (run in phpMyAdmin)
-- Run each statement once against your production database.
-- Column additions are also auto-deployed from api/plant_actions.php on first API hit.

-- Add 'daily' pricing type (required for daily-rate plant assets)
ALTER TABLE `plants`
  MODIFY COLUMN `pricing_type` ENUM('hourly','fixed_then_hourly','per_trip','quote_per_meter','daily') NOT NULL DEFAULT 'hourly';
