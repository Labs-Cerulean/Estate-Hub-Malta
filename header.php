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
    <style>
        /* Inline critical styles as fallback */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #0a0e27;
            color: #e0e7ff;
            min-height: 100vh;
        }
        
        .main-nav {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 1rem 0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }
        
        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .nav-logo {
            height: 50px;
            width: auto;
            border-radius: 8px;
        }
        
        .nav-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
        }
        
        .nav-links {
            display: flex;
            list-style: none;
            gap: 2rem;
        }
        
        .nav-links a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: background 0.3s;
        }
        
        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .nav-user {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: white;
        }
        
        .user-name {
            font-weight: 600;
        }
        
        .user-role {
            opacity: 0.8;
            font-size: 0.9rem;
        }
        
        .btn-logout {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .btn-logout:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .main-content {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
    </style>
</head>
<body>
    <?php if (isLoggedIn()): ?>
        <nav class="main-nav">
            <div class="nav-container">
                <div class="nav-brand">
                    <img src="/logo.png" alt="Estate Hub Logo" class="nav-logo">
                    <span class="nav-title">Estate Hub</span>
                </div>
                
                <ul class="nav-links">
                    <li><a href="/dashboard.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a></li>
                    <li><a href="/mobilization.php" class="<?= $currentPage === 'mobilization' ? 'active' : '' ?>">Mobilization</a></li>
                    <li><a href="/clients.php" class="<?= $currentPage === 'clients' ? 'active' : '' ?>">Clients</a></li>
                    
                    <?php if (isAdmin()): ?>
                        <li><a href="/users-management.php" class="<?= $currentPage === 'users-management' ? 'active' : '' ?>">Users</a></li>
                        <li><a href="/professionals-management.php" class="<?= $currentPage === 'professionals-management' ? 'active' : '' ?>">Professionals</a></li>
                    <?php endif; ?>
                </ul>
                
                <div class="nav-user">
                    <span class="user-name"><?= htmlspecialchars(getCurrentUserFullName()) ?></span>
                    <span class="user-role">(<?= htmlspecialchars(getCurrentRole()) ?>)</span>
                    <a href="api/logout.php" class="btn-logout">Logout</a>
                </div>
            </div>
        </nav>
    <?php endif; ?>
    
    <main class="main-content">
