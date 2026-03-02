<?php
require_once 'init.php';
require_once 'session-check.php';

// Get current user details
$userId = getCurrentUserId();
$userName = getCurrentUserFullName();
$userRole = getCurrentRole();
$isAdmin = isAdmin();

// Capability checks
$canViewTracking = hasPermission('view_tracking') || $isAdmin;

// 1. Determine Dashboard View Type based on PDF specifications
$dashboardType = 'Project Dashboard'; // Default for Architects, Engineers, PMO, etc.
if ($userRole === 'admin') {
    $dashboardType = 'Admin Dashboard';
} elseif ($userRole === 'director') {
    $dashboardType = 'Company Dashboard';
} elseif (in_array($userRole, ['sales_manager', 'sales_agent'])) {
    $dashboardType = 'Sales Dashboard';
} elseif ($userRole === 'condominium_agent') {
    $dashboardType = 'None'; // Restricted Dashboard
}

// 2. Define Visible Stages based on Role
$visibleStages = [];
if ($canViewTracking) {
    $visibleStages = ['Feasibility', 'Tracking'];
}

switch ($userRole) {
    case 'sales_manager':
    case 'sales_agent':
        $visibleStages = array_merge($visibleStages, ['Finishes', 'Compliance', 'Condominium', 'Handed Over']);
        break;
    case 'end_customer':
        $visibleStages = array_merge($visibleStages, ['Handed Over']);
        break;
    case 'condominium_agent':
        $visibleStages = array_merge($visibleStages, ['Condominium', 'Handed Over']);
        break;
    case 'subcontractor':
        $visibleStages = array_merge($visibleStages, ['Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over']);
        break;
    case 'ohsa_rep':
        $visibleStages = array_merge($visibleStages, ['Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over']);
        break;
    default:
        $visibleStages = array_merge($visibleStages, ['Permit', 'Mobilisation', 'Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over']);
        break;
}

// Legend Items builder (Admins/Directors also see On-Hold and Withdrawn)
$legendItems = $visibleStages;
if (in_array($userRole, ['admin', 'director'])) {
    array_unshift($legendItems, 'Withdrawn', 'On-Hold');
}

// Stage Enums & Colors for the Legend and Dots
$stageEnum = [
    'Feasibility' => 1, 'Tracking' => 2, 'Permit' => 3, 'Mobilisation' => 4,
    'Demolition' => 5, 'Excavation' => 6, 'Construction' => 7, 'Finishes' => 8,
    'Compliance' => 9, 'Condominium' => 10, 'Handed Over' => 11
];

$stageColors = [
    'Withdrawn' => '#4b5563',     // Dark Slate
    'On-Hold' => '#f59e0b',       // Amber
    'Feasibility' => '#94a3b8',   
    'Tracking' => '#0ea5e9',      
    'Permit' => '#3b82f6',        
    'Mobilisation' => '#6366f1',  
    'Demolition' => '#ef4444',    
    'Excavation' => '#f97316',    
    'Construction' => '#eab308',  
    'Finishes' => '#84cc16',      
    'Compliance' => '#14b8a6',    
    'Condominium' => '#a855f7',   
    'Handed Over' => '#22c55e'    
];

if ($dashboardType !== 'None') {
    $filterType = $_GET['filter_type'] ?? 'all';
    $filterStatus = $_GET['filter_status'] ?? 'all'; 
    $filterCity = $_GET['filter_city'] ?? 'all';
    $filterClient = $_GET['filter_client'] ?? 'all';
    $filterIsland = $_GET['filter_island'] ?? 'all';
    $filterDbStatus = $_GET['filter_db_status'] ?? 'Active'; // Active vs Withdrawn
    
    $sortBy = $_GET['sort'] ?? 'stage';
    $sortOrder = $_GET['order'] ?? 'DESC';

    $allowedSorts = ['name', 'client', 'city', 'type', 'finish_level', 'stage'];
    $allowedOrders = ['ASC', 'DESC'];
    if (!in_array($sortBy, $allowedSorts)) $sortBy = 'stage';
    if (!in_array($sortOrder, $allowedOrders)) $sortOrder = 'DESC';

    try {
        $projects = getAccessibleProjects($pdo, $userId);
        
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

        $preExecCount = 0; 
        $execCount = 0;    
        $finalCount = 0;   
        $companyKpis = []; 

        foreach ($projects as $key => $project) {
            $pStatus = $project['project_status'] ?? 'Active';

            // 1. Security Lock: If not Admin/Director, ALWAYS hide Withdrawn/On-Hold
            if (!in_array($userRole, ['admin', 'director']) && $pStatus !== 'Active') {
                unset($projects[$key]);
                continue;
            }

            // 2. User Dropdown Filter Application
            if ($filterDbStatus !== 'All' && $pStatus !== $filterDbStatus) {
                unset($projects[$key]);
                continue;
            }

            // 3. Stage Logic Calculation
            if ($pStatus === 'Withdrawn' || $pStatus === 'On-Hold') {
                // Override stage name so Director explicitly knows it's dead
                $stage = $pStatus;
                $stageNum = ($pStatus === 'Withdrawn') ? -1 : 0; 
            } else {
                $stage = deriveProjectStage($pdo, $project['id']);
                $stageNum = $stageEnum[$stage] ?? 1;
                
                if (!$canViewTracking && in_array($stage, ['Feasibility', 'Tracking'])) {
                    unset($projects[$key]);
                    continue;
                }
                if (!in_array($stage, $visibleStages)) {
                    unset($projects[$key]);
                    continue;
                }
            }

            $projects[$key]['stage'] = $stage;
            $projects[$key]['stage_num'] = $stageNum;

            // Only count ACTIVE projects towards operational KPIs
            if ($pStatus === 'Active') {
                if ($stageNum >= 9) $finalCount++;
                elseif ($stageNum >= 5) $execCount++;
                else $preExecCount++;

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
        }
        
        $projects = array_values($projects); 
        $projectCount = count($projects);
        $userCount = $isAdmin ? $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;

        if ($filterType !== 'all') $projects = array_filter($projects, fn($p) => $p['type'] === $filterType);
        if ($filterCity !== 'all') $projects = array_filter($projects, fn($p) => $p['city'] === $filterCity);
        if ($filterClient !== 'all') $projects = array_filter($projects, fn($p) => $p['clientid'] == $filterClient);
        if ($filterIsland !== 'all') $projects = array_filter($projects, fn($p) => $p['island'] === $filterIsland);
        if ($filterStatus !== 'all') $projects = array_filter($projects, fn($p) => $p['stage'] === $filterStatus);

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
    global $sortBy, $sortOrder, $filterType, $filterStatus, $filterCity, $filterClient, $filterIsland, $filterDbStatus;
    $newOrder = ($sortBy == $column && $sortOrder == 'ASC') ? 'DESC' : 'ASC';
    $params = ['sort' => $column, 'order' => $newOrder, 'filter_type' => $filterType, 'filter_status' => $filterStatus, 'filter_city' => $filterCity, 'filter_client' => $filterClient, 'filter_db_status' => $filterDbStatus];
    if ($filterIsland !== 'all') $params['filter_island'] = $filterIsland;
    return 'dashboard.php?' . http_build_query($params);
}

function getSortIndicator($column) {
    global $sortBy, $sortOrder;
    if ($sortBy === $column) return $sortOrder === 'ASC' ? ' ▲' : ' ▼';
    return '';
}

function buildPaUrl(?string $paNumber): ?string {
    if (empty($paNumber)) return null;
    if (!preg_match('/(PA|PC|DN)\/(\d+)\/(\d+)/', $paNumber, $m)) return null;
    return "https://eapps.pa.org.mt/Case/CaseDetails?caseType={$m[1]}&casenumber={$m[2]}&caseYear={$m[3]}";
}

$pageTitle = $dashboardType;
require_once 'header.php';
?>

<style>
.stage-dot { display: inline-block; width: 14px; height: 14px; border-radius: 50%; box-shadow: 0 0 4px rgba(0,0,0,0.3); flex-shrink: 0; }
.legend-container { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-md); padding: 1rem; margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: center; }
.legend-item { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; color: var(--text-secondary); font-weight: 500; }
.table-container { overflow-x: auto; }
.table-container table { width: 100%; table-layout: auto; border-collapse: collapse; }
.table-container th, .table-container td { padding: 1rem 0.75rem; vertical-align: top; word-break: normal; }
.nowrap-cell { white-space: nowrap; }
.min-w-150 { min-width: 150px; }
.cell-list-item { display: block; margin-bottom: 0.5rem; min-height: 1.2rem; line-height: 1.3; }
.cell-list-item:last-child { margin-bottom: 0; }
.action-buttons-wrapper { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-start; max-width: 220px; }
.action-buttons-wrapper .btn-sm { margin: 0; padding: 0.35rem 0.6rem; font-size: 0.75rem; flex: 0 0 auto; text-align: center; white-space: nowrap; }
.summer-break-icon { margin-left: 0.5rem; font-size: 1.1rem; cursor: help; filter: drop-shadow(0 0 2px rgba(245, 158, 11, 0.5)); }
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
            <?php if ($dashboardType === 'Admin Dashboard'): ?>
                <div class="stat-card"><div class="stat-number"><?= $projectCount ?></div><div class="stat-label">Active Projects</div></div>
                <div class="stat-card"><div class="stat-number" style="color: <?= $stageColors['Permit'] ?>;"><?= $preExecCount ?></div><div class="stat-label">Pre-Execution (Stg 1-4)</div></div>
                <div class="stat-card"><div class="stat-number" style="color: <?= $stageColors['Construction'] ?>;"><?= $execCount ?></div><div class="stat-label">In Execution (Stg 5-8)</div></div>
                <div class="stat-card"><div class="stat-number" style="color: <?= $stageColors['Handed Over'] ?>;"><?= $finalCount ?></div><div class="stat-label">Finalizing (Stg 9-11)</div></div>
                <div class="stat-card"><div class="stat-number"><?= $userCount ?></div><div class="stat-label">Total Users</div></div>
            <?php elseif ($dashboardType === 'Company Dashboard'): ?>
                <div class="stat-card"><div class="stat-number"><?= $projectCount ?></div><div class="stat-label">Active Projects</div></div>
                <div class="stat-card"><div class="stat-number" style="color: <?= $stageColors['Construction'] ?>;"><?= $execCount ?></div><div class="stat-label">Sites In Execution</div></div>
                <div class="stat-card"><div class="stat-number" style="color: <?= $stageColors['Handed Over'] ?>;"><?= $finalCount ?></div><div class="stat-label">Sites Finalizing</div></div>
                <div class="stat-card"><div class="stat-number" style="color: var(--primary-color);"><?= count($companyKpis) ?></div><div class="stat-label">Companies Supervised</div></div>
            <?php else: ?>
                <div class="stat-card"><div class="stat-number"><?= $projectCount ?></div><div class="stat-label">Active Projects</div></div>
                <div class="stat-card"><div class="stat-number" style="color: <?= $stageColors['Permit'] ?>;"><?= $preExecCount ?></div><div class="stat-label">Pre-Execution</div></div>
                <div class="stat-card"><div class="stat-number" style="color: <?= $stageColors['Construction'] ?>;"><?= $execCount ?></div><div class="stat-label">In Execution</div></div>
                <div class="stat-card"><div class="stat-number" style="color: <?= $stageColors['Handed Over'] ?>;"><?= $finalCount ?></div><div class="stat-label">Finalizing</div></div>
            <?php endif; ?>
        </div>

        <div class="projects-section">
            <div class="projects-header">
                <h2 class="section-title">Projects Portfolio</h2>
                <?php if (hasPermission('add_project')): ?>
                    <a href="create-project.php" class="btn">Add Project</a>
                <?php endif; ?>
            </div>

            <div class="filters-section">
                <form method="GET" id="dashboardFilters">
                    <div class="filters-grid">
                        
                        <?php if (in_array($userRole, ['admin', 'director'])): ?>
                        <div class="filter-group" style="background: rgba(239, 68, 68, 0.05); padding: 0.5rem; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.2);">
                            <label style="color: #ef4444;">Operational Status (Admin)</label>
                            <select name="filter_db_status">
                                <option value="Active" <?= $filterDbStatus === 'Active' ? 'selected' : '' ?>>🟢 Active Projects</option>
                                <option value="On-Hold" <?= $filterDbStatus === 'On-Hold' ? 'selected' : '' ?>>🟡 On-Hold Projects</option>
                                <option value="Withdrawn" <?= $filterDbStatus === 'Withdrawn' ? 'selected' : '' ?>>⚫ Withdrawn Projects</option>
                                <option value="All" <?= $filterDbStatus === 'All' ? 'selected' : '' ?>>All Projects</option>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="filter-group">
                            <label>Stage Filter</label>
                            <select name="filter_status">
                                <option value="all">All Stages</option>
                                <?php 
                                $preExecOpts = array_intersect(['Feasibility', 'Tracking', 'Permit', 'Mobilisation'], $visibleStages);
                                $execOpts = array_intersect(['Demolition', 'Excavation', 'Construction', 'Finishes'], $visibleStages);
                                $finalOpts = array_intersect(['Compliance', 'Condominium', 'Handed Over'], $visibleStages);
                                ?>
                                <?php if (!empty($preExecOpts)): ?><optgroup label="Pre-Execution"><?php foreach($preExecOpts as $st): ?><option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= $stageEnum[$st] ?>. <?= $st ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                                <?php if (!empty($execOpts)): ?><optgroup label="Execution"><?php foreach($execOpts as $st): ?><option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= $stageEnum[$st] ?>. <?= $st ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                                <?php if (!empty($finalOpts)): ?><optgroup label="Finalization"><?php foreach($finalOpts as $st): ?><option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= $stageEnum[$st] ?>. <?= $st ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Project Type</label>
                            <select name="filter_type">
                                <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Types</option>
                                <option value="in-house" <?= $filterType === 'in-house' ? 'selected' : '' ?>>In-House (Self Funded)</option>
                                <option value="3rd-party" <?= $filterType === '3rd-party' ? 'selected' : '' ?>>Capital Project (3rd Party)</option>
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
                            <label>Island</label>
                            <div class="checkbox-group">
                                <div class="checkbox-item"><input type="checkbox" name="island_malta" id="island_malta" value="Malta" <?= ($filterIsland === 'all' || $filterIsland === 'Malta') ? 'checked' : '' ?>><label for="island_malta">Malta</label></div>
                                <div class="checkbox-item"><input type="checkbox" name="island_gozo" id="island_gozo" value="Gozo" <?= ($filterIsland === 'all' || $filterIsland === 'Gozo') ? 'checked' : '' ?>><label for="island_gozo">Gozo</label></div>
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
                <?php foreach ($legendItems as $name): ?>
                    <?php $num = $stageEnum[$name] ?? '-'; ?>
                    <div class="legend-item" title="<?= $num !== '-' ? "Stage $num" : $name ?>">
                        <span class="stage-dot" style="background-color: <?= $stageColors[$name] ?>;"></span>
                        <?= $name ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($projectCount > 0): ?>
            <?php $isSalesDb = ($dashboardType === 'Sales Dashboard'); ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 50px; text-align: center;"><a href="<?= getSortUrl('stage') ?>" class="sortable-header">Stage<?= getSortIndicator('stage') ?></a></th>
                            <th class="min-w-150"><a href="<?= getSortUrl('name') ?>" class="sortable-header">Project Name<?= getSortIndicator('name') ?></a></th>
                            <th class="min-w-150"><a href="<?= getSortUrl('client') ?>" class="sortable-header">Client<?= getSortIndicator('client') ?></a></th>
                            <th class="nowrap-cell"><a href="<?= getSortUrl('type') ?>" class="sortable-header">Type<?= getSortIndicator('type') ?></a></th>
                            <th class="nowrap-cell"><a href="<?= getSortUrl('city') ?>" class="sortable-header">City<?= getSortIndicator('city') ?></a></th>
                            <th class="nowrap-cell"><a href="<?= getSortUrl('finish_level') ?>" class="sortable-header">Finish Level<?= getSortIndicator('finish_level') ?></a></th>
                            
                            <?php if (!$isSalesDb): ?>
                                <th class="nowrap-cell">PA Number</th>
                                <th class="min-w-150">Architect</th>
                                <th class="min-w-150">Structural Engineer</th>
                            <?php endif; ?>
                            
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $project): ?>
                            <tr style="<?= in_array($project['project_status'] ?? '', ['Withdrawn', 'On-Hold']) ? 'opacity: 0.6;' : '' ?>">
                                <td style="text-align: center;">
                                    <span class="stage-dot" 
                                          style="background-color: <?= $stageColors[$project['stage']] ?>;" 
                                          title="<?= $project['stage'] ?>"></span>
                                </td>
                                
                                <td style="font-weight: 600; color: var(--text-primary);">
                                    <div style="display: flex; align-items: center;">
                                        <?= htmlspecialchars($project['name']) ?>
                                        <?php if (!empty($project['summer_break_flag']) && $project['summer_break_flag'] == 1): ?>
                                            <span class="summer-break-icon" title="Summer Break Alarm Active (Tourism Area)">☀️</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                
                                <td><?= htmlspecialchars($project['client_name'] ?? 'N/A') ?></td>
                                
                                <td class="nowrap-cell">
                                    <?php if ($project['type'] === 'in-house'): ?>
                                        <span style="color: var(--primary-color); font-weight: 500;">In-House</span>
                                    <?php elseif ($project['type'] === '3rd-party'): ?>
                                        <span style="color: #0ea5e9; font-weight: 500;">Capital Project</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($project['type']) ?>
                                    <?php endif; ?>
                                </td>

                                <td class="nowrap-cell"><?= htmlspecialchars($project['city']) ?></td>
                                <td class="nowrap-cell"><?= htmlspecialchars($project['finishlevel'] ?? 'N/A') ?></td>

                                <?php 
                                $projectPAs = $paByProject[$project['id']] ?? []; 
                                if (!$isSalesDb): 
                                ?>
                                    <td class="nowrap-cell">
                                        <?php if (!empty($projectPAs)): ?>
                                            <?php foreach ($projectPAs as $pa): ?>
                                                <div class="cell-list-item">
                                                    <?php 
                                                    $paText = htmlspecialchars(formatPANumber($pa['pa_number']));
                                                    $paUrl = buildPaUrl($pa['pa_number']);
                                                    if ($paUrl): ?>
                                                        <a href="<?= htmlspecialchars($paUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $paText ?></a>
                                                    <?php else: echo $paText; endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?><span style="color: var(--text-muted)">TBC</span><?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($projectPAs)): ?>
                                            <?php foreach ($projectPAs as $pa): ?>
                                                <div class="cell-list-item"><?= htmlspecialchars(!empty($pa['architect_name']) ? $pa['architect_name'] : 'TBC') ?></div>
                                            <?php endforeach; ?>
                                        <?php else: ?><span style="color: var(--text-muted)">TBC</span><?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($projectPAs)): ?>
                                            <?php foreach ($projectPAs as $pa): ?>
                                                <div class="cell-list-item"><?= htmlspecialchars(!empty($pa['structural_engineer_name']) ? $pa['structural_engineer_name'] : 'TBC') ?></div>
                                            <?php endforeach; ?>
                                        <?php else: ?><span style="color: var(--text-muted)">TBC</span><?php endif; ?>
                                    </td>
                                <?php endif; ?>

                                <td>
                                    <div class="action-buttons-wrapper">
                                        <?php if (hasPermission('view_mobilisation') || $isAdmin): ?>
                                            <?php $canUpdateProjStatus = canUpdateStatus($pdo, $project['id']); ?>
                                            <a href="mobilisation_detail.php?project_id=<?= $project['id'] ?>" class="btn btn-sm btn-primary">
                                                <?= $canUpdateProjStatus ? 'Execution' : 'View Hub' ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (hasPermission('view_property_sales') || $isAdmin): ?>
                                            <a href="property_sales.php?project_id=<?= $project['id'] ?>" class="btn btn-sm" style="background: #10B981; color: white; border: none;">Sales</a>
                                        <?php endif; ?>
                                        <?php if ((hasPermission('view_capital_projects') || $isAdmin) && $project['type'] === '3rd-party'): ?>
                                            <a href="capital_projects.php?project_id=<?= $project['id'] ?>" class="btn btn-sm" style="background: #0ea5e9; color: white; border: none;">Capital</a>
                                        <?php endif; ?>
                                        <?php if (canEditProjectDetails($pdo, $project['id'])): ?>
                                            <a href="edit-project.php?id=<?= $project['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <p>No projects match your current filters or assigned access limits.</p>
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

    function validateIslands(e) {
        if (!maltaCheckbox.checked && !gozoCheckbox.checked) { e.preventDefault(); this.checked = true; alert('At least one island must be selected'); }
    }

    if(maltaCheckbox) maltaCheckbox.addEventListener('change', validateIslands);
    if(gozoCheckbox) gozoCheckbox.addEventListener('change', validateIslands);

    form.addEventListener('submit', function(e) {
        const existingInput = form.querySelector('input[name="filter_island"]');
        if (existingInput) existingInput.remove();

        let filterValue = 'all';
        if (maltaCheckbox && maltaCheckbox.checked && (!gozoCheckbox || !gozoCheckbox.checked)) filterValue = 'Malta';
        else if (gozoCheckbox && gozoCheckbox.checked && (!maltaCheckbox || !maltaCheckbox.checked)) filterValue = 'Gozo';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'filter_island';
        input.value = filterValue;
        form.appendChild(input);
    });
});
</script>

<?php require_once 'footer.php'; ?>
