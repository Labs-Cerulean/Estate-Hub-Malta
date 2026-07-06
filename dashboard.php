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

// Fetch Firms for the Professional Filter
$firms = getAllFirms($pdo); 

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
require_once __DIR__ . '/includes/pm_filter_logic.php';
$filterType = $_GET['filter_type'] ?? 'all';
$filterCity = $_GET['filter_city'] ?? 'all';
$filterClient = $_GET['filter_client'] ?? 'all';
$filterIsland = $_GET['filter_island'] ?? 'all';
$filterStatus = $_GET['filter_status'] ?? 'all';
$filterProf = $_GET['filter_prof'] ?? 'all'; 
$filterDbStatus = $_GET['filter_db_status'] ?? 'Active'; // Active, On-Hold, Completed, Withdrawn, All
$currentView = $_GET['view'] ?? 'table';
$groupMode = $_GET['group_mode'] ?? 'stage';
$allowedGroupModes = ['stage', 'client', 'perit', 'pa_review', 'flat'];
if (!in_array($groupMode, $allowedGroupModes, true)) $groupMode = 'stage';
$sortBy = $_GET['sort'] ?? 'stage';
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
    $profData = [];
    if (!empty($projectIds)) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $paStmt = $pdo->prepare("SELECT pan.project_id, pan.pa_number, pan.pa_status, arch.name AS architect_name, arch.firm_name AS arch_firm, se.name AS structural_engineer_name, se.firm_name AS struct_firm FROM project_pa_numbers pan LEFT JOIN professionals arch ON arch.id = pan.architect_id LEFT JOIN professionals se ON se.id = pan.structural_engineer_id WHERE pan.project_id IN ($placeholders)");
        $paStmt->execute($projectIds);
        foreach ($paStmt->fetchAll(PDO::FETCH_ASSOC) as $pa) {
            $paByProject[$pa['project_id']][] = $pa;
            if (!empty($pa['arch_firm'])) $profData[$pa['project_id']][] = $pa['arch_firm'];
            if (!empty($pa['struct_firm'])) $profData[$pa['project_id']][] = $pa['struct_firm'];
        }
    }
} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

// Define Macro Stages for Group Filtering
$preExecStages = ['Feasibility', 'Tracking', 'Permit', 'Mobilisation'];
$execStages = ['Demolition', 'Excavation', 'Construction', 'Finishes'];
$finalStages = ['Compliance', 'Condominium', 'Handed Over'];

// Client filtering and tracking
$preExecCount = 0; $execCount = 0; $finalCount = 0;
$companyKpis = [];

$filteredProjects = [];
$archiveProjects = [];
$showArchiveSection = ($filterDbStatus === 'Active');
$stageIds = array_column($projects, 'id');
$stagesBatch = getAccurateProjectStagesBatch($pdo, $stageIds);
foreach ($projects as $project) {
    $project['stage'] = $stagesBatch[$project['id']] ?? getAccurateProjectStage($pdo, $project['id']);
    $stageNum = $stageEnum[$project['stage']] ?? 1;
    $pStatus = $project['project_status'] ?? 'Active';
    if ($pStatus === '') $pStatus = 'Active';
    $isArchive = pmIsArchiveProject($project, $project['stage']);

    if ($isArchive && $showArchiveSection) {
        if (empty($visibleStages) || in_array($project['stage'], $visibleStages) || $isAdmin || hasPermission('view_all_projects')) {
            if (!pmMatchesStageFilter($project['stage'], 'all', $filterStatus)) continue;
            if ($filterType !== 'all' && $project['type'] !== $filterType) continue;
            if ($filterCity !== 'all' && $project['city'] !== $filterCity) continue;
            if ($filterIsland !== 'all' && $project['island'] !== $filterIsland) continue;
            if (!pmMatchesClientFilter($project, $filterClient)) continue;
            if ($filterProf !== 'all') {
                $frms = $profData[$project['id']] ?? [];
                if (!in_array($filterProf, $frms)) continue;
            }
            $archiveProjects[] = $project;
        }
        continue;
    }

    // Apply DB Status filter FIRST
    if ($filterDbStatus !== 'All') {
        if ($pStatus !== $filterDbStatus) continue;
    }

    if (empty($visibleStages) || in_array($project['stage'], $visibleStages) || $isAdmin || hasPermission('view_all_projects')) {
        
        // Stage Filter (Including Group Handlers)
        if (!pmMatchesStageFilter($project['stage'], 'all', $filterStatus)) continue;

        // Standard Filters
        if ($filterType !== 'all' && $project['type'] !== $filterType) continue;
        if ($filterCity !== 'all' && $project['city'] !== $filterCity) continue;
        if ($filterIsland !== 'all' && $project['island'] !== $filterIsland) continue;
        if (!pmMatchesClientFilter($project, $filterClient)) continue;

        // Professional Filter
        if ($filterProf !== 'all') {
            $frms = $profData[$project['id']] ?? [];
            if (!in_array($filterProf, $frms)) continue;
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
$archiveProjectCount = count($archiveProjects);
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

usort($archiveProjects, function($a, $b) use ($sortBy, $sortOrder, $stageEnum) {
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

$projectGroups = pmGroupProjects($projects, $groupMode, $paByProject, $stageEnum);

function getSortUrl($column) {
    global $sortBy, $sortOrder, $filterType, $filterCity, $filterClient, $filterIsland, $filterStatus, $filterDbStatus, $currentView, $filterProf, $groupMode;
    $newOrder = ($sortBy === $column && $sortOrder === 'ASC') ? 'DESC' : 'ASC';
    return '?' . http_build_query([
        'view' => $currentView,
        'group_mode' => $groupMode,
        'filter_type' => $filterType,
        'filter_city' => $filterCity,
        'filter_client' => $filterClient,
        'filter_island' => $filterIsland,
        'filter_status' => $filterStatus,
        'filter_db_status' => $filterDbStatus,
        'filter_prof' => $filterProf,
        'sort' => $column,
        'order' => $newOrder,
    ]);
}
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

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/MarkerCluster.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/MarkerCluster.Default.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/leaflet.markercluster.js"></script>

<style>
/* Dashboard General CSS */
.stage-dot { display: inline-block; width: 14px; height: 14px; border-radius: 50%; box-shadow: 0 0 4px rgba(0,0,0,0.3); flex-shrink: 0; }
.legend-container { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-md); padding: 1rem; margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: center; }
.legend-item { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; color: var(--text-secondary); white-space: nowrap; }

.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
.stat-card { background: var(--bg-card); padding: 1.5rem; border-radius: var(--radius-lg); border: 1px solid var(--border-glass); text-align: center; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: center; transition: transform 0.2s; }
.stat-card:hover { transform: translateY(-3px); border-color: rgba(255,255,255,0.2); }
.stat-number { font-size: 3rem; font-weight: 800; color: var(--primary-color); line-height: 1; font-variant-numeric: tabular-nums; letter-spacing: -1px; }
.stat-label { font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.75rem; font-weight: 600; }

.filters-section { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-md); padding: 1.5rem; margin-bottom: 1.5rem; }
.filters-grid { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; }
.filter-group { flex: 1; min-width: 150px; }
.filter-group label { display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.3rem; font-weight: 600; }
.filter-group select { width: 100%; padding: 0.6rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary); font-size: 0.9rem; }
.filter-buttons { display: flex; gap: 0.5rem; align-items: center; margin-top: 1rem; }
.reset-btn { padding: 0.6rem 1rem; color: var(--text-muted); text-decoration: none; font-size: 0.9rem; border-radius: 6px; transition: background 0.2s; } .reset-btn:hover { background: rgba(255,255,255,0.05); color: var(--text-primary); }

.view-toggle { display: flex; background: var(--bg-secondary); border-radius: 8px; padding: 4px; border: 1px solid var(--border-glass); }
.view-toggle-btn { flex: 1; padding: 8px 16px; text-align: center; border-radius: 6px; cursor: pointer; color: var(--text-muted); font-weight: 600; transition: all 0.2s; font-size: 0.9rem; border: none; outline: none; }
.view-toggle-btn.active { background: var(--primary-color); color: #fff; box-shadow: 0 2px 8px rgba(14, 165, 233, 0.4); }

.group-mode-bar { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; align-items: center; }
.group-mode-bar > span { font-size: 0.8rem; color: var(--text-muted); font-weight: 600; margin-right: 0.25rem; }
.group-mode-btn { padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.78rem; text-decoration: none; color: var(--text-secondary); border: 1px solid var(--border-glass); background: rgba(255,255,255,0.02); white-space: nowrap; }
.group-mode-btn.active, .group-mode-btn:hover { color: #fff; border-color: var(--primary-color); background: rgba(99, 102, 241, 0.2); }
.group-section { margin-bottom: 1.75rem; }
.group-section-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; padding: 0.65rem 1rem; background: rgba(255,255,255,0.03); border: 1px solid var(--border-glass); border-radius: 8px; }
.group-section-header h3 { margin: 0; font-size: 0.95rem; color: var(--text-primary); }
.group-count { font-size: 0.75rem; color: var(--text-muted); background: rgba(255,255,255,0.05); padding: 2px 8px; border-radius: 999px; }

/* Table Improvements */
.dashboard-wrapper { overflow-x: auto; background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid var(--border-glass); max-height: calc(100vh - 350px); overflow-y: auto; }
table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.9rem; }
thead { position: sticky; top: 0; z-index: 10; background: var(--bg-primary); }
th { padding: 1rem; text-align: left; font-weight: 600; color: var(--text-muted); border-bottom: 2px solid var(--border-glass); white-space: nowrap; }
td { padding: 1rem; border-bottom: 1px solid var(--border-glass); color: var(--text-secondary); vertical-align: middle; }
tr:hover td { background: rgba(255,255,255,0.02); }
tr:last-child td { border-bottom: none; }
.nowrap-cell { white-space: nowrap; } .min-w-150 { min-width: 150px; }
.cell-list-item { display: block; margin-bottom: 0.5rem; min-height: 1.2rem; line-height: 1.3; } .cell-list-item:last-child { margin-bottom: 0; }
.action-buttons-wrapper { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-start; max-width: 220px; }
.action-buttons-wrapper .btn-sm { margin: 0; padding: 0.35rem 0.6rem; font-size: 0.75rem; flex: 0 0 auto; text-align: center; white-space: nowrap; cursor: pointer; }
.pa-link { color: var(--primary-color); text-decoration: none; font-weight: 600; }
.pa-link:hover { text-decoration: underline; }
.pa-status-chip { display: inline-block; margin-left: 6px; padding: 1px 6px; border-radius: 4px; font-size: 0.68rem; font-weight: 600; background: rgba(139, 92, 246, 0.15); color: #c4b5fd; border: 1px solid rgba(139, 92, 246, 0.25); }

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

/* REDUCED PIN SIZE CSS */
.custom-pin { border-radius: 50%; border: 2px solid #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.5); background: #fff; overflow: hidden; display: flex; justify-content: center; align-items: center; }
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

.empty-state { text-align: center; padding: 4rem 2rem; background: var(--bg-card); border-radius: var(--radius-lg); border: 1px dashed var(--border-glass); color: var(--text-muted); }

/* --- Checkbox Styling for Island Filter --- */
.checkbox-group { display: flex; gap: 15px; align-items: center; height: 42px; }
.checkbox-item { display: flex; align-items: center; gap: 6px; cursor: pointer; }
.checkbox-item input[type="checkbox"] { appearance: none; -webkit-appearance: none; width: 18px; height: 18px; border: 1px solid var(--border-glass); border-radius: 4px; background: var(--bg-primary); cursor: pointer; position: relative; transition: 0.2s; }
.checkbox-item input[type="checkbox"]:checked { background: var(--primary-color); border-color: var(--primary-color); }
.checkbox-item input[type="checkbox"]:checked::after { content: "✔"; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 10px; }
.checkbox-item label { font-size: 0.9rem; color: var(--text-primary); cursor: pointer; user-select: none; margin: 0; padding: 0; }

/* Advanced KPIs Table */
.kpi-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; margin-top: 10px; }
.kpi-table th, .kpi-table td { padding: 6px 8px; text-align: right; border-bottom: 1px solid rgba(255,255,255,0.05); }
.kpi-table th:first-child, .kpi-table td:first-child { text-align: left; }
.kpi-table th { color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.7rem; }
.kpi-table td { color: #fff; font-weight: 500; }
.kpi-table tr:hover td { background: rgba(255,255,255,0.02); }
.total-col { color: var(--primary-color) !important; font-weight: 800 !important; }

.pm-archive-section { margin-top: 2rem; padding: 1rem 1.25rem; border-radius: var(--radius-lg); border: 1px solid rgba(107, 114, 128, 0.35); background: rgba(15, 23, 42, 0.35); }
.pm-archive-section > summary { cursor: pointer; font-weight: 700; color: var(--text-secondary); font-size: 0.95rem; list-style: none; display: flex; align-items: center; gap: 0.5rem; }
.pm-archive-section > summary::-webkit-details-marker { display: none; }
.pm-archive-section > summary::before { content: '▸'; display: inline-block; transition: transform 0.2s; color: var(--text-muted); }
.pm-archive-section[open] > summary::before { transform: rotate(90deg); }
.pm-archive-section .archive-hint { font-size: 0.8rem; color: var(--text-muted); margin: 0.75rem 0 1rem; }
.pm-archive-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.pm-archive-table th, .pm-archive-table td { padding: 0.55rem 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.05); text-align: left; }
.pm-archive-table th { color: var(--text-muted); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; }
.pm-archive-table tr:hover td { background: rgba(255,255,255,0.02); }

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

            <?php
            $groupModeLabels = [
                'stage' => 'By Stage',
                'client' => 'By Client',
                'perit' => 'By Perit',
                'pa_review' => 'PA Review',
                'flat' => 'Flat List',
            ];
            $groupModeQuery = array_filter([
                'view' => $currentView,
                'filter_type' => $filterType !== 'all' ? $filterType : null,
                'filter_city' => $filterCity !== 'all' ? $filterCity : null,
                'filter_client' => $filterClient !== 'all' ? $filterClient : null,
                'filter_island' => $filterIsland !== 'all' ? $filterIsland : null,
                'filter_status' => $filterStatus !== 'all' ? $filterStatus : null,
                'filter_db_status' => $filterDbStatus !== 'Active' ? $filterDbStatus : null,
                'filter_prof' => $filterProf !== 'all' ? $filterProf : null,
                'sort' => $sortBy !== 'stage' ? $sortBy : null,
                'order' => $sortOrder !== 'ASC' ? $sortOrder : null,
            ]);
            ?>
            <div class="group-mode-bar">
                <span>View mode:</span>
                <?php foreach ($groupModeLabels as $mode => $label): ?>
                    <?php $modeQuery = http_build_query(array_merge($groupModeQuery, ['group_mode' => $mode])); ?>
                    <a href="dashboard.php?<?= $modeQuery ?>" class="group-mode-btn<?= $groupMode === $mode ? ' active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>

            <div class="filters-section">
                <form method="GET" id="dashboardFilters" class="pm-auto-filter">
                    <input type="hidden" name="view" id="viewStateInput" value="<?= htmlspecialchars($currentView) ?>">
                    <input type="hidden" name="group_mode" value="<?= htmlspecialchars($groupMode) ?>">
                    <div class="filters-grid">
                        <?php if (in_array($userRole, ['admin', 'director'])): ?>
                        <div class="filter-group" style="background: rgba(239, 68, 68, 0.05); padding: 0.5rem; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.2);">
                            <label style="color: #ef4444;">Operational Status (Admin)</label>
                            <select name="filter_db_status">
                                <option value="Active" <?= $filterDbStatus === 'Active' ? 'selected' : '' ?>>🟢 Active Projects</option>
                                <option value="On-Hold" <?= $filterDbStatus === 'On-Hold' ? 'selected' : '' ?>>🟡 On-Hold Projects</option>
                                <option value="Withdrawn" <?= $filterDbStatus === 'Withdrawn' ? 'selected' : '' ?>>⚫ Withdrawn Projects</option>
                                <option value="Completed" <?= $filterDbStatus === 'Completed' ? 'selected' : '' ?>>🔵 Completed (Handed Over)</option>
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
                                <?php if (!empty($preExecOpts)): ?>
                                    <option value="group_pre" <?= $filterStatus === 'group_pre' ? 'selected' : '' ?> style="font-weight: bold; color: var(--primary-color);">▼ PRE-EXECUTION (All)</option>
                                    <?php foreach($preExecOpts as $st): ?>
                                        <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>>  • <?= $stageEnum[$st] ?>. <?= $st ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if (!empty($execOpts)): ?>
                                    <option value="group_exec" <?= $filterStatus === 'group_exec' ? 'selected' : '' ?> style="font-weight: bold; color: var(--primary-color);">▼ EXECUTION (All)</option>
                                    <?php foreach($execOpts as $st): ?>
                                        <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>>  • <?= $stageEnum[$st] ?>. <?= $st ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if (!empty($finalOpts)): ?>
                                    <option value="group_final" <?= $filterStatus === 'group_final' ? 'selected' : '' ?> style="font-weight: bold; color: var(--primary-color);">▼ FINALIZATION (All)</option>
                                    <?php foreach($finalOpts as $st): ?>
                                        <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>>  • <?= $stageEnum[$st] ?>. <?= $st ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Architect/Engineer</label>
                            <select name="filter_prof">
                                <option value="all">All Professionals</option>
                                <?php 
                                $allFirmsList = array_unique(array_merge($firms['architects'], $firms['structural_engineers']));
                                sort($allFirmsList);
                                foreach ($allFirmsList as $firm): ?>
                                    <option value="<?= htmlspecialchars($firm) ?>" <?= $filterProf === $firm ? 'selected' : '' ?>><?= htmlspecialchars($firm) ?></option>
                                <?php endforeach; ?>
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
                <?php foreach ($projectGroups as $groupKey => $group): ?>
                <div class="group-section">
                    <?php if ($groupMode !== 'flat'): ?>
                    <div class="group-section-header">
                        <?php if ($groupMode === 'stage'): ?>
                            <span class="stage-dot" style="background-color: <?= $stageColors[$group['label']] ?? '#64748b' ?>;"></span>
                        <?php endif; ?>
                        <h3><?= htmlspecialchars($group['label']) ?></h3>
                        <span class="group-count"><?= count($group['projects']) ?> project<?= count($group['projects']) === 1 ? '' : 's' ?></span>
                    </div>
                    <?php endif; ?>
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
                                    <th class="nowrap-cell">PA Number / Status</th>
                                    <th class="min-w-150">Architect</th>
                                    <th class="min-w-150">Structural Engineer</th>
                                <?php endif; ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['projects'] as $project): ?>
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
                                                <div class="cell-list-item"><?= pmRenderPaChip($pa) ?></div>
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
                                                <button type="button" onclick="openExecutionModal(<?= $project['id'] ?>, <?= htmlspecialchars(json_encode($project['name']), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-sm btn-primary" style="cursor: pointer;"><?= canUpdateStatus($pdo, $project['id']) ? 'Execution' : 'View Hub' ?></button>
                                            <?php endif; ?>
                                            <?php if (hasPermission('view_property_sales') || $isAdmin): ?><a href="property_sales.php?project_id=<?= $project['id'] ?>" class="btn btn-sm" style="background: #10B981; color: white; border: none;">Sales</a><?php endif; ?>
                                            <?php if ((hasPermission('view_capital_projects') || $isAdmin) && $project['type'] === '3rd-party'): ?><a href="capital_projects.php?project_id=<?= $project['id'] ?>" class="btn btn-sm" style="background: #0ea5e9; color: white; border: none;">Capital</a><?php endif; ?>
                                            
                                            <?php if (canEditProjectDetails($pdo, $project['id'])): ?>
                                                <button type="button" onclick="openEditModal(<?= $project['id'] ?>, <?= htmlspecialchars(json_encode($project['name']), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-sm btn-secondary" style="cursor: pointer;">Edit</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="mapView" style="display: <?= $currentView === 'map' ? 'block' : 'none' ?>;">
                <div class="map-layout">
                    <div class="map-sidebar">
                        <div style="padding: 1rem; border-bottom: 1px solid var(--border-glass);">
                            <h3 style="margin: 0; color: #fff; font-size: 1rem;">Map Locations</h3>
                            <p style="margin: 4px 0 0 0; font-size: 0.75rem; color: var(--text-muted);"><span id="mapProjCount">0</span> projects shown</p>
                            <input type="text" id="mapSearchInput" placeholder="Search map list..." style="width: 100%; margin-top: 10px; padding: 6px 10px; border-radius: 4px; border: 1px solid var(--border-glass); background: #1e1e2d; color: #fff; font-size: 0.8rem; box-sizing: border-box;">
                        </div>
                        <div class="map-sidebar-list" id="mapSidebarList"></div>
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

            <?php elseif (!$showArchiveSection || $archiveProjectCount === 0): ?>
            <div class="empty-state"><p>No projects match your current filters or assigned access limits.</p></div>
            <?php endif; ?>

            <?php if ($showArchiveSection && $archiveProjectCount > 0): ?>
            <details class="pm-archive-section">
                <summary>Handed Over Archive (<?= $archiveProjectCount ?>)</summary>
                <p class="archive-hint">Completed projects are kept here for reference. For meter applications and engineering records, use the <a href="engineering.php">Engineering Hub</a>. To revive a project marked completed in error, use <strong>Edit Project</strong> and set status back to Active.</p>
                <div class="dashboard-wrapper">
                    <table class="pm-archive-table">
                        <thead>
                            <tr>
                                <th style="width: 50px; text-align: center;">Stage</th>
                                <th>Project</th>
                                <th>Client</th>
                                <th>City</th>
                                <th>Type</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($archiveProjects as $project): ?>
                            <tr>
                                <td style="text-align: center;"><span class="stage-dot" style="background-color: <?= $stageColors[$project['stage']] ?? '#10b981' ?>;" title="<?= htmlspecialchars($project['stage']) ?>"></span></td>
                                <td style="font-weight: 600;">
                                    <a href="project-status.php?id=<?= $project['id'] ?>" style="color: var(--text-primary); text-decoration: none;"><?= htmlspecialchars($project['name']) ?></a>
                                </td>
                                <td><?= htmlspecialchars($project['client_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($project['city']) ?></td>
                                <td><?= $project['type'] === 'in-house' ? 'In-House' : ($project['type'] === '3rd-party' ? 'Capital' : htmlspecialchars($project['type'])) ?></td>
                                <td>
                                    <div class="action-buttons-wrapper">
                                        <?php if (hasPermission('edit_services') || $isAdmin): ?>
                                            <a href="engineering.php?project_id=<?= $project['id'] ?>" class="btn btn-sm" style="background: #14b8a6; color: white; border: none;">Engineering</a>
                                        <?php endif; ?>
                                        <?php if (hasPermission('view_mobilisation') || $isAdmin): ?>
                                            <button type="button" onclick="openExecutionModal(<?= $project['id'] ?>, <?= htmlspecialchars(json_encode($project['name']), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-sm btn-secondary" style="cursor: pointer;">View Hub</button>
                                        <?php endif; ?>
                                        <?php if (canEditProjectDetails($pdo, $project['id'])): ?>
                                            <button type="button" onclick="openEditModal(<?= $project['id'] ?>, <?= htmlspecialchars(json_encode($project['name']), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-sm btn-secondary" style="cursor: pointer;">Edit</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div id="genericIframeModal">
    <div class="modal-wrapper">
        <div class="modal-header">
            <h2 id="genericModalTitle">Dashboard Viewer</h2>
            <span class="modal-close" onclick="closeGenericModal()">×</span>
        </div>
        <iframe id="genericIframe" src=""></iframe>
    </div>
</div>

<script>
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

document.addEventListener("DOMContentLoaded", function() {
    let savedScroll = sessionStorage.getItem('dashboard_scrollpos');
    if (savedScroll) {
        let wrapper = document.querySelector('.dashboard-wrapper');
        if (wrapper) wrapper.scrollTop = savedScroll;
        sessionStorage.removeItem('dashboard_scrollpos');
    }
});

window.addEventListener('message', function(event) {
    if (event.data === 'projectUpdated') {
        let wrapper = document.querySelector('.dashboard-wrapper');
        if (wrapper) sessionStorage.setItem('dashboard_scrollpos', wrapper.scrollTop);
        window.location.reload();
    } else if (event.data === 'closeModal') {
        closeGenericModal();
    }
});

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

const projectsData = <?= json_encode($projects ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const localityCoords = { "Attard": [35.8914, 14.4431], "Balzan": [35.8983, 14.4533], "Birkirkara": [35.8972, 14.4611], "Birżebbuġa": [35.8258, 14.5269], "Bormla (Cospicua)": [35.8814, 14.5219], "Dingli": [35.8961, 14.4000], "Fgura": [35.8711, 14.5161], "Floriana": [35.8925, 14.5031], "Għargħur": [35.9031, 14.4525], "Gżira": [35.9228, 14.4650], "Ħamrun": [35.8847, 14.4844], "Iklin": [35.9081, 14.4542], "Isla (Senglea)": [35.8872, 14.5169], "Kalkara": [35.8889, 14.5222], "Kirkop": [35.9042, 14.4608], "Lija": [35.9008, 14.4464], "Luqa": [35.8436, 14.4883], "Marsa": [35.8672, 14.4947], "Marsaskala": [35.8272, 14.5447], "Marsaxlokk": [35.8617, 14.5683], "Mdina": [35.8833, 14.4022], "Mellieħa": [35.9564, 14.3631], "Mġarr": [35.9214, 14.4467], "Mosta": [35.9014, 14.4256], "Mqabba": [35.8425, 14.4756], "Msida": [35.9022, 14.4889], "Mtarfa": [35.8906, 14.3986], "Naxxar": [35.9133, 14.4444], "Paola": [35.8728, 14.5081], "Pembroke": [35.9325, 14.4853], "Pietà": [35.8933, 14.4939], "Qormi": [35.8789, 14.4694], "Qrendi": [35.8372, 14.4586], "Rabat": [35.8817, 14.3989], "Safi": [35.8331, 14.4850], "San Ġiljan (St. Julian's)": [35.9184, 14.4885], "San Ġwann": [35.9094, 14.4775], "San Pawl il-Baħar": [35.9483, 14.4014], "Santa Luċija": [35.8239, 14.4944], "Santa Venera": [35.8683, 14.4775], "Siġġiewi": [35.8336, 14.4372], "Sliema": [35.9122, 14.5042], "Swieqi": [35.9222, 14.4789], "Ta' Xbiex": [35.8992, 14.4936], "Tarxien": [35.8653, 14.5125], "Valletta": [35.8989, 14.5146], "Xgħajra": [35.8864, 14.5317], "Żabbar": [35.8678, 14.5367], "Żebbuġ": [35.8722, 14.4431], "Żejtun": [35.8683, 14.5333], "Żurrieq": [35.8306, 14.4744], "Fontana": [36.0353, 14.2383], "Għajnsielem": [36.0275, 14.2886], "Għarb": [36.0403, 14.2017], "Għasri": [36.0583, 14.2153], "Kerċem": [36.0522, 14.2253], "Munxar": [36.0306, 14.2333], "Nadur": [36.0378, 14.2944], "Qala": [36.0392, 14.3083], "San Lawrenz": [36.0544, 14.2044], "Sannat": [36.0244, 14.2436], "Victoria (Rabat)": [36.0436, 14.2361], "Xagħra": [36.05, 14.2667], "Xewkija": [36.0322, 14.2583], "Żebbuġ (Gozo)": [36.0717, 14.2369] };

const colorPalette = ['#ef4444', '#3b82f6', '#10b981', '#f59e0b', '#a855f7', '#ec4899', '#06b6d4', '#f97316', '#8b5cf6', '#14b8a6'];
let clientColors = {}; let colorIndex = 0;
function getClientColor(cName) { let n = cName ? cName.trim() : 'In-House (Internal)'; if (!clientColors[n]) { clientColors[n] = colorPalette[colorIndex % colorPalette.length]; colorIndex++; } return clientColors[n]; }

function initMap() {
    window.map = L.map('projectMap').setView([35.91, 14.4], 11);
    
    const darkMap = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { attribution: '© OpenStreetMap', subdomains: 'abcd', maxZoom: 19 });
    const satMap = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: 'Tiles © Esri', maxZoom: 19 });
    
    satMap.addTo(window.map); 
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

        const customIcon = L.divIcon({ className: 'custom-pin', html: `<div class="custom-pin-inner" style="background-color: ${pinColor};"></div>`, iconSize: [16, 16], iconAnchor: [8, 8] });
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

        marker.bindPopup(popupContent);
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
<script src="/assets/js/pm-filters.js?v=<?= time() ?>"></script>
