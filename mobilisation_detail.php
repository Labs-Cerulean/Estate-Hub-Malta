<?php
require_once 'init.php';
require_once 'session-check.php';

// Get and validate project ID
$projectId = $_GET['project_id'] ?? $_GET['projectid'] ?? null;

if (!$projectId) {
    header('Location: dashboard.php');
    exit;
}

// 1. Check Project Access (Level 1, 2, 3 logic)
if (!hasProjectAccess($pdo, $projectId)) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$project = getProjectWithClient($pdo, $projectId);
if (!$project) {
    header('Location: dashboard.php');
    exit;
}

// 2. Check Tracking Visibility
if ($project['is_tracking'] == 1 && !hasPermission('view_tracking') && !isAdmin()) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

// Handle log submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_log'])) {
    $message = trim($_POST['log_message'] ?? '');
    if (!empty($message) && $projectId) {
        $stmt = $pdo->prepare("INSERT INTO project_logs (project_id, user_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$projectId, getCurrentUserId(), $message]);
        header("Location: mobilisation_detail.php?project_id=$projectId#project-log");
        exit;
    }
}

// Fetch project logs
$logsStmt = $pdo->prepare("
    SELECT pl.id, pl.message, pl.created_at, u.username, u.first_name, u.last_name
    FROM project_logs pl
    JOIN users u ON pl.user_id = u.id
    WHERE pl.project_id = ?
    ORDER BY pl.created_at DESC LIMIT 100
");
$logsStmt->execute([$projectId]);
$projectLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

function getUserColor($username) {
    $colors = ['#6366F1', '#8B5CF6', '#EC4899', '#10B981', '#F59E0B', '#3B82F6', '#EF4444', '#14B8A6', '#F97316', '#06B6D4'];
    return $colors[abs(crc32($username)) % count($colors)];
}

// Get mobilisation data
$mobStmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
$mobStmt->execute([$projectId]);
$mob = $mobStmt->fetch();

if (!$mob) {
    $pdo->prepare("INSERT INTO project_mobilisation (project_id) VALUES (?)")->execute([$projectId]);
    $mobStmt->execute([$projectId]);
    $mob = $mobStmt->fetch();
}

// Check Capabilities
$canUpdateStatus = canUpdateStatus($pdo, $projectId);
$canEditServices = hasPermission('edit_services') || isAdmin();
$disabledAttr = $canUpdateStatus ? '' : 'disabled';
$servicesDisabledAttr = $canEditServices ? '' : 'disabled';

$message = '';

// Handle BCA Mobilisation Updates
if (($_POST['action'] ?? null) === 'update_mobilisation' && $canUpdateStatus) {
    try {
        $updates = [];
        $values = [];
        $allowedFields = [
            'acquisition_complete', 'acquisition_date', 'archaeologist_assigned',
            'change_of_applicant', 'geological_test', 'condition_report_contacts',
            'condition_reports', 'method_statements', 'insurance_status',
            'pavement_guarantee', 'wellbeing_guarantee', 'umbrella_guarantee',
            'responsibility_form', 'bca_clearance', 'mob_demolition', 'mob_excavation', 'mob_construction'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $updates[] = "$field = ?";
                $values[] = $_POST[$field];
            }
        }
        
        if (!empty($updates)) {
            $values[] = $projectId;
            $updateStmt = $pdo->prepare("UPDATE project_mobilisation SET " . implode(', ', $updates) . " WHERE project_id = ?");
            $updateStmt->execute($values);
            $message = 'Mobilisation steps updated successfully!';
            
            $mobStmt->execute([$projectId]);
            $mob = $mobStmt->fetch();
        }
    } catch (PDOException $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

// Handle Services Updates
if (($_POST['action'] ?? null) === 'update_services' && $canEditServices) {
    try {
        $servicesStmt = $pdo->prepare("
            INSERT INTO project_services (
                project_id, existing_meters_required, existing_meters_complete,
                enemalta_deviation_required, enemalta_deviation_complete,
                go_deviation_required, go_deviation_complete, melita_deviation_required, melita_deviation_complete,
                lc_lamps_required, lc_lamps_complete, temp_elec_meter_required, temp_elec_meter_complete,
                temp_wsc_meter_required, temp_wsc_meter_complete
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                existing_meters_required = VALUES(existing_meters_required), existing_meters_complete = VALUES(existing_meters_complete),
                enemalta_deviation_required = VALUES(enemalta_deviation_required), enemalta_deviation_complete = VALUES(enemalta_deviation_complete),
                go_deviation_required = VALUES(go_deviation_required), go_deviation_complete = VALUES(go_deviation_complete),
                melita_deviation_required = VALUES(melita_deviation_required), melita_deviation_complete = VALUES(melita_deviation_complete),
                lc_lamps_required = VALUES(lc_lamps_required), lc_lamps_complete = VALUES(lc_lamps_complete),
                temp_elec_meter_required = VALUES(temp_elec_meter_required), temp_elec_meter_complete = VALUES(temp_elec_meter_complete),
                temp_wsc_meter_required = VALUES(temp_wsc_meter_required), temp_wsc_meter_complete = VALUES(temp_wsc_meter_complete)
        ");
        
        $servicesStmt->execute([
            $projectId,
            $_POST['existing_meters_required'] ?? 'Not Required', $_POST['existing_meters_complete'] ?? 'Not Complete',
            $_POST['enemalta_deviation_required'] ?? 'Not Required', $_POST['enemalta_deviation_complete'] ?? 'Not Complete',
            $_POST['go_deviation_required'] ?? 'Not Required', $_POST['go_deviation_complete'] ?? 'Not Complete',
            $_POST['melita_deviation_required'] ?? 'Not Required', $_POST['melita_deviation_complete'] ?? 'Not Complete',
            $_POST['lc_lamps_required'] ?? 'Not Required', $_POST['lc_lamps_complete'] ?? 'Not Complete',
            $_POST['temp_elec_meter_required'] ?? 'Not Required', $_POST['temp_elec_meter_complete'] ?? 'Not Complete',
            $_POST['temp_wsc_meter_required'] ?? 'Not Required', $_POST['temp_wsc_meter_complete'] ?? 'Not Complete'
        ]);
        
        $message = 'Services & Utilities updated successfully!';
    } catch (PDOException $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

// Logic Locks for UI Display
$geoComplete = ($mob['geological_test'] ?? 'NA') === 'Complete' || ($mob['geological_test'] ?? 'NA') === 'NA';
$condComplete = ($mob['condition_reports'] ?? 'Not Started') === 'Complete' || ($mob['condition_reports'] ?? 'Not Started') === 'NA';
$canSequential = $geoComplete && $condComplete;

$seqFieldsDisplay = ['method_statements', 'insurance_status', 'pavement_guarantee', 'wellbeing_guarantee', 'umbrella_guarantee'];
$allSeqComplete = true;
foreach ($seqFieldsDisplay as $field) {
    if (($mob[$field] ?? 'Not Complete') !== 'Complete') {
        $allSeqComplete = false; break;
    }
}

$respComplete = ($mob['responsibility_form'] ?? 'Not Complete') === 'Complete';
$canFinal = $allSeqComplete;
$canClearance = $respComplete;

$services = getProjectServices($pdo, $projectId);

$pageTitle = 'Mobilisation - ' . $project['name'];
require_once 'header.php';
?>

<div class="main-container">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h1 class="page-title" style="margin-bottom: 0;"><?php echo htmlspecialchars($project['name']); ?></h1>
        <?php if ($project['is_tracking'] == 1): ?>
            <span style="background: var(--warning-bg); color: var(--warning); padding: 0.5rem 1rem; border-radius: 8px; font-weight: bold;">
                Tracking Stage
            </span>
        <?php endif; ?>
    </div>

    <?php if ($project['summer_break_flag'] == 1): ?>
        <div class="alert alert-error" style="display: flex; align-items: center; gap: 1rem; border-left: 5px solid var(--danger); margin-bottom: 1.5rem;">
            <span style="font-size: 1.5rem;">☀️</span>
            <div>
                <strong>Summer Break Alarm Active</strong><br>
                This project is subject to Malta Summer Break restrictions. Demolition and Excavation phases are heavily impacted.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="projects-section" style="margin-bottom: 2rem;">
        <div class="project-meta">
            <div class="meta-item">
                <span class="meta-label">Client: </span>
                <span><?php echo htmlspecialchars($project['client_name'] ?? 'Unknown'); ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Type: </span>
                <span><?php echo ucwords(str_replace('-', ' ', $project['type'])); ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">City: </span>
                <span><?php echo htmlspecialchars($project['city']); ?></span>
            </div>
        </div>
    </div>

    <div class="section-card" id="project-log" style="margin-bottom: 2rem;">
        <div class="section-header"><h2>Project Activity Log</h2></div>
        
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

    <section class="projects-section">
        <div class="section-title" style="margin-bottom: 1.5rem;">BCA Mobilisation Steps</div>
        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="update_mobilisation">

            <?php if ($project['type'] === 'in-house'): ?>
                <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem;">
                    <legend style="font-weight: 600;">🏠 Acquisition Complete</legend>
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="acquisition_complete" <?= $disabledAttr ?>>
                                <option value="No" <?= ($mob['acquisition_complete'] ?? 'No') === 'No' ? 'selected' : '' ?>>No</option>
                                <option value="Yes" <?= ($mob['acquisition_complete'] ?? 'No') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" name="acquisition_date" value="<?= $mob['acquisition_date'] ?? '' ?>" <?= $disabledAttr ?>>
                        </div>
                    </div>
                </fieldset>
            <?php endif; ?>

            <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem;">
                <legend style="font-weight: 600;">📋 Non-Sequential Tasks</legend>
                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div class="form-group">
                        <label>Archaeologist Assigned</label>
                        <select name="archaeologist_assigned" <?= $disabledAttr ?>><option value="NA" <?= ($mob['archaeologist_assigned'] ?? 'NA') === 'NA' ? 'selected' : '' ?>>N/A</option><option value="Yes" <?= ($mob['archaeologist_assigned'] ?? 'NA') === 'Yes' ? 'selected' : '' ?>>Yes</option><option value="No" <?= ($mob['archaeologist_assigned'] ?? 'NA') === 'No' ? 'selected' : '' ?>>No</option></select>
                    </div>
                    <div class="form-group">
                        <label>Change of Applicant</label>
                        <select name="change_of_applicant" <?= $disabledAttr ?>><option value="NA" <?= ($mob['change_of_applicant'] ?? 'NA') === 'NA' ? 'selected' : '' ?>>N/A</option><option value="Complete" <?= ($mob['change_of_applicant'] ?? 'NA') === 'Complete' ? 'selected' : '' ?>>Complete</option><option value="Not Complete" <?= ($mob['change_of_applicant'] ?? 'NA') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option></select>
                    </div>
                    <div class="form-group">
                        <label>Geological Test</label>
                        <select name="geological_test" <?= $disabledAttr ?>><option value="NA" <?= ($mob['geological_test'] ?? 'NA') === 'NA' ? 'selected' : '' ?>>N/A</option><option value="Complete" <?= ($mob['geological_test'] ?? 'NA') === 'Complete' ? 'selected' : '' ?>>Complete</option><option value="Not Complete" <?= ($mob['geological_test'] ?? 'NA') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Awaiting Result" <?= ($mob['geological_test'] ?? 'NA') === 'Awaiting Result' ? 'selected' : '' ?>>Awaiting Result</option></select>
                    </div>
                    <div class="form-group">
                        <label>Condition Report Contacts</label>
                        <select name="condition_report_contacts" <?= $disabledAttr ?>><option value="Not Started" <?= ($mob['condition_report_contacts'] ?? 'Not Started') === 'Not Started' ? 'selected' : '' ?>>Not Started</option><option value="In Process" <?= ($mob['condition_report_contacts'] ?? 'Not Started') === 'In Process' ? 'selected' : '' ?>>In Process</option><option value="Complete" <?= ($mob['condition_report_contacts'] ?? 'Not Started') === 'Complete' ? 'selected' : '' ?>>Complete</option><option value="NA" <?= ($mob['condition_report_contacts'] ?? 'Not Started') === 'NA' ? 'selected' : '' ?>>NA</option></select>
                    </div>
                    <div class="form-group">
                        <label>Condition Reports</label>
                        <select name="condition_reports" <?= $disabledAttr ?>><option value="Not Started" <?= ($mob['condition_reports'] ?? 'Not Started') === 'Not Started' ? 'selected' : '' ?>>Not Started</option><option value="In Process" <?= ($mob['condition_reports'] ?? 'Not Started') === 'In Process' ? 'selected' : '' ?>>In Process</option><option value="Complete" <?= ($mob['condition_reports'] ?? 'Not Started') === 'Complete' ? 'selected' : '' ?>>Complete</option><option value="NA" <?= ($mob['condition_reports'] ?? 'Not Started') === 'NA' ? 'selected' : '' ?>>NA</option></select>
                    </div>
                </div>
            </fieldset>

            <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; opacity: <?= $canSequential ? '1' : '0.5' ?>; margin-bottom: 1.5rem;">
                <legend style="font-weight: 600;">🔗 Sequential Chain</legend>
                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div class="form-group">
                        <label>Method Statements</label>
                        <select name="method_statements" <?= !$canSequential ? 'disabled' : $disabledAttr ?>><option value="Not Complete" <?= ($mob['method_statements'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Complete" <?= ($mob['method_statements'] ?? 'Not Complete') === 'Complete' ? 'selected' : '' ?>>Complete</option></select>
                    </div>
                    <div class="form-group">
                        <label>Insurance</label>
                        <select name="insurance_status" <?= !$canSequential ? 'disabled' : $disabledAttr ?>><option value="Not Started" <?= ($mob['insurance_status'] ?? 'Not Started') === 'Not Started' ? 'selected' : '' ?>>Not Started</option><option value="In Process" <?= ($mob['insurance_status'] ?? 'Not Started') === 'In Process' ? 'selected' : '' ?>>In Process</option><option value="Complete" <?= ($mob['insurance_status'] ?? 'Not Started') === 'Complete' ? 'selected' : '' ?>>Complete</option></select>
                    </div>
                    <div class="form-group">
                        <label>Pavement Guarantee</label>
                        <select name="pavement_guarantee" <?= !$canSequential ? 'disabled' : $disabledAttr ?>><option value="Not Started" <?= ($mob['pavement_guarantee'] ?? 'Not Started') === 'Not Started' ? 'selected' : '' ?>>Not Started</option><option value="In Process" <?= ($mob['pavement_guarantee'] ?? 'Not Started') === 'In Process' ? 'selected' : '' ?>>In Process</option><option value="Complete" <?= ($mob['pavement_guarantee'] ?? 'Not Started') === 'Complete' ? 'selected' : '' ?>>Complete</option></select>
                    </div>
                    <div class="form-group">
                        <label>Wellbeing Guarantee</label>
                        <select name="wellbeing_guarantee" <?= !$canSequential ? 'disabled' : $disabledAttr ?>><option value="Not Started" <?= ($mob['wellbeing_guarantee'] ?? 'Not Started') === 'Not Started' ? 'selected' : '' ?>>Not Started</option><option value="In Process" <?= ($mob['wellbeing_guarantee'] ?? 'Not Started') === 'In Process' ? 'selected' : '' ?>>In Process</option><option value="Complete" <?= ($mob['wellbeing_guarantee'] ?? 'Not Started') === 'Complete' ? 'selected' : '' ?>>Complete</option></select>
                    </div>
                    <div class="form-group">
                        <label>Umbrella Guarantee</label>
                        <select name="umbrella_guarantee" <?= !$canSequential ? 'disabled' : $disabledAttr ?>><option value="Not Started" <?= ($mob['umbrella_guarantee'] ?? 'Not Started') === 'Not Started' ? 'selected' : '' ?>>Not Started</option><option value="In Process" <?= ($mob['umbrella_guarantee'] ?? 'Not Started') === 'In Process' ? 'selected' : '' ?>>In Process</option><option value="Complete" <?= ($mob['umbrella_guarantee'] ?? 'Not Started') === 'Complete' ? 'selected' : '' ?>>Complete</option></select>
                    </div>
                </div>
            </fieldset>

            <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; opacity: <?= $canFinal ? '1' : '0.5' ?>; margin-bottom: 1.5rem;">
                <legend style="font-weight: 600;">🏗️ Final Clearance & Execution Phases</legend>
                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    
                    <div class="form-group">
                        <label>Responsibility Form</label>
                        <select name="responsibility_form" <?= !$canFinal ? 'disabled' : $disabledAttr ?>><option value="Not Complete" <?= ($mob['responsibility_form'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option><option value="Complete" <?= ($mob['responsibility_form'] ?? 'Not Complete') === 'Complete' ? 'selected' : '' ?>>Complete</option></select>
                    </div>

                    <div class="form-group">
                        <label>BCA Clearance (General)</label>
                        <select name="bca_clearance" <?= !$canClearance ? 'disabled' : $disabledAttr ?>><option value="No" <?= ($mob['bca_clearance'] ?? 'No') === 'No' ? 'selected' : '' ?>>No</option><option value="Yes" <?= ($mob['bca_clearance'] ?? 'No') === 'Yes' ? 'selected' : '' ?>>Yes</option></select>
                    </div>

                    <div class="form-group" style="background: rgba(239, 68, 68, 0.1); padding: 0.5rem; border-radius: 6px; border-left: 3px solid var(--danger);">
                        <label>Demolition Phase</label>
                        <select name="mob_demolition" <?= !$canClearance ? 'disabled' : $disabledAttr ?>><option value="No" <?= ($mob['mob_demolition'] ?? 'No') === 'No' ? 'selected' : '' ?>>No Clearance</option><option value="Yes" <?= ($mob['mob_demolition'] ?? 'No') === 'Yes' ? 'selected' : '' ?>>Cleared</option><option value="NA" <?= ($mob['mob_demolition'] ?? 'No') === 'NA' ? 'selected' : '' ?>>N/A</option></select>
                    </div>

                    <div class="form-group" style="background: rgba(245, 158, 11, 0.1); padding: 0.5rem; border-radius: 6px; border-left: 3px solid var(--warning);">
                        <label>Excavation Phase</label>
                        <select name="mob_excavation" <?= !$canClearance ? 'disabled' : $disabledAttr ?>><option value="No" <?= ($mob['mob_excavation'] ?? 'No') === 'No' ? 'selected' : '' ?>>No Clearance</option><option value="Yes" <?= ($mob['mob_excavation'] ?? 'No') === 'Yes' ? 'selected' : '' ?>>Cleared</option><option value="NA" <?= ($mob['mob_excavation'] ?? 'No') === 'NA' ? 'selected' : '' ?>>N/A</option></select>
                    </div>

                    <div class="form-group" style="background: rgba(34, 197, 94, 0.1); padding: 0.5rem; border-radius: 6px; border-left: 3px solid var(--success);">
                        <label>Construction Phase</label>
                        <select name="mob_construction" <?= !$canClearance ? 'disabled' : $disabledAttr ?>><option value="No" <?= ($mob['mob_construction'] ?? 'No') === 'No' ? 'selected' : '' ?>>No Clearance</option><option value="Yes" <?= ($mob['mob_construction'] ?? 'No') === 'Yes' ? 'selected' : '' ?>>Cleared</option><option value="NA" <?= ($mob['mob_construction'] ?? 'No') === 'NA' ? 'selected' : '' ?>>N/A</option></select>
                    </div>

                </div>
            </fieldset>

            <?php if ($canUpdateStatus): ?>
                <div class="form-actions" style="margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem;">Save BCA Updates</button>
                </div>
            <?php endif; ?>
        </form>
    </section>

    <section class="projects-section">
        <div class="section-title" style="margin-bottom: 1.5rem;">Services Engineer Steps</div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_services">
            
            <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                <div class="form-group service-item">
                    <label>Existing Meter/s for Removal</label>
                    <div class="service-controls">
                        <select name="existing_meters_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>>
                            <option value="Not Required" <?= ($services['existing_meters_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option>
                            <option value="Required" <?= ($services['existing_meters_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option>
                        </select>
                        <select name="existing_meters_complete" class="completion-status" <?= ($services['existing_meters_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>>
                            <option value="Not Complete" <?= ($services['existing_meters_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option>
                            <option value="Complete" <?= ($services['existing_meters_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option>
                        </select>
                    </div>
                </div>
        
                <div class="form-group service-item">
                    <label>Enemalta Lines for Deviation</label>
                    <div class="service-controls">
                        <select name="enemalta_deviation_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>>
                            <option value="Not Required" <?= ($services['enemalta_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option>
                            <option value="Required" <?= ($services['enemalta_deviation_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option>
                        </select>
                        <select name="enemalta_deviation_complete" class="completion-status" <?= ($services['enemalta_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>>
                            <option value="Not Complete" <?= ($services['enemalta_deviation_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option>
                            <option value="Complete" <?= ($services['enemalta_deviation_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option>
                        </select>
                    </div>
                </div>
        
                <div class="form-group service-item">
                    <label>GO Lines for Deviation</label>
                    <div class="service-controls">
                        <select name="go_deviation_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>>
                            <option value="Not Required" <?= ($services['go_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option>
                            <option value="Required" <?= ($services['go_deviation_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option>
                        </select>
                        <select name="go_deviation_complete" class="completion-status" <?= ($services['go_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>>
                            <option value="Not Complete" <?= ($services['go_deviation_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option>
                            <option value="Complete" <?= ($services['go_deviation_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option>
                        </select>
                    </div>
                </div>
        
                <div class="form-group service-item">
                    <label>Melita Lines for Deviation</label>
                    <div class="service-controls">
                        <select name="melita_deviation_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>>
                            <option value="Not Required" <?= ($services['melita_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option>
                            <option value="Required" <?= ($services['melita_deviation_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option>
                        </select>
                        <select name="melita_deviation_complete" class="completion-status" <?= ($services['melita_deviation_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>>
                            <option value="Not Complete" <?= ($services['melita_deviation_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option>
                            <option value="Complete" <?= ($services['melita_deviation_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option>
                        </select>
                    </div>
                </div>
        
                <div class="form-group service-item">
                    <label>LC Lamps</label>
                    <div class="service-controls">
                        <select name="lc_lamps_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>>
                            <option value="Not Required" <?= ($services['lc_lamps_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option>
                            <option value="Required" <?= ($services['lc_lamps_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option>
                        </select>
                        <select name="lc_lamps_complete" class="completion-status" <?= ($services['lc_lamps_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>>
                            <option value="Not Complete" <?= ($services['lc_lamps_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option>
                            <option value="Complete" <?= ($services['lc_lamps_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option>
                        </select>
                    </div>
                </div>
        
                <div class="form-group service-item">
                    <label>Temp Elec Meter Installation</label>
                    <div class="service-controls">
                        <select name="temp_elec_meter_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>>
                            <option value="Not Required" <?= ($services['temp_elec_meter_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option>
                            <option value="Required" <?= ($services['temp_elec_meter_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option>
                        </select>
                        <select name="temp_elec_meter_complete" class="completion-status" <?= ($services['temp_elec_meter_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>>
                            <option value="Not Complete" <?= ($services['temp_elec_meter_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option>
                            <option value="Complete" <?= ($services['temp_elec_meter_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option>
                        </select>
                    </div>
                </div>
        
                <div class="form-group service-item">
                    <label>Temp WSC Meter Installation</label>
                    <div class="service-controls">
                        <select name="temp_wsc_meter_required" class="requirement-toggle" <?= $servicesDisabledAttr ?>>
                            <option value="Not Required" <?= ($services['temp_wsc_meter_required'] ?? 'Not Required') === 'Not Required' ? 'selected' : '' ?>>Not Required</option>
                            <option value="Required" <?= ($services['temp_wsc_meter_required'] ?? '') === 'Required' ? 'selected' : '' ?>>Required</option>
                        </select>
                        <select name="temp_wsc_meter_complete" class="completion-status" <?= ($services['temp_wsc_meter_required'] ?? 'Not Required') === 'Not Required' ? 'disabled' : $servicesDisabledAttr ?>>
                            <option value="Not Complete" <?= ($services['temp_wsc_meter_complete'] ?? 'Not Complete') === 'Not Complete' ? 'selected' : '' ?>>Not Complete</option>
                            <option value="Complete" <?= ($services['temp_wsc_meter_complete'] ?? '') === 'Complete' ? 'selected' : '' ?>>Complete</option>
                        </select>
                    </div>
                </div>
            </div>
    
            <?php if ($canEditServices): ?>
                <div class="form-actions" style="margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem;">Save Services Updates</button>
                </div>
            <?php endif; ?>
        </form>
        
        <script>
        document.querySelectorAll('.requirement-toggle').forEach(function(select) {
            select.addEventListener('change', function() {
                const completionSelect = this.parentElement.querySelector('.completion-status');
                if (this.value === 'Required') {
                    completionSelect.disabled = false;
                } else {
                    completionSelect.disabled = true;
                    completionSelect.value = 'Not Complete';
                }
            });
        });
        </script>
    </section>
</div>

<?php require_once 'footer.php'; ?>
