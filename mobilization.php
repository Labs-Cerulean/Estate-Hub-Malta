<?php
$pageTitle = "Mobilisation";
include 'header.php';

// Get current user
$userId = getCurrentUserId();
$userName = getCurrentUserFullName();
$userRole = getCurrentRole();
$isAdmin = isAdmin();

// Get filter and sort parameters
$filterType = $_GET['filtertype'] ?? 'all';
$filterStatus = $_GET['filterstatus'] ?? 'all';
$filterCity = $_GET['filtercity'] ?? 'all';
$filterClient = $_GET['filterclient'] ?? 'all';
$filterArchitect = $_GET['filterarchitect'] ?? 'all';
$filterEngineer = $_GET['filterengineer'] ?? 'all';
$filterIsland = $_GET['filterisland'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'name';
$sortOrder = $_GET['order'] ?? 'ASC';

// Validate sort parameters
$allowedSorts = ['name', 'client', 'city', 'type'];
$allowedOrders = ['ASC', 'DESC'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'name';
if (!in_array($sortOrder, $allowedOrders)) $sortOrder = 'ASC';

try {
    // Get filter options - but only for accessible clients/projects
    if ($isAdmin) {
        // Admins see all filter options
        $cities = $pdo->query("SELECT DISTINCT city FROM projects ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);
        $clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Non-admins see only their accessible clients/projects
        $user = getUserById($pdo, $userId);
        
        if ($user['role'] === 'architect') {
            // Architects see clients/cities from their firm's projects
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, c.name
                FROM clients c
                INNER JOIN projects p ON c.id = p.clientid
                INNER JOIN project_pa_numbers ppn ON p.id = ppn.project_id
                WHERE (
                    (? IS NOT NULL AND ppn.architect_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (
                            SELECT firm_name FROM professionals WHERE id = ?
                        ) AND role_type = 'architect'
                    ))
                    OR
                    (? IS NOT NULL AND ppn.structural_engineer_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (
                            SELECT firm_name FROM professionals WHERE id = ?
                        ) AND role_type = 'structural_engineer'
                    ))
                )
                ORDER BY c.name
            ");
            $stmt->execute([
                $user['assigned_architect_firm_id'],
                $user['assigned_architect_firm_id'],
                $user['assigned_structural_firm_id'],
                $user['assigned_structural_firm_id']
            ]);
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("
                SELECT DISTINCT p.city
                FROM projects p
                INNER JOIN project_pa_numbers ppn ON p.id = ppn.project_id
                WHERE (
                    (? IS NOT NULL AND ppn.architect_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (
                            SELECT firm_name FROM professionals WHERE id = ?
                        ) AND role_type = 'architect'
                    ))
                    OR
                    (? IS NOT NULL AND ppn.structural_engineer_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (
                            SELECT firm_name FROM professionals WHERE id = ?
                        ) AND role_type = 'structural_engineer'
                    ))
                )
                ORDER BY p.city
            ");
            $stmt->execute([
                $user['assigned_architect_firm_id'],
                $user['assigned_architect_firm_id'],
                $user['assigned_structural_firm_id'],
                $user['assigned_structural_firm_id']
            ]);
            $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Other users see only assigned clients
            $clients = getUserClients($pdo, $userId);
            
            $stmt = $pdo->prepare("
                SELECT DISTINCT p.city
                FROM projects p
                INNER JOIN user_client_access uca ON p.clientid = uca.client_id
                WHERE uca.user_id = ?
                ORDER BY p.city
            ");
            $stmt->execute([$userId]);
            $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    
    // Get all architects and engineers (for filters)
    $architects = $pdo->query("SELECT DISTINCT id, name FROM professionals WHERE role_type = 'architect' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $engineers = $pdo->query("SELECT DISTINCT id, name FROM professionals WHERE role_type = 'structural_engineer' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // ===== USE getAccessibleProjects() INSTEAD OF DIRECT QUERY =====
    $projects = getAccessibleProjects($pdo, $userId);
    
    // Apply additional filters to accessible projects
    if ($filterType !== 'all') {
        $projects = array_filter($projects, function($project) use ($filterType) {
            return $project['type'] === $filterType;
        });
    }
    
    if ($filterCity !== 'all') {
        $projects = array_filter($projects, function($project) use ($filterCity) {
            return $project['city'] === $filterCity;
        });
    }
    
    if ($filterClient !== 'all') {
        $projects = array_filter($projects, function($project) use ($filterClient) {
            return $project['clientid'] == $filterClient;
        });
    }
    
    if ($filterIsland !== 'all') {
        $projects = array_filter($projects, function($project) use ($filterIsland) {
            return $project['island'] === $filterIsland;
        });
    }
    
    // Get PA numbers for all accessible projects
    $projectIds = array_column($projects, 'id');
    $paByProject = [];
    
    if (!empty($projectIds)) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $paSql = "
            SELECT pan.project_id, pan.pa_number, pan.pa_status, pan.architect_id, pan.structural_engineer_id,
                   arch.name AS architect_name, se.name AS structural_engineer_name
            FROM project_pa_numbers pan
            LEFT JOIN professionals arch ON arch.id = pan.architect_id
            LEFT JOIN professionals se ON se.id = pan.structural_engineer_id
            WHERE pan.project_id IN ($placeholders)
            ORDER BY pan.project_id, pan.id
        ";
        $paStmt = $pdo->prepare($paSql);
        $paStmt->execute($projectIds);
        $paNumbers = $paStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group PA numbers by project_id
        foreach ($paNumbers as $pa) {
            $paByProject[$pa['project_id']][] = $pa;
        }
    }
    
    // Apply PA status filter
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
    
    // Apply architect filter
    if ($filterArchitect !== 'all') {
        $projects = array_filter($projects, function($project) use ($paByProject, $filterArchitect) {
            $projectPAs = $paByProject[$project['id']] ?? [];
            foreach ($projectPAs as $pa) {
                if ($pa['architect_id'] == $filterArchitect) {
                    return true;
                }
            }
            return empty($projectPAs) && $filterArchitect === 'none';
        });
    }
    
    // Apply engineer filter
    if ($filterEngineer !== 'all') {
        $projects = array_filter($projects, function($project) use ($paByProject, $filterEngineer) {
            $projectPAs = $paByProject[$project['id']] ?? [];
            foreach ($projectPAs as $pa) {
                if ($pa['structural_engineer_id'] == $filterEngineer) {
                    return true;
                }
            }
            return empty($projectPAs) && $filterEngineer === 'none';
        });
    }
    
    // Sort projects
    usort($projects, function($a, $b) use ($sortBy, $sortOrder) {
        $valA = $sortBy === 'client' ? ($a['client_name'] ?? '') : $a[$sortBy];
        $valB = $sortBy === 'client' ? ($b['client_name'] ?? '') : $b[$sortBy];
        
        $comparison = strcasecmp($valA, $valB);
        return $sortOrder === 'ASC' ? $comparison : -$comparison;
    });
    
    // Get stats
    $projectCount = count($projects);
    $userCount = $isAdmin ? $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;
    
    // Count mobilised projects (only accessible ones)
    $mobilisedCount = 0;
    foreach ($projects as $project) {
        $mobStatus = deriveMobilisationStatus($pdo, $project['id']);
        if ($mobStatus === 'Mobilised') {
            $mobilisedCount++;
        }
    }
    
} catch (Exception $e) {
    $projects = [];
    $paByProject = [];
    $cities = [];
    $clients = [];
    $architects = [];
    $engineers = [];
    $projectCount = 0;
    $userCount = 0;
    $mobilisedCount = 0;
}

// Helper functions remain the same...
function getSortUrl($column) {
    global $sortBy, $sortOrder, $filterType, $filterStatus, $filterCity, $filterClient, $filterArchitect, $filterEngineer, $filterIsland;
    $newOrder = ($sortBy === $column && $sortOrder === 'ASC') ? 'DESC' : 'ASC';
    $params = [
        'sort' => $column,
        'order' => $newOrder,
        'filtertype' => $filterType,
        'filterstatus' => $filterStatus,
        'filtercity' => $filterCity,
        'filterclient' => $filterClient,
        'filterarchitect' => $filterArchitect,
        'filterengineer' => $filterEngineer,
        'filterisland' => $filterIsland
    ];
    return 'dashboard.php?' . http_build_query($params);
}

function getSortIndicator($column) {
    global $sortBy, $sortOrder;
    if ($sortBy === $column) {
        return $sortOrder === 'ASC' ? ' ▲' : ' ▼';
    }
    return '';
}

function buildPaUrl(?string $paNumber): ?string {
    if (empty($paNumber)) return null;
    if (!preg_match('/PA(\d{4,5})\/(\d{2})/', trim($paNumber), $m)) return null;
    $caseNumber = $m[1];
    $caseYear = $m[2];
    return "https://eapps.pa.org.mt/Case/CaseDetails?caseType=PA&casenumber={$caseNumber}&caseyear={$caseYear}";
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

    .checkbox-group {
      display: flex;
      gap: 1rem;
      align-items: center;
    }

    .checkbox-item {
      display: flex;
      align-items: center;
      gap: 0.4rem;
    }

    .checkbox-item input[type="checkbox"] {
      width: 18px;
      height: 18px;
      cursor: pointer;
      accent-color: var(--primary-color);
    }

    .checkbox-item label {
      cursor: pointer;
      margin: 0 !important;
      font-weight: 500 !important;
      color: var(--text-primary) !important;
      font-size: 0.9rem !important;
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
      min-width: max-content;
      border-collapse: collapse;
    }

    table th,
    table td {
      padding: 0.75rem 0.6rem;
      text-align: left;
      vertical-align: top;
      white-space: nowrap;
    }

    /* Allow text wrapping only in specific columns */
    table td:nth-child(1),  /* Project Name */
    table td:nth-child(2),  /* Client */
    table td:nth-child(5),  /* PA Number */
    table td:nth-child(6),  /* PA Status */
    table td:nth-child(7),  /* Architect */
    table td:nth-child(8) { /* Engineer */
      white-space: normal;
      max-width: 150px;
    }

    /* Actions column - always visible, never wrapped */
    table th:nth-child(9),
    table td:nth-child(9) {
      position: sticky;
      right: 0;
      background: var(--bg-card);
      box-shadow: -2px 0 5px rgba(0,0,0,0.1);
      min-width: 150px;
      text-align: center;
    }

    /* Ensure buttons display properly */
    .btn-sm {
      white-space: nowrap;
      display: inline-block;
      margin: 0.2rem;
    }

    @media (max-width: 768px) {
      .filters-grid {
        grid-template-columns: 1fr;
      }

      table th:nth-child(9),
      table td:nth-child(9) {
        position: static;
        box-shadow: none;
      }
    }


    /* Vertical button stacking in Actions column */
    table td:nth-child(9) {
      text-align: center;
      padding: 0.5rem !important;
    }

    table td:nth-child(9) .btn-sm {
      display: block;
      margin: 0.3rem auto;
      padding: 0.5rem 1rem !important;
      font-size: 0.85rem !important;
      min-width: 70px;
      width: 70px;
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
      <form method="GET">
        <div class="filters-grid">
          <div class="filter-group">
            <label>Project Type</label>
            <select name="filter_type">
              <option value="all" <?php echo $filterType === 'all' ? 'selected' : ''; ?>>All Types</option>
              <option value="in-house" <?php echo $filterType === 'in-house' ? 'selected' : ''; ?>>In-House</option>
              <option value="3rd-party" <?php echo $filterType === '3rd-party' ? 'selected' : ''; ?>>3rd Party</option>
            </select>
          </div>

          <div class="filter-group">
            <label>Client</label>
            <select name="filter_client">
              <option value="all" <?php echo $filterClient === 'all' ? 'selected' : ''; ?>>All Clients</option>
              <?php foreach ($clients as $client): ?>
                <option value="<?php echo $client['id']; ?>" <?php echo $filterClient == $client['id'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($client['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="filter-group">
            <label>City</label>
            <select name="filter_city">
              <option value="all" <?php echo $filterCity === 'all' ? 'selected' : ''; ?>>All Cities</option>
              <?php foreach ($cities as $city): ?>
                <option value="<?php echo htmlspecialchars($city); ?>" <?php echo $filterCity === $city ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($city); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="filter-group">
            <label>PA Status</label>
            <select name="filter_status">
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

          <div class="filter-group">
            <label>Architect</label>
            <select name="filter_architect">
              <option value="all" <?php echo $filterArchitect === 'all' ? 'selected' : ''; ?>>All Architects</option>
              <?php foreach ($architects as $architect): ?>
                <option value="<?php echo $architect['id']; ?>" <?php echo $filterArchitect == $architect['id'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($architect['name']); ?>
                </option>
              <?php endforeach; ?>
              <option value="none" <?php echo $filterArchitect === 'none' ? 'selected' : ''; ?>>Unassigned</option>
            </select>
          </div>

          <div class="filter-group">
            <label>Structural Engineer</label>
            <select name="filter_engineer">
              <option value="all" <?php echo $filterEngineer === 'all' ? 'selected' : ''; ?>>All Engineers</option>
              <?php foreach ($engineers as $engineer): ?>
                <option value="<?php echo $engineer['id']; ?>" <?php echo $filterEngineer == $engineer['id'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($engineer['name']); ?>
                </option>
              <?php endforeach; ?>
              <option value="none" <?php echo $filterEngineer === 'none' ? 'selected' : ''; ?>>Unassigned</option>
            </select>
          </div>

          <div class="filter-group">
            <label>Island</label>
            <div class="checkbox-group">
              <div class="checkbox-item">
                <input 
                  type="checkbox" 
                  name="island_malta" 
                  id="island_malta" 
                  value="Malta"
                  <?php echo ($filterIsland === 'all' || $filterIsland === 'Malta') ? 'checked' : ''; ?>
                >
                <label for="island_malta">Malta</label>
              </div>
              <div class="checkbox-item">
                <input 
                  type="checkbox" 
                  name="island_gozo" 
                  id="island_gozo" 
                  value="Gozo"
                  <?php echo ($filterIsland === 'all' || $filterIsland === 'Gozo') ? 'checked' : ''; ?>
                >
                <label for="island_gozo">Gozo</label>
              </div>
            </div>
          </div>
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

        if (!preg_match('/PA\/(\d{4,5})\/(\d{2})/', trim($paNumber), $m)) {
            return null;
        }

        $caseNumber = $m[1];
        $caseYear = $m[2];

        return "https://eapps.pa.org.mt/Case/CaseDetails?caseType=PA&casenumber={$caseNumber}&caseYear={$caseYear}";
    }
    ?>

    <?php if (count($projects) > 0): ?>
    <div class="table-container">
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

                  <?php $projectPAs = $paByProject[$project['id']] ?? []; ?>

                  <td>
                      <?php if (!empty($projectPAs)): ?>
                          <?php foreach ($projectPAs as $index => $pa): ?>
                              <?php 
                              $paText = htmlspecialchars($pa['pa_number']);
                              $paUrl = buildPaUrl($pa['pa_number']);
                              ?>
                              <?php if ($paUrl): ?>
                                  <a href="<?= htmlspecialchars($paUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $paText ?></a>
                              <?php else: ?>
                                  <?= $paText ?>
                              <?php endif; ?>
                              <?php if ($index < count($projectPAs) - 1): ?><br><?php endif; ?>
                          <?php endforeach; ?>
                      <?php else: ?>
                          TBC
                      <?php endif; ?>
                  </td>

                  <td>
                      <?php if (!empty($projectPAs)): ?>
                          <?php foreach ($projectPAs as $index => $pa): ?>
                              <?= htmlspecialchars($pa['pa_status']) ?>
                              <?php if ($index < count($projectPAs) - 1): ?><br><?php endif; ?>
                          <?php endforeach; ?>
                      <?php else: ?>
                          TBC
                      <?php endif; ?>
                  </td>

                  <td>
                      <?php if (!empty($projectPAs)): ?>
                          <?php foreach ($projectPAs as $index => $pa): ?>
                              <?= htmlspecialchars(!empty($pa['architect_name']) ? $pa['architect_name'] : 'TBC') ?>
                              <?php if ($index < count($projectPAs) - 1): ?><br><?php endif; ?>
                          <?php endforeach; ?>
                      <?php else: ?>
                          TBC
                      <?php endif; ?>
                  </td>

                  <td>
                      <?php if (!empty($projectPAs)): ?>
                          <?php foreach ($projectPAs as $index => $pa): ?>
                              <?= htmlspecialchars(!empty($pa['structural_engineer_name']) ? $pa['structural_engineer_name'] : 'TBC') ?>
                              <?php if ($index < count($projectPAs) - 1): ?><br><?php endif; ?>
                          <?php endforeach; ?>
                      <?php else: ?>
                          TBC
                      <?php endif; ?>
                  </td>

                  <td>
                      <a href="mobilisation_detail.php?project_id=<?= $project['id'] ?>" class="btn btn-sm btn-primary">View</a>
                      <?php if (hasRole('admin') || hasRole('manager')): ?>
                          <a href="edit-project.php?id=<?= $project['id'] ?>" class="btn btn-sm">Edit</a>
                      <?php endif; ?>
                  </td>
              </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <p>No projects match your filters.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.querySelector('.filters-section form');
  const maltaCheckbox = document.getElementById('island_malta');
  const gozoCheckbox = document.getElementById('island_gozo');

  // Prevent both from being unchecked
  function validateIslands(e) {
    if (!maltaCheckbox.checked && !gozoCheckbox.checked) {
      e.preventDefault();
      this.checked = true;
      alert('At least one island must be selected');
    }
  }

  maltaCheckbox.addEventListener('change', validateIslands);
  gozoCheckbox.addEventListener('change', validateIslands);

  // Handle form submission
  form.addEventListener('submit', function(e) {
    // Remove any existing filter_island input
    const existingInput = form.querySelector('input[name="filter_island"]');
    if (existingInput) existingInput.remove();

    // Determine filter value
    let filterValue = 'all';
    if (maltaCheckbox.checked && !gozoCheckbox.checked) {
      filterValue = 'Malta';
    } else if (gozoCheckbox.checked && !maltaCheckbox.checked) {
      filterValue = 'Gozo';
    }

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'filter_island';
    input.value = filterValue;
    form.appendChild(input);
  });
});
</script>

</body>
</html>
