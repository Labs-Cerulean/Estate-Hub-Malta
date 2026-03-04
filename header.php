<?php
/**
 * header.php - Complete HTML header and navigation
 */
if (!function_exists('isLoggedIn')) {
    die('Error: init.php must be included before header.php');
}

$pageTitle = $pageTitle ?? 'Estate Hub';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Define visibility for Dropdowns based on user capabilities
$showSiteDocs = hasPermission('view_ohsa') || hasPermission('view_documentation') || hasPermission('view_drawings') || isAdmin();
$showCommercial = hasPermission('view_works_sales') || hasPermission('view_property_sales') || hasPermission('view_capital_projects') || isAdmin();
$showManagement = hasPermission('manage_clients') || hasPermission('manage_professionals') || hasPermission('manage_subcontractors') || hasPermission('manage_users') || isAdmin();

// Fetch Pending Actions Count
$pendingActionsCount = 0;
if (isLoggedIn() && isset($pdo)) {
    $stmtAct = $pdo->prepare("SELECT COUNT(*) FROM project_logs WHERE assigned_to = ? AND status = 'Action - Pending'");
    $stmtAct->execute([getCurrentUserId()]);
    $pendingActionsCount = $stmtAct->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Estate Hub</title>
    <link rel="stylesheet" href="/styles.css?v=<?= time() ?>"> 
</head>
<body>
    <?php if (isLoggedIn()): ?>
        <header class="header">
            <div class="header-container">
                <div class="header-left">
                    <a href="dashboard.php" style="display: flex; align-items: center; gap: 1rem; text-decoration: none;">
                        <img src="/logo.png" alt="Estate Hub Logo" class="logo-nav">
                        <div>
                            <h1 class="header-title">Estate Hub</h1>
                            <p class="header-subtitle">Malta</p>
                        </div>
                    </a>
                </div>
                
                <div class="header-right">
                    
                    <a href="dashboard.php" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
                    
                    <?php if (hasPermission('view_projects') || isAdmin()): ?>
                        <a href="projects.php" class="nav-link <?= $currentPage === 'projects' ? 'active' : '' ?>">Projects</a>
                    <?php endif; ?>

                    

                    <?php if (hasPermission('view_mobilisation') || isAdmin()): ?>
                        <a href="mobilization.php" class="nav-link <?= $currentPage === 'mobilization' ? 'active' : '' ?>">Mobilisation</a>
                    <?php endif; ?>

                    <?php if ($showSiteDocs): ?>
                    <div class="nav-dropdown">
                        <span class="nav-link <?= in_array($currentPage, ['ohsa', 'documentation', 'drawings']) ? 'active' : '' ?>">
                            Site & Docs ▾
                        </span>
                        <div class="dropdown-content">
                            <?php if (hasPermission('view_ohsa') || isAdmin()): ?>
                                <a href="ohsa.php" class="<?= $currentPage === 'ohsa' ? 'active' : '' ?>">OHSA</a>
                            <?php endif; ?>
                            <?php if (hasPermission('view_documentation') || isAdmin()): ?>
                                <a href="documentation.php" class="<?= $currentPage === 'documentation' ? 'active' : '' ?>">Documentation</a>
                            <?php endif; ?>
                            <?php if (hasPermission('view_drawings') || isAdmin()): ?>
                                <a href="drawings.php" class="<?= $currentPage === 'drawings' ? 'active' : '' ?>">Drawings</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($showCommercial): ?>
                    <div class="nav-dropdown">
                        <span class="nav-link <?= in_array($currentPage, ['works_sales', 'property_sales', 'capital_projects']) ? 'active' : '' ?>">
                            Commercial ▾
                        </span>
                        <div class="dropdown-content">
                            <?php if (hasPermission('view_works_sales') || isAdmin()): ?>
                                <a href="works_sales.php" class="<?= $currentPage === 'works_sales' ? 'active' : '' ?>">Works Sales</a>
                            <?php endif; ?>
                            <?php if (hasPermission('view_property_sales') || isAdmin()): ?>
                                <a href="property_sales.php" class="<?= $currentPage === 'property_sales' ? 'active' : '' ?>">Property Sales</a>
                            <?php endif; ?>
                            <?php if (hasPermission('view_capital_projects') || isAdmin()): ?>
                                <a href="capital_projects.php" class="<?= $currentPage === 'capital_projects' ? 'active' : '' ?>">Capital Projects</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($showManagement): ?>
                    <div class="nav-dropdown">
                        <span class="nav-link <?= in_array($currentPage, ['clients', 'professionals-management', 'subcontractors', 'users-management']) ? 'active' : '' ?>">
                            Management ▾
                        </span>
                        <div class="dropdown-content">
                            <?php if (hasPermission('manage_clients') || isAdmin()): ?>
                                <a href="clients.php" class="<?= $currentPage === 'clients' ? 'active' : '' ?>">Clients & Developers</a>
                            <?php endif; ?>
                            <?php if (hasPermission('manage_professionals') || isAdmin()): ?>
                                <a href="professionals-management.php" class="<?= $currentPage === 'professionals-management' ? 'active' : '' ?>">Professionals</a>
                            <?php endif; ?>
                            <?php if (hasPermission('manage_subcontractors') || isAdmin()): ?>
                                <a href="subcontractors.php" class="<?= $currentPage === 'subcontractors' ? 'active' : '' ?>">Subcontractors</a>
                            <?php endif; ?>
                            <?php if (hasPermission('manage_users') || isAdmin()): ?>
                                <a href="users-management.php" class="<?= $currentPage === 'users-management' ? 'active' : '' ?>">System Users</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php 
                    $unreadCount = getUnreadNotificationCount($pdo, getCurrentUserId());
                    $badgeColor = $unreadCount > 0 ? '#EF4444' : '#6B7280';
                    ?>
                    <a href="notifications.php" class="nav-link <?= $currentPage === 'notifications' ? 'active' : '' ?>" style="position: relative;">
                        Notifications
                        <?php if ($unreadCount > 0): ?>
                            <span class="notification-badge" style="position: absolute; top: -8px; right: -8px; background: <?= $badgeColor ?>; color: white; border-radius: 50%; min-width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; padding: 0 5px;">
                                <?= $unreadCount ?>
                            </span>
                        <?php else: ?>
                            <span class="notification-badge-zero" style="position: absolute; top: -8px; right: -8px; background: <?= $badgeColor ?>; color: white; border-radius: 50%; width: 10px; height: 10px;"></span>
                        <?php endif; ?>
                    </a>
                    
                    <?php $actBadgeColor = $pendingActionsCount > 0 ? '#F59E0B' : '#6B7280'; ?>
                    <a href="actions.php" class="nav-link <?= $currentPage === 'actions' ? 'active' : '' ?>" style="position: relative;">
                        Actions
                        <?php if ($pendingActionsCount > 0): ?>
                            <span class="notification-badge" style="position: absolute; top: -8px; right: -8px; background: <?= $actBadgeColor ?>; color: white; border-radius: 50%; min-width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; padding: 0 5px;">
                                <?= $pendingActionsCount ?>
                            </span>
                        <?php else: ?>
                            <span class="notification-badge-zero" style="position: absolute; top: -8px; right: -8px; background: <?= $actBadgeColor ?>; color: white; border-radius: 50%; width: 10px; height: 10px;"></span>
                        <?php endif; ?>
                    </a>
                    
                    <div style="display: flex; align-items: center; gap: 1rem; margin-left: 1rem; padding-left: 1rem; border-left: 1px solid rgba(255,255,255,0.1);">
                        <a href="profile.php" style="text-align: right; text-decoration: none; color: inherit; display: block;" class="profile-nav-item">
                            <div style="font-weight: 600; color: #ffffff; font-size: 0.9rem;"><?= htmlspecialchars(getCurrentUserFullName()) ?></div>
                            <div style="font-size: 0.75rem; color: var(--primary-color); text-transform: uppercase; letter-spacing: 0.5px;"><?= htmlspecialchars(str_replace('_', ' ', getCurrentRole())) ?></div>
                        </a>
                        <a href="api/logout.php" class="nav-link" style="padding: 0.5rem 1rem;">Logout</a>
                    </div>

                </div>
            </div>
        </header>
    <?php endif; ?>
    
    <main class="main-content">
