<?php
/**
 * Session Check Helper
 * Include at the top of every protected page
 */

session_start();

// Check if user is logged in
if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: ' . dirname($_SERVER['PHP_SELF']) . '/index.php');
    exit;
}

// Check if user_id exists in session
if (!isset($_SESSION['user_id'])) {
    session_destroy();
    header('Location: ' . dirname($_SERVER['PHP_SELF']) . '/index.php');
    exit;
}

// Function to check if user is admin
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Function to get current user ID
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Function to get current user info
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

// Function to logout
function logout() {
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

?>
