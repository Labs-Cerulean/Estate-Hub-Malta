<?php
/**
 * header.php - Complete HTML header and navigation
 */
if (!function_exists('isLoggedIn')) {
    die('Error: init.php must be included before header.php');
}

$pageTitle = $pageTitle ?? 'Estate Hub';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$userRole = getCurrentRole(); // Fetch the role once for easy routing

// Define visibility for Dropdowns based on user capabilities
$showProjects = hasPermission('view_projects') || hasPermission('view_mobilisation') || hasPermission('edit_services') || isAdmin();
$showSiteDocs = hasPermission('view_ohsa') || hasPermission('view_documentation') || hasPermission('view_drawings') || isAdmin();

// Check if user has ANY Work Sales access (Generic or Granular)
$hasWorkSalesAccess = hasPermission('view_works_sales') || hasPermission('view_sales_demo_exc') || hasPermission('view_sales_const') || hasPermission('view_sales_finishes') || hasPermission('view_sales_ohsa');

$showCommercial = $hasWorkSalesAccess || hasPermission('view_property_sales') || hasPermission('view_capital_projects') || hasPermission('view_nav_subcontractors') || isAdmin();

// FIXED: Added view_plant_bookings and native Plant/Accountant roles to ensure the dropdown renders
$showManagement = hasPermission('manage_clients') || hasPermission('manage_professionals') || hasPermission('manage_subcontractors') || hasPermission('manage_users') || hasPermission('view_plant_bookings') || in_array($userRole, ['admin', 'director', 'accountant', 'plant_manager']);

// Fetch Pending Actions Count
$pendingActionsCount = 0;
$headerAvatarUrl = null;
$headerInitials = '';
if (isLoggedIn() && isset($pdo)) {
    $stmtAct = $pdo->prepare("SELECT COUNT(*) FROM project_logs WHERE assigned_to = ? AND status = 'Action - Pending'");
    $stmtAct->execute([getCurrentUserId()]);
    $pendingActionsCount = $stmtAct->fetchColumn();

    $headerInitials = getUserInitials($_SESSION['first_name'] ?? '', $_SESSION['last_name'] ?? '', $_SESSION['username'] ?? 'U');

    $avatarKey = $_SESSION['avatar_key'] ?? null;
    if (!$avatarKey) {
        $stmtAv = $pdo->prepare("SELECT avatar_key FROM users WHERE id = ?");
        $stmtAv->execute([getCurrentUserId()]);
        $avatarKey = $stmtAv->fetchColumn();
        if ($avatarKey) $_SESSION['avatar_key'] = $avatarKey;
    }
    if ($avatarKey) {
        require_once __DIR__ . '/S3FileManager.php';
        try {
            $s3Header = new S3FileManager();
            $headerAvatarUrl = $s3Header->getPresignedUrl($avatarKey, '+24 hours');
        } catch (Exception $e) {}
    }
}

// Determine Home Link based on Role (Sales Managers and Agents go to Sales Hub)
if ($userRole === 'legal_representative') {
    $homeLink = 'projects.php';
} elseif (in_array($userRole, ['sales_agent', 'sales_manager'])) {
    $homeLink = 'sales_hub.php';
} else {
    $homeLink = 'dashboard.php';
}
$isLegalRep = ($userRole === 'legal_representative');
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
                    <a href="<?= $homeLink ?>" style="display: flex; align-items: center; gap: 1rem; text-decoration: none;">
                        <img src="/logo.png" alt="Estate Hub Logo" class="logo-nav">
                        <div>
                            <h1 class="header-title">Estate Hub</h1>
                            <p class="header-subtitle">Malta</p>
                        </div>
                    </a>
                </div>
                
                <div class="header-right">
                    
                    <?php if ($isLegalRep): ?>
                        <span class="nav-link active" style="cursor: default;">Project Status</span>
                    <?php elseif (!in_array($userRole, ['sales_agent', 'sales_manager'])): ?>
                        <a href="dashboard.php" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">Portfolio</a>
                    <?php endif; ?>

                    <?php if (!$isLegalRep && (in_array($userRole, ['sales_manager', 'sales_agent', 'admin', 'director']) || hasPermission('view_property_sales'))): ?>
                        <a href="sales_hub.php" class="nav-link <?= $currentPage === 'sales_hub' ? 'active' : '' ?>">Sales Hub</a>
                    <?php endif; ?>
                    
                    <?php if (!$isLegalRep && !in_array($userRole, ['sales_agent', 'sales_manager'])): ?>
                    
                        <?php if ($showProjects): ?>
                        <div class="nav-dropdown">
                            <span class="nav-link <?= in_array($currentPage, ['projects', 'mobilization', 'engineering']) ? 'active' : '' ?>">
                                Projects ▾
                            </span>
                            <div class="dropdown-content">
                                <?php if (hasPermission('view_projects') || isAdmin()): ?>
                                    <a href="projects.php" class="<?= $currentPage === 'projects' ? 'active' : '' ?>">Execution Dashboard</a>
                                <?php endif; ?>
                                <?php if (hasPermission('view_mobilisation') || isAdmin()): ?>
                                    <a href="mobilization.php" class="<?= $currentPage === 'mobilization' ? 'active' : '' ?>">Mobilisation Dashboard</a>
                                <?php endif; ?>
                                <?php if (hasPermission('edit_services') || isAdmin()): ?>
                                    <a href="engineering.php" class="<?= $currentPage === 'engineering' ? 'active' : '' ?>">Engineering Dashboard</a>
                                <?php endif; ?>
                            </div>
                        </div>
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
                            <span class="nav-link <?= in_array($currentPage, ['work_sales', 'works_sales', 'property_sales', 'capital_projects', 'subcontractor_accounts']) ? 'active' : '' ?>">
                                Capital & Commercial ▾
                            </span>
                            <div class="dropdown-content">
                                <?php if ($hasWorkSalesAccess || isAdmin()): ?>
                                    <a href="work_sales.php" class="<?= in_array($currentPage, ['work_sales', 'works_sales']) ? 'active' : '' ?>">Works Sales</a>
                                <?php endif; ?>
                                <?php if (hasPermission('view_property_sales') || isAdmin()): ?>
                                    <a href="property_sales.php" class="<?= $currentPage === 'property_sales' ? 'active' : '' ?>">Property Sales</a>
                                <?php endif; ?>
                                <?php if (hasPermission('view_capital_projects') || isAdmin()): ?>
                                    <a href="capital_projects.php" class="<?= $currentPage === 'capital_projects' ? 'active' : '' ?>">Capital Projects</a>
                                <?php endif; ?>
                                <?php if (hasPermission('view_nav_subcontractors') || isAdmin()): ?>
                                    <a href="subcontractor_accounts.php" class="<?= $currentPage === 'subcontractor_accounts' ? 'active' : '' ?>">Subcontractor Accounts</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($showManagement): ?>
                        <div class="nav-dropdown">
                            <span class="nav-link <?= in_array($currentPage, ['clients', 'professionals-management', 'subcontractors', 'users-management', 'plant_dashboard']) ? 'active' : '' ?>">
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
                                
                               <?php if (in_array($userRole, ['admin', 'director']) || ($userRole === 'system_manager' && hasPermission('manage_plant_fleet'))): ?>
                                    <a href="plant_dashboard.php" class="<?= $currentPage === 'plant_dashboard' ? 'active' : '' ?>" style="color: #FF9800; font-weight: 800; border-top: 1px solid rgba(255,255,255,0.05); margin-top: 5px; padding-top: 10px;">
                                        <i class="fas fa-chart-line"></i> Fleet Dashboard
                                    </a>
                                <?php endif; ?>

                                <?php if (hasPermission('view_plant_bookings') || in_array($userRole, ['admin', 'accountant', 'plant_manager'])): ?>
                                    <a href="plant_bookings.php" target="_blank" style="color: #FF9800; font-weight: 800;">
                                        <i class="fas fa-tractor"></i> Plant Operations Hub
                                    </a>
                                <?php endif; ?>
                                
                                <?php if (isAdmin()): ?>
                                    <a href="backup_db.php" target="_blank" style="color: #10B981; font-weight: bold; border-top: 1px solid rgba(255,255,255,0.1); margin-top: 5px; padding-top: 10px;">
                                        💾 Download Database Backup
                                    </a>
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
                    
                    <?php endif; /* END RESTRICTION WRAPPER */ ?>
                    
                    <div class="header-user-menu" style="display: flex; align-items: center; gap: 1rem; margin-left: 1rem; padding-left: 1rem; border-left: 1px solid rgba(255,255,255,0.1);">
                        <a href="profile.php" class="user-menu-btn" title="My Profile">
                            <span class="user-avatar">
                                <?php if ($headerAvatarUrl): ?>
                                    <img src="<?= htmlspecialchars($headerAvatarUrl) ?>" alt="">
                                <?php else: ?>
                                    <?= htmlspecialchars($headerInitials) ?>
                                <?php endif; ?>
                            </span>
                            <span class="user-menu-text">
                                <span class="user-menu-name"><?= htmlspecialchars(getCurrentUserFullName()) ?></span>
                                <span class="user-menu-role"><?= htmlspecialchars(str_replace('_', ' ', getCurrentRole())) ?></span>
                            </span>
                            <span class="user-menu-chevron">▾</span>
                        </a>
                        <a href="api/logout.php" class="nav-link" style="padding: 0.5rem 1rem;">Logout</a>
                    </div>

                </div>
            </div>
        </header>
    <?php endif; ?>
    
    <main class="main-content">
