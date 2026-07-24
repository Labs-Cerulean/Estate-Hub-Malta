-- Project flag: enable manual unit status management (non-CSV sites)
-- Run in phpMyAdmin before deploy. Default 0 = CSV/sync managed.

ALTER TABLE `projects`
  ADD COLUMN `manage_manually` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'When 1, Sales Project Manager may set Available/Proceeding/Sold - POS'
    AFTER `show_for_sale_external`;
