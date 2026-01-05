<?php
/**
 * Estate Hub - Database Configuration & Initialization
 * Updated with new mobilisation and user management tables
 */

define('DB_HOST', getenv('MYSQLHOST') ?: 'mysql.railway.internal');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: 'uZGDNAHVOBaMNxJflkNXtHJVHxtZmgDQ');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'railway');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO(
                $dsn,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }

/**
 * CLIENTS TABLE
 * Stores client information
 */
$pdo->exec("CREATE TABLE IF NOT EXISTS clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    city VARCHAR(100),
    contact VARCHAR(255),
    type ENUM('in-house','3rd-party') DEFAULT '3rd-party',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_name (name)
)");

/**
 * PROJECTS TABLE - UPDATED FOR NEW WORKFLOW
 * Stores project information
 * Note: status column removed (now auto-calculated from mobilisation steps)
 * bca_status renamed to pa_status for backwards compatibility
 */
$pdo->exec("CREATE TABLE IF NOT EXISTS projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    pa_number VARCHAR(50),
    pa_status VARCHAR(50),
    type ENUM('in-house','3rd-party') NOT NULL,
    finish_level ENUM('Common Parts Only','Semi Finished','Finished') NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_client (client_id),
    INDEX idx_created (created_at)
)");

/**
 * PA_NUMBERS TABLE - NEW
 * Stores multiple PA numbers for each project
 * Each project can have multiple PA numbers, each with independent status
 */
$pdo->exec("CREATE TABLE IF NOT EXISTS pa_numbers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    pa_number VARCHAR(50) NOT NULL,
    pa_status VARCHAR(50) DEFAULT 'Pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE KEY unique_pa_per_project (project_id, pa_number),
    INDEX idx_project (project_id),
    INDEX idx_status (pa_status)
)");

/**
 * MOBILISATION_STEP_TEMPLATES TABLE - NEW
 * Defines the default mobilisation steps for all projects
 * Can be customized by editing this table
 */
$pdo->exec("CREATE TABLE IF NOT EXISTS mobilisation_step_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    step_order INT NOT NULL UNIQUE,
    step_name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

/**
 * MOBILISATION_STEPS TABLE - NEW
 * Tracks the status of each mobilisation step for each project
 * Status values: 'Not Started', 'In Progress', 'Completed'
 * Mobilisation Status is calculated from these steps:
 *   - Complete: All steps are Completed
 *   - In Progress: At least one step is started but not all completed
 *   - Not Started: No steps have been started
 */
$pdo->exec("CREATE TABLE IF NOT EXISTS mobilisation_steps (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    step_order INT NOT NULL,
    step_name VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('Not Started', 'In Progress', 'Completed') DEFAULT 'Not Started',
    completed_date DATETIME NULL,
    completed_by VARCHAR(255) NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE KEY unique_step_per_project (project_id, step_order),
    INDEX idx_project (project_id),
    INDEX idx_status (status),
    INDEX idx_completed_date (completed_date)
)");

/**
 * USER_ROLES TABLE - NEW
 * Manages user permissions and roles
 * Roles: 'admin' (full access), 'manager' (can update steps if enabled), 'user' (view-only)
 * can_update_status: Boolean flag to allow/prevent step status updates
 */
$pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) UNIQUE NOT NULL,
    role ENUM('admin', 'manager', 'user') DEFAULT 'user',
    can_update_status BOOLEAN DEFAULT FALSE,
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role)
)");

/**
 * Insert default mobilisation steps if table is empty
 */
$template_count = $pdo->query("SELECT COUNT(*) as count FROM mobilisation_step_templates")->fetch()['count'];

if ($template_count === 0) {
    $default_steps = [
        [1, 'Site Preparation', 'Prepare site and infrastructure'],
        [2, 'Team Assembly', 'Assemble and brief team members'],
        [3, 'Resource Allocation', 'Allocate necessary resources'],
        [4, 'Documentation', 'Complete all required documentation'],
        [5, 'Stakeholder Approval', 'Obtain stakeholder sign-off'],
        [6, 'Mobilisation Complete', 'Project fully mobilised'],
    ];
    
    $stmt = $pdo->prepare("INSERT INTO mobilisation_step_templates (step_order, step_name, description) VALUES (?, ?, ?)");
    foreach ($default_steps as $step) {
        try {
            $stmt->execute($step);
        } catch (PDOException $e) {
            // Skip if already exists
        }
    }
}
    }
return $pdo;
?>
