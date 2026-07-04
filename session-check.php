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

// Sales agent — strict hub isolation (mirrors Plant staff pattern)
if (isset($_SESSION['role']) && $_SESSION['role'] === 'sales_agent') {
    $current_file = basename($_SERVER['PHP_SELF']);
    $isApi = strpos($_SERVER['PHP_SELF'], '/api/') !== false;
    $isLogout = $current_file === 'logout.php' || strpos($_SERVER['PHP_SELF'], 'logout') !== false;

    $allowed_sales_agent_pages = [
        'sales_hub.php',
        'profile.php',
    ];

    $allowed_sales_agent_apis = [
        'sales_actions.php',
        'get_sales_map_data.php',
        'get_holds_ledger.php',
        'get_project_units.php',
        'upload_sales_media.php',
        'upload_project_frame.php',
        'update_unit_price.php',
    ];

    if ($isLogout) {
        // allow logout
    } elseif ($isApi) {
        if (!in_array($current_file, $allowed_sales_agent_apis, true)) {
            header('Location: ../sales_hub.php');
            exit;
        }
    } elseif (!in_array($current_file, $allowed_sales_agent_pages, true)) {
        header('Location: sales_hub.php');
        exit;
    }
}

// Sales manager — Sales Hub + limited Estate Hub (project execution, not Work Sales)
if (isset($_SESSION['role']) && $_SESSION['role'] === 'sales_manager') {
    $current_file = basename($_SERVER['PHP_SELF']);
    $isApi = strpos($_SERVER['PHP_SELF'], '/api/') !== false;
    $isLogout = $current_file === 'logout.php' || strpos($_SERVER['PHP_SELF'], 'logout') !== false;

    $blocked_sales_manager_pages = [
        'work_sales.php',
        'works_sales.php',
        'admin_standard_rates.php',
    ];

    $allowed_sales_manager_pages = [
        'sales_hub.php',
        'sales_project_manager.php',
        'import_key_simplified.php',
        'dashboard.php',
        'projects.php',
        'mobilization.php',
        'mobilisation_detail.php',
        'engineering.php',
        'create-project.php',
        'edit-project.php',
        'project-status.php',
        'map-view.php',
        'notifications.php',
        'actions.php',
        'profile.php',
    ];

    $allowed_sales_manager_apis = [
        'sales_actions.php',
        'get_sales_map_data.php',
        'get_holds_ledger.php',
        'get_project_units.php',
        'upload_sales_media.php',
        'upload_project_frame.php',
        'update_unit_price.php',
        'sync_daily_report.php',
    ];

    if ($isLogout) {
        // allow logout
    } elseif (in_array($current_file, $blocked_sales_manager_pages, true)) {
        header('Location: sales_hub.php');
        exit;
    } elseif ($isApi) {
        if (!in_array($current_file, $allowed_sales_manager_apis, true)) {
            header('Location: ../sales_hub.php');
            exit;
        }
    } elseif (!in_array($current_file, $allowed_sales_manager_pages, true)) {
        header('Location: sales_hub.php');
        exit;
    }
}

// Legal representatives: project status matrix + read-only hub + profile only
if (isset($_SESSION['role']) && $_SESSION['role'] === 'legal_representative') {
    $current_file = basename($_SERVER['PHP_SELF']);
    $allowed_legal_pages = [
        'projects.php',
        'mobilisation_detail.php',
        'project-status.php',
        'profile.php',
    ];
    $isApi = strpos($_SERVER['PHP_SELF'], '/api/') !== false;
    $isLogout = $current_file === 'logout.php' || strpos($_SERVER['PHP_SELF'], 'logout') !== false;
    if (!$isApi && !$isLogout && !in_array($current_file, $allowed_legal_pages)) {
        header('Location: projects.php');
        exit;
    }
}
