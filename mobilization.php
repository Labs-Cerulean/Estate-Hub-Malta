<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: index.php'); exit;
}
require_once 'config.php';

$pdo = getDB();
$is_admin = $_SESSION['user'] === 'admin';

$stats = [
    'total' => $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn(),
    'mobilised' => $pdo->query("SELECT COUNT(*) FROM projects WHERE status='Mobilised'")->fetchColumn(),
    'pending' => $pdo->query("SELECT COUNT(*) FROM projects WHERE status='Pending'")->fetchColumn(),
    'in_process' => $pdo->query("SELECT COUNT(*) FROM projects WHERE status='In Process'")->fetchColumn(),
];

$projects = $pdo->query("
    SELECT p.*, c.name as client_name 
    FROM projects p 
    JOIN clients c ON p.client_id = c.id 
    ORDER BY p.created_at DESC LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mobilization Dashboard - Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div class="header-left">
                <img src="logo.png" alt="Estate Hub Malta" class="logo-nav" onerror="this.src='logo_icon.png'">
                <div>
                    <div class="header-title">Estate Hub Malta</div>
                    <div class="header-subtitle">Mobilization Dashboard</div>
                </div>
            </div>
            <div class="header-right">
                <a href="dashboard.php" class="nav-link">📊 Overview</a>
                <?php if ($is_admin): ?>
                    <a href="clients.php" class="nav-link">👥 Clients</a>
                    <a href="create-project.php" class="nav-link">➕ Projects</a>
                <?php endif; ?>
                <a href="api/auth.php?logout=1" class="nav-link">🚪 Logout</a>
            </div>
        </div>
    </header>
    
    <main class="main-container">
        <h1 class="page-title">Mobilization Dashboard</h1>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stat-label">Total Projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-number mobilised"><?php echo number_format($stats['mobilised']); ?></div>
                <div class="stat-label">Mobilised</div>
            </div>
            <div class="stat-card">
                <div class="stat-number pending"><?php echo number_format($stats['pending']); ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number in-process"><?php echo number_format($stats['in_process']); ?></div>
                <div class="stat-label">In Process</div>
            </div>
        </div>
        
        <section class="projects-section">
            <div class="projects-header">
                <h2 class="section-title">Recent Projects</h2>
                <?php if ($is_admin): ?>
                    <a href="create-project.php" class="nav-link">➕ New Project</a>
                <?php endif; ?>
            </div>
            <div class="projects-grid">
                <?php foreach ($projects as $project): ?>
                <article class="project-card">
                    <div class="project-header">
                        <h3 class="project-name"><?php echo htmlspecialchars($project['name']); ?></h3>
                        <span class="status-badge status-<?php echo str_replace(' ', '-', $project['status']); ?>">
                            <?php echo $project['status']; ?>
                        </span>
                    </div>
                    <div class="project-meta">
                        <div class="meta-item">
                            <span class="meta-label">Client</span>
                            <?php echo htmlspecialchars($project['client_name']); ?>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Location</span>
                            <?php echo htmlspecialchars($project['city']); ?>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">PA Number</span>
                            <?php echo htmlspecialchars($project['pa_number'] ?? 'N/A'); ?>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">BCA Status</span>
                            <?php echo htmlspecialchars($project['bca_status'] ?? 'N/A'); ?>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Type</span>
                            <span class="client-type"><?php echo ucwords(str_replace('-', ' ', $project['type'])); ?></span>
                        </div>
                        <?php if ($project['finish_level']): ?>
                        <div class="meta-item">
                            <span class="meta-label">Finish Level</span>
                            <?php echo $project['finish_level']; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php if (empty($projects)): ?>
                <div class="empty-state">
                    <h3>No projects yet</h3>
                    <p>Get started by creating your first project.</p>
                    <?php if ($is_admin): ?>
                        <a href="create-project
