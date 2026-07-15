-- Sales Hub Stage E: hold alert tracking (run in phpMyAdmin before deploy)
-- Supports email deduplication when holds approach or pass their deadline.
-- Holds are NOT auto-released when expired — managers release manually.

ALTER TABLE `sales_properties`
  ADD COLUMN `hold_warning_sent_at` DATETIME NULL DEFAULT NULL COMMENT '24h expiry warning email sent',
  ADD COLUMN `hold_expired_alert_sent_at` DATETIME NULL DEFAULT NULL COMMENT 'Expired-hold alert email sent';
