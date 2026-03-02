<?php
require_once 'init.php';
require_once 'session-check.php';

$projectId = $_GET['project_id'] ?? $_GET['projectid'] ?? null;
if (!$projectId) { header('Location: dashboard.php'); exit; }

if (!hasProjectAccess($pdo, $projectId)) { header('Location: dashboard.php?error=access_denied'); exit; }

$project = getProjectWithClient($pdo, $projectId);
if (!$project) { header('Location: dashboard.php'); exit; }

if ($project['is_tracking'] == 1 && !hasPermission('view_tracking') && !isAdmin()) {
    header('Location: dashboard.php?error=access_denied'); exit;
}

$canUpdateStatus = canUpdateStatus($pdo, $projectId);
$canEditServices = hasPermission('edit_services') || isAdmin();
$disabledAttr = $canUpdateStatus ? '' : 'disabled';
$servicesDisabledAttr = $canEditServices ? '' : 'disabled';

$message = ''; $error = '';

// ==========================================
// HANDLE POST REQUESTS (Logs, BCA, Blocks, Services)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_log'])) {
        $logMsg = trim($_POST['log_message'] ?? '');
        if (!empty($logMsg)) {
            $pdo->prepare("INSERT INTO project_logs (project_id, user_id, message) VALUES (?, ?, ?)")->execute([$projectId, getCurrentUserId(), $logMsg]);
            header("Location: mobilisation_detail.php?project_id=$projectId#project-log"); exit;
        }
    }
    
    if (($_POST['action'] ?? null) === 'update_mobilisation' && $canUpdateStatus) {
        try {
            $updates = []; $values = [];
            $allowedFields = ['acquisition_complete', 'acquisition_date', 'archaeologist_assigned', 'change_of_applicant', 'geological_test', 'condition_report_contacts', 'condition_reports', 'method_statements', 'insurance_status', 'pavement_guarantee', 'wellbeing_guarantee', 'umbrella_guarantee', 'responsibility_form', 'mob_demolition', 'mob_excavation', 'mob_construction'];
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
            if (isset($_POST['blocks']) && is_array($_POST['blocks'])) {
                $bStmt = $pdo->prepare("UPDATE project_blocks SET compliance_submitted=?, compliance_certified=?, condominium_formed=?, cp_meters_installed=? WHERE id=? AND project_id=?");
                foreach ($_POST['blocks'] as $bId => $bData) {
                    $bStmt->execute([$bData['compliance_submitted'] ?? 'No', $bData['compliance_certified'] ?? 'No', $bData['condominium_formed'] ?? 'No', $bData['cp_meters_installed'] ?? 'No', $bId, $projectId]);
                }
            }
            if (isset($_POST['levels']) && is_array($_POST['levels'])) {
                $lStmt = $pdo->prepare("UPDATE block_levels SET construction_status=?, finishes_status=? WHERE id=?");
                foreach ($_POST['levels'] as $lId => $lData) {
                    $lStmt->execute([$lData['construction_status'] ?? 'Pending', $lData['finishes_status'] ?? 'Pending', $lId]);
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
$logsStmt = $pdo->prepare("SELECT pl.*, u.username, u.first_name, u.last_name FROM project_logs pl JOIN users u ON pl.user_id = u.id WHERE pl.project_id = ? ORDER BY pl.created_at DESC LIMIT 100");
$logsStmt->execute([$projectId]);
$projectLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

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

$stagesEnum = [
    'Feasibility' => 1, 'Tracking' => 2, 'Permit' => 3, 'Mobilisation' => 4,
    'Demolition' => 5, 'Excavation' => 6, 'Construction' => 7, 'Finishes' => 8,
    'Compliance' => 9, 'Condominium' => 10, 'Handed Over' => 11
];
$stageNum = $stagesEnum[$currentStageName] ?? 1;
$progressPercent = min(100, round(($stageNum / 11) * 100));

// Determine which accordions should be open
$bcaOpen = ($stageNum <= 6) ? 'open' : '';
$execOpen = ($stageNum >= 6) ? 'open' : '';

// UI Locks for BCA
$canSequential = (($mob['geological_test'] ?? 'NA') === 'Complete' || ($mob['geological_test'] ?? 'NA') === 'NA') && (($mob['condition_reports'] ?? 'Not Started') === 'Complete' || ($mob['condition_reports'] ?? 'Not Started') === 'NA');
$allSeqComplete = true;
foreach (['method_statements', 'insurance_status', 'pavement_guarantee', 'wellbeing_guarantee', 'umbrella_guarantee'] as $field) {
    if (($mob[$field] ?? 'Not Complete') !== 'Complete') { $allSeqComplete = false; break; }
}
$canFinal = $allSeqComplete;
$canClearance = ($mob['responsibility_form'] ?? 'Not Complete') === 'Complete';

$pageTitle = 'Execution - ' . $project['name'];
require_once 'header.php';
?>

<style>
/* Custom Accordion Styles */
.custom-accordion {
    background: var(--bg-card);
    border: 1px solid var(--border-glass);
    border-radius: var(--radius-md);
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
}
.custom-accordion summary {
    padding: 1.25rem 1.5rem;
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--text-primary);
    cursor: pointer;
    background: rgba(255,255,255,0.02);
    list-style: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: var(--radius-md);
    user-select: none;
    transition: background 0.2s ease;
}
.custom-accordion summary:hover { background: rgba(255,255,255,0.05); }
.custom-accordion summary::-webkit-details-marker { display: none; }
.custom-accordion summary::after { content: '▼'; font-size: 1rem; color: var(--primary-color); transition: transform 0.3s ease; }
.custom-accordion[open] summary::after { transform: rotate(180deg); }
.custom-accordion[open] summary { border-bottom-left-radius: 0; border-bottom-right-radius: 0; border-bottom: 1px solid var(--border-glass); }
.accordion-content { padding: 1.5rem; }

/* Stage Tracker Styles */
.stage-tracker {
    display: flex; align-items: center; justify-content: space-between;
    background: var(--bg-card); border: 1px solid var(--border-glass);
    border-radius: var(--radius-md); padding: 1.5rem; margin-bottom: 2rem;
}
.stage-tracker-info { flex: 1; }
.stage-badge {
    display: inline-block; padding: 0.5rem 1rem; border-radius: 20px;
    background: rgba(99, 102, 241, 0.15); color: var(--primary-color);
    font-weight: 700; font-size: 1.1rem; border: 1px solid rgba(99, 102, 241, 0.3);
}
.progress-bar-bg { height: 10px; background: rgba(255,255,255,0.1); border-radius: 5px; margin-top: 1rem; overflow: hidden; }
.progress-bar-fill { height: 100%; background: linear-gradient(90deg, var(--primary-color), var(--secondary-color)); transition: width 0.5s ease; }
</style>

<div class="main-container">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h1 class="page-title" style="margin-bottom: 0;"><?php echo htmlspecialchars($project['name']); ?></h1>
    </div>

    <div class="stage-tracker">
        <div class="stage-tracker-info">
            <div style="font-size: 0.85rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.5rem;">Current System Stage</div>
            <div class="stage-badge">Stage <?= $stageNum ?>/11: <?= $currentStageName ?></div>
            <div class="progress-bar-bg">
                <div class="progress-bar-fill" style="width: <?= $progressPercent ?>%;"></div>
            </div>
            <div style="margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-muted);">Status auto-calculates based on clearance and block progress below.</div>
        </div>
    </div>

    <?php if ($project['summer_break_flag'] == 1): ?>
        <div class="alert alert-error" style="display: flex; align-items: center; gap: 1rem; border-left: 5px solid var(--danger); margin-bottom: 1.5rem;">
            <span style="font-size: 1.5rem;">☀️</span>
            <div><strong>Summer Break Alarm Active</strong><br>This project is subject to Malta Summer Break restrictions. Demolition and Excavation phases are heavily impacted.</div>
        </div>
    <?php endif; ?>

    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="projects-section" style="margin-bottom: 2rem;">
        <div class="project-meta">
            <div class="meta-item"><span class="meta-label">Client: </span><span><?php echo htmlspecialchars($project['client_name'] ?? 'Unknown'); ?></span></div>
            <div class="meta-item"><span class="meta-label">Type: </span><span><?php echo ucwords(str_replace('-', ' ', $project['type'])); ?></span></div>
            <div class="meta-item"><span class="meta-label">City: </span><span><?php echo htmlspecialchars($project['city']); ?></span></div>
            <div class="meta-item"><span class="meta-label">Finish Level: </span><span style="color: var(--primary-color); font-weight: 600;"><?php echo htmlspecialchars($project['finishlevel'] ?? 'N/A'); ?></span></div>
        </div>
    </div>

    <details class="custom-accordion" id="project-log">
        <summary>💬 Project Activity Log</summary>
        <div class="accordion-content">
            <form method="POST" class="log-input-form">
                <div class="log-input-container" style="display: flex; gap: 0.5rem;">
                    <textarea name="log_message" class="log-textarea" placeholder="Add update, next step, or note..." required style="flex:1; padding: 0.5rem;"></textarea>
                    <button type="submit" name="add_log" class="btn btn-primary btn-sm">Post</button>
                </div>
            </form>
            <div class="log-container" style="max-height: 300px; overflow-y: auto; margin-top: 1rem;">
                <?php if (empty($projectLogs)): ?>
                    <p style="color: var(--text-muted);">No activity logs yet.</p>
                <?php else: ?>
                    <?php foreach ($projectLogs as $log): ?>
                        <div style="padding: 0.75rem; background: var(--bg-secondary); margin-bottom: 0.5rem; border-radius: 6px; border-left: 3px solid <?= getUserColor($log['username']) ?>">
                            <strong style="color: <?= getUserColor($log['username']) ?>;">@<?= htmlspecialchars($log['username']) ?></strong>
                            <span style="font-size: 0.8rem; color: var(--text-muted); margin-left: 0.5rem;"><?= date('d M Y, H:i', strtotime($log['created_at'])) ?></span>
                            <div style="margin-top: 0.25rem;"><?= htmlspecialchars($log['message']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </details>

    <details class="custom-accordion" <?= $bcaOpen ?>>
        <summary>📋 Pre-Construction & BCA Clearances</summary>
        <div class="accordion-content">
            <form method="POST" class="form-grid">
                <input type="hidden" name="action" value="update_mobilisation">

                <?php if ($project['type'] === 'in-house'): ?>
                    <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem;">
                        <legend style="font-weight: 600;">🏠 Acquisition Complete</legend>
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group"><label>Status</label><select name="acquisition_complete" <?= $disabledAttr ?>><option value="No" <?= ($mob['acquisition_complete'] ?? 'No') === 'No' ? 'selected' : '' ?>>No</option><option value="Yes" <?= ($mob['acquisition_complete'] ?? 'No') === 'Yes' ? 'selected' : '' ?>>Yes</option></select></div>
                            <div class="form-group"><label>Date</label><input type="date" name="acquisition_date" value="<?= $mob['acquisition_date'] ?? '' ?>" <?= $disabledAttr ?>></div>
                        </div>
                    </fieldset>
                <?php endif; ?>

                <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem;">
                    <legend style="font-weight: 600;">📋 Non-Sequential Tasks</legend>
                    <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div class="form-group"><label>Archaeologist Assigned</label><select name="archaeologist_assigned" <?= $disabledAttr ?>><option value="NA" <?= ($mob['archaeologist_assigned'] ?? 'NA') === 'NA' ? 'selected' : '' ?>>N/A</option><option value="Yes" <?= ($mob['archaeologist_assigned'] ?? 'NA') === 'Yes' ? 'selected' : '' ?>>Yes</option><option value="No" <?= ($mob['archaeologist_assigned'] ?? 'NA') === 'No' ? 'selected' : '' ?>>No</option></select></div>
                        <div class="form-group"><label>Change of Applicant</label><select name="change_of_applicant" <?= $disabledAttr ?>><option value="NA" <?= ($mob['change_of_applicant'] ?? 'NA') === 'NA' ? 'selected' : '' ?>>N/A</option><option value="Complete" <?= ($mob['change_of_applicant'] ?? 'NA') === 'Complete' ? 'selected' : '' ?>>Complete</option><option value="Not Complete" <?= ($mob['change_of_applicant'] ?? 'NA') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option></select></div>
                        <div class="form-group"><label>Geological Test</label><select name="geological_test" <?= $disabledAttr ?>><option value="NA" <?= ($mob['geological_test'] ?? 'NA') === 'NA' ? 'selected' : '' ?>>N/A</option><option value="Complete" <?= ($mob['geological_test'] ?? 'NA') === 'Complete' ? 'selected' : '' ?>>Complete</option><option value="Not Complete" <?= ($mob['geological_test'] ?? 'NA') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Awaiting Result" <?= ($mob['geological_test'] ?? 'NA') === 'Awaiting Result' ? 'selected' : '' ?>>Awaiting Result</option></select></div>
                        <div class="form-group"><label>Condition Report Contacts</label><select name="condition_report_contacts" <?= $disabledAttr ?>><option value="Not Started" <?= ($mob['condition_report_contacts'] ?? 'Not Started') === 'Not Started' ? 'selected' : '' ?>>Not Started</option><option value="In Process" <?= ($mob['condition_report_contacts'] ?? 'Not Started') === 'In Process' ? 'selected' : '' ?>>In Process</option><option value="Complete" <?= ($mob['condition_report_contacts'] ?? 'Not Started') === 'Complete' ? 'selected' : '' ?>>Complete</option><option value="NA" <?= ($mob['condition_report_contacts'] ?? 'Not Started') === 'NA' ? 'selected' : '' ?>>NA</option></select></div>
                        <div class="form-group"><label>Condition Reports</label><select name="condition_reports" <?= $disabledAttr ?>><option value="Not Started" <?= ($mob['condition_reports'] ?? 'Not Started') === 'Not Started' ? 'selected' : '' ?>>Not Started</option><option value="In Process" <?= ($mob['condition_reports'] ?? 'Not Started') === 'In Process' ? 'selected' : '' ?>>In Process</option><option value="Complete" <?= ($mob['condition_reports'] ?? 'Not Started') === 'Complete' ? 'selected' : '' ?>>Complete</option><option value="NA" <?= ($mob['condition_reports'] ?? 'Not Started') === 'NA' ? 'selected' : '' ?>>NA</option></select></div>
                    </div>
                </fieldset>

                <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; opacity: <?= $canSequential ? '1' : '0.5' ?>; margin-bottom: 1.5rem;">
                    <legend style="font-weight: 600;">🔗 Sequential Chain</legend>
                    <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div class="form-group"><label>Method Statements</label><select name="method_statements" <?= !$canSequential ? 'disabled' : $disabledAttr ?>><option value="Not Complete" <?= ($mob['method_statements'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Complete" <?= ($mob['method_statements'] ?? 'Not Complete') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div>
                        <div class="form-group"><label>Insurance</label><select name="insurance_status" <?= !$canSequential ? 'disabled' : $disabledAttr ?>><option value="Not Started" <?= ($mob['insurance_status'] ?? 'Not Started') === 'Not Started' ? 'selected' : '' ?>>Not Started</option><option value="In Process" <?= ($mob['insurance_status'] ?? 'Not Started') === 'In Process' ? 'selected' : '' ?>>In Process</option><option value="Complete" <?= ($mob['insurance_status'] ?? 'Not Started') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div>
                        <div class="form-group"><label>Pavement Guarantee</label><select name="pavement_guarantee" <?= !$canSequential ? 'disabled' : $disabledAttr ?>><option value="Not Started" <?= ($mob['pavement_guarantee'] ?? 'Not Started') === 'Not Started' ? 'selected' : '' ?>>Not Started</option><option value="In Process" <?= ($mob['pavement_guarantee'] ?? 'Not Started') === 'In Process' ? 'selected' : '' ?>>In Process</option><option value="Complete" <?= ($mob['pavement_guarantee'] ?? 'Not Started') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div>
                        <div class="form-group"><label>Wellbeing Guarantee</label><select name="wellbeing_guarantee" <?= !$canSequential ? 'disabled' : $disabledAttr ?>><option value="Not Started" <?= ($mob['wellbeing_guarantee'] ?? 'Not Started') === 'Not Started' ? 'selected' : '' ?>>Not Started</option><option value="In Process" <?= ($mob['wellbeing_guarantee'] ?? 'Not Started') === 'In Process' ? 'selected' : '' ?>>In Process</option><option value="Complete" <?= ($mob['wellbeing_guarantee'] ?? 'Not Started') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div>
                        <div class="form-group"><label>Umbrella Guarantee</label><select name="umbrella_guarantee" <?= !$canSequential ? 'disabled' : $disabledAttr ?>><option value="Not Started" <?= ($mob['umbrella_guarantee'] ?? 'Not Started') === 'Not Started' ? 'selected' : '' ?>>Not Started</option><option value="In Process" <?= ($mob['umbrella_guarantee'] ?? 'Not Started') === 'In Process' ? 'selected' : '' ?>>In Process</option><option value="Complete" <?= ($mob['umbrella_guarantee'] ?? 'Not Started') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div>
                    </div>
                </fieldset>

                <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; opacity: <?= $canFinal ? '1' : '0.5' ?>;">
                    <legend style="font-weight: 600;">🏗️ Clearance Phase</legend>
                    <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div class="form-group"><label>Responsibility Form</label><select name="responsibility_form" <?= !$canFinal ? 'disabled' : $disabledAttr ?>><option value="Not Complete" <?= ($mob['responsibility_form'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Complete" <?= ($mob['responsibility_form'] ?? 'Not Complete') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div>
                        <div class="form-group" style="background: rgba(239, 68, 68, 0.1); padding: 0.5rem; border-radius: 6px; border-left: 3px solid var(--danger);"><label>Demolition Clearance</label><select name="mob_demolition" <?= !$canClearance ? 'disabled' : $disabledAttr ?>><option value="No" <?= ($mob['mob_demolition'] ?? 'No') === 'No' ? 'selected' : '' ?>>No Clearance</option><option value="Yes" <?= ($mob['mob_demolition'] ?? 'No') === 'Yes' ? 'selected' : '' ?>>Cleared</option><option value="NA" <?= ($mob['mob_demolition'] ?? 'No') === 'NA' ? 'selected' : '' ?>>N/A</option></select></div>
                        <div class="form-group" style="background: rgba(245, 158, 11, 0.1); padding: 0.5rem; border-radius: 6px; border-left: 3px solid var(--warning);"><label>Excavation Clearance</label><select name="mob_excavation" <?= !$canClearance ? 'disabled' : $disabledAttr ?>><option value="No" <?= ($mob['mob_excavation'] ?? 'No') === 'No' ? 'selected' : '' ?>>No Clearance</option><option value="Yes" <?= ($mob['mob_excavation'] ?? 'No') === 'Yes' ? 'selected' : '' ?>>Cleared</option><option value="NA" <?= ($mob['mob_excavation'] ?? 'No') === 'NA' ? 'selected' : '' ?>>N/A</option></select></div>
                        <div class="form-group" style="background: rgba(34, 197, 94, 0.1); padding: 0.5rem; border-radius: 6px; border-left: 3px solid var(--success);"><label>Construction Clearance</label><select name="mob_construction" <?= !$canClearance ? 'disabled' : $disabledAttr ?>><option value="No" <?= ($mob['mob_construction'] ?? 'No') === 'No' ? 'selected' : '' ?>>No Clearance</option><option value="Yes" <?= ($mob['mob_construction'] ?? 'No') === 'Yes' ? 'selected' : '' ?>>Cleared</option><option value="NA" <?= ($mob['mob_construction'] ?? 'No') === 'NA' ? 'selected' : '' ?>>N/A</option></select></div>
                    </div>
                </fieldset>

                <?php if ($canUpdateStatus): ?>
                    <div class="form-actions" style="margin-top: 1rem;">
                        <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem;">Save BCA Updates</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </details>

    <details class="custom-accordion" <?= $execOpen ?>>
        <summary>🏢 Block Execution & Progress</summary>
        <div class="accordion-content">
            <?php if (empty($projectBlocks)): ?>
                <div class="alert alert-info">No blocks defined for this project. <a href="edit-project.php?id=<?= $projectId ?>" style="color:white; text-decoration:underline;">Edit Project</a> to add blocks.</div>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="update_blocks">
                    
                    <?php 
                    $requiresFinishes = !in_array($project['finishlevel'], ['Shell', null, '']);
                    foreach ($projectBlocks as $block): 
                    ?>
                        <fieldset style="border: 1px solid var(--primary-color); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; background: rgba(99, 102, 241, 0.02);">
                            <legend style="font-weight: 600; color: var(--primary-color); font-size: 1.1rem; padding: 0 0.5rem; background: var(--bg-card); border-radius: 4px;">
                                <?= htmlspecialchars($block['block_name']) ?> (<?= htmlspecialchars($block['block_type']) ?>)
                            </legend>
                            
                            <h4 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Level-by-Level Execution</h4>
                            <div class="table-container" style="margin-bottom: 2rem; background: var(--bg-primary);">
                                <table class="data-table" style="background: transparent;">
                                    <thead><tr><th>Level</th><th>Construction Status</th><th>Finishes Status</th></tr></thead>
                                    <tbody>
                                        <?php 
                                        $levels = $blockLevels[$block['id']] ?? [];
                                        foreach ($levels as $lvl): 
                                        ?>
                                            <tr>
                                                <td style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($lvl['level_name']) ?></td>
                                                <td>
                                                    <select name="levels[<?= $lvl['id'] ?>][construction_status]" <?= $disabledAttr ?> style="padding: 0.4rem; font-size: 0.85rem; width: 80%;">
                                                        <option value="Pending" <?= $lvl['construction_status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                        <option value="In Progress" <?= $lvl['construction_status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                                        <option value="Complete" <?= $lvl['construction_status'] === 'Complete' ? 'selected' : '' ?>>Complete</option>
                                                        <option value="NA" <?= $lvl['construction_status'] === 'NA' ? 'selected' : '' ?>>N/A</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <?php if ($requiresFinishes): ?>
                                                        <select name="levels[<?= $lvl['id'] ?>][finishes_status]" <?= $disabledAttr ?> style="padding: 0.4rem; font-size: 0.85rem; width: 80%;">
                                                            <option value="Pending" <?= $lvl['finishes_status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                            <option value="In Progress" <?= $lvl['finishes_status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                                            <option value="Complete" <?= $lvl['finishes_status'] === 'Complete' ? 'selected' : '' ?>>Complete</option>
                                                            <option value="NA" <?= $lvl['finishes_status'] === 'NA' ? 'selected' : '' ?>>N/A</option>
                                                        </select>
                                                    <?php else: ?>
                                                        <select disabled style="padding: 0.4rem; font-size: 0.85rem; width: 80%; opacity: 0.5;"><option>N/A (Shell Form)</option></select>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <h4 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Post-Construction Milestones</h4>
                            <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                                <div class="form-group"><label>Compliance Submitted</label><select name="blocks[<?= $block['id'] ?>][compliance_submitted]" <?= $disabledAttr ?>><option value="No" <?= $block['compliance_submitted'] === 'No' ? 'selected' : '' ?>>No</option><option value="Yes" <?= $block['compliance_submitted'] === 'Yes' ? 'selected' : '' ?>>Yes</option><option value="NA" <?= $block['compliance_submitted'] === 'NA' ? 'selected' : '' ?>>N/A</option></select></div>
                                <div class="form-group"><label>Compliance Certified</label><select name="blocks[<?= $block['id'] ?>][compliance_certified]" <?= $disabledAttr ?>><option value="No" <?= $block['compliance_certified'] === 'No' ? 'selected' : '' ?>>No</option><option value="Yes" <?= $block['compliance_certified'] === 'Yes' ? 'selected' : '' ?>>Yes</option><option value="NA" <?= $block['compliance_certified'] === 'NA' ? 'selected' : '' ?>>N/A</option></select></div>
                                <div class="form-group"><label>Condominium Formed</label><select name="blocks[<?= $block['id'] ?>][condominium_formed]" <?= $disabledAttr ?>><option value="No" <?= $block['condominium_formed'] === 'No' ? 'selected' : '' ?>>No</option><option value="Yes" <?= $block['condominium_formed'] === 'Yes' ? 'selected' : '' ?>>Yes</option><option value="NA" <?= $block['condominium_formed'] === 'NA' ? 'selected' : '' ?>>N/A</option></select></div>
                                <div class="form-group"><label>CP Meters Installed</label><select name="blocks[<?= $block['id'] ?>][cp_meters_installed]" <?= $disabledAttr ?>><option value="No" <?= $block['cp_meters_installed'] === 'No' ? 'selected' : '' ?>>No</option><option value="Yes" <?= $block['cp_meters_installed'] === 'Yes' ? 'selected' : '' ?>>Yes</option><option value="NA" <?= $block['cp_meters_installed'] === 'NA' ? 'selected' : '' ?>>N/A</option></select></div>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>

                    <?php if ($canUpdateStatus): ?>
                        <div class="form-actions" style="margin-top: 1rem;">
                            <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem;">Save Block Progress</button>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </details>

    <details class="custom-accordion">
        <summary>⚡ Services Engineer Utilities</summary>
        <div class="accordion-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_services">
                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                    <div class="form-group service-item"><label>Existing Meter/s for Removal</label><div class="service-controls"><select name="existing_meters_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>><option value="Not Required" <?= ($services['existing_meters_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option><option value="Required" <?= ($services['existing_meters_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option></select><select name="existing_meters_complete" class="completion-status" <?= ($services['existing_meters_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>><option value="Not Complete" <?= ($services['existing_meters_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Complete" <?= ($services['existing_meters_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div></div>
                    <div class="form-group service-item"><label>Enemalta Lines for Deviation</label><div class="service-controls"><select name="enemalta_deviation_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>><option value="Not Required" <?= ($services['enemalta_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option><option value="Required" <?= ($services['enemalta_deviation_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option></select><select name="enemalta_deviation_complete" class="completion-status" <?= ($services['enemalta_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>><option value="Not Complete" <?= ($services['enemalta_deviation_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Complete" <?= ($services['enemalta_deviation_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div></div>
                    <div class="form-group service-item"><label>GO Lines for Deviation</label><div class="service-controls"><select name="go_deviation_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>><option value="Not Required" <?= ($services['go_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option><option value="Required" <?= ($services['go_deviation_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option></select><select name="go_deviation_complete" class="completion-status" <?= ($services['go_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>><option value="Not Complete" <?= ($services['go_deviation_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Complete" <?= ($services['go_deviation_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div></div>
                    <div class="form-group service-item"><label>Melita Lines for Deviation</label><div class="service-controls"><select name="melita_deviation_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>><option value="Not Required" <?= ($services['melita_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option><option value="Required" <?= ($services['melita_deviation_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option></select><select name="melita_deviation_complete" class="completion-status" <?= ($services['melita_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>><option value="Not Complete" <?= ($services['melita_deviation_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Complete" <?= ($services['melita_deviation_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div></div>
                    <div class="form-group service-item"><label>LC Lamps</label><div class="service-controls"><select name="lc_lamps_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>><option value="Not Required" <?= ($services['lc_lamps_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option><option value="Required" <?= ($services['lc_lamps_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option></select><select name="lc_lamps_complete" class="completion-status" <?= ($services['lc_lamps_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>><option value="Not Complete" <?= ($services['lc_lamps_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Complete" <?= ($services['lc_lamps_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div></div>
                    <div class="form-group service-item"><label>Temp Elec Meter Installation</label><div class="service-controls"><select name="temp_elec_meter_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>><option value="Not Required" <?= ($services['temp_elec_meter_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option><option value="Required" <?= ($services['temp_elec_meter_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option></select><select name="temp_elec_meter_complete" class="completion-status" <?= ($services['temp_elec_meter_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>><option value="Not Complete" <?= ($services['temp_elec_meter_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Complete" <?= ($services['temp_elec_meter_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div></div>
                    <div class="form-group service-item"><label>Temp WSC Meter Installation</label><div class="service-controls"><select name="temp_wsc_meter_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>><option value="Not Required" <?= ($services['temp_wsc_meter_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option><option value="Required" <?= ($services['temp_wsc_meter_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option></select><select name="temp_wsc_meter_complete" class="completion-status" <?= ($services['temp_wsc_meter_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>><option value="Not Complete" <?= ($services['temp_wsc_meter_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Complete" <?= ($services['temp_wsc_meter_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option></select></div></div>
                </div>
                <?php if ($canEditServices): ?>
                    <div class="form-actions" style="margin-top: 1rem;"><button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem;">Save Services Updates</button></div>
                <?php endif; ?>
            </form>
        </div>
    </details>

    <script>
    document.querySelectorAll('.requirement-toggle').forEach(function(select) {
        select.addEventListener('change', function() {
            const compSelect = this.parentElement.querySelector('.completion-status');
            compSelect.disabled = (this.value !== 'Required');
            if (this.value !== 'Required') compSelect.value = 'Not Complete';
        });
    });
    </script>
</div>

<?php require_once 'footer.php'; ?>
