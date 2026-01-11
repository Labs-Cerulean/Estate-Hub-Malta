<?php
/**
 * Database Configuration & Setup
 * Estate Hub - Project Management System
 */

// Database configuration from environment variables (Railway)
define('DB_HOST', getenv('MYSQL_HOST') ?: 'mysql.railway.internal');
define('DB_USER', getenv('MYSQL_USER') ?: 'root');
define('DB_PASS', getenv('MYSQL_PASSWORD') ?: 'uZGDNAHVOBaMNxJflkNXtHJVHxtZmgDQ');
define('DB_NAME', getenv('MYSQL_DATABASE') ?: 'railway');

function getDB() {
    $charset = 'utf8mb4';
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . $charset;
    
    try {
        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        die('Database connection failed: ' . $e->getMessage());
    }
}

$pdo = getDB();

// ===== USERS TABLE =====
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager', 'architect', 'viewer') NOT NULL DEFAULT 'viewer',
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    is_active ENUM('Yes', 'No') DEFAULT 'Yes',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_active (is_active)
)");

// ===== USER PROJECT ACCESS TABLE =====
// Links users to projects and defines their access level per project
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_project_access (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    project_id INT NOT NULL,
    access_level ENUM('admin', 'manager', 'architect', 'viewer') NOT NULL DEFAULT 'viewer',
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_user_project (user_id, project_id),
    INDEX idx_user_id (user_id),
    INDEX idx_project_id (project_id),
    INDEX idx_access_level (access_level)
)");

// ===== USER CLIENT ACCESS TABLE =====
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_client_access (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  client_id INT NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  assigned_by INT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY unique_user_client (user_id, client_id),
  INDEX idx_user_id (user_id),
  INDEX idx_client_id (client_id)
)");

// ===== USER PROJECT EXCLUSIONS TABLE =====
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_project_exclusions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  project_id INT NOT NULL,
  excluded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  excluded_by INT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (excluded_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY unique_user_project_exclusion (user_id, project_id),
  INDEX idx_user_id (user_id),
  INDEX idx_project_id (project_id)
)");

// ===== CLIENTS TABLE =====
$pdo->exec("
CREATE TABLE IF NOT EXISTS clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    city VARCHAR(100),
    contact VARCHAR(255),
    type ENUM('in-house', '3rd-party') DEFAULT '3rd-party',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_name (name)
)");

// ===== PROJECTS TABLE =====
$pdo->exec("
CREATE TABLE IF NOT EXISTS projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    clientid INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    type ENUM('in-house', '3rd-party') NOT NULL,
    finishlevel ENUM('Common Parts Only', 'Semi Finished', 'Finished') NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (clientid) REFERENCES clients(id) ON DELETE CASCADE
)");

// ===== PROJECT PA NUMBERS TABLE =====
$pdo->exec("
CREATE TABLE IF NOT EXISTS project_pa_numbers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    pa_number VARCHAR(50) NOT NULL,
    pa_status ENUM('Endorsed', 'Approved', 'Fee Payment', 'Not Approved') DEFAULT 'Endorsed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE KEY unique_pa_per_project (project_id, pa_number)
)");

// ===== PROJECT MOBILISATION TABLE =====
$pdo->exec("
CREATE TABLE IF NOT EXISTS project_mobilisation (
    project_id INT PRIMARY KEY,
    acquisition_complete ENUM('Yes', 'No') DEFAULT 'No',
    acquisition_date DATE NULL,
    archaeologist_assigned ENUM('Yes', 'No', 'NA') DEFAULT 'NA',
    change_of_applicant ENUM('Complete', 'Not Complete', 'NA') DEFAULT 'NA',
    geological_test ENUM('Complete', 'Not Complete', 'Awaiting Result', 'NA') DEFAULT 'NA',
    condition_report_contacts ENUM('Complete', 'In Process', 'Not Started') DEFAULT 'Not Started',
    condition_reports ENUM('Complete', 'In Process', 'Not Started') DEFAULT 'Not Started',
    method_statements ENUM('Completed', 'Not Complete') DEFAULT 'Not Complete',
    insurance_status ENUM('Complete', 'In Process', 'Not Started') DEFAULT 'Not Started',
    pavement_guarantee ENUM('Complete', 'In Process', 'Not Started') DEFAULT 'Not Started',
    wellbeing_guarantee ENUM('Complete', 'In Process', 'Not Started') DEFAULT 'Not Started',
    umbrella_guarantee ENUM('Complete', 'In Process', 'Not Started') DEFAULT 'Not Started',
    responsibility_form ENUM('Complete', 'Not Complete') DEFAULT 'Not Complete',
    bca_clearance ENUM('Yes', 'No') DEFAULT 'No',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
)");

/**
 * Derive mobilisation status from project_mobilisation
 * Rules: If BCA=Yes → Mobilised; else if any In Progress/Awaiting → In Process; else Pending
 */
function deriveMobilisationStatus($pdo, $projectId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
        $stmt->execute([$projectId]);
        $mob = $stmt->fetch();
        
        if (!$mob) return 'Pending';
        if ($mob['bca_clearance'] === 'Yes') return 'Mobilised';
        
        $inProgressFields = [
            'condition_report_contacts', 'condition_reports', 'geological_test',
            'insurance_status', 'pavement_guarantee', 'wellbeing_guarantee', 'umbrella_guarantee'
        ];
        
        foreach ($inProgressFields as $field) {
            if (isset($mob[$field]) && $mob[$field] === 'In Process') return 'In Process';
        }
        
        if (isset($mob['geological_test']) && $mob['geological_test'] === 'Awaiting Result') {
            return 'In Process';
        }
        
        return 'Pending';
    } catch (Exception $e) {
        return 'Pending';
    }
}

/**
 * Get all PA numbers for a project
 */
function getProjectPANumbers($pdo, $projectId) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, pa_number, pa_status, architect_id, structural_engineer_id
            FROM project_pa_numbers
            WHERE project_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get project with client info
 */
function getProjectWithClient($pdo, $projectId) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, c.name as client_name, c.type as client_type
            FROM projects p
            LEFT JOIN clients c ON p.clientid = c.id
            WHERE p.id = ?
        ");
        $stmt->execute([$projectId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}

return $pdo;

// PROJECT SERVICES TABLE (Services Engineer Section)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS `project_services` (
    `project_id` INT PRIMARY KEY,
    `existing_meters_required` ENUM('Required', 'Not Required') DEFAULT 'Not Required',
    `existing_meters_complete` ENUM('Complete', 'Not Complete') DEFAULT 'Not Complete',
    `enemalta_deviation_required` ENUM('Required', 'Not Required') DEFAULT 'Not Required',
    `enemalta_deviation_complete` ENUM('Complete', 'Not Complete') DEFAULT 'Not Complete',
    `go_deviation_required` ENUM('Required', 'Not Required') DEFAULT 'Not Required',
    `go_deviation_complete` ENUM('Complete', 'Not Complete') DEFAULT 'Not Complete',
    `melita_deviation_required` ENUM('Required', 'Not Required') DEFAULT 'Not Required',
    `melita_deviation_complete` ENUM('Complete', 'Not Complete') DEFAULT 'Not Complete',
    `lc_lamps_required` ENUM('Required', 'Not Required') DEFAULT 'Not Required',
    `lc_lamps_complete` ENUM('Complete', 'Not Complete') DEFAULT 'Not Complete',
    `temp_elec_meter_required` ENUM('Required', 'Not Required') DEFAULT 'Not Required',
    `temp_elec_meter_complete` ENUM('Complete', 'Not Complete') DEFAULT 'Not Complete',
    `temp_wsc_meter_required` ENUM('Required', 'Not Required') DEFAULT 'Not Required',
    `temp_wsc_meter_complete` ENUM('Complete', 'Not Complete') DEFAULT 'Not Complete',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
  )
");

// Helper function to get project services data
function getProjectServices($pdo, $projectId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM project_services WHERE project_id = ?");
        $stmt->execute([$projectId]);
        $services = $stmt->fetch();
        
        // If no record exists, create one with defaults
        if (!$services) {
            $pdo->prepare("INSERT INTO project_services (project_id) VALUES (?)")->execute([$projectId]);
            $stmt->execute([$projectId]);
            $services = $stmt->fetch();
        }
        
        return $services;
    } catch (Exception $e) {
        return null;
    }
}

// Helper function to check if project has any endorsed PA
function hasEndorsedPA($pdo, $projectId) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_pa_numbers WHERE project_id = ? AND pa_status = 'Endorsed'");
        $stmt->execute([$projectId]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}
?>


