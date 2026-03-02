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
