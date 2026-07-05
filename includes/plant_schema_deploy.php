<?php
/**
 * Plant Hub schema auto-deploy — safe to re-run on every request.
 * Prefer sql/plant_hub_schema_phpmyadmin.sql for manual production deploys.
 */
function plantDeploySchema(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS plant_job_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            punch_in DATETIME NOT NULL,
            punch_out DATETIME NOT NULL,
            hours DECIMAL(10,2) NOT NULL,
            mode_name VARCHAR(100) DEFAULT NULL,
            addons_used TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("ALTER TABLE plants ADD COLUMN setup_fee DECIMAL(10,2) DEFAULT 0.00");
        $pdo->exec("ALTER TABLE plants ADD COLUMN nom_code_setup VARCHAR(50) DEFAULT NULL");
        $pdo->exec("ALTER TABLE plants ADD COLUMN requires_driver TINYINT(1) DEFAULT 1");
        $pdo->exec("ALTER TABLE plants ADD COLUMN lifecycle_type VARCHAR(50) DEFAULT 'Standard'");
        $pdo->exec("ALTER TABLE plants ADD COLUMN has_configurations TINYINT(1) DEFAULT 0");
        $pdo->exec("ALTER TABLE plants ADD COLUMN configurations TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE plants ADD COLUMN billing_unit VARCHAR(50) DEFAULT 'Hourly'");
        $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN apply_setup_fee TINYINT(1) DEFAULT 0");
        $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN final_setup_fee DECIMAL(10,2) DEFAULT NULL");
        $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN final_rate_fixed DECIMAL(10,2) DEFAULT NULL");
        $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN final_rate_var DECIMAL(10,2) DEFAULT NULL");
        $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN final_discount_pct DECIMAL(5,2) DEFAULT 0.00");
        $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN end_date DATE DEFAULT NULL");
        $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN active_mode VARCHAR(100) DEFAULT NULL");
        $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN active_addons TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE plant_job_sessions ADD COLUMN mode_name VARCHAR(100) DEFAULT NULL");
        $pdo->exec("ALTER TABLE plant_job_sessions ADD COLUMN addons_used TEXT DEFAULT NULL");
    } catch (PDOException $e) {
        // Duplicate column / table errors are expected on repeat runs.
    }
}
