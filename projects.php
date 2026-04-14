<?php
require_once 'init.php';
require_once 'session-check.php';

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
    
    if ($_POST['action'] === 'assign_team' && $canAssignTeam) {
        try {
            $pId = $_POST['project_id'];
            $subFinishes = isset($_POST['sub_finishes']) ? implode(',', $_POST['sub_finishes']) : null;
            
            $stmt = $pdo->prepare("UPDATE projects SET pm_construction_id=?, pm_finishes_id=?, sub_demolition_id=?, sub_excavation_id=?, sub_construction_id=?, sub_finishes_ids=? WHERE id=?");
            $stmt->execute([
                empty($_POST['pm_const']) ? null : $_POST['pm_const'],
                empty($_POST['pm_fin']) ? null : $_POST['pm_fin'],
                empty($_POST['sub_demo']) ? null : $_POST['sub_demo'],
                empty($_POST['sub_exc']) ? null : $_POST['sub_exc'],
                empty($_POST['sub_const']) ? null : $_POST['sub_const'],
                $subFinishes,
                $pId
            ]);
            $message = "Project team updated successfully!";
        } catch (PDOException $e) { $error = "Error updating team: " . $e->getMessage(); }
    }

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

// 1. Fetch available PMs and Subcontractors
$pms = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'project_manager' AND is_active = 'Yes' ORDER BY first_name")->fetchAll();
$subs = $pdo->query("SELECT id, name FROM subcontractors ORDER BY name")->fetchAll();

// 2. Define Allowed Stages
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
$allowedSorts = ['name', 'demo_status', 'exc_status', 'const_status', 'fin_status', 'pm_const'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'name';
$allowedOrders = ['ASC', 'DESC'];
if (!in_array($sortOrder, $allowedOrders)) $sortOrder = 'ASC';

// 4. Fetch Projects base data
$projectsRaw = getAccessibleProjects($pdo, getCurrentUserId());
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
$mobData = []; $blockAggData = []; $floorFinishesData = []; $ohsaData = []; $paData = [];

if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    
    $mobStmt = $pdo->prepare("SELECT project_id, demo_status, excavation_status FROM project_mobilisation WHERE project_id IN ($placeholders)");
    $mobStmt->execute($projectIds);
    foreach ($mobStmt->fetchAll() as $row) { $mobData[$row['project_id']] = $row; }
    
    $ohsaStmt = $pdo->prepare("SELECT project_id, safety_status, safety_comments FROM project_ohsa_setup WHERE project_id IN ($placeholders)");
    $ohsaStmt->execute($projectIds);
    foreach ($ohsaStmt->fetchAll() as $row) { $ohsaData[$row['project_id']] = $row; }

    $paStmt = $pdo->prepare("SELECT project_id, pa_number FROM project_pa_numbers WHERE project_id IN ($placeholders)");
    $paStmt->execute($projectIds);
    foreach ($paStmt->fetchAll() as $row) { $paData[$row['project_id']][] = $row['pa_number']; }

    $blockStmt = $pdo->prepare("
        SELECT pb.project_id, pb.id as block_id, pb.block_name, pb.finishes_overall_status, pb.finish_level, pb.progress, bl.id as level_id, bl.level_name, bl.level_number, bl.construction_status, bl.construction_pct
        FROM project_blocks pb LEFT JOIN block_levels bl ON pb.id = bl.block_id 
        WHERE pb.project_id IN ($placeholders) ORDER BY pb.id ASC, bl.level_number ASC
    ");
    $blockStmt->execute($projectIds);
    foreach ($blockStmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $blockAggData[$row['project_id']][] = $row; }

    $finStmt = $pdo->prepare("
        SELECT bls.project_id, bls.block_id, bls.level_id, bls.status
        FROM block_levels_statuses bls JOIN finish_types ft ON bls.finish_type_id = ft.id
        WHERE bls.project_id IN ($placeholders) AND ft.is_active = 1
    ");
    $finStmt->execute($projectIds);
    foreach ($finStmt->fetchAll(PDO::FETCH_ASSOC) as $fRow) { $floorFinishesData[$fRow['project_id']][$fRow['block_id']][$fRow['level_id']][] = $fRow['status']; }
}

// 5. Build Final Matrix Array
$matrixProjects = [];
foreach ($projectsRaw as $p) {
    if (($p['project_status'] ?? 'Active') !== 'Active') continue;
    $stage = getAccurateProjectStage($pdo, $p['id']);
    
    if (in_array($stage, $allowedStages)) {
        if ($filterStage !== 'all' && $stage !== $filterStage) continue;

        $p['stage'] = $stage;
        $p['demo_status'] = $mobData[$p['id']]['demo_status'] ?? 'Pending';
        $p['exc_status'] = $mobData[$p['id']]['excavation_status'] ?? 'Pending';
        $p['safety_status'] = $ohsaData[$p['id']]['safety_status'] ?? 'N/A';
        $p['safety_comments'] = $ohsaData[$p['id']]['safety_comments'] ?? '';
        $p['pa_numbers'] = isset($paData[$p['id']]) ? implode(', ', $paData[$p['id']]) : null;

        $projConstStatuses = [];
        $projFinStatuses = [];
        $p['detailed_blocks'] = []; 

        if (isset($blockAggData[$p['id']])) {
            $blocksData = [];
            foreach ($blockAggData[$p['id']] as $row) {
                $blocksData[$row['block_id']]['name'] = $row['block_name'];
                $blocksData[$row['block_id']]['master_finishes'] = $row['finishes_overall_status'];
                $blocksData[$row['block_id']]['finish_level'] = $row['finish_level'];
                $blocksData[$row['block_id']]['progress'] = $row['progress'];
                if ($row['level_id']) { $blocksData[$row['block_id']]['levels'][] = $row; }
            }

            foreach ($blocksData as $bid => $b) {
                $blockConstStatuses = []; $blockFinStatuses = []; $levelDetails = [];

                if (isset($b['levels'])) {
                    foreach ($b['levels'] as $lvl) {
                        $blockConstStatuses[] = $lvl['construction_status'];
                        $projConstStatuses[] = $lvl['construction_status'];

                        $floorStatus = 'NA';
                        if (!in_array($p['finishlevel'], ['Shell', null, ''])) {
                            $statuses = $floorFinishesData[$p['id']][$bid][$lvl['level_id']] ?? [];
                            if (empty($statuses)) { $floorStatus = 'Pending'; } 
                            else {
                                $uStatuses = array_unique($statuses);
                                if (in_array('In Progress', $uStatuses)) { $floorStatus = 'In Progress'; }
                                elseif (count($uStatuses) === 1 && end($uStatuses) === 'Complete') { $floorStatus = 'Complete'; }
                                elseif (in_array('Complete', $uStatuses)) { $floorStatus = 'In Progress'; }
                                else { $floorStatus = 'Pending'; }
                            }
                        }
                        $blockFinStatuses[] = $floorStatus;
                        $levelDetails[] = ['name' => $lvl['level_name'], 'const_status' => $lvl['construction_status'] ?? 'Pending', 'const_pct' => $lvl['construction_pct'] ?? 0, 'fin_status' => $floorStatus];
                    }
                }

                $bFinStatus = $b['master_finishes'];
                if (empty($bFinStatus) || $bFinStatus === 'Pending') {
                    $bFinGoal = !empty($b['finish_level']) ? $b['finish_level'] : $p['finishlevel'];
                    
                    if (in_array($bFinGoal, ['Shell', 'Shell (No Finishes)', null, ''])) { 
                        $bFinStatus = 'NA'; 
                    } elseif (isset($b['progress']) && $b['progress'] >= 100) {
                        $bFinStatus = 'Complete';
                    } elseif (!empty($blockFinStatuses)) {
                        if (in_array('In Progress', $blockFinStatuses)) { $bFinStatus = 'In Progress'; }
                        elseif (count(array_unique($blockFinStatuses)) === 1 && end($blockFinStatuses) === 'Complete') { $bFinStatus = 'Complete'; }
                        elseif (in_array('Complete', $blockFinStatuses)) { $bFinStatus = 'In Progress'; }
                        else { $bFinStatus = 'Pending'; }
                    } else { 
                        $bFinStatus = 'Pending'; 
                    }
                }
                $projFinStatuses[] = $bFinStatus;
                $p['detailed_blocks'][] = ['name' => $b['name'], 'master_finishes' => $bFinStatus, 'levels' => $levelDetails];
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
if ($filterSub !== 'all') { $matrixProjects = array_filter($matrixProjects, fn($p) => ($p['sub_demolition_id'] == $filterSub || $p['sub_excavation_id'] == $filterSub || $p['sub_construction_id'] == $filterSub || strpos(','.$p['sub_finishes_ids'].',', ','.$filterSub.',') !== false)); }
if ($filterIsland !== 'all') $matrixProjects = array_filter($matrixProjects, fn($p) => $p['island'] === $filterIsland);

$statusEnumMap = ['Complete'=>4, 'In Progress'=>3, 'Pending'=>2, 'NA'=>1, 'N/A'=>1];

usort($matrixProjects, function($a, $b) use ($sortBy, $sortOrder, $statusEnumMap) {
    $valA = ''; $valB = '';
    if (in_array($sortBy, ['demo_status', 'exc_status', 'const_status', 'fin_status'])) {
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
        'sort' => $column, 'order' => $newOrder, 'filter_stage' => $filterStage, 'filter_type' => $filterType, 'filter_finish' => $filterFinish, 
        'filter_city' => $filterCity, 'filter_client' => $filterClient, 'filter_pm' => $filterPm, 'filter_sub' => $filterSub
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
    return "<span class='badge' style='display: inline-flex; justify-content: center; min-width: 75px; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.7rem; font-weight: 600; white-space: nowrap; $style'>$status</span>";
}

function renderDemoExcBadge($badgeHtml, $pId, $pName, $type, $status, $canUpdateStatus) {
    if ($canUpdateStatus) {
        return "<div onclick='openMobModal($pId, \"$pName\", \"$type\", \"$status\")' class='clickable-cell' style='justify-content:center;' title='Click to Update Phase'>$badgeHtml<span class='edit-icon'>✎</span></div>";
    }
    return "<div class='normal-cell' style='justify-content:center;'>$badgeHtml</div>";
}

function renderConstFinBadge($badgeHtml, $type, $pJson, $canUpdateStatus) {
    $icon = $canUpdateStatus ? "✎" : "👁️";
    $title = $canUpdateStatus ? 'Click to View Details & Update' : 'Click to View Details';
    return "<div onclick='openConstFinModal(\"$type\", $pJson)' class='clickable-cell' style='justify-content:center;' title='$title'>$badgeHtml<span class='edit-icon'>$icon</span></div>";
}

function renderPMsCell($pmConst, $pmFin, $pJson, $canAssignTeam) {
    $cDisplay = $pmConst === 'Unassigned' ? "<span style='color:var(--text-muted); font-style:italic;'>Unassigned</span>" : htmlspecialchars($pmConst);
    $fDisplay = $pmFin === 'Unassigned' ? "<span style='color:var(--text-muted); font-style:italic;'>Unassigned</span>" : htmlspecialchars($pmFin);
    
    $content = "<div style='display:flex; flex-direction:column; gap:6px; font-size: 0.8rem; flex:1;'>
                    <div style='white-space:nowrap; overflow:hidden; text-overflow:ellipsis;' title='Construction PM'><span style='color:var(--text-muted); font-size:0.65rem; text-transform:uppercase; width: 45px; display:inline-block;'>Const:</span> $cDisplay</div>
                    <div style='white-space:nowrap; overflow:hidden; text-overflow:ellipsis;' title='Finishes PM'><span style='color:var(--text-muted); font-size:0.65rem; text-transform:uppercase; width: 45px; display:inline-block;'>Fin:</span> $fDisplay</div>
                </div>";

    if ($canAssignTeam) {
        return "<div onclick='openAssignModal($pJson)' class='clickable-cell' title='Click to Assign Team' style='align-items:flex-start;'>$content<span class='edit-icon' style='margin-top:2px;'>✎</span></div>";
    }
    return "<div class='normal-cell' style='align-items:flex-start;'>$content</div>";
}

function renderAllSubsCell($demo, $exc, $const, $finIds, $subsArray, $pJson, $canAssignTeam) {
    $dDisp = $demo === 'Unassigned' ? "<span style='color:var(--text-muted); font-style:italic;'>Unassigned</span>" : htmlspecialchars($demo);
    $eDisp = $exc === 'Unassigned' ? "<span style='color:var(--text-muted); font-style:italic;'>Unassigned</span>" : htmlspecialchars($exc);
    $cDisp = $const === 'Unassigned' ? "<span style='color:var(--text-muted); font-style:italic;'>Unassigned</span>" : htmlspecialchars($const);

    $fIds = empty($finIds) ? [] : explode(',', $finIds);
    $fNames = [];
    foreach ($fIds as $id) {
        foreach ($subsArray as $sub) { if ($sub['id'] == $id) { $fNames[] = htmlspecialchars($sub['name']); break; } }
    }
    $fDisp = empty($fNames) ? "<span style='color:var(--text-muted); font-style:italic;'>Unassigned</span>" : implode(', ', $fNames);

    $content = "<div class='custom-scrollbar' style='display:flex; flex-direction:column; gap:4px; font-size: 0.75rem; flex:1; max-height: 85px; overflow-y: auto; padding-right: 4px;'>
                    <div style='white-space:nowrap; overflow:hidden; text-overflow:ellipsis;' title='Demolition Contractor'><span style='color:var(--text-muted); font-size:0.65rem; text-transform:uppercase; width: 45px; display:inline-block;'>Demo:</span> $dDisp</div>
                    <div style='white-space:nowrap; overflow:hidden; text-overflow:ellipsis;' title='Excavation Contractor'><span style='color:var(--text-muted); font-size:0.65rem; text-transform:uppercase; width: 45px; display:inline-block;'>Exc:</span> $eDisp</div>
                    <div style='white-space:nowrap; overflow:hidden; text-overflow:ellipsis;' title='Construction Contractor'><span style='color:var(--text-muted); font-size:0.65rem; text-transform:uppercase; width: 45px; display:inline-block;'>Const:</span> $cDisp</div>
                    <div title='Finishes Contractors'><span style='color:var(--text-muted); font-size:0.65rem; text-transform:uppercase; width: 45px; display:inline-block; vertical-align:top;'>Fin:</span> <span style='display:inline-block; width:calc(100% - 50px); white-space:normal; line-height:1.2;'>$fDisp</span></div>
                </div>";

    if ($canAssignTeam) {
        return "<div onclick='openAssignModal($pJson)' class='clickable-cell' title='Click to Assign Team' style='align-items:flex-start;'>$content<span class='edit-icon' style='margin-top:2px;'>✎</span></div>";
    }
    return "<div class='normal-cell' style='align-items:flex-start;'>$content</div>";
}

$pageTitle = 'Project Execution Matrix';
require_once 'header.php';
?>

<style>
/* MATRIX WRAPPER & SCROLLBAR */
.matrix-wrapper { position: relative; width: 100%; max-height: calc(100vh - 180px); overflow: auto; background: var(--bg-card); border-radius: var(--radius-md); border: 1px solid var(--border-glass); box-shadow: var(--shadow-sm); }
.matrix-wrapper::-webkit-scrollbar { height: 12px; width: 12px; }
.matrix-wrapper::-webkit-scrollbar-track { background: rgba(0,0,0,0.15); border-radius: 8px; }
.matrix-wrapper::-webkit-scrollbar-thumb { background: rgba(99, 102, 241, 0.6); border-radius: 8px; border: 2px solid var(--bg-card); cursor: pointer; }
.matrix-wrapper::-webkit-scrollbar-thumb:hover { background: rgba(99, 102, 241, 1); }

/* TABLE BASE */
.matrix-table { width: max-content; min-width: 100%; border-collapse: separate; border-spacing: 0; text-align: left; font-size: 0.85rem; }
.matrix-table th { position: sticky; top: 0; background: #1e1e2d; z-index: 10; padding: 1rem; font-weight: 600; color: var(--text-primary); border-bottom: 2px solid var(--border-glass); white-space: nowrap; }
.matrix-table td { padding: 0; border-bottom: 1px solid var(--border-glass); vertical-align: middle; color: var(--text-secondary); white-space: nowrap; height: 50px; }

/* FORCIBLY UNFREEZE RIGHT COLUMNS */
.matrix-table th, .matrix-table td { position: static !important; right: auto !important; }
.matrix-table thead th:first-child { position: sticky !important; left: 0 !important; z-index: 20 !important; border-right: 2px solid var(--border-glass) !important; background: #1e1e2d; }
.matrix-table tbody tr td:first-child { position: sticky !important; left: 0 !important; background: #1e1e2d !important; z-index: 5 !important; border-right: 2px solid var(--border-glass) !important; }
.matrix-table tbody tr:hover td:first-child { background: #2a2a3b !important; }
.matrix-table tbody tr:hover td { background: rgba(255,255,255,0.03); }

/* CONSOLIDATED PROJECT CELL */
.project-info-cell { display: flex; flex-direction: column; gap: 6px; align-items: flex-start !important; padding: 0.75rem 1rem !important; }
.project-title { font-weight: 700; color: var(--primary-color); font-size: 0.95rem; white-space: normal; line-height: 1.2; }
.project-client { font-size: 0.75rem; color: var(--text-muted); font-weight: normal; margin-bottom: 4px; }
.project-tags { display: flex; flex-wrap: wrap; gap: 6px; max-width: 320px; }
.info-tag { font-size: 0.65rem; padding: 2px 6px; border-radius: 4px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: var(--text-secondary); white-space: nowrap; }
.info-tag.ohsa-green { color: #22c55e; border-color: rgba(34,197,94,0.3); background: rgba(34,197,94,0.1); cursor: pointer; }
.info-tag.ohsa-yellow { color: #f59e0b; border-color: rgba(245,158,11,0.3); background: rgba(245,158,11,0.1); cursor: pointer; }
.info-tag.ohsa-red { color: #ef4444; border-color: rgba(239,68,68,0.3); background: rgba(239,68,68,0.1); cursor: pointer; }
.info-tag.ohsa-green:hover, .info-tag.ohsa-yellow:hover, .info-tag.ohsa-red:hover { filter: brightness(1.2); }

/* INTERACTIONS */
.sort-link { color: inherit; text-decoration: none; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.sort-link:hover { color: var(--primary-color); }
.sort-indicator { font-size: 0.7rem; opacity: 0.7; }
.normal-cell { padding: 0.75rem 1rem; height: 100%; display: flex; align-items: center; }
.clickable-cell { cursor: pointer; padding: 0.75rem 1rem; height: 100%; width: 100%; display: flex; align-items: center; gap: 8px; transition: background 0.2s ease; border-radius: 4px; }
.clickable-cell:hover { background: rgba(99, 102, 241, 0.15); }
.clickable-cell .edit-icon { font-size: 0.85rem; opacity: 0.2; color: var(--primary-color); transition: opacity 0.2s, transform 0.2s; flex-shrink: 0; }
.clickable-cell:hover .edit-icon { opacity: 1; transform: scale(1.15); }
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: var(--primary-color); border-radius: 4px; }

/* MODALS */
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.7); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; line-height: 1; }
.close-modal:hover { color: var(--text-primary); }

/* MODERN TAG SELECTOR */
.tag-input-container { position: relative; width: 100%; }
.tag-box { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding: 6px; min-height: 42px; border: 1px solid var(--border-glass); border-radius: 6px; background: var(--bg-primary); cursor: text; }
.tag-pill { background: var(--primary-color); color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; display: flex; align-items: center; gap: 6px; }
.tag-pill .remove-tag { cursor: pointer; font-weight: bold; opacity: 0.7; transition: 0.2s; }
.tag-pill .remove-tag:hover { opacity: 1; }
.tag-search-input { flex: 1; min-width: 150px; border: none; background: transparent; color: var(--text-primary); outline: none; font-size: 0.9rem; padding: 4px; }
.tag-dropdown { position: absolute; top: 100%; left: 0; width: 100%; background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: 6px; max-height: 200px; overflow-y: auto; z-index: 1001; box-shadow: 0 10px 25px rgba(0,0,0,0.5); display: none; margin-top: 4px; }
.tag-option { padding: 10px 12px; font-size: 0.85rem; color: var(--text-primary); cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.02); }
.tag-option:hover { background: rgba(99, 102, 241, 0.2); }
.tag-empty { padding: 10px 12px; font-size: 0.85rem; color: var(--text-muted); font-style: italic; }

/* ==========================================
   PRINT / PDF EXPORT STYLES
   ========================================== */
@media print {
    @page { size: landscape; margin: 1cm; }
    body { background: #fff !important; color: #000 !important; }
    
    /* Hide UI Elements */
    .header, .sidebar, .filters-section, .edit-icon, .close-modal, .sort-indicator, .modal { display: none !important; }
    button, .btn { display: none !important; }
    
    /* Adjust Containers for PDF */
    .main-container { padding: 0 !important; max-width: 100% !important; margin: 0 !important; }
    .matrix-wrapper { max-height: none !important; overflow: visible !important; border: none !important; box-shadow: none !important; background: transparent !important; }
    
    /* Adjust Table for PDF */
    .matrix-table { width: 100% !important; border-collapse: collapse !important; page-break-inside: auto; }
    .matrix-table th { position: static !important; background: #f3f4f6 !important; color: #111827 !important; border: 1px solid #d1d5db !important; padding: 8px !important; font-size: 10pt !important; }
    .matrix-table td { position: static !important; background: #fff !important; color: #1f2937 !important; border: 1px solid #d1d5db !important; padding: 8px !important; font-size: 9pt !important; }
    
    /* Preserve Badge Colors via Webkit extension */
    .badge, .info-tag { -webkit-print-color-adjust: exact; print-color-adjust: exact; border-color: #d1d5db !important; }
    
    /* Reset layout quirks for printing */
    .normal-cell, .clickable-cell { padding: 4px !important; }
    a { text-decoration: none !important; color: inherit !important; }
    h1.page-title { color: #000 !important; margin-bottom: 20px !important; }
    p { color: #4b5563 !important; }
}
</style>

<div class="main-container" style="max-width: 100%; padding: 1.5rem;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;">Project Execution Matrix</h1>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.25rem;">Live operational dashboard. Click on any project, status, or team member to manage them directly.</p>
        </div>
        <div>
            <button onclick="window.print()" class="btn" style="background: var(--primary-color); color: white; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-file-pdf"></i> Export PDF Report
            </button>
        </div>
    </div>

    <div class="filters-section" style="margin-bottom: 1.5rem;">
        <form method="GET" id="matrixFilters">
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>">
            <input type="hidden" name="order" value="<?= htmlspecialchars($sortOrder) ?>">
            <div class="filters-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
                <div class="filter-group"><label>Stage</label><select name="filter_stage"><option value="all">All Stages</option><?php foreach ($allowedStages as $stg): ?><option value="<?= $stg ?>" <?= $filterStage === $stg ? 'selected' : '' ?>><?= $stg ?></option><?php endforeach; ?></select></div>
                <div class="filter-group"><label>Finish Req</label><select name="filter_finish"><option value="all">All Levels</option><option value="Shell" <?= $filterFinish === 'Shell' ? 'selected' : '' ?>>Shell</option><option value="Common Parts Only" <?= $filterFinish === 'Common Parts Only' ? 'selected' : '' ?>>Common Parts Only</option><option value="Semi Finished" <?= $filterFinish === 'Semi Finished' ? 'selected' : '' ?>>Semi Finished</option><option value="Finished" <?= $filterFinish === 'Finished' ? 'selected' : '' ?>>Finished</option></select></div>
                <div class="filter-group"><label>Project Type</label><select name="filter_type"><option value="all">All Types</option><option value="in-house" <?= $filterType === 'in-house' ? 'selected' : '' ?>>In-House</option><option value="3rd-party" <?= $filterType === '3rd-party' ? 'selected' : '' ?>>3rd Party</option></select></div>
                <div class="filter-group"><label>Client</label><select name="filter_client"><option value="all">All Clients</option><optgroup label="Groups"><option value="group_excel" <?= $filterClient === 'group_excel' ? 'selected' : '' ?>>Excel Group</option><option value="group_blue_clay" <?= $filterClient === 'group_blue_clay' ? 'selected' : '' ?>>Blue Clay</option></optgroup><optgroup label="Individual"><?php foreach ($clients as $client): ?><option value="<?= $client['id'] ?>" <?= $filterClient == $client['id'] ? 'selected' : '' ?>><?= htmlspecialchars($client['name']) ?></option><?php endforeach; ?></optgroup></select></div>
                
                <div class="filter-group">
                    <label>Island</label>
                    <select name="filter_island">
                        <option value="all">All Islands</option>
                        <option value="Malta" <?= $filterIsland === 'Malta' ? 'selected' : '' ?>>Malta</option>
                        <option value="Gozo" <?= $filterIsland === 'Gozo' ? 'selected' : '' ?>>Gozo</option>
                    </select>
                </div>
                
                <div class="filter-group"><label>PM</label><select name="filter_pm"><option value="all">All PMs</option><?php foreach ($pms as $pm): ?><option value="<?= $pm['id'] ?>" <?= $filterPm == $pm['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pm['first_name'] . ' ' . $pm['last_name']) ?></option><?php endforeach; ?></select></div>
                <div class="filter-group"><label>Subcontractor</label><select name="filter_sub"><option value="all">All Subcontractors</option><?php foreach ($subs as $sub): ?><option value="<?= $sub['id'] ?>" <?= $filterSub == $sub['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sub['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="filter-buttons"><button type="submit" class="btn">Apply Filters</button><a href="projects.php" class="reset-btn">Reset</a></div>
        </form>
    </div>

    <div class="matrix-wrapper">
        <table class="matrix-table">
            <thead>
                <tr>
                    <th><a href="<?= getSortUrl('name') ?>" class="sort-link">Project Detail <span class="sort-indicator"><?= getSortIndicator('name') ?></span></a></th>
                    <th style="border-left: 2px solid var(--border-glass); text-align: center;"><a href="<?= getSortUrl('demo_status') ?>" class="sort-link" style="justify-content:center;">Demolition <span class="sort-indicator"><?= getSortIndicator('demo_status') ?></span></a></th>
                    <th style="text-align: center;"><a href="<?= getSortUrl('exc_status') ?>" class="sort-link" style="justify-content:center;">Excavation <span class="sort-indicator"><?= getSortIndicator('exc_status') ?></span></a></th>
                    <th style="text-align: center;"><a href="<?= getSortUrl('const_status') ?>" class="sort-link" style="justify-content:center;">Construction <span class="sort-indicator"><?= getSortIndicator('const_status') ?></span></a></th>
                    <th style="text-align: center;"><a href="<?= getSortUrl('fin_status') ?>" class="sort-link" style="justify-content:center;">Finishes <span class="sort-indicator"><?= getSortIndicator('fin_status') ?></span></a></th>
                    <th style="border-left: 2px solid var(--border-glass);"><a href="<?= getSortUrl('pm_const') ?>" class="sort-link">Project Managers <span class="sort-indicator"><?= getSortIndicator('pm_const') ?></span></a></th>
                    <th style="border-left: 2px solid var(--border-glass);">Lead Subcontractors</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($matrixProjects)): ?>
                    <tr><td colspan="7" style="text-align: center; padding: 2rem;">No active projects found.</td></tr>
                <?php else: ?>
                    <?php foreach($matrixProjects as $p): 
                        $pJson = htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8');
                        $ohsaJson = htmlspecialchars(json_encode(['name'=>$p['name'], 'status'=>$p['safety_status'], 'comments'=>$p['safety_comments']]), ENT_QUOTES, 'UTF-8');
                        
                        $ohsaClass = strtolower($p['safety_status'] ?? 'na');
                        $ohsaIcon = ['Green'=>'🟢', 'Yellow'=>'🟡', 'Red'=>'🔴'][$p['safety_status']] ?? '⚪';
                    ?>
                        <tr>
                            <td style="min-width: 320px; max-width: 380px;">
                                <div class="clickable-cell project-info-cell" onclick="openIframeModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>', 'mobilisation_detail.php')" title="Open Execution Workspace">
                                    <div class="project-title"><?= htmlspecialchars($p['name']) ?></div>
                                    <div class="project-client"><?= htmlspecialchars($p['client_name'] ?? 'No Client') ?></div>
                                    
                                    <div class="project-tags">
                                        <span class="info-tag"><?= htmlspecialchars($p['stage']) ?></span>
                                        <span class="info-tag">Fin: <?= htmlspecialchars($p['finishlevel'] ?? 'N/A') ?></span>
                                        <?php if($p['pa_numbers']): ?>
                                            <span class="info-tag" style="white-space:normal;">PA: <?= htmlspecialchars($p['pa_numbers']) ?></span>
                                        <?php endif; ?>
                                        <span class="info-tag ohsa-<?= $ohsaClass ?>" onclick="event.stopPropagation(); openOhsaInfoModal(<?= $ohsaJson ?>);" title="View Safety Comments">
                                            <?= $ohsaIcon ?> OHSA
                                        </span>
                                    </div>
                                </div>
                            </td>
                            
                            <td style="border-left: 2px solid var(--border-glass);">
                                <?= renderDemoExcBadge(renderStatusBadge($p['demo_status']), $p['id'], htmlspecialchars($p['name'], ENT_QUOTES), 'demo', $p['demo_status'], $canUpdateStatus) ?>
                            </td>
                            <td><?= renderDemoExcBadge(renderStatusBadge($p['exc_status']), $p['id'], htmlspecialchars($p['name'], ENT_QUOTES), 'exc', $p['exc_status'], $canUpdateStatus) ?></td>
                            <td><?= renderConstFinBadge(renderStatusBadge($p['const_status']), 'const', $pJson, $canUpdateStatus) ?></td>
                            <td><?= renderConstFinBadge(renderStatusBadge($p['fin_status']), 'fin', $pJson, $canUpdateStatus) ?></td>

                            <td style="border-left: 2px solid var(--border-glass);">
                                <?= renderPMsCell($p['pm_const_name'], $p['pm_fin_name'], $pJson, $canAssignTeam) ?>
                            </td>
                            <td style="border-left: 2px solid var(--border-glass); min-width: 250px;">
                                <?= renderAllSubsCell($p['sub_demo_name'], $p['sub_exc_name'], $p['sub_const_name'], $p['sub_finishes_ids'] ?? '', $subs, $pJson, $canAssignTeam) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="ohsaInfoModal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <span class="close-modal" onclick="closeModal('ohsaInfoModal')">&times;</span>
        <h2 style="margin-top: 0; color: var(--primary-color);" id="ohsaInfoTitle">Safety Details</h2>
        <div style="text-align: center; margin: 1.5rem 0;"><span id="ohsaInfoBadge" style="padding: 0.5rem 1rem; border-radius: 8px; font-size: 1rem; font-weight: bold;"></span></div>
        <div class="form-group">
            <label>Safety Comments / Alerts:</label>
            <div id="ohsaInfoComments" style="background: rgba(255,255,255,0.05); padding: 1rem; border-radius: 6px; border: 1px solid var(--border-glass); min-height: 80px; white-space: pre-wrap; font-size: 0.9rem; color: var(--text-primary); line-height: 1.5;"></div>
        </div>
        <div style="text-align: right; margin-top: 1.5rem; border-top: 1px solid var(--border-glass); padding-top: 1rem;">
            <a href="ohsa.php" class="btn btn-sm btn-secondary">Go to Full OHSA Dashboard</a>
        </div>
    </div>
</div>

<?php if ($canAssignTeam): ?>
<div id="assignModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('assignModal')">&times;</span>
        <h2 id="modalProjectName" style="margin-bottom: 1.5rem; color: var(--primary-color);">Assign Project Team</h2>
        
        <form method="POST" id="assignTeamForm">
            <input type="hidden" name="action" value="assign_team">
            <input type="hidden" name="project_id" id="modalProjectId">
            
            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div class="form-group"><label>Construction PM</label><select name="pm_const" id="modalPmConst"><option value="">-- Unassigned --</option><?php foreach($pms as $pm): ?><option value="<?= $pm['id'] ?>"><?= htmlspecialchars($pm['first_name'] . ' ' . $pm['last_name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Finishes PM</label><select name="pm_fin" id="modalPmFin"><option value="">-- Unassigned --</option><?php foreach($pms as $pm): ?><option value="<?= $pm['id'] ?>"><?= htmlspecialchars($pm['first_name'] . ' ' . $pm['last_name']) ?></option><?php endforeach; ?></select></div>
            </div>

            <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div class="form-group"><label>Demolition</label><select name="sub_demo" id="modalSubDemo"><option value="">-- Unassigned --</option><?php foreach($subs as $sub): ?><option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Excavation</label><select name="sub_exc" id="modalSubExc"><option value="">-- Unassigned --</option><?php foreach($subs as $sub): ?><option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Construction</label><select name="sub_const" id="modalSubConst"><option value="">-- Unassigned --</option><?php foreach($subs as $sub): ?><option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            
            <div class="form-group" style="margin-bottom: 2.5rem;">
                <label>Finishes Contractors</label>
                <div class="tag-input-container">
                    <div class="tag-box" id="tagBox" onclick="document.getElementById('tagSearch').focus()">
                        <input type="text" id="tagSearch" class="tag-search-input" placeholder="Type to search contractors..." autocomplete="off">
                    </div>
                    <div id="tagDropdown" class="tag-dropdown custom-scrollbar"></div>
                    <div id="tagHiddenInputs"></div> 
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
            <div class="form-group" style="margin-top: 1.5rem;"><label>Overall Phase Status</label><select name="status" id="mobStatus" required><option value="Pending">Pending</option><option value="In Progress">In Progress</option><option value="Complete">Complete</option><option value="NA">N/A</option></select></div>
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
        <div id="sdModalBody" class="custom-scrollbar" style="max-height: 50vh; overflow-y: auto; padding-right: 5px;"></div>
        <div style="margin-top: 1.5rem; text-align: right; border-top: 1px solid var(--border-glass); padding-top: 1.5rem;" id="sdFooter">
            <button id="sdEditBtn" class="btn btn-primary" style="width:100%;">Open Execution Workspace to Update</button>
        </div>
    </div>
</div>

<div id="iframeModal" class="modal">
    <div class="modal-content" style="width: 95%; max-width: 1600px; height: 95vh; padding: 0; display: flex; flex-direction: column; overflow: hidden; background: var(--bg-primary);">
        <div style="padding: 1rem 1.5rem; background: var(--bg-panel); border-bottom: 1px solid var(--border-glass); display: flex; justify-content: space-between; align-items: center;">
            <h2 id="iframeModalTitle" style="margin:0; color: var(--primary-color); font-size: 1.25rem;">Workspace</h2>
            <div style="display:flex; gap: 15px; align-items:center;">
                <a id="iframeExternalLink" href="#" target="_blank" class="btn btn-sm btn-secondary" style="margin:0;">Open in Full Tab</a>
                <span class="close-modal" onclick="closeIframeModal()" style="font-size: 2rem; line-height:1; float:none;">&times;</span>
            </div>
        </div>
        <div id="iframeLoader" style="padding: 2rem; text-align: center; color: var(--text-muted);">Loading workspace...</div>
        <iframe id="mainIframe" style="flex: 1; width: 100%; border: none; background: var(--bg-primary); display: none;"></iframe>
    </div>
</div>

<script>
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.onclick = function(e) { if (e.target.classList.contains('modal')) e.target.style.display = "none"; }

function openIframeModal(id, name, targetFile) {
    let url = targetFile + '?project_id=' + id + '&modal=1';
    
    document.getElementById('iframeModalTitle').textContent = 'Execution Workspace: ' + name;
    document.getElementById('iframeExternalLink').href = targetFile + '?project_id=' + id;
    
    const iframe = document.getElementById('mainIframe');
    const loader = document.getElementById('iframeLoader');
    
    iframe.style.display = 'none';
    loader.style.display = 'block';
    iframe.src = url;
    
    iframe.onload = function() {
        loader.style.display = 'none';
        iframe.style.display = 'block';
    };
    
    document.getElementById('iframeModal').style.display = 'block';
    document.body.style.overflow = 'hidden'; 
}

function closeIframeModal() {
    document.getElementById('iframeModal').style.display = 'none';
    document.getElementById('mainIframe').src = '';
    document.body.style.overflow = 'auto';
    window.location.reload(); 
}

function openOhsaInfoModal(data) {
    document.getElementById('ohsaInfoTitle').textContent = 'Safety: ' + data.name;
    const badge = document.getElementById('ohsaInfoBadge');
    badge.textContent = data.status;
    if (data.status === 'Red') { badge.style.background = 'rgba(239, 68, 68, 0.2)'; badge.style.color = '#ef4444'; badge.style.border = '1px solid #ef4444'; }
    else if (data.status === 'Yellow') { badge.style.background = 'rgba(245, 158, 11, 0.2)'; badge.style.color = '#f59e0b'; badge.style.border = '1px solid #f59e0b'; }
    else if (data.status === 'Green') { badge.style.background = 'rgba(34, 197, 94, 0.2)'; badge.style.color = '#22c55e'; badge.style.border = '1px solid #22c55e'; }
    else { badge.textContent = 'N/A'; badge.style.background = 'rgba(255, 255, 255, 0.1)'; badge.style.color = '#9ca3af'; badge.style.border = '1px solid #4b5563'; }
    document.getElementById('ohsaInfoComments').textContent = data.comments || 'No active comments or warnings for this site.';
    document.getElementById('ohsaInfoModal').style.display = 'block';
}

function openMobModal(pId, pName, type, currentStatus) {
    document.getElementById('mobProjectId').value = pId;
    document.getElementById('mobType').value = type;
    document.getElementById('mobModalTitle').textContent = (type === 'demo' ? 'Demolition: ' : 'Excavation: ') + pName;
    document.getElementById('mobStatus').value = currentStatus || 'Pending';
    document.getElementById('mobUpdateModal').style.display = 'block';
}

function openConstFinModal(type, project) {
    document.getElementById('sdModalTitle').textContent = (type === 'const' ? 'Construction: ' : 'Finishes: ') + project.name;
    let html = '';
    if (!project.detailed_blocks || project.detailed_blocks.length === 0) {
        html = '<div style="color: var(--text-muted); font-style: italic; text-align: center; padding: 2rem;">No blocks or levels defined for this project.</div>';
    } else {
        project.detailed_blocks.forEach(b => {
            html += `<div style="background: var(--bg-primary); border: 1px solid var(--border-glass); border-radius: 8px; padding: 1.2rem; margin-bottom: 1rem; border-left: 3px solid var(--primary-color);">
                <h4 style="margin: 0 0 1rem 0; color: var(--primary-color); border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem; display: flex; justify-content: space-between;">
                    ${b.name} <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;">Overall: <span style="color:#fff; font-weight:bold;">${b.master_finishes}</span></span>
                </h4>`;
            if (!b.levels || b.levels.length === 0) {
                html += `<div style="font-size: 0.85rem; color: var(--text-muted); font-style: italic;">No levels added.</div>`;
            } else {
                html += `<table style="width: 100%; font-size: 0.85rem; border-collapse: collapse;"><tbody>`;
                b.levels.forEach(lvl => {
                    let statusText = type === 'const' ? lvl.const_status : lvl.fin_status;
                    let pctText = (type === 'const' && lvl.const_status === 'In Progress' && lvl.const_pct > 0) ? `<span style="color:var(--primary-color); font-weight:bold; margin-left: 5px;">${lvl.const_pct}%</span>` : '';
                    let colorCode = (statusText === 'Complete') ? '#22c55e' : (statusText === 'In Progress' ? '#f59e0b' : '#9ca3af');
                    html += `<tr><td style="padding: 0.5rem 0; color: var(--text-secondary); border-bottom: 1px solid rgba(255,255,255,0.02);">${lvl.name}</td>
                        <td style="padding: 0.5rem 0; text-align: right; border-bottom: 1px solid rgba(255,255,255,0.02); font-weight: 600; color: ${colorCode};">${statusText}${pctText}</td></tr>`;
                });
                html += `</tbody></table>`;
            }
            html += `</div>`;
        });
    }
    document.getElementById('sdModalBody').innerHTML = html;
    
    const btn = document.getElementById('sdEditBtn');
    if (<?= $canUpdateStatus ? 'true' : 'false' ?>) {
        btn.style.display = 'inline-block';
        btn.onclick = function() {
            closeModal('statusDetailModal');
            openIframeModal(project.id, project.name, 'mobilisation_detail.php');
        };
    } else { btn.style.display = 'none'; }
    document.getElementById('statusDetailModal').style.display = 'block';
}

const allSubs = <?= json_encode($subs) ?>;
let selectedTagIds = []; 

function renderTags() {
    const box = document.getElementById('tagBox');
    const searchInput = document.getElementById('tagSearch');
    const hiddenContainer = document.getElementById('tagHiddenInputs');
    if (!box || !hiddenContainer) return;
    
    box.querySelectorAll('.tag-pill').forEach(el => el.remove());
    hiddenContainer.innerHTML = '';
    
    selectedTagIds.forEach(id => {
        const sub = allSubs.find(c => c.id == id);
        if (sub) {
            const pill = document.createElement('div');
            pill.className = 'tag-pill';
            pill.innerHTML = `<span>${sub.name}</span><span class="remove-tag" onclick="removeTag(${sub.id}, event)">&times;</span>`;
            box.insertBefore(pill, searchInput);
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'sub_finishes[]';
            input.value = sub.id;
            hiddenContainer.appendChild(input);
        }
    });
}

function removeTag(id, e) {
    e.stopPropagation();
    selectedTagIds = selectedTagIds.filter(tagId => tagId != id);
    renderTags();
    document.getElementById('tagSearch').focus();
}

function initTagSelector() {
    const searchInput = document.getElementById('tagSearch');
    const dropdown = document.getElementById('tagDropdown');
    const container = document.querySelector('.tag-input-container');
    if (!searchInput) return;

    function filterOptions() {
        const val = searchInput.value.toLowerCase();
        dropdown.innerHTML = '';
        let count = 0;
        
        allSubs.forEach(sub => {
            if (!selectedTagIds.includes(sub.id.toString()) && sub.name.toLowerCase().includes(val)) {
                count++;
                const opt = document.createElement('div');
                opt.className = 'tag-option';
                opt.textContent = sub.name;
                opt.onclick = (e) => {
                    e.stopPropagation();
                    selectedTagIds.push(sub.id.toString());
                    searchInput.value = '';
                    renderTags();
                    dropdown.style.display = 'none';
                    searchInput.focus();
                };
                dropdown.appendChild(opt);
            }
        });
        
        if (count === 0) {
            dropdown.innerHTML = '<div class="tag-empty">No unselected contractors match your search.</div>';
        }
        dropdown.style.display = 'block';
    }

    searchInput.addEventListener('input', filterOptions);
    searchInput.addEventListener('focus', filterOptions);
    
    document.addEventListener('click', (e) => {
        if (!container.contains(e.target)) dropdown.style.display = 'none';
    });
}

function openAssignModal(data) {
    document.getElementById('modalProjectId').value = data.id;
    document.getElementById('modalProjectName').textContent = 'Assign Team: ' + data.name;
    document.getElementById('modalPmConst').value = data.pm_construction_id || '';
    document.getElementById('modalPmFin').value = data.pm_finishes_id || '';
    document.getElementById('modalSubDemo').value = data.sub_demolition_id || '';
    document.getElementById('modalSubExc').value = data.sub_excavation_id || '';
    document.getElementById('modalSubConst').value = data.sub_construction_id || '';
    
    selectedTagIds = data.sub_finishes_ids ? data.sub_finishes_ids.split(',') : [];
    renderTags();
    
    document.getElementById('assignModal').style.display = 'block';
}

document.addEventListener('DOMContentLoaded', initTagSelector);
</script>

<?php require_once 'footer.php'; ?>
