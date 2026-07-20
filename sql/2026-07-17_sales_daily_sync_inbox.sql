-- Sales Hub: pending daily sync CSV library + batch run audit (run in phpMyAdmin before deploy)
-- Depends on: existing sync_translations, sales_property_logs

CREATE TABLE IF NOT EXISTS sales_daily_sync_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_date DATE NOT NULL COMMENT 'Business date of the master report',
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ingest_source ENUM('email', 'manual', 'system') NOT NULL DEFAULT 'email',
    r2_storage_key VARCHAR(512) NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    file_status ENUM('ready', 'superseded', 'applied') NOT NULL DEFAULT 'ready',
    superseded_by_id INT UNSIGNED NULL DEFAULT NULL,
    applied_run_id INT UNSIGNED NULL DEFAULT NULL,
    original_filename VARCHAR(255) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_file_status_date (file_status, report_date DESC),
    KEY idx_received (received_at DESC),
    UNIQUE KEY uq_content_sha256 (content_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_daily_sync_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sync_file_id INT UNSIGNED NULL DEFAULT NULL,
    started_by_user_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL DEFAULT NULL,
    outcome ENUM('in_progress', 'committed', 'failed', 'cancelled') NOT NULL DEFAULT 'in_progress',
    price_updates_count INT UNSIGNED NOT NULL DEFAULT 0,
    status_updates_count INT UNSIGNED NOT NULL DEFAULT 0,
    translation_updates_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(255) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_runs_user (started_by_user_id),
    KEY idx_runs_file (sync_file_id),
    KEY idx_runs_started (started_at DESC),
    CONSTRAINT fk_sync_runs_file
        FOREIGN KEY (sync_file_id) REFERENCES sales_daily_sync_files (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
