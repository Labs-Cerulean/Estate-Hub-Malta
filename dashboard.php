<?php
require_once 'init.php';
require_once 'session-check.php';

// Get current user details
$userId = getCurrentUserId();
$userName = getCurrentUserFullName();
$userRole = getCurrentRole();
$isAdmin = isAdmin();

// Determine Dashboard View Type based on PDF specifications
$dashboardType = 'Project Dashboard'; // Default
if ($userRole === 'admin') {
    $dashboardType = 'Admin Dashboard';
} elseif ($userRole === 'director') {
    $dashboardType = 'Company Dashboard';
} elseif (in_array($userRole, ['sales_manager', 'sales_agent'])) {
    $dashboardType = 'Sales Dashboard';
} elseif ($userRole === 'condominium_agent') {
    $dashboardType = 'None';
}

// Stage Enums & Colors for the Legend and Dots
$stageEnum = [
    'Feasibility' => 1, 'Tracking' => 2, 'Permit' => 3, 'Mobilisation' => 4,
    'Demolition' => 5, 'Excavation' => 6, 'Construction' => 7, 'Finishes' => 8,
    'Compliance' => 9, 'Condominium' => 10, 'Handed Over' => 11
];

$stageColors = [
    'Feasibility' => '#94a3b8',   // Slate Gray
    'Tracking' => '#0ea5e9',      // Cyan
    'Permit' => '#3b82f6',        // Blue
    'Mobilisation' => '#6366f1',  // Indigo
    'Demolition' => '#ef4444',    // Red
    'Excavation' => '#f97316',    // Orange
    'Construction' => '#eab308',  // Yellow
    'Finishes' => '#84cc16',      // Lime
    'Compliance' => '#14b8a6',    // Teal
    'Condominium' => '#a855f7',   // Purple
    'Handed Over' => '#22c55e'    // Green
];

// If user has no dashboard access, don't process filters
if ($dashboardType !== 'None') {
    // Get filter and sort parameters
    $filterType = $_GET['filter_type'] ?? 'all';
    $filterStatus = $_GET['filter_status'] ?? 'all'; // Now uses 11 Stages
    $filterCity = $_GET['filter_city'] ?? 'all';
    $filterClient = $_GET['filter_client'] ?? 'all';
    $filterIsland = $_GET['filter_island'] ?? 'all';
    
    // Default to sorting by Stage (Descending - highest stage first)
    $sortBy = $_GET['sort'] ?? 'stage';
    $sortOrder = $_GET['order'] ?? 'DESC';

    $allowedSorts = ['name', 'client', 'city', 'type', 'finish_level', 'stage'];
    $allowedOrders = ['ASC', 'DESC'];
    if (!in_array($sortBy, $allowedSorts)) $sortBy = 'stage';
    if (!in_array($sortOrder, $allowedOrders)) $sortOrder = 'DESC';

    try {
        // Fetch accessible projects
        $projects = getAccessibleProjects($pdo, $userId);
        
        // Extract filter options dynamically
        $cities = array_unique(array_filter(array_column($projects, 'city')));
        sort($cities);
        
        $clientIds = array_unique(array_column($projects, 'clientid'));
        $clients = [];
        if (!empty($clientIds)) {
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
            $clientStmt = $pdo->prepare("SELECT id, name FROM clients WHERE id IN ($placeholders) ORDER BY name");
            $clientStmt->execute(array_values($clientIds));
            $clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get PA numbers for display
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
            ";
            $paStmt = $pdo->prepare($paSql);
            $paStmt->execute($projectIds);
            foreach ($paStmt->fetchAll(PDO::FETCH_ASSOC) as $pa) {
                $paByProject[$pa['project_id']][] = $pa;
            }
        }

        // CALCULATE STAGES & KPI STATS
        $projectCount = count($projects);
        $preExecCount = 0; // Stages 1-4
        $execCount = 0;    // Stages 5-8
        $finalCount = 0;   // Stages 9-11
        
        $companyKpis = []; // For Director Role

        // Process projects to attach stage info
        foreach ($projects as $key => $project) {
            $stage = deriveProjectStage($pdo, $project['id']);
            $stageNum = $stageEnum[$stage] ?? 1;
            
            $projects[$key]['stage'] = $stage;
            $projects[$key]['stage_num'] = $stageNum;

            // KPI Grouping
            if ($stageNum >= 9) $finalCount++;
            elseif ($stageNum >= 5) $execCount++;
            else $preExecCount++;

            // Aggregate for Director Company Dashboard
            if ($dashboardType === 'Company Dashboard') {
                $cName = $project['client_name'] ?? 'Unassigned';
                if (!isset($companyKpis[$cName])) {
                    $companyKpis[$cName] = ['total' => 0, 'pre' => 0, 'exec' => 0, 'final' => 0];
                }
                $companyKpis[$cName]['total']++;
                if ($stageNum >= 9) $companyKpis[$cName]['final']++;
                elseif ($stageNum >= 5) $companyKpis[$cName]['exec']++;
                else $companyKpis[$cName]['pre']++;
            }
        }

        // Apply filters
        if ($filterType !== 'all') $projects = array_filter($projects, fn($p) => $p['type'] === $filterType);
        if ($filterCity !== 'all') $projects = array_filter($projects, fn($p) => $p['city'] === $filterCity);
        if ($filterClient !== 'all') $projects = array_filter($projects, fn($p) => $p['clientid'] == $filterClient);
        if ($filterIsland !== 'all') $projects = array_filter($projects, fn($p) => $p['island'] === $filterIsland);
        if ($filterStatus !== 'all') $projects = array_filter($projects, fn($p) => $p['stage'] === $filterStatus);

        // Sort projects
        usort($projects, function($a, $b) use ($sortBy, $sortOrder) {
            if ($sortBy === 'stage') {
                $comparison = $a['stage_num'] <=> $b['stage_num'];
            } else {
                $valA = $sortBy === 'client' ? ($a['client_name'] ?? '') : ($sortBy === 'finish_level' ? ($a['finishlevel'] ?? 'ZZZ') : $a[$sortBy]);
                $valB = $sortBy === 'client' ? ($b['client_name'] ?? '') : ($sortBy === 'finish_level' ? ($b['finishlevel'] ?? 'ZZZ') : $b[$sortBy]);
                $comparison = strcasecmp($valA, $valB);
            }
            return $sortOrder === 'ASC' ? $comparison : -$comparison;
        });

    } catch (Exception $e) {
        $projects = [];
        $paByProject = [];
        $projectCount = 0;
    }
}

function getSortUrl($column) {
    global $sortBy, $sortOrder, $filterType, $filterStatus, $filterCity, $filterClient, $filterIsland;
    $newOrder = ($sortBy == $column && $sortOrder == 'ASC') ? 'DESC' : 'ASC';
    $params = [
        'sort' => $column, 'order' => $newOrder, 'filter_type' => $filterType, 
        'filter_status' => $filterStatus, 'filter_city' => $filterCity, 
        'filter_client' => $filterClient
    ];
    if ($filterIsland !== 'all') $params['filter_island'] = $filterIsland;
    return 'dashboard.php?' . http_build_query($params);
}

function getSortIndicator($column) {
    global $sortBy, $sortOrder;
    if ($sortBy === $column) return $sortOrder === 'ASC' ? ' ▲' : ' ▼';
    return '';
}

$pageTitle = $dashboardType;
require_once 'header.php';
?>

<style>
/* Legend & Dot Styles */
.stage-dot {
    display: inline-block;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    box-shadow: 0 0 4px rgba(0,0,0,0.3);
    flex-shrink: 0;
}
.legend-container {
    background: var(--bg-card);
    border: 1px solid var(--border-glass);
    border-radius: var(--radius-md);
    padding: 1rem;
    margin-bottom: 1.5rem;
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: center;
    justify-content: center;
}
.legend-item {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.85rem;
    color: var(--text-secondary);
    font-weight: 500;
}
</style>

<div class="main-container">
    <h1 class="page-title"><?= htmlspecialchars($dashboardType) ?></h1>

    <?php if ($dashboardType === 'None'): ?>
        <div class="empty-state card">
            <h2 style="margin-bottom: 1rem; color: var(--primary-color);">Welcome to Estate Hub</h2>
            <p>You have successfully logged in. Please use the navigation menu above to access your specific modules.</p>
        </div>
    <?php else: ?>
        
        <div class="stats-grid">
            <?php if ($dashboardType === 'Admin Dashboard' || $dashboardType === 'Project Dashboard'): ?>
                <div class="stat-card">
                    <div class="stat-number"><?= $projectCount ?></div>
                    <div class="stat-label">Total Projects</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: <?= $stageColors['Permit'] ?>;"><?= $preExecCount ?></div>
                    <div class="stat-label">Pre-Execution (Stg 1-4)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: <?= $stageColors['Construction'] ?>;"><?= $execCount ?></div>
                    <div class="stat-label">In Execution (Stg 5-8)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: <?= $stageColors['Handed Over'] ?>;"><?= $finalCount ?></div>
                    <div class="stat-label">Finalizing (Stg 9-11)</div>
                </div>

            <?php elseif ($dashboardType === 'Company Dashboard'): ?>
                <div class="stat-card">
                    <div class="stat-number"><?= $projectCount ?></div>
                    <div class="stat-label">Projects Managed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: <?= $stageColors['Construction'] ?>;"><?= $execCount ?></div>
                    <div class="stat-label">Sites In Execution</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: <?= $stageColors['Handed Over'] ?>;"><?= $finalCount ?></div>
                    <div class="stat-label">Sites Finalizing</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--primary-color);"><?= count($companyKpis) ?></div>
                    <div class="stat-label">Companies Supervised</div>
                </div>

            <?php elseif ($dashboardType === 'Sales Dashboard'): ?>
                <div class="stat-card"><div class="stat-number"><?= $projectCount ?></div><div class="stat-label">Available Projects</div></div>
                <div class="stat-card"><div class="stat-number" style="color: var(--text-muted);">-</div><div class="stat-label">Units Available</div></div>
                <div class="stat-card"><div class="stat-number" style="color: var(--text-muted);">-</div><div class="stat-label">Active Leads</div></div>
            <?php endif; ?>
        </div>

        <?php if ($dashboardType === 'Company Dashboard' && !empty($companyKpis)): ?>
            <h3 style="margin-bottom: 1rem;">Company Overview</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                <?php foreach ($companyKpis as $cName => $kpi): ?>
                    <div class="card" style="border-left: 4px solid var(--primary-color);">
                        <h4 style="margin: 0 0 0.5rem 0; font-size: 1.1rem; color: var(--text-primary);"><?= htmlspecialchars($cName) ?></h4>
                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--text-secondary);">
                            <span>Total: <strong style="color: var(--text-primary);"><?= $kpi['total'] ?></strong></span>
                            <span>Pre: <strong style="color: <?= $stageColors['Permit'] ?>;"><?= $kpi['pre'] ?></strong></span>
                            <span>Exec: <strong style="color: <?= $stageColors['Construction'] ?>;"><?= $kpi['exec'] ?></strong></span>
                            <span>Fin: <strong style="color: <?= $stageColors['Handed Over'] ?>;"><?= $kpi['final'] ?></strong></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="filters-section">
            <form method="GET" id="dashboardFilters">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Stage Filter</label>
                        <select name="filter_status">
                            <option value="all">All Stages</option>
                            <optgroup label="Pre-Execution">
                                <option value="Feasibility" <?= $filterStatus === 'Feasibility' ? 'selected' : '' ?>>1. Feasibility</option>
                                <option value="Tracking" <?= $filterStatus === 'Tracking' ? 'selected' : '' ?>>2. Tracking</option>
                                <option value="Permit" <?= $filterStatus === 'Permit' ? 'selected' : '' ?>>3. Permit</option>
                                <option value="Mobilisation" <?= $filterStatus === 'Mobilisation' ? 'selected' : '' ?>>4. Mobilisation</option>
                            </optgroup>
                            <optgroup label="Execution">
                                <option value="Demolition" <?= $filterStatus === 'Demolition' ? 'selected' : '' ?>>5. Demolition</option>
                                <option value="Excavation" <?= $filterStatus === 'Excavation' ? 'selected' : '' ?>>6. Excavation</option>
                                <option value="Construction" <?= $filterStatus === 'Construction' ? 'selected' : '' ?>>7. Construction</option>
                                <option value="Finishes" <?= $filterStatus === 'Finishes' ? 'selected' : '' ?>>8. Finishes</option>
                            </optgroup>
                            <optgroup label="Finalization">
                                <option value="Compliance" <?= $filterStatus === 'Compliance' ? 'selected' : '' ?>>9. Compliance</option>
                                <option value="Condominium" <?= $filterStatus === 'Condominium' ? 'selected' : '' ?>>10. Condominium</option>
                                <option value="Handed Over" <?= $filterStatus === 'Handed Over' ? 'selected' : '' ?>>11. Handed Over</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Project Type</label>
                        <select name="filter_type">
                            <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Types</option>
                            <option value="in-house" <?= $filterType === 'in-house' ? 'selected' : '' ?>>In-House</option>
                            <option value="3rd-party" <?= $filterType === '3rd-party' ? 'selected' : '' ?>>3rd Party</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Client</label>
                        <select name="filter_client">
                            <option value="all">All Clients</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= $client['id'] ?>" <?= $filterClient == $client['id'] ? 'selected' : '' ?>><?= htmlspecialchars($client['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>City</label>
                        <select name="filter_city">
                            <option value="all">All Cities</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?= htmlspecialchars($city) ?>" <?= $filterCity === $city ? 'selected' : '' ?>><?= htmlspecialchars($city) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Island</label>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" name="island_malta" id="island_malta" value="Malta" <?= ($filterIsland === 'all' || $filterIsland === 'Malta') ? 'checked' : '' ?>>
                                <label for="island_malta">Malta</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="island_gozo" id="island_gozo" value="Gozo" <?= ($filterIsland === 'all' || $filterIsland === 'Gozo') ? 'checked' : '' ?>>
                                <label for="island_gozo">Gozo</label>
                            </div>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>">
                <input type="hidden" name="order" value="<?= htmlspecialchars($sortOrder) ?>">
                <div class="filter-buttons">
                    <button type="submit" class="btn">Apply Filters</button>
                    <a href="dashboard.php" class="reset-btn">Reset</a>
                </div>
            </form>
        </div>

        <div class="legend-container">
            <?php foreach ($stageEnum as $name => $num): ?>
                <div class="legend-item" title="Stage <?= $num ?>">
                    <span class="stage-dot" style="background-color: <?= $stageColors[$name] ?>;"></span>
                    <?= $name ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($projectCount > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th><a href="<?= getSortUrl('stage') ?>" class="sortable-header">Stage<?= getSortIndicator('stage') ?></a></th>
                        <th><a href="<?= getSortUrl('name') ?>" class="sortable-header">Project Name<?= getSortIndicator('name') ?></a></th>
                        <th><a href="<?= getSortUrl('client') ?>" class="sortable-header">Client<?= getSortIndicator('client') ?></a></th>
                        <th><a href="<?= getSortUrl('city') ?>" class="sortable-header">City<?= getSortIndicator('city') ?></a></th>
                        <th>PA Number</th>
                        <th>PA Status</th>
                        <th>Architect</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $project): ?>
                        <tr>
                            <td style="text-align: center;">
                                <span class="stage-dot" 
                                      style="background-color: <?= $stageColors[$project['stage']] ?>;" 
                                      title="Stage <?= $project['stage_num'] ?>: <?= $project['stage'] ?>"></span>
                            </td>
                            
                            <td style="font-weight: 600;">
                                <?= htmlspecialchars($project['name']) ?>
                            </td>
                            
                            <td><?= htmlspecialchars($project['client_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($project['city']) ?></td>

                            <?php $projectPAs = $paByProject[$project['id']] ?? []; ?>

                            <td>
                                <?php if (!empty($projectPAs)): ?>
                                    <?php foreach ($projectPAs as $index => $pa): ?>
                                        <?php 
                                        $paText = htmlspecialchars(formatPANumber($pa['pa_number']));
                                        $paUrl = getEAppsUrl($pa['pa_number']);
                                        ?>
                                        <?php if ($paUrl && $paUrl !== '#'): ?>
                                            <a href="<?= $paUrl ?>" target="_blank"><?= $paText ?></a>
                                        <?php else: ?>
                                            <?= $paText ?>
                                        <?php endif; ?>
                                        <?php if ($index < count($projectPAs) - 1): ?><br><?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: var(--text-muted)">TBC</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($projectPAs)): ?>
                                    <?php foreach ($projectPAs as $index => $pa): ?>
                                        <?= htmlspecialchars($pa['pa_status']) ?>
                                        <?php if ($index < count($projectPAs) - 1): ?><br><?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: var(--text-muted)">TBC</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($projectPAs)): ?>
                                    <?php foreach ($projectPAs as $index => $pa): ?>
                                        <?= htmlspecialchars(!empty($pa['architect_name']) ? $pa['architect_name'] : 'TBC') ?>
                                        <?php if ($index < count($projectPAs) - 1): ?><br><?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: var(--text-muted)">TBC</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <a href="mobilisation_detail.php?project_id=<?= $project['id'] ?>" class="btn btn-sm btn-primary">Execution</a>
                                <?php if (canEditProjectDetails($pdo, $project['id'])): ?>
                                    <a href="edit-project.php?id=<?= $project['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <p>No projects match your current filters.</p>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('dashboardFilters');
    if (!form) return;

    const maltaCheckbox = document.getElementById('island_malta');
    const gozoCheckbox = document.getElementById('island_gozo');

    function validateIslands(e) {
        if (!maltaCheckbox.checked && !gozoCheckbox.checked) {
            e.preventDefault();
            this.checked = true;
            alert('At least one island must be selected');
        }
    }

    maltaCheckbox.addEventListener('change', validateIslands);
    gozoCheckbox.addEventListener('change', validateIslands);

    form.addEventListener('submit', function(e) {
        const existingInput = form.querySelector('input[name="filter_island"]');
        if (existingInput) existingInput.remove();

        let filterValue = 'all';
        if (maltaCheckbox.checked && !gozoCheckbox.checked) filterValue = 'Malta';
        else if (gozoCheckbox.checked && !maltaCheckbox.checked) filterValue = 'Gozo';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'filter_island';
        input.value = filterValue;
        form.appendChild(input);
    });
});
</script>

<?php require_once 'footer.php'; ?>
