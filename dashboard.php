<?php
session_start();

// Strict validation
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || 
    !isset($_SESSION['user']) || $_SESSION['user'] !== 'admin') {
  session_destroy();
  header("Location: index.php");
  exit;
}

require_once 'config.php';
$pdo = getDB();

$stats = [
  'projects' => $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn(),
  'clients' => $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn(),
  'mobilised' => $pdo->query("SELECT COUNT(*) FROM project_mobilisation WHERE bca_clearance = 'Yes'")->fetchColumn()
];

$isadmin = $_SESSION['user'] === 'admin';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Overview – Estate Hub Malta</title>
  <link rel="icon" href="logo.jpg">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <header class="header">
    <div class="header-container">
      <div class="header-left">
        <img src="logo.jpg" alt="Estate Hub Malta" class="logo-nav" onerror="this.src='logo.jpg'">
        <div class="header-title">Estate Hub Malta</div>
        <div class="header-subtitle">Project Overview</div>
      </div>
      <div class="header-right">
        <a href="mobilization.php" class="nav-link">Mobilization</a>
        <?php if ($isadmin): ?>
          <a href="clients.php" class="nav-link">Clients</a>
          <a href="create-project.php" class="nav-link">New Project</a>
        <?php endif; ?>
        <a href="apiauth.php?logout=1" class="nav-link">Logout</a>
      </div>
    </div>
  </header>

  <main class="main-container">
    <h1 class="page-title">Project Overview</h1>

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-number"><?php echo number_format($stats['projects']); ?></div>
        <div class="stat-label">Active Projects</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?php echo number_format($stats['clients']); ?></div>
        <div class="stat-label">Clients</div>
      </div>
      <div class="stat-card">
        <div class="stat-number" style="color: var(--success);"><?php echo number_format($stats['mobilised']); ?></div>
        <div class="stat-label">Mobilised</div>
      </div>
    </div>

    <section class="projects-section">
      <div class="projects-header">
        <h2 class="section-title">Quick Actions</h2>
      </div>

      <div class="projects-grid">
        <?php if ($isadmin): ?>
          <a href="create-project.php" class="project-card" style="text-decoration: none; display: block;">
            <div class="project-header">
              <h3 class="project-name">New Project</h3>
            </div>
            <p style="color: var(--text-secondary); margin: 0;">Create a new project</p>
          </a>

          <a href="clients.php" class="project-card" style="text-decoration: none; display: block;">
            <div class="project-header">
              <h3 class="project-name">Manage Clients</h3>
            </div>
            <p style="color: var(--text-secondary); margin: 0;">Add and manage clients</p>
          </a>
        <?php else: ?>
          <div class="project-card">
            <div class="project-header">
              <h3 class="project-name">View Mobilization</h3>
            </div>
            <a href="mobilization.php" class="nav-link" style="width: 100%; text-align: center; margin-top: 1rem;">Go to Dashboard</a>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>

</body>
</html>
