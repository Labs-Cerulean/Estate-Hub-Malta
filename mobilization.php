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

// Check admin access
$isAdmin = $_SESSION['user'] === 'admin' ? true : false;

// SAFE STATS - Using mobilization table for accurate counts
$stats = [
  'total' => $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn() ?? 0,
  'mobilised' => $pdo->query("SELECT COUNT(*) FROM projects p JOIN project_mobilisation m ON p.id = m.project_id WHERE m.bca_clearance = 'Yes'")->fetchColumn() ?? 0,
  'pending' => $pdo->query("SELECT COUNT(*) FROM projects p LEFT JOIN project_mobilisation m ON p.id = m.project_id WHERE m.bca_clearance IS NULL OR m.bca_clearance = 'No'")->fetchColumn() ?? 0,
  'inprocess' => $pdo->query("SELECT COUNT(*) FROM projects p JOIN project_mobilisation m ON p.id = m.project_id WHERE m.bca_clearance IS NULL OR m.bca_clearance = 'No'")->fetchColumn() ?? 0,
];

// Get filter values from GET
$filterClient = $_GET['client'] ?? '';
$filterCity = $_GET['city'] ?? '';

// Build dynamic WHERE clause - NO status filter since it doesn't exist
$whereConditions = [];
$params = [];

if ($filterClient) {
  $whereConditions[] = "p.client_id = ?";
  $params[] = $filterClient;
}

if ($filterCity) {
  $whereConditions[] = "p.city = ?";
  $params[] = $filterCity;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get all clients for filter dropdown
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?? [];

// Get all unique cities for filter dropdown
$cities = $pdo->query("SELECT DISTINCT city FROM projects WHERE city IS NOT NULL AND city != '' ORDER BY city")->fetchAll(PDO::FETCH_COLUMN) ?? [];

// Get projects with filters applied
$query = "SELECT * FROM projects p $whereClause ORDER BY p.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

// Enrich project data with client names and mobilization progress
foreach ($projects as &$project) {
  // Get client name
  $clientStmt = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
  $clientStmt->execute([$project['client_id'] ?? null]);
  $client = $clientStmt->fetch();
  $project['client_name'] = $client ? $client['name'] : 'No Client';
  
  // Calculate mobilization progress
  $mobStmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
  $mobStmt->execute([$project['id']]);
  $mob = $mobStmt->fetch();
  
  if ($mob) {
    // Count completed mobilization steps
    $completedSteps = 0;
    $totalSteps = 12; // Total mobilization steps
    
    // Non-sequential tasks
    if ($mob['archaeologist_assigned'] === 'Yes' || $mob['archaeologist_assigned'] === 'NA') $completedSteps++;
    if ($mob['change_of_applicant'] === 'Complete' || $mob['change_of_applicant'] === 'NA') $completedSteps++;
    if ($mob['geological_test'] === 'Complete' || $mob['geological_test'] === 'NA') $completedSteps++;
    if ($mob['condition_report_contacts'] === 'Complete') $completedSteps++;
    if ($mob['condition_reports'] === 'Complete') $completedSteps++;
    
    // Sequential chain
    if ($mob['method_statements'] === 'Complete') $completedSteps++;
    if ($mob['insurance_status'] === 'Complete') $completedSteps++;
    if ($mob['pavement_guarantee'] === 'Complete') $completedSteps++;
    if ($mob['wellbeing_guarantee'] === 'Complete') $completedSteps++;
    if ($mob['umbrella_guarantee'] === 'Complete') $completedSteps++;
    
    // Final clearance
    if ($mob['responsibility_form'] === 'Complete') $completedSteps++;
    if ($mob['bca_clearance'] === 'Yes') $completedSteps++;
    
    $project['mobilization_progress'] = round(($completedSteps / $totalSteps) * 100);
  } else {
    $project['mobilization_progress'] = 0;
  }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mobilization Dashboard - Estate Hub Malta</title>
  <link rel="icon" href="logo.jpg">
  <link rel="stylesheet" href="styles.css">
  <style>
    /* Enhanced styles for visual status and filters */
    .filter-section {
      background: var(--bg-card);
      border: 1px solid var(--border-glass);
      border-radius: 16px;
      padding: 2rem;
      margin-bottom: 2.5rem;
      backdrop-filter: blur(20px);
    }
    
    .filter-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 1.5rem;
      margin-bottom: 1.5rem;
    }
    
    .filter-group {
      display: flex;
      flex-direction: column;
    }
    
    .filter-group label {
      font-weight: 500;
      color: var(--text-secondary);
      margin-bottom: 0.5rem;
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .filter-group select {
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid var(--border-glass);
      border-radius: 12px;
      padding: 0.75rem;
      color: var(--text-primary);
      backdrop-filter: blur(10px);
      transition: all 0.3s ease;
      font-size: 0.95rem;
    }
    
    .filter-group select:focus {
      outline: none;
      border-color: var(--accent-blue);
      box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
    }
    
    .filter-actions {
      display: flex;
      gap: 1rem;
      justify-content: flex-start;
    }
    
    .btn-filter {
      padding: 0.75rem 2rem;
      background: var(--accent-blue);
      color: white;
      border: none;
      border-radius: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      font-size: 0.95rem;
    }
    
    .btn-filter:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 25px rgba(79, 70, 229, 0.4);
    }
    
    .btn-reset {
      padding: 0.75rem 2rem;
      background: rgba(255, 255, 255, 0.1);
      color: var(--text-primary);
      border: 1px solid var(--border-glass);
      border-radius: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      font-size: 0.95rem;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
    }
    
    .btn-reset:hover {
      background: rgba(255, 255, 255, 0.15);
      border-color: var(--border-glass);
    }
    
    /* Progress bar styling */
    .progress-section {
      margin-top: 1.5rem;
    }
    
    .progress-label {
      display: flex;
      justify-content: space-between;
      margin-bottom: 0.5rem;
      font-size: 0.85rem;
    }
    
    .progress-label-left {
      font-weight: 600;
      color: var(--text-secondary);
    }
    
    .progress-label-right {
      font-weight: 700;
      color: var(--accent-blue);
    }
    
    .progress-bar {
      width: 100%;
      height: 8px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid var(--border-glass);
    }
    
    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--accent-blue), var(--accent-purple));
      border-radius: 12px;
      transition: width 0.4s ease;
      width: var(--progress-width, 0%);
    }
    
    .progress-status {
      margin-top: 0.75rem;
      display: flex;
      gap: 1rem;
      flex-wrap: wrap;
      font-size: 0.8rem;
    }
    
    .status-dot {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.25rem 0.75rem;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 8px;
      border: 1px solid var(--border-glass);
    }
    
    .dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
    }
    
    .dot-complete { background: var(--success); }
    .dot-pending { background: var(--warning); }
    .dot-in-progress { background: var(--info); }
    
    /* Enhanced card styling */
    .project-card {
      display: flex;
      flex-direction: column;
      height: 100%;
    }
    
    .project-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 1.5rem;
    }
    
    .project-info {
      flex: 1;
    }
    
    .project-name {
      font-size: 1.2rem;
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 0.5rem;
    }
  </style>
</head>
<body>
  <header class="header">
    <div class="header-container">
      <div style="display: flex; align-items: center; gap: 1rem;">
        <img src="logo.jpg" alt="Estate Hub Malta" class="logo-nav" onerror="this.src='logo.jpg'">
        <div style="font-size: 1.4rem; font-weight: 700;">Estate Hub Malta</div>
        <div style="font-size: 0.85rem; color: var(--text-muted);">Mobilization Dashboard</div>
      </div>
      <div class="header-right">
        <a href="dashboard.php" class="nav-link">Overview</a>
        <?php if ($isAdmin): ?>
          <a href="clients.php" class="nav-link">Clients</a>
          <a href="create-project.php" class="nav-link">Projects</a>
        <?php endif; ?>
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
        <div class="stat-number mobilised" style="color: var(--success);"><?php echo number_format($stats['mobilised']); ?></div>
        <div class="stat-label">Mobilised</div>
      </div>
      <div class="stat-card">
        <div class="stat-number pending" style="color: var(--warning);"><?php echo number_format($stats['pending']); ?></div>
        <div class="stat-label">Pending</div>
      </div>
      <div class="stat-card">
        <div class="stat-number in-process" style="color: var(--info);"><?php echo number_format($stats['inprocess']); ?></div>
        <div class="stat-label">In Process</div>
      </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
      <div class="section-title" style="margin-bottom: 1.5rem;">Filter Projects</div>
      
      <form method="GET" action="mobilization.php">
        <div class="filter-grid">
          <div class="filter-group">
            <label for="filter-client">Client</label>
            <select name="client" id="filter-client">
              <option value="">All Clients</option>
              <?php foreach ($clients as $client): ?>
                <option value="<?php echo $client['id']; ?>" <?php echo $filterClient == $client['id'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($client['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="filter-group">
            <label for="filter-city">Location</label>
            <select name="city" id="filter-city">
              <option value="">All Locations</option>
              <?php foreach ($cities as $city): ?>
                <option value="<?php echo htmlspecialchars($city); ?>" <?php echo $filterCity === $city ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($city); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        
        <div class="filter-actions">
          <button type="submit" class="btn-filter">Apply Filters</button>
          <a href="mobilization.php" class="btn-reset">Reset Filters</a>
        </div>
      </form>
    </div>

    <!-- Projects Section -->
    <section class="projects-section">
      <div class="projects-header">
        <div class="section-title">
          Projects 
          <?php 
            $displayCount = count($projects);
            $totalCount = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn() ?? 0;
            if ($displayCount != $totalCount) {
              echo "(" . $displayCount . " of " . $totalCount . ")";
            } else {
              echo "(" . $displayCount . ")";
            }
          ?>
        </div>
        <?php if ($isAdmin): ?>
          <a href="create-project.php" class="nav-link" style="padding: 0.75rem 2rem;">+ New Project</a>
        <?php endif; ?>
      </div>

      <div class="projects-grid">
        <?php if (empty($projects)): ?>
          <div class="empty-state" style="grid-column: 1 / -1;">
            <h3>No projects found</h3>
            <p>
              <?php 
                if (!empty($filterClient) || !empty($filterCity)) {
                  echo "Try adjusting your filter criteria.";
                } else {
                  echo "Get started by creating your first project.";
                }
              ?>
            </p>
            <?php if ($isAdmin && empty($filterClient) && empty($filterCity)): ?>
              <a href="create-project.php" class="nav-link" style="padding: 1rem 2.5rem;">Create First Project</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <?php foreach ($projects as $project): ?>
            <div class="project-card">
              <div class="project-header">
                <div class="project-info">
                  <a href="mobilisation_detail.php?project_id=<?php echo $project['id']; ?>" style="text-decoration: none; color: inherit;">
                    <div class="project-name"><?php echo htmlspecialchars($project['name'] ?? 'Unnamed'); ?></div>
                  </a>
                  <div class="project-meta">
                    <div class="meta-item">
                      <span class="meta-label">Client</span>
                      <span><?php echo htmlspecialchars($project['client_name'] ?? 'No Client'); ?></span>
                    </div>
                    <div class="meta-item">
                      <span class="meta-label">Location</span>
                      <span><?php echo htmlspecialchars($project['city'] ?? 'N/A'); ?></span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Visual Status Summary -->
              <div class="progress-section">
                <div class="progress-label">
                  <span class="progress-label-left">Mobilization Progress</span>
                  <span class="progress-label-right"><?php echo $project['mobilization_progress']; ?>%</span>
                </div>
                <div class="progress-bar">
                  <div class="progress-fill" style="--progress-width: <?php echo $project['mobilization_progress']; ?>%"></div>
                </div>
                <div class="progress-status">
                  <div class="status-dot">
                    <div class="dot dot-complete"></div>
                    <span><?php echo $project['mobilization_progress']; ?>% Complete</span>
                  </div>
                </div>
              </div>

              <!-- View Details Button -->
              <div style="margin-top: auto; padding-top: 1.5rem;">
                <a href="mobilisation_detail.php?project_id=<?php echo $project['id']; ?>" class="btn" style="width: 100%; text-align: center; text-decoration: none;">
                  View Details
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <script>
    // Optional: Auto-submit form on filter change
    const filterSelects = document.querySelectorAll('.filter-group select');
    filterSelects.forEach(select => {
      select.addEventListener('change', function() {
        // You can enable auto-submit here if desired
        // this.closest('form').submit();
      });
    });
  </script>
</body>
</html>
