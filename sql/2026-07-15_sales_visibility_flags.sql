-- Sales Hub Stage C: project visibility flags (run in phpMyAdmin before deploy)
-- Existing projects default to visible in-house; external library off until enabled.

ALTER TABLE `projects`
  ADD COLUMN `show_for_sale` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Show in in-house Sales Hub map/list',
  ADD COLUMN `show_for_sale_external` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Show in external agent library (Stage F)';
