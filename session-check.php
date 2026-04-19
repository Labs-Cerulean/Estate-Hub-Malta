<?php
/**
 * session-check.php - Check if user is logged in
 * Include this after init.php on pages that require authentication
 */

// Make sure init.php was included first
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/init.php';
}

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Check session timeout (30 minutes)
$timeout = 1800; // 30 minutes in seconds
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    session_unset();
    session_destroy();
    header('Location: index.php?timeout=1');
    exit;
}

// Auto-Redirect Plant Staff to their specific app
if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['plant_manager', 'plant_driver'])) {
    $current_file = basename($_SERVER['PHP_SELF']);
    if ($current_file !== 'plant_bookings.php' && strpos($_SERVER['PHP_SELF'], '/api/') === false && $current_file !== 'logout.php') {
        header("Location: plant_bookings.php");
        exit;
    }
}
