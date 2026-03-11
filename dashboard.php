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
        $visibleStages = array_merge($visibleStages, ['Demolition', 'Excavation', 'Construction', 'Finishes']); break;
    case 'architect':
    case 'structural_engineer':
    case 'services_engineer':
        $visibleStages = array_merge($visibleStages, ['Permit', 'Mobilisation', 'Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance']); break;
    case 'site_technical_officer':
        $visibleStages = array_merge($visibleStages, ['Mobilisation', 'Demolition', 'Excavation', 'Construction']); break;
    case 'accountant':
    case 'project_manager':
    case 'pmo_staff':
    case 'admin':
    case 'director':
    case 'system_manager':
        $visibleStages = ['Feasibility', 'Tracking', 'Permit', 'Mobilisation', 'Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over']; break;
}

$stageEnum = ['Feasibility'=>1, 'Tracking'=>2, 'Permit'=>3, 'Mobilisation'=>4, 'Demolition'=>5, 'Excavation'=>6, 'Construction'=>7, 'Finishes'=>8, 'Compliance'=>9, 'Condominium'=>10, 'Handed Over'=>11];
$stageColors = ['Feasibility'=>'#64748b', 'Tracking'=>'#f59e0b', 'Permit'=>'#8b5cf6', 'Mobilisation'=>'#3b82f6', 'Demolition'=>'#ef4444', 'Excavation'=>'#f97316', 'Construction'=>'#eab308', 'Finishes'=>'#22c55e', 'Compliance'=>'#14b8a6', 'Condominium'=>'#06b6d4', 'Handed Over'=>'#10b981'];
$legendItems = ['Feasibility', 'Tracking', 'Permit', 'Mobilisation', 'Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over'];

// Process Filters
$filterType = $_GET['filter_type'] ?? 'all';
$filterCity = $_GET['filter_city'] ?? 'all';
$filterClient = $_GET['filter_client'] ?? 'all';
$filterIsland = $_GET['filter_island'] ?? 'all';
$filterStatus = $_GET['filter_status'] ?? 'all';
$filterDbStatus = $_GET['filter_db_status'] ?? 'Active'; // Active, On-Hold, Completed, Withdrawn, All
$currentView = $_GET['view'] ?? 'table';
$sortBy = $_GET['sort'] ?? 'name';
$sortOrder = $_GET['order'] ?? 'ASC';

// Allow sorting via array mapping
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
        $paStmt = $pdo->prepare("SELECT pan.project_id, pan.pa_number, pan.pa_status, arch.name AS architect_name, se.name AS structural_engineer_name FROM project_pa_numbers pan LEFT JOIN professionals arch ON arch.id = pan.architect_id LEFT JOIN professionals se ON se.id = pan.structural_engineer_id WHERE pan.project_id IN ($placeholders)");
        $paStmt->execute($projectIds);
        foreach ($paStmt->fetchAll(PDO::FETCH_ASSOC) as $pa) $paByProject[$pa['project_id']][] = $pa;
    }
} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

// Client filtering and tracking
$preExecCount = 0; $execCount = 0; $finalCount = 0;
$companyKpis = [];

$filteredProjects = [];
foreach ($projects as $project) {
    $project['stage'] = deriveProjectStage($pdo, $project['id']);
    $stageNum = $stageEnum[$project['stage']] ?? 1;

    // Apply DB Status filter FIRST
    if ($filterDbStatus !== 'All') {
        $pStatus = $project['project_status'] ?? 'Active';
        if ($pStatus === '') $pStatus = 'Active'; // Normalize empty to active
        if ($pStatus !== $filterDbStatus) continue;
    }

    if (empty($visibleStages) || in_array($project['stage'], $visibleStages) || $isAdmin || hasPermission('view_all_projects')) {
        
        // Filter out based on GET params
        if ($filterType !== 'all' && $project['type'] !== $filterType) continue;
        if ($filterCity !== 'all' && $project['city'] !== $filterCity) continue;
        if ($filterIsland !== 'all' && $project['island'] !== $filterIsland) continue;
        if ($filterStatus !== 'all' && $project['stage'] !== $filterStatus) continue;
        
        if ($filterClient !== 'all') {
            if ($filterClient === 'group_excel') {
                if (stripos($project['client_name'] ?? '', 'Excel') === false) continue;
            } elseif ($filterClient === 'group_blue_clay') {
                if (stripos($project['client_name'] ?? '', 'Blue Clay') === false && stripos($project['client_name'] ?? '', 'Blueclay') === false) continue;
            } elseif ($project['clientid'] != $filterClient) {
                continue;
            }
        }

        $filteredProjects[] = $project;
        if ($stageNum >= 9) $finalCount++; elseif ($stageNum >= 5) $execCount++; else $preExecCount++;
        if ($dashboardType === 'Company Dashboard') {
            $cName = $project['client_name'] ?? 'Unassigned';
            if (!isset($companyKpis[$cName])) $companyKpis[$cName] = ['total'=>0, 'pre'=>0, 'exec'=>0, 'final'=>0];
            $companyKpis[$cName]['total']++;
            if ($stageNum >= 9) $companyKpis[$cName]['final']++; elseif ($stageNum >= 5) $companyKpis[$cName]['exec']++; else $companyKpis[$cName]['pre']++;
        }
    }
}

$projects = $filteredProjects;
$projectCount = count($projects);
$userCount = $isAdmin ? $pdo->query("SELECT COUNT(*) FROM users WHERE is_active='Yes'")->fetchColumn() : 0;

usort($projects, function($a, $b) use ($sortBy, $sortOrder, $stageEnum) {
    $valA = ''; $valB = '';
    if ($sortBy === 'name') { $valA = strtolower($a['name']); $valB = strtolower($b['name']); }
    elseif ($sortBy === 'client') { $valA = strtolower($a['client_name'] ?? 'z'); $valB = strtolower($b['client_name'] ?? 'z'); }
    elseif ($sortBy === 'city') { $valA = strtolower($a['city']); $valB = strtolower($b['city']); }
    elseif ($sortBy === 'type') { $valA = strtolower($a['type']); $valB = strtolower($b['type']); }
    elseif ($sortBy === 'finish_level') { $valA = strtolower($a['finishlevel'] ?? ''); $valB = strtolower($b['finishlevel'] ?? ''); }
    elseif ($sortBy === 'stage') { 
        $valA = $stageEnum[$a['stage']] ?? 0; $valB = $stageEnum[$b['stage']] ?? 0; 
        return $sortOrder === 'ASC' ? $valA <=> $valB : $valB <=> $valA;
    }
    if ($valA == $valB) return 0;
    $cmp = ($valA < $valB) ? -1 : 1;
    return $sortOrder === 'ASC' ? $cmp : -$cmp;
});

function getSortUrl($column) { global $sortBy, $sortOrder, $filterType, $filterCity, $filterClient, $filterIsland, $filterStatus, $filterDbStatus, $currentView; $newOrder = ($sortBy === $column && $sortOrder === 'ASC') ? 'DESC' : 'ASC'; return "?view=$currentView&filter_type=$filterType&filter_city=$filterCity&filter_client=$filterClient&filter_island=$filterIsland&filter_status=$filterStatus&filter_db_status=$filterDbStatus&sort=$column&order=$newOrder"; }
function getSortIndicator($column) { global $sortBy, $sortOrder; if ($sortBy === $column) return $sortOrder === 'ASC' ? ' ▲' : ' ▼'; return ''; }
if (!function_exists('buildPaUrl')) {
    function buildPaUrl(?string $paNumber): ?string { if (empty($paNumber)) return null; if (!preg_match('/(PA|PC|DN)\/(\d+)\/(\d+)/', $paNumber, $m)) return null; return "https://eapps.pa.org.mt/Case/CaseDetails?caseType={$m[1]}&casenumber={$m[2]}&caseYear={$m[3]}"; }
}

if (!function_exists('formatPANumber')) {
    function formatPANumber(?string $paNumber): ?string {
        if (empty($paNumber)) return null;
        if (preg_match('/(PA|PC|DN)\/(\d+)\/(\d+)/', $paNumber, $m)) {
            return "{$m[1]} {$m[2]}/{$m[3]}";
        }
        return $paNumber;
    }
}

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
/* Map Layout with Sidebar */
.map-layout { display: flex; height: calc(100vh - 200px); min-height: 500px; border-radius: var(--radius-md); border: 1px solid var(--border-glass); overflow: hidden; background: #1a1a24; }
.map-sidebar { width: 300px; background: var(--bg-card); display: flex; flex-direction: column; border-right: 1px solid var(--border-glass); z-index: 10; }
.map-sidebar-list { flex: 1; overflow-y: auto; }
.map-list-item { padding: 12px 15px; border-bottom: 1px solid rgba(255,255,255,0.05); cursor: pointer; transition: 0.2s; }
.map-list-item:hover { background: rgba(14, 165, 233, 0.1); }
.map-list-item.active { background: rgba(14, 165, 233, 0.15); border-left: 3px solid var(--primary-color); padding-left: 12px; }
.map-item-title { font-weight: bold; color: var(--primary-color); font-size: 0.9rem; margin-bottom: 2px; }
.map-item-meta { font-size: 0.75rem; color: var(--text-secondary); display: flex; justify-content: space-between; }
.map-container { flex: 1; position: relative; height: 100%; border: none; border-radius: 0; }
#projectMap { height: 100%; width: 100%; z-index: 1; }

.leaflet-popup-content-wrapper { background: var(--bg-card); color: var(--text-primary); border-radius: 8px; border: 1px solid var(--border-glass); box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
.leaflet-popup-tip { background: var(--bg-card); border: 1px solid var(--border-glass); }
.popup-title { font-size: 1.1rem; font-weight: bold; color: var(--primary-color); margin-bottom: 0.25rem; }
.popup-meta { font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.75rem; }
.custom-pin { border-radius: 50%; border: 3px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.5); background: #fff; overflow: hidden; display: flex; justify-content: center; align-items: center; }
.custom-pin-inner { width: 100%; height: 100%; border-radius: 50%; }

/* Fixed Map Legend */
.map-legend { 
    position: absolute; bottom: 30px; left: 20px; z-index: 1000; 
    background: rgba(30, 30, 45, 0.9); backdrop-filter: blur(10px); 
    padding: 15px; border-radius: 8px; border: 1px solid var(--border-glass); 
    box-shadow: 0 4px 15px rgba(0,0,0,0.3); color: #fff; font-size: 0.8rem; 
    width: auto; min-width: 200px; max-width: 350px;
    max-height: 250px; overflow-y: auto; 
}
.legend-title { font-weight: bold; margin-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 4px; }
.legend-item-map { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 6px; }
.legend-color { width: 12px; height: 12px; border-radius: 50%; display: inline-block; flex-shrink: 0; }

/* Generic Iframe Modal (Widened for Execution Hub) */
#genericIframeModal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.7); backdrop-filter: blur(4px); }
.modal-wrapper { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 95%; max-width: 1400px; height: 95%; background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border-glass); display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); }
.modal-header { padding: 1rem 1.5rem; background: var(--bg-primary); border-bottom: 1px solid var(--border-glass); display: flex; justify-content: space-between; align-items: center; }
.modal-header h2 { margin: 0; font-size: 1.2rem; color: var(--primary-color); }
.modal-close { font-size: 1.5rem; cursor: pointer; color: var(--text-muted); line-height: 1; transition: color 0.2s; }
.modal-close:hover { color: #ef4444; }
#genericIframe { flex: 1; width: 100%; border: none; background: var(--bg-card); }

</style>

<div class="main-container">
    <h1 class="page-title"><?= htmlspecialchars($dashboardType) ?></h1>

    <?php if ($dashboardType === 'None'): ?>
        <div class="empty-state card">
            <h2 style="margin-bottom: 1rem; color: var(--primary-color);">Welcome to Estate Hub</h2>
            <p>Please use the navigation menu above to access your specific modules.</p>
        </div>
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
                        <div class="filter-group">
                            <label>Locality</label>
                            <select name="filter_city">
                                <option value="all">All Localities</option>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?= htmlspecialchars($city) ?>" <?= $filterCity === $city ? 'selected' : '' ?>><?= htmlspecialchars($city) ?></option>
                                <?php endforeach; ?>
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
                                    <td style="text-align: center;"><span class="stage-dot" style="background-color: <?= $stageColors[$project['stage']] ?>;" title="<?= $project['stage'] ?>"></span></td>
                                    <td style="font-weight: 600;">
                                        <a href="project-status.php?id=<?= $project['id'] ?>" style="color: var(--text-primary); text-decoration: none; display: flex; align-items: center; transition: color 0.2s;" onmouseover="this.style.color='var(--primary-color)'" onmouseout="this.style.color='var(--text-primary)'" title="View Project Snapshot">
                                            <?= htmlspecialchars($project['name']) ?>
                                            <?php if (!empty($project['summer_break_flag']) && $project['summer_break_flag'] == 1): ?>
                                                <span class="summer-break-icon" style="margin-left: 0.5rem;" title="Summer Break Alarm Active">☀️</span>
                                            <?php endif; ?>
                                        </a>
                                    </td>
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
                                            <?php if (hasPermission('view_mobilisation') || $isAdmin): ?>
                                                <button type="button" onclick="openExecutionModal(<?= $project['id'] ?>, '<?= htmlspecialchars(addslashes($project['name']), ENT_QUOTES) ?>')" class="btn btn-sm btn-primary" style="cursor: pointer;"><?= canUpdateStatus($pdo, $project['id']) ? 'Execution' : 'View Hub' ?></button>
                                            <?php endif; ?>
                                            <?php if (hasPermission('view_property_sales') || $isAdmin): ?><a href="property_sales.php?project_id=<?= $project['id'] ?>" class="btn btn-sm" style="background: #10B981; color: white; border: none;">Sales</a><?php endif; ?>
                                            <?php if ((hasPermission('view_capital_projects') || $isAdmin) && $project['type'] === '3rd-party'): ?><a href="capital_projects.php?project_id=<?= $project['id'] ?>" class="btn btn-sm" style="background: #0ea5e9; color: white; border: none;">Capital</a><?php endif; ?>
                                            
                                            <?php if (canEditProjectDetails($pdo, $project['id'])): ?>
                                                <button type="button" onclick="openEditModal(<?= $project['id'] ?>, '<?= htmlspecialchars(addslashes($project['name']), ENT_QUOTES) ?>')" class="btn btn-sm btn-secondary" style="cursor: pointer;">Edit</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="mapView" style="display: <?= $currentView === 'map' ? 'block' : 'none' ?>;">
                <div class="map-layout">
                    <div class="map-sidebar">
                        <div style="padding: 1rem; border-bottom: 1px solid var(--border-glass);">
                            <h3 style="margin: 0; color: #fff; font-size: 1rem;">Map Locations</h3>
                            <p style="margin: 4px 0 0 0; font-size: 0.75rem; color: var(--text-muted);"><span id="mapProjCount">0</span> projects shown</p>
                            <input type="text" id="mapSearchInput" placeholder="Search map list..." style="width: 100%; margin-top: 10px; padding: 6px 10px; border-radius: 4px; border: 1px solid var(--border-glass); background: #1e1e2d; color: #fff; font-size: 0.8rem; box-sizing: border-box;">
                        </div>
                        <div class="map-sidebar-list" id="mapSidebarList">
                            </div>
                    </div>
                    
                    <div class="map-container">
                        <div id="projectMap"></div>
                        <div class="map-legend" id="mapLegend" style="display: none;">
                            <div class="legend-title">Developers / Clients</div>
                            <div id="legendContent"></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <div class="empty-state"><p>No projects match your current filters or assigned access limits.</p></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div id="genericIframeModal">
    <div class="modal-wrapper">
        <div class="modal-header">
            <h2 id="genericModalTitle">Dashboard Viewer</h2>
            <span class="modal-close" onclick="closeGenericModal()">&times;</span>
        </div>
        <iframe id="genericIframe" src=""></iframe>
    </div>
</div>

<script>
// --- Iframe Modal Logic ---
function openEditModal(projectId, projectName) {
    document.getElementById('genericModalTitle').innerText = 'Edit Project: ' + projectName;
    document.getElementById('genericIframe').src = 'edit-project.php?id=' + projectId + '&modal=1';
    document.getElementById('genericIframeModal').style.display = 'block';
}

function openExecutionModal(projectId, projectName) {
    document.getElementById('genericModalTitle').innerText = 'Execution Hub: ' + projectName;
    document.getElementById('genericIframe').src = 'mobilisation_detail.php?project_id=' + projectId + '&modal=1';
    document.getElementById('genericIframeModal').style.display = 'block';
}

function closeGenericModal() {
    document.getElementById('genericIframeModal').style.display = 'none';
    document.getElementById('genericIframe').src = '';
}

// When dashboard loads, immediately jump back to exact scroll position if saved
document.addEventListener("DOMContentLoaded", function() {
    let savedScroll = sessionStorage.getItem('dashboard_scrollpos');
    if (savedScroll) {
        let wrapper = document.querySelector('.dashboard-wrapper');
        if (wrapper) wrapper.scrollTop = savedScroll;
        sessionStorage.removeItem('dashboard_scrollpos');
    }
});

// Listen for messages from the iframe (Success Save or Invalid Project)
window.addEventListener('message', function(event) {
    if (event.data === 'projectUpdated') {
        let wrapper = document.querySelector('.dashboard-wrapper');
        if (wrapper) sessionStorage.setItem('dashboard_scrollpos', wrapper.scrollTop);
        window.location.reload();
    } else if (event.data === 'closeModal') {
        closeGenericModal();
    }
});


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
            window.map.invalidateSize(); 
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
    
    const darkMap = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { attribution: '© OpenStreetMap', subdomains: 'abcd', maxZoom: 19 });
    const satMap = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: 'Tiles © Esri', maxZoom: 19 });
    
    satMap.addTo(window.map); // Satellite default
    L.control.layers({ "Satellite View": satMap, "Dark Street View": darkMap }, null, { position: 'topright' }).addTo(window.map);

    let markersGroup = L.markerClusterGroup({ maxClusterRadius: 35, spiderfyOnMaxZoom: true, showCoverageOnHover: false, zoomToBoundsOnClick: true });
    let visibleClients = new Set();

    projectsData.forEach(p => {
        let coords;
        let hasExactLocation = false;
        
        if (p.latitude && p.longitude && p.latitude !== '' && p.longitude !== '') {
            coords = [parseFloat(p.latitude), parseFloat(p.longitude)];
            hasExactLocation = true;
        } else {
            coords = localityCoords[p.city] || [35.91, 14.4];
        }

        const clientName = p.client_name || 'In-House (Internal)';
        const pinColor = getClientColor(clientName);
        visibleClients.add(clientName);

        const customIcon = L.divIcon({ className: 'custom-pin', html: `<div class="custom-pin-inner" style="background-color: ${pinColor};"></div>`, iconSize: [26, 26], iconAnchor: [13, 13] });
        const marker = L.marker(coords, { icon: customIcon });
        
        let mapLinksHtml = '';
        if (hasExactLocation) {
            const googleMapsUrl = `https://maps.google.com/?q=${coords[0]},${coords[1]}`;
            mapLinksHtml = `
                <div style="display: flex; gap: 0.4rem; margin-top: 0.5rem;">
                    <a href="${googleMapsUrl}" target="_blank" style="flex-grow: 1; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.05); color: var(--text-primary); padding: 0.4rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; border: 1px solid var(--border-glass);">🗺️ Google Maps</a>
                    
                    <button onclick="navigator.clipboard.writeText('${googleMapsUrl}'); this.innerText='✅ Copied'; setTimeout(()=>this.innerText='📋 Copy', 2000);" style="background: rgba(255,255,255,0.05); color: var(--text-primary); padding: 0.4rem 0.5rem; border-radius: 6px; border: 1px solid var(--border-glass); cursor: pointer; font-size: 0.75rem;" title="Copy Link">📋 Copy</button>
                </div>
            `;
        }
        
        const safeName = p.name.replace(/'/g, "\\'");

        const popupContent = `
            <div style="min-width: 220px;">
                <div class="popup-title">${p.name}</div>
                <div class="popup-meta">
                    <strong>Developer:</strong> <span style="color: ${pinColor}; font-weight: bold;">${clientName}</span><br>
                    <strong>Location:</strong> ${p.city}<br>
                    <strong>Stage:</strong> <span style="color: #fff;">${p.stage}</span><br>
                    <span style="font-size: 0.75rem; color: #6b7280; font-style: italic;">${hasExactLocation ? '📍 Exact Coordinates' : '📍 Locality Approximation'}</span>
                </div>
                
                ${mapLinksHtml}
                
                <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.75rem;">
                    <button onclick="openExecutionModal(${p.id}, '${safeName}')" style="display: block; box-sizing: border-box; background: var(--primary-color); color: #ffffff; padding: 0.6rem 1rem; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 0.85rem; text-align: center; border: 1px solid var(--primary-color); cursor: pointer; width: 100%;">⚙️ Open Execution Hub</button>
                </div>
            </div>
        `;

        marker.bindPopup(finalPopupContent);
        markersGroup.addLayer(marker);
        
        p.leafletMarker = marker;
    });

    const sidebarList = document.getElementById('mapSidebarList');
    if(sidebarList) {
        document.getElementById('mapProjCount').innerText = projectsData.length;
        
        function renderSidebar(data) {
            sidebarList.innerHTML = '';
            data.forEach(p => {
                const clientName = p.client_name || 'In-House (Internal)';
                const color = getClientColor(clientName);
                
                const div = document.createElement('div');
                div.className = 'map-list-item';
                div.innerHTML = `
                    <div class="map-item-title">${p.name}</div>
                    <div class="map-item-meta">
                        <span>📍 ${p.city}</span>
                        <span style="color: ${color}; font-weight: bold;">${clientName.substring(0,12)}</span>
                    </div>
                `;
                
                div.onclick = () => {
                    document.querySelectorAll('.map-list-item').forEach(el => el.classList.remove('active'));
                    div.classList.add('active');
                    if(window.map && p.leafletMarker) {
                        window.map.flyTo(p.leafletMarker.getLatLng(), 16, { duration: 1.5 });
                        markersGroup.zoomToShowLayer(p.leafletMarker, () => {
                            p.leafletMarker.openPopup();
                        });
                    }
                };
                sidebarList.appendChild(div);
            });
        }
        
        renderSidebar(projectsData);
        
        document.getElementById('mapSearchInput').addEventListener('input', function(e) {
            const term = e.target.value.toLowerCase();
            const filtered = projectsData.filter(p => 
                p.name.toLowerCase().includes(term) || 
                (p.city && p.city.toLowerCase().includes(term)) || 
                (p.client_name && p.client_name.toLowerCase().includes(term))
            );
            renderSidebar(filtered);
            document.getElementById('mapProjCount').innerText = filtered.length;
        });
    }

    window.map.addLayer(markersGroup);

    const legendContainer = document.getElementById('mapLegend');
    const legendContent = document.getElementById('legendContent');
    legendContent.innerHTML = '';
    
    if (visibleClients.size > 0) {
        legendContainer.style.display = 'block';
        Array.from(visibleClients).sort().forEach(client => {
            legendContent.innerHTML += `<div class="legend-item-map"><div class="legend-color" style="background-color: ${getClientColor(client)};"></div><span>${client}</span></div>`;
        });
    }
}

if ('<?= $currentView ?>' === 'map') {
    initMap();
    mapInitialized = true;
}
</script>

<?php require_once 'footer.php'; ?>
