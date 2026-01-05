<?php
session_start();

$host = getenv('MYSQLHOST') ?: 'mysql.railway.internal';
$db = getenv('MYSQLDATABASE') ?: 'railway';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: 'uZGDNAHVOBaMNxJflkNXtHJVHxtZmgDQ';
$port = getenv('DB_PORT') ?: 3306;

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// CLIENTS TABLE
$pdo->exec("CREATE TABLE IF NOT EXISTS clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    city VARCHAR(100),
    contact VARCHAR(255),
    type ENUM('in-house','3rd-party') DEFAULT '3rd-party',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_name (name)
)");

// PROJECTS TABLE
$pdo->exec("CREATE TABLE IF NOT EXISTS projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    type ENUM('in-house','3rd-party') NOT NULL,
    finish_level ENUM('Common Parts Only','Semi Finished','Finished') NULL,
    mobilisation_status VARCHAR(50) DEFAULT 'Not Started',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
)");

// PA NUMBERS TABLE - Multiple PA numbers per project with individual status
$pdo->exec("CREATE TABLE IF NOT EXISTS pa_numbers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    pa_number VARCHAR(50) NOT NULL,
    pa_status ENUM('endorsed','approved','fee payment','not approved') DEFAULT 'endorsed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_pa_per_project (project_id, pa_number),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
)");

// MOBILISATION STATUS TRACKING TABLE
$pdo->exec("CREATE TABLE IF NOT EXISTS mobilisation_steps (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    
    -- In-House Project Only
    acquisition_complete TINYINT(1) DEFAULT 0,
    acquisition_target_date DATE NULL,
    acquisition_actual_date DATE NULL,
    
    -- Applicable to All Projects
    archeologist_assigned ENUM('Yes','No','NA') DEFAULT 'NA',
    change_of_applicant ENUM('Complete','Not Complete','NA') DEFAULT 'NA',
    geological_test ENUM('Complete','Not Complete','Awaiting Result','NA') DEFAULT 'NA',
    condition_report_contacts ENUM('Complete','In Process','Not Started') DEFAULT 'Not Started',
    condition_reports ENUM('Complete','In Process','Not Started') DEFAULT 'Not Started',
    
    -- Dependent on Condition Reports + Geological Test Complete
    method_statements ENUM('Complete','Not Complete') DEFAULT 'Not Complete',
    
    -- Dependent on Method Statements Complete
    insurance ENUM('Complete','In Process','Not Started') DEFAULT 'Not Started',
    pavement_guarantee ENUM('Complete','In Process','Not Started') DEFAULT 'Not Started',
    wellbeing_guarantee ENUM('Complete','In Process','Not Started') DEFAULT 'Not Started',
    umbrella_guarantee ENUM('Complete','In Process','Not Started') DEFAULT 'Not Started',
    
    -- Independent
    responsibility_form ENUM('Complete','Not Complete') DEFAULT 'Not Complete',
    
    -- BCA Clearance - Auto-calculated based on dependencies
    bca_clearance ENUM('YES','NO') DEFAULT 'NO',
    
    updated_by VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE KEY unique_project_steps (project_id)
)");

// USERS TABLE for role-based access control
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','coordinator','viewer') DEFAULT 'viewer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Activity Log for tracking changes
$pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    user VARCHAR(255),
    action VARCHAR(255),
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
)");

return $pdo;
?>
