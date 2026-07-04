<?php
/**
 * header.php - Complete HTML header and navigation with three-hub switcher.
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
$isPlantShell = navIsPlantOnlyRole();
$isSalesAgentShell = navIsSalesAgentRole();
$showEstateUtilities = !$isPlantShell && !$isSalesAgentShell;
$navItems = navItemsForHub($activeHub);
$unreadCount = (isLoggedIn() && isset($pdo)) ? getUnreadNotificationCount($pdo, getCurrentUserId()) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Estate Hub</title>
    <link rel="stylesheet" href="/styles.css?v=<?= time() ?>">
    <script src="/assets/js/entity-select.js?v=<?= time() ?>" defer></script>
</head>
<body>
    <?php if (isLoggedIn()): ?>
        <header class="header">
            <div class="header-container">
                <div class="header-left">
                    <a href="<?= htmlspecialchars($homeLink) ?>" class="header-brand">
                        <img src="/logo.png" alt="Estate Hub Logo" class="logo-nav">
                        <div>
                            <h1 class="header-title">Estate Hub</h1>
                            <p class="header-subtitle">Malta</p>
                        </div>
                    </a>
                </div>

                <div class="header-right">
                    <?php if ($showHubSwitcher): ?>
                        <nav class="hub-switcher" aria-label="Hub selector">
                            <?php foreach ($userHubs as $hubKey): ?>
                                <?php $meta = $hubMeta[$hubKey]; ?>
                                <a href="<?= htmlspecialchars($meta['home']) ?>"
                                   class="hub-tab <?= $meta['class'] ?><?= $activeHub === $hubKey ? ' active' : '' ?>">
                                    <?= htmlspecialchars($meta['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                    <?php elseif (count($userHubs) === 1): ?>
                        <span class="hub-single-label <?= htmlspecialchars($hubMeta[$userHubs[0]]['class']) ?>">
                            <?= htmlspecialchars($hubMeta[$userHubs[0]]['label']) ?>
                        </span>
                    <?php endif; ?>

                    <nav class="header-nav" aria-label="Main navigation">
                        <?php foreach ($navItems as $item): ?>
                            <?php if ($item['type'] === 'link'): ?>
                                <?php if (!empty($item['static'])): ?>
                                    <span class="nav-link active" style="cursor: default;"><?= htmlspecialchars($item['label']) ?></span>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars($item['href']) ?>"
                                       class="nav-link<?= navIsItemActive($item, $currentPage) ? ' active' : '' ?><?= !empty($item['class']) ? ' ' . htmlspecialchars($item['class']) : '' ?>">
                                        <?= htmlspecialchars($item['label']) ?>
                                    </a>
                                <?php endif; ?>
                            <?php elseif ($item['type'] === 'dropdown'): ?>
                                <div class="nav-dropdown">
                                    <button type="button"
                                            class="nav-link nav-dropdown-toggle<?= navIsItemActive($item, $currentPage) ? ' active' : '' ?>"
                                            aria-haspopup="true"
                                            aria-expanded="false">
                                        <?= htmlspecialchars($item['label']) ?> <span class="nav-chevron">▾</span>
                                    </button>
                                    <div class="dropdown-content">
                                        <?php foreach ($item['children'] as $child): ?>
                                            <?php
                                            $childActive = !empty($child['pages']) && in_array($currentPage, $child['pages'], true);
                                            ?>
                                            <a href="<?= htmlspecialchars($child['href']) ?>"
                                               class="<?= $childActive ? 'active' : '' ?><?= !empty($child['class']) ? ' ' . htmlspecialchars($child['class']) : '' ?>"
                                               <?php if (!empty($child['confirm'])): ?>onclick="return confirm('Download a full database backup?');"<?php endif; ?>>
                                                <?= htmlspecialchars($child['label']) ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>

                    <?php if ($showEstateUtilities): ?>
                        <div class="header-utilities">
                            <a href="notifications.php" class="nav-link nav-utility<?= $currentPage === 'notifications' ? ' active' : '' ?>" title="Notifications">
                                <span class="nav-utility-icon">🔔</span>
                                <span class="nav-utility-label">Notifications</span>
                                <?php if ($unreadCount > 0): ?>
                                    <span class="nav-badge nav-badge-danger"><?= (int)$unreadCount ?></span>
                                <?php endif; ?>
                            </a>

                            <a href="actions.php" class="nav-link nav-utility<?= $currentPage === 'actions' ? ' active' : '' ?>" title="Actions">
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
                            <div class="dropdown-content dropdown-content-right">
                                <a href="profile.php" class="<?= $currentPage === 'profile' ? 'active' : '' ?>">My Profile</a>
                                <a href="api/logout.php">Logout</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.nav-dropdown-toggle').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var parent = btn.closest('.nav-dropdown');
                    var open = parent.classList.contains('is-open');
                    document.querySelectorAll('.nav-dropdown.is-open').forEach(function (el) {
                        el.classList.remove('is-open');
                        el.querySelector('.nav-dropdown-toggle')?.setAttribute('aria-expanded', 'false');
                    });
                    if (!open) {
                        parent.classList.add('is-open');
                        btn.setAttribute('aria-expanded', 'true');
                    }
                });
            });
            document.addEventListener('click', function () {
                document.querySelectorAll('.nav-dropdown.is-open').forEach(function (el) {
                    el.classList.remove('is-open');
                    el.querySelector('.nav-dropdown-toggle')?.setAttribute('aria-expanded', 'false');
                });
            });
        });
        </script>
    <?php endif; ?>

    <main class="main-content">
