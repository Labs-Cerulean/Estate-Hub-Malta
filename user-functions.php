<?php
/**
 * user-functions.php - Rev 2.0 Enterprise Logic
 */

// 1. CAPABILITY ENGINE
function hasPermission($capability) {
    global $pdo;
    $userId = $_SESSION['user_id'] ?? null;
    $role = $_SESSION['role'] ?? null;

    if (!$userId) return false;
    if ($role === 'admin') return true; // Level 0: All Access

    try {
        $stmt = $pdo->prepare("SELECT $capability FROM user_capabilities WHERE user_id = ?");
        $stmt->execute([$userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (bool)$val : false;
    } catch (PDOException $e) {
        error_log("Permission Error: " . $e->getMessage());
        return false; 
    }
}

function canEditProjectDetails($pdo, $projectId) {
    return hasPermission('edit_project_details') && hasProjectAccess($pdo, $projectId);
}

function canUpdateStatus($pdo, $projectId) {
    return hasPermission('update_project_status') && hasProjectAccess($pdo, $projectId);
}

// 2. PROJECT ACCESS ENGINE (Maps to your "User Access Modes" PDF)
function getAccessibleProjects($pdo, $userId = null) {
    if ($userId === null) $userId = getCurrentUserId();
    
    // Tracking Visibility Check
    $trackingFilter = "";
    if (!isAdmin() && !hasPermission('view_tracking')) {
        $trackingFilter = " AND p.is_tracking = 0 ";
    }

    // LEVEL 0: Admin (All Access)
    if (isAdmin()) {
        $stmt = $pdo->prepare("SELECT p.*, c.name as client_name FROM projects p LEFT JOIN clients c ON p.clientid = c.id ORDER BY p.created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    $user = getUserById($pdo, $userId);
    if (!$user) return [];
    
    // LEVEL 1: By Architect / Structural Engineer / STO
    $level1Roles = ['architect', 'structural_engineer', 'site_technical_officer'];
    if (in_array($user['role'], $level1Roles)) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.*, c.name as client_name FROM projects p
            LEFT JOIN clients c ON p.clientid = c.id
            LEFT JOIN project_pa_numbers ppn ON p.id = ppn.project_id
            WHERE (
                (? IS NOT NULL AND ppn.architect_id IN (SELECT id FROM professionals WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?) AND role_type = 'architect'))
                OR
                (? IS NOT NULL AND ppn.structural_engineer_id IN (SELECT id FROM professionals WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?) AND role_type = 'structural_engineer'))
            )
            AND p.id NOT IN (SELECT project_id FROM user_project_exclusions WHERE user_id = ?)
            $trackingFilter
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$user['assigned_architect_firm_id'], $user['assigned_architect_firm_id'], $user['assigned_structural_firm_id'], $user['assigned_structural_firm_id'], $userId]);
        return $stmt->fetchAll();
    }
    
    // LEVEL 2 & 3: By Client or By Specific Project (using user_client_access)
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.*, c.name as client_name FROM projects p
        LEFT JOIN clients c ON p.clientid = c.id
        LEFT JOIN user_client_access uca ON p.clientid = uca.client_id AND uca.user_id = ?
        LEFT JOIN user_project_access upa ON p.id = upa.project_id AND upa.user_id = ?
        WHERE (uca.id IS NOT NULL OR upa.id IS NOT NULL)
        AND p.id NOT IN (SELECT project_id FROM user_project_exclusions WHERE user_id = ?) 
        $trackingFilter
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$userId, $userId, $userId]);
    return $stmt->fetchAll();
}

function hasProjectAccess($pdo, $projectId) {
    $userId = getCurrentUserId();
    if (isAdmin()) return true;
    
    $stmt = $pdo->prepare("SELECT id FROM user_project_exclusions WHERE user_id = ? AND project_id = ?");
    $stmt->execute([$userId, $projectId]);
    if ($stmt->fetch()) return false;

    $user = getUserById($pdo, $userId);
    $level1Roles = ['architect', 'structural_engineer', 'site_technical_officer'];
    
    if (in_array($user['role'], $level1Roles)) {
        $stmt = $pdo->prepare("SELECT p.id FROM projects p LEFT JOIN project_pa_numbers ppn ON p.id = ppn.project_id WHERE p.id = ? AND (ppn.architect_id IN (SELECT id FROM professionals WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?)))");
        $stmt->execute([$projectId, $user['assigned_architect_firm_id']]);
        return $stmt->fetch() !== false;
    }
    
    // Client OR specific Project access
    $stmt = $pdo->prepare("
        SELECT p.id FROM projects p 
        LEFT JOIN user_client_access uca ON p.clientid = uca.client_id AND uca.user_id = ?
        LEFT JOIN user_project_access upa ON p.id = upa.project_id AND upa.user_id = ?
        WHERE p.id = ? AND (uca.id IS NOT NULL OR upa.id IS NOT NULL)
    ");
    $stmt->execute([$userId, $userId, $projectId]);
    return $stmt->fetch() !== false;
}

// 3. UTILITIES
function getUserById($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

function getAllUsers($pdo) {
    return $pdo->query("SELECT * FROM users ORDER BY username ASC")->fetchAll();
}

function getUserClients($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT c.*, uca.assigned_at FROM clients c INNER JOIN user_client_access uca ON c.id = uca.client_id WHERE uca.user_id = ? ORDER BY c.name ASC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function formatPANumber($pa) {
    $clean = str_replace(['/', ' '], '', $pa);
    if (preg_match('/^([A-Z]{2})(\d{4})(\d{2})$/', $clean, $matches)) {
        return "{$matches[1]}/{$matches[2]}/{$matches[3]}";
    }
    return $pa;
}

/**
 * Generate eApps URL from PA number
 */
function getEAppsUrl($pa) {
    // Ensure we use the raw PA number (no slashes or spaces) for the URL
    $rawPa = str_replace(['/', ' '], '', $pa);
    
    // Match PAxxxxx24, PCxxxxx24, or DNxxxxx24 format
    if (preg_match('/(PA|PC|DN)(\d+)(\d{2})/', $rawPa, $m)) {
        return "https://eapps.pa.org.mt/Case/CaseDetails?caseType={$m[1]}&casenumber={$m[2]}&caseYear={$m[3]}";
    }
    return "#";
}
?>


