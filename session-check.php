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
    
    // List of exact files Plant Staff are allowed to view
    $allowed_plant_pages = [
        'plant_bookings.php', 
        'print_plant_invoice.php', 
        'logout.php'
    ];
    
    // If they try to load a page NOT in the list (and not an API call), redirect them back
    if (!in_array($current_file, $allowed_plant_pages) && strpos($_SERVER['PHP_SELF'], '/api/') === false) {
        header("Location: plant_bookings.php");
        exit;
    }
}
