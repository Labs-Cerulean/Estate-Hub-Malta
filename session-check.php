<?php
/**
 * Session Check Helper
 * Include at the top of every protected page
 * 
 * IMPORTANT: Do NOT call session_start() before including this file!
 * This file will handle all session management.
 */

// Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

// Check if user_id exists in session
if (!isset($_SESSION['user_id'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Verify session is still valid (basic security check)
if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// ===== HELPER FUNCTIONS (Only define if not already defined) =====

if (!function_exists('isAdmin')) {
    function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
}

if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId() {
        return $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('getCurrentUser')) {
    function getCurrentUser() {
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'first_name' => $_SESSION['first_name'] ?? null,
            'last_name' => $_SESSION['last_name'] ?? null,
            'email' => $_SESSION['email'] ?? null,
            'role' => $_SESSION['role'] ?? null
        ];
    }
}

if (!function_exists('logout')) {
    function logout() {
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

?>
