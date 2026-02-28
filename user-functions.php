<?php
/**
 * user-functions.php - Rev 2.0 Enterprise Logic (Complete)
 * Contains all capability, access, and user utility functions.
 */

// ==========================================
// 1. CAPABILITY ENGINE
// ==========================================

function hasPermission($capability) {
    global $pdo;
    $userId = $_SESSION['user_id'] ?? null;
    $role = $_SESSION['role'] ?? null;

    if (!$userId) return false;
    if ($role === 'admin') return true; // Level 0: All Access

    // Rev 2.0 Hard Constraint: Viewers can NEVER edit/add
    $editCapabilities = ['add_project', 'edit_project_details', 'update_project_status', 'manage_clients', 'manage_professionals', 'manage_users', 'manage_subcontractors'];
    if ($role === 'viewer' && in_array($capability, $editCapabilities)) {
        return false;
    }

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

// ==========================================
// 2. PROJECT ACCESS ENGINE (Level 1, 2, 3)
// ==========================================

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
    
    // LEVEL 2 & 3: By Client or By Specific Project
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
    
    $stmt = $pdo->prepare("
        SELECT p.id FROM projects p 
        LEFT JOIN user_client_access uca ON p.clientid = uca.client_id AND uca.user_id = ?
        LEFT JOIN user_project_access upa ON p.id = upa.project_id AND upa.user_id = ?
        WHERE p.id = ? AND (uca.id IS NOT NULL OR upa.id IS NOT NULL)
    ");
    $stmt->execute([$userId, $userId, $projectId]);
    return $stmt->fetch() !== false;
}

// ==========================================
// 3. USER MANAGEMENT UTILITIES
// ==========================================

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

function getUserExcludedProjects($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as client_name, upe.excluded_at
        FROM projects p
        LEFT JOIN clients c ON p.clientid = c.id
        INNER JOIN user_project_exclusions upe ON p.id = upe.project_id
        WHERE upe.user_id = ?
        ORDER BY p.name ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function assignUserToClient($pdo, $userId, $clientId) {
    try {
        $check = $pdo->prepare("SELECT id FROM user_client_access WHERE user_id = ? AND client_id = ?");
        $check->execute([$userId, $clientId]);
        if ($check->fetch()) return true;
        
        $stmt = $pdo->prepare("INSERT INTO user_client_access (user_id, client_id, assigned_by) VALUES (?, ?, ?)");
        return $stmt->execute([$userId, $clientId, getCurrentUserId()]);
    } catch (PDOException $e) { return false; }
}

function removeUserFromClient($pdo, $userId, $clientId) {
    try {
        $stmt = $pdo->prepare("DELETE FROM user_client_access WHERE user_id = ? AND client_id = ?");
        return $stmt->execute([$userId, $clientId]);
    } catch (PDOException $e) { return false; }
}

function excludeProjectFromUser($pdo, $userId, $projectId) {
    try {
        $check = $pdo->prepare("SELECT id FROM user_project_exclusions WHERE user_id = ? AND project_id = ?");
        $check->execute([$userId, $projectId]);
        if ($check->fetch()) return true;
        
        $stmt = $pdo->prepare("INSERT INTO user_project_exclusions (user_id, project_id, excluded_by) VALUES (?, ?, ?)");
        return $stmt->execute([$userId, $projectId, getCurrentUserId()]);
    } catch (PDOException $e) { return false; }
}

function removeProjectExclusion($pdo, $userId, $projectId) {
    try {
        $stmt = $pdo->prepare("DELETE FROM user_project_exclusions WHERE user_id = ? AND project_id = ?");
        return $stmt->execute([$userId, $projectId]);
    } catch (PDOException $e) { return false; }
}

function changePassword($pdo, $userId, $newPassword) {
    try {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        return $stmt->execute([$passwordHash, $userId]);
    } catch (PDOException $e) { return false; }
}

function deleteUser($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$userId]);
    } catch (PDOException $e) { return false; }
}

// ==========================================
// 4. FIRM / PROFESSIONAL UTILITIES
// ==========================================

function getAllFirms($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT firm_name, role_type FROM professionals ORDER BY firm_name ASC, role_type ASC");
    $allFirms = $stmt->fetchAll();
    
    $firms = ['architects' => [], 'structural_engineers' => []];
    foreach ($allFirms as $firm) {
        if ($firm['role_type'] === 'architect' && !in_array($firm['firm_name'], $firms['architects'])) {
            $firms['architects'][] = $firm['firm_name'];
        }
        if ($firm['role_type'] === 'structural_engineer' && !in_array($firm['firm_name'], $firms['structural_engineers'])) {
            $firms['structural_engineers'][] = $firm['firm_name'];
        }
    }
    return $firms;
}

function getProfessionalIdByFirm($pdo, $firmName, $roleType) {
    $stmt = $pdo->prepare("SELECT id FROM professionals WHERE firm_name = ? AND role_type = ? LIMIT 1");
    $stmt->execute([$firmName, $roleType]);
    $result = $stmt->fetch();
    return $result ? $result['id'] : null;
}

// ==========================================
// 5. FORMATTING HELPERS
// ==========================================

function formatPANumber($pa) {
    $clean = str_replace(['/', ' '], '', $pa);
    if (preg_match('/^([A-Z]{2})(\d{4})(\d{2})$/', $clean, $matches)) {
        return "{$matches[1]}/{$matches[2]}/{$matches[3]}";
    }
    return $pa;
}

function getEAppsUrl($pa) {
    $rawPa = str_replace(['/', ' '], '', $pa);
    if (preg_match('/(PA|PC|DN)(\d+)(\d{2})/', $rawPa, $m)) {
        return "https://eapps.pa.org.mt/Case/CaseDetails?caseType={$m[1]}&casenumber={$m[2]}&caseYear={$m[3]}";
    }
    return "#";
}
