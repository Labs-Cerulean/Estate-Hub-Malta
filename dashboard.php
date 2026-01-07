<?php
$pageTitle = 'Dashboard';
include 'header.php';

// Get current user
$userId = getCurrentUserId();
$userName = getCurrentUserFullName();
$userRole = getCurrentRole();
$isAdmin = isAdmin();

try {
    // Get all projects
    $sql = "SELECT * FROM projects ORDER BY name";
    $stmt = $pdo->query($sql);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all PA numbers grouped by project
    $paSql = "
        SELECT 
            pan.project_id,
            pan.pa_number,
            pan.pa_status,
            arch.name AS architect_name,
            se.name AS structural_engineer_name
        FROM project_pa_numbers pan
        LEFT JOIN professionals arch ON arch.id = pan.architect_id
        LEFT JOIN professionals se ON se.id = pan.structural_engineer_id
        ORDER BY pan.project_id, pan.id
    ";
    $paStmt = $pdo->query($paSql);
    $paNumbers = $paStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group PA numbers by project_id
    $paByProject = [];
    foreach ($paNumbers as $pa) {
        $paByProject[$pa['project_id']][] = $pa;
    }

    // Get stats
    $projectCount = count($projects);
    $userCount = $isAdmin ? $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;
    $mobilisedCount = $pdo->query("SELECT COUNT(*) FROM project_mobilisation WHERE bca_clearance = 'Yes'")->fetchColumn();

} catch (Exception $e) {
    $projects = [];
    $paByProject = [];
    $projectCount = 0;
    $userCount = 0;
    $mobilisedCount = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Estate Hub</title>
    <link rel="stylesheet" href="styles.css">
  <style>
    .filters-section {
      background: var(--bg-card);
      border: 1px solid var(--border-glass);
      padding: 1.5rem;
      margin-bottom: 2rem;
      border-radius: 16px;
      backdrop-filter: blur(20px);
    }

    .filters-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-bottom: 1rem;
    }

    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .filter-group label {
      font-size: 0.85rem;
      font-weight: 500;
      color: var(--text-secondary);
    }

    .filter-group select {
      padding: 0.6rem;
      border: 1px solid var(--border-glass);
      border-radius: 8px;
      background: var(--bg-secondary);
      color: var(--text-primary);
      font-size: 0.9rem;
    }

    .filter-buttons {
      display: flex;
      gap: 0.5rem;
      justify-content: flex-start;
      margin-top: 0.5rem;
    }

    .reset-btn {
      padding: 0.6rem 1.2rem;
      border: 1px solid var(--border-glass);
      border-radius: 8px;
      background: rgba(239, 68, 68, 0.2);
      color: #ef4444;
      cursor: pointer;
      font-size: 0.9rem;
      transition: all 0.3s ease;
      text-decoration: none;
      display: inline-block;
    }

    .reset-btn:hover {
      background: rgba(239, 68, 68, 0.3);
    }

    .sortable-header {
      cursor: pointer;
      user-select: none;
      transition: color 0.2s ease;
      text-decoration: none;
      color: var(--text-primary);
      display: block;
    }

    .sortable-header:hover {
      color: var(--primary-color);
    }

    .table-container {
      overflow-x: auto;
      border-radius: 12px;
      border: 1px solid var(--border-glass);
    }

    thead th {
      position: sticky;
      top: 0;
      background: var(--bg-card);
      z-index: 10;
      white-space: nowrap;
    }

    table {
      width: 100%;
      min-width: 1400px;
      border-collapse: collapse;
    }

    /* Tighter table styling */
    table th,
    table td {
      padding: 0.75rem 0.5rem !important; /* Reduced horizontal padding */
      text-align: left;
      vertical-align: top;
    }

    /* Set specific widths for columns */
    table th:nth-child(1), table td:nth-child(1) { width: 12%; } /* Project Name */
    table th:nth-child(2), table td:nth-child(2) { width: 12%; } /* Client */
    table th:nth-child(3), table td:nth-child(3) { width: 8%; }  /* City */
    table th:nth-child(4), table td:nth-child(4) { width: 7%; }  /* Type */
    table th:nth-child(5), table td:nth-child(5) { width: 10%; } /* PA Number */
    table th:nth-child(6), table td:nth-child(6) { width: 12%; } /* PA Status */
    table th:nth-child(7), table td:nth-child(7) { width: 11%; } /* Architect */
    table th:nth-child(8), table td:nth-child(8) { width: 11%; } /* Engineer */
    table th:nth-child(9), table td:nth-child(9) { 
      width: 150px !important; /* Fixed width for Actions */
      min-width: 150px !important;
      padding-right: 1rem !important;
    }

    /* Ensure buttons don't wrap */
    .btn-sm {
      white-space: nowrap !important;
      display: inline-block !important;
    }

    .actions-cell {
      white-space: nowrap !important;
      overflow: visible !important;
    }

    @media (max-width: 768px) {
      .filters-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>

</head>
<body>

    <div class="main-container">
        <h1 class="page-title">Dashboard</h1>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $projectCount; ?></div>
                <div class="stat-label">Total Projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-number mobilised"><?php echo $mobilisedCount; ?></div>
                <div class="stat-label">Mobilised</div>
            </div>
            <?php if ($isAdmin): ?>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $userCount; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
            <?php endif; ?>
        </div>

        <div class="projects-section">
            <div class="projects-header">
                <h2 class="section-title">Projects</h2>
                <span></span>
                <?php if ($isAdmin): ?>
                    <a href="create-project.php" class="btn">Add Project</a>
                <?php endif; ?>
            </div>
        
<?php
function buildPaUrl(?string $paNumber): ?string {
    if (empty($paNumber)) {
        return null;
    }

    // Expect format like "PA/04937/22"
    if (!preg_match('#^PA/(\d{4,5})/(\d{2})$#', trim($paNumber), $m)) {
        return null;
    }

    $caseNumber = $m[1];
    $caseYear   = $m[2];

    return "https://eapps.pa.org.mt/Case/CaseDetails?caseType=PA&casenumber={$caseNumber}&caseYear={$caseYear}";
}
?>
            <?php if (count($projects) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Project Name</th>
                            <th>City</th>
                            <th>Type</th>
                            <th>PA Number</th>
                            <th>PA Status</th>
                            <th>Architect</th>
                            <th>Structural Engineer</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $project): ?>
                            <tr>
                                <td><?= htmlspecialchars($project['name']) ?></td>
                                <td><?= htmlspecialchars($project['city']) ?></td>
                                <td><?= htmlspecialchars($project['type']) ?></td>
                    
                                <?php
                                // Get all PA numbers for this project
                                $projectPAs = $paByProject[$project['id']] ?? [];
                                ?>
                                
                                <!-- PA Number Column -->
                                <td>
                                    <?php if (!empty($projectPAs)): ?>
                                        <?php foreach ($projectPAs as $index => $pa): ?>
                                            <?php 
                                            $paText = htmlspecialchars($pa['pa_number']);
                                            $paUrl = buildPaUrl($pa['pa_number']);
                                            ?>
                                            <?php if ($paUrl): ?>
                                                <a href="<?= htmlspecialchars($paUrl) ?>" 
                                                   target="_blank" 
                                                   rel="noopener noreferrer"
                                                   class="text-decoration-none">
                                                    <?= $paText ?>
                                                </a>
                                            <?php else: ?>
                                                <?= $paText ?>
                                            <?php endif; ?>
                                            <?php if ($index < count($projectPAs) - 1): ?>
                                                <br>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        TBC
                                    <?php endif; ?>
                                </td>
                                
                                <!-- PA Status Column -->
                                <td>
                                    <?php if (!empty($projectPAs)): ?>
                                        <?php foreach ($projectPAs as $index => $pa): ?>
                                            <?= htmlspecialchars($pa['pa_status']) ?>
                                            <?php if ($index < count($projectPAs) - 1): ?>
                                                <br>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        TBC
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Architect Column -->
                                <td>
                                    <?php if (!empty($projectPAs)): ?>
                                        <?php foreach ($projectPAs as $index => $pa): ?>
                                            <?= htmlspecialchars(!empty($pa['architect_name']) ? $pa['architect_name'] : 'TBC') ?>
                                            <?php if ($index < count($projectPAs) - 1): ?>
                                                <br>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        TBC
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Structural Engineer Column -->
                                <td>
                                    <?php if (!empty($projectPAs)): ?>
                                        <?php foreach ($projectPAs as $index => $pa): ?>
                                            <?= htmlspecialchars(!empty($pa['structural_engineer_name']) ? $pa['structural_engineer_name'] : 'TBC') ?>
                                            <?php if ($index < count($projectPAs) - 1): ?>
                                                <br>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        TBC
                                    <?php endif; ?>
                                </td>
                    
                                <td>
                                    <a href="mobilisation_detail.php?project_id=<?= $project['id'] ?>" class="btn btn-sm btn-primary">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <p>No projects yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
