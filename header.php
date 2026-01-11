<?php
/**
 * header.php - Complete HTML header and navigation
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
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
    <?php if (isLoggedIn()): ?>
        <header class="header">
            <div class="header-container">
                <div class="header-left">
                    <img src="/logo.png" alt="Estate Hub Logo" class="logo-nav">
                    <div>
                        <h1 class="header-title">Estate Hub</h1>
                        <p class="header-subtitle">Malta</p>
                    </div>
                </div>
                
                <div class="header-right">
                    <a href="/dashboard.php" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
                    <a href="/mobilization.php" class="nav-link <?= $currentPage === 'mobilization' ? 'active' : '' ?>">Mobilization</a>
                   
                    
                    <?php if (isAdmin() || getCurrentRole() === 'manager'): ?>
                         <a href="/clients.php" class="nav-link <?= $currentPage === 'clients' ? 'active' : '' ?>">Clients</a>
                        <a href="/professionals-management.php" class="nav-link <?= $currentPage === 'professionals-management' ? 'active' : '' ?>">Professionals</a>
                    <?php endif; ?>
                    
                    <?php if (isAdmin()): ?>
                        <a href="/users-management.php" class="nav-link <?= $currentPage === 'users-management' ? 'active' : '' ?>">Users</a>
                    <?php endif; ?>
                    
                    <div style="display: flex; align-items: center; gap: 1rem; margin-left: 1rem; padding-left: 1rem; border-left: 1px solid rgba(255,255,255,0.1);">
                        <div style="text-align: right;">
                            <div style="font-weight: 600; color: #ffffff; font-size: 0.9rem;"><?= htmlspecialchars(getCurrentUserFullName()) ?></div>
                            <div style="font-size: 0.75rem; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.5px;"><?= htmlspecialchars(getCurrentRole()) ?></div>
                        </div>
                        <a href="api/logout.php" class="nav-link" style="padding: 0.5rem 1rem;">Logout</a>
                    </div>
                </div>
            </div>
        </header>
    <?php endif; ?>
    
    <main class="main-content">
