-- OHSA standard service catalogue (run in phpMyAdmin after pm_cohesion_phpmyadmin.sql)
-- Adds the 16 default OHSA services to sales_standard_items.
-- Safe to re-run: skips if any OHSA items already exist.

INSERT INTO `sales_standard_items` (`quote_type`, `category`, `description`, `unit`, `default_rate`, `sort_order`, `is_active`)
SELECT * FROM (
    SELECT 'OHSA' AS quote_type, '1 - Documentation' AS category, 'Preparation and submission of initial documentation for construction projects' AS description, 'lump_sum' AS unit, 350.00 AS default_rate, 10 AS sort_order, 1 AS is_active
    UNION ALL SELECT 'OHSA', '2 - Site Inspections', 'Site inspection and preparation of report — normal size sites (max 45 min)', 'visit', 50.00, 20, 1
    UNION ALL SELECT 'OHSA', '2 - Site Inspections', 'Site inspection and preparation of report — large size sites (max 1 hr; extra at €35/hr)', 'visit', 70.00, 21, 1
    UNION ALL SELECT 'OHSA', '3 - Training', 'General OHS training', 'participant', 45.00, 30, 1
    UNION ALL SELECT 'OHSA', '3 - Training', 'Policies and procedures training to management/workers', 'participant', 45.00, 31, 1
    UNION ALL SELECT 'OHSA', '1 - Documentation', 'Preparation of Company policy and procedures for OHSMS (€250–350 per procedure)', 'procedure', 300.00, 11, 1
    UNION ALL SELECT 'OHSA', '1 - Documentation', 'Preparation of OHSMS documentation — registers, forms, permits, checklists', 'document', 150.00, 12, 1
    UNION ALL SELECT 'OHSA', '1 - Documentation', 'Preparation of evacuation procedure — small to medium premises', 'procedure', 250.00, 13, 1
    UNION ALL SELECT 'OHSA', '1 - Documentation', 'Preparation of evacuation procedure — large premises (€350–500)', 'procedure', 425.00, 14, 1
    UNION ALL SELECT 'OHSA', '4 - Risk Assessments', 'Preparation of a general risk assessment', 'assessment', 250.00, 40, 1
    UNION ALL SELECT 'OHSA', '4 - Risk Assessments', 'Preparation of specific risk assessment / SWMS / RAMS (€350–450)', 'assessment', 400.00, 41, 1
    UNION ALL SELECT 'OHSA', '4 - Risk Assessments', 'General risk assessment — small to medium workplaces', 'assessment', 450.00, 42, 1
    UNION ALL SELECT 'OHSA', '4 - Risk Assessments', 'General risk assessment — large workplaces (€550–850)', 'assessment', 700.00, 43, 1
    UNION ALL SELECT 'OHSA', '4 - Risk Assessments', 'General risk assessment — small to medium Hotels (€550–750)', 'assessment', 650.00, 44, 1
    UNION ALL SELECT 'OHSA', '4 - Risk Assessments', 'General risk assessment — large and complex Hotels (€850–1,250)', 'assessment', 1050.00, 45, 1
    UNION ALL SELECT 'OHSA', '5 - Consultancy', 'General OHS Consultancy', 'hour', 35.00, 50, 1
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `sales_standard_items` WHERE `quote_type` = 'OHSA' LIMIT 1);

INSERT IGNORE INTO `sales_default_terms` (`quote_type`, `terms_text`) VALUES
('OHSA', 'All prices quoted are exclusive of VAT. Payment terms: 50% on acceptance, balance on completion unless otherwise agreed.');
