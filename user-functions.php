<?php
/**
 * user-functions.php - User and access control functions
 * All user management and project access logic
 */

/**
 * Rev 2.0: Get all accessible projects for a user
 * Logic:
 * - Admins: See everything.
 * - View Tracking: If permission is OFF, projects with is_tracking=1 are hidden.
 * - Architects: See projects where their assigned firms match project professionals.
 * - Others (Managers/Directors/Viewers): See projects from assigned clients.
 * - Exclusions: Explicitly excluded projects are always hidden.
 */
function getAccessibleProjects($pdo, $userId = null) {
    if ($userId === null) {
        $userId = getCurrentUserId();
    }
    
    // 1. Check Tracking Permission
    // If they aren't admin and don't have the permission, we filter out tracking projects
    $trackingFilter = "";
    if (!isAdmin() && !hasPermission('can_view_tracking')) {
        $trackingFilter = " AND p.is_tracking = 0 ";
    }

    // 2. ADMIN ACCESS (Sees everything)
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
    
    // Get user details for specific role logic
    $user = getUserById($pdo, $userId);
    if (!$user) return [];
    
    // 3. ARCHITECT ACCESS (Firm-based)
    if ($user['role'] === 'architect') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.*, c.name as client_name, c.type as client_type
            FROM projects p
            LEFT JOIN clients c ON p.clientid = c.id
            LEFT JOIN project_pa_numbers ppn ON p.id = ppn.project_id
            WHERE (
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
            AND p.id NOT IN (
                SELECT project_id FROM user_project_exclusions WHERE user_id = ?
            )
            $trackingFilter
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
    
    // 4. STANDARD ACCESS (Managers, Directors, Viewers - Client-based)
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.*, c.name as client_name, c.type as client_type
        FROM projects p
        LEFT JOIN clients c ON p.clientid = c.id
        INNER JOIN user_client_access uca ON p.clientid = uca.client_id
        WHERE uca.user_id = ?
        AND p.id NOT IN (
            SELECT project_id FROM user_project_exclusions WHERE user_id = ?
        )
        $trackingFilter
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
 * Get user's access level for a specific project
 * Returns: 'admin', 'manager', 'architect', 'viewer', or null if no access
 */
function getProjectAccessLevel($pdo, $projectId) {
    $userId = getCurrentUserId();
    
    // Admin users have admin access to all projects
    if (isAdmin()) {
        return 'admin';
    }
    
    // Check if user has access at all
    if (!hasProjectAccess($pdo, $projectId)) {
        return null;
    }
    
    // Return user's global role as their access level
    return getCurrentRole();
}

/**
 * Check if user can edit a specific project
 */
function canEditProject($pdo, $projectId) {
    // Admins can edit all projects
    if (isAdmin()) {
        return true;
    }
    
    // Managers can edit projects they have access to
    if (getCurrentRole() === 'manager' && hasProjectAccess($pdo, $projectId)) {
        return true;
    }
    
    return false;
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

/**
 * Create a new user
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
 * Update user information
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
 * Delete user (cascade will handle user_client_access and user_project_exclusions records)
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
 * Automatically assign creator to client (for non-admins)
 * Admins don't need assignments as they have access to everything
 */
function autoAssignCreatorToClient($pdo, $clientId, $creatorUserId = null) {
    if ($creatorUserId === null) {
        $creatorUserId = getCurrentUserId();
    }
    
    // Don't auto-assign for admins (they have access to everything anyway)
    if (isAdmin()) {
        return true;
    }
    
    // Auto-assign the creator to the client
    try {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO user_client_access (user_id, client_id, assigned_by)
            VALUES (?, ?, ?)
        ");
        return $stmt->execute([$creatorUserId, $clientId, $creatorUserId]);
    } catch (PDOException $e) {
        // Fail silently - the client was still created
        return false;
    }
}

/**
 * Automatically assign creator to project's client (for non-admins)
 */
function autoAssignCreatorToProjectClient($pdo, $projectId, $creatorUserId = null) {
    if ($creatorUserId === null) {
        $creatorUserId = getCurrentUserId();
    }
    
    // Don't auto-assign for admins
    if (isAdmin()) {
        return true;
    }
    
    try {
        // Get the project's client
        $stmt = $pdo->prepare("SELECT clientid FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
        
        if ($project) {
            return autoAssignCreatorToClient($pdo, $project['clientid'], $creatorUserId);
        }
        
        return false;
    } catch (PDOException $e) {
        return false;
    }
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

function formatPANumber($pa) {
    // Input: PA005324 -> Output: PA/0053/24
    if (preg_match('/^([A-Z]{2})(\d{4})(\d{2})$/', $pa, $matches)) {
        return "{$matches[1]}/{$matches[2]}/{$matches[3]}";
    }
    return $pa; // Return original if format doesn't match
}

function getEAppsUrl($pa) {
    // Ensure we use the raw PA number (no slashes) for the URL
    $rawPa = str_replace('/', '', $pa);
    // Based on your existing logic in mobilization.php
    if (preg_match('/(PA|PC|DN)(\d+)(\d{2})/', $rawPa, $m)) {
        return "https://eapps.pa.org.mt/Case/CaseDetails?caseType={$m[1]}&casenumber={$m[2]}&caseYear={$m[3]}";
    }
    return "#";
}

/**
 * Checks if the current user has a specific capability.
 * Respects the Rev 2.0 rule: Viewers can NEVER add/edit.
 */
function hasPermission($capability) {
    global $pdo;
    
    // 1. Hard Override: Admins always have permission
    if ($_SESSION['role'] === 'admin') return true;

    // 2. Hard Constraint: Viewers can NEVER add or edit projects
    $restrictedForViewers = ['can_add_project', 'can_edit_project'];
    if ($_SESSION['role'] === 'viewer' && in_array($capability, $restrictedForViewers)) {
        return false;
    }

    // 3. Dynamic Check: Fetch the bit from the user record
    $stmt = $pdo->prepare("SELECT $capability FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return (bool)$stmt->fetchColumn();
}

?>
