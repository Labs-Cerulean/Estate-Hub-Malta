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

// Determine Dashboard View Type based on PDF specifications
$dashboardType = 'Project Dashboard'; // Default
if ($userRole === 'admin') $dashboardType = 'Admin Dashboard';
elseif ($userRole === 'director') $dashboardType = 'Company Dashboard';
elseif (in_array($userRole, ['sales_manager', 'sales_agent'])) $dashboardType = 'Sales Dashboard';
elseif ($userRole === 'condominium_agent') $dashboardType = 'None'; 

// Define Visible Stages based on Role
$visibleStages = [];
if ($canViewTracking) $visibleStages = ['Feasibility', 'Tracking'];

switch ($userRole) {
    case 'sales_manager':
    case 'sales_agent':
        $visibleStages = array_merge($visibleStages, ['Finishes', 'Compliance', 'Condominium', 'Handed Over']); break;
    case 'end_customer':
        $visibleStages = array_merge($visibleStages, ['Handed Over']); break;
    case 'condominium_agent':
        $visibleStages = array_merge($visibleStages, ['Condominium', 'Handed Over']); break;
    case 'subcontractor':
        $visibleStages = array_merge($visibleStages, ['Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over']); break;
    case 'ohsa_rep':
        $visibleStages = array_merge($visibleStages, ['Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over']); break;
    default:
        $visibleStages = array_merge($visibleStages, ['Permit', 'Mobilisation', 'Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over']); break;
}

// Legend Items builder
$legendItems = $visibleStages;
if (in_array($userRole, ['admin', 'director'])) array_unshift($legendItems, 'Withdrawn', 'On-Hold');

$stageEnum = ['Feasibility'=>1, 'Tracking'=>2, 'Permit'=>3, 'Mobilisation'=>4, 'Demolition'=>5, 'Excavation'=>6, 'Construction'=>7, 'Finishes'=>8, 'Compliance'=>9, 'Condominium'=>10, 'Handed Over'=>11];
$stageColors = ['Withdrawn'=>'#4b5563', 'On-Hold'=>'#f59e0b', 'Feasibility'=>'#94a3b8', 'Tracking'=>'#0ea5e9', 'Permit'=>'#3b82f6', 'Mobilisation'=>'#6366f1', 'Demolition'=>'#ef4444', 'Excavation'=>'#f97316', 'Construction'=>'#eab308', 'Finishes'=>'#84cc16', 'Compliance'=>'#14b8a6', 'Condominium'=>'#a855f7', 'Handed Over'=>'#22c55e'];

// Current View State (Map vs Table)
$currentView = $_GET['view'] ?? 'table';

if ($dashboardType !== 'None') {
    $filterType = $_GET['filter_type'] ?? 'all';
    $filterStatus = $_GET['filter_status'] ?? 'all'; 
    $filterCity = $_GET['filter_city'] ?? 'all';
    $filterClient = $_GET['filter_client'] ?? 'all';
    $filterIsland = $_GET['filter_island'] ?? 'all';
    $filterDbStatus = $_GET['filter_db_status'] ?? 'Active'; 
    
    $sortBy = $_GET['sort'] ?? 'stage';
    $sortOrder = $_GET['order'] ?? 'DESC';

    $allowedSorts = ['name', 'client', 'city', 'type', 'finish_level', 'stage'];
    if (!in_array($sortBy, $allowedSorts)) $sortBy = 'stage';
    if (!in_array($sortOrder, ['ASC', 'DESC'])) $sortOrder = 'DESC';

    try {
        $projects = getAccessibleProjects($pdo, $userId);
        
        $cities = array_unique(array_filter(array_column($projects, 'city'))); sort($cities);
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
            // FIX: Added pan.pa_status to the SQL Query so we can display it!
            $paStmt = $pdo->prepare("SELECT pan.project_id, pan.pa_number, pan.pa_status, arch.name AS architect_name, se.name AS structural_engineer_name FROM project_pa_numbers pan LEFT JOIN professionals arch ON arch.id = pan.architect_id LEFT JOIN professionals se ON se.id = pan.structural_engineer_id WHERE pan.project_id IN ($placeholders)");
            $paStmt->execute($projectIds);
            foreach ($paStmt->fetchAll(PDO::FETCH_ASSOC) as $pa) $paByProject[$pa['project_id']][] = $pa;
        }

        $preExecCount = 0; $execCount = 0; $finalCount = 0; $companyKpis = []; 

        foreach ($projects as $key => $project) {
            $pStatus = $project['project_status'] ?? 'Active';
            if (!in_array($userRole, ['admin', 'director']) && $pStatus !== 'Active') { unset($projects[$key]); continue; }
            if ($filterDbStatus !== 'All' && $pStatus !== $filterDbStatus) { unset($projects[$key]); continue; }

            if ($pStatus === 'Withdrawn' || $pStatus === 'On-Hold') {
                $stage = $pStatus; $stageNum = ($pStatus === 'Withdrawn') ? -1 : 0; 
            } else {
                $stage = deriveProjectStage($pdo, $project['id']);
                $stageNum = $stageEnum[$stage] ?? 1;
                if (!$canViewTracking && in_array($stage, ['Feasibility', 'Tracking'])) { unset($projects[$key]); continue; }
                if (!in_array($stage, $visibleStages)) { unset($projects[$key]); continue; }
            }

            $projects[$key]['stage'] = $stage;
            $projects[$key]['stage_num'] = $stageNum;

            if ($pStatus === 'Active') {
                if ($stageNum >= 9) $finalCount++; elseif ($stageNum >= 5) $execCount++; else $preExecCount++;
                if ($dashboardType === 'Company Dashboard') {
                    $cName = $project['client_name'] ?? 'Unassigned';
                    if (!isset($companyKpis[$cName])) $companyKpis[$cName] = ['total'=>0, 'pre'=>0, 'exec'=>0, 'final'=>0];
                    $companyKpis[$cName]['total']++;
                    if ($stageNum >= 9) $companyKpis[$cName]['final']++; elseif ($stageNum >= 5) $companyKpis[$cName]['exec']++; else $companyKpis[$cName]['pre']++;
                }
            }
        }
        
        $projects = array_values($projects); 
        $projectCount = count($projects);
        $userCount = $isAdmin ? $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;

        if ($filterType !== 'all') $projects = array_filter($projects, fn($p) => $p['type'] === $filterType);
        if ($filterCity !== 'all') $projects = array_filter($projects, fn($p) => $p['city'] === $filterCity);
        if ($filterClient !== 'all') {
            if ($filterClient === 'group_excel') {
                $projects = array_filter($projects, fn($p) => stripos($p['client_name'] ?? '', 'Excel') !== false);
            } elseif ($filterClient === 'group_blue_clay') {
                $projects = array_filter($projects, fn($p) => stripos($p['client_name'] ?? '', 'Blue Clay') !== false || stripos($p['client_name'] ?? '', 'Blueclay') !== false);
            } else {
                $projects = array_filter($projects, fn($p) => $p['clientid'] == $filterClient);
            }
        }
        if ($filterIsland !== 'all') $projects = array_filter($projects, fn($p) => $p['island'] === $filterIsland);
        if ($filterStatus !== 'all') $projects = array_filter($projects, fn($p) => $p['stage'] === $filterStatus);

        usort($projects, function($a, $b) use ($sortBy, $sortOrder) {
            if ($sortBy === 'stage') $comp = $a['stage_num'] <=> $b['stage_num'];
            else {
                $vA = $sortBy === 'client' ? ($a['client_name'] ?? '') : ($sortBy === 'finish_level' ? ($a['finishlevel'] ?? 'ZZZ') : $a[$sortBy]);
                $vB = $sortBy === 'client' ? ($b['client_name'] ?? '') : ($sortBy === 'finish_level' ? ($b['finishlevel'] ?? 'ZZZ') : $b[$sortBy]);
                $comp = strcasecmp($vA, $vB);
            }
            return $sortOrder === 'ASC' ? $comp : -$comp;
        });
        
        $projects = array_values($projects); // Re-index for JSON

    } catch (Exception $e) { $projects = []; $paByProject = []; $projectCount = 0; }
}

function getSortUrl($column) {
    global $sortBy, $sortOrder, $filterType, $filterStatus, $filterCity, $filterClient, $filterIsland, $filterDbStatus, $currentView;
    $newOrder = ($sortBy == $column && $sortOrder == 'ASC') ? 'DESC' : 'ASC';
    $params = ['sort' => $column, 'order' => $newOrder, 'filter_type' => $filterType, 'filter_status' => $filterStatus, 'filter_city' => $filterCity, 'filter_client' => $filterClient, 'filter_db_status' => $filterDbStatus, 'view' => $currentView];
    if ($filterIsland !== 'all') $params['filter_island'] = $filterIsland;
    return 'dashboard.php?' . http_build_query($params);
}
function getSortIndicator($column) { global $sortBy, $sortOrder; if ($sortBy === $column) return $sortOrder === 'ASC' ? ' ▲' : ' ▼'; return ''; }
function buildPaUrl(?string $paNumber): ?string { if (empty($paNumber)) return null; if (!preg_match('/(PA|PC|DN)\/(\d+)\/(\d+)/', $paNumber, $m)) return null; return "https://eapps.pa.org.mt/Case/CaseDetails?caseType={$m[1]}&casenumber={$m[2]}&caseYear={$m[3]}"; }

$pageTitle = $dashboardType;
require_once 'header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>

<style>
/* Dashboard General CSS */
.stage-dot { display: inline-block; width: 14px; height: 14px; border-radius: 50%; box-shadow: 0 0 4px rgba(0,0,0,0.3); flex-shrink: 0; }
.legend-container { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-md); padding: 1rem; margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: center; }
.legend-item { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; color: var(--text-secondary); font-weight: 500; }

/* View Toggle Switch */
.view-toggle { display: inline-flex; background: var(--bg-secondary); border: 1px solid var(--border-glass); border-radius: 8px; padding: 0.25rem; }
.view-toggle-btn { background: transparent; color: var(--text-secondary); border: none; padding: 0.5rem 1rem; font-weight: 600; font-size: 0.9rem; border-radius: 6px; cursor: pointer; transition: all 0.2s ease; }
.view-toggle-btn.active { background: var(--primary-color); color: white; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }

/* Table View Styling */
.dashboard-wrapper { position: relative; width: 100%; max-height: calc(100vh - 200px); overflow-x: auto; overflow-y: auto; background: var(--bg-card); border-radius: var(--radius-md); border: 1px solid var(--border-glass); box-shadow: var(--shadow-sm); }
.dashboard-wrapper table { width: max-content; min-width: 100%; table-layout: auto; border-collapse: separate; border-spacing: 0; }
.dashboard-wrapper th { position: sticky; top: 0; background: #1e1e2d; z-index: 10; padding: 1rem 0.75rem; vertical-align: middle; border-bottom: 2px solid var(--border-glass); white-space: nowrap; }
.dashboard-wrapper td { padding: 1rem 0.75rem; vertical-align: top; word-break: normal; border-bottom: 1px solid var(--border-glass); }
.dashboard-wrapper thead th:last-child { position: sticky; right: 0; z-index: 20; border-left: 2px solid var(--border-glass); }
.dashboard-wrapper tbody td:last-child { position: sticky; right: 0; background: #1e1e2d; z-index: 5; border-left: 2px solid var(--border-glass); }
.dashboard-wrapper tbody tr:hover td { background: rgba(255,255,255,0.03); }
.dashboard-wrapper tbody tr:hover td:last-child { background: #2a2a3b; }
.nowrap-cell { white-space: nowrap; } .min-w-150 { min-width: 150px; }
.cell-list-item { display: block; margin-bottom: 0.5rem; min-height: 1.2rem; line-height: 1.3; } .cell-list-item:last-child { margin-bottom: 0; }
.action-buttons-wrapper { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-start; max-width: 220px; }
.action-buttons-wrapper .btn-sm { margin: 0; padding: 0.35rem 0.6rem; font-size: 0.75rem; flex: 0 0 auto; text-align: center; white-space: nowrap; }

/* Map View Styling */
.map-container { position: relative; height: calc(100vh - 200px); min-height: 500px; width: 100%; background: #1a1a24; border-radius: var(--radius-md); border: 1px solid var(--border-glass); box-shadow: var(--shadow-sm); overflow: hidden; }
#projectMap { height: 100%; width: 100%; z-index: 1; }
.leaflet-popup-content-wrapper { background: var(--bg-card); color: var(--text-primary); border-radius: 8px; border: 1px solid var(--border-glass); box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
.leaflet-popup-tip { background: var(--bg-card); border: 1px solid var(--border-glass); }
.popup-title { font-size: 1.1rem; font-weight: bold; color: var(--primary-color); margin-bottom: 0.25rem; }
.popup-meta { font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.75rem; }
.popup-btn { display: block; box-sizing: border-box; background: var(--primary-color); color: #ffffff !important; padding: 0.6rem 1rem; margin-top: 0.75rem; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 0.85rem; text-align: center; width: 100%; transition: all 0.2s ease; border: 1px solid var(--primary-color); box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
.popup-btn:hover { background: transparent; color: var(--primary-color) !important; box-shadow: 0 4px 8px rgba(0,0,0,0.3); transform: translateY(-2px); }
.custom-pin { display: flex; align-items: center; justify-content: center; }
.custom-pin-inner { width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.6); }
.map-legend { position: absolute; bottom: 30px; left: 15px; z-index: 999; background: rgba(30, 30, 45, 0.9); backdrop-filter: blur(5px); border: 1px solid var(--border-glass); border-radius: 8px; padding: 1rem; box-shadow: 0 4px 15px rgba(0,0,0,0.5); max-height: 300px; overflow-y: auto; min-width: 200px; }
.legend-title { font-weight: bold; font-size: 0.85rem; color: var(--text-primary); margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.25rem; }
.legend-item { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.4rem; font-size: 0.85rem; color: var(--text-secondary); }
.legend-color { width: 14px; height: 14px; border-radius: 50%; border: 2px solid white; box-shadow: 0 1px 3px rgba(0,0,0,0.4); }
</style>

<div class="main-container">
    <h1 class="page-title"><?= htmlspecialchars($dashboardType) ?></h1>

    <?php if ($dashboardType === 'None'): ?>
        <div class="empty-state card"><h2 style="margin-bottom: 1rem; color: var(--primary-color);">Welcome to Estate Hub</h2><p>Please use the navigation menu above to access your specific modules.</p></div>
    <?php else: ?>
        
        <div class="stats-grid">
            <?php if ($dashboardType === 'Admin Dashboard'): ?>
                <div class="stat-card"><div class="stat-number"><?= $projectCount ?></div><div class="stat-label">Active Projects</div></div>
                <div class="stat-card"><div class="stat-number" style="color: <?= $stageColors['Permit'] ?>;"><?= $preExecCount ?></div><div class="stat-label">Pre-Execution</div></div>
                <div class="stat-card"><div class="stat-number" style="color: <?= $stageColors['Construction'] ?>;"><?= $execCount ?></div><div class="stat-label">In Execution</div></div>
                <div class="stat-card"><div class="stat-number" style="color: <?= $stageColors['Handed Over'] ?>;"><?= $finalCount ?></div><div class="stat-label">Finalizing</div></div>
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
            <div class="projects-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h2 class="section-title" style="margin: 0;">Projects Portfolio</h2>
                
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <div class="view-toggle">
                        <button type="button" class="view-toggle-btn <?= $currentView === 'table' ? 'active' : '' ?>" onclick="switchView('table')">📊 Table</button>
                        <button type="button" class="view-toggle-btn <?= $currentView === 'map' ? 'active' : '' ?>" onclick="switchView('map')">🗺️ Map</button>
                    </div>
                    <?php if (hasPermission('add_project')): ?>
                        <a href="create-project.php" class="btn">Add Project</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="filters-section">
                <form method="GET" id="dashboardFilters">
                    <input type="hidden" name="view" id="viewStateInput" value="<?= htmlspecialchars($currentView) ?>">
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
                                <option value="in-house" <?= $filterType === 'in-house' ? 'selected' : '' ?>>In-House</option>
                                <option value="3rd-party" <?= $filterType === '3rd-party' ? 'selected' : '' ?>>Capital Project</option>
                            </select>
                        </div>
                        <div class="filter-group"><label>Client</label>
                            <select name="filter_client">
                                <option value="all">All Clients</option>
                                
                                <optgroup label="Umbrella Groups">
                                    <option value="group_excel" <?= $filterClient === 'group_excel' ? 'selected' : '' ?>>🏢 Excel Group (All)</option>
                                    <option value="group_blue_clay" <?= $filterClient === 'group_blue_clay' ? 'selected' : '' ?>>🏢 Blue Clay Collection (All)</option>
                                </optgroup>
                                
                                <optgroup label="Individual Clients">
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['id'] ?>" <?= $filterClient == $client['id'] ? 'selected' : '' ?>><?= htmlspecialchars($client['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
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
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>"><input type="hidden" name="order" value="<?= htmlspecialchars($sortOrder) ?>">
                    <div class="filter-buttons"><button type="submit" class="btn">Apply Filters</button><a href="dashboard.php" class="reset-btn">Reset</a></div>
                </form>
            </div>

            <div class="legend-container">
                <?php foreach ($legendItems as $name): ?>
                    <?php $num = $stageEnum[$name] ?? '-'; ?>
                    <div class="legend-item" title="<?= $num !== '-' ? "Stage $num" : $name ?>"><span class="stage-dot" style="background-color: <?= $stageColors[$name] ?>;"></span><?= $name ?></div>
                <?php endforeach; ?>
            </div>

            <?php if ($projectCount > 0): ?>
            
            <div id="tableView" style="display: <?= $currentView === 'table' ? 'block' : 'none' ?>;">
                <?php $isSalesDb = ($dashboardType === 'Sales Dashboard'); ?>
                <div class="dashboard-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 50px; text-align: center;"><a href="<?= getSortUrl('stage') ?>" class="sortable-header" style="color: inherit;">Stage<?= getSortIndicator('stage') ?></a></th>
                                <th class="min-w-150"><a href="<?= getSortUrl('name') ?>" class="sortable-header" style="color: inherit;">Project Name<?= getSortIndicator('name') ?></a></th>
                                <th class="min-w-150"><a href="<?= getSortUrl('client') ?>" class="sortable-header" style="color: inherit;">Client<?= getSortIndicator('client') ?></a></th>
                                <th class="nowrap-cell"><a href="<?= getSortUrl('type') ?>" class="sortable-header" style="color: inherit;">Type<?= getSortIndicator('type') ?></a></th>
                                <th class="nowrap-cell"><a href="<?= getSortUrl('city') ?>" class="sortable-header" style="color: inherit;">City<?= getSortIndicator('city') ?></a></th>
                                <th class="nowrap-cell"><a href="<?= getSortUrl('finish_level') ?>" class="sortable-header" style="color: inherit;">Finish Level<?= getSortIndicator('finish_level') ?></a></th>
                                <?php if (!$isSalesDb): ?><th class="nowrap-cell">PA Number</th><th class="min-w-150">Architect</th><th class="min-w-150">Structural Engineer</th><?php endif; ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $project): ?>
                                <tr style="<?= in_array($project['project_status'] ?? '', ['Withdrawn', 'On-Hold']) ? 'opacity: 0.6;' : '' ?>">
                                    <td style="text-align: center;"><span class="stage-dot" style="background-color: <?= $stageColors[$project['stage']] ?>;" title="<?= $project['stage'] ?>"></span></td>
                                    <td style="font-weight: 600; color: var(--text-primary);"><div style="display: flex; align-items: center;"><?= htmlspecialchars($project['name']) ?><?php if (!empty($project['summer_break_flag']) && $project['summer_break_flag'] == 1): ?><span class="summer-break-icon" title="Summer Break Alarm Active">☀️</span><?php endif; ?></div></td>
                                    <td><?= htmlspecialchars($project['client_name'] ?? 'N/A') ?></td>
                                    <td class="nowrap-cell">
                                        <?php if ($project['type'] === 'in-house'): ?><span style="color: var(--primary-color); font-weight: 500;">In-House</span>
                                        <?php elseif ($project['type'] === '3rd-party'): ?><span style="color: #0ea5e9; font-weight: 500;">Capital Project</span>
                                        <?php else: ?><?= htmlspecialchars($project['type']) ?><?php endif; ?>
                                    </td>
                                    <td class="nowrap-cell"><?= htmlspecialchars($project['city']) ?></td>
                                    <td class="nowrap-cell"><?= htmlspecialchars($project['finishlevel'] ?? 'N/A') ?></td>

                                    <?php $projectPAs = $paByProject[$project['id']] ?? []; if (!$isSalesDb): ?>
                                        <td class="nowrap-cell">
                                            <?php if (!empty($projectPAs)): foreach ($projectPAs as $pa): ?>
                                                <div class="cell-list-item">
                                                    <?php 
                                                    $paText = htmlspecialchars(formatPANumber($pa['pa_number'])); 
                                                    $paUrl = buildPaUrl($pa['pa_number']); 
                                                    // FIX: Display PA Status Badge
                                                    $statusText = !empty($pa['pa_status']) ? ' <span style="font-size:0.75rem; color:var(--text-muted);">[' . htmlspecialchars($pa['pa_status']) . ']</span>' : '';
                                                    if ($paUrl): ?>
                                                        <a href="<?= htmlspecialchars($paUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $paText ?></a><?= $statusText ?>
                                                    <?php else: ?>
                                                        <?= $paText ?><?= $statusText ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; else: ?><span style="color: var(--text-muted)">TBC</span><?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <?php 
                                            $uniqueArchs = array_unique(array_filter(array_column($projectPAs, 'architect_name')));
                                            if (!empty($uniqueArchs)): foreach ($uniqueArchs as $arch): ?>
                                                <div class="cell-list-item"><?= htmlspecialchars($arch) ?></div>
                                            <?php endforeach; else: ?><span style="color: var(--text-muted)">TBC</span><?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <?php 
                                            $uniqueEngs = array_unique(array_filter(array_column($projectPAs, 'structural_engineer_name')));
                                            if (!empty($uniqueEngs)): foreach ($uniqueEngs as $eng): ?>
                                                <div class="cell-list-item"><?= htmlspecialchars($eng) ?></div>
                                            <?php endforeach; else: ?><span style="color: var(--text-muted)">TBC</span><?php endif; ?>
                                        </td>
                                    <?php endif; ?>

                                    <td>
                                        <div class="action-buttons-wrapper">
                                            <?php if (hasPermission('view_mobilisation') || $isAdmin): ?><a href="mobilisation_detail.php?project_id=<?= $project['id'] ?>" class="btn btn-sm btn-primary"><?= canUpdateStatus($pdo, $project['id']) ? 'Execution' : 'View Hub' ?></a><?php endif; ?>
                                            <?php if (hasPermission('view_property_sales') || $isAdmin): ?><a href="property_sales.php?project_id=<?= $project['id'] ?>" class="btn btn-sm" style="background: #10B981; color: white; border: none;">Sales</a><?php endif; ?>
                                            <?php if ((hasPermission('view_capital_projects') || $isAdmin) && $project['type'] === '3rd-party'): ?><a href="capital_projects.php?project_id=<?= $project['id'] ?>" class="btn btn-sm" style="background: #0ea5e9; color: white; border: none;">Capital</a><?php endif; ?>
                                            <?php if (canEditProjectDetails($pdo, $project['id'])): ?><a href="edit-project.php?id=<?= $project['id'] ?>" class="btn btn-sm btn-secondary">Edit</a><?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="mapView" style="display: <?= $currentView === 'map' ? 'block' : 'none' ?>;">
                <div class="map-container">
                    <div id="projectMap"></div>
                    <div class="map-legend" id="mapLegend" style="display: none;">
                        <div class="legend-title">Developers / Clients</div>
                        <div id="legendContent"></div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <div class="empty-state"><p>No projects match your current filters or assigned access limits.</p></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// --- Island Filter Logic ---
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('dashboardFilters');
    if (!form) return;
    const maltaCheckbox = document.getElementById('island_malta');
    const gozoCheckbox = document.getElementById('island_gozo');
    function validateIslands(e) { if (!maltaCheckbox.checked && !gozoCheckbox.checked) { e.preventDefault(); this.checked = true; alert('At least one island must be selected'); } }
    if(maltaCheckbox) maltaCheckbox.addEventListener('change', validateIslands);
    if(gozoCheckbox) gozoCheckbox.addEventListener('change', validateIslands);
    form.addEventListener('submit', function(e) {
        const existingInput = form.querySelector('input[name="filter_island"]');
        if (existingInput) existingInput.remove();
        let filterValue = 'all';
        if (maltaCheckbox && maltaCheckbox.checked && (!gozoCheckbox || !gozoCheckbox.checked)) filterValue = 'Malta';
        else if (gozoCheckbox && gozoCheckbox.checked && (!maltaCheckbox || !maltaCheckbox.checked)) filterValue = 'Gozo';
        const input = document.createElement('input'); input.type = 'hidden'; input.name = 'filter_island'; input.value = filterValue;
        form.appendChild(input);
    });
});

// --- View Toggle Logic ---
let mapInitialized = false;

function switchView(view) {
    document.getElementById('viewStateInput').value = view;
    document.querySelectorAll('.view-toggle-btn').forEach(btn => btn.classList.remove('active'));
    
    if (view === 'map') {
        document.querySelector('.view-toggle-btn[onclick="switchView(\'map\')"]').classList.add('active');
        document.getElementById('tableView').style.display = 'none';
        document.getElementById('mapView').style.display = 'block';
        if (!mapInitialized && typeof initMap === 'function') {
            initMap(); 
            mapInitialized = true;
        } else if (mapInitialized && window.map) {
            window.map.invalidateSize(); // Critical fix for Leaflet loading inside hidden divs
        }
    } else {
        document.querySelector('.view-toggle-btn[onclick="switchView(\'table\')"]').classList.add('active');
        document.getElementById('mapView').style.display = 'none';
        document.getElementById('tableView').style.display = 'block';
    }
}

// --- Map Logic ---
const projectsData = <?= json_encode($projects ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const localityCoords = { "Attard": [35.8914, 14.4431], "Balzan": [35.8983, 14.4533], "Birkirkara": [35.8972, 14.4611], "Birżebbuġa": [35.8258, 14.5269], "Bormla (Cospicua)": [35.8814, 14.5219], "Dingli": [35.8961, 14.4000], "Fgura": [35.8711, 14.5161], "Floriana": [35.8925, 14.5031], "Għargħur": [35.9031, 14.4525], "Gżira": [35.9228, 14.4650], "Ħamrun": [35.8847, 14.4844], "Iklin": [35.9081, 14.4542], "Isla (Senglea)": [35.8872, 14.5169], "Kalkara": [35.8889, 14.5222], "Kirkop": [35.9042, 14.4608], "Lija": [35.9008, 14.4464], "Luqa": [35.8436, 14.4883], "Marsa": [35.8672, 14.4947], "Marsaskala": [35.8272, 14.5447], "Marsaxlokk": [35.8617, 14.5683], "Mdina": [35.8833, 14.4022], "Mellieħa": [35.9564, 14.3631], "Mġarr": [35.9214, 14.4467], "Mosta": [35.9014, 14.4256], "Mqabba": [35.8425, 14.4756], "Msida": [35.9022, 14.4889], "Mtarfa": [35.8906, 14.3986], "Naxxar": [35.9133, 14.4444], "Paola": [35.8728, 14.5081], "Pembroke": [35.9325, 14.4853], "Pietà": [35.8933, 14.4939], "Qormi": [35.8789, 14.4694], "Qrendi": [35.8372, 14.4586], "Rabat": [35.8817, 14.3989], "Safi": [35.8331, 14.4850], "San Ġiljan (St. Julian's)": [35.9184, 14.4885], "San Ġwann": [35.9094, 14.4775], "San Pawl il-Baħar": [35.9483, 14.4014], "Santa Luċija": [35.8239, 14.4944], "Santa Venera": [35.8683, 14.4775], "Siġġiewi": [35.8336, 14.4372], "Sliema": [35.9122, 14.5042], "Swieqi": [35.9222, 14.4789], "Ta' Xbiex": [35.8992, 14.4936], "Tarxien": [35.8653, 14.5125], "Valletta": [35.8989, 14.5146], "Xgħajra": [35.8864, 14.5317], "Żabbar": [35.8678, 14.5367], "Żebbuġ": [35.8722, 14.4431], "Żejtun": [35.8683, 14.5333], "Żurrieq": [35.8306, 14.4744], "Fontana": [36.0353, 14.2383], "Għajnsielem": [36.0275, 14.2886], "Għarb": [36.0403, 14.2017], "Għasri": [36.0583, 14.2153], "Kerċem": [36.0522, 14.2253], "Munxar": [36.0306, 14.2333], "Nadur": [36.0378, 14.2944], "Qala": [36.0392, 14.3083], "San Lawrenz": [36.0544, 14.2044], "Sannat": [36.0244, 14.2436], "Victoria (Rabat)": [36.0436, 14.2361], "Xagħra": [36.05, 14.2667], "Xewkija": [36.0322, 14.2583], "Żebbuġ (Gozo)": [36.0717, 14.2369] };

const colorPalette = ['#ef4444', '#3b82f6', '#10b981', '#f59e0b', '#a855f7', '#ec4899', '#06b6d4', '#f97316', '#8b5cf6', '#14b8a6'];
let clientColors = {}; let colorIndex = 0;
function getClientColor(cName) { let n = cName ? cName.trim() : 'In-House (Internal)'; if (!clientColors[n]) { clientColors[n] = colorPalette[colorIndex % colorPalette.length]; colorIndex++; } return clientColors[n]; }

function initMap() {
    window.map = L.map('projectMap').setView([35.91, 14.4], 11);
    
    const darkMap = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { attribution: '&copy; OpenStreetMap', subdomains: 'abcd', maxZoom: 19 });
    const satMap = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: 'Tiles &copy; Esri', maxZoom: 19 });
    
    satMap.addTo(window.map); // Satellite default
    L.control.layers({ "Satellite View": satMap, "Dark Street View": darkMap }, null, { position: 'topright' }).addTo(window.map);

    let markersGroup = L.markerClusterGroup({ maxClusterRadius: 35, spiderfyOnMaxZoom: true, showCoverageOnHover: false, zoomToBoundsOnClick: true });
    let visibleClients = new Set();

    projectsData.forEach(p => {
        let coords;
        if (p.latitude && p.longitude && p.latitude !== '' && p.longitude !== '') {
            coords = [parseFloat(p.latitude), parseFloat(p.longitude)];
        } else {
            coords = localityCoords[p.city] || [35.91, 14.4];
        }

        const clientName = p.client_name || 'In-House (Internal)';
        const pinColor = getClientColor(clientName);
        visibleClients.add(clientName);

        const customIcon = L.divIcon({ className: 'custom-pin', html: `<div class="custom-pin-inner" style="background-color: ${pinColor};"></div>`, iconSize: [26, 26], iconAnchor: [13, 13] });
        const marker = L.marker(coords, { icon: customIcon });
        
        const popupContent = `
            <div style="min-width: 200px;">
                <div class="popup-title">${p.name}</div>
                <div class="popup-meta">
                    <strong>Developer:</strong> <span style="color: ${pinColor}; font-weight: bold;">${clientName}</span><br>
                    <strong>Location:</strong> ${p.city}<br>
                    <strong>Stage:</strong> <span style="color: #fff;">${p.stage}</span><br>
                    <span style="font-size: 0.75rem; color: #6b7280; font-style: italic;">${(p.latitude && p.longitude) ? '📍 Exact Coordinates' : '📍 Locality Approximation'}</span>
                </div>
                <a href="mobilisation_detail.php?project_id=${p.id}" class="popup-btn">Open Project Dashboard</a>
            </div>
        `;
        marker.bindPopup(popupContent);
        markersGroup.addLayer(marker);
    });

    window.map.addLayer(markersGroup);

    const legendContainer = document.getElementById('mapLegend');
    const legendContent = document.getElementById('legendContent');
    legendContent.innerHTML = '';
    
    if (visibleClients.size > 0) {
        legendContainer.style.display = 'block';
        Array.from(visibleClients).sort().forEach(client => {
            legendContent.innerHTML += `<div class="legend-item"><div class="legend-color" style="background-color: ${getClientColor(client)};"></div><span>${client}</span></div>`;
        });
    }
}

// Auto-trigger map init if we loaded on map view (due to filter refresh)
if ('<?= $currentView ?>' === 'map') {
    initMap();
    mapInitialized = true;
}
</script>

<?php require_once 'footer.php'; ?>
