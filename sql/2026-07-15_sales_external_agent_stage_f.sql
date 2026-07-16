-- Sales Hub Stage F: external_agent role (run in phpMyAdmin before deploy)
-- Read-only property library for third-party agents (show_for_sale_external on projects).

ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM(
    'admin','director','system_manager','project_manager','accountant','architect',
    'structural_engineer','services_engineer','quality_controller','pmo_staff','ohsa_rep',
    'site_technical_officer','subcontractor','condominium_agent','sales_manager','sales_agent',
    'external_agent',
    'end_customer','viewer','plant_manager','plant_driver','legal_representative'
  ) NOT NULL DEFAULT 'viewer';
