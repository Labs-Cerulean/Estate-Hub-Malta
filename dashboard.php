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

// If user has no dashboard access, don't process filters
if ($dashboardType !== 'None') {
    // Get filter and sort parameters
    $filterType = $_GET['filter_type'] ?? 'all';
    $filterStatus = $_GET['filter_status'] ?? 'all';
    $filterCity = $_GET['filter_city'] ?? 'all';
    $filterClient = $_GET['filter_client'] ?? 'all';
    $filterArchitect = $_GET['filter_architect'] ?? 'all';
    $filterEngineer = $_GET['filter_engineer'] ?? 'all';
    $filterIsland = $_GET['filter_island'] ?? 'all';
    $filterFinishLevel = $_GET['filter_finish_level'] ?? 'all';
    $sortBy = $_GET['sort'] ?? 'name';
    $sortOrder = $_GET['order'] ?? 'ASC';

    // Validate sort parameters
    $allowedSorts = ['name', 'client', 'city', 'type', 'finish_level'];
    $allowedOrders = ['ASC', 'DESC'];
    if (!in_array($sortBy, $allowedSorts)) $sortBy = 'name';
    if (!in_array($sortOrder, $allowedOrders)) $sortOrder = 'ASC';

    try {
        // Fetch accessible projects using the Rev 2.0 engine
        $projects = getAccessibleProjects($pdo, $userId);
        
        // Extract filter options dynamically from accessible projects
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

        $architects = $pdo->query("SELECT DISTINCT id, name FROM professionals WHERE role_type = 'architect' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $engineers = $pdo->query("SELECT DISTINCT id, name FROM professionals WHERE role_type = 'structural_engineer' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

        // Apply filters
        if ($filterType !== 'all') $projects = array_filter($projects, fn($p) => $p['type'] === $filterType);
        if ($filterCity !== 'all') $projects = array_filter($projects, fn($p) => $p['city'] === $filterCity);
        if ($filterClient !== 'all') $projects = array_filter($projects, fn($p) => $p['clientid'] == $filterClient);
        if ($filterIsland !== 'all') $projects = array_filter($projects, fn($p) => $p['island'] === $filterIsland);
        if ($filterFinishLevel !== 'all') $projects = array_filter($projects, fn($p) => $p['finishlevel'] === $filterFinishLevel);

        // Get PA numbers
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

        // Apply PA specific filters
        if ($filterStatus !== 'all' || $filterArchitect !== 'all' || $filterEngineer !== 'all') {
            $projects = array_filter($projects, function($project) use ($paByProject, $filterStatus, $filterArchitect, $filterEngineer) {
                $projectPAs = $paByProject[$project['id']] ?? [];
                if (empty($projectPAs)) return false; // Hide projects with no PAs if filtering by PA attribute
                
                foreach ($projectPAs as $pa) {
                    $statusMatch = ($filterStatus === 'all' || $pa['pa_status'] === $filterStatus);
                    $archMatch = ($filterArchitect === 'all' || $pa['architect_id'] == $filterArchitect || ($filterArchitect === 'none' && empty($pa['architect_id'])));
                    $engMatch = ($filterEngineer === 'all' || $pa['structural_engineer_id'] == $filterEngineer || ($filterEngineer === 'none' && empty($pa['structural_engineer_id'])));
                    
                    if ($statusMatch && $archMatch && $engMatch) return true;
                }
                return false;
            });
        }

        // Sort projects
        usort($projects, function($a, $b) use ($sortBy, $sortOrder) {
            $valA = $sortBy === 'client' ? ($a['client_name'] ?? '') : ($sortBy === 'finish_level' ? ($a['finishlevel'] ?? 'ZZZ') : $a[$sortBy]);
            $valB = $sortBy === 'client' ? ($b['client_name'] ?? '') : ($sortBy === 'finish_level' ? ($b['finishlevel'] ?? 'ZZZ') : $b[$sortBy]);
            $comparison = strcasecmp($valA, $valB);
            return $sortOrder === 'ASC' ? $comparison : -$comparison;
        });

        // CALCULATE KPI STATS
        $projectCount = count($projects);
        $mobilisedCount = 0;
        $inProcessCount = 0;
        $pendingCount = 0;
        
        // Director Specific KPIs
        $companyKpis = [];

        foreach ($projects as $project) {
            $mobStatus = deriveMobilisationStatus($pdo, $project['id']);
            if ($mobStatus === 'Mobilised') $mobilisedCount++;
            elseif ($mobStatus === 'In Process') $inProcessCount++;
            else $pendingCount++;

            // Aggregate for Director Company Dashboard
            if ($dashboardType === 'Company Dashboard') {
                $cName = $project['client_name'] ?? 'Unassigned';
                if (!isset($companyKpis[$cName])) {
                    $companyKpis[$cName] = ['total' => 0, 'mobilised' => 0, 'pending' => 0];
                }
                $companyKpis[$cName]['total']++;
                if ($mobStatus === 'Mobilised') $companyKpis[$cName]['mobilised']++;
                else $companyKpis[$cName]['pending']++;
            }
        }

        $userCount = $isAdmin ? $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;
        $activeClientsCount = count($clients);

    } catch (Exception $e) {
        $projects = [];
        $paByProject = [];
        $projectCount = 0;
    }
}

function getSortUrl($column) {
    global $sortBy, $sortOrder, $filterType, $filterStatus, $filterCity, $filterClient, $filterArchitect, $filterEngineer, $filterIsland, $filterFinishLevel;
    $newOrder = ($sortBy == $column && $sortOrder == 'ASC') ? 'DESC' : 'ASC';
    $params = [
        'sort' => $column, 'order' => $newOrder, 'filter_type' => $filterType, 
        'filter_status' => $filterStatus, 'filter_city' => $filterCity, 
        'filter_client' => $filterClient, 'filter_architect' => $filterArchitect, 
        'filter_engineer' => $filterEngineer, 'filter_finish_level' => $filterFinishLevel,
    ];
    if ($filterIsland !== 'all') $params['filterisland'] = $filterIsland;
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

<div class="main-container">
    <h1 class="page-title"><?= htmlspecialchars($dashboardType) ?></h1>

    <?php if ($dashboardType === 'None'): ?>
        <div class="empty-state card">
            <h2 style="margin-bottom: 1rem; color: var(--primary-color);">Welcome to Estate Hub</h2>
            <p>You have successfully logged in. Please use the navigation menu above to access your specific modules.</p>
        </div>

    <?php else: ?>
        
        <div class="stats-grid">
            <?php if ($dashboardType === 'Admin Dashboard'): ?>
                <div class="stat-card">
                    <div class="stat-number"><?= $projectCount ?></div>
                    <div class="stat-label">Total Projects</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number mobilised" style="color: var(--success);"><?= $mobilisedCount ?></div>
                    <div class="stat-label">Fully Mobilised</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $activeClientsCount ?></div>
                    <div class="stat-label">Active Clients</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $userCount ?></div>
                    <div class="stat-label">System Users</div>
                </div>

            <?php elseif ($dashboardType === 'Company Dashboard'): ?>
                <div class="stat-card">
                    <div class="stat-number"><?= $projectCount ?></div>
                    <div class="stat-label">Total Projects Managed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--success);"><?= $mobilisedCount ?></div>
                    <div class="stat-label">Mobilised Projects</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--warning);"><?= $inProcessCount ?></div>
                    <div class="stat-label">In Process</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--primary-color);"><?= count($companyKpis) ?></div>
                    <div class="stat-label">Companies Supervised</div>
                </div>

            <?php elseif ($dashboardType === 'Sales Dashboard'): ?>
                <div class="stat-card">
                    <div class="stat-number"><?= $projectCount ?></div>
                    <div class="stat-label">Available Projects</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--text-muted);">-</div>
                    <div class="stat-label">Units Available (Coming Soon)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--text-muted);">-</div>
                    <div class="stat-label">Active Leads (Coming Soon)</div>
                </div>

            <?php else: /* Standard Project Dashboard */ ?>
                <div class="stat-card">
                    <div class="stat-number"><?= $projectCount ?></div>
                    <div class="stat-label">My Projects</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--success);"><?= $mobilisedCount ?></div>
                    <div class="stat-label">Mobilised</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: var(--danger);"><?= $pendingCount ?></div>
                    <div class="stat-label">Action Required</div>
                </div>
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
                            <span>Mobilised: <strong style="color: var(--success);"><?= $kpi['mobilised'] ?></strong></span>
                            <span>Pending: <strong style="color: var(--danger);"><?= $kpi['pending'] ?></strong></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="projects-section">
            <div class="projects-header">
                <h2 class="section-title">Projects List</h2>
                <?php if (hasPermission('add_project')): ?>
                    <a href="create-project.php" class="btn">Add Project</a>
                <?php endif; ?>
            </div>

            <div class="filters-section">
                <form method="GET" id="dashboardFilters">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label>Project Type</label>
                            <select name="filter_type">
                                <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Types</option>
                                <option value="in-house" <?= $filterType === 'in-house' ? 'selected' : '' ?>>In-House</option>
                                <option value="3rd-party" <?= $filterType === '3rd-party' ? 'selected' : '' ?>>3rd Party</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Finish Level</label>
                            <select name="filter_finish_level">
                                <option value="all">All Levels</option>
                                <option value="Common Parts Only" <?= $filterFinishLevel === 'Common Parts Only' ? 'selected' : '' ?>>Common Parts Only</option>
                                <option value="Semi Finished" <?= $filterFinishLevel === 'Semi Finished' ? 'selected' : '' ?>>Semi Finished</option>
                                <option value="Finished" <?= $filterFinishLevel === 'Finished' ? 'selected' : '' ?>>Finished</option>
                                <option value="Shell" <?= $filterFinishLevel === 'Shell' ? 'selected' : '' ?>>Shell</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Client</label>
                            <select name="filter_client">
                                <option value="all">All Clients</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= $client['id'] ?>" <?= $filterClient == $client['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($client['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>City</label>
                            <select name="filter_city">
                                <option value="all">All Cities</option>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?= htmlspecialchars($city) ?>" <?= $filterCity === $city ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($city) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>PA Status</label>
                            <select name="filter_status">
                                <option value="all">All Statuses</option>
                                <option value="Endorsed" <?= $filterStatus === 'Endorsed' ? 'selected' : '' ?>>Endorsed</option>
                                <option value="Decided" <?= $filterStatus === 'Decided' ? 'selected' : '' ?>>Decided</option>
                                <option value="Tracking" <?= $filterStatus === 'Tracking' ? 'selected' : '' ?>>Tracking</option>
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

            <?php if ($projectCount > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th><a href="<?= getSortUrl('name') ?>" class="sortable-header">Project Name<?= getSortIndicator('name') ?></a></th>
                            <th><a href="<?= getSortUrl('client') ?>" class="sortable-header">Client<?= getSortIndicator('client') ?></a></th>
                            <th><a href="<?= getSortUrl('city') ?>" class="sortable-header">City<?= getSortIndicator('city') ?></a></th>
                            <th><a href="<?= getSortUrl('finish_level') ?>" class="sortable-header">Finish Level<?= getSortIndicator('finish_level') ?></a></th>
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
                                <td>
                                    <?= htmlspecialchars($project['name']) ?>
                                    <?php if ($project['is_tracking'] == 1): ?>
                                        <br><span style="font-size: 0.7rem; background: var(--warning-bg); color: var(--warning); padding: 2px 6px; border-radius: 4px;">Tracking Stage</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($project['client_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($project['city']) ?></td>
                                <td><?= htmlspecialchars($project['finishlevel'] ?? 'N/A') ?></td>

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
                                    <?php if (!empty($projectPAs)): ?>
                                        <?php foreach ($projectPAs as $index => $pa): ?>
                                            <?= htmlspecialchars(!empty($pa['structural_engineer_name']) ? $pa['structural_engineer_name'] : 'TBC') ?>
                                            <?php if ($index < count($projectPAs) - 1): ?><br><?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted)">TBC</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <a href="mobilisation_detail.php?project_id=<?= $project['id'] ?>" class="btn btn-sm btn-primary">View</a>
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
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('dashboardFilters');
    if (!form) return;

    const maltaCheckbox = document.getElementById('island_malta');
    const gozoCheckbox = document.getElementById('island_gozo');

    // Prevent both islands from being unchecked
    function validateIslands(e) {
        if (!maltaCheckbox.checked && !gozoCheckbox.checked) {
            e.preventDefault();
            this.checked = true;
            alert('At least one island must be selected');
        }
    }

    maltaCheckbox.addEventListener('change', validateIslands);
    gozoCheckbox.addEventListener('change', validateIslands);

    // Island form submission logic
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
