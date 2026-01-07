<?php
$pageTitle = "Dashboard";
include 'header.php';

// Get current user
$userId = getCurrentUserId();
$userName = getCurrentUserFullName();
$userRole = getCurrentRole();
$isAdmin = isAdmin();

// Get filter and sort parameters
$filterType = $_GET['filter_type'] ?? 'all';
$filterStatus = $_GET['filter_status'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'name';
$sortOrder = $_GET['order'] ?? 'ASC';

// Validate sort parameters
$allowedSorts = ['name', 'client', 'city', 'type'];
$allowedOrders = ['ASC', 'DESC'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'name';
if (!in_array($sortOrder, $allowedOrders)) $sortOrder = 'ASC';

try {
    // Get all projects with client information
    $sql = "SELECT p.*, c.name as client_name 
            FROM projects p
            LEFT JOIN clients c ON p.clientid = c.id
            WHERE 1=1";

    // Apply filters
    if ($filterType !== 'all') {
        $sql .= " AND p.type = :filter_type";
    }

    $sql .= " ORDER BY ";

    // Handle sorting
    switch($sortBy) {
        case 'client':
            $sql .= "c.name {$sortOrder}";
            break;
        case 'city':
            $sql .= "p.city {$sortOrder}";
            break;
        case 'type':
            $sql .= "p.type {$sortOrder}";
            break;
        default:
            $sql .= "p.name {$sortOrder}";
    }

    $stmt = $pdo->prepare($sql);

    if ($filterType !== 'all') {
        $stmt->bindValue(':filter_type', $filterType);
    }

    $stmt->execute();
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all PA numbers grouped by project
    $paSql = "SELECT 
                pan.project_id,
                pan.pa_number,
                pan.pa_status,
                arch.name AS architect_name,
                se.name AS structural_engineer_name
              FROM project_pa_numbers pan
              LEFT JOIN professionals arch ON arch.id = pan.architect_id
              LEFT JOIN professionals se ON se.id = pan.structural_engineer_id
              ORDER BY pan.project_id, pan.id";

    $paStmt = $pdo->query($paSql);
    $paNumbers = $paStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group PA numbers by project_id
    $paByProject = [];
    foreach ($paNumbers as $pa) {
        $paByProject[$pa['project_id']][] = $pa;
    }

    // Apply PA status filter if needed
    if ($filterStatus !== 'all') {
        $projects = array_filter($projects, function($project) use ($paByProject, $filterStatus) {
            $projectPAs = $paByProject[$project['id']] ?? [];
            foreach ($projectPAs as $pa) {
                if ($pa['pa_status'] === $filterStatus) {
                    return true;
                }
            }
            return empty($projectPAs) && $filterStatus === 'TBC';
        });
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

// Helper function to generate sort URL
function getSortUrl($column) {
    global $sortBy, $sortOrder, $filterType, $filterStatus;
    $newOrder = ($sortBy === $column && $sortOrder === 'ASC') ? 'DESC' : 'ASC';
    $params = [
        'sort' => $column,
        'order' => $newOrder,
        'filter_type' => $filterType,
        'filter_status' => $filterStatus
    ];
    return 'dashboard.php?' . http_build_query($params);
}

// Helper function to get sort indicator
function getSortIndicator($column) {
    global $sortBy, $sortOrder;
    if ($sortBy === $column) {
        return $sortOrder === 'ASC' ? ' ▲' : ' ▼';
    }
    return '';
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
      display: flex;
      gap: 1rem;
      align-items: center;
      flex-wrap: wrap;
    }

    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      min-width: 200px;
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
      align-items: flex-end;
      margin-top: 1.5rem;
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
    }

    .reset-btn:hover {
      background: rgba(239, 68, 68, 0.3);
    }

    .sortable-header {
      cursor: pointer;
      user-select: none;
      transition: color 0.2s ease;
    }

    .sortable-header:hover {
      color: var(--primary-color);
    }

    thead th {
      position: sticky;
      top: 0;
      background: var(--bg-card);
      z-index: 10;
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

    <!-- Filters Section -->
    <div class="filters-section">
      <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; width: 100%; align-items: flex-end;">
        <div class="filter-group">
          <label>Project Type</label>
          <select name="filter_type" id="filter-type">
            <option value="all" <?php echo $filterType === 'all' ? 'selected' : ''; ?>>All Types</option>
            <option value="in-house" <?php echo $filterType === 'in-house' ? 'selected' : ''; ?>>In-House</option>
            <option value="3rd-party" <?php echo $filterType === '3rd-party' ? 'selected' : ''; ?>>3rd Party</option>
          </select>
        </div>

        <div class="filter-group">
          <label>PA Status</label>
          <select name="filter_status" id="filter-status">
            <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
            <option value="Endorsed" <?php echo $filterStatus === 'Endorsed' ? 'selected' : ''; ?>>Endorsed</option>
            <option value="Decided" <?php echo $filterStatus === 'Decided' ? 'selected' : ''; ?>>Decided</option>
            <option value="Fee Payment" <?php echo $filterStatus === 'Fee Payment' ? 'selected' : ''; ?>>Fee Payment</option>
            <option value="Refused" <?php echo $filterStatus === 'Refused' ? 'selected' : ''; ?>>Refused</option>
            <option value="Pending/Awaiting Decision" <?php echo $filterStatus === 'Pending/Awaiting Decision' ? 'selected' : ''; ?>>Pending/Awaiting Decision</option>
            <option value="Recommended for Approval" <?php echo $filterStatus === 'Recommended for Approval' ? 'selected' : ''; ?>>Recommended for Approval</option>
            <option value="Recommended for Refusal" <?php echo $filterStatus === 'Recommended for Refusal' ? 'selected' : ''; ?>>Recommended for Refusal</option>
            <option value="Under Appeal" <?php echo $filterStatus === 'Under Appeal' ? 'selected' : ''; ?>>Under Appeal</option>
            <option value="Revoked/Annulled" <?php echo $filterStatus === 'Revoked/Annulled' ? 'selected' : ''; ?>>Revoked/Annulled</option>
            <option value="Withdrawn" <?php echo $filterStatus === 'Withdrawn' ? 'selected' : ''; ?>>Withdrawn</option>
            <option value="TBC" <?php echo $filterStatus === 'TBC' ? 'selected' : ''; ?>>TBC</option>
          </select>
        </div>

        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
        <input type="hidden" name="order" value="<?php echo htmlspecialchars($sortOrder); ?>">

        <div class="filter-buttons">
          <button type="submit" class="btn" style="padding: 0.6rem 1.5rem;">Apply Filters</button>
          <a href="dashboard.php" class="reset-btn">Reset</a>
        </div>
      </form>
    </div>

    <?php
    function buildPaUrl(?string $paNumber): ?string {
        if (empty($paNumber)) return null;

        // Expect format like PA/04937/22
        if (!preg_match('/PA\/(\d{4,5})\/(\d{2})/', trim($paNumber), $m)) {
            return null;
        }

        $caseNumber = $m[1];
        $caseYear = $m[2];

        return "https://eapps.pa.org.mt/Case/CaseDetails?caseType=PA&casenumber={$caseNumber}&caseYear={$caseYear}";
    }
    ?>

    <?php if (count($projects) > 0): ?>
    <table>
      <thead>
        <tr>
          <th><a href="<?php echo getSortUrl('name'); ?>" class="sortable-header">Project Name<?php echo getSortIndicator('name'); ?></a></th>
          <th><a href="<?php echo getSortUrl('client'); ?>" class="sortable-header">Client<?php echo getSortIndicator('client'); ?></a></th>
          <th><a href="<?php echo getSortUrl('city'); ?>" class="sortable-header">City<?php echo getSortIndicator('city'); ?></a></th>
          <th><a href="<?php echo getSortUrl('type'); ?>" class="sortable-header">Type<?php echo getSortIndicator('type'); ?></a></th>
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
                <td><?= htmlspecialchars($project['client_name'] ?? 'N/A') ?></td>
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

                <td style="white-space: nowrap;">
                    <a href="mobilisation_detail.php?project_id=<?= $project['id'] ?>" 
                       class="btn btn-sm btn-primary" 
                       style="padding: 0.4rem 0.8rem; font-size: 0.85rem; min-width: 60px;">View</a>
                    <?php if (hasRole('admin') || hasRole('manager')): ?>
                        <a href="edit-project.php?id=<?= $project['id'] ?>" 
                           class="btn btn-sm" 
                           style="padding: 0.4rem 0.8rem; font-size: 0.85rem; margin-left: 0.5rem; min-width: 60px;">Edit</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state">
      <p>No projects match your filters.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
