-- Resale pricing mode + hold revert for sold units listed for 3rd-party resale.
-- Run in phpMyAdmin before deploying the matching application PR.

ALTER TABLE sales_properties
    ADD COLUMN resale_pricing_mode ENUM('single', 'split') DEFAULT NULL AFTER resale_price,
    ADD COLUMN resale_shell_price DECIMAL(12,2) DEFAULT NULL AFTER resale_pricing_mode,
    ADD COLUMN resale_finishes_price DECIMAL(12,2) DEFAULT NULL AFTER resale_shell_price,
    ADD COLUMN resale_prior_status VARCHAR(50) DEFAULT NULL AFTER resale_finishes_price,
    ADD COLUMN status_before_hold VARCHAR(50) DEFAULT NULL AFTER resale_prior_status;
