<?php
/**
 * init.php - Initialization file with no output
 * Include this at the top of every page BEFORE any redirects
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration and database connection
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/user-functions.php';

// Set timezone
date_default_timezone_set('Europe/Malta');

// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Helper function to get current user ID
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Helper function to get current username
function getCurrentUsername() {
    return $_SESSION['username'] ?? null;
}

// Helper function to get current user full name
function getCurrentUserFullName() {
    $firstName = $_SESSION['first_name'] ?? '';
    $lastName = $_SESSION['last_name'] ?? '';
    return trim($firstName . ' ' . $lastName) ?: getCurrentUsername();
}

// Helper function to get current user role
function getCurrentRole() {
    return $_SESSION['role'] ?? null;
}

// Helper function to check if user is admin
function isAdmin() {
    return getCurrentRole() === 'admin';
}

// Helper function to check if user has a specific role
function hasRole($role) {
    return getCurrentRole() === $role;
}

// Update last activity timestamp
if (isLoggedIn()) {
    $_SESSION['last_activity'] = time();
}

// Auto-deploy schema additions for PM cohesion features (skip on API calls to avoid lock contention)
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$isApiRequest = str_contains($requestUri, '/api/');
if (isset($pdo) && !$isApiRequest) {
    try { $pdo->exec("ALTER TABLE user_capabilities ADD COLUMN view_all_projects TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE user_capabilities ADD COLUMN edit_project_schedule TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE user_capabilities ADD COLUMN view_sales_ohsa TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE user_capabilities ADD COLUMN manage_sales_ohsa TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN avatar_key VARCHAR(255) DEFAULT NULL"); } catch (PDOException $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token_hash),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_delivery_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL UNIQUE,
            planned_shell_date DATE DEFAULT NULL,
            forecast_shell_date DATE DEFAULT NULL,
            actual_shell_date DATE DEFAULT NULL,
            planned_finishes_date DATE DEFAULT NULL,
            forecast_finishes_date DATE DEFAULT NULL,
            actual_finishes_date DATE DEFAULT NULL,
            finishes_scope VARCHAR(50) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}
}


// Helper function to check if user is services engineer
function isServicesEngineer() {
    return getCurrentRole() === 'services_engineer';
}
/**
 * Automatically grants the creator of a client access to view/manage it
 */
function autoAssignCreatorToClient($pdo, $clientId, $userId) {
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO user_client_access (user_id, client_id) VALUES (?, ?)");
        $stmt->execute([$userId, $clientId]);
        return true;
    } catch (PDOException $e) {
        error_log("Error auto-assigning client access: " . $e->getMessage());
        return false;
    }
}
