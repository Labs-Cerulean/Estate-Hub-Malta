<?php
/**
 * header.php - Site header with hub switcher (top) and navigation (below).
 */
if (!function_exists('isLoggedIn')) {
    die('Error: init.php must be included before header.php');
}

$pageTitle = $pageTitle ?? 'Estate Hub';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$userRole = getCurrentRole();

$hasWorkSalesAccess = hasPermission('view_works_sales')
    || hasPermission('view_sales_demo_exc')
    || hasPermission('view_sales_const')
    || hasPermission('view_sales_finishes')
    || hasPermission('view_sales_ohsa');

require_once __DIR__ . '/includes/nav_config.php';

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
        if ($avatarKey) {
            $_SESSION['avatar_key'] = $avatarKey;
        }
    }
    if ($avatarKey) {
        require_once __DIR__ . '/S3FileManager.php';
        try {
            $s3Header = new S3FileManager();
            $headerAvatarUrl = $s3Header->getPresignedUrl($avatarKey, '+24 hours');
        } catch (Exception $e) {}
    }
}

$homeLink = navHomeLink();
$userHubs = navUserHubs();
$hubMeta = navHubMeta();
$activeHub = navDetectActiveHub($currentPage);
$showHubSwitcher = count($userHubs) > 1;
// Sales Hub pages (map, library, management) are a focused workspace — no Estate notifications/actions
$showEstateUtilities = navCanAccessEstateHub() && $activeHub !== 'sales';
$navItems = navItemsForHub($activeHub);
$unreadCount = (isLoggedIn() && isset($pdo)) ? getUnreadNotificationCount($pdo, getCurrentUserId()) : 0;

function headerRenderNavItems(array $navItems, string $currentPage, string $extraClass = ''): void {
    foreach ($navItems as $item) {
        if ($item['type'] === 'link') {
            if (!empty($item['static'])) {
                echo '<span class="nav-link active' . $extraClass . '" style="cursor: default;">' . htmlspecialchars($item['label']) . '</span>';
            } else {
                $active = navIsItemActive($item, $currentPage) ? ' active' : '';
                $class = !empty($item['class']) ? ' ' . htmlspecialchars($item['class']) : '';
                echo '<a href="' . htmlspecialchars($item['href']) . '" class="nav-link' . $active . $class . $extraClass . '">' . htmlspecialchars($item['label']) . '</a>';
            }
        } elseif ($item['type'] === 'dropdown') {
            $active = navIsItemActive($item, $currentPage) ? ' active' : '';
            echo '<div class="nav-dropdown' . ($extraClass ? ' mobile-nav-group' : '') . '">';
            if ($extraClass) {
                echo '<div class="mobile-nav-group-label">' . htmlspecialchars($item['label']) . '</div>';
                echo '<div class="mobile-nav-group-links">';
                foreach ($item['children'] as $child) {
                    $childActive = !empty($child['pages']) && in_array($currentPage, $child['pages'], true) ? ' active' : '';
                    $childClass = !empty($child['class']) ? ' ' . htmlspecialchars($child['class']) : '';
                    $confirm = !empty($child['confirm']) ? ' onclick="return confirm(\'Download a full database backup?\');"' : '';
                    echo '<a href="' . htmlspecialchars($child['href']) . '" class="nav-link' . $childActive . $childClass . '"' . $confirm . '>' . htmlspecialchars($child['label']) . '</a>';
                }
                echo '</div></div>';
            } else {
                echo '<button type="button" class="nav-link nav-dropdown-toggle' . $active . '" aria-haspopup="true" aria-expanded="false">';
                echo htmlspecialchars($item['label']) . ' <span class="nav-chevron">▾</span></button>';
                echo '<div class="dropdown-content">';
                foreach ($item['children'] as $child) {
                    $childActive = !empty($child['pages']) && in_array($currentPage, $child['pages'], true) ? ' active' : '';
                    $childClass = !empty($child['class']) ? ' ' . htmlspecialchars($child['class']) : '';
                    $confirm = !empty($child['confirm']) ? ' onclick="return confirm(\'Download a full database backup?\');"' : '';
                    echo '<a href="' . htmlspecialchars($child['href']) . '" class="' . trim($childActive . $childClass) . '"' . $confirm . '>' . htmlspecialchars($child['label']) . '</a>';
                }
                echo '</div></div>';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Estate Hub</title>
    <link rel="stylesheet" href="/styles.css?v=<?= time() ?>">
    <script src="/assets/js/entity-select.js?v=<?= time() ?>" defer></script>
    <script src="/assets/js/header-nav.js?v=<?= time() ?>" defer></script>
</head>
<body>
    <?php if (isLoggedIn()): ?>
        <header class="site-header">
            <div class="header-container">
                <div class="header-top">
                    <a href="<?= htmlspecialchars($homeLink) ?>" class="header-brand">
                        <img src="/logo.png" alt="Estate Hub Logo" class="logo-nav">
                        <div class="header-brand-text">
                            <span class="header-title">Estate Hub</span>
                            <span class="header-subtitle">Malta</span>
                        </div>
                    </a>

                    <div class="header-hub-center">
                        <?php if ($showHubSwitcher): ?>
                            <nav class="hub-switcher" aria-label="Hub selector">
                                <?php foreach ($userHubs as $hubKey): ?>
                                    <?php $meta = $hubMeta[$hubKey]; ?>
                                    <a href="<?= htmlspecialchars($meta['home']) ?>"
                                       class="hub-tab <?= htmlspecialchars($meta['class'] ?? '') ?><?= $activeHub === $hubKey ? ' active' : '' ?>">
                                        <?= htmlspecialchars($meta['label']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </nav>
                        <?php elseif (count($userHubs) === 1): ?>
                            <span class="hub-single-label <?= htmlspecialchars($hubMeta[$userHubs[0]]['class']) ?>">
                                <?= htmlspecialchars($hubMeta[$userHubs[0]]['label']) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="header-top-actions">
                        <?php if ($showEstateUtilities): ?>
                            <div class="header-utilities">
                                <a href="/notifications.php" class="nav-link nav-utility<?= $currentPage === 'notifications' ? ' active' : '' ?>" title="Notifications">
                                    <span class="nav-utility-icon">🔔</span>
                                    <span class="nav-utility-label">Notifications</span>
                                    <?php if ($unreadCount > 0): ?>
                                        <span class="nav-badge nav-badge-danger"><?= (int)$unreadCount ?></span>
                                    <?php endif; ?>
                                </a>
                                <a href="/actions.php" class="nav-link nav-utility<?= $currentPage === 'actions' ? ' active' : '' ?>" title="Actions">
                                    <span class="nav-utility-icon">✓</span>
                                    <span class="nav-utility-label">Actions</span>
                                    <?php if ($pendingActionsCount > 0): ?>
                                        <span class="nav-badge nav-badge-warning"><?= (int)$pendingActionsCount ?></span>
                                    <?php endif; ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <div class="header-user-menu">
                            <div class="nav-dropdown nav-profile-dropdown">
                                <a href="/profile.php" class="user-menu-btn" title="My Profile">
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
                                <div class="dropdown-content dropdown-content-right">
                                    <a href="/profile.php" class="<?= $currentPage === 'profile' ? 'active' : '' ?>">My Profile</a>
                                    <a href="/api/logout.php">Logout</a>
                                </div>
                            </div>
                        </div>

                        <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-label="Open menu" aria-expanded="false">
                            <span></span><span></span><span></span>
                        </button>
                    </div>
                </div>

                <div class="header-nav-row">
                    <nav class="header-nav desktop-nav" aria-label="Main navigation">
                        <?php headerRenderNavItems($navItems, $currentPage); ?>
                    </nav>
                </div>
            </div>

            <div class="mobile-nav-overlay" id="mobileNavOverlay" hidden>
                <div class="mobile-nav-drawer" role="dialog" aria-label="Mobile navigation">
                    <div class="mobile-nav-drawer-head">
                        <strong>Menu</strong>
                        <button type="button" class="mobile-nav-close" id="mobileNavClose" aria-label="Close menu">&times;</button>
                    </div>
                    <?php if ($showHubSwitcher): ?>
                        <nav class="hub-switcher mobile-hub-switcher" aria-label="Hub selector">
                            <?php foreach ($userHubs as $hubKey): ?>
                                <?php $meta = $hubMeta[$hubKey]; ?>
                                <a href="<?= htmlspecialchars($meta['home']) ?>"
                                   class="hub-tab <?= htmlspecialchars($meta['class'] ?? '') ?><?= $activeHub === $hubKey ? ' active' : '' ?>">
                                    <?= htmlspecialchars($meta['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>
                    <nav class="mobile-nav" aria-label="Mobile main navigation">
                        <?php headerRenderNavItems($navItems, $currentPage, ' mobile-nav-link'); ?>
                        <?php if ($showEstateUtilities): ?>
                            <a href="/notifications.php" class="nav-link mobile-nav-link<?= $currentPage === 'notifications' ? ' active' : '' ?>">Notifications<?= $unreadCount > 0 ? ' (' . (int)$unreadCount . ')' : '' ?></a>
                            <a href="/actions.php" class="nav-link mobile-nav-link<?= $currentPage === 'actions' ? ' active' : '' ?>">Actions<?= $pendingActionsCount > 0 ? ' (' . (int)$pendingActionsCount . ')' : '' ?></a>
                        <?php endif; ?>
                        <a href="/profile.php" class="nav-link mobile-nav-link">My Profile</a>
                        <a href="/api/logout.php" class="nav-link mobile-nav-link">Logout</a>
                    </nav>
                </div>
            </div>
        </header>
    <?php endif; ?>

    <main class="main-content">
