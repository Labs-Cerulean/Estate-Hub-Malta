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
$canUpdateStatus = hasPermission('update_project_status') || isAdmin();

// ==========================================
// HANDLE POST ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ACTION: Assign Team
    if ($_POST['action'] === 'assign_team' && $canAssignTeam) {
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
        } catch (PDOException $e) { $error = "Error updating team: " . $e->getMessage(); }
    }

    // ACTION: Quick Update Mobilisation
    if ($_POST['action'] === 'update_mobilisation' && $canUpdateStatus) {
        try {
            $pId = (int)$_POST['project_id'];
            $type = $_POST['mob_type'];
            $status = $_POST['status'];
            $col = ($type === 'demo') ? 'demo_status' : 'excavation_status';
            
            $stmt = $pdo->prepare("INSERT IGNORE INTO project_mobilisation (project_id) VALUES (?)");
            $stmt->execute([$pId]);

            $stmt = $pdo->prepare("UPDATE project_mobilisation SET $col = ? WHERE project_id = ?");
            $stmt->execute([$status, $pId]);
            $message = "Mobilisation status updated successfully!";
        } catch (PDOException $e) { $error = "Error updating status: " . $e->getMessage(); }
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
    
    // Fetch Block & Floor Construction Statuses
    $blockStmt = $pdo->prepare("
        SELECT pb.project_id, pb.id as block_id, pb.block_name, pb.finishes_overall_status, 
               bl.id as level_id, bl.level_name, bl.level_number, bl.construction_status, bl.construction_pct
        FROM project_blocks pb 
        LEFT JOIN block_levels bl ON pb.id = bl.block_id 
        WHERE pb.project_id IN ($placeholders)
        ORDER BY pb.id ASC, bl.level_number ASC
    ");
    $blockStmt->execute($projectIds);
    $allBlocksAndLevels = $blockStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allBlocksAndLevels as $row) {
        $blockAggData[$row['project_id']][] = $row; 
    }

    // Fetch individual floor finishes statuses
    $finStmt = $pdo->prepare("
        SELECT bls.project_id, bls.block_id, bls.level_id, bls.finish_type_id, bls.status
        FROM block_levels_statuses bls
        JOIN finish_types ft ON bls.finish_type_id = ft.id
        WHERE bls.project_id IN ($placeholders) AND ft.is_active = 1
    ");
    $finStmt->execute($projectIds);
    $rawFloorFinishes = $finStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawFloorFinishes as $fRow) {
        $floorFinishesData[$fRow['project_id']][$fRow['block_id']][$fRow['level_id']][] = $fRow['status'];
    }
}

// 5. Build Final Matrix Array with derived statuses
$matrixProjects = [];

foreach ($projectsRaw as $p) {
    if (($p['project_status'] ?? 'Active') !== 'Active') continue;

    $stage = deriveProjectStage($pdo, $p['id']);
    
    if (in_array($stage, $allowedStages)) {
        if ($filterStage !== 'all' && $stage !== $filterStage) continue;

        $p['stage'] = $stage;
        $p['demo_status'] = $mobData[$p['id']]['demo_status'] ?? 'Pending';
        $p['exc_status'] = $mobData[$p['id']]['excavation_status'] ?? 'Pending';
        
        $projConstStatuses = [];
        $projFinStatuses = [];
        $p['detailed_blocks'] = []; 

        if (isset($blockAggData[$p['id']])) {
            $blocksData = [];
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
                        
                        $levelDetails[] = [
                            'name' => $lvl['level_name'],
                            'const_status' => $lvl['construction_status'] ?? 'Pending',
                            'const_pct' => $lvl['construction_pct'] ?? 0,
                            'fin_status' => $floorStatus
                        ];
                    }
                }

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

                $p['detailed_blocks'][] = [
                    'name' => $b['name'],
                    'master_finishes' => $bFinStatus,
                    'levels' => $levelDetails
                ];
            }
        }
        
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

if ($filterType !== 'all') $matrixProjects = array_filter($matrixProjects, fn($p) => $p['type'] === $filterType);
if ($filterFinish !== 'all') $matrixProjects = array_filter($matrixProjects, fn($p) => ($p['finishlevel'] ?? '') === $filterFinish);
if ($filterCity !== 'all') $matrixProjects = array_filter($matrixProjects, fn($p) => $p['city'] === $filterCity);

if ($filterClient !== 'all') {
    if ($filterClient === 'group_excel') {
        $matrixProjects = array_filter($matrixProjects, fn($p) => stripos($p['client_name'] ?? '', 'Excel') !== false);
    } elseif ($filterClient === 'group_blue_clay') {
        $matrixProjects = array_filter($matrixProjects, fn($p) => stripos($p['client_name'] ?? '', 'Blue Clay') !== false || stripos($p['client_name'] ?? '', 'Blueclay') !== false);
    } else {
        $matrixProjects = array_filter($matrixProjects, fn($p) => $p['clientid'] == $filterClient);
    }
}

if ($filterPm !== 'all') { $matrixProjects = array_filter($matrixProjects, fn($p) => ($p['pm_construction_id'] == $filterPm || $p['pm_finishes_id'] == $filterPm)); }
if ($filterSub !== 'all') { $matrixProjects = array_filter($matrixProjects, fn($p) => ($p['sub_demolition_id'] == $filterSub || $p['sub_excavation_id'] == $filterSub || $p['sub_construction_id'] == $filterSub)); }
if ($filterIsland !== 'all') $matrixProjects = array_filter($matrixProjects, fn($p) => $p['island'] === $filterIsland);

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

$matrixProjects = array_values($matrixProjects);

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

function renderStatusBadge($status) {
    $colors = [
        'Pending' => 'background: rgba(107, 114, 128, 0.1); color: #9ca3af; border: 1px solid #4b5563;',
        'In Progress' => 'background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid #d97706;',
        'Complete' => 'background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid #16a34a;',
        'NA' => 'background: rgba(255, 255, 255, 0.05); color: #6b7280; border: 1px solid #374151;'
    ];
    $style = $colors[$status] ?? $colors['Pending'];
    return "<span style='display: inline-flex; justify-content: center; min-width: 75px; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.7rem; font-weight: 600; white-space: nowrap; $style'>$status</span>";
}

// Helper functions for cell rendering
function renderDemoExcBadge($badgeHtml, $pId, $pName, $type, $status, $canUpdateStatus) {
    if ($canUpdateStatus) {
        return "<div onclick='openMobModal($pId, \"$pName\", \"$type\", \"$status\")' class='clickable-cell' style='justify-content:center;' title='Click to Update Phase'>
                    $badgeHtml
                    <span class='edit-icon'>✎</span>
                </div>";
    }
    return "<div class='normal-cell' style='justify-content:center;'>$badgeHtml</div>";
}

function renderConstFinBadge($badgeHtml, $type, $pJson, $canUpdateStatus) {
    $icon = $canUpdateStatus ? "✎" : "👁️";
    $title = $canUpdateStatus ? 'Click to View Details & Update' : 'Click to View Details';
    return "<div onclick='openConstFinModal(\"$type\", $pJson)' class='clickable-cell' style='justify-content:center;' title='$title'>
                $badgeHtml
                <span class='edit-icon'>$icon</span>
            </div>";
}

function renderTeamCell($text, $pJson, $canAssignTeam) {
    $display = $text === 'Unassigned' ? "<span style='color:var(--text-muted); font-style:italic;'>Unassigned</span>" : htmlspecialchars($text);
    if ($canAssignTeam) {
        return "<div onclick='openAssignModal($pJson)' class='clickable-cell' title='Click to Assign Team'>
                    <span style='white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1;'>$display</span>
                    <span class='edit-icon'>✎</span>
                </div>";
    }
    return "<div class='normal-cell' style='white-space:nowrap; overflow:hidden; text-overflow:ellipsis;'>$display</div>";
}

$pageTitle = 'Project Execution Matrix';
require_once 'header.php';
?>

<style>
.matrix-wrapper { position: relative; width: 100%; max-height: calc(100vh - 180px); overflow: auto; background: var(--bg-card); border-radius: var(--radius-md); border: 1px solid var(--border-glass); box-shadow: var(--shadow-sm); }
.matrix-table { width: max-content; min-width: 100%; border-collapse: separate; border-spacing: 0; text-align: left; font-size: 0.85rem; }
.matrix-table th { position: sticky; top: 0; background: #1e1e2d; z-index: 10; padding: 1rem; font-weight: 600; color: var(--text-primary); border-bottom: 2px solid var(--border-glass); white-space: nowrap; }

/* Remove standard padding because inner divs will handle it for clickable areas */
.matrix-table td { padding: 0; border-bottom: 1px solid var(--border-glass); vertical-align: middle; color: var(--text-secondary); white-space: nowrap; height: 50px; }

.sort-link { color: inherit; text-decoration: none; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.sort-link:hover { color: var(--primary-color); }
.sort-indicator { font-size: 0.7rem; opacity: 0.7; }

.matrix-table thead th:first-child { position: sticky; left: 0; z-index: 20; border-right: 2px solid var(--border-glass); }
.matrix-table tbody tr td:first-child { position: sticky; left: 0; background: #1e1e2d; z-index: 5; border-right: 2px solid var(--border-glass); }
.matrix-table tbody tr:hover td:first-child { background: #2a2a3b; }
.matrix-table tbody tr:hover td { background: rgba(255,255,255,0.03); }

/* Unified Cell Interactions */
.normal-cell { padding: 0.75rem 1rem; height: 100%; display: flex; align-items: center; }
.clickable-cell {
    cursor: pointer;
    padding: 0.75rem 1rem;
    height: 100%;
    width: 100%;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: background 0.2s ease;
}
.clickable-cell:hover {
    background: rgba(99, 102, 241, 0.1);
}
.clickable-cell .edit-icon {
    font-size: 0.85rem;
    opacity: 0.2;
    color: var(--primary-color);
    transition: opacity 0.2s, transform 0.2s;
    flex-shrink: 0;
}
.clickable-cell:hover .edit-icon {
    opacity: 1;
    transform: scale(1.15);
}

.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.7); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; line-height: 1; }
.close-modal:hover { color: var(--text-primary); }
</style>

<div class="main-container" style="max-width: 100%; padding: 1.5rem;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;">Project Execution Matrix</h1>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.25rem;">Live operational dashboard. Click on any project, status, or team member to manage them directly.</p>
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
                </tr>
            </thead>
            <tbody>
                <?php if(empty($matrixProjects)): ?>
                    <tr><td colspan="12" style="text-align: center; padding: 2rem;">No active projects found matching these filters.</td></tr>
                <?php else: ?>
                    <?php foreach($matrixProjects as $p): 
                        $pJson = htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr>
                            <td>
                                <div onclick="openExecution(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')" class="clickable-cell" style="align-items: flex-start; justify-content: space-between;" title="Open Execution Dashboard">
                                    <div>
                                        <div style="font-weight: 700; color: var(--primary-color); font-size: 0.95rem; margin-bottom: 2px; white-space: normal; line-height: 1.2;"><?= htmlspecialchars($p['name']) ?></div>
                                        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;"><?= htmlspecialchars($p['client_name'] ?? '') ?></span>
                                    </div>
                                    <span class="edit-icon" style="font-size: 1.2rem; margin-top: 2px;">↗</span>
                                </div>
                            </td>
                            <td><div class="normal-cell"><?= htmlspecialchars($p['stage']) ?></div></td>
                            <td><div class="normal-cell"><?= htmlspecialchars($p['finishlevel'] ?? 'N/A') ?></div></td>
                            
                            <td style="border-left: 2px solid var(--border-glass);">
                                <?= renderDemoExcBadge(renderStatusBadge($p['demo_status']), $p['id'], htmlspecialchars($p['name'], ENT_QUOTES), 'demo', $p['demo_status'], $canUpdateStatus) ?>
                            </td>
                            <td>
                                <?= renderDemoExcBadge(renderStatusBadge($p['exc_status']), $p['id'], htmlspecialchars($p['name'], ENT_QUOTES), 'exc', $p['exc_status'], $canUpdateStatus) ?>
                            </td>
                            <td>
                                <?= renderConstFinBadge(renderStatusBadge($p['const_status']), 'const', $pJson, $canUpdateStatus) ?>
                            </td>
                            <td>
                                <?= renderConstFinBadge(renderStatusBadge($p['fin_status']), 'fin', $pJson, $canUpdateStatus) ?>
                            </td>

                            <td style="border-left: 2px solid var(--border-glass);">
                                <?= renderTeamCell($p['pm_const_name'], $pJson, $canAssignTeam) ?>
                            </td>
                            <td>
                                <?= renderTeamCell($p['pm_fin_name'], $pJson, $canAssignTeam) ?>
                            </td>
                            
                            <td style="border-left: 2px solid var(--border-glass);">
                                <?= renderTeamCell($p['sub_demo_name'], $pJson, $canAssignTeam) ?>
                            </td>
                            <td>
                                <?= renderTeamCell($p['sub_exc_name'], $pJson, $canAssignTeam) ?>
                            </td>
                            <td>
                                <?= renderTeamCell($p['sub_const_name'], $pJson, $canAssignTeam) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const canUpdateStatusGlobal = <?= $canUpdateStatus ? 'true' : 'false' ?>;
</script>

<?php if ($canAssignTeam): ?>
<div id="assignModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('assignModal')">&times;</span>
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

<?php if ($canUpdateStatus): ?>
<div id="mobUpdateModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <span class="close-modal" onclick="closeModal('mobUpdateModal')">&times;</span>
        <h2 id="mobModalTitle" style="margin-top: 0; color: var(--primary-color);">Update Status</h2>
        <form method="POST">
            <input type="hidden" name="action" value="update_mobilisation">
            <input type="hidden" name="project_id" id="mobProjectId">
            <input type="hidden" name="mob_type" id="mobType">
            
            <div class="form-group" style="margin-top: 1.5rem;">
                <label>Overall Phase Status</label>
                <select name="status" id="mobStatus" required>
                    <option value="Pending">Pending</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Complete">Complete</option>
                    <option value="NA">N/A</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Save Status</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="statusDetailModal" class="modal">
    <div class="modal-content" style="max-width: 500px; padding: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 id="sdModalTitle" style="margin: 0; color: var(--primary-color);">Details</h2>
            <span class="close-modal" onclick="closeModal('statusDetailModal')" style="float:none;">&times;</span>
        </div>
        
        <div id="sdModalBody" style="max-height: 50vh; overflow-y: auto; padding-right: 5px;">
            </div>
        
        <div style="margin-top: 1.5rem; text-align: right; border-top: 1px solid var(--border-glass); padding-top: 1.5rem;" id="sdFooter">
            <button id="sdEditBtn" class="btn btn-primary" style="width:100%;">Open Execution Workspace to Edit Details</button>
        </div>
    </div>
</div>

<div id="executionModal" class="modal">
    <div class="modal-content" style="width: 95%; max-width: 1600px; height: 95vh; padding: 0; display: flex; flex-direction: column; overflow: hidden; background: var(--bg-primary);">
        <div style="padding: 1rem 1.5rem; background: var(--bg-panel); border-bottom: 1px solid var(--border-glass); display: flex; justify-content: space-between; align-items: center;">
            <h2 id="execModalTitle" style="margin:0; color: var(--primary-color); font-size: 1.25rem;">Project Execution Workspace</h2>
            <div style="display:flex; gap: 15px; align-items:center;">
                <a id="execExternalLink" href="#" target="_blank" class="btn btn-sm btn-secondary" style="margin:0;">Open Full Tab</a>
                <span class="close-modal" onclick="closeExecution()" style="font-size: 2rem; line-height:1; float:none;">&times;</span>
            </div>
        </div>
        <iframe id="execIframe" style="flex: 1; width: 100%; border: none; background: var(--bg-primary);"></iframe>
    </div>
</div>

<script>
// UI Control
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.onclick = function(e) {
    if (e.target.classList.contains('modal')) e.target.style.display = "none";
}

// 1. Assign Team Modal
function openAssignModal(data) {
    document.getElementById('modalProjectId').value = data.id;
    document.getElementById('modalProjectName').textContent = 'Assign Team: ' + data.name;
    document.getElementById('modalPmConst').value = data.pm_construction_id || '';
    document.getElementById('modalPmFin').value = data.pm_finishes_id || '';
    document.getElementById('modalSubDemo').value = data.sub_demolition_id || '';
    document.getElementById('modalSubExc').value = data.sub_excavation_id || '';
    document.getElementById('modalSubConst').value = data.sub_construction_id || '';
    document.getElementById('assignModal').style.display = 'block';
}

// 2. Quick Update Mobilisation Modal
function openMobModal(pId, pName, type, currentStatus) {
    document.getElementById('mobProjectId').value = pId;
    document.getElementById('mobType').value = type;
    document.getElementById('mobModalTitle').textContent = (type === 'demo' ? 'Demolition: ' : 'Excavation: ') + pName;
    document.getElementById('mobStatus').value = currentStatus || 'Pending';
    document.getElementById('mobUpdateModal').style.display = 'block';
}

// 3. Status Detail Modal (Const/Fin Breakdown)
function openConstFinModal(type, project) {
    document.getElementById('sdModalTitle').textContent = (type === 'const' ? 'Construction: ' : 'Finishes: ') + project.name;
    
    let html = '';
    if (!project.detailed_blocks || project.detailed_blocks.length === 0) {
        html = '<div style="color: var(--text-muted); font-style: italic; text-align: center; padding: 2rem;">No blocks or levels defined for this project.</div>';
    } else {
        project.detailed_blocks.forEach(b => {
            html += `<div style="background: var(--bg-primary); border: 1px solid var(--border-glass); border-radius: 8px; padding: 1.2rem; margin-bottom: 1rem; border-left: 3px solid var(--primary-color);">
                <h4 style="margin: 0 0 1rem 0; color: var(--primary-color); border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem; display: flex; justify-content: space-between;">
                    ${b.name}
                    <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;">Overall: <span style="color:#fff; font-weight:bold;">${b.master_finishes}</span></span>
                </h4>`;
            
            if (!b.levels || b.levels.length === 0) {
                html += `<div style="font-size: 0.85rem; color: var(--text-muted); font-style: italic;">No levels added.</div>`;
            } else {
                html += `<table style="width: 100%; font-size: 0.85rem; border-collapse: collapse;"><tbody>`;
                b.levels.forEach(lvl => {
                    let statusText = type === 'const' ? lvl.const_status : lvl.fin_status;
                    let pctText = (type === 'const' && lvl.const_status === 'In Progress' && lvl.const_pct > 0) ? `<span style="color:var(--primary-color); font-weight:bold; margin-left: 5px;">${lvl.const_pct}%</span>` : '';
                    
                    let colorCode = '#9ca3af';
                    if(statusText === 'Complete') colorCode = '#22c55e';
                    if(statusText === 'In Progress') colorCode = '#f59e0b';

                    html += `<tr>
                        <td style="padding: 0.5rem 0; color: var(--text-secondary); border-bottom: 1px solid rgba(255,255,255,0.02);">${lvl.name}</td>
                        <td style="padding: 0.5rem 0; text-align: right; border-bottom: 1px solid rgba(255,255,255,0.02); font-weight: 600; color: ${colorCode};">${statusText}${pctText}</td>
                    </tr>`;
                });
                html += `</tbody></table>`;
            }
            html += `</div>`;
        });
    }
    
    document.getElementById('sdModalBody').innerHTML = html;
    
    const btn = document.getElementById('sdEditBtn');
    if (canUpdateStatusGlobal) {
        btn.style.display = 'inline-block';
        btn.onclick = function() {
            closeModal('statusDetailModal');
            openExecution(project.id, project.name);
        };
    } else {
        btn.style.display = 'none';
    }
    
    document.getElementById('statusDetailModal').style.display = 'block';
}

// 4. Full Execution Workspace Iframe
function openExecution(id, name) {
    document.getElementById('execModalTitle').textContent = 'Execution Workspace: ' + name;
    document.getElementById('execExternalLink').href = 'project-status.php?id=' + id;
    document.getElementById('execIframe').src = 'project-status.php?id=' + id + '&modal=1';
    document.getElementById('executionModal').style.display = 'block';
    document.body.style.overflow = 'hidden'; // Stop background scrolling
}

function closeExecution() {
    document.getElementById('executionModal').style.display = 'none';
    document.getElementById('execIframe').src = '';
    document.body.style.overflow = 'auto';
    window.location.reload(); // Refresh the parent page so they see any status changes made in the iframe
}

// Island Filter Logic
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
