<?php
require_once 'init.php';
require_once 'session-check.php';
require_once __DIR__ . '/includes/pm_filter_logic.php';

if (!hasPermission('view_projects') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = ''; $error = '';
$canAssignTeam = hasPermission('edit_project_details') || isAdmin();
$canUpdateStatus = hasPermission('update_project_status') || isAdmin();
$canEditSchedule = hasPermission('edit_project_schedule') || isAdmin();
$isLegalRep = isLegalRepresentative();

// ==========================================
// HANDLE POST ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'assign_team' && $canAssignTeam) {
        try {
            $pId = (int)$_POST['project_id'];
            if (!hasProjectAccess($pdo, $pId)) { throw new Exception('Access denied to this project.'); }
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
            if (!hasProjectAccess($pdo, $pId)) { throw new Exception('Access denied to this project.'); }
            $type = $_POST['mob_type'];
            $status = $_POST['status'];
            $col = ($type === 'demo') ? 'demo_status' : 'excavation_status';
            
            $stmt = $pdo->prepare("INSERT IGNORE INTO project_mobilisation (project_id) VALUES (?)");
            $stmt->execute([$pId]);

            $stmt = $pdo->prepare("UPDATE project_mobilisation SET $col = ? WHERE project_id = ?");
            $stmt->execute([$status, $pId]);
            $message = "Mobilisation status updated successfully!";
        } catch (PDOException $e) { $error = "Error updating status: " . $e->getMessage(); }
        catch (Exception $e) { $error = $e->getMessage(); }
    }

    if ($_POST['action'] === 'update_schedule' && $canEditSchedule) {
        try {
            $pId = (int)$_POST['project_id'];
            if (!hasProjectAccess($pdo, $pId)) { throw new Exception('Access denied to this project.'); }
            $proj = $pdo->prepare("SELECT type, finishlevel FROM projects WHERE id = ?");
            $proj->execute([$pId]);
            $projRow = $proj->fetch(PDO::FETCH_ASSOC);
            if (!$projRow || ($projRow['type'] ?? '') !== 'in-house') {
                throw new Exception('Delivery schedule applies to in-house projects only.');
            }
            $stmt = $pdo->prepare("
                INSERT INTO project_delivery_schedule
                (project_id, planned_shell_date, forecast_shell_date, actual_shell_date,
                 planned_finishes_date, forecast_finishes_date, actual_finishes_date, finishes_scope, notes, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                planned_shell_date=VALUES(planned_shell_date), forecast_shell_date=VALUES(forecast_shell_date),
                actual_shell_date=VALUES(actual_shell_date), planned_finishes_date=VALUES(planned_finishes_date),
                forecast_finishes_date=VALUES(forecast_finishes_date), actual_finishes_date=VALUES(actual_finishes_date),
                finishes_scope=VALUES(finishes_scope), notes=VALUES(notes), updated_by=VALUES(updated_by)
            ");
            $emptyDate = fn($k) => empty($_POST[$k]) ? null : $_POST[$k];
            $stmt->execute([
                $pId,
                $emptyDate('planned_shell_date'), $emptyDate('forecast_shell_date'), $emptyDate('actual_shell_date'),
                $emptyDate('planned_finishes_date'), $emptyDate('forecast_finishes_date'), $emptyDate('actual_finishes_date'),
                $_POST['finishes_scope'] ?? $projRow['finishlevel'],
                trim($_POST['schedule_notes'] ?? ''),
                getCurrentUserId()
            ]);
            $message = 'Delivery schedule updated successfully!';
        } catch (PDOException $e) { $error = 'Error updating schedule: ' . $e->getMessage(); }
        catch (Exception $e) { $error = $e->getMessage(); }
    }
}

// 1. Fetch available PMs and Subcontractors
$pms = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'project_manager' AND is_active = 'Yes' ORDER BY first_name")->fetchAll();
$subs = $pdo->query("SELECT id, name FROM subcontractors ORDER BY name")->fetchAll();

// 2. Define Allowed Stages (execution matrix — excludes Tracking / Feasibility / Permit)
$allowedStages = ['Mobilisation', 'Demolition', 'Excavation', 'Construction', 'Finishes', 'Compliance', 'Condominium', 'Handed Over'];
$hiddenStages = ['Tracking', 'Feasibility', 'Permit'];

// 3. GET FILTERS AND SORTS
$filterDefaults = ['filter_type' => $isLegalRep ? 'in-house' : 'in-house'];
$filters = pmGetFilterParams($filterDefaults);
$filterStage = $filters['filter_stage'];
$filterType = $isLegalRep ? 'in-house' : $filters['filter_type'];
$filterFinish = $filters['filter_finish'];
$filterCity = $filters['filter_city'];
$filterClient = $filters['filter_client'];
$filterIsland = $filters['filter_island'];
$filterPm = $filters['filter_pm'];
$filterSub = $filters['filter_sub'];

$sortBy = $_GET['sort'] ?? 'name';
$sortOrder = $_GET['order'] ?? 'ASC';
$allowedSorts = ['name', 'city', 'client', 'stage', 'progress'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'name';
$stageSortOrder = ['Mobilisation' => 1, 'Demolition' => 2, 'Excavation' => 3, 'Construction' => 4, 'Finishes' => 5, 'Compliance' => 6, 'Condominium' => 7, 'Handed Over' => 8];
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

    $paStmt = $pdo->prepare("
        SELECT pan.project_id, pan.pa_number, pan.pa_status, arch.firm_name AS arch_firm
        FROM project_pa_numbers pan
        LEFT JOIN professionals arch ON arch.id = pan.architect_id
        WHERE pan.project_id IN ($placeholders)
        ORDER BY pan.pa_number ASC
    ");
    $paStmt->execute($projectIds);
    foreach ($paStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $paData[$row['project_id']][] = $row;
    }

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
$projectIdsForStage = array_column($projectsRaw, 'id');
$stagesBatch = getAccurateProjectStagesBatch($pdo, $projectIdsForStage);
$schedulesBatch = getDeliverySchedulesBatch($pdo, $projectIdsForStage);

$matrixProjectsActive = [];
$matrixProjectsArchive = [];
foreach ($projectsRaw as $p) {
    if ($isLegalRep && ($p['type'] ?? '') !== 'in-house') continue;
    $stage = $stagesBatch[$p['id']] ?? getAccurateProjectStage($pdo, $p['id']);

    if (in_array($stage, $hiddenStages, true)) continue;

    $isArchive = pmIsArchiveProject($p, $stage);
    if (!$isArchive && ($p['project_status'] ?? 'Active') !== 'Active') continue;

    $paRecordsEarly = $paData[$p['id']] ?? [];
    $hasEndorsedPa = pmProjectHasEndorsedPa($paRecordsEarly);
    $isHandedOver = ($stage === 'Handed Over');

    if ($isArchive) {
        if ($filterStage !== 'all' && $stage !== $filterStage) continue;
    } else {
        if (!$hasEndorsedPa) continue;
        if (!in_array($stage, $allowedStages, true)) continue;
        if ($filterStage !== 'all' && $stage !== $filterStage) continue;
    }

    if ($isArchive || in_array($stage, $allowedStages, true)) {
        $p['stage'] = $stage;
        $p['card_mode'] = $isArchive ? 'summary' : 'full';
        $p['demo_status'] = $mobData[$p['id']]['demo_status'] ?? 'Pending';
        $p['exc_status'] = $mobData[$p['id']]['excavation_status'] ?? 'Pending';
        $p['safety_status'] = $ohsaData[$p['id']]['safety_status'] ?? 'N/A';
        $p['safety_comments'] = $ohsaData[$p['id']]['safety_comments'] ?? '';
        $p['pa_records'] = $paData[$p['id']] ?? [];
        $p['pa_numbers'] = !empty($p['pa_records'])
            ? implode(', ', array_map(fn($pa) => pmFormatPaDisplay($pa['pa_number']), $p['pa_records']))
            : null;

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

        $levelPcts = [];
        $hasProgressData = false;
        if (isset($blockAggData[$p['id']])) {
            foreach ($blockAggData[$p['id']] as $row) {
                if (!empty($row['level_id'])) {
                    $levelPcts[] = (float)($row['construction_pct'] ?? 0);
                    $hasProgressData = true;
                } else {
                    $levelPcts[] = (float)($row['progress'] ?? 0);
                    $hasProgressData = true;
                }
            }
        }
        if ($isHandedOver) {
            $p['progress_pct'] = 100;
            $p['sort_progress'] = 100;
        } elseif ($hasProgressData && !empty($levelPcts)) {
            $p['progress_pct'] = (int)round(array_sum($levelPcts) / count($levelPcts));
            $p['sort_progress'] = $p['progress_pct'];
        } else {
            $p['progress_pct'] = 0;
            $p['sort_progress'] = $stageSortOrder[$stage] ?? 0;
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
        
        if ($isArchive) {
            $matrixProjectsArchive[] = $p;
        } else {
            $matrixProjectsActive[] = $p;
        }
    }
}

$applyMatrixListFilters = function (array $list) use ($filterType, $filterFinish, $filterCity, $filterClient, $filterPm, $filterSub, $filterIsland) {
    if ($filterType !== 'all') $list = array_filter($list, fn($p) => $p['type'] === $filterType);
    if ($filterFinish !== 'all') $list = array_filter($list, fn($p) => ($p['finishlevel'] ?? '') === $filterFinish);
    if ($filterCity !== 'all') $list = array_filter($list, fn($p) => $p['city'] === $filterCity);
    if ($filterClient !== 'all') {
        $list = array_filter($list, fn($p) => pmMatchesClientFilter($p, $filterClient));
    }
    if ($filterPm !== 'all') { $list = array_filter($list, fn($p) => ($p['pm_construction_id'] == $filterPm || $p['pm_finishes_id'] == $filterPm)); }
    if ($filterSub !== 'all') { $list = array_filter($list, fn($p) => ($p['sub_demolition_id'] == $filterSub || $p['sub_excavation_id'] == $filterSub || $p['sub_construction_id'] == $filterSub || strpos(','.$p['sub_finishes_ids'].',', ','.$filterSub.',') !== false)); }
    if ($filterIsland !== 'all') $list = array_filter($list, fn($p) => $p['island'] === $filterIsland);
    return array_values($list);
};

$sortMatrixList = function (array $list) use ($sortBy, $sortOrder, $stageSortOrder) {
    usort($list, function($a, $b) use ($sortBy, $sortOrder, $stageSortOrder) {
        $valA = ''; $valB = '';
        if ($sortBy === 'client') {
            $valA = $a['client_name'] ?? '';
            $valB = $b['client_name'] ?? '';
        } elseif ($sortBy === 'city') {
            $valA = $a['city'] ?? '';
            $valB = $b['city'] ?? '';
        } elseif ($sortBy === 'stage') {
            $valA = $stageSortOrder[$a['stage'] ?? ''] ?? 99;
            $valB = $stageSortOrder[$b['stage'] ?? ''] ?? 99;
        } elseif ($sortBy === 'progress') {
            $valA = (int)($a['sort_progress'] ?? 0);
            $valB = (int)($b['sort_progress'] ?? 0);
        } else {
            $valA = $a[$sortBy] ?? '';
            $valB = $b[$sortBy] ?? '';
        }

        if ($valA == $valB) return 0;
        if (is_numeric($valA) && is_numeric($valB)) { $comp = $valA <=> $valB; }
        else { $comp = strcasecmp((string)$valA, (string)$valB); }

        return $sortOrder === 'ASC' ? $comp : -$comp;
    });
    return array_values($list);
};

$matrixProjectsActive = $applyMatrixListFilters($matrixProjectsActive);
$matrixProjectsArchive = $applyMatrixListFilters($matrixProjectsArchive);

foreach ($matrixProjectsActive as &$mp) {
    $mp['schedule'] = $schedulesBatch[$mp['id']] ?? null;
}
unset($mp);

foreach ($matrixProjectsArchive as &$mp) {
    $mp['schedule'] = $schedulesBatch[$mp['id']] ?? null;
}
unset($mp);

$matrixProjectsActive = $sortMatrixList($matrixProjectsActive);
$matrixProjectsArchive = $sortMatrixList($matrixProjectsArchive);

$matrixProjects = $matrixProjectsActive;

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

function renderScheduleColumn($project, $schedule, $column, $canEditSchedule) {
    if (($project['type'] ?? '') !== 'in-house') {
        return '<div class="normal-cell schedule-cell" style="justify-content:center;"><span class="rag-neutral">Capital</span></div>';
    }
    $s = $schedule ?? [];
    if ($column === 'finishes' && in_array($project['finishlevel'] ?? '', ['Shell', 'Shell (No Finishes)', null, ''])) {
        return '<div class="normal-cell schedule-cell" style="justify-content:center;"><span class="rag-neutral">N/A</span></div>';
    }
    $keys = $column === 'shell'
        ? ['planned_shell_date', 'forecast_shell_date', 'actual_shell_date']
        : ['planned_finishes_date', 'forecast_finishes_date', 'actual_finishes_date'];
    $rag = getScheduleRagClass($s[$keys[0]] ?? null, $s[$keys[1]] ?? null, $s[$keys[2]] ?? null);
    $inner = '<div class="schedule-cell"><span class="schedule-rag-dot ' . $rag . '"></span>'
        . '<div class="sch-row"><span class="sch-label">Plan</span>' . formatScheduleDate($s[$keys[0]] ?? null) . '</div>'
        . '<div class="sch-row"><span class="sch-label">Fcst</span>' . formatScheduleDate($s[$keys[1]] ?? null) . '</div>'
        . '<div class="sch-row"><span class="sch-label">Act</span>' . formatScheduleDate($s[$keys[2]] ?? null) . '</div></div>';
    if ($canEditSchedule) {
        $payload = htmlspecialchars(json_encode(['id' => $project['id'], 'name' => $project['name'], 'schedule' => $s, 'finishlevel' => $project['finishlevel'] ?? '']), ENT_QUOTES);
        return '<div class="clickable-cell" onclick=\'openScheduleModal(' . $payload . ')\' style="flex-direction:column;align-items:flex-start;">' . $inner . '<span class="edit-icon">✎</span></div>';
    }
    return '<div class="normal-cell" style="align-items:flex-start;">' . $inner . '</div>';
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
        $pId = (int)$pId;
        $nameJs = json_encode((string)$pName, JSON_UNESCAPED_UNICODE);
        $typeJs = json_encode((string)$type, JSON_UNESCAPED_UNICODE);
        $statusJs = json_encode((string)$status, JSON_UNESCAPED_UNICODE);
        return "<div onclick='openMobModal($pId, $nameJs, $typeJs, $statusJs)' class='clickable-cell' style='justify-content:center;' title='Click to Update Phase'>$badgeHtml<span class='edit-icon'>✎</span></div>";
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

function renderMatrixProjectCard(array $p, $pdo, $canUpdateStatus, $canEditSchedule, $isLegalRep, $canAssignTeam, array $subs) {
    $pJson = htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8');
    $ohsaJson = htmlspecialchars(json_encode(['name' => $p['name'], 'status' => $p['safety_status'], 'comments' => $p['safety_comments']]), ENT_QUOTES, 'UTF-8');
    $ohsaClass = strtolower($p['safety_status'] ?? 'na');
    $ohsaIcon = match ($p['safety_status'] ?? '') {
        'Green' => '🟢',
        'Yellow' => '🟡',
        'Red' => '🔴',
        default => '⚪',
    };
    $isSummary = ($p['card_mode'] ?? 'full') === 'summary';
    $sched = $p['schedule'] ?? null;
    $pNameJs = htmlspecialchars(json_encode($p['name']), ENT_QUOTES, 'UTF-8');
    ?>
    <article class="project-card<?= $isSummary ? ' card-summary' : '' ?>">
        <div class="card-header" onclick="openIframeModal(<?= $p['id'] ?>, <?= $pNameJs ?>, 'mobilisation_detail.php')" title="Open Execution Workspace">
            <div class="card-title"><?= htmlspecialchars($p['name']) ?></div>
            <div class="card-client"><?= htmlspecialchars($p['client_name'] ?? 'No Client') ?><?= !empty($p['city']) ? ' · ' . htmlspecialchars($p['city']) : '' ?></div>
            <div class="card-meta">
                <span class="info-tag"><?= htmlspecialchars($p['stage']) ?></span>
                <?php if (!$isSummary): ?>
                <span class="info-tag">Fin: <?= htmlspecialchars($p['finishlevel'] ?? 'N/A') ?></span>
                <span class="info-tag"><?= (int)($p['progress_pct'] ?? 0) ?>% built</span>
                <span class="info-tag ohsa-<?= $ohsaClass ?>" onclick="event.stopPropagation(); openOhsaInfoModal(<?= $ohsaJson ?>);" title="View Safety Comments"><?= $ohsaIcon ?> OHSA</span>
                <?php else: ?>
                <span class="card-summary-badge">Handed Over</span>
                <?php endif; ?>
            </div>
            <?php if ($isSummary): ?>
            <div class="card-summary-row">
                <?php if (!empty($sched['actual_finishes_date'])): ?>
                    <span>Finishes: <?= formatScheduleDate($sched['actual_finishes_date']) ?></span>
                <?php elseif (!empty($sched['actual_shell_date'])): ?>
                    <span>Shell: <?= formatScheduleDate($sched['actual_shell_date']) ?></span>
                <?php endif; ?>
                <?php if (!empty($p['pa_numbers'])): ?>
                    <span>PA: <?= htmlspecialchars($p['pa_numbers']) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($isSummary && canEditProjectDetails($pdo, $p['id'])): ?>
        <div class="card-section card-summary-actions">
            <button type="button" class="btn btn-sm btn-secondary" onclick="event.stopPropagation(); openEditProjectModal(<?= (int)$p['id'] ?>, <?= $pNameJs ?>);">Edit Project</button>
        </div>
        <?php endif; ?>

        <?php if (!$isSummary): ?>
        <div class="card-section">
            <div class="card-section-title">Permit Application</div>
            <?php if (!empty($p['pa_records'])): ?>
                <div class="card-pa-list">
                    <?php foreach ($p['pa_records'] as $pa): ?>
                        <div class="card-pa-item"><?= pmRenderPaChip($pa) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card-empty">No PA number assigned</div>
            <?php endif; ?>
        </div>

        <div class="card-section">
            <div class="card-section-title">Execution Status</div>
            <div class="card-execution">
                <div class="exec-item">
                    <span class="exec-label">Demo</span>
                    <?= renderDemoExcBadge(renderStatusBadge($p['demo_status']), $p['id'], $p['name'], 'demo', $p['demo_status'], $canUpdateStatus) ?>
                </div>
                <div class="exec-item">
                    <span class="exec-label">Exc</span>
                    <?= renderDemoExcBadge(renderStatusBadge($p['exc_status']), $p['id'], $p['name'], 'exc', $p['exc_status'], $canUpdateStatus) ?>
                </div>
                <div class="exec-item">
                    <span class="exec-label">Const</span>
                    <?= renderConstFinBadge(renderStatusBadge($p['const_status']), 'const', $pJson, $canUpdateStatus) ?>
                </div>
                <div class="exec-item">
                    <span class="exec-label">Fin</span>
                    <?= renderConstFinBadge(renderStatusBadge($p['fin_status']), 'fin', $pJson, $canUpdateStatus) ?>
                </div>
            </div>
        </div>

        <div class="card-section">
            <div class="card-section-title">Delivery Milestones</div>
            <div class="card-schedule-grid">
                <div>
                    <div style="font-size:0.65rem;color:#6366f1;font-weight:700;margin-bottom:4px;">Construction Complete</div>
                    <?= renderScheduleColumn($p, $p['schedule'] ?? null, 'shell', $canEditSchedule && !$isLegalRep) ?>
                </div>
                <div>
                    <div style="font-size:0.65rem;color:#22c55e;font-weight:700;margin-bottom:4px;">Finishes Complete</div>
                    <?= renderScheduleColumn($p, $p['schedule'] ?? null, 'finishes', $canEditSchedule && !$isLegalRep) ?>
                </div>
            </div>
        </div>

        <div class="card-section">
            <div class="card-section-title">Team</div>
            <div class="card-team-grid">
                <div class="card-team-block">
                    <div style="font-size:0.65rem;color:var(--text-muted);font-weight:700;margin-bottom:4px;">Project Managers</div>
                    <?= renderPMsCell($p['pm_const_name'], $p['pm_fin_name'], $pJson, $canAssignTeam) ?>
                </div>
                <div class="card-team-block">
                    <div style="font-size:0.65rem;color:var(--text-muted);font-weight:700;margin-bottom:4px;">Subcontractors</div>
                    <?= renderAllSubsCell($p['sub_demo_name'], $p['sub_exc_name'], $p['sub_const_name'], $p['sub_finishes_ids'] ?? '', $subs, $pJson, $canAssignTeam) ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </article>
    <?php
}

$pageTitle = $isLegalRep ? 'Project Status' : 'Project Execution Matrix';
require_once 'header.php';
?>
<script src="/assets/js/pm-filters.js?v=<?= time() ?>"></script>

<style>
/* CARD GRID LAYOUT */
.matrix-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 1rem; }
.project-card { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-md); overflow: hidden; display: flex; flex-direction: column; transition: border-color 0.2s, box-shadow 0.2s; }
.project-card:hover { border-color: rgba(99, 102, 241, 0.35); box-shadow: var(--shadow-sm); }
.card-header { padding: 1rem 1rem 0.75rem; border-bottom: 1px solid var(--border-glass); cursor: pointer; }
.card-header:hover { background: rgba(255,255,255,0.02); }
.card-title { font-weight: 700; color: var(--primary-color); font-size: 1rem; line-height: 1.3; margin-bottom: 0.25rem; }
.card-client { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.6rem; }
.card-meta { display: flex; flex-wrap: wrap; gap: 6px; }
.card-section { padding: 0.75rem 1rem; border-bottom: 1px solid rgba(255,255,255,0.04); }
.card-section:last-child { border-bottom: none; }
.card-section-title { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); font-weight: 700; margin-bottom: 0.5rem; }
.card-pa-list { display: flex; flex-direction: column; gap: 4px; }
.card-pa-item { font-size: 0.8rem; line-height: 1.4; }
.pa-link { color: var(--primary-color); text-decoration: none; font-weight: 600; }
.pa-link:hover { text-decoration: underline; }
.pa-status-chip { display: inline-block; margin-left: 6px; padding: 1px 6px; border-radius: 4px; font-size: 0.68rem; font-weight: 600; background: rgba(139, 92, 246, 0.15); color: #c4b5fd; border: 1px solid rgba(139, 92, 246, 0.25); }
.card-execution { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; }
.exec-item { text-align: center; }
.exec-label { display: block; font-size: 0.6rem; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; font-weight: 600; }
.exec-item .clickable-cell, .exec-item .normal-cell { padding: 0.35rem 0.25rem; justify-content: center; min-height: 36px; }
.card-schedule-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.card-schedule-grid .clickable-cell, .card-schedule-grid .normal-cell { padding: 0.5rem; align-items: flex-start; font-size: 0.72rem; }
.card-team-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.card-team-block { font-size: 0.75rem; line-height: 1.45; }
.card-team-block .clickable-cell, .card-team-block .normal-cell { padding: 0; align-items: flex-start; }
.card-empty { color: var(--text-muted); font-size: 0.8rem; font-style: italic; }
.card-sort-bar { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; align-items: center; }
.card-sort-bar span { font-size: 0.8rem; color: var(--text-muted); font-weight: 600; }
.card-sort-link { padding: 0.35rem 0.65rem; border-radius: 6px; font-size: 0.75rem; text-decoration: none; color: var(--text-secondary); border: 1px solid var(--border-glass); background: rgba(255,255,255,0.02); }
.card-sort-link.active, .card-sort-link:hover { color: #fff; border-color: var(--primary-color); background: rgba(99, 102, 241, 0.2); }
.project-card.card-summary { border-color: rgba(107, 114, 128, 0.35); }
.project-card.card-summary .card-header { cursor: pointer; }
.card-summary-row { display: flex; flex-wrap: wrap; gap: 8px; font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.35rem; }
.card-summary-badge { font-size: 0.65rem; padding: 2px 8px; border-radius: 4px; background: rgba(34, 197, 94, 0.12); color: #86efac; border: 1px solid rgba(34, 197, 94, 0.25); font-weight: 700; text-transform: uppercase; }
.card-summary-actions { padding: 0.65rem 1rem; border-top: 1px solid rgba(255,255,255,0.04); }
.pm-archive-section { margin-top: 2rem; padding: 1rem 1.25rem; border-radius: var(--radius-lg); border: 1px solid rgba(107, 114, 128, 0.35); background: rgba(15, 23, 42, 0.35); }
.pm-archive-section > summary { cursor: pointer; font-weight: 700; color: var(--text-secondary); font-size: 0.95rem; list-style: none; display: flex; align-items: center; gap: 0.5rem; }
.pm-archive-section > summary::-webkit-details-marker { display: none; }
.pm-archive-section > summary::before { content: '▸'; display: inline-block; transition: transform 0.2s; color: var(--text-muted); }
.pm-archive-section[open] > summary::before { transform: rotate(90deg); }
.pm-archive-section .archive-hint { font-size: 0.8rem; color: var(--text-muted); margin: 0.75rem 0 1rem; }

.info-tag { font-size: 0.65rem; padding: 2px 6px; border-radius: 4px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: var(--text-secondary); white-space: nowrap; }
.info-tag.ohsa-green { color: #22c55e; border-color: rgba(34,197,94,0.3); background: rgba(34,197,94,0.1); cursor: pointer; }
.info-tag.ohsa-yellow { color: #f59e0b; border-color: rgba(245,158,11,0.3); background: rgba(245,158,11,0.1); cursor: pointer; }
.info-tag.ohsa-red { color: #ef4444; border-color: rgba(239,68,68,0.3); background: rgba(239,68,68,0.1); cursor: pointer; }
.info-tag.ohsa-green:hover, .info-tag.ohsa-yellow:hover, .info-tag.ohsa-red:hover { filter: brightness(1.2); }

/* INTERACTIONS */
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

.schedule-cell { font-size: 0.72rem; line-height: 1.5; }
.schedule-cell .sch-row { display: flex; justify-content: space-between; gap: 6px; padding: 2px 0; border-bottom: 1px solid rgba(255,255,255,0.04); }
.schedule-cell .sch-label { color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.65rem; }
.rag-green { color: #22c55e; } .rag-amber { color: #f59e0b; } .rag-red { color: #ef4444; } .rag-neutral { color: var(--text-muted); }
.schedule-rag-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
.schedule-rag-dot.rag-green { background: #22c55e; } .schedule-rag-dot.rag-amber { background: #f59e0b; }
.schedule-rag-dot.rag-red { background: #ef4444; } .schedule-rag-dot.rag-neutral { background: #6b7280; }

@media print {
    @page { size: landscape; margin: 1cm; }
    body { background: #fff !important; color: #000 !important; }
    .header, .sidebar, .filters-section, .edit-icon, .close-modal, .modal, .card-sort-bar { display: none !important; }
    button, .btn { display: none !important; }
    .main-container { padding: 0 !important; max-width: 100% !important; margin: 0 !important; }
    .matrix-cards { display: block !important; }
    .project-card { break-inside: avoid; margin-bottom: 1rem; border: 1px solid #d1d5db !important; box-shadow: none !important; }
    .badge, .info-tag, .pa-status-chip { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    h1.page-title { color: #000 !important; margin-bottom: 20px !important; }
}
</style>

<div class="main-container" style="max-width: 100%; padding: 1.5rem;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;"><?= $isLegalRep ? 'In-House Project Status' : 'Project Execution Matrix' ?></h1>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.25rem;">
                <?= $isLegalRep ? 'Read-only view of in-house project delivery milestones and execution status.' : 'Active projects with an endorsed permit (full detail) and completed handovers (summary). Tracking and pre-permit projects are hidden.' ?>
            </p>
        </div>
        <div>
            <button onclick="window.print()" class="btn" style="background: var(--primary-color); color: white; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-file-pdf"></i> Export PDF Report
            </button>
        </div>
    </div>

    <div class="filters-section" style="margin-bottom: 1.5rem;">
        <form method="GET" id="matrixFilters" class="pm-auto-filter">
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>">
            <input type="hidden" name="order" value="<?= htmlspecialchars($sortOrder) ?>">
            <div class="filters-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
                <div class="filter-group"><label>Stage</label><select name="filter_stage"><option value="all">All Stages</option><?php foreach ($allowedStages as $stg): ?><option value="<?= $stg ?>" <?= $filterStage === $stg ? 'selected' : '' ?>><?= $stg ?></option><?php endforeach; ?></select></div>
                <div class="filter-group"><label>Finish Req</label><select name="filter_finish"><option value="all">All Levels</option><option value="Shell" <?= $filterFinish === 'Shell' ? 'selected' : '' ?>>Shell</option><option value="Common Parts Only" <?= $filterFinish === 'Common Parts Only' ? 'selected' : '' ?>>Common Parts Only</option><option value="Semi Finished" <?= $filterFinish === 'Semi Finished' ? 'selected' : '' ?>>Semi Finished</option><option value="Finished" <?= $filterFinish === 'Finished' ? 'selected' : '' ?>>Finished</option></select></div>
                <?php if (!$isLegalRep): ?>
                <div class="filter-group"><label>Project Type</label><select name="filter_type"><option value="all">All Types</option><option value="in-house" <?= $filterType === 'in-house' ? 'selected' : '' ?>>In-House</option><option value="3rd-party" <?= $filterType === '3rd-party' ? 'selected' : '' ?>>3rd Party (Capital)</option></select></div>
                <?php endif; ?>
                <div class="filter-group"><label>Locality</label><select name="filter_city"><option value="all">All Localities</option><?php foreach ($cities as $city): ?><option value="<?= htmlspecialchars($city) ?>" <?= $filterCity === $city ? 'selected' : '' ?>><?= htmlspecialchars($city) ?></option><?php endforeach; ?></select></div>
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

    <?php
    $sortColumns = [
        'name' => 'Name',
        'city' => 'Locality',
        'client' => 'Client',
        'stage' => 'Stage',
        'progress' => 'Progress',
    ];
    ?>
    <div class="card-sort-bar">
        <span>Sort:</span>
        <?php foreach ($sortColumns as $col => $label): ?>
            <a href="<?= getSortUrl($col) ?>" class="card-sort-link<?= $sortBy === $col ? ' active' : '' ?>"><?= $label ?><?= getSortIndicator($col) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($matrixProjectsActive) && empty($matrixProjectsArchive)): ?>
        <div class="empty-state card" style="text-align:center;padding:2rem;color:var(--text-muted);">No projects found matching your filters.</div>
    <?php else: ?>
        <?php if (empty($matrixProjectsActive)): ?>
            <div class="empty-state card" style="text-align:center;padding:2rem;color:var(--text-muted);">No active projects found.</div>
        <?php else: ?>
            <div class="matrix-cards">
                <?php foreach ($matrixProjectsActive as $p) { renderMatrixProjectCard($p, $pdo, $canUpdateStatus, $canEditSchedule, $isLegalRep, $canAssignTeam, $subs); } ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($matrixProjectsArchive)): ?>
            <details class="pm-archive-section">
                <summary>Handed Over Archive (<?= count($matrixProjectsArchive) ?>)</summary>
                <p class="archive-hint">Completed projects are kept here for reference. For meter applications and engineering records, use the <a href="engineering.php">Engineering Hub</a>. To revive a project marked completed in error, use <strong>Edit Project</strong> and set status back to Active.</p>
                <div class="matrix-cards">
                    <?php foreach ($matrixProjectsArchive as $p) { renderMatrixProjectCard($p, $pdo, $canUpdateStatus, $canEditSchedule, $isLegalRep, $canAssignTeam, $subs); } ?>
                </div>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($canEditSchedule && !$isLegalRep): ?>
<div id="scheduleModal" class="modal">
    <div class="modal-content" style="max-width: 560px;">
        <span class="close-modal" onclick="closeModal('scheduleModal')">&times;</span>
        <h2 id="scheduleModalTitle" style="margin-top:0;color:var(--primary-color);">Delivery Schedule</h2>
        <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:1.5rem;">In-house delivery milestones — construction complete triggers legal sale contracts; finishes complete triggers final payment.</p>
        <form method="POST">
            <input type="hidden" name="action" value="update_schedule">
            <input type="hidden" name="project_id" id="schedProjectId">
            <input type="hidden" name="finishes_scope" id="schedFinishesScope">
            <h4 style="color:#6366f1;margin:1rem 0 0.5rem;">Construction Complete (Shell)</h4>
            <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr;gap:0.75rem;">
                <div class="form-group"><label>Planned</label><input type="date" name="planned_shell_date" id="schedPlanShell"></div>
                <div class="form-group"><label>Forecast</label><input type="date" name="forecast_shell_date" id="schedFcstShell"></div>
                <div class="form-group"><label>Actual</label><input type="date" name="actual_shell_date" id="schedActShell"></div>
            </div>
            <h4 style="color:#22c55e;margin:1rem 0 0.5rem;">Finishes Complete</h4>
            <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr;gap:0.75rem;">
                <div class="form-group"><label>Planned</label><input type="date" name="planned_finishes_date" id="schedPlanFin"></div>
                <div class="form-group"><label>Forecast</label><input type="date" name="forecast_finishes_date" id="schedFcstFin"></div>
                <div class="form-group"><label>Actual</label><input type="date" name="actual_finishes_date" id="schedActFin"></div>
            </div>
            <div class="form-group" style="margin-top:1rem;"><label>Notes</label><textarea name="schedule_notes" id="schedNotes" rows="2" style="width:100%;"></textarea></div>
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:1rem;">Save Schedule</button>
        </form>
    </div>
</div>
<?php endif; ?>

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

function openEditProjectModal(id, name) {
    const url = 'edit-project.php?id=' + id + '&modal=1';
    document.getElementById('iframeModalTitle').textContent = 'Edit Project: ' + name;
    document.getElementById('iframeExternalLink').href = 'edit-project.php?id=' + id;

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

window.addEventListener('message', function(event) {
    if (event.data === 'projectUpdated') {
        closeIframeModal();
    } else if (event.data === 'closeModal') {
        document.getElementById('iframeModal').style.display = 'none';
        document.getElementById('mainIframe').src = '';
        document.body.style.overflow = 'auto';
    }
});

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

function openScheduleModal(data) {
    document.getElementById('schedProjectId').value = data.id;
    document.getElementById('scheduleModalTitle').textContent = 'Delivery Schedule: ' + data.name;
    document.getElementById('schedFinishesScope').value = data.finishlevel || '';
    const s = data.schedule || {};
    document.getElementById('schedPlanShell').value = s.planned_shell_date || '';
    document.getElementById('schedFcstShell').value = s.forecast_shell_date || '';
    document.getElementById('schedActShell').value = s.actual_shell_date || '';
    document.getElementById('schedPlanFin').value = s.planned_finishes_date || '';
    document.getElementById('schedFcstFin').value = s.forecast_finishes_date || '';
    document.getElementById('schedActFin').value = s.actual_finishes_date || '';
    document.getElementById('schedNotes').value = s.notes || '';
    document.getElementById('scheduleModal').style.display = 'block';
}
</script>

<?php require_once 'footer.php'; ?>
