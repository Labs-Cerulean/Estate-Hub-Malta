<?php
/**
 * User Management & Authorization Functions
 * Estate Hub - Project Management System
 */

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && isset($_SESSION['user_id']);
}

/**
 * Require user to be logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Check if current user has a specific role
 */
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
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
function hasProjectAccess($pdo, $projectId) {
    $userId = getCurrentUserId();
    
    // Admins have access to all projects
    if (isAdmin()) {
        return true;
    }
    
    // Check if user is assigned to this project
    $stmt = $pdo->prepare("
        SELECT id FROM user_project_access
        WHERE user_id = ? AND project_id = ?
    ");
    $stmt->execute([$userId, $projectId]);
    return $stmt->fetch() !== null;
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
function getUserById($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT id, username, email, password_hash, role, first_name, last_name, is_active, created_at, last_login
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

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
 * Create a new user
 */
function createUser($pdo, $username, $email, $password, $role = 'viewer', $firstName = '', $lastName = '') {
    try {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password_hash, role, first_name, last_name, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 'Yes')
        ");
        
        $stmt->execute([$username, $email, $passwordHash, $role, $firstName, $lastName]);
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
        
        $allowedFields = ['username', 'email', 'role', 'first_name', 'last_name', 'is_active'];
        
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

?>
