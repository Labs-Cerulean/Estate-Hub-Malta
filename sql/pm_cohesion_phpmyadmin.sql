-- PM Cohesion manual schema updates (run in phpMyAdmin)
-- Keeps existing ENUM columns — only adds new allowed values.
-- Run each statement once against the `railway` database.

-- 1) OHSA quote type (fixes quote creation + standard rates seed)
ALTER TABLE `sales_quotes`
  MODIFY COLUMN `quote_type` ENUM('Demolition_Excavation','Construction','Finishes','OHSA') NOT NULL;

ALTER TABLE `sales_standard_items`
  MODIFY COLUMN `quote_type` ENUM('Demolition_Excavation','Construction','Finishes','OHSA') NOT NULL;

ALTER TABLE `sales_default_terms`
  MODIFY COLUMN `quote_type` ENUM('Demolition_Excavation','Construction','Finishes','OHSA') NOT NULL;

-- 2) Legal representative role (fixes user save when role = legal_representative)
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM(
    'admin','director','system_manager','project_manager','accountant','architect',
    'structural_engineer','services_engineer','quality_controller','pmo_staff','ohsa_rep',
    'site_technical_officer','subcontractor','condominium_agent','sales_manager','sales_agent',
    'end_customer','viewer','plant_manager','plant_driver','legal_representative'
  ) NOT NULL DEFAULT 'viewer';

-- 3) Optional: seed OHSA default terms (safe to re-run)
INSERT IGNORE INTO `sales_default_terms` (`quote_type`, `terms_text`) VALUES
('OHSA', 'All prices quoted are exclusive of VAT. Payment terms: 50% on acceptance, balance on completion unless otherwise agreed.');
