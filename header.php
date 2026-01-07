<?php
/**
 * header.php - HTML header output only
 * Must be included AFTER init.php and AFTER any header() redirects
 */

// This file should only be included after init.php
if (!function_exists('isLoggedIn')) {
    die('Error: init.php must be included before header.php');
}

$pageTitle = $pageTitle ?? 'Estate Hub';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Estate Hub</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <nav class="main-nav">
        <div class="nav-container">
            <div class="nav-brand">
                <img src="logo.jpg" alt="Estate Hub Logo" class="nav-logo">
                <span class="nav-title">Estate Hub</span>
            </div>
            
            <?php if (isLoggedIn()): ?>
                <ul class="nav-links">
                    <li><a href="dashboard.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a></li>
                    <li><a href="mobilization.php" class="<?= $currentPage === 'mobilization' ? 'active' : '' ?>">Mobilization</a></li>
                    <li><a href="clients.php" class="<?= $currentPage === 'clients' ? 'active' : '' ?>">Clients</a></li>
                    
                    <?php if (isAdmin()): ?>
                        <li><a href="users-management.php" class="<?= $currentPage === 'users-management' ? 'active' : '' ?>">Users</a></li>
                        <li><a href="professionals-management.php" class="<?= $currentPage === 'professionals-management' ? 'active' : '' ?>">Professionals</a></li>
                    <?php endif; ?>
                </ul>
                
                <div class="nav-user">
                    <span class="user-name"><?= htmlspecialchars(getCurrentUserFullName()) ?></span>
                    <span class="user-role">(<?= htmlspecialchars(getCurrentRole()) ?>)</span>
                    <a href="logout.php" class="btn-logout">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>
    
    <main class="main-content">
