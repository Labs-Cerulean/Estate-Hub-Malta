<?php
session_start();
if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin'] || !isset($_SESSION['user']) || $_SESSION['user'] !== 'admin') {
    session_destroy();
    header('Location: index.php');
    exit;
}

require_once 'config.php';
$pdo = getDB();
$is_admin = $_SESSION['user'] === 'admin';

// SAFE STATS - COUNT(*) never fails
$stats = [
    'total' => $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn() ?: 0,
    'mobilised' => $pdo->query("SELECT COUNT(*) FROM projects WHERE status='Mobilised'")->fetchColumn() ?: 0,
    'pending' => $pdo->query("SELECT COUNT(*) FROM projects WHERE status='Pending'")->fetchColumn() ?: 0,
    'in_process' => $pdo->query("SELECT COUNT(*) FROM projects WHERE status='In Process'")->fetchColumn() ?: 0,
];

// ULTRA SAFE: Get ALL columns dynamically - NO HARDCODING
$columns_result = $pdo->query("DESCRIBE projects");
$columns = [];
while ($row = $columns_result->fetch(PDO::FETCH_ASSOC)) {
    $columns[] = $row['Field'];
}

// Build safe SELECT with ONLY existing columns
$safe_columns = implode(', ', $columns);
$projects = $pdo->query("SELECT $safe_columns FROM projects ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// Add client names using dynamic client column detection
foreach ($projects as &$project) {
    // Try common client column names
    $client_col = null;
    foreach (['client_id', 'clientid', 'clientId', 'fk_client_id'] as $possible) {
        if (isset($project[$possible]) && $project[$possible]) {
            $client_col = $possible;
            break;
        }
    }
    
    if ($client_col && $project[$client_col]) {
        $stmt = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
        $stmt->execute([$project[$client_col]]);
        $client = $stmt->fetch();
        $project['client_name'] = $client ? $client['name'] : 'Unknown';
    } else {
        $project['client_name'] = 'No Client';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mobilization Dashboard - Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.jpg">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <!-- SAME HTML AS BEFORE - header, stats-grid, projects-section -->
    <header class="header">
        <div class="header-container">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <img src="logo.jpg" alt="Estate Hub Malta" class="logo-nav" onerror="this.src='logo_icon.jpg'">
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
                <div class="section-title">Recent Projects (<?php echo count($projects); ?>)</div>
                <?php if ($is_admin): ?>
                    <a href="create-project.php" class="nav-link" style="padding: 0.75rem 2rem;">+ New Project</a>
                <?php endif; ?>
            </div>
            <div class="projects-grid">
                <?php if (empty($projects)): ?>
                    <div class="empty-state" style="grid-column: 1/-1;">
                        <h3>No projects yet</h3>
                        <p>Get started by creating your first project.</p>
                        <?php if ($is_admin): ?>
                            <a href="create-project.php" class="nav-link" style="padding: 1rem 2.5rem;">Create First Project</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($projects as $project): ?>
                        <div class="project-card">
                            <div class="project-header">
                                <div class="project-name"><?php echo htmlspecialchars($project['name'] ?? 'Unnamed'); ?></div>
                                <span class="status-badge status-<?php echo str_replace(' ', '-', $project['status'] ?? 'pending'); ?>">
                                    <?php echo htmlspecialchars($project['status'] ?? 'Pending'); ?>
                                </span>
                            </div>
                            <div class="project-meta">
                                <div class="meta-item">
                                    <span class="meta-label">Client</span>
                                    <?php echo htmlspecialchars($project['client_name'] ?? 'No Client'); ?>
                                </div>
                                <div class="meta-item">
                                    <span class="meta-label">Location</span>
                                    <?php echo htmlspecialchars($project['city'] ?? 'N/A'); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</body>
</html>
