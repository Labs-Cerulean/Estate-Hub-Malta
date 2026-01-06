<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
require_once 'user-functions.php';
requireLogin(); // Redirects if not logged in[file:10][file:3]
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' : ''; ?>Estate Hub</title>
    <link rel="icon" href="logo.png">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div class="header-left">
                <img src="logo.png" alt="Estate Hub" onerror="this.src='logo.png'" class="logo-nav">
                <div>
                    <h1 class="header-title">Estate Hub</h1>
                    <p class="header-subtitle">Project Management System</p>
                </div>
            </div>
            <div class="header-right">
                <span class="header-title"><?php echo htmlspecialchars(getCurrentUserFullName()); ?></span>
                <span class="header-subtitle"><?php echo htmlspecialchars(getCurrentRole()); ?></span>
                <a href="api/logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </header>
    <nav class="main-nav">
        <div style="max-width: 1440px; margin: 0 auto; display: flex; gap: 1rem; padding: 1rem 2rem;">
            <a href="dashboard.php" class="nav-link active">Dashboard</a>
            <a href="clients.php" class="nav-link" <?php if (!hasRole('manager')&&!hasRole('admin')) echo 'style="display:none;"'; ?>>Clients</a>
            <a href="create-project.php" class="nav-link" <?php if (!hasRole('manager')&&!hasRole('admin')) echo 'style="display:none;"'; ?>>New Project</a>
            <a href="mobilization.php" class="nav-link">Mobilization</a>
            <?php if (isAdmin()): ?>
                <a href="users-management.php" class="nav-link">Users</a>
            <?php endif; ?>
            <?php if (isAdmin() || getCurrentRole() === 'manager'): ?>
                <a href="professionals-management.php" class="nav-link">Professionals</a>
            <?php endif; ?>
        </div>
    </nav>
