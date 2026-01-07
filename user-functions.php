<?php
/**
 * User Management & Authorization Functions
 * Estate Hub - Project Management System
 */



/**
 * Require user to be logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}



function getCurrentRole() {
    $userrole = $_SESSION['role'];
    return ($userrole);
}

/**
 * Check if current user has admin privileges
 */
function isAdmin() {
    return hasRole('admin');
}

/**
 * Check if current user is an admin or manager
 */
function canEdit() {
    $role = $_SESSION['role'] ?? null;
    return in_array($role, ['admin', 'manager']);
}

/**
 * Check if current user can edit (admin/manager role globally)
 */
function canEditGlobally() {
    return isAdmin() || hasRole('manager');
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user's full name
 */
function getCurrentUserFullName() {
    $first = $_SESSION['first_name'] ?? '';
    $last = $_SESSION['last_name'] ?? '';
    return trim($first . ' ' . $last) ?: $_SESSION['username'] ?? 'User';
}

/**
 * Get all projects visible to current user
 */
function getVisibleProjects($pdo) {
    $userId = getCurrentUserId();
    
    // Admins see all projects
    if (isAdmin()) {
        $stmt = $pdo->prepare("
            SELECT p.*, c.name as client_name, c.type as client_type
            FROM projects p
            LEFT JOIN clients c ON p.clientid = c.id
            ORDER BY p.created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    // Other users see only projects they're assigned to
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.*, c.name as client_name, c.type as client_type
        FROM projects p
        LEFT JOIN clients c ON p.clientid = c.id
        INNER JOIN user_project_access upa ON p.id = upa.project_id
        WHERE upa.user_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Check if user has access to a specific project
 */


/**
 * Get user's access level for a specific project
 * Returns: 'admin', 'manager', 'architect', 'viewer', or null if no access
 */
function getProjectAccessLevel($pdo, $projectId) {
    $userId = getCurrentUserId();
    
    // Admin users have admin access to all projects
    if (isAdmin()) {
        return 'admin';
    }
    
    // Check user's project-specific access
    $stmt = $pdo->prepare("
        SELECT access_level FROM user_project_access
        WHERE user_id = ? AND project_id = ?
    ");
    $stmt->execute([$userId, $projectId]);
    $result = $stmt->fetch();
    return $result ? $result['access_level'] : null;
}

/**
 * Check if user can edit a specific project
 */
function canEditProject($pdo, $projectId) {
    // Admins can edit all projects
    if (isAdmin()) {
        return true;
    }
    
    $accessLevel = getProjectAccessLevel($pdo, $projectId);
    return $accessLevel === 'manager' || $accessLevel === 'admin';
}

/**
 * Check if user is an architect assigned to a project
 */
function isArchitectForProject($pdo, $projectId) {
    $userId = getCurrentUserId();
    
    $stmt = $pdo->prepare("
        SELECT id FROM user_project_access
        WHERE user_id = ? AND project_id = ? AND access_level = 'architect'
    ");
    $stmt->execute([$userId, $projectId]);
    return $stmt->fetch() !== null;
}

/**
 * Get all users in the system
 */
function getAllUsers($pdo) {
    $stmt = $pdo->query("
        SELECT id, username, email, role, first_name, last_name, is_active, created_at, last_login
        FROM users
        ORDER BY username ASC
    ");
    return $stmt->fetchAll();
}

/**
 * Get user by ID
 */


/**
 * Get users assigned to a specific project
 */
function getProjectUsers($pdo, $projectId) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.role as global_role, 
               upa.access_level, upa.assigned_at, upa.assigned_by
        FROM users u
        INNER JOIN user_project_access upa ON u.id = upa.user_id
        WHERE upa.project_id = ?
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}





/**
 * Change user password
 */
function changePassword($pdo, $userId, $newPassword) {
    try {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        return $stmt->execute([$passwordHash, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Delete user (cascade will handle user_project_access records)
 */
function deleteUser($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$userId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Assign user to a project
 */
function assignUserToProject($pdo, $userId, $projectId, $accessLevel = 'viewer') {
    try {
        $currentUserId = getCurrentUserId();
        
        // Check if assignment already exists
        $check = $pdo->prepare("
            SELECT id FROM user_project_access
            WHERE user_id = ? AND project_id = ?
        ");
        $check->execute([$userId, $projectId]);
        
        if ($check->fetch()) {
            // Update existing assignment
            $stmt = $pdo->prepare("
                UPDATE user_project_access
                SET access_level = ?, assigned_by = ?
                WHERE user_id = ? AND project_id = ?
            ");
            return $stmt->execute([$accessLevel, $currentUserId, $userId, $projectId]);
        } else {
            // Create new assignment
            $stmt = $pdo->prepare("
                INSERT INTO user_project_access (user_id, project_id, access_level, assigned_by)
                VALUES (?, ?, ?, ?)
            ");
            return $stmt->execute([$userId, $projectId, $accessLevel, $currentUserId]);
        }
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Remove user from a project
 */
function removeUserFromProject($pdo, $userId, $projectId) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM user_project_access
            WHERE user_id = ? AND project_id = ?
        ");
        return $stmt->execute([$userId, $projectId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get all projects a user is assigned to
 */
function getUserProjects($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as client_name, c.type as client_type, upa.access_level
        FROM projects p
        LEFT JOIN clients c ON p.clientid = c.id
        INNER JOIN user_project_access upa ON p.id = upa.project_id
        WHERE upa.user_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Get role description
 */
function getRoleDescription($role) {
    $descriptions = [
        'admin' => 'Administrator - Full system access, user management',
        'manager' => 'Manager - Can edit projects, manage data',
        'architect' => 'Architect - View assigned projects only',
        'viewer' => 'Viewer - Read-only access to assigned projects'
    ];
    return $descriptions[$role] ?? 'Unknown Role';
}

/**
 * Enhanced user access control functions
 * Supports client-based access and architect firm-based access
 */

/**
 * Get all accessible projects for a user
 * Logic:
 * - Admins see all projects
 * - Architects see projects where their assigned firms match project architect/structural engineer
 * - Other users see projects from assigned clients, minus any excluded projects
 */
function getAccessibleProjects($pdo, $userId = null) {
    if ($userId === null) {
        $userId = getCurrentUserId();
    }
    
    // Admins have access to all projects
    if (isAdmin()) {
        $stmt = $pdo->prepare("
            SELECT p.*, c.name as client_name, c.type as client_type
            FROM projects p
            LEFT JOIN clients c ON p.clientid = c.id
            ORDER BY p.created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    // Get user details
    $user = getUserById($pdo, $userId);
    if (!$user) return [];
    
    // Check if user is an architect
    if ($user['role'] === 'architect') {
        // Architects see projects based on their assigned firms
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.*, c.name as client_name, c.type as client_type
            FROM projects p
            LEFT JOIN clients c ON p.clientid = c.id
            LEFT JOIN project_pa_numbers ppn ON p.id = ppn.project_id
            WHERE (
                (? IS NOT NULL AND ppn.architect_id IN (
                    SELECT id FROM professionals 
                    WHERE firm_name = (
                        SELECT firm_name FROM professionals WHERE id = ?
                    ) AND role_type = 'architect'
                ))
                OR
                (? IS NOT NULL AND ppn.structural_engineer_id IN (
                    SELECT id FROM professionals 
                    WHERE firm_name = (
                        SELECT firm_name FROM professionals WHERE id = ?
                    ) AND role_type = 'structural_engineer'
                ))
            )
            AND p.id NOT IN (
                SELECT project_id FROM user_project_exclusions WHERE user_id = ?
            )
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([
            $user['assigned_architect_firm_id'],
            $user['assigned_architect_firm_id'],
            $user['assigned_structural_firm_id'],
            $user['assigned_structural_firm_id'],
            $userId
        ]);
        return $stmt->fetchAll();
    }
    
    // Other users see projects from assigned clients
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.*, c.name as client_name, c.type as client_type
        FROM projects p
        LEFT JOIN clients c ON p.clientid = c.id
        INNER JOIN user_client_access uca ON p.clientid = uca.client_id
        WHERE uca.user_id = ?
        AND p.id NOT IN (
            SELECT project_id FROM user_project_exclusions WHERE user_id = ?
        )
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$userId, $userId]);
    return $stmt->fetchAll();
}

/**
 * Check if user has access to a specific project
 */
function hasProjectAccess($pdo, $projectId) {
    $userId = getCurrentUserId();
    
    // Admins have access to all projects
    if (isAdmin()) {
        return true;
    }
    
    // Check if project is explicitly excluded
    $stmt = $pdo->prepare("
        SELECT id FROM user_project_exclusions
        WHERE user_id = ? AND project_id = ?
    ");
    $stmt->execute([$userId, $projectId]);
    if ($stmt->fetch()) {
        return false;
    }
    
    // Get user details
    $user = getUserById($pdo, $userId);
    if (!$user) return false;
    
    // Check architect firm-based access
    if ($user['role'] === 'architect') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.id
            FROM projects p
            LEFT JOIN project_pa_numbers ppn ON p.id = ppn.project_id
            WHERE p.id = ?
            AND (
                (? IS NOT NULL AND ppn.architect_id IN (
                    SELECT id FROM professionals 
                    WHERE firm_name = (
                        SELECT firm_name FROM professionals WHERE id = ?
                    ) AND role_type = 'architect'
                ))
                OR
                (? IS NOT NULL AND ppn.structural_engineer_id IN (
                    SELECT id FROM professionals 
                    WHERE firm_name = (
                        SELECT firm_name FROM professionals WHERE id = ?
                    ) AND role_type = 'structural_engineer'
                ))
            )
        ");
        $stmt->execute([
            $projectId,
            $user['assigned_architect_firm_id'],
            $user['assigned_architect_firm_id'],
            $user['assigned_structural_firm_id'],
            $user['assigned_structural_firm_id']
        ]);
        return $stmt->fetch() !== false;
    }
    
    // Check client-based access
    $stmt = $pdo->prepare("
        SELECT p.id
        FROM projects p
        INNER JOIN user_client_access uca ON p.clientid = uca.client_id
        WHERE p.id = ? AND uca.user_id = ?
    ");
    $stmt->execute([$projectId, $userId]);
    return $stmt->fetch() !== false;
}

/**
 * Assign a client to a user
 */
function assignUserToClient($pdo, $userId, $clientId) {
    try {
        $currentUserId = getCurrentUserId();
        
        // Check if assignment already exists
        $check = $pdo->prepare("
            SELECT id FROM user_client_access
            WHERE user_id = ? AND client_id = ?
        ");
        $check->execute([$userId, $clientId]);
        
        if ($check->fetch()) {
            return true; // Already assigned
        }
        
        // Create new assignment
        $stmt = $pdo->prepare("
            INSERT INTO user_client_access (user_id, client_id, assigned_by)
            VALUES (?, ?, ?)
        ");
        return $stmt->execute([$userId, $clientId, $currentUserId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Remove a client from a user
 */
function removeUserFromClient($pdo, $userId, $clientId) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM user_client_access
            WHERE user_id = ? AND client_id = ?
        ");
        return $stmt->execute([$userId, $clientId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Exclude a specific project from a user's access
 */
function excludeProjectFromUser($pdo, $userId, $projectId) {
    try {
        $currentUserId = getCurrentUserId();
        
        // Check if exclusion already exists
        $check = $pdo->prepare("
            SELECT id FROM user_project_exclusions
            WHERE user_id = ? AND project_id = ?
        ");
        $check->execute([$userId, $projectId]);
        
        if ($check->fetch()) {
            return true; // Already excluded
        }
        
        // Create new exclusion
        $stmt = $pdo->prepare("
            INSERT INTO user_project_exclusions (user_id, project_id, excluded_by)
            VALUES (?, ?, ?)
        ");
        return $stmt->execute([$userId, $projectId, $currentUserId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Remove project exclusion (restore access)
 */
function removeProjectExclusion($pdo, $userId, $projectId) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM user_project_exclusions
            WHERE user_id = ? AND project_id = ?
        ");
        return $stmt->execute([$userId, $projectId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get all clients assigned to a user
 */
function getUserClients($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT c.*, uca.assigned_at
        FROM clients c
        INNER JOIN user_client_access uca ON c.id = uca.client_id
        WHERE uca.user_id = ?
        ORDER BY c.name ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Get all projects excluded for a user
 */
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

/**
 * Update user firm assignments (for architects)
 */
function updateUserFirmAssignments($pdo, $userId, $architectFirmId, $structuralFirmId) {
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET assigned_architect_firm_id = ?, 
                assigned_structural_firm_id = ?
            WHERE id = ?
        ");
        return $stmt->execute([$architectFirmId, $structuralFirmId, $userId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get all available firms grouped by type
 */
function getAllFirms($pdo) {
    $stmt = $pdo->query("
        SELECT DISTINCT firm_name, role_type
        FROM professionals
        ORDER BY firm_name ASC, role_type ASC
    ");
    $allFirms = $stmt->fetchAll();
    
    $firms = [
        'architects' => [],
        'structural_engineers' => []
    ];
    
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

/**
 * Get professional ID by firm name and role type
 */
function getProfessionalIdByFirm($pdo, $firmName, $roleType) {
    $stmt = $pdo->prepare("
        SELECT id FROM professionals
        WHERE firm_name = ? AND role_type = ?
        LIMIT 1
    ");
    $stmt->execute([$firmName, $roleType]);
    $result = $stmt->fetch();
    return $result ? $result['id'] : null;
}

/**
 * Update existing createUser function to support firm assignments
 */
function createUser($pdo, $username, $email, $password, $role = 'viewer', $firstName = '', $lastName = '', $architectFirmId = null, $structuralFirmId = null) {
    try {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password_hash, role, first_name, last_name, is_active, assigned_architect_firm_id, assigned_structural_firm_id)
            VALUES (?, ?, ?, ?, ?, ?, 'Yes', ?, ?)
        ");
        $stmt->execute([$username, $email, $passwordHash, $role, $firstName, $lastName, $architectFirmId, $structuralFirmId]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Update existing updateUser function to include firm assignments
 */
function updateUser($pdo, $userId, $data) {
    try {
        $updates = [];
        $params = [];
        $allowedFields = ['username', 'email', 'role', 'first_name', 'last_name', 'is_active', 'assigned_architect_firm_id', 'assigned_structural_firm_id'];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updates[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $userId;
        $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($query);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get user by ID with firm details
 */
function getUserById($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT u.*, 
               pa.firm_name as architect_firm_name,
               ps.firm_name as structural_firm_name
        FROM users u
        LEFT JOIN professionals pa ON u.assigned_architect_firm_id = pa.id AND pa.role_type = 'architect'
        LEFT JOIN professionals ps ON u.assigned_structural_firm_id = ps.id AND ps.role_type = 'structural_engineer'
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

?>
