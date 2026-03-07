<?php
require_once 'init.php';
require_once 'session-check.php';

$projectId = $_GET['project_id'] ?? $_GET['projectid'] ?? null;
if (!$projectId) { header('Location: dashboard.php'); exit; }

if (!hasProjectAccess($pdo, $projectId)) { header('Location: dashboard.php?error=access_denied'); exit; }

$project = getProjectWithClient($pdo, $projectId);
if (!$project) { header('Location: dashboard.php'); exit; }

// Explicitly fetch all PA Numbers for this specific project
try {
    $paStmt = $pdo->prepare("SELECT pa_number FROM project_pa_numbers WHERE project_id = ?");
    $paStmt->execute([$projectId]);
    $fetchedPas = $paStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($fetchedPas) {
        $project['pa_numbers'] = $fetchedPas;
    }
} catch(PDOException $e) {}

if ($project['is_tracking'] == 1 && !hasPermission('view_tracking') && !isAdmin()) {
    header('Location: dashboard.php?error=access_denied'); exit;
}

$canUpdateStatus = canUpdateStatus($pdo, $projectId);
$canEditServices = hasPermission('edit_services') || isAdmin();
$disabledAttr = $canUpdateStatus ? '' : 'disabled';
$servicesDisabledAttr = $canEditServices ? '' : 'disabled';

$message = ''; $error = '';

// ==========================================
// HANDLE POST REQUESTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- NEW: LOG & ACTION MANAGEMENT ---
    if (isset($_POST['add_log'])) {
        $logMsg = trim($_POST['log_message'] ?? '');
        $assignedTo = !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null;
        $status = $assignedTo ? 'Action - Pending' : 'Info';
        
        if (!empty($logMsg)) {
            $stmt = $pdo->prepare("INSERT INTO project_logs (project_id, user_id, message, assigned_to, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$projectId, getCurrentUserId(), $logMsg, $assignedTo, $status]);
            header("Location: mobilisation_detail.php?project_id=$projectId#project-log"); exit;
        }
    }
    
    if (isset($_POST['close_action'])) {
        $logId = $_POST['log_id'];
        $stmt = $pdo->prepare("UPDATE project_logs SET status = 'Action - Closed', closed_at = NOW(), closed_by = ? WHERE id = ? AND project_id = ?");
        $stmt->execute([getCurrentUserId(), $logId, $projectId]);
        header("Location: mobilisation_detail.php?project_id=$projectId#project-log"); exit;
    }
    // ------------------------------------
    
    if (($_POST['action'] ?? null) === 'update_mobilisation' && $canUpdateStatus) {
        try {
            $updates = []; $values = [];
            $allowedFields = ['acquisition_complete', 'acquisition_date', 'archaeologist_assigned', 'change_of_applicant', 'geological_test', 'condition_report_contacts', 'condition_reports', 'method_statements', 'insurance_status', 'pavement_guarantee', 'wellbeing_guarantee', 'umbrella_guarantee', 'responsibility_form', 'mob_demolition', 'mob_excavation', 'mob_construction', 'demo_status', 'excavation_status'];
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== '') { $updates[] = "$field = ?"; $values[] = $_POST[$field]; }
            }
            if (!empty($updates)) {
                $values[] = $projectId;
                $pdo->prepare("UPDATE project_mobilisation SET " . implode(', ', $updates) . " WHERE project_id = ?")->execute($values);
                $message = 'BCA Mobilisation steps updated successfully!';
            }
        } catch (PDOException $e) { $error = 'Error: ' . $e->getMessage(); }
    }

    if (($_POST['action'] ?? null) === 'update_blocks' && $canUpdateStatus) {
        try {
            $pdo->beginTransaction();
            // 2. Update Blocks Data & Finishes Matrix
            if (isset($_POST['blocks']) && is_array($_POST['blocks'])) {
                $allowedFinishesFields = [
                    'fin_electrical', 'fin_plumbing', 'fin_pumps', 'fin_lifts', 'fin_substation', 'fin_septic', 'fin_sewer',
                    'fin_fire_detection', 'fin_fire_fighting', 'fin_fire_doors', 'fin_intercoms',
                    'fin_garden', 'fin_pool',
                    'fin_rend_facade', 'fin_rend_appogg', 'fin_rend_back', 'fin_rend_cp', 'fin_cladding',
                    'fin_marble_cp', 'fin_marble_sills', 'fin_wp_roof', 'fin_wp_shafts', 'fin_wp_ext',
                    'fin_gypsum_cp', 'fin_gypsum_facade',
                    'fin_cp_doors_win', 'fin_int_railings', 'fin_partitions',
                    'fin_water_tanks', 'fin_wp_balconies', 'fin_tile_balconies', 'fin_apt_fire_doors', 'fin_apt_doors_win', 'fin_ext_railings',
                    'fin_gar_rend_cp', 'fin_gar_rend', 'fin_gar_main_door', 'fin_gar_vent',
                    'fin_gar_ind_doors', 'fin_gar_win'
                ];
    
                foreach ($_POST['blocks'] as $bId => $bData) {
                    $updates = []; 
                    $params = [];
                    
                    // Update basic block stage/progress
                    if (isset($bData['stage'])) { $updates[] = "stage = ?"; $params[] = $bData['stage']; }
                    if (isset($bData['progress'])) { $updates[] = "progress = ?"; $params[] = $bData['progress']; }
                    
                    // Update all finishes fields
                    foreach ($allowedFinishesFields as $f) {
                        if (isset($bData[$f])) {
                            $updates[] = "$f = ?";
                            $params[] = $bData[$f];
                        }
                    }
                    
                    if (!empty($updates)) {
                        $params[] = $bId;
                        $params[] = $project_id;
                        $sql = "UPDATE project_blocks SET " . implode(', ', $updates) . " WHERE id = ? AND project_id = ?";
                        $updateBlock = $pdo->prepare($sql);
                        $updateBlock->execute($params);
                    }
                }
            }
            if (isset($_POST['levels']) && is_array($_POST['levels'])) {
                $lStmt = $pdo->prepare("UPDATE block_levels SET construction_status=? WHERE id=?");
                foreach ($_POST['levels'] as $lId => $lData) {
                    $lStmt->execute([$lData['construction_status'] ?? 'Pending', $lId]);
                }
            }
            $pdo->commit();
            $message = 'Block execution progress updated successfully!';
        } catch (PDOException $e) { $pdo->rollBack(); $error = 'Error: ' . $e->getMessage(); }
    }

    if (($_POST['action'] ?? null) === 'update_services' && $canEditServices) {
        try {
            $pdo->prepare("INSERT INTO project_services (project_id, existing_meters_required, existing_meters_complete, enemalta_deviation_required, enemalta_deviation_complete, go_deviation_required, go_deviation_complete, melita_deviation_required, melita_deviation_complete, lc_lamps_required, lc_lamps_complete, temp_elec_meter_required, temp_elec_meter_complete, temp_wsc_meter_required, temp_wsc_meter_complete) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE existing_meters_required=VALUES(existing_meters_required), existing_meters_complete=VALUES(existing_meters_complete), enemalta_deviation_required=VALUES(enemalta_deviation_required), enemalta_deviation_complete=VALUES(enemalta_deviation_complete), go_deviation_required=VALUES(go_deviation_required), go_deviation_complete=VALUES(go_deviation_complete), melita_deviation_required=VALUES(melita_deviation_required), melita_deviation_complete=VALUES(melita_deviation_complete), lc_lamps_required=VALUES(lc_lamps_required), lc_lamps_complete=VALUES(lc_lamps_complete), temp_elec_meter_required=VALUES(temp_elec_meter_required), temp_elec_meter_complete=VALUES(temp_elec_meter_complete), temp_wsc_meter_required=VALUES(temp_wsc_meter_required), temp_wsc_meter_complete=VALUES(temp_wsc_meter_complete)")->execute([$projectId, $_POST['existing_meters_required'] ?? 'Not Required', $_POST['existing_meters_complete'] ?? 'Not Complete', $_POST['enemalta_deviation_required'] ?? 'Not Required', $_POST['enemalta_deviation_complete'] ?? 'Not Complete', $_POST['go_deviation_required'] ?? 'Not Required', $_POST['go_deviation_complete'] ?? 'Not Complete', $_POST['melita_deviation_required'] ?? 'Not Required', $_POST['melita_deviation_complete'] ?? 'Not Complete', $_POST['lc_lamps_required'] ?? 'Not Required', $_POST['lc_lamps_complete'] ?? 'Not Complete', $_POST['temp_elec_meter_required'] ?? 'Not Required', $_POST['temp_elec_meter_complete'] ?? 'Not Complete', $_POST['temp_wsc_meter_required'] ?? 'Not Required', $_POST['temp_wsc_meter_complete'] ?? 'Not Complete']);
            $message = 'Services updated successfully!';
        } catch (PDOException $e) { $error = 'Error: ' . $e->getMessage(); }
    }
}

// ==========================================
// FETCH DATA FOR UI
// ==========================================

// 1. Fetch Advanced Logs
$logsStmt = $pdo->prepare("
    SELECT pl.*, 
           u.username as author_username, 
           au.username as assignee_username, 
           cu.username as closer_username
    FROM project_logs pl 
    JOIN users u ON pl.user_id = u.id 
    LEFT JOIN users au ON pl.assigned_to = au.id
    LEFT JOIN users cu ON pl.closed_by = cu.id
    WHERE pl.project_id = ? 
    ORDER BY pl.created_at DESC 
    LIMIT 100
");
$logsStmt->execute([$projectId]);
$projectLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch Assignable Users (Only users who have access to this specific project)
$clientId = $project['clientid'] ?? 0;
$assignUsersStmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.first_name, u.last_name, u.username, u.role
    FROM users u
    LEFT JOIN user_client_access uca ON u.id = uca.user_id AND uca.client_id = ?
    LEFT JOIN user_project_access upa ON u.id = upa.user_id AND upa.project_id = ?
    LEFT JOIN user_project_exclusions upe ON u.id = upe.user_id AND upe.project_id = ?
    WHERE u.is_active = 'Yes'
    AND (
        u.role IN ('admin', 'director', 'system_manager', 'project_manager', 'accountant') 
        OR upa.project_id IS NOT NULL 
        OR (uca.client_id IS NOT NULL AND upe.project_id IS NULL)
    )
    ORDER BY u.role ASC, u.first_name ASC
");
$assignUsersStmt->execute([$clientId, $projectId, $projectId]);
$assignableUsers = $assignUsersStmt->fetchAll(PDO::FETCH_ASSOC);

function getUserColor($username) {
    if (!$username) return '#6B7280';
    $colors = ['#6366F1', '#8B5CF6', '#EC4899', '#10B981', '#F59E0B', '#3B82F6', '#EF4444', '#14B8A6', '#F97316', '#06B6D4'];
    return $colors[abs(crc32($username)) % count($colors)];
}

$mobStmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
$mobStmt->execute([$projectId]);
$mob = $mobStmt->fetch();
if (!$mob) { $pdo->prepare("INSERT INTO project_mobilisation (project_id) VALUES (?)")->execute([$projectId]); $mobStmt->execute([$projectId]); $mob = $mobStmt->fetch(); }

$blocksStmt = $pdo->prepare("SELECT * FROM project_blocks WHERE project_id = ? ORDER BY id ASC");
$blocksStmt->execute([$projectId]);
$projectBlocks = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);

$blockLevels = [];
if (!empty($projectBlocks)) {
    $blockIds = array_column($projectBlocks, 'id');
    $placeholders = implode(',', array_fill(0, count($blockIds), '?'));
    $levelsStmt = $pdo->prepare("SELECT * FROM block_levels WHERE block_id IN ($placeholders) ORDER BY block_id ASC, level_number ASC");
    $levelsStmt->execute($blockIds);
    foreach ($levelsStmt->fetchAll(PDO::FETCH_ASSOC) as $lvl) { $blockLevels[$lvl['block_id']][] = $lvl; }
}

$services = getProjectServices($pdo, $projectId);

// ==========================================
// 11-STAGE LOGIC & UI AUTO-EXPAND
// ==========================================
$currentStageName = deriveProjectStage($pdo, $projectId);
$stagesEnum = ['Feasibility'=>1, 'Tracking'=>2, 'Permit'=>3, 'Mobilisation'=>4, 'Demolition'=>5, 'Excavation'=>6, 'Construction'=>7, 'Finishes'=>8, 'Compliance'=>9, 'Condominium'=>10, 'Handed Over'=>11];
$stageNum = $stagesEnum[$currentStageName] ?? 1;
$progressPercent = min(100, round(($stageNum / 11) * 100));

$bcaOpen = ($stageNum <= 6) ? 'open' : '';
$execOpen = ($stageNum >= 6) ? 'open' : '';

$geoComplete = ($mob['geological_test'] ?? 'NA') === 'Complete' || ($mob['geological_test'] ?? 'NA') === 'NA';
$condComplete = ($mob['condition_reports'] ?? 'Not Started') === 'Complete' || ($mob['condition_reports'] ?? 'Not Started') === 'NA';
$canSequential = $geoComplete && $condComplete;

$allSeqComplete = true;
foreach (['method_statements', 'insurance_status', 'pavement_guarantee', 'wellbeing_guarantee', 'umbrella_guarantee'] as $field) {
    if (($mob[$field] ?? 'Not Complete') !== 'Complete') { $allSeqComplete = false; break; }
}
$canFinal = $allSeqComplete;
$canClearance = ($mob['responsibility_form'] ?? 'Not Complete') === 'Complete';

// ==========================================
// PDF MATRICES: FINISHES
// ==========================================
$optElec = ['Not Started', 'First Fix', 'Second Fix', 'Third Fix', 'Completed'];
$optLifts = ['Not Started', 'Delivered', 'Installing', 'Installed', 'Switched On'];
$optSub = ['NA', 'Not Started', 'Finishings', 'Installed', 'Switched On', 'Handed Over'];
$optPool = ['NA', 'Not Started', 'Construction', 'Finishes', 'Completed', 'Switched On'];
$optRend = ['Not Started', 'Plastering', 'Painting', 'Completed'];
$optNaRend = ['NA', 'Not Started', 'Plastering', 'Painting', 'Completed'];
$optNotOngComp = ['Not Started', 'Ongoing', 'Completed'];
$optNaNotOngComp = ['NA', 'Not Started', 'Ongoing', 'Completed'];

$finishesTemplate = [
    'elec_work' => ['label' => 'Electrical Work', 'opts' => $optElec, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'plumb_work' => ['label' => 'Plumbing Work', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'pumps' => ['label' => 'Pumps, Lifts, Reservoirs', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'water_tanks' => ['label' => 'Water Tanks', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished']],
    'lifts' => ['label' => 'Lifts', 'opts' => $optLifts, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'substation' => ['label' => 'Substation', 'opts' => $optSub, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'septic' => ['label' => 'Septic Tanks', 'opts' => $optNaNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'garden' => ['label' => 'Garden Landscaping', 'opts' => $optNaNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'pool' => ['label' => 'Common Pool', 'opts' => $optPool, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'fire_det' => ['label' => 'Fire Detection', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'fire_fight' => ['label' => 'Fire Fighting', 'opts' => $optNaNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'fire_doors' => ['label' => 'Fire Doors', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'intercoms' => ['label' => 'Intercoms', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'rend_facade' => ['label' => 'Rendering Façade', 'opts' => $optRend, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'rend_appogg' => ['label' => 'Rendering Appogg', 'opts' => $optNaRend, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'rend_back' => ['label' => 'Rendering Back Façade', 'opts' => $optRend, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'rend_cp' => ['label' => 'Rendering Common Parts', 'opts' => $optRend, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'rend_garage_cp' => ['label' => 'Rendering Garage C.P.', 'opts' => $optNaRend, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'rend_garages' => ['label' => 'Rendering Garages', 'opts' => $optNaRend, 'lvls' => ['Semi Finished']],
    'marble_cp' => ['label' => 'Marble in Common Parts', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'marble_sills' => ['label' => 'Marble Sills', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'waterproof_balc' => ['label' => 'Waterproofing Balconies', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished']],
    'waterproof_roof' => ['label' => 'Waterproofing Roof', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'waterproof_shafts' => ['label' => 'Waterproofing Shafts', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'waterproof_ext' => ['label' => 'Waterproofing other ext.', 'opts' => $optNaNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'tiling_balc' => ['label' => 'Tiling of balconies', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished']],
    'gypsum_cp' => ['label' => 'Gypsum in common parts', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'gypsum_facade' => ['label' => 'Gypsum in facades', 'opts' => $optNaNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'fire_apt_doors' => ['label' => 'Fire Rated Apt Doors', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished']],
    'cp_doors_win' => ['label' => 'C.P. doors & windows', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'apt_doors_win' => ['label' => 'Apt doors & windows', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished']],
    'int_railings' => ['label' => 'All Internal Railings', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'ext_railings' => ['label' => 'All External Railings', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished']],
    'terrace_parts' => ['label' => 'Terrace/Shaft Partitions', 'opts' => $optNaNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'planters' => ['label' => 'Planters/Landscaping', 'opts' => $optNaNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'garage_main_door' => ['label' => 'Garage Main Door/Gate', 'opts' => $optNaNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'garage_grilles' => ['label' => 'Garage Vent Grilles', 'opts' => $optNaNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'ind_garage_doors' => ['label' => 'Individual Garage Doors', 'opts' => $optNaNotOngComp, 'lvls' => ['Semi Finished']],
    'sewer' => ['label' => 'Main Sewer Connection', 'opts' => $optNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
    'other_cladding' => ['label' => 'Other cladding', 'opts' => $optNaNotOngComp, 'lvls' => ['Semi Finished', 'Common Parts Only']],
];

function rSel($n, $opts, $v, $dis, $cls='') {
    $h = "<select name=\"$n\" $dis class=\"$cls\" style=\"padding:0.4rem; font-size:0.8rem; width:100%; border:1px solid var(--border-glass); border-radius:4px; background:var(--bg-secondary); color:var(--text-primary);\">";
    foreach ($opts as $ov => $ol) {
        if (is_numeric($ov)) $ov = $ol;
        $s = ((string)$v === (string)$ov) ? 'selected' : '';
        $h .= "<option value=\"$ov\" $s>$ol</option>";
    }
    return $h . "</select>";
}

$pageTitle = 'Execution - ' . $project['name'];
require_once 'header.php';
?>

<style>
.custom-accordion { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-md); margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); }
.custom-accordion summary { padding: 1.25rem 1.5rem; font-size: 1.2rem; font-weight: 600; color: var(--text-primary); cursor: pointer; background: rgba(255,255,255,0.02); list-style: none; display: flex; justify-content: space-between; align-items: center; border-radius: var(--radius-md); user-select: none; transition: background 0.2s ease; }
.custom-accordion summary:hover { background: rgba(255,255,255,0.05); }
.custom-accordion summary::-webkit-details-marker { display: none; }
.custom-accordion summary::after { content: '▼'; font-size: 1rem; color: var(--primary-color); transition: transform 0.3s ease; }
.custom-accordion[open] summary::after { transform: rotate(180deg); }
.custom-accordion[open] summary { border-bottom-left-radius: 0; border-bottom-right-radius: 0; border-bottom: 1px solid var(--border-glass); }
.accordion-content { padding: 1.5rem; }
.stage-tracker { display: flex; align-items: center; justify-content: space-between; background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-md); padding: 1.5rem; margin-bottom: 2rem; }
.stage-badge { display: inline-block; padding: 0.5rem 1rem; border-radius: 20px; background: rgba(99, 102, 241, 0.15); color: var(--primary-color); font-weight: 700; font-size: 1.1rem; border: 1px solid rgba(99, 102, 241, 0.3); }
.progress-bar-bg { height: 10px; background: rgba(255,255,255,0.1); border-radius: 5px; margin-top: 1rem; overflow: hidden; }
.progress-bar-fill { height: 100%; background: linear-gradient(90deg, var(--primary-color), var(--secondary-color)); transition: width 0.5s ease; }
.fin-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
</style>

<div class="main-container">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h1 class="page-title" style="margin-bottom: 0;"><?= htmlspecialchars($project['name']) ?></h1>
    </div>

    <div class="stage-tracker">
        <div style="flex: 1;">
            <div style="font-size: 0.85rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.5rem;">Current System Stage</div>
            <div class="stage-badge">Stage <?= $stageNum ?>/11: <?= $currentStageName ?></div>
            <div class="progress-bar-bg"><div class="progress-bar-fill" style="width: <?= $progressPercent ?>%;"></div></div>
            <div style="margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-muted);">Status auto-calculates based on clearance and block progress below.</div>
        </div>
    </div>

    <?php if ($project['summer_break_flag'] == 1): ?>
        <div class="alert alert-error" style="display: flex; align-items: center; gap: 1rem; border-left: 5px solid var(--danger); margin-bottom: 1.5rem;">
            <span style="font-size: 1.5rem;">☀️</span><div><strong>Summer Break Alarm Active</strong><br>This project is subject to Malta Summer Break restrictions. Demolition and Excavation phases are heavily impacted.</div>
        </div>
    <?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="projects-section" style="margin-bottom: 2rem;">
        <div class="project-meta">
            <div class="meta-item">
                <span class="meta-label">PA Ref: </span>
                <span style="color: var(--primary-color); font-weight: 700;">
                    <?php 
                    if (!empty($project['pa_numbers'])) {
                        $paLinks = [];
                        foreach ($project['pa_numbers'] as $pa) {
                            $url = getEAppsUrl($pa);
                            $paLinks[] = "<a href=\"$url\" target=\"_blank\" style=\"color: inherit; text-decoration: underline;\">" . htmlspecialchars($pa) . "</a>";
                        }
                        echo implode(', ', $paLinks);
                    } else {
                        echo 'Pending';
                    }
                    ?>
                </span>
            </div>
            <div class="meta-item"><span class="meta-label">Client: </span><span><?= htmlspecialchars($project['client_name'] ?? 'Unknown') ?></span></div>
            <div class="meta-item"><span class="meta-label">Type: </span><span><?= ucwords(str_replace('-', ' ', $project['type'])) ?></span></div>
            <div class="meta-item"><span class="meta-label">City: </span><span><?= htmlspecialchars($project['city']) ?></span></div>
            <div class="meta-item"><span class="meta-label">Finish Level: </span><span style="color: var(--primary-color); font-weight: 600;"><?= htmlspecialchars($project['finishlevel'] ?? 'N/A') ?></span></div>
        </div>
    </div>

    <div class="section-card" id="project-log" style="margin-bottom: 2rem; border-top: 4px solid var(--primary-color);">
        <div class="section-header"><h2>📝 Activity Log & Task Assignments</h2></div>
        
        <form method="POST" style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 1.5rem;">
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <textarea name="log_message" placeholder="Add an update, observation, or assign a task..." required style="flex:1; min-width: 300px; padding: 0.75rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary); resize: vertical; min-height: 48px;"></textarea>
                
                <div style="display: flex; flex-direction: column; gap: 0.5rem; min-width: 250px;">
                    <select name="assigned_to" style="padding: 0.75rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-primary); color: var(--text-primary);">
                        <option value="">-- Info Only (No Assignment) --</option>
                        <?php 
                        $currentRoleGroup = '';
                        foreach ($assignableUsers as $u) {
                            $roleLabel = ucwords(str_replace('_', ' ', $u['role']));
                            if ($currentRoleGroup !== $roleLabel) {
                                if ($currentRoleGroup !== '') echo "</optgroup>";
                                echo "<optgroup label=\"$roleLabel\">";
                                $currentRoleGroup = $roleLabel;
                            }
                            echo "<option value=\"{$u['id']}\">{$u['first_name']} {$u['last_name']} (@{$u['username']})</option>";
                        }
                        if ($currentRoleGroup !== '') echo "</optgroup>";
                        ?>
                    </select>
                    <button type="submit" name="add_log" class="btn btn-primary" style="margin: 0; padding: 0.75rem;">Post to Log</button>
                </div>
            </div>
        </form>

        <div style="max-height: 500px; overflow-y: auto; padding-right: 0.5rem;">
            <?php if (empty($projectLogs)): ?>
                <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No activity logged yet.</p>
            <?php else: foreach ($projectLogs as $log): ?>
                <?php 
                $isAction = ($log['status'] !== 'Info');
                $isClosed = ($log['status'] === 'Action - Closed');
                $borderColor = getUserColor($log['author_username']);
                
                if ($isAction) {
                    $borderColor = $isClosed ? '#10B981' : '#F59E0B'; // Green if closed, Amber if pending
                }
                ?>
                
                <div style="padding: 1rem; background: var(--bg-secondary); margin-bottom: 0.75rem; border-radius: 8px; border-left: 4px solid <?= $borderColor ?>;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                        <div>
                            <strong style="color: <?= getUserColor($log['author_username']) ?>;">@<?= htmlspecialchars($log['author_username']) ?></strong>
                            <span style="font-size: 0.8rem; color: var(--text-muted); margin-left: 0.5rem;"><?= date('d M Y, H:i', strtotime($log['created_at'])) ?></span>
                        </div>
                        
                        <?php if ($isAction): ?>
                            <?php if ($isClosed): ?>
                                <span style="font-size: 0.75rem; background: rgba(16, 185, 129, 0.1); color: #10B981; padding: 0.3rem 0.6rem; border-radius: 12px; font-weight: bold; border: 1px solid rgba(16, 185, 129, 0.3);">
                                    ✅ Closed by @<?= htmlspecialchars($log['closer_username'] ?? 'Unknown') ?> on <?= date('d M, H:i', strtotime($log['closed_at'])) ?>
                                </span>
                            <?php else: ?>
                                <span style="font-size: 0.75rem; background: rgba(245, 158, 11, 0.1); color: #F59E0B; padding: 0.3rem 0.6rem; border-radius: 12px; font-weight: bold; border: 1px solid rgba(245, 158, 11, 0.3);">
                                    ⏳ Pending Action for @<?= htmlspecialchars($log['assignee_username'] ?? 'Unknown') ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div style="font-size: 0.95rem; color: var(--text-primary); margin-bottom: <?= ($isAction && !$isClosed) ? '1rem' : '0' ?>;">
                        <?= nl2br(htmlspecialchars($log['message'])) ?>
                    </div>

                    <?php if ($isAction && !$isClosed): ?>
                        <div style="display: flex; justify-content: flex-end;">
                            <form method="POST">
                                <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                <button type="submit" name="close_action" class="btn btn-sm" style="background: #10B981; color: white; border: none; padding: 0.4rem 1rem; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                                    <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    Mark as Complete
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <details class="custom-accordion" <?= $bcaOpen ?>>
        <summary>📋 Pre-Construction & BCA Clearances</summary>
        <div class="accordion-content">
            <form method="POST" class="form-grid">
                <input type="hidden" name="action" value="update_mobilisation">
                <?php if ($project['type'] === 'in-house'): ?>
                    <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem;">
                        <legend style="font-weight: 600;">🏠 Acquisition Complete</legend>
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group"><label>Status</label><?= rSel('acquisition_complete', ['No', 'Yes'], $mob['acquisition_complete']??'No', $disabledAttr) ?></div>
                            <div class="form-group"><label>Date</label><input type="date" name="acquisition_date" value="<?= $mob['acquisition_date'] ?? '' ?>" <?= $disabledAttr ?>></div>
                        </div>
                    </fieldset>
                <?php endif; ?>

                <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem;">
                    <legend style="font-weight: 600;">📋 Non-Sequential Tasks</legend>
                    <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div class="form-group"><label>Archaeologist Assigned</label><?= rSel('archaeologist_assigned', ['NA','Yes','No'], $mob['archaeologist_assigned']??'NA', $disabledAttr) ?></div>
                        <div class="form-group"><label>Change of Applicant</label><?= rSel('change_of_applicant', ['NA','Complete','Not Complete'], $mob['change_of_applicant']??'NA', $disabledAttr) ?></div>
                        <div class="form-group"><label>Geological Test</label><?= rSel('geological_test', ['NA','Complete','Not Complete','Awaiting Result'], $mob['geological_test']??'NA', $disabledAttr) ?></div>
                        <div class="form-group"><label>Cond. Report Contacts</label><?= rSel('condition_report_contacts', ['NA','Not Started','In Process','Complete'], $mob['condition_report_contacts']??'Not Started', $disabledAttr) ?></div>
                        <div class="form-group"><label>Condition Reports</label><?= rSel('condition_reports', ['NA','Not Started','In Process','Complete'], $mob['condition_reports']??'Not Started', $disabledAttr) ?></div>
                    </div>
                </fieldset>

                <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; opacity: <?= $canSequential ? '1' : '0.5' ?>; margin-bottom: 1.5rem;">
                    <legend style="font-weight: 600;">🔗 Sequential Chain</legend>
                    <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <?php $seqDis = !$canSequential ? 'disabled' : $disabledAttr; $optSeq = ['Not Started','In Process','Complete']; ?>
                        <div class="form-group"><label>Method Statements</label><?= rSel('method_statements', ['Not Complete','Complete'], $mob['method_statements']??'Not Complete', $seqDis) ?></div>
                        <div class="form-group"><label>Insurance</label><?= rSel('insurance_status', $optSeq, $mob['insurance_status']??'Not Started', $seqDis) ?></div>
                        <div class="form-group"><label>Pavement Guarantee</label><?= rSel('pavement_guarantee', $optSeq, $mob['pavement_guarantee']??'Not Started', $seqDis) ?></div>
                        <div class="form-group"><label>Wellbeing Guarantee</label><?= rSel('wellbeing_guarantee', $optSeq, $mob['wellbeing_guarantee']??'Not Started', $seqDis) ?></div>
                        <div class="form-group"><label>Umbrella Guarantee</label><?= rSel('umbrella_guarantee', $optSeq, $mob['umbrella_guarantee']??'Not Started', $seqDis) ?></div>
                    </div>
                </fieldset>

                <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; opacity: <?= $canFinal ? '1' : '0.5' ?>;">
                    <legend style="font-weight: 600;">🏗️ Clearances & Site Prep Execution</legend>
                    <div class="form-grid" style="grid-template-columns: 1fr; gap: 1rem;">
                        <?php $finDis = !$canFinal ? 'disabled' : $disabledAttr; $clrDis = !$canClearance ? 'disabled' : $disabledAttr; $optEx = ['Pending','In Progress','Complete','NA']; ?>
                        <div class="form-group"><label>Responsibility Form</label><?= rSel('responsibility_form', ['Not Complete','Complete'], $mob['responsibility_form']??'Not Complete', $finDis) ?></div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; background: rgba(239, 68, 68, 0.1); padding: 1rem; border-radius: 6px; border-left: 3px solid var(--danger);">
                            <div class="form-group" style="margin:0;"><label>Demolition Clearance</label><?= rSel('mob_demolition', ['No'=>'No Clearance', 'Yes'=>'Cleared', 'NA'=>'N/A'], $mob['mob_demolition']??'No', $clrDis) ?></div>
                            <div class="form-group" style="margin:0;"><label>Demolition Execution</label><?= rSel('demo_status', $optEx, $mob['demo_status']??'Pending', $clrDis) ?></div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; background: rgba(245, 158, 11, 0.1); padding: 1rem; border-radius: 6px; border-left: 3px solid var(--warning);">
                            <div class="form-group" style="margin:0;"><label>Excavation Clearance</label><?= rSel('mob_excavation', ['No'=>'No Clearance', 'Yes'=>'Cleared', 'NA'=>'N/A'], $mob['mob_excavation']??'No', $clrDis) ?></div>
                            <div class="form-group" style="margin:0;"><label>Excavation Execution</label><?= rSel('excavation_status', $optEx, $mob['excavation_status']??'Pending', $clrDis) ?></div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr; gap: 1rem; background: rgba(34, 197, 94, 0.1); padding: 1rem; border-radius: 6px; border-left: 3px solid var(--success);">
                            <div class="form-group" style="margin:0;"><label>Construction Clearance</label><?= rSel('mob_construction', ['No'=>'No Clearance', 'Yes'=>'Cleared', 'NA'=>'N/A'], $mob['mob_construction']??'No', $clrDis) ?></div>
                            <div style="font-size: 0.85rem; color: var(--text-muted);">(Construction Execution is tracked Block-by-Block below)</div>
                        </div>
                    </div>
                </fieldset>
                <?php if ($canUpdateStatus): ?><div class="form-actions" style="margin-top: 1rem;"><button type="submit" class="btn btn-primary">Save BCA Updates</button></div><?php endif; ?>
            </form>
        </div>
    </details>

    <details class="custom-accordion" <?= $execOpen ?>>
        <summary>🏢 Block Execution & Progress</summary>
        <div class="accordion-content">
            <?php if (empty($projectBlocks)): ?>
                <div class="alert alert-info">No blocks defined. Edit project to add blocks.</div>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="update_blocks">
                    <?php 
                    $requiresFinishes = !in_array($project['finishlevel'], ['Shell', null, '']);
                    $finTier = in_array($project['finishlevel'], ['Semi Finished', 'Finished']) ? 'Semi Finished' : 'Common Parts Only';
                    
                    foreach ($projectBlocks as $block): 
                        $bFinData = json_decode($block['finishes_data'] ?? '{}', true) ?: [];
                    ?>
                        <fieldset style="border: 1px solid var(--primary-color); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; background: rgba(99, 102, 241, 0.02);">
                            <legend style="font-weight: 600; color: var(--primary-color); font-size: 1.1rem; padding: 0 0.5rem; background: var(--bg-card); border-radius: 4px;">
                                <?= htmlspecialchars($block['block_name']) ?> (<?= htmlspecialchars($block['block_type']) ?>)
                            </legend>
                            
                            <h4 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Construction Status (Sequential)</h4>
                            
                            <?php $levels = $blockLevels[$block['id']] ?? []; ?>
                            <?php if (empty($levels)): ?>
                                <div class="alert alert-warning" style="margin-bottom: 2rem; border-left: 4px solid var(--warning);">
                                    <strong>⚠️ Block levels are still to be defined.</strong><br>
                                    Please <a href="edit-project.php?id=<?= $projectId ?>" style="color: inherit; text-decoration: underline;">edit the project details</a> to set the lowest and highest floors for this block.
                                </div>
                            <?php else: ?>
                                <div class="table-container" style="margin-bottom: 2rem; background: var(--bg-primary);">
                                    <table class="data-table" style="background: transparent;">
                                        <thead><tr><th style="width: 30%;">Level</th><th>Construction Status</th></tr></thead>
                                        <tbody class="construction-table-body">
                                            <?php foreach ($levels as $lvl): ?>
                                                <tr>
                                                    <td style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($lvl['level_name']) ?></td>
                                                    <td><?= rSel("levels[{$lvl['id']}][construction_status]", ['Pending','In Progress','Complete','NA'], $lvl['construction_status'], $disabledAttr, 'const-status') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <h4 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem; display:flex; justify-content:space-between; align-items:center;">
                                Finishes Matrix (Scope of Work)
                                <div style="display:flex; align-items:center; gap:0.5rem;">
                                    <span style="font-size:0.8rem; color:var(--text-secondary);">Master Block Status:</span>
                                    <div style="width: 150px;">
                                        <?= rSel("blocks[{$block['id']}][finishes_overall_status]", ['Pending','In Progress','Complete','NA'], $block['finishes_overall_status'], $disabledAttr) ?>
                                    </div>
                                </div>
                            </h4>
                            
                            <?php if (!$requiresFinishes): ?>
                                <div class="alert alert-info">Finishes tracking is disabled for Shell properties.</div>
                            <?php else: ?>
                                <div class="fin-grid" style="margin-bottom: 2rem;">
                                    <?php 
                                    $scopeGroups = [
                                        'Engineering Works' => [
                                            'icon' => '⚙️', 'color' => '#0ea5e9',
                                            'fields' => [ 'fin_electrical'=>'Electrical Work', 'fin_plumbing'=>'Plumbing Work', 'fin_pumps'=>'Pumps: Lifts & Reservoirs', 'fin_lifts'=>'Lifts', 'fin_substation'=>'Substation', 'fin_septic'=>'Septic Tanks', 'fin_sewer'=>'Main Sewer Conn.' ]
                                        ],
                                        'Fire and ELV' => [
                                            'icon' => '🔥', 'color' => '#ef4444',
                                            'fields' => [ 'fin_fire_detection'=>'Fire Detection', 'fin_fire_fighting'=>'Fire Fighting', 'fin_fire_doors'=>'Metal Fire Doors', 'fin_intercoms'=>'Intercoms' ]
                                        ],
                                        'Landscaping' => [
                                            'icon' => '🌳', 'color' => '#22c55e',
                                            'fields' => [ 'fin_garden'=>'Garden Landscaping', 'fin_pool'=>'Common Pool' ]
                                        ],
                                        'Rendering' => [
                                            'icon' => '🧱', 'color' => '#f97316',
                                            'fields' => [ 'fin_rend_facade'=>'Rendering Façade', 'fin_rend_appogg'=>'Rendering Appogg', 'fin_rend_back'=>'Rendering Back Façade', 'fin_rend_cp'=>'Rendering Common Parts', 'fin_cladding'=>'Other Cladding' ]
                                        ],
                                        'Flooring & Waterproofing' => [
                                            'icon' => '🛡️', 'color' => '#a855f7',
                                            'fields' => [ 'fin_marble_cp'=>'Marble in Common Parts', 'fin_marble_sills'=>'Marble Sills', 'fin_wp_roof'=>'Waterproofing Roof', 'fin_wp_shafts'=>'Waterproofing Shafts', 'fin_wp_ext'=>'Waterproofing Other Ext.' ]
                                        ],
                                        'Gypsum Works' => [
                                            'icon' => '🖌️', 'color' => '#14b8a6',
                                            'fields' => [ 'fin_gypsum_cp'=>'Gypsum in Common Parts', 'fin_gypsum_facade'=>'Gypsum in Facades' ]
                                        ],
                                        'Apertures & Railings' => [
                                            'icon' => '🚪', 'color' => '#eab308',
                                            'fields' => [ 'fin_cp_doors_win'=>'C.P. Doors & Windows', 'fin_int_railings'=>'All Internal Railings', 'fin_partitions'=>'Terrace/Shaft Partitions' ]
                                        ],
                                        'Blocks Additions (Semi-Finished)' => [
                                            'icon' => '🏠', 'color' => '#6366f1',
                                            'fields' => [ 'fin_water_tanks'=>'Water Tanks', 'fin_wp_balconies'=>'Waterproofing Balconies', 'fin_tile_balconies'=>'Tiling of Balconies', 'fin_apt_fire_doors'=>'Fire Rated Apt Doors', 'fin_apt_doors_win'=>'Apt Doors & Windows', 'fin_ext_railings'=>'All External Railings' ]
                                        ],
                                        'Garage Complexes (Common Parts)' => [
                                            'icon' => '🚗', 'color' => '#64748b',
                                            'fields' => [ 'fin_gar_rend_cp'=>'Rendering Garage C.P.', 'fin_gar_rend'=>'Rendering Garages', 'fin_gar_main_door'=>'Garage Main Door/Gate', 'fin_gar_vent'=>'Garage Vent Grilles' ]
                                        ],
                                        'Garage Additions (Semi-Finished)' => [
                                            'icon' => '🚘', 'color' => '#475569',
                                            'fields' => [ 'fin_gar_ind_doors'=>'Individual Garage Doors', 'fin_gar_win'=>'Garage Windows' ]
                                        ]
                                    ];
                                    
                                    foreach ($scopeGroups as $gName => $group): ?>
                                        <div style="margin-top: 1.5rem; background: rgba(255,255,255,0.02); padding: 16px; border-radius: 8px; border-left: 3px solid <?= $group['color'] ?>;">
                                            <h5 style="margin: 0 0 12px 0; color: <?= $group['color'] ?>; font-size: 0.95rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                                <span><?= $group['icon'] ?></span> <?= $gName ?>
                                            </h5>
                                            
                                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px;">
                                                <?php foreach ($group['fields'] as $dbKey => $label): 
                                                    $val = $b[$dbKey] ?? 'Not Required';
                                                ?>
                                                    <div>
                                                        <label style="display: block; font-size: 0.65rem; color: #94a3b8; margin-bottom: 4px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;"><?= $label ?></label>
                                                        <select name="blocks[<?= $b['id'] ?>][<?= $dbKey ?>]" style="width: 100%; background: #1e1e2d; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 8px; border-radius: 4px; font-size: 0.8rem; cursor: pointer;">
                                                            <?php
                                                            // Intercoms has special options
                                                            if ($dbKey === 'fin_intercoms') {
                                                                $opts = ['Not Required', 'Not started', 'Ongoing CP', 'First Call', 'Second Call', 'Complete'];
                                                            } else {
                                                                $opts = ['Not Required', 'Required', 'In Progress', 'Complete'];
                                                            }
                                                            
                                                            foreach ($opts as $opt):
                                                                $sel = ($val === $opt) ? 'selected' : '';
                                                                // Dynamic Status Colors inside the dropdown
                                                                $colorStyle = '';
                                                                if ($opt === 'Complete') $colorStyle = 'color: #22c55e;';
                                                                elseif (in_array($opt, ['In Progress', 'Ongoing CP', 'First Call', 'Second Call'])) $colorStyle = 'color: #f59e0b;';
                                                                elseif ($opt === 'Not Required') $colorStyle = 'color: #64748b;';
                                                                else $colorStyle = 'color: #ef4444;';
                                                            ?>
                                                                <option value="<?= $opt ?>" <?= $sel ?> style="<?= $colorStyle ?>"><?= $opt ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <h4 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Post-Construction Milestones</h4>
                            <div class="fin-grid">
                                <?php $optYNN = ['No','Yes','NA']; ?>
                                <div class="form-group" style="margin:0;"><label style="font-size:0.8rem;">Compliance Submitted</label><?= rSel("blocks[{$block['id']}][compliance_submitted]", $optYNN, $block['compliance_submitted'], $disabledAttr) ?></div>
                                <div class="form-group" style="margin:0;"><label style="font-size:0.8rem;">Compliance Certified</label><?= rSel("blocks[{$block['id']}][compliance_certified]", $optYNN, $block['compliance_certified'], $disabledAttr) ?></div>
                                <div class="form-group" style="margin:0;"><label style="font-size:0.8rem;">Condominium Formed</label><?= rSel("blocks[{$block['id']}][condominium_formed]", $optYNN, $block['condominium_formed'], $disabledAttr) ?></div>
                                <div class="form-group" style="margin:0;"><label style="font-size:0.8rem;">CP Meters Installed</label><?= rSel("blocks[{$block['id']}][cp_meters_installed]", $optYNN, $block['cp_meters_installed'], $disabledAttr) ?></div>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                    <?php if ($canUpdateStatus): ?><div class="form-actions"><button type="submit" class="btn btn-primary">Save Block Progress</button></div><?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </details>

    <details class="custom-accordion">
        <summary>⚡ Services Engineer Utilities</summary>
        <div class="accordion-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_services">
                <div class="fin-grid">
                    <?php 
                    $srvMap = [
                        'existing_meters' => 'Existing Meter/s for Removal', 'enemalta_deviation' => 'Enemalta Lines for Deviation',
                        'go_deviation' => 'GO Lines for Deviation', 'melita_deviation' => 'Melita Lines for Deviation',
                        'lc_lamps' => 'LC Lamps', 'temp_elec_meter' => 'Temp Elec Meter Installation', 'temp_wsc_meter' => 'Temp WSC Meter Installation'
                    ];
                    foreach ($srvMap as $key => $label):
                        $reqVal = $services["{$key}_required"] ?? 'Not Required';
                        $compVal = $services["{$key}_complete"] ?? 'Not Complete';
                        $compDis = ($reqVal === 'Not Required') ? 'disabled' : $servicesDisabledAttr;
                    ?>
                    <div class="form-group" style="padding: 1rem; border: 1px solid var(--border-glass); border-radius: 8px;">
                        <label style="font-weight: 600; margin-bottom: 0.5rem; display: block;"><?= $label ?></label>
                        <div style="display: flex; gap: 0.5rem;">
                            <?= rSel("{$key}_required", ['Not Required','Required'], $reqVal, $servicesDisabledAttr, 'req-toggle') ?>
                            <?= rSel("{$key}_complete", ['Not Complete','Complete'], $compVal, $compDis, 'comp-status') ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($canEditServices): ?><div class="form-actions" style="margin-top: 1.5rem;"><button type="submit" class="btn btn-primary">Save Services Updates</button></div><?php endif; ?>
            </form>
        </div>
    </details>
</div>

<script>
// Toggle for Services section
document.querySelectorAll('select.req-toggle').forEach(function(select) {
    select.addEventListener('change', function() {
        const compSelect = this.parentElement.querySelector('select.comp-status');
        if (this.value === 'Required') { compSelect.disabled = false; } 
        else { compSelect.disabled = true; compSelect.value = 'Not Complete'; }
    });
});

// Sequential Locking for Construction Floors
const canEditStatus = <?= $canUpdateStatus ? 'true' : 'false' ?>;

function enforceSequentialConstruction() {
    if (!canEditStatus) return; 

    document.querySelectorAll('.construction-table-body').forEach(tbody => {
        const rows = tbody.querySelectorAll('tr');
        let canStartNext = true;

        rows.forEach(row => {
            const select = row.querySelector('.const-status');
            if (!select) return;

            if (!canStartNext) {
                // Lock this row
                select.value = 'Pending';
                select.style.pointerEvents = 'none';
                select.style.background = 'var(--bg-primary)';
                select.style.opacity = '0.5';
                row.style.opacity = '0.6';
                row.title = "🔒 Previous floor must be Complete to unlock this level.";
            } else {
                // Unlock this row
                select.style.pointerEvents = 'auto';
                select.style.background = 'var(--bg-secondary)';
                select.style.opacity = '1';
                row.style.opacity = '1';
                row.title = "";

                if (select.value !== 'Complete' && select.value !== 'NA') {
                    canStartNext = false;
                }
            }
        });
    });
}

if (canEditStatus) {
    document.querySelectorAll('.const-status').forEach(select => {
        select.addEventListener('change', enforceSequentialConstruction);
    });
    
    // Fire on load to lock rows correctly
    enforceSequentialConstruction();
}
</script>

<?php require_once 'footer.php'; ?>
