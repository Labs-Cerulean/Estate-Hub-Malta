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

$sessionBasename = basename($_SERVER['PHP_SELF'] ?? '');

/**
 * Plant Hub API must never be short-circuited by hub isolation redirects for authorized users.
 * Authorization is also enforced inside api/plant_actions.php for sensitive actions.
 */
function sessionCanUsePlantHubApi(): bool {
    if (!function_exists('hasPermission')) {
        require_once __DIR__ . '/user-functions.php';
    }
    $role = $_SESSION['role'] ?? '';
    if (in_array($role, ['admin', 'director', 'accountant', 'system_manager', 'plant_manager', 'plant_driver'], true)) {
        return true;
    }
    return hasPermission('view_plant_bookings')
        || hasPermission('manage_plant_fleet')
        || hasPermission('view_plant_ledger');
}

$allowPlantHubApi = ($sessionBasename === 'plant_actions.php') && sessionCanUsePlantHubApi();

if (!$allowPlantHubApi) {

    // Auto-Redirect Plant Staff to their specific app
    if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['plant_manager', 'plant_driver'], true)) {
        $allowed_plant_pages = [
            'plant_bookings.php',
            'print_plant_invoice.php',
        ];
        $allowed_plant_apis = [
            'plant_actions.php',
        ];

        $isApiRequest = strpos($_SERVER['PHP_SELF'] ?? '', '/api/') !== false
            || ($sessionBasename === 'plant_actions.php');
        $isLogout = $sessionBasename === 'logout.php' || strpos($_SERVER['PHP_SELF'] ?? '', 'logout') !== false;

        if ($isLogout) {
            // allow logout
        } elseif ($isApiRequest && in_array($sessionBasename, $allowed_plant_apis, true)) {
            // allow plant API explicitly (do not rely on /api/ being present in PHP_SELF)
        } elseif (in_array($sessionBasename, $allowed_plant_pages, true)) {
            // allow plant pages
        } else {
            header('Location: plant_bookings.php');
            exit;
        }
    }

    // Sales agent — strict hub isolation (mirrors Plant staff pattern)
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'sales_agent') {
        $isApi = strpos($_SERVER['PHP_SELF'] ?? '', '/api/') !== false;
        $isLogout = $sessionBasename === 'logout.php' || strpos($_SERVER['PHP_SELF'] ?? '', 'logout') !== false;

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
            if (!in_array($sessionBasename, $allowed_sales_agent_apis, true)) {
                header('Location: /sales_hub.php');
                exit;
            }
        } elseif (!in_array($sessionBasename, $allowed_sales_agent_pages, true)) {
            header('Location: sales_hub.php');
            exit;
        }
    }

    // Sales manager — Sales Hub + limited Estate Hub (project execution, not Work Sales)
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'sales_manager') {
        $isApi = strpos($_SERVER['PHP_SELF'] ?? '', '/api/') !== false;
        $isLogout = $sessionBasename === 'logout.php' || strpos($_SERVER['PHP_SELF'] ?? '', 'logout') !== false;

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
        } elseif (in_array($sessionBasename, $blocked_sales_manager_pages, true)) {
            header('Location: sales_hub.php');
            exit;
        } elseif ($isApi) {
            if (!in_array($sessionBasename, $allowed_sales_manager_apis, true)) {
                header('Location: /sales_hub.php');
                exit;
            }
        } elseif (!in_array($sessionBasename, $allowed_sales_manager_pages, true)) {
            header('Location: sales_hub.php');
            exit;
        }
    }

    // Legal representatives: project status matrix + read-only hub + profile only
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'legal_representative') {
        $allowed_legal_pages = [
            'projects.php',
            'mobilisation_detail.php',
            'project-status.php',
            'profile.php',
        ];
        $isApi = strpos($_SERVER['PHP_SELF'] ?? '', '/api/') !== false;
        $isLogout = $sessionBasename === 'logout.php' || strpos($_SERVER['PHP_SELF'] ?? '', 'logout') !== false;
        if (!$isApi && !$isLogout && !in_array($sessionBasename, $allowed_legal_pages, true)) {
            header('Location: projects.php');
            exit;
        }
    }
}
