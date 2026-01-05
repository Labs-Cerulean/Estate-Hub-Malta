<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || 
    !isset($_SESSION['user']) || $_SESSION['user'] !== 'admin') {
  session_destroy();
  header("Location: index.php");
  exit;
}

require_once 'config.php';
$pdo = getDB();

// Stats
$total = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn() ?? 0;
$mobilised = $pdo->query("SELECT COUNT(*) FROM project_mobilisation WHERE bca_clearance = 'Yes'")->fetchColumn() ?? 0;
$inprocess = $pdo->query("
  SELECT COUNT(*) FROM project_mobilisation 
  WHERE bca_clearance = 'No' 
    AND (condition_report_contacts = 'In Process' 
      OR condition_reports = 'In Process' 
      OR geological_test IN ('In Process', 'Awaiting Result')
      OR insurance_status = 'In Process'
      OR pavement_guarantee = 'In Process'
      OR wellbeing_guarantee = 'In Process'
      OR umbrella_guarantee = 'In Process')
")->fetchColumn() ?? 0;
$pending = $total - $mobilised - $inprocess;

$stats = [
  'total' => $total,
  'mobilised' => $mobilised,
  'pending' => $pending,
  'inprocess' => $inprocess
];

// Get projects
$projects = $pdo->query("SELECT * FROM projects ORDER BY id DESC LIMIT 10")->fetchAll();

// Enrich each project with derived status and PA info
foreach ($projects as &$project) {
  $project['mobilisation_status'] = deriveMobilisationStatus($pdo, $project['id']);
  $project['pa_numbers'] = getProjectPANumbers($pdo, $project['id']);
  
  // Add client name
  $clientStmt = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
  $clientStmt->execute([$project['clientid']]);
  $client = $clientStmt->fetch();
  $project['client_name'] = $client['name'] ?? 'Unknown';
}
unset($project);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mobilization Dashboard – Estate Hub Malta</title>
  <link rel="icon" href="logo.jpg">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <header class="header">
    <div class="header-container">
      <div style="display: flex; align-items: center; gap: 1rem;">
        <img src="logo.jpg" alt="Estate Hub Malta" class="logo-nav" onerror="this.src='logo.jpg'">
        <div>
          <div style="font-size: 1.4rem; font-weight: 700;">Estate Hub Malta</div>
          <div style="font-size: 0.85rem; color: var(--text-muted);">Mobilization Dashboard</div>
        </div>
      </div>
      <div class="header-right">
        <a href="dashboard.php" class="nav-link">Overview</a>
        <a href="clients.php" class="nav-link">Clients</a>
        <a href="create-project.php" class="nav-link">New Project</a>
        <a href="apiauth.php?logout=1" class="nav-link">Logout</a>
      </div>
    </div>
  </header>

  <div class="main-container">
    <h1 class="page-title">Mobilization Dashboard</h1>

    <!-- Stats Grid -->
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
        <div class="stat-number" style="color: var(--info);"><?php echo number_format($stats['inprocess']); ?></div>
        <div class="stat-label">In Process</div>
      </div>
    </div>

    <!-- Projects Section -->
    <section class="projects-section">
      <div class="projects-header">
        <div class="section-title">Recent Projects (<?php echo count($projects); ?>)</div>
        <a href="create-project.php" class="nav-link" style="padding: 0.75rem 2rem;">+ New Project</a>
      </div>

      <div class="projects-grid">
        <?php if (empty($projects)): ?>
          <div class="empty-state" style="grid-column: 1/-1;">
            <h3>No projects yet</h3>
            <p>Get started by creating your first project.</p>
            <a href="create-project.php" class="nav-link" style="padding: 1rem 2.5rem; display: inline-block;">Create First Project</a>
          </div>
        <?php else: ?>
          <?php foreach ($projects as $project): ?>
            <div class="project-card">
              <div class="project-header">
                <a href="mobilisation_detail.php?project_id=<?php echo $project['id']; ?>" style="color: inherit; text-decoration: none;">
                  <h3 class="project-name"><?php echo htmlspecialchars($project['name'] ?? 'Unnamed'); ?></h3>
                </a>
                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $project['mobilisation_status'])); ?>">
                  <?php echo htmlspecialchars($project['mobilisation_status']); ?>
                </span>
              </div>

              <div class="project-meta">
                <div class="meta-item">
                  <span class="meta-label">Client</span>
                  <span><?php echo htmlspecialchars($project['client_name']); ?></span>
                </div>
                <div class="meta-item">
                  <span class="meta-label">Location</span>
                  <span><?php echo htmlspecialchars($project['city'] ?? 'N/A'); ?></span>
                </div>
                <div class="meta-item">
                  <span class="meta-label">Type</span>
                  <span><?php echo ucwords(str_replace('-', ' ', $project['type'])); ?></span>
                </div>
              </div>

              <?php if (!empty($project['pa_numbers'])): ?>
                <div class="meta-item" style="margin-top: 1rem;">
                  <span class="meta-label">PA Numbers</span>
                  <div class="pa-list">
                    <?php foreach ($project['pa_numbers'] as $pa): ?>
                      <span class="pa-badge pa-status-<?php echo strtolower(str_replace(' ', '-', $pa['pa_status'])); ?>">
                        <?php echo htmlspecialchars($pa['pa_number']); ?> (<?php echo htmlspecialchars($pa['pa_status']); ?>)
                      </span>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>

              <a href="mobilisation_detail.php?project_id=<?php echo $project['id']; ?>" class="nav-link" style="display: block; margin-top: 1rem; text-align: center; padding: 0.75rem;">
                View Details
              </a>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

  </div>
</body>
</html>
