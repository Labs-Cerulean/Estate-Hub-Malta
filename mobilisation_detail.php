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

// Helper for UI colors based on Finish Level
function getFinishLevelColor($level) {
    switch($level) {
        case 'Finished': return '#22c55e'; // Green
        case 'Semi Finished': return '#f59e0b'; // Orange
        case 'Common Parts Only': return '#0ea5e9'; // Blue
        case 'Shell': return '#9ca3af'; // Gray
        default: return '#fff';
    }
}

// ==========================================
// HANDLE POST REQUESTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Update overall project notes/status if allowed
    if ($canUpdateStatus) {
        $stmtProj = $pdo->prepare("UPDATE projects SET project_status = ?, notes = ? WHERE id = ?");
        $stmtProj->execute([$_POST['project_status'], $_POST['project_notes'], $projectId]);
    }

    // 2. Update Mobilisation (Services/Permits) if allowed
    if ($canEditServices && isset($_POST['mob'])) {
        $mob = $_POST['mob'];
        $stmtMob = $pdo->prepare("UPDATE project_mobilisation SET 
            demo_permit = ?, exc_permit = ?, const_permit = ?,
            demo_status = ?, excavation_status = ?,
            temporary_water = ?, temporary_electricity = ?,
            hoarding = ?, site_toilet = ?
            WHERE project_id = ?");
        $stmtMob->execute([
            $mob['demo_permit'] ?? 'NA', $mob['exc_permit'] ?? 'NA', $mob['const_permit'] ?? 'NA',
            $mob['demo_status'] ?? 'Pending', $mob['excavation_status'] ?? 'Pending',
            $mob['temporary_water'] ?? 'Not Started', $mob['temporary_electricity'] ?? 'Not Started',
            $mob['hoarding'] ?? 'No', $mob['site_toilet'] ?? 'No',
            $projectId
        ]);
    }

    // 3. Update Blocks (Construction & Finishes & Post Compliance)
    if ($canUpdateStatus && isset($_POST['blocks'])) {
        
        $finFields = [
            'fin_electrical', 'fin_plumbing', 'fin_pumps', 'fin_lifts', 'fin_substation', 
            'fin_septic', 'fin_sewer', 'fin_fire_detection', 'fin_fire_fighting', 
            'fin_fire_doors', 'fin_intercoms', 'fin_garden', 'fin_pool', 
            'fin_rend_facade', 'fin_rend_appogg', 'fin_rend_back', 'fin_rend_cp', 
            'fin_cladding', 'fin_marble_cp', 'fin_marble_sills', 'fin_wp_roof', 
            'fin_wp_shafts', 'fin_wp_ext', 'fin_gypsum_cp', 'fin_gypsum_facade', 
            'fin_cp_doors_win', 'fin_int_railings', 'fin_partitions', 'fin_water_tanks', 
            'fin_wp_balconies', 'fin_tile_balconies', 'fin_apt_fire_doors', 'fin_apt_doors_win', 
            'fin_ext_railings', 'fin_gar_rend_cp', 'fin_gar_rend', 'fin_gar_main_door', 
            'fin_gar_vent', 'fin_gar_ind_doors', 'fin_gar_win'
        ];

        $stmtBlock = $pdo->prepare("UPDATE project_blocks SET 
            lowest_level = ?, highest_level = ?, construction_complete = ?,
            fin_electrical = ?, fin_plumbing = ?, fin_pumps = ?, fin_lifts = ?, fin_substation = ?, 
            fin_septic = ?, fin_sewer = ?, fin_fire_detection = ?, fin_fire_fighting = ?, 
            fin_fire_doors = ?, fin_intercoms = ?, fin_garden = ?, fin_pool = ?, 
            fin_rend_facade = ?, fin_rend_appogg = ?, fin_rend_back = ?, fin_rend_cp = ?, 
            fin_cladding = ?, fin_marble_cp = ?, fin_marble_sills = ?, fin_wp_roof = ?, 
            fin_wp_shafts = ?, fin_wp_ext = ?, fin_gypsum_cp = ?, fin_gypsum_facade = ?, 
            fin_cp_doors_win = ?, fin_int_railings = ?, fin_partitions = ?, fin_water_tanks = ?, 
            fin_wp_balconies = ?, fin_tile_balconies = ?, fin_apt_fire_doors = ?, fin_apt_doors_win = ?, 
            fin_ext_railings = ?, fin_gar_rend_cp = ?, fin_gar_rend = ?, fin_gar_main_door = ?, 
            fin_gar_vent = ?, fin_gar_ind_doors = ?, fin_gar_win = ?,
            finish_level = ?, finishes_overall_status = ?, compliance_submitted = ?, 
            compliance_certified = ?, condominium_formed = ?, cp_meters_installed = ?, progress = ?
            WHERE id = ?");

        $stmtLevel = $pdo->prepare("UPDATE block_levels SET construction_status = ? WHERE id = ? AND block_id = ?");

        foreach ($_POST['blocks'] as $bId => $bData) {
            
            // Auto-Calculate Progress Percentage based on Finishes and Floor Finishes
            $validCount = 0; $completeCount = 0; $inProgressCount = 0;
            
            // Tally Block-Level Finishes
            foreach ($finFields as $f) {
                $val = $bData[$f] ?? 'Not Required';
                if ($val !== 'Not Required' && $val !== 'NA') {
                    $validCount++;
                    if ($val === 'Complete') $completeCount++;
                    elseif ($val === 'In Progress') $inProgressCount++;
                }
            }
            // Tally Floor-Level Finishes
            $floorFinishes = $_POST['floor_finishes'][$bId] ?? [];
            foreach ($floorFinishes as $lvlId => $types) {
                foreach ($types as $tId => $val) {
                    if ($val !== 'Not Required' && $val !== 'NA') {
                        $validCount++;
                        if ($val === 'Complete') $completeCount++;
                        elseif ($val === 'In Progress') $inProgressCount++;
                    }
                }
            }

            // Calculate Progress! (In progress counts as 50% done)
            $calculatedProgress = 0;
            if ($validCount > 0) {
                $calculatedProgress = round((($completeCount + (0.5 * $inProgressCount)) / $validCount) * 100);
            }

            // Execute Block Update
            $stmtBlock->execute([
                $bData['lowest_level'] ?? 0,
                $bData['highest_level'] ?? 0,
                $bData['construction_complete'] ?? 'No',
                $bData['fin_electrical'] ?? 'Not Required',
                $bData['fin_plumbing'] ?? 'Not Required',
                $bData['fin_pumps'] ?? 'Not Required',
                $bData['fin_lifts'] ?? 'Not Required',
                $bData['fin_substation'] ?? 'Not Required',
                $bData['fin_septic'] ?? 'Not Required',
                $bData['fin_sewer'] ?? 'Not Required',
                $bData['fin_fire_detection'] ?? 'Not Required',
                $bData['fin_fire_fighting'] ?? 'Not Required',
                $bData['fin_fire_doors'] ?? 'Not Required',
                $bData['fin_intercoms'] ?? 'Not Required',
                $bData['fin_garden'] ?? 'Not Required',
                $bData['fin_pool'] ?? 'Not Required',
                $bData['fin_rend_facade'] ?? 'Not Required',
                $bData['fin_rend_appogg'] ?? 'Not Required',
                $bData['fin_rend_back'] ?? 'Not Required',
                $bData['fin_rend_cp'] ?? 'Not Required',
                $bData['fin_cladding'] ?? 'Not Required',
                $bData['fin_marble_cp'] ?? 'Not Required',
                $bData['fin_marble_sills'] ?? 'Not Required',
                $bData['fin_wp_roof'] ?? 'Not Required',
                $bData['fin_wp_shafts'] ?? 'Not Required',
                $bData['fin_wp_ext'] ?? 'Not Required',
                $bData['fin_gypsum_cp'] ?? 'Not Required',
                $bData['fin_gypsum_facade'] ?? 'Not Required',
                $bData['fin_cp_doors_win'] ?? 'Not Required',
                $bData['fin_int_railings'] ?? 'Not Required',
                $bData['fin_partitions'] ?? 'Not Required',
                $bData['fin_water_tanks'] ?? 'Not Required',
                $bData['fin_wp_balconies'] ?? 'Not Required',
                $bData['fin_tile_balconies'] ?? 'Not Required',
                $bData['fin_apt_fire_doors'] ?? 'Not Required',
                $bData['fin_apt_doors_win'] ?? 'Not Required',
                $bData['fin_ext_railings'] ?? 'Not Required',
                $bData['fin_gar_rend_cp'] ?? 'Not Required',
                $bData['fin_gar_rend'] ?? 'Not Required',
                $bData['fin_gar_main_door'] ?? 'Not Required',
                $bData['fin_gar_vent'] ?? 'Not Required',
                $bData['fin_gar_ind_doors'] ?? 'Not Required',
                $bData['fin_gar_win'] ?? 'Not Required',
                $bData['finish_level'] ?? null,
                $bData['finishes_overall_status'] ?? 'Pending',
                $bData['compliance_submitted'] ?? 'No',
                $bData['compliance_certified'] ?? 'No',
                $bData['condominium_formed'] ?? 'No',
                $bData['cp_meters_installed'] ?? 'No',
                $calculatedProgress,
                $bId
            ]);

            // Save Construction Levels
            if (isset($_POST['levels'][$bId])) {
                foreach ($_POST['levels'][$bId] as $lvlId => $lData) {
                    $stmtLevel->execute([$lData['construction_status'], $lvlId, $bId]);
                }
            }

            // Save Floor Finishes
            if (isset($_POST['floor_finishes'][$bId]) && is_array($_POST['floor_finishes'][$bId])) {
                $stmtFloorFin = $pdo->prepare("INSERT INTO block_levels_statuses (project_id, block_id, level_id, finish_type_id, status, updated_by)
                                               VALUES (?, ?, ?, ?, ?, ?)
                                               ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by)");
                foreach ($_POST['floor_finishes'][$bId] as $lvlId => $types) {
                    foreach ($types as $tId => $status) {
                        $stmtFloorFin->execute([$projectId, $bId, $lvlId, $tId, $status, getCurrentUserId()]);
                    }
                }
            }
        }
    }
    
    $message = "Project details updated successfully.";
    $project = getProjectWithClient($pdo, $projectId);
}

// Fetch DB Records
$stmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
$stmt->execute([$projectId]);
$mob = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$stmtBlocks = $pdo->prepare("SELECT * FROM project_blocks WHERE project_id = ? ORDER BY block_name ASC");
$stmtBlocks->execute([$projectId]);
$blocks = $stmtBlocks->fetchAll(PDO::FETCH_ASSOC);

// Fetch Master Finish Types
$stmtFinTypes = $pdo->query("SELECT * FROM finish_types WHERE is_active=1 ORDER BY name ASC");
$finishTypes = $stmtFinTypes->fetchAll(PDO::FETCH_ASSOC);

// Fetch Floor Finishes Data
$stmtAllStatuses = $pdo->prepare("SELECT level_id, finish_type_id, status FROM block_levels_statuses WHERE project_id = ?");
$stmtAllStatuses->execute([$projectId]);
$floorStatusesRaw = $stmtAllStatuses->fetchAll(PDO::FETCH_ASSOC);

$floorStatuses = [];
foreach ($floorStatusesRaw as $r) {
    $floorStatuses[$r['level_id']][$r['finish_type_id']] = $r['status'];
}

$pageTitle = htmlspecialchars($project['name']) . " - Detail";
require_once 'header.php';
?>

<style>
    .status-select { font-weight: bold; background: #1e1e2d; color: #fff; border: 1px solid var(--border-glass); padding: 5px; border-radius: 4px; width: 100%; }
    .status-select option[value="Pending"], .status-select option[value="No"], .status-select option[value="Not Started"] { color: #ef4444; }
    .status-select option[value="In Progress"] { color: #f59e0b; }
    .status-select option[value="Complete"], .status-select option[value="Yes"], .status-select option[value="Connected"] { color: #22c55e; }
    .status-select option[value="Not Required"], .status-select option[value="NA"] { color: #9ca3af; }
    
    .grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem; }
    .form-group label { display: block; margin-bottom: 5px; font-size: 0.8rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }
    
    .finishes-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 10px; }
    .finishes-table th, .finishes-table td { border: 1px solid var(--border-glass); padding: 8px; text-align: left; }
    .finishes-table th { background: rgba(0,0,0,0.2); color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; }

    /* Accordion Details */
    details.block-accordion { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: 8px; margin-bottom: 1.5rem; }
    details.block-accordion > summary { padding: 1.25rem 1.5rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.05); list-style: none; }
    details.block-accordion > summary::-webkit-details-marker { display: none; }
    .accordion-arrow { transition: transform 0.3s; font-size: 0.8rem; color: var(--primary-color); }
    details[open].block-accordion .accordion-arrow { transform: rotate(180deg); }
    
    /* Sticky Save Bar */
    .sticky-save-bar {
        position: sticky;
        bottom: 0;
        background: rgba(30, 30, 45, 0.95);
        backdrop-filter: blur(10px);
        padding: 15px 30px;
        border-top: 1px solid var(--border-glass);
        z-index: 1000;
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 15px;
        box-shadow: 0 -10px 25px rgba(0,0,0,0.5);
        border-radius: 12px 12px 0 0;
        margin-top: 2rem;
    }
</style>

<div class="main-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin: 0;"><?= htmlspecialchars($project['name']) ?></h1>
            <p style="color: var(--text-secondary); margin: 5px 0 0 0;">Mobilisation, Construction & Finishes Tracker</p>
        </div>
        <a href="projects.php" class="btn btn-secondary">← Back</a>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST" id="mainForm">
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
            
            <div class="card" style="padding: 1.5rem;">
                <h3 style="margin-top: 0; color: var(--primary-color); border-bottom: 1px solid var(--border-glass); padding-bottom: 10px;">Master Status</h3>
                <div class="form-group" style="margin-top: 15px;">
                    <label>Overall Project Status</label>
                    <select name="project_status" class="form-select" <?= $disabledAttr ?>>
                        <option value="Active" <?= $project['project_status'] == 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="On Hold" <?= $project['project_status'] == 'On Hold' ? 'selected' : '' ?>>On Hold</option>
                        <option value="Completed" <?= $project['project_status'] == 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Cancelled" <?= $project['project_status'] == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Project Notes</label>
                    <textarea name="project_notes" rows="4" class="form-select" style="width: 100%;" <?= $disabledAttr ?>><?= htmlspecialchars($project['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="card" style="padding: 1.5rem;">
                <h3 style="margin-top: 0; color: var(--primary-color); border-bottom: 1px solid var(--border-glass); padding-bottom: 10px;">PA Numbers</h3>
                <div style="margin-top: 15px; color: var(--text-secondary); font-size: 0.9rem;">
                    <?php if (!empty($project['pa_numbers'])): ?>
                        <ul style="padding-left: 20px; margin: 0;">
                            <?php foreach ($project['pa_numbers'] as $pa): ?>
                                <li><strong><?= htmlspecialchars($pa) ?></strong></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p><em>No PA Numbers linked. Add them in Project Settings.</em></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card" style="padding: 1.5rem;">
                <h3 style="margin-top: 0; color: var(--primary-color); border-bottom: 1px solid var(--border-glass); padding-bottom: 10px;">Site Mobilisation</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
                    <div class="form-group">
                        <label>Demo Permit</label>
                        <select name="mob[demo_permit]" class="status-select" <?= $servicesDisabledAttr ?>>
                            <option value="NA" <?= ($mob['demo_permit'] ?? 'NA') == 'NA' ? 'selected' : '' ?>>NA</option>
                            <option value="Pending" <?= ($mob['demo_permit'] ?? '') == 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Complete" <?= ($mob['demo_permit'] ?? '') == 'Complete' ? 'selected' : '' ?>>Complete</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Demo Status</label>
                        <select name="mob[demo_status]" class="status-select" <?= $servicesDisabledAttr ?>>
                            <option value="Pending" <?= ($mob['demo_status'] ?? 'Pending') == 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="In Progress" <?= ($mob['demo_status'] ?? '') == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="Complete" <?= ($mob['demo_status'] ?? '') == 'Complete' ? 'selected' : '' ?>>Complete</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Excavation Permit</label>
                        <select name="mob[exc_permit]" class="status-select" <?= $servicesDisabledAttr ?>>
                            <option value="NA" <?= ($mob['exc_permit'] ?? 'NA') == 'NA' ? 'selected' : '' ?>>NA</option>
                            <option value="Pending" <?= ($mob['exc_permit'] ?? '') == 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Complete" <?= ($mob['exc_permit'] ?? '') == 'Complete' ? 'selected' : '' ?>>Complete</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Excavation Status</label>
                        <select name="mob[excavation_status]" class="status-select" <?= $servicesDisabledAttr ?>>
                            <option value="Pending" <?= ($mob['excavation_status'] ?? 'Pending') == 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="In Progress" <?= ($mob['excavation_status'] ?? '') == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="Complete" <?= ($mob['excavation_status'] ?? '') == 'Complete' ? 'selected' : '' ?>>Complete</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Temp Water</label>
                        <select name="mob[temporary_water]" class="status-select" <?= $servicesDisabledAttr ?>>
                            <option value="Not Started" <?= ($mob['temporary_water'] ?? '') == 'Not Started' ? 'selected' : '' ?>>Not Started</option>
                            <option value="In Process" <?= ($mob['temporary_water'] ?? '') == 'In Process' ? 'selected' : '' ?>>In Process</option>
                            <option value="Connected" <?= ($mob['temporary_water'] ?? '') == 'Connected' ? 'selected' : '' ?>>Connected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Temp Electricity</label>
                        <select name="mob[temporary_electricity]" class="status-select" <?= $servicesDisabledAttr ?>>
                            <option value="Not Started" <?= ($mob['temporary_electricity'] ?? '') == 'Not Started' ? 'selected' : '' ?>>Not Started</option>
                            <option value="In Process" <?= ($mob['temporary_electricity'] ?? '') == 'In Process' ? 'selected' : '' ?>>In Process</option>
                            <option value="Connected" <?= ($mob['temporary_electricity'] ?? '') == 'Connected' ? 'selected' : '' ?>>Connected</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <h2 style="border-bottom: 2px solid var(--border-glass); padding-bottom: 10px; margin-bottom: 1.5rem;">Blocks & Finishes Detail</h2>

        <?php if (empty($blocks)): ?>
            <div class="alert alert-info">No blocks have been created for this project yet. Edit the project settings to add blocks.</div>
        <?php endif; ?>

        <?php foreach ($blocks as $b): 
            $bId = $b['id'];
            $stmtLevels = $pdo->prepare("SELECT * FROM block_levels WHERE block_id = ? ORDER BY level_number ASC");
            $stmtLevels->execute([$bId]);
            $levels = $stmtLevels->fetchAll(PDO::FETCH_ASSOC);
            $finLevelColor = getFinishLevelColor($b['finish_level'] ?? '');
        ?>
            <details class="block-accordion" id="block-content-<?= $bId ?>">
                <summary>
                    <div>
                        <h3 style="margin:0; color: <?= $finLevelColor ?>; font-size: 1.2rem;">
                            <?= htmlspecialchars($b['block_name']) ?>
                            <span class="badge" style="font-size: 0.65rem; background: rgba(255,255,255,0.1); color: <?= $finLevelColor ?>; border: 1px solid <?= $finLevelColor ?>50; margin-left: 8px;">
                                <?= $b['finish_level'] ?: 'Level Unspecified' ?>
                            </span>
                        </h3>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">Type: <?= htmlspecialchars($b['block_type']) ?></div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 15px; min-width: 250px;">
                        <div style="flex: 1; height: 10px; background: rgba(255,255,255,0.1); border-radius: 5px; overflow: hidden; box-shadow: inset 0 1px 3px rgba(0,0,0,0.5);">
                            <div style="height: 100%; width: <?= $b['progress'] ?>%; background: <?= $b['progress'] == 100 ? '#22c55e' : 'var(--primary-color)' ?>; transition: width 0.5s;"></div>
                        </div>
                        <span style="font-weight: bold; color: #fff; width: 45px; text-align: right;"><?= $b['progress'] ?>%</span>
                        <span class="accordion-arrow">▼</span>
                    </div>
                </summary>

                <div style="padding: 1.5rem;">
                    
                    <div class="grid-container" style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 1.5rem;">
                        <div class="form-group">
                            <label>Finish Level Goal</label>
                            <select name="blocks[<?= $bId ?>][finish_level]" class="status-select" <?= $disabledAttr ?>>
                                <option value="" <?= empty($b['finish_level']) ? 'selected' : '' ?>>Unspecified</option>
                                <option value="Finished" <?= $b['finish_level'] === 'Finished' ? 'selected' : '' ?>>Finished</option>
                                <option value="Semi Finished" <?= $b['finish_level'] === 'Semi Finished' ? 'selected' : '' ?>>Semi Finished</option>
                                <option value="Common Parts Only" <?= $b['finish_level'] === 'Common Parts Only' ? 'selected' : '' ?>>Common Parts Only</option>
                                <option value="Shell" <?= $b['finish_level'] === 'Shell' ? 'selected' : '' ?>>Shell</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Overall Stage</label>
                            <select name="blocks[<?= $bId ?>][finishes_overall_status]" class="status-select" <?= $disabledAttr ?>>
                                <option value="Pending" <?= ($b['finishes_overall_status'] ?? 'Pending') == 'Pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="In Progress" <?= ($b['finishes_overall_status'] ?? '') == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="Complete" <?= ($b['finishes_overall_status'] ?? '') == 'Complete' ? 'selected' : '' ?>>Complete</option>
                                <option value="NA" <?= ($b['finishes_overall_status'] ?? '') == 'NA' ? 'selected' : '' ?>>NA</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Calculated Progress</label>
                            <input type="text" class="form-select" value="<?= $b['progress'] ?>% (Auto-calculated)" readonly style="background: rgba(255,255,255,0.05); color: #0ea5e9; font-weight: bold; cursor: not-allowed; border: 1px dashed rgba(14,165,233,0.5);">
                        </div>
                        <div class="form-group">
                            <label>Construction Complete</label>
                            <select name="blocks[<?= $bId ?>][construction_complete]" class="status-select" <?= $disabledAttr ?>>
                                <option value="No" <?= ($b['construction_complete'] ?? 'No') == 'No' ? 'selected' : '' ?>>No</option>
                                <option value="Yes" <?= ($b['construction_complete'] ?? '') == 'Yes' ? 'selected' : '' ?>>Yes</option>
                                <option value="NA" <?= ($b['construction_complete'] ?? '') == 'NA' ? 'selected' : '' ?>>NA</option>
                            </select>
                        </div>
                    </div>

                    <h4 style="margin-top: 0; color: #0ea5e9;">1. Structural Construction (By Floor)</h4>
                    <table class="finishes-table mb-4">
                        <thead>
                            <tr>
                                <th style="width: 200px;">Floor / Level</th>
                                <th>Structural Status</th>
                            </tr>
                        </thead>
                        <tbody class="construction-table-body">
                            <?php foreach ($levels as $lvl): ?>
                                <tr>
                                    <td style="font-weight: bold; color: #fff;"><?= htmlspecialchars($lvl['level_name']) ?></td>
                                    <td>
                                        <select name="levels[<?= $bId ?>][<?= $lvl['id'] ?>][construction_status]" class="status-select const-status" <?= $disabledAttr ?>>
                                            <option value="Pending" <?= $lvl['construction_status'] == 'Pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="In Progress" <?= $lvl['construction_status'] == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                            <option value="Complete" <?= $lvl['construction_status'] == 'Complete' ? 'selected' : '' ?>>Complete</option>
                                            <option value="NA" <?= $lvl['construction_status'] == 'NA' ? 'selected' : '' ?>>NA</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 2rem; margin-bottom: 10px;">
                        <h4 style="margin: 0; color: #f59e0b;">2. Block-Level Finishes</h4>
                        
                        <?php if ($canUpdateStatus): ?>
                        <div style="display: flex; gap: 10px; align-items: center; background: rgba(0,0,0,0.3); padding: 5px 10px; border-radius: 6px; border: 1px dashed rgba(255,255,255,0.1);">
                            <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: bold;">⚡ QUICK FILL:</span>
                            <button type="button" class="btn btn-sm" style="background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); margin: 0; padding: 4px 8px; font-size: 0.75rem;" onclick="quickFillBlock(<?= $bId ?>, 'Complete')">Mark All Complete</button>
                            <button type="button" class="btn btn-sm" style="background: rgba(156, 163, 175, 0.1); color: #9ca3af; border: 1px solid rgba(156, 163, 175, 0.3); margin: 0; padding: 4px 8px; font-size: 0.75rem;" onclick="quickFillBlock(<?= $bId ?>, 'Not Required')">Set Not Required</button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php
                    function renderFinSelect($name, $currentVal, $disabledAttr) {
                        $html = '<select name="'.$name.'" class="status-select fin-status" '.$disabledAttr.'>';
                        $html .= '<option value="Not Required" '.($currentVal == 'Not Required' ? 'selected' : '').'>Not Required</option>';
                        $html .= '<option value="Pending" '.($currentVal == 'Pending' ? 'selected' : '').'>Pending</option>';
                        $html .= '<option value="In Progress" '.($currentVal == 'In Progress' ? 'selected' : '').'>In Progress</option>';
                        $html .= '<option value="Complete" '.($currentVal == 'Complete' ? 'selected' : '').'>Complete</option>';
                        $html .= '<option value="NA" '.($currentVal == 'NA' ? 'selected' : '').'>NA</option>';
                        $html .= '</select>';
                        return $html;
                    }
                    ?>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem;">
                        
                        <div>
                            <table class="finishes-table">
                                <thead><tr><th colspan="2">M&E (Block Level)</th></tr></thead>
                                <tbody>
                                    <tr><td>Electrical Prep</td><td><?= renderFinSelect("blocks[$bId][fin_electrical]", $b['fin_electrical'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Plumbing Prep</td><td><?= renderFinSelect("blocks[$bId][fin_plumbing]", $b['fin_plumbing'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Pumps</td><td><?= renderFinSelect("blocks[$bId][fin_pumps]", $b['fin_pumps'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Lifts</td><td><?= renderFinSelect("blocks[$bId][fin_lifts]", $b['fin_lifts'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Substation</td><td><?= renderFinSelect("blocks[$bId][fin_substation]", $b['fin_substation'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Septic Tank</td><td><?= renderFinSelect("blocks[$bId][fin_septic]", $b['fin_septic'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Sewer Connection</td><td><?= renderFinSelect("blocks[$bId][fin_sewer]", $b['fin_sewer'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <table class="finishes-table">
                                <thead><tr><th colspan="2">Safety & Common Areas</th></tr></thead>
                                <tbody>
                                    <tr><td>Fire Detection</td><td><?= renderFinSelect("blocks[$bId][fin_fire_detection]", $b['fin_fire_detection'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Fire Fighting</td><td><?= renderFinSelect("blocks[$bId][fin_fire_fighting]", $b['fin_fire_fighting'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Fire Doors</td><td><?= renderFinSelect("blocks[$bId][fin_fire_doors]", $b['fin_fire_doors'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Intercoms</td><td><?= renderFinSelect("blocks[$bId][fin_intercoms]", $b['fin_intercoms'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Garden/Landscaping</td><td><?= renderFinSelect("blocks[$bId][fin_garden]", $b['fin_garden'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Pool</td><td><?= renderFinSelect("blocks[$bId][fin_pool]", $b['fin_pool'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <table class="finishes-table">
                                <thead><tr><th colspan="2">Render, Gypsum & Cladding</th></tr></thead>
                                <tbody>
                                    <tr><td>Render Facade</td><td><?= renderFinSelect("blocks[$bId][fin_rend_facade]", $b['fin_rend_facade'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Render Appogg</td><td><?= renderFinSelect("blocks[$bId][fin_rend_appogg]", $b['fin_rend_appogg'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Render Back</td><td><?= renderFinSelect("blocks[$bId][fin_rend_back]", $b['fin_rend_back'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Render Common Parts</td><td><?= renderFinSelect("blocks[$bId][fin_rend_cp]", $b['fin_rend_cp'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Gypsum Common Parts</td><td><?= renderFinSelect("blocks[$bId][fin_gypsum_cp]", $b['fin_gypsum_cp'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Gypsum Facade</td><td><?= renderFinSelect("blocks[$bId][fin_gypsum_facade]", $b['fin_gypsum_facade'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Cladding</td><td><?= renderFinSelect("blocks[$bId][fin_cladding]", $b['fin_cladding'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <table class="finishes-table">
                                <thead><tr><th colspan="2">Marble, Waterproofing & Fixtures</th></tr></thead>
                                <tbody>
                                    <tr><td>Marble Common Parts</td><td><?= renderFinSelect("blocks[$bId][fin_marble_cp]", $b['fin_marble_cp'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Marble Window Sills</td><td><?= renderFinSelect("blocks[$bId][fin_marble_sills]", $b['fin_marble_sills'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Waterproofing Roof</td><td><?= renderFinSelect("blocks[$bId][fin_wp_roof]", $b['fin_wp_roof'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Waterproofing Shafts</td><td><?= renderFinSelect("blocks[$bId][fin_wp_shafts]", $b['fin_wp_shafts'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Waterproofing Ext Walls</td><td><?= renderFinSelect("blocks[$bId][fin_wp_ext]", $b['fin_wp_ext'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Common Parts Doors/Win</td><td><?= renderFinSelect("blocks[$bId][fin_cp_doors_win]", $b['fin_cp_doors_win'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Internal Railings</td><td><?= renderFinSelect("blocks[$bId][fin_int_railings]", $b['fin_int_railings'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <table class="finishes-table">
                                <thead><tr><th colspan="2">Apartment / Balcony Elements</th></tr></thead>
                                <tbody>
                                    <tr><td>Partitions</td><td><?= renderFinSelect("blocks[$bId][fin_partitions]", $b['fin_partitions'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Water Tanks</td><td><?= renderFinSelect("blocks[$bId][fin_water_tanks]", $b['fin_water_tanks'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Waterproofing Balconies</td><td><?= renderFinSelect("blocks[$bId][fin_wp_balconies]", $b['fin_wp_balconies'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Tiles Balconies</td><td><?= renderFinSelect("blocks[$bId][fin_tile_balconies]", $b['fin_tile_balconies'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Apt Fire Doors</td><td><?= renderFinSelect("blocks[$bId][fin_apt_fire_doors]", $b['fin_apt_fire_doors'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Apt Doors & Windows</td><td><?= renderFinSelect("blocks[$bId][fin_apt_doors_win]", $b['fin_apt_doors_win'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>External Railings</td><td><?= renderFinSelect("blocks[$bId][fin_ext_railings]", $b['fin_ext_railings'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <table class="finishes-table">
                                <thead><tr><th colspan="2">Garage Level Specifics</th></tr></thead>
                                <tbody>
                                    <tr><td>Render Common Parts</td><td><?= renderFinSelect("blocks[$bId][fin_gar_rend_cp]", $b['fin_gar_rend_cp'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Render Individual</td><td><?= renderFinSelect("blocks[$bId][fin_gar_rend]", $b['fin_gar_rend'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Main Door</td><td><?= renderFinSelect("blocks[$bId][fin_gar_main_door]", $b['fin_gar_main_door'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Ventilation</td><td><?= renderFinSelect("blocks[$bId][fin_gar_vent]", $b['fin_gar_vent'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Individual Doors</td><td><?= renderFinSelect("blocks[$bId][fin_gar_ind_doors]", $b['fin_gar_ind_doors'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                    <tr><td>Windows</td><td><?= renderFinSelect("blocks[$bId][fin_gar_win]", $b['fin_gar_win'] ?? 'Not Required', $disabledAttr) ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <h4 style="margin-top: 2rem; color: #a855f7;">3. Interior Finishes (By Floor)</h4>
                    <div style="overflow-x: auto;">
                        <table class="finishes-table" style="min-width: 1000px;">
                            <thead>
                                <tr>
                                    <th>Level</th>
                                    <?php foreach($finishTypes as $ft): ?>
                                        <th style="text-align: center; font-size: 0.7rem;"><?= htmlspecialchars($ft['name']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($levels as $lvl): ?>
                                    <tr>
                                        <td style="font-weight: bold; color: #fff;"><?= htmlspecialchars($lvl['level_name']) ?></td>
                                        <?php foreach($finishTypes as $ft): 
                                            $currStatus = $floorStatuses[$lvl['id']][$ft['id']] ?? 'Not Required';
                                        ?>
                                            <td>
                                                <select name="floor_finishes[<?= $bId ?>][<?= $lvl['id'] ?>][<?= $ft['id'] ?>]" class="status-select floor-fin-status" style="font-size: 0.75rem;" <?= $disabledAttr ?>>
                                                    <option value="Not Required" <?= $currStatus == 'Not Required' ? 'selected' : '' ?>>Not Required</option>
                                                    <option value="Pending" <?= $currStatus == 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="In Progress" <?= $currStatus == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                                    <option value="Complete" <?= $currStatus == 'Complete' ? 'selected' : '' ?>>Complete</option>
                                                    <option value="NA" <?= $currStatus == 'NA' ? 'selected' : '' ?>>NA</option>
                                                </select>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <h4 style="margin-top: 2rem; color: #10b981;">4. Handover & Post Compliance</h4>
                    <div class="grid-container" style="background: rgba(16, 185, 129, 0.05); padding: 15px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.2);">
                        <div class="form-group">
                            <label>Compliance Submitted</label>
                            <select name="blocks[<?= $bId ?>][compliance_submitted]" class="status-select" <?= $disabledAttr ?>>
                                <option value="No" <?= ($b['compliance_submitted'] ?? 'No') == 'No' ? 'selected' : '' ?>>No</option>
                                <option value="Yes" <?= ($b['compliance_submitted'] ?? '') == 'Yes' ? 'selected' : '' ?>>Yes</option>
                                <option value="NA" <?= ($b['compliance_submitted'] ?? '') == 'NA' ? 'selected' : '' ?>>NA</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Compliance Certified</label>
                            <select name="blocks[<?= $bId ?>][compliance_certified]" class="status-select" <?= $disabledAttr ?>>
                                <option value="No" <?= ($b['compliance_certified'] ?? 'No') == 'No' ? 'selected' : '' ?>>No</option>
                                <option value="Yes" <?= ($b['compliance_certified'] ?? '') == 'Yes' ? 'selected' : '' ?>>Yes</option>
                                <option value="NA" <?= ($b['compliance_certified'] ?? '') == 'NA' ? 'selected' : '' ?>>NA</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Condominium Formed</label>
                            <select name="blocks[<?= $bId ?>][condominium_formed]" class="status-select" <?= $disabledAttr ?>>
                                <option value="No" <?= ($b['condominium_formed'] ?? 'No') == 'No' ? 'selected' : '' ?>>No</option>
                                <option value="Yes" <?= ($b['condominium_formed'] ?? '') == 'Yes' ? 'selected' : '' ?>>Yes</option>
                                <option value="NA" <?= ($b['condominium_formed'] ?? '') == 'NA' ? 'selected' : '' ?>>NA</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>CP Meters Installed</label>
                            <select name="blocks[<?= $bId ?>][cp_meters_installed]" class="status-select" <?= $disabledAttr ?>>
                                <option value="No" <?= ($b['cp_meters_installed'] ?? 'No') == 'No' ? 'selected' : '' ?>>No</option>
                                <option value="Yes" <?= ($b['cp_meters_installed'] ?? '') == 'Yes' ? 'selected' : '' ?>>Yes</option>
                                <option value="NA" <?= ($b['cp_meters_installed'] ?? '') == 'NA' ? 'selected' : '' ?>>NA</option>
                            </select>
                        </div>
                    </div>

                </div>
            </details>
            <?php endforeach; ?>

        <?php if ($canUpdateStatus || $canEditServices): ?>
            <div class="sticky-save-bar">
                <span style="color: var(--text-muted); font-size: 0.9rem;">Progress percentages will automatically recalculate on save.</span>
                <button type="submit" class="btn btn-primary" style="margin: 0; font-size: 1.1rem; padding: 10px 30px; box-shadow: 0 4px 10px rgba(14, 165, 233, 0.4);">💾 Save All Project Changes</button>
            </div>
        <?php endif; ?>
    </form>
</div>

<script>
const canEditStatus = <?= $canUpdateStatus ? 'true' : 'false' ?>;

// Auto-fill button function replacing the dangerous override dropdown
function quickFillBlock(blockId, status) {
    if (!canEditStatus) return;
    
    let msg = status === 'Complete' 
        ? "Are you sure you want to mark ALL finishes in this block as 'Complete'?" 
        : "Are you sure you want to set ALL finishes in this block to 'Not Required'?";
        
    if (!confirm(msg + "\n\nYou must click 'Save All' at the bottom to finalize this action.")) return;
    
    const blockDiv = document.getElementById('block-content-' + blockId);
    if (!blockDiv) return;
    
    // Find all select elements assigned the fin-status or floor-fin-status classes
    const selects = blockDiv.querySelectorAll('select.fin-status, select.floor-fin-status');
    selects.forEach(sel => {
        // Make sure the dropdown actually contains the target option before setting it
        let optionExists = Array.from(sel.options).some(opt => opt.value === status);
        if (optionExists) {
            sel.value = status;
        }
    });
}

// Logic to lock levels until previous level is complete
function enforceSequentialConstruction() {
    if (!canEditStatus) return; 

    document.querySelectorAll('.construction-table-body').forEach(tbody => {
        const rows = tbody.querySelectorAll('tr');
        let canStartNext = true;

        rows.forEach(row => {
            const select = row.querySelector('.const-status');
            if (!select) return;

            if (!canStartNext) {
                select.value = 'Pending';
                select.style.pointerEvents = 'none';
                select.style.background = 'var(--bg-primary)';
                select.style.opacity = '0.5';
                row.style.opacity = '0.6';
                row.title = "🔒 Previous floor must be Complete to unlock this level.";
            } else {
                select.style.pointerEvents = 'auto';
                select.style.background = '#1e1e2d';
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
