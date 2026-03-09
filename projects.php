<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('view_projects') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = ''; $error = '';
$canAssignTeam = hasPermission('edit_project_details') || isAdmin();

// Handle Team Assignment Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_team' && $canAssignTeam) {
    try {
        $pId = $_POST['project_id'];
        $stmt = $pdo->prepare("UPDATE projects SET pm_construction_id=?, pm_finishes_id=?, sub_demolition_id=?, sub_excavation_id=?, sub_construction_id=? WHERE id=?");
        $stmt->execute([
            empty($_POST['pm_const']) ? null : $_POST['pm_const'],
            empty($_POST['pm_fin']) ? null : $_POST['pm_fin'],
            empty($_POST['sub_demo']) ? null : $_POST['sub_demo'],
            empty($_POST['sub_exc']) ? null : $_POST['sub_exc'],
            empty($_POST['sub_const']) ? null : $_POST['sub_const'],
            $pId
        ]);
        $message = "Project team updated successfully!";
    } catch (PDOException $e) {
        $error = "Error updating team: " . $e->getMessage();
    }
}

// 1. Fetch available PMs and Subcontractors for the dropdowns
$pms = $pdo->query("SELECT id, first_name, last_name, username FROM users WHERE role = 'project_manager' AND is_active = 'Yes' ORDER BY first_name")->fetchAll();
$subs = $pdo->query("SELECT id, name FROM subcontractors ORDER BY name")->fetchAll();

// 2. Define Allowed Stages (Stages 4 to 11)
$allowedStages = ['Mobilisation', 'Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over'];

// 3. GET FILTERS AND SORTS
$filterStage = $_GET['filter_stage'] ?? 'all';
$filterType = $_GET['filter_type'] ?? 'all';
$filterFinish = $_GET['filter_finish'] ?? 'all';
$filterCity = $_GET['filter_city'] ?? 'all';
$filterClient = $_GET['filter_client'] ?? 'all';
$filterIsland = $_GET['filter_island'] ?? 'all';
$filterPm = $_GET['filter_pm'] ?? 'all';
$filterSub = $_GET['filter_sub'] ?? 'all';

$sortBy = $_GET['sort'] ?? 'name';
$sortOrder = $_GET['order'] ?? 'ASC';
$allowedSorts = ['name', 'stage', 'finishlevel', 'demo_status', 'exc_status', 'const_status', 'fin_status', 'pm_const', 'pm_fin'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'name';
$allowedOrders = ['ASC', 'DESC'];
if (!in_array($sortOrder, $allowedOrders)) $sortOrder = 'ASC';

// 4. Fetch Projects base data
$projectsRaw = getAccessibleProjects($pdo, getCurrentUserId());

// Extract data for Filter Dropdowns
$cities = array_unique(array_filter(array_column($projectsRaw, 'city')));
sort($cities);

$clientIds = array_unique(array_column($projectsRaw, 'clientid'));
$clients = [];
if (!empty($clientIds)) {
    $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
    $clientStmt = $pdo->prepare("SELECT id, name FROM clients WHERE id IN ($placeholders) ORDER BY name");
    $clientStmt->execute(array_values($clientIds));
    $clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC);
}

$projectIds = array_column($projectsRaw, 'id');
$mobData = [];
$blockAggData = [];
$floorFinishesData = [];

if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    
    // Fetch Execution Clearances
    $mobStmt = $pdo->prepare("SELECT project_id, demo_status, excavation_status FROM project_mobilisation WHERE project_id IN ($placeholders)");
    $mobStmt->execute($projectIds);
    foreach ($mobStmt->fetchAll() as $row) { $mobData[$row['project_id']] = $row; }
    
    // Fetch Block & Floor Construction Statuses (needed for sorting/filtering aggregation)
    $blockStmt = $pdo->prepare("
        SELECT pb.project_id, pb.id as block_id, pb.block_name, pb.finishes_overall_status, 
               bl.id as level_id, bl.level_name, bl.level_number, bl.construction_status 
        FROM project_blocks pb 
        LEFT JOIN block_levels bl ON pb.id = bl.block_id 
        WHERE pb.project_id IN ($placeholders)
        ORDER BY pb.id ASC, bl.level_number ASC
    ");
    $blockStmt->execute($projectIds);
    $allBlocksAndLevels = $blockStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group construction data for high-level aggregation
    foreach ($allBlocksAndLevels as $row) {
        $blockAggData[$row['project_id']][] = $row; 
    }

    // Fetch individual floor finishes statuses (required for the enhanced dropdown)
    $finStmt = $pdo->prepare("
        SELECT bls.project_id, bls.block_id, bls.level_id, bls.finish_type_id, bls.status
        FROM block_levels_statuses bls
        JOIN finish_types ft ON bls.finish_type_id = ft.id
        WHERE bls.project_id IN ($placeholders) AND ft.is_active = 1
    ");
    $finStmt->execute($projectIds);
    $rawFloorFinishes = $finStmt->fetchAll(PDO::FETCH_ASSOC);

    // Index floor finishes for quick lookup
    foreach ($rawFloorFinishes as $fRow) {
        $floorFinishesData[$fRow['project_id']][$fRow['block_id']][$fRow['level_id']][] = $fRow['status'];
    }
}

// 5. Build Final Matrix Array with derived statuses
$matrixProjects = [];
$today = new DateTime();

foreach ($projectsRaw as $p) {
    if (($p['project_status'] ?? 'Active') !== 'Active') continue;

    $stage = deriveProjectStage($pdo, $p['id']);
    
    // Stage Filter is applied here before doing heavy calculations
    if (in_array($stage, $allowedStages)) {
        if ($filterStage !== 'all' && $stage !== $filterStage) continue;

        $p['stage'] = $stage;
        $p['demo_status'] = $mobData[$p['id']]['demo_status'] ?? 'Pending';
        $p['exc_status'] = $mobData[$p['id']]['excavation_status'] ?? 'Pending';
        
        $projConstStatuses = [];
        $projFinStatuses = [];
        $p['detailed_blocks'] = []; // Setup for enhanced dropdown

        if (isset($blockAggData[$p['id']])) {
            $blocksData = [];
            // Group by block first
            foreach ($blockAggData[$p['id']] as $row) {
                $blocksData[$row['block_id']]['name'] = $row['block_name'];
                $blocksData[$row['block_id']]['master_finishes'] = $row['finishes_overall_status'];
                if ($row['level_id']) {
                    $blocksData[$row['block_id']]['levels'][] = $row;
                }
            }

            foreach ($blocksData as $bid => $b) {
                $blockConstStatuses = [];
                $blockFinStatuses = [];
                $levelDetails = [];

                if (isset($b['levels'])) {
                    foreach ($b['levels'] as $lvl) {
                        $blockConstStatuses[] = $lvl['construction_status'];
                        $projConstStatuses[] = $lvl['construction_status'];

                        // CALCULATE INDIVIDUAL FLOOR FINISHES STATUS
                        $floorStatus = 'NA';
                        if (!in_array($p['finishlevel'], ['Shell', null, ''])) {
                            $statuses = $floorFinishesData[$p['id']][$bid][$lvl['level_id']] ?? [];
                            if (empty($statuses)) {
                                $floorStatus = 'Pending';
                            } else {
                                $uStatuses = array_unique($statuses);
                                if (in_array('In Progress', $uStatuses)) { $floorStatus = 'In Progress'; }
                                elseif (count($uStatuses) === 1 && end($uStatuses) === 'Complete') { $floorStatus = 'Complete'; }
                                elseif (in_array('Complete', $uStatuses)) { $floorStatus = 'In Progress'; }
                                else { $floorStatus = 'Pending'; }
                            }
                        }
                        $blockFinStatuses[] = $floorStatus;
                        
                        // Data for dropdown UI
                        $levelDetails[] = [
                            'name' => $lvl['level_name'],
                            'const_status' => $lvl['construction_status'] ?? 'Pending',
                            'fin_status' => $floorStatus
                        ];
                    }
                }

                // Aggregate Block Finishes (if not defined by PA, use dynamic calc)
                $bFinStatus = $b['master_finishes'];
                if (empty($bFinStatus) || $bFinStatus === 'Pending') {
                    if (in_array($p['finishlevel'], ['Shell', null, ''])) { $bFinStatus = 'NA'; }
                    elseif (!empty($blockFinStatuses)) {
                        if (in_array('In Progress', $blockFinStatuses)) { $bFinStatus = 'In Progress'; }
                        elseif (count(array_unique($blockFinStatuses)) === 1 && end($blockFinStatuses) === 'Complete') { $bFinStatus = 'Complete'; }
                        elseif (in_array('Complete', $blockFinStatuses)) { $bFinStatus = 'In Progress'; }
                        else { $bFinStatus = 'Pending'; }
                    } else { $bFinStatus = 'Pending'; }
                }
                $projFinStatuses[] = $bFinStatus;

                // Add to detailed data for dropdown
                $p['detailed_blocks'][] = [
                    'name' => $b['name'],
                    'master_finishes' => $bFinStatus,
                    'levels' => $levelDetails
                ];
            }
        }
        
        // AGGREGATE PROJECT HIGH-LEVEL STATUSES (Construction & Finishes)
        $p['const_status'] = 'Pending';
        if (!empty($projConstStatuses)) {
            if (in_array('In Progress', $projConstStatuses)) { $p['const_status'] = 'In Progress'; }
            elseif (count(array_unique($projConstStatuses)) === 1 && (end($projConstStatuses) === 'Complete' || end($projConstStatuses) === 'NA')) { $p['const_status'] = 'Complete'; }
            elseif (in_array('Complete', $projConstStatuses)) { $p['const_status'] = 'In Progress'; } 
        }
        
        $p['fin_status'] = 'Pending';
        if (in_array($p['finishlevel'], ['Shell', null, ''])) {
            $p['fin_status'] = 'NA';
        } elseif (!empty($projFinStatuses)) {
            $uProjFin = array_unique($projFinStatuses);
            if (in_array('In Progress', $uProjFin)) { $p['fin_status'] = 'In Progress'; }
            elseif (count($uProjFin) === 1 && (end($uProjFin) === 'Complete' || end($uProjFin) === 'NA')) { $p['fin_status'] = 'Complete'; }
            elseif (in_array('Complete', $uProjFin)) { $p['fin_status'] = 'In Progress'; }
            else { $p['fin_status'] = 'Pending'; }
        }

        // Map PMs and Subs names for sorting/display
        $p['pm_const_name'] = 'Unassigned'; $p['pm_fin_name'] = 'Unassigned';
        foreach ($pms as $pm) {
            if ($pm['id'] == $p['pm_construction_id']) $p['pm_const_name'] = $pm['first_name'] . ' ' . $pm['last_name'];
            if ($pm['id'] == $p['pm_finishes_id']) $p['pm_fin_name'] = $pm['first_name'] . ' ' . $pm['last_name'];
        }
        
        $p['sub_demo_name'] = 'Unassigned'; $p['sub_exc_name'] = 'Unassigned'; $p['sub_const_name'] = 'Unassigned';
        foreach ($subs as $sub) {
            if ($sub['id'] == $p['sub_demolition_id']) $p['sub_demo_name'] = $sub['name'];
            if ($sub['id'] == $p['sub_excavation_id']) $p['sub_exc_name'] = $sub['name'];
            if ($sub['id'] == $p['sub_construction_id']) $p['sub_const_name'] = $sub['name'];
        }
        
        $matrixProjects[] = $p;
    }
}

// 6. APPLY REMAINING FILTERS (Type, Finish, City, Client, PM, Sub)
if ($filterType !== 'all') $matrixProjects = array_filter($matrixProjects, fn($p) => $p['type'] === $filterType);
if ($filterFinish !== 'all') $matrixProjects = array_filter($matrixProjects, fn($p) => ($p['finishlevel'] ?? '') === $filterFinish);
if ($filterCity !== 'all') $matrixProjects = array_filter($matrixProjects, fn($p) => $p['city'] === $filterCity);

// --- THE FIX: Smart Umbrella Group Client Filter ---
if ($filterClient !== 'all') {
    if ($filterClient === 'group_excel') {
        $matrixProjects = array_filter($matrixProjects, fn($p) => stripos($p['client_name'] ?? '', 'Excel') !== false);
    } elseif ($filterClient === 'group_blue_clay') {
        $matrixProjects = array_filter($matrixProjects, fn($p) => stripos($p['client_name'] ?? '', 'Blue Clay') !== false || stripos($p['client_name'] ?? '', 'Blueclay') !== false);
    } else {
        $matrixProjects = array_filter($matrixProjects, fn($p) => $p['clientid'] == $filterClient);
    }
}
// ---------------------------------------------------

if ($filterPm !== 'all') { $matrixProjects = array_filter($matrixProjects, fn($p) => ($p['pm_construction_id'] == $filterPm || $p['pm_finishes_id'] == $filterPm)); }
if ($filterSub !== 'all') { $matrixProjects = array_filter($matrixProjects, fn($p) => ($p['sub_demolition_id'] == $filterSub || $p['sub_excavation_id'] == $filterSub || $p['sub_construction_id'] == $filterSub)); }
if ($filterIsland !== 'all') $matrixProjects = array_filter($matrixProjects, fn($p) => $p['island'] === $filterIsland);

// 7. APPLY SORTS
$stageEnumMap = ['Mobilisation'=>4, 'Demolition'=>5, 'Excavation'=>6, 'Construction'=>7, 'Finishes'=>8, 'Compliance'=>9, 'Condominium'=>10, 'Handed Over'=>11];
$statusEnumMap = ['Complete'=>4, 'In Progress'=>3, 'Pending'=>2, 'NA'=>1, 'N/A'=>1];

usort($matrixProjects, function($a, $b) use ($sortBy, $sortOrder, $stageEnumMap, $statusEnumMap) {
    $valA = ''; $valB = '';
    
    if ($sortBy === 'stage') {
        $valA = $stageEnumMap[$a['stage']] ?? 0;
        $valB = $stageEnumMap[$b['stage']] ?? 0;
    } elseif (in_array($sortBy, ['demo_status', 'exc_status', 'const_status', 'fin_status'])) {
        $valA = $statusEnumMap[$a[$sortBy]] ?? 0;
        $valB = $statusEnumMap[$b[$sortBy]] ?? 0;
    } elseif (in_array($sortBy, ['pm_const', 'pm_fin'])) {
        $key = $sortBy . '_name';
        $valA = $a[$key] ?? ''; $valB = $b[$key] ?? '';
    } else {
        $valA = $a[$sortBy] ?? ''; $valB = $b[$sortBy] ?? '';
    }

    if ($valA == $valB) return 0;
    
    if (is_numeric($valA) && is_numeric($valB)) { $comp = $valA <=> $valB; } 
    else { $comp = strcasecmp((string)$valA, (string)$valB); }
    
    return $sortOrder === 'ASC' ? $comp : -$comp;
});

$matrixProjects = array_values($matrixProjects); // Re-index array

// Helper functions for sort headers
function getSortUrl($column) {
    global $sortBy, $sortOrder, $filterStage, $filterType, $filterFinish, $filterCity, $filterClient, $filterIsland, $filterPm, $filterSub;
    $newOrder = ($sortBy == $column && $sortOrder == 'ASC') ? 'DESC' : 'ASC';
    $params = [
        'sort' => $column, 'order' => $newOrder, 
        'filter_stage' => $filterStage, 'filter_type' => $filterType, 'filter_finish' => $filterFinish, 
        'filter_city' => $filterCity, 'filter_client' => $filterClient,
        'filter_pm' => $filterPm, 'filter_sub' => $filterSub
    ];
    if ($filterIsland !== 'all') $params['filter_island'] = $filterIsland;
    return 'projects.php?' . http_build_query($params);
}
function getSortIndicator($column) {
    global $sortBy, $sortOrder;
    if ($sortBy === $column) return $sortOrder === 'ASC' ? ' ▲' : ' ▼';
    return '';
}

function renderStatusBadge($status, $isSmall = false) {
    $colors = [
        'Pending' => 'background: rgba(107, 114, 128, 0.1); color: #9ca3af; border: 1px solid #4b5563;',
        'In Progress' => 'background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid #d97706;',
        'Complete' => 'background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid #16a34a;',
        'NA' => 'background: rgba(255, 255, 255, 0.05); color: #6b7280; border: 1px solid #374151;'
    ];
    $style = $colors[$status] ?? $colors['Pending'];
    $padding = $isSmall ? '0.15rem 0.4rem' : '0.25rem 0.5rem';
    $fontSize = $isSmall ? '0.65rem' : '0.7rem';
    $minWidth = $isSmall ? '60px' : '75px';
    return "<span style='display: inline-flex; justify-content: center; min-width: $minWidth; padding: $padding; border-radius: 4px; font-size: $fontSize; font-weight: 600; white-space: nowrap; $style'>$status</span>";
}

$pageTitle = 'Project Status Matrix';
require_once 'header.php';
?>

<style>
.matrix-wrapper { position: relative; width: 100%; max-height: calc(100vh - 180px); overflow: auto; background: var(--bg-card); border-radius: var(--radius-md); border: 1px solid var(--border-glass); box-shadow: var(--shadow-sm); }
.matrix-table { width: max-content; min-width: 100%; border-collapse: separate; border-spacing: 0; text-align: left; font-size: 0.85rem; }
.matrix-table th { position: sticky; top: 0; background: #1e1e2d; z-index: 10; padding: 1rem; font-weight: 600; color: var(--text-primary); border-bottom: 2px solid var(--border-glass); white-space: nowrap; }
.matrix-table td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-glass); vertical-align: middle; color: var(--text-secondary); white-space: nowrap; }

/* Header Sort Links */
.sort-link { color: inherit; text-decoration: none; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.sort-link:hover { color: var(--primary-color); }
.sort-indicator { font-size: 0.7rem; opacity: 0.7; }

/* Sticky Columns for Main Rows */
.matrix-table thead th:first-child { position: sticky; left: 0; z-index: 20; border-right: 2px solid var(--border-glass); }
.matrix-table tbody tr.main-row td:first-child { position: sticky; left: 0; background: #1e1e2d; z-index: 5; border-right: 2px solid var(--border-glass); }
.matrix-table thead th:last-child { position: sticky; right: 0; z-index: 20; border-left: 2px solid var(--border-glass); }
.matrix-table tbody tr.main-row td:last-child { position: sticky; right: 0; background: #1e1e2d; z-index: 5; border-left: 2px solid var(--border-glass); }

.matrix-table tbody tr.main-row:hover td { background: rgba(255,255,255,0.03); }
.matrix-table tbody tr.main-row:hover td:first-child,
.matrix-table tbody tr.main-row:hover td:last-child { background: #2a2a3b; }

/* Sub-row Styling (Dropdown) */
.sub-row td.sub-content { background: rgba(99, 102, 241, 0.05); border-bottom: 2px solid var(--border-glass); padding: 0; }
.sub-row td.sticky-left { position: sticky; left: 0; background: #1a1a24; z-index: 5; border-right: 2px solid var(--border-glass); border-bottom: 2px solid var(--border-glass); }
.sub-row td.sticky-right { position: sticky; right: 0; background: #1a1a24; z-index: 5; border-left: 2px solid var(--border-glass); border-bottom: 2px solid var(--border-glass); }

.btn-expand { background: none; border: 1px solid var(--border-glass); color: var(--text-primary); border-radius: 4px; cursor: pointer; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; margin-right: 0.5rem; font-weight: bold; transition: all 0.2s; }
.btn-expand:hover { background: rgba(255,255,255,0.1); }

/* Modal */
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }
</style>

<div class="main-container" style="max-width: 100%; padding: 1.5rem;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;">Project Execution Matrix</h1>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.25rem;">Live operational status for projects in Stages 4 through 11.</p>
        </div>
    </div>

    <div class="filters-section" style="margin-bottom: 1.5rem;">
        <form method="GET" id="matrixFilters">
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>">
            <input type="hidden" name="order" value="<?= htmlspecialchars($sortOrder) ?>">
            
            <div class="filters-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
                <div class="filter-group">
                    <label>Current Stage</label>
                    <select name="filter_stage">
                        <option value="all" <?= $filterStage === 'all' ? 'selected' : '' ?>>All Stages</option>
                        <?php foreach ($allowedStages as $stg): ?>
                            <option value="<?= $stg ?>" <?= $filterStage === $stg ? 'selected' : '' ?>><?= $stg ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Finish Requirement</label>
                    <select name="filter_finish">
                        <option value="all" <?= $filterFinish === 'all' ? 'selected' : '' ?>>All Levels</option>
                        <option value="Shell" <?= $filterFinish === 'Shell' ? 'selected' : '' ?>>Shell</option>
                        <option value="Common Parts Only" <?= $filterFinish === 'Common Parts Only' ? 'selected' : '' ?>>Common Parts Only</option>
                        <option value="Semi Finished" <?= $filterFinish === 'Semi Finished' ? 'selected' : '' ?>>Semi Finished</option>
                        <option value="Finished" <?= $filterFinish === 'Finished' ? 'selected' : '' ?>>Finished</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Project Type</label>
                    <select name="filter_type">
                        <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Types</option>
                        <option value="in-house" <?= $filterType === 'in-house' ? 'selected' : '' ?>>In-House</option>
                        <option value="3rd-party" <?= $filterType === '3rd-party' ? 'selected' : '' ?>>3rd Party (Capital)</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Client</label>
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
                    <label>Project Manager</label>
                    <select name="filter_pm">
                        <option value="all">All Managers</option>
                        <?php foreach ($pms as $pm): ?>
                            <option value="<?= $pm['id'] ?>" <?= $filterPm == $pm['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pm['first_name'] . ' ' . $pm['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Subcontractor</label>
                    <select name="filter_sub">
                        <option value="all">All Subcontractors</option>
                        <?php foreach ($subs as $sub): ?>
                            <option value="<?= $sub['id'] ?>" <?= $filterSub == $sub['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sub['name']) ?></option>
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
                <div class="filter-group" style="min-width: 120px;">
                    <label>Island</label>
                    <div class="checkbox-group">
                        <div class="checkbox-item"><input type="checkbox" name="island_malta" id="island_malta" value="Malta" <?= ($filterIsland === 'all' || $filterIsland === 'Malta') ? 'checked' : '' ?>><label for="island_malta">Malta</label></div>
                        <div class="checkbox-item"><input type="checkbox" name="island_gozo" id="island_gozo" value="Gozo" <?= ($filterIsland === 'all' || $filterIsland === 'Gozo') ? 'checked' : '' ?>><label for="island_gozo">Gozo</label></div>
                    </div>
                </div>
            </div>
            <div class="filter-buttons">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="projects.php" class="reset-btn">Reset</a>
            </div>
        </form>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="matrix-wrapper">
        <table class="matrix-table">
            <thead>
                <tr>
                    <th><a href="<?= getSortUrl('name') ?>" class="sort-link">Project Name <span class="sort-indicator"><?= getSortIndicator('name') ?></span></a></th>
                    <th><a href="<?= getSortUrl('stage') ?>" class="sort-link">Stage <span class="sort-indicator"><?= getSortIndicator('stage') ?></span></a></th>
                    <th><a href="<?= getSortUrl('finishlevel') ?>" class="sort-link">Finish Req <span class="sort-indicator"><?= getSortIndicator('finishlevel') ?></span></a></th>
                    
                    <th style="border-left: 2px solid var(--border-glass); text-align: center;"><a href="<?= getSortUrl('demo_status') ?>" class="sort-link" style="justify-content:center;">Demolition <span class="sort-indicator"><?= getSortIndicator('demo_status') ?></span></a></th>
                    <th style="text-align: center;"><a href="<?= getSortUrl('exc_status') ?>" class="sort-link" style="justify-content:center;">Excavation <span class="sort-indicator"><?= getSortIndicator('exc_status') ?></span></a></th>
                    <th style="text-align: center;"><a href="<?= getSortUrl('const_status') ?>" class="sort-link" style="justify-content:center;">Construction <span class="sort-indicator"><?= getSortIndicator('const_status') ?></span></a></th>
                    <th style="text-align: center;"><a href="<?= getSortUrl('fin_status') ?>" class="sort-link" style="justify-content:center;">Finishes <span class="sort-indicator"><?= getSortIndicator('fin_status') ?></span></a></th>

                    <th style="border-left: 2px solid var(--border-glass);"><a href="<?= getSortUrl('pm_const') ?>" class="sort-link">PM (Const) <span class="sort-indicator"><?= getSortIndicator('pm_const') ?></span></a></th>
                    <th><a href="<?= getSortUrl('pm_fin') ?>" class="sort-link">PM (Finishes) <span class="sort-indicator"><?= getSortIndicator('pm_fin') ?></span></a></th>
                    
                    <th style="border-left: 2px solid var(--border-glass);">Sub (Demolition)</th>
                    <th>Sub (Excavation)</th>
                    <th>Sub (Construction)</th>
                    
                    <?php if ($canAssignTeam): ?><th style="text-align: center;">Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($matrixProjects)): ?>
                    <tr><td colspan="13" style="text-align: center; padding: 2rem;">No active projects found matching these filters.</td></tr>
                <?php else: ?>
                    <?php foreach($matrixProjects as $p): ?>
                        <tr class="main-row">
                            <td style="font-weight: 700; color: var(--primary-color);">
                                <div style="display: flex; align-items: flex-start;">
                                    <button class="btn-expand" onclick="toggleDetails(<?= $p['id'] ?>)" title="View Blocks & Floors">⏬</button>
                                    <div>
                                        <?= htmlspecialchars($p['name']) ?><br>
                                        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;"><?= htmlspecialchars($p['client_name'] ?? '') ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($p['stage']) ?></td>
                            <td><?= htmlspecialchars($p['finishlevel'] ?? 'N/A') ?></td>
                            
                            <td style="border-left: 2px solid var(--border-glass); text-align: center;"><?= renderStatusBadge($p['demo_status']) ?></td>
                            <td style="text-align: center;"><?= renderStatusBadge($p['exc_status']) ?></td>
                            <td style="text-align: center;"><?= renderStatusBadge($p['const_status']) ?></td>
                            <td style="text-align: center;"><?= renderStatusBadge($p['fin_status']) ?></td>

                            <td style="border-left: 2px solid var(--border-glass);">
                                <?= $p['pm_const_name'] === 'Unassigned' ? '<span style="color:var(--text-muted); font-style:italic;">Unassigned</span>' : htmlspecialchars($p['pm_const_name']) ?>
                            </td>
                            <td>
                                <?= $p['pm_fin_name'] === 'Unassigned' ? '<span style="color:var(--text-muted); font-style:italic;">Unassigned</span>' : htmlspecialchars($p['pm_fin_name']) ?>
                            </td>
                            
                            <td style="border-left: 2px solid var(--border-glass);">
                                <?= $p['sub_demo_name'] === 'Unassigned' ? '<span style="color:var(--text-muted); font-style:italic;">Unassigned</span>' : htmlspecialchars($p['sub_demo_name']) ?>
                            </td>
                            <td>
                                <?= $p['sub_exc_name'] === 'Unassigned' ? '<span style="color:var(--text-muted); font-style:italic;">Unassigned</span>' : htmlspecialchars($p['sub_exc_name']) ?>
                            </td>
                            <td>
                                <?= $p['sub_const_name'] === 'Unassigned' ? '<span style="color:var(--text-muted); font-style:italic;">Unassigned</span>' : htmlspecialchars($p['sub_const_name']) ?>
                            </td>
                            
                            <?php if ($canAssignTeam): ?>
                            <td style="text-align: center;">
                                <button onclick='openAssignModal(<?= json_encode([
                                    "id" => $p["id"], "name" => $p["name"],
                                    "pm_const" => $p["pm_construction_id"], "pm_fin" => $p["pm_finishes_id"],
                                    "sub_demo" => $p["sub_demolition_id"], "sub_exc" => $p["sub_excavation_id"], "sub_const" => $p["sub_construction_id"]
                                ], JSON_HEX_APOS) ?>)' class="btn btn-sm btn-secondary" style="padding: 0.35rem 0.75rem; margin: 0;">Assign Team</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        
                        <tr id="details-row-<?= $p['id'] ?>" class="sub-row" style="display: none;">
                            <td class="sticky-left"></td>
                            <td colspan="11" class="sub-content">
                                <div style="padding: 1.5rem; display: flex; gap: 1.5rem; flex-wrap: wrap; overflow-x: auto;">
                                    <?php if (empty($p['detailed_blocks'])): ?>
                                        <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0;">No blocks or levels have been defined for this project.</p>
                                    <?php else: ?>
                                        <?php foreach ($p['detailed_blocks'] as $b): ?>
                                            <div style="background: var(--bg-primary); border: 1px solid var(--border-glass); border-radius: 8px; padding: 1rem; min-width: 300px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); border-top: 3px solid var(--primary-color);">
                                                <h4 style="margin-bottom: 0.75rem; color: var(--primary-color); border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center;">
                                                    <?= htmlspecialchars($b['name']) ?>
                                                    <div style="display: flex; flex-direction: column; align-items: flex-end;">
                                                        <span style="font-size: 0.6rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px;">Block Finishes</span>
                                                        <?= renderStatusBadge($b['master_finishes'], true) ?>
                                                    </div>
                                                </h4>
                                                
                                                <?php if (empty($b['levels'])): ?>
                                                    <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 1rem; font-style: italic;">No floors added.</p>
                                                <?php else: ?>
                                                    <table style="width: 100%; font-size: 0.8rem; border-collapse: collapse; margin-top: 0.5rem;">
                                                        <thead>
                                                            <tr>
                                                                <th style="padding: 0.25rem 0; color: var(--text-muted); border-bottom: 1px solid rgba(255,255,255,0.05); font-weight: normal;">Level / Floor</th>
                                                                <th style="padding: 0.25rem 0.5rem; color: var(--text-muted); border-bottom: 1px solid rgba(255,255,255,0.05); font-weight: normal; text-align: center;">Const.</th>
                                                                <th style="padding: 0.25rem 0; color: var(--text-muted); border-bottom: 1px solid rgba(255,255,255,0.05); font-weight: normal; text-align: right;">Finishes</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                        <?php foreach ($b['levels'] as $lvl): ?>
                                                            <tr>
                                                                <td style="padding: 0.5rem 0; color: var(--text-primary); border-bottom: 1px solid rgba(255,255,255,0.02); font-weight: 500;"><?= htmlspecialchars($lvl['name']) ?></td>
                                                                <td style="padding: 0.5rem 0.5rem; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.02);"><?= renderStatusBadge($lvl['const_status'], true) ?></td>
                                                                <td style="padding: 0.5rem 0; text-align: right; border-bottom: 1px solid rgba(255,255,255,0.02);"><?= renderStatusBadge($lvl['fin_status'], true) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php if ($canAssignTeam): ?>
                                <td class="sticky-right"></td>
                            <?php endif; ?>
                        </tr>

                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canAssignTeam): ?>
<div id="assignModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <h2 id="modalProjectName" style="margin-bottom: 1.5rem; color: var(--primary-color);">Assign Project Team</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="assign_team">
            <input type="hidden" name="project_id" id="modalProjectId">
            
            <h4 style="margin-bottom: 0.5rem; color: var(--text-secondary); border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Project Managers</h4>
            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div class="form-group">
                    <label>Construction PM</label>
                    <select name="pm_const" id="modalPmConst">
                        <option value="">-- Unassigned --</option>
                        <?php foreach($pms as $pm): ?><option value="<?= $pm['id'] ?>"><?= htmlspecialchars($pm['first_name'] . ' ' . $pm['last_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Finishes PM</label>
                    <select name="pm_fin" id="modalPmFin">
                        <option value="">-- Unassigned --</option>
                        <?php foreach($pms as $pm): ?><option value="<?= $pm['id'] ?>"><?= htmlspecialchars($pm['first_name'] . ' ' . $pm['last_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h4 style="margin-bottom: 0.5rem; color: var(--text-secondary); border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Lead Subcontractors</h4>
            <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
                <div class="form-group">
                    <label>Demolition</label>
                    <select name="sub_demo" id="modalSubDemo">
                        <option value="">-- Unassigned --</option>
                        <?php foreach($subs as $sub): ?><option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Excavation</label>
                    <select name="sub_exc" id="modalSubExc">
                        <option value="">-- Unassigned --</option>
                        <?php foreach($subs as $sub): ?><option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Construction</label>
                    <select name="sub_const" id="modalSubConst">
                        <option value="">-- Unassigned --</option>
                        <?php foreach($subs as $sub): ?><option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.1rem;">Save Assignments</button>
        </form>
    </div>
</div>
<?php endif; ?>
<script>
function openAssignModal(data) {
    document.getElementById('modalProjectId').value = data.id;
    document.getElementById('modalProjectName').textContent = 'Assign Team: ' + data.name;
    
    document.getElementById('modalPmConst').value = data.pm_const || '';
    document.getElementById('modalPmFin').value = data.pm_fin || '';
    document.getElementById('modalSubDemo').value = data.sub_demo || '';
    document.getElementById('modalSubExc').value = data.sub_exc || '';
    document.getElementById('modalSubConst').value = data.sub_const || '';
    
    document.getElementById('assignModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('assignModal').style.display = 'none';
}

window.onclick = function(event) {
    let modal = document.getElementById('assignModal');
    if (event.target == modal) { modal.style.display = "none"; }
}

function toggleDetails(id) {
    const row = document.getElementById('details-row-' + id);
    if (row.style.display === 'none') {
        row.style.display = 'table-row';
    } else {
        row.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('matrixFilters');
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
