<?php
session_start();
if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    header('Location: index.php');
    exit;
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

// FIXED QUERY - Matches config.php schema EXACTLY
$projects = $pdo->query("
    SELECT p.*, c.name as client_name 
    FROM projects p 
    JOIN clients c ON p.client_id = c.id 
    ORDER BY p.created_at DESC 
    LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mobilization Dashboard - Estate Hub Malta</title>
    <link rel="icon" href="logoicon.png">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <img src="logo.png" alt="Estate Hub Malta" class="logo-nav" onerror="this.src='logoicon.png'">
                <div>
                    <div style="font-size: 1.4rem; font-weight: 700;">Estate Hub Malta</div>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">Mobilization Dashboard</div>
                </div>
            </div>
            <div class="header-right">
                <a href="dashboard.php" class="nav-link">Overview</a>
                <?php if ($is_admin): ?>
                    <a href="clients.php" class="nav-link">Clients</a>
                    <a href="create-project.php" class="nav-link">Projects</a>
                <?php endif; ?>
                <a href="apiauth.php?logout=1" class="nav-link">Logout</a>
            </div>
        </div>
    </header>

    <div class="main-container">
        <h1 class="page-title">Mobilization Dashboard</h1>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stat-label">Total Projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: var(--success);"><?php echo number_format($stats['mobilised']); ?></div>
                <div class="stat-label">Mobilised</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: var(--warning);"><?php echo number_format($stats['pending']); ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: var(--info);"><?php echo number_format($stats['in_process']); ?></div>
                <div class="stat-label">In Process</div>
            </div>
        </div>

        <section class="projects-section">
            <div class="projects-header">
                <div class="section-title">Recent Projects</div>
                <?php if ($is_admin): ?>
                    <a href="create-project.php" class="nav-link" style="padding: 0.75rem 2rem;">New Project</a>
                <?php endif; ?>
            </div>
            <div class="projects-grid">
                <?php foreach ($projects as $project): ?>
                    <div class="project-card">
                        <div class="project-header">
                            <div class="project-name"><?php echo htmlspecialchars($project['name']); ?></div>
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
                    </div>
                <?php endforeach; ?>
                <?php if (empty($projects)): ?>
                    <div class="empty-state">
                        <h3 style="font-size: 1.5rem; margin-bottom: 1rem;">No projects yet</h3>
                        <p style="font-size: 1.1rem; margin-bottom: 2rem;">Get started by creating your first project.</p>
                        <?php if ($is_admin): ?>
                            <a href="create-project.php" class="nav-link" style="padding: 1rem 2.5rem; font-size: 1.1rem;">Create First Project</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</body>
</html>
