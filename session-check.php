<?php
/**
 * session-check.php - Check if user is logged in
 * Include this after init.php on pages that require authentication
 */

if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/init.php';
}

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$timeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    session_unset();
    session_destroy();
    header('Location: index.php?timeout=1');
    exit;
}

$sessionBasename = basename($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? '');
$phpSelfBasename = basename($_SERVER['PHP_SELF'] ?? '');
$sessionRequestUri = $_SERVER['REQUEST_URI'] ?? '';

function sessionIsPlantActionsRequest(): bool {
    global $sessionBasename, $phpSelfBasename, $sessionRequestUri;
    if ($sessionBasename === 'plant_actions.php' || $phpSelfBasename === 'plant_actions.php') {
        return true;
    }
    if (str_contains($sessionRequestUri, 'plant_actions.php')) {
        return true;
    }
    return (bool) preg_match('#/api/plant_actions(\.php)?(\?|$|/)#', $sessionRequestUri);
}

function sessionIsLogoutRequest(): bool {
    global $sessionBasename, $sessionRequestUri;
    return $sessionBasename === 'logout.php' || str_contains($sessionRequestUri, 'logout');
}

/**
 * Authorized plant_actions.php requests must bypass hub isolation redirects.
 * Main branch never applied hub isolation to the Plant API; staging regressed this in f2a4e39.
 */
$allowPlantHubApi = sessionIsPlantActionsRequest() && canUsePlantHubApi();

if (!$allowPlantHubApi) {

    if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['plant_manager', 'plant_driver'], true)) {
        $allowed_plant_pages = [
            'plant_bookings.php',
            'print_plant_invoice.php',
            'print_plant_pricelist.php',
        ];
        $allowed_plant_apis = ['plant_actions.php'];
        $isApiRequest = sessionIsPlantActionsRequest()
            || str_contains($_SERVER['PHP_SELF'] ?? '', '/api/')
            || str_contains($sessionRequestUri, '/api/');

        if (sessionIsLogoutRequest()) {
            // allow
        } elseif ($isApiRequest && (
            in_array($sessionBasename, $allowed_plant_apis, true)
            || in_array($phpSelfBasename, $allowed_plant_apis, true)
            || str_contains($sessionRequestUri, 'plant_actions')
        )) {
            // allow plant API (do not rely on PHP_SELF always containing /api/)
        } elseif (in_array($sessionBasename, $allowed_plant_pages, true)) {
            // allow
        } else {
            header('Location: /plant_bookings.php');
            exit;
        }
    }

    if (isset($_SESSION['role']) && $_SESSION['role'] === 'sales_agent') {
        $isApi = str_contains($sessionRequestUri, '/api/') || str_contains($_SERVER['PHP_SELF'] ?? '', '/api/');
        $allowed_sales_agent_pages = ['sales_hub.php', 'profile.php'];
        $allowed_sales_agent_apis = [
            'sales_actions.php', 'get_sales_map_data.php', 'get_holds_ledger.php',
            'get_project_units.php', 'upload_sales_media.php', 'upload_project_frame.php', 'update_unit_price.php',
        ];

        if (sessionIsLogoutRequest()) {
            // allow
        } elseif ($isApi) {
            if (!in_array($sessionBasename, $allowed_sales_agent_apis, true)) {
                header('Location: /sales_hub.php');
                exit;
            }
        } elseif (!in_array($sessionBasename, $allowed_sales_agent_pages, true)) {
            header('Location: /sales_hub.php');
            exit;
        }
    }

    if (isset($_SESSION['role']) && $_SESSION['role'] === 'sales_manager') {
        $isApi = str_contains($sessionRequestUri, '/api/') || str_contains($_SERVER['PHP_SELF'] ?? '', '/api/');
        $blocked_sales_manager_pages = ['work_sales.php', 'works_sales.php', 'admin_standard_rates.php'];
        $allowed_sales_manager_pages = [
            'sales_hub.php', 'sales_project_manager.php', 'import_key_simplified.php',
            'dashboard.php', 'projects.php', 'mobilization.php', 'mobilisation_detail.php',
            'engineering.php', 'create-project.php', 'edit-project.php', 'project-status.php',
            'map-view.php', 'notifications.php', 'actions.php', 'profile.php',
        ];
        $allowed_sales_manager_apis = [
            'sales_actions.php', 'get_sales_map_data.php', 'get_holds_ledger.php',
            'get_project_units.php', 'upload_sales_media.php', 'upload_project_frame.php',
            'update_unit_price.php', 'sync_daily_report.php',
        ];

        if (sessionIsLogoutRequest()) {
            // allow
        } elseif (in_array($sessionBasename, $blocked_sales_manager_pages, true)) {
            header('Location: /sales_hub.php');
            exit;
        } elseif ($isApi) {
            if (!in_array($sessionBasename, $allowed_sales_manager_apis, true)) {
                header('Location: /sales_hub.php');
                exit;
            }
        } elseif (!in_array($sessionBasename, $allowed_sales_manager_pages, true)) {
            header('Location: /sales_hub.php');
            exit;
        }
    }

    if (isset($_SESSION['role']) && $_SESSION['role'] === 'legal_representative') {
        $allowed_legal_pages = ['projects.php', 'mobilisation_detail.php', 'project-status.php', 'profile.php'];
        $isApi = str_contains($sessionRequestUri, '/api/') || str_contains($_SERVER['PHP_SELF'] ?? '', '/api/');
        if (!$isApi && !sessionIsLogoutRequest() && !in_array($sessionBasename, $allowed_legal_pages, true)) {
            header('Location: /projects.php');
            exit;
        }
    }
}
