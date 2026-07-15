<?php
/**
 * Central navigation config — Estate Hub, Sales Hub, Plant Hub.
 * Menu visibility only; page/API permission checks remain authoritative.
 */

if (!function_exists('navCanAccessEstateHub')) {

function navIsPlantOnlyRole(): bool {
    return in_array(getCurrentRole(), ['plant_manager', 'plant_driver'], true);
}

function navIsSalesAgentRole(): bool {
    return getCurrentRole() === 'sales_agent';
}

function navIsSalesManagerRole(): bool {
    return getCurrentRole() === 'sales_manager';
}

function navIsLegalRep(): bool {
    return getCurrentRole() === 'legal_representative';
}

function navCanAccessEstateHub(): bool {
    if (navIsPlantOnlyRole() || navIsSalesAgentRole()) {
        return false;
    }
    if (navIsLegalRep()) {
        return true;
    }
    if (navIsSalesManagerRole()) {
        return hasPermission('view_projects')
            || hasPermission('add_project')
            || hasPermission('edit_project_details')
            || hasPermission('view_mobilisation')
            || hasPermission('edit_services')
            || hasPermission('view_ohsa')
            || hasPermission('view_documentation')
            || hasPermission('view_drawings')
            || hasPermission('view_capital_projects')
            || hasPermission('view_nav_subcontractors')
            || hasPermission('manage_clients')
            || hasPermission('manage_professionals')
            || hasPermission('manage_subcontractors')
            || hasPermission('manage_users');
    }

    return hasPermission('view_projects')
        || hasPermission('view_mobilisation')
        || hasPermission('edit_services')
        || hasPermission('view_ohsa')
        || hasPermission('view_documentation')
        || hasPermission('view_drawings')
        || $GLOBALS['hasWorkSalesAccess']
        || hasPermission('view_capital_projects')
        || hasPermission('view_nav_subcontractors')
        || hasPermission('manage_clients')
        || hasPermission('manage_professionals')
        || hasPermission('manage_subcontractors')
        || hasPermission('manage_users')
        || isAdmin();
}

function navCanAccessSalesHub(): bool {
    if (navIsPlantOnlyRole()) {
        return false;
    }
    // Dedicated sales roles always use Sales Hub; everyone else needs explicit Property Sales cap
    if (navIsSalesAgentRole() || navIsSalesManagerRole()) {
        return true;
    }
    return isAdmin() || hasPermission('view_property_sales');
}

/** Matches sales_project_manager.php gate — frames, media, daily sync tooling. */
function navCanAccessSalesProjectManager(): bool {
    return in_array(getCurrentRole(), ['sales_manager', 'admin', 'director', 'system_manager'], true)
        || hasPermission('manage_sales_frames');
}

function navCanAccessPlantHub(): bool {
    return hasPermission('view_plant_bookings')
        || hasPermission('manage_plant_fleet')
        || in_array(getCurrentRole(), ['admin', 'director', 'accountant', 'plant_manager', 'plant_driver'], true);
}

function navCanAccessPlantDashboard(): bool {
    if (in_array(getCurrentRole(), ['admin', 'director'], true)) {
        return true;
    }
    return getCurrentRole() === 'system_manager' && hasPermission('manage_plant_fleet');
}

function navPlantHubHome(): string {
    return 'plant_hub.php';
}

function navUserHubs(): array {
    $hubs = [];
    if (navCanAccessEstateHub()) {
        $hubs[] = 'estate';
    }
    if (navCanAccessSalesHub()) {
        $hubs[] = 'sales';
    }
    if (navCanAccessPlantHub()) {
        $hubs[] = 'plant';
    }
    return $hubs;
}

function navHubMeta(): array {
    return [
        'estate' => [
            'label' => 'Estate Hub',
            'home'  => navIsLegalRep() ? 'projects.php' : 'dashboard.php',
            'class' => 'hub-estate',
        ],
        'sales' => [
            'label' => 'Sales Hub',
            'home'  => 'sales_hub.php',
            'class' => 'hub-sales',
        ],
        'plant' => [
            'label' => 'Plant Hub',
            'home'  => navPlantHubHome(),
            'class' => 'hub-plant',
        ],
    ];
}

function navPageHubMap(): array {
    return [
        'dashboard' => 'estate',
        'projects' => 'estate',
        'mobilization' => 'estate',
        'mobilisation_detail' => 'estate',
        'engineering' => 'estate',
        'ohsa' => 'estate',
        'documentation' => 'estate',
        'work_sales' => 'estate',
        'works_sales' => 'estate',
        'capital_projects' => 'estate',
        'subcontractor_accounts' => 'estate',
        'clients' => 'estate',
        'professionals-management' => 'estate',
        'subcontractors' => 'estate',
        'users-management' => 'estate',
        'create-project' => 'estate',
        'edit-project' => 'estate',
        'project-status' => 'estate',
        'map-view' => 'estate',
        'actions' => 'estate',
        'notifications' => 'estate',
        'admin_standard_rates' => 'estate',
        'profile' => 'estate',

        'sales_hub' => 'sales',
        'sales_project_manager' => 'sales',
        'import_key_simplified' => 'sales',

        'plant_bookings' => 'plant',
        'plant_hub' => 'plant',
        'plant_dashboard' => 'plant',
        'print_plant_invoice' => 'plant',
        'print_plant_pricelist' => 'plant',
    ];
}

function navDetectActiveHub(string $currentPage): string {
    $map = navPageHubMap();
    if (isset($map[$currentPage])) {
        return $map[$currentPage];
    }

    $hubs = navUserHubs();
    if (count($hubs) === 1) {
        return $hubs[0];
    }

    return $hubs[0] ?? 'estate';
}

function navHomeLink(): string {
    if (navIsLegalRep()) {
        return 'projects.php';
    }
    if (navIsSalesAgentRole()) {
        return 'sales_hub.php';
    }
    if (navIsPlantOnlyRole()) {
        return 'plant_bookings.php';
    }

    $hubs = navUserHubs();
    if (count($hubs) === 1) {
        return navHubMeta()[$hubs[0]]['home'];
    }

    $active = navDetectActiveHub($GLOBALS['currentPage'] ?? '');
    return navHubMeta()[$active]['home'] ?? 'dashboard.php';
}

function navShowWorkSales(): bool {
    if (navIsSalesManagerRole()) {
        return false;
    }
    return ($GLOBALS['hasWorkSalesAccess'] ?? false) || isAdmin();
}

function navEstateItems(): array {
    if (navIsLegalRep()) {
        return [
            ['type' => 'link', 'label' => 'Project Status', 'href' => 'projects.php', 'pages' => ['projects'], 'static' => true],
        ];
    }

    $items = [];

    if (!navIsSalesManagerRole() || hasPermission('view_projects') || hasPermission('add_project') || isAdmin()) {
        $items[] = ['type' => 'link', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'pages' => ['dashboard']];
    }

    $projectChildren = [];
    if (hasPermission('view_projects') || isAdmin()) {
        $projectChildren[] = ['label' => 'Execution Dashboard', 'href' => 'projects.php', 'pages' => ['projects']];
    }
    if (hasPermission('view_mobilisation') || isAdmin()) {
        $projectChildren[] = ['label' => 'Mobilisation Dashboard', 'href' => 'mobilization.php', 'pages' => ['mobilization']];
    }
    if (hasPermission('edit_services') || isAdmin()) {
        $projectChildren[] = ['label' => 'Engineering Dashboard', 'href' => 'engineering.php', 'pages' => ['engineering']];
    }
    if (hasPermission('add_project') || isAdmin()) {
        $projectChildren[] = ['label' => 'Create Project', 'href' => 'create-project.php', 'pages' => ['create-project']];
    }
    if (!empty($projectChildren)) {
        $items[] = [
            'type' => 'dropdown',
            'label' => 'Projects',
            'pages' => ['projects', 'mobilization', 'engineering', 'create-project', 'edit-project', 'mobilisation_detail', 'project-status'],
            'children' => $projectChildren,
        ];
    }

    $siteChildren = [];
    if (hasPermission('view_ohsa') || isAdmin()) {
        $siteChildren[] = ['label' => 'OHSA', 'href' => 'ohsa.php', 'pages' => ['ohsa']];
    }
    if (hasPermission('view_documentation') || isAdmin()) {
        $siteChildren[] = ['label' => 'Documentation', 'href' => 'documentation.php', 'pages' => ['documentation']];
    }
    if (!empty($siteChildren) && !navIsSalesManagerRole()) {
        $items[] = ['type' => 'dropdown', 'label' => 'Site & Docs', 'pages' => ['ohsa', 'documentation'], 'children' => $siteChildren];
    }

    $commercialChildren = [];
    if (navShowWorkSales()) {
        $commercialChildren[] = ['label' => 'Works Sales', 'href' => 'work_sales.php', 'pages' => ['work_sales', 'works_sales']];
    }
    if (hasPermission('view_capital_projects') || isAdmin()) {
        $commercialChildren[] = ['label' => 'Capital Projects', 'href' => 'capital_projects.php', 'pages' => ['capital_projects']];
    }
    if (hasPermission('view_nav_subcontractors') || isAdmin()) {
        $commercialChildren[] = ['label' => 'Subcontractor Accounts', 'href' => 'subcontractor_accounts.php', 'pages' => ['subcontractor_accounts']];
    }
    if (!empty($commercialChildren) && !navIsSalesManagerRole()) {
        $items[] = ['type' => 'dropdown', 'label' => 'Commercial', 'pages' => ['work_sales', 'works_sales', 'capital_projects', 'subcontractor_accounts'], 'children' => $commercialChildren];
    }

    $mgmtChildren = [];
    if (hasPermission('manage_clients') || isAdmin()) {
        $mgmtChildren[] = ['label' => 'Clients & Developers', 'href' => 'clients.php', 'pages' => ['clients']];
    }
    if (hasPermission('manage_professionals') || isAdmin()) {
        $mgmtChildren[] = ['label' => 'Professionals', 'href' => 'professionals-management.php', 'pages' => ['professionals-management']];
    }
    if (hasPermission('manage_subcontractors') || isAdmin()) {
        $mgmtChildren[] = ['label' => 'Subcontractors', 'href' => 'subcontractors.php', 'pages' => ['subcontractors']];
    }
    if (hasPermission('manage_users') || isAdmin()) {
        $mgmtChildren[] = ['label' => 'System Users', 'href' => 'users-management.php', 'pages' => ['users-management']];
    }
    if (isAdmin()) {
        $mgmtChildren[] = ['label' => 'Download Database Backup', 'href' => 'backup_db.php', 'pages' => ['backup_db'], 'class' => 'nav-admin-tool', 'confirm' => true];
    }
    if (!empty($mgmtChildren) && !navIsSalesManagerRole()) {
        $items[] = ['type' => 'dropdown', 'label' => 'Directory', 'pages' => ['clients', 'professionals-management', 'subcontractors', 'users-management', 'backup_db'], 'children' => $mgmtChildren];
    }

    return $items;
}

function navSalesItems(): array {
    $items = [
        ['type' => 'link', 'label' => 'Sales Map', 'href' => 'sales_hub.php', 'pages' => ['sales_hub']],
    ];

    if (navCanAccessSalesProjectManager()) {
        $items[] = ['type' => 'link', 'label' => 'Sales Management', 'href' => 'sales_project_manager.php', 'pages' => ['sales_project_manager']];
    }

    return $items;
}

function navPlantItems(): array {
    $items = [];

    if (hasPermission('view_plant_bookings') || in_array(getCurrentRole(), ['admin', 'accountant', 'plant_manager', 'plant_driver'], true)) {
        $items[] = ['type' => 'link', 'label' => 'Plant Operations', 'href' => 'plant_bookings.php', 'pages' => ['plant_bookings']];
    }
    if (navCanAccessPlantDashboard()) {
        $items[] = ['type' => 'link', 'label' => 'Fleet Dashboard', 'href' => 'plant_dashboard.php', 'pages' => ['plant_dashboard'], 'class' => 'nav-plant-accent'];
    }

    return $items;
}

function navItemsForHub(string $hub): array {
    switch ($hub) {
        case 'sales':
            return navSalesItems();
        case 'plant':
            return navPlantItems();
        default:
            return navEstateItems();
    }
}

function navIsItemActive(array $item, string $currentPage): bool {
    if (!empty($item['pages']) && in_array($currentPage, $item['pages'], true)) {
        return true;
    }
    if ($item['type'] === 'dropdown' && !empty($item['children'])) {
        foreach ($item['children'] as $child) {
            if (!empty($child['pages']) && in_array($currentPage, $child['pages'], true)) {
                return true;
            }
        }
    }
    return false;
}

}
