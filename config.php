<?php
/**
 * Database Configuration & Setup
 * Estate Hub - Project Management System
 */

// Database configuration strictly from environment variables
define('DB_HOST', getenv('MYSQL_HOST') ?: 'mysql.railway.internal');
define('DB_USER', getenv('MYSQL_USER') ?: 'root');
define('DB_NAME', getenv('MYSQL_DATABASE') ?: 'railway');

// Do not fallback to a hardcoded string for the password!
$db_pass = getenv('MYSQL_PASSWORD');
if ($db_pass === false) {
    die('Critical Error: Database credentials are not configured securely in the environment.');
}
define('DB_PASS', $db_pass);


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
 * 11-Stage Project Lifecycle Engine
 */
function deriveProjectStage($pdo, $projectId) {
    try {
        $stmt = $pdo->prepare("SELECT finishlevel, is_tracking FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
        if (!$project) return 'Unknown';

        $stmtMob = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
        $stmtMob->execute([$projectId]);
        $mob = $stmtMob->fetch();

        $stmtPa = $pdo->prepare("SELECT pa_number, pa_status FROM project_pa_numbers WHERE project_id = ?");
        $stmtPa->execute([$projectId]);
        $pas = $stmtPa->fetchAll();

        $stmtBlocks = $pdo->prepare("SELECT id, finishes_overall_status, compliance_submitted, compliance_certified, condominium_formed, cp_meters_installed FROM project_blocks WHERE project_id = ?");
        $stmtBlocks->execute([$projectId]);
        $blocks = $stmtBlocks->fetchAll();
        
        $levels = [];
        if (!empty($blocks)) {
            $blockIds = array_column($blocks, 'id');
            $placeholders = implode(',', array_fill(0, count($blockIds), '?'));
            $stmtLevels = $pdo->prepare("SELECT construction_status FROM block_levels WHERE block_id IN ($placeholders)");
            $stmtLevels->execute($blockIds);
            $levels = $stmtLevels->fetchAll();
        }

        $requiresFinishes = !in_array($project['finishlevel'], ['Shell', null, '']);
        $maxStage = 1; // 1 = Feasibility

        // STAGE 2 & 3: Tracking & Permit
        $hasTrkPc = false; $hasPA = false; $hasEndorsed = false;
        foreach ($pas as $pa) {
            if ($pa['pa_status'] === 'Endorsed') $hasEndorsed = true;
            $cleanPa = strtoupper(str_replace([' ', '/'], '', $pa['pa_number']));
            if (strpos($cleanPa, 'PA') === 0) $hasPA = true;
            if (strpos($cleanPa, 'TRK') === 0 || strpos($cleanPa, 'PC') === 0 || strpos($cleanPa, 'DN') === 0) $hasTrkPc = true;
        }
        if ($project['is_tracking'] == 1 || $hasTrkPc) $maxStage = max($maxStage, 2);
        if ($hasPA) $maxStage = max($maxStage, 3);

        // STAGE 4: Mobilisation
        if ($hasEndorsed) $maxStage = max($maxStage, 4);

        // STAGE 5, 6, 7: BCA Clearances (With N/A Skip Logic)
        if ($mob) {
            $demoClear = $mob['mob_demolition'] ?? 'No';
            $demoStat = $mob['demo_status'] ?? 'Pending';
            $excClear = $mob['mob_excavation'] ?? 'No';
            $excStat = $mob['excavation_status'] ?? 'Pending';
            $constClear = $mob['mob_construction'] ?? 'No';

            $demoDone = in_array($demoClear, ['NA']) || in_array($demoStat, ['Complete', 'NA']);
            $excDone = in_array($excClear, ['NA']) || in_array($excStat, ['Complete', 'NA']);

            if ($demoClear === 'Yes' || in_array($demoStat, ['In Progress'])) $maxStage = max($maxStage, 5);
            if (($excClear === 'Yes' && $demoDone) || in_array($excStat, ['In Progress'])) $maxStage = max($maxStage, 6);
            if ($constClear === 'Yes' && $excDone && $demoDone) $maxStage = max($maxStage, 7);
        }

        // STAGE 7 & 8: Block Execution
        $constInProgress = false; $allConstComplete = true;
        $finishesInProgress = false; $allFinishesComplete = true;
        $hasBlocks = count($blocks) > 0;
        $hasLevels = count($levels) > 0;

        if ($hasLevels) {
            foreach ($levels as $l) {
                if (in_array($l['construction_status'], ['In Progress', 'Complete'])) $constInProgress = true;
                if (!in_array($l['construction_status'], ['Complete', 'NA'])) $allConstComplete = false;
            }
        } else {
            $allConstComplete = false;
        }

        if ($hasBlocks) {
            foreach ($blocks as $b) {
                if (in_array($b['finishes_overall_status'], ['In Progress', 'Complete'])) $finishesInProgress = true;
                if (!in_array($b['finishes_overall_status'], ['Complete', 'NA'])) $allFinishesComplete = false;
            }
        } else {
            $allFinishesComplete = false;
        }

        if ($constInProgress) $maxStage = max($maxStage, 7);
        if ($allConstComplete && $hasLevels && $maxStage >= 7) $maxStage = max($maxStage, $requiresFinishes ? 8 : 8);
        if ($finishesInProgress && $requiresFinishes) $maxStage = max($maxStage, 8);

        // STAGE 9-11: Post-Construction Milestones
        if ($hasBlocks) {
            foreach ($blocks as $b) {
                $cSub = $b['compliance_submitted'] ?? 'No';
                $cCert = $b['compliance_certified'] ?? 'No';
                $condo = $b['condominium_formed'] ?? 'No';
                $cp = $b['cp_meters_installed'] ?? 'No';

                if (in_array($cSub, ['Yes', 'NA']) && ($allFinishesComplete || !$requiresFinishes) && $allConstComplete && $hasLevels) $maxStage = max($maxStage, 9);
                if (in_array($cCert, ['Yes', 'NA']) && $maxStage >= 9) $maxStage = max($maxStage, 10);
                if (in_array($condo, ['Yes', 'NA']) && in_array($cp, ['Yes', 'NA']) && $maxStage >= 10) $maxStage = max($maxStage, 11);
            }
        }

        $stageMap = [
            1 => 'Feasibility', 2 => 'Tracking', 3 => 'Permit', 4 => 'Mobilisation',
            5 => 'Demolition', 6 => 'Excavation', 7 => 'Construction', 8 => 'Finishes',
            9 => 'Compliance', 10 => 'Condominium', 11 => 'Handed Over'
        ];

        return $stageMap[$maxStage] ?? 'Unknown';
    } catch (Exception $e) { return 'Feasibility'; }
}

function deriveMobilisationStatus($pdo, $projectId) {
    $stage = deriveProjectStage($pdo, $projectId);
    if ($stage === 'Handed Over') return 'Mobilised';
    if (in_array($stage, ['Construction', 'Finishes', 'Compliance', 'Condominium', 'Demolition', 'Excavation'])) return 'In Process';
    return 'Pending';
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

/**
 * Get unread notification count for current user
 * Only counts logs from projects the user can access
 */


function getUnreadNotificationCount($pdo, $userId) {
    try {
        $user = getUserById($pdo, $userId);
        if (!$user) return 0;
        
        // For admins - all project logs
        if ($user['role'] === 'admin') {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT pl.id)
                FROM project_logs pl
                LEFT JOIN user_notification_reads unr 
                    ON pl.id = unr.logid AND unr.userid = ?
                WHERE unr.id IS NULL
                AND pl.user_id != ?
            ");
            $stmt->execute([$userId, $userId]);
            return (int)$stmt->fetchColumn();
        }
        
        // For architects - logs from their firm's projects
        if ($user['role'] === 'architect') {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT pl.id)
                FROM project_logs pl
                INNER JOIN projects p ON pl.project_id = p.id
                LEFT JOIN project_pa_numbers ppn ON p.id = ppn.project_id
                LEFT JOIN user_notification_reads unr 
                    ON pl.id = unr.logid AND unr.userid = ?
                LEFT JOIN user_project_exclusions upe 
                    ON p.id = upe.project_id AND upe.user_id = ?
                WHERE unr.id IS NULL
                AND pl.user_id != ?
                AND upe.id IS NULL
                AND (
                    (? IS NOT NULL AND ppn.architect_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?)
                        AND role_type = 'architect'
                    ))
                    OR
                    (? IS NOT NULL AND ppn.structural_engineer_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?)
                        AND role_type = 'structural_engineer'
                    ))
                )
            ");
            $stmt->execute([
                $userId, $userId, $userId,
                $user['assigned_architect_firm_id'], $user['assigned_architect_firm_id'],
                $user['assigned_structural_firm_id'], $user['assigned_structural_firm_id']
            ]);
            return (int)$stmt->fetchColumn();
        }
        
        // For other users - logs from client-assigned projects
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT pl.id)
            FROM project_logs pl
            INNER JOIN projects p ON pl.project_id = p.id
            INNER JOIN user_client_access uca ON p.clientid = uca.client_id
            LEFT JOIN user_notification_reads unr 
                ON pl.id = unr.logid AND unr.userid = ?
            LEFT JOIN user_project_exclusions upe 
                ON p.id = upe.project_id AND upe.user_id = ?
            WHERE uca.user_id = ?
            AND unr.id IS NULL
            AND pl.user_id != ?
            AND upe.id IS NULL
        ");
        $stmt->execute([$userId, $userId, $userId, $userId]);
        return (int)$stmt->fetchColumn();
        
    } catch (Exception $e) {
        error_log("Error in getUnreadNotificationCount: " . $e->getMessage());
        return 0;
    }
}

/**
 * Mark a notification as read
 */
function markNotificationRead($pdo, $userId, $logId) {
    try {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO user_notification_reads (userid, logid)
            VALUES (?, ?)
        ");
        return $stmt->execute([$userId, $logId]);
    } catch (Exception $e) {
        error_log("Error in markNotificationRead: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark a notification as unread
 */
function markNotificationUnread($pdo, $userId, $logId) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM user_notification_reads 
            WHERE userid = ? AND logid = ?
        ");
        return $stmt->execute([$userId, $logId]);
    } catch (Exception $e) {
        error_log("Error in markNotificationUnread: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all notifications for a user
 */
function getUserNotifications($pdo, $userId, $unreadOnly = false) {
    try {
        $user = getUserById($pdo, $userId);
        if (!$user) return [];
        
        $unreadFilter = $unreadOnly ? "AND unr.id IS NULL" : "";
        
        // Build query based on user role
        if ($user['role'] === 'admin') {
            $query = "
                SELECT 
                    pl.id,
                    pl.message,
                    pl.created_at,
                    pl.project_id,
                    p.name as project_name,
                    u.username,
                    u.first_name,
                    u.last_name,
                    c.name as client_name,
                    (unr.id IS NOT NULL) as is_read,
                    (ua.id IS NOT NULL) as is_action,
                    ua.is_complete as action_complete
                FROM project_logs pl
                INNER JOIN users u ON pl.user_id = u.id
                INNER JOIN projects p ON pl.project_id = p.id
                LEFT JOIN clients c ON p.clientid = c.id
                LEFT JOIN user_notification_reads unr 
                    ON pl.id = unr.logid AND unr.userid = ?
                LEFT JOIN user_actions ua 
                    ON pl.id = ua.logid AND ua.userid = ?
                WHERE pl.user_id != ?
                $unreadFilter
                ORDER BY pl.created_at DESC
                LIMIT 100
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$userId, $userId, $userId]);
            
        } elseif ($user['role'] === 'architect') {
            $query = "
                SELECT DISTINCT
                    pl.id,
                    pl.message,
                    pl.created_at,
                    pl.project_id,
                    p.name as project_name,
                    u.username,
                    u.first_name,
                    u.last_name,
                    c.name as client_name,
                    (unr.id IS NOT NULL) as is_read,
                    (ua.id IS NOT NULL) as is_action,
                    ua.is_complete as action_complete
                FROM project_logs pl
                INNER JOIN users u ON pl.user_id = u.id
                INNER JOIN projects p ON pl.project_id = p.id
                LEFT JOIN clients c ON p.clientid = c.id
                LEFT JOIN project_pa_numbers ppn ON p.id = ppn.project_id
                LEFT JOIN user_notification_reads unr 
                    ON pl.id = unr.logid AND unr.userid = ?
                LEFT JOIN user_actions ua 
                    ON pl.id = ua.logid AND ua.userid = ?
                LEFT JOIN user_project_exclusions upe 
                    ON p.id = upe.project_id AND upe.user_id = ?
                WHERE pl.user_id != ?
                AND upe.id IS NULL
                AND (
                    (? IS NOT NULL AND ppn.architect_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?)
                        AND role_type = 'architect'
                    ))
                    OR
                    (? IS NOT NULL AND ppn.structural_engineer_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?)
                        AND role_type = 'structural_engineer'
                    ))
                )
                $unreadFilter
                ORDER BY pl.created_at DESC
                LIMIT 100
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                $userId, $userId, $userId, $userId,
                $user['assigned_architect_firm_id'], $user['assigned_architect_firm_id'],
                $user['assigned_structural_firm_id'], $user['assigned_structural_firm_id']
            ]);
            
        } else {
            $query = "
                SELECT DISTINCT
                    pl.id,
                    pl.message,
                    pl.created_at,
                    pl.project_id,
                    p.name as project_name,
                    u.username,
                    u.first_name,
                    u.last_name,
                    c.name as client_name,
                    (unr.id IS NOT NULL) as is_read,
                    (ua.id IS NOT NULL) as is_action,
                    ua.is_complete as action_complete
                FROM project_logs pl
                INNER JOIN users u ON pl.user_id = u.id
                INNER JOIN projects p ON pl.project_id = p.id
                LEFT JOIN clients c ON p.clientid = c.id
                INNER JOIN user_client_access uca ON p.clientid = uca.client_id
                LEFT JOIN user_notification_reads unr 
                    ON pl.id = unr.logid AND unr.userid = ?
                LEFT JOIN user_actions ua 
                    ON pl.id = ua.logid AND ua.userid = ?
                LEFT JOIN user_project_exclusions upe 
                    ON p.id = upe.project_id AND upe.user_id = ?
                WHERE uca.user_id = ?
                AND pl.user_id != ?
                AND upe.id IS NULL
                $unreadFilter
                ORDER BY pl.created_at DESC
                LIMIT 100
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
        }
        
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Error in getUserNotifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark notification as action
 */
function markAsAction($pdo, $userId, $logId) {
    try {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO user_actions (userid, logid, is_complete)
            VALUES (?, ?, 'No')
        ");
        return $stmt->execute([$userId, $logId]);
    } catch (Exception $e) {
        error_log("Error in markAsAction: " . $e->getMessage());
        return false;
    }
}

/**
 * Complete an action
 */
function completeAction($pdo, $userId, $logId) {
    try {
        $stmt = $pdo->prepare("
            UPDATE user_actions 
            SET is_complete = 'Yes', completed_at = CURRENT_TIMESTAMP
            WHERE userid = ? AND logid = ?
        ");
        return $stmt->execute([$userId, $logId]);
    } catch (Exception $e) {
        error_log("Error in completeAction: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark action as incomplete
 */
function uncompleteAction($pdo, $userId, $logId) {
    try {
        $stmt = $pdo->prepare("
            UPDATE user_actions 
            SET is_complete = 'No', completed_at = NULL
            WHERE userid = ? AND logid = ?
        ");
        return $stmt->execute([$userId, $logId]);
    } catch (Exception $e) {
        error_log("Error in uncompleteAction: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user actions
 */
function getUserActions($pdo, $userId, $includeCompleted = true) {
    try {
        $completeFilter = $includeCompleted ? "" : "AND ua.is_complete = 'No'";
        
        $stmt = $pdo->prepare("
            SELECT 
                ua.id as action_id,
                pl.id as log_id,
                pl.message,
                pl.created_at as log_created_at,
                pl.project_id,
                p.name as project_name,
                c.name as client_name,
                u.username,
                u.first_name,
                u.last_name,
                ua.is_complete,
                ua.completed_at,
                ua.created_at as action_created_at
            FROM user_actions ua
            INNER JOIN project_logs pl ON ua.logid = pl.id
            INNER JOIN users u ON pl.user_id = u.id
            INNER JOIN projects p ON pl.project_id = p.id
            LEFT JOIN clients c ON p.clientid = c.id
            WHERE ua.userid = ?
            $completeFilter
            ORDER BY ua.is_complete ASC, pl.created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Error in getUserActions: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark all notifications as read for a user
 */
function markAllNotificationsRead($pdo, $userId) {
    try {
        $user = getUserById($pdo, $userId);
        if (!$user) return false;
        
        // Get all unread notification IDs for this user
        if ($user['role'] === 'admin') {
            $stmt = $pdo->prepare("
                SELECT DISTINCT pl.id
                FROM project_logs pl
                LEFT JOIN user_notification_reads unr 
                    ON pl.id = unr.logid AND unr.userid = ?
                WHERE unr.id IS NULL
                AND pl.user_id != ?
            ");
            $stmt->execute([$userId, $userId]);
            
        } elseif ($user['role'] === 'architect') {
            $stmt = $pdo->prepare("
                SELECT DISTINCT pl.id
                FROM project_logs pl
                INNER JOIN projects p ON pl.project_id = p.id
                LEFT JOIN project_pa_numbers ppn ON p.id = ppn.project_id
                LEFT JOIN user_notification_reads unr 
                    ON pl.id = unr.logid AND unr.userid = ?
                LEFT JOIN user_project_exclusions upe 
                    ON p.id = upe.project_id AND upe.user_id = ?
                WHERE unr.id IS NULL
                AND pl.user_id != ?
                AND upe.id IS NULL
                AND (
                    (? IS NOT NULL AND ppn.architect_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?)
                        AND role_type = 'architect'
                    ))
                    OR
                    (? IS NOT NULL AND ppn.structural_engineer_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?)
                        AND role_type = 'structural_engineer'
                    ))
                )
            ");
            $stmt->execute([
                $userId, $userId, $userId,
                $user['assigned_architect_firm_id'], $user['assigned_architect_firm_id'],
                $user['assigned_structural_firm_id'], $user['assigned_structural_firm_id']
            ]);
            
        } else {
            $stmt = $pdo->prepare("
                SELECT DISTINCT pl.id
                FROM project_logs pl
                INNER JOIN projects p ON pl.project_id = p.id
                INNER JOIN user_client_access uca ON p.clientid = uca.client_id
                LEFT JOIN user_notification_reads unr 
                    ON pl.id = unr.logid AND unr.userid = ?
                LEFT JOIN user_project_exclusions upe 
                    ON p.id = upe.project_id AND upe.user_id = ?
                WHERE uca.user_id = ?
                AND unr.id IS NULL
                AND pl.user_id != ?
                AND upe.id IS NULL
            ");
            $stmt->execute([$userId, $userId, $userId, $userId]);
        }
        
        $logIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($logIds)) {
            return true; // No unread notifications
        }
        
        // Insert all as read in batch
        $placeholders = str_repeat('(?,?),', count($logIds) - 1) . '(?,?)';
        $values = [];
        foreach ($logIds as $logId) {
            $values[] = $userId;
            $values[] = $logId;
        }
        
        $insertStmt = $pdo->prepare("
            INSERT IGNORE INTO user_notification_reads (userid, logid)
            VALUES $placeholders
        ");
        
        return $insertStmt->execute($values);
        
    } catch (Exception $e) {
        error_log("Error in markAllNotificationsRead: " . $e->getMessage());
        return false;
    }
}

return $pdo;
?>




