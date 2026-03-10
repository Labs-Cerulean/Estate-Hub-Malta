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
    if ($level === 'Finished') return '#22c55e'; // Green
    if ($level === 'Semi Finished') return '#f59e0b'; // Orange
    if ($level === 'Common Parts Only') return '#0ea5e9'; // Blue
    return '#9ca3af'; // Shell or default Gray
}

// ==========================================
// HANDLE POST REQUESTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- LOG & ACTION MANAGEMENT ---
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
    
    // 1. Update Mobilisation (BCA & Site Clearances)
    if (($_POST['action'] ?? null) === 'update_mobilisation' && $canUpdateStatus) {
        try {
            $updates = []; $values = [];
            // Strictly bound to actual DB columns
            $allowedFields = [
                'acquisition_complete', 'acquisition_date', 'archaeologist_assigned', 'change_of_applicant', 
                'geological_test', 'condition_report_contacts', 'condition_reports', 'method_statements', 
                'insurance_status', 'pavement_guarantee', 'wellbeing_guarantee', 'umbrella_guarantee', 
                'responsibility_form', 'mob_demolition', 'mob_excavation', 'mob_construction', 
                'demo_status', 'excavation_status', 'temporary_water', 'temporary_electricity', 
                'hoarding', 'site_toilet'
            ];
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) { 
                    $updates[] = "$field = ?"; 
                    $values[] = $_POST[$field]; 
                }
            }
            if (!empty($updates)) {
                $values[] = $projectId;
                $pdo->prepare("UPDATE project_mobilisation SET " . implode(', ', $updates) . " WHERE project_id = ?")->execute($values);
                $message = 'Site Mobilisation & Clearances updated successfully!';
            }
        } catch (PDOException $e) { $error = 'Error: ' . $e->getMessage(); }
    }

    // 2. Update Blocks & Finishes (The Core Engine)
    if (($_POST['action'] ?? null) === 'update_blocks' && $canUpdateStatus) {
        try {
            $pdo->beginTransaction();

            if (isset($_POST['blocks']) && is_array($_POST['blocks'])) {
                // Strictly mapped to DB columns (Fixed Post-Compliance reverting)
                $allowedFinishesFields = [
                    'finish_level', 'progress', 'finishes_overall_status', 
                    'compliance_submitted', 'compliance_certified', 'condominium_formed', 'cp_meters_installed',
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
                    
                    foreach ($allowedFinishesFields as $f) {
                        if (isset($bData[$f])) {
                            $updates[] = "$f = ?";
                            $params[] = $bData[$f];
                        }
                    }
                    
                    if (!empty($updates)) {
                        $params[] = $bId;
                        $params[] = $projectId;
                        $sql = "UPDATE project_blocks SET " . implode(', ', $updates) . " WHERE id = ? AND project_id = ?";
                        $updateBlock = $pdo->prepare($sql);
                        $updateBlock->execute($params);
                    }
                }
            }
            
            // Save Structural Levels
            if (isset($_POST['levels']) && is_array($_POST['levels'])) {
                $lStmt = $pdo->prepare("UPDATE block_levels SET construction_status=? WHERE id=?");
                foreach ($_POST['levels'] as $lId => $lData) {
                    $lStmt->execute([$lData['construction_status'] ?? 'Pending', $lId]);
                }
            }
            
            // Save Interior Floor Finishes
            if (isset($_POST['floor_finishes']) && is_array($_POST['floor_finishes'])) {
                $stmtFloorFin = $pdo->prepare("INSERT INTO block_levels_statuses (project_id, block_id, level_id, finish_type_id, status, updated_by)
                                               VALUES (?, ?, ?, ?, ?, ?)
                                               ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by)");
                foreach ($_POST['floor_finishes'] as $bId => $levelsArray) {
                    foreach ($levelsArray as $lvlId => $types) {
                        foreach ($types as $tId => $status) {
                            $stmtFloorFin->execute([$projectId, $bId, $lvlId, $tId, $status, getCurrentUserId()]);
                        }
                    }
                }
            }
            
            $pdo->commit();
            $message = 'Block execution progress saved securely!';
        } catch (PDOException $e) { $pdo->rollBack(); $error = 'Error: ' . $e->getMessage(); }
    }

    // 3. Update Engineer Services
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
$logsStmt = $pdo->prepare("SELECT pl.*, u.username as author_username, au.username as assignee_username, cu.username as closer_username FROM project_logs pl JOIN users u ON pl.user_id = u.id LEFT JOIN users au ON pl.assigned_to = au.id LEFT JOIN users cu ON pl.closed_by = cu.id WHERE pl.project_id = ? ORDER BY pl.created_at DESC LIMIT 100");
$logsStmt->execute([$projectId]);
$projectLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch Assignable Users
$clientId = $project['clientid'] ?? 0;
$assignUsersStmt = $pdo->prepare("SELECT DISTINCT u.id, u.first_name, u.last_name, u.username, u.role FROM users u LEFT JOIN user_client_access uca ON u.id = uca.user_id AND uca.client_id = ? LEFT JOIN user_project_access upa ON u.id = upa.user_id AND upa.project_id = ? LEFT JOIN user_project_exclusions upe ON u.id = upe.user_id AND upe.project_id = ? WHERE u.is_active = 'Yes' AND (u.role IN ('admin', 'director', 'system_manager', 'project_manager', 'accountant') OR upa.project_id IS NOT NULL OR (uca.client_id IS NOT NULL AND upe.project_id IS NULL)) ORDER BY u.role ASC, u.first_name ASC");
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

// Fetch Master Finish Types
$stmtFinTypes = $pdo->query("SELECT * FROM finish_types WHERE is_active=1 ORDER BY name ASC");
$finishTypes = $stmtFinTypes->fetchAll(PDO::FETCH_ASSOC);

// Fetch Floor Finishes Data
$stmtAllStatuses = $pdo->prepare("SELECT level_id, finish_type_id, status FROM block_levels_statuses WHERE project_id = ?");
$stmtAllStatuses->execute([$projectId]);
$floorStatusesRaw = $stmtAllStatuses->fetchAll(PDO::FETCH_ASSOC);
$floorStatuses = [];
foreach ($floorStatusesRaw as $r) { $floorStatuses[$r['level_id']][$r['finish_type_id']] = $r['status']; }

// ==========================================
// STAGE LOGIC & HELPERS
// ==========================================
$currentStageName = deriveProjectStage($pdo, $projectId);
$stagesEnum = ['Feasibility'=>1, 'Tracking'=>2, 'Permit'=>3, 'Mobilisation'=>4, 'Demolition'=>5, 'Excavation'=>6, 'Construction'=>7, 'Finishes'=>8, 'Compliance'=>9, 'Condominium'=>10, 'Handed Over'=>11];
$stageNum = $stagesEnum[$currentStageName] ?? 1;
$progressPercent = min(100, round(($stageNum / 11) * 100));

$geoComplete = ($mob['geological_test'] ?? 'NA') === 'Complete' || ($mob['geological_test'] ?? 'NA') === 'NA';
$condComplete = ($mob['condition_reports'] ?? 'Not Started') === 'Complete' || ($mob['condition_reports'] ?? 'Not Started') === 'NA';
$canSequential = $geoComplete && $condComplete;

$allSeqComplete = true;
foreach (['method_statements', 'insurance_status', 'pavement_guarantee', 'wellbeing_guarantee', 'umbrella_guarantee'] as $field) {
    if (($mob[$field] ?? 'Not Complete') !== 'Complete') { $allSeqComplete = false; break; }
}
$canFinal = $allSeqComplete;
$canClearance = ($mob['responsibility_form'] ?? 'Not Complete') === 'Complete';

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
.status-select { font-weight: bold; background: #1e1e2d; color: #fff; border: 1px solid var(--border-glass); padding: 5px; border-radius: 4px; width: 100%; }
.status-select option[value="Pending"], .status-select option[value="No"], .status-select option[value="Not Started"] { color: #ef4444; }
.status-select option[value="In Progress"], .status-select option[value="Ongoing CP"] { color: #f59e0b; }
.status-select option[value="Complete"], .status-select option[value="Yes"], .status-select option[value="Connected"] { color: #22c55e; }
.status-select option[value="Not Required"], .status-select option[value="NA"] { color: #9ca3af; }

.grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem; }
.form-group label { display: block; margin-bottom: 5px; font-size: 0.8rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }

.finishes-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 10px; }
.finishes-table th, .finishes-table td { border: 1px solid var(--border-glass); padding: 8px; text-align: left; }
.finishes-table th { background: rgba(0,0,0,0.2); color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; }

/* Accordion Details */
details.block-accordion { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: 8px; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); }
details.block-accordion > summary { padding: 1.25rem 1.5rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center; list-style: none; user-select: none; transition: background 0.2s; border-radius: 8px; }
details.block-accordion > summary:hover { background: rgba(255,255,255,0.02); }
details.block-accordion > summary::-webkit-details-marker { display: none; }
.accordion-arrow { transition: transform 0.3s; font-size: 1rem; color: var(--primary-color); }
details[open].block-accordion .accordion-arrow { transform: rotate(180deg); }
details[open].block-accordion > summary { border-bottom: 1px solid var(--border-glass); border-bottom-left-radius: 0; border-bottom-right-radius: 0; }

.stage-tracker { display: flex; align-items: center; justify-content: space-between; background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-md); padding: 1.5rem; margin-bottom: 2rem; }
.stage-badge { display: inline-block; padding: 0.5rem 1rem; border-radius: 20px; background: rgba(99, 102, 241, 0.15); color: var(--primary-color); font-weight: 700; font-size: 1.1rem; border: 1px solid rgba(99, 102, 241, 0.3); }

/* Sticky Save Bar */
.sticky-save-bar { position: sticky; bottom: 0; background: rgba(30, 30, 45, 0.95); backdrop-filter: blur(10px); padding: 15px 30px; border-top: 1px solid var(--border-glass); z-index: 1000; display: flex; justify-content: space-between; align-items: center; gap: 15px; box-shadow: 0 -10px 25px rgba(0,0,0,0.5); border-radius: 12px 12px 0 0; margin-top: 2rem; }
</style>

<div class="main-container" style="padding-bottom: 100px;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h1 class="page-title" style="margin: 0;"><?= htmlspecialchars($project['name']) ?></h1>
        <a href="projects.php" class="btn btn-secondary">← Back to Projects</a>
    </div>

    <div class="stage-tracker">
        <div style="flex: 1;">
            <div style="font-size: 0.85rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.5rem;">Current System Stage</div>
            <div class="stage-badge">Stage <?= $stageNum ?>/11: <?= $currentStageName ?></div>
            <div style="height: 10px; background: rgba(255,255,255,0.1); border-radius: 5px; margin-top: 1rem; overflow: hidden;">
                <div style="height: 100%; width: <?= $progressPercent ?>%; background: linear-gradient(90deg, var(--primary-color), var(--secondary-color)); transition: width 0.5s ease;"></div>
            </div>
            <div style="margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-muted);">Status auto-calculates based on clearance and block progress below.</div>
        </div>
    </div>

    <?php if ($project['summer_break_flag'] == 1): ?>
        <div class="alert alert-error" style="display: flex; align-items: center; gap: 1rem; border-left: 5px solid var(--danger); margin-bottom: 1.5rem;">
            <span style="font-size: 1.5rem;">☀️</span><div><strong>Summer Break Alarm Active</strong><br>This project is subject to Malta Summer Break restrictions.</div>
        </div>
    <?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

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

        <div style="max-height: 350px; overflow-y: auto; padding-right: 0.5rem;">
            <?php if (empty($projectLogs)): ?>
                <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No activity logged yet.</p>
            <?php else: foreach ($projectLogs as $log): ?>
                <?php 
                $isAction = ($log['status'] !== 'Info');
                $isClosed = ($log['status'] === 'Action - Closed');
                $borderColor = getUserColor($log['author_username']);
                if ($isAction) { $borderColor = $isClosed ? '#10B981' : '#F59E0B'; }
                ?>
                <div style="padding: 1rem; background: var(--bg-secondary); margin-bottom: 0.75rem; border-radius: 8px; border-left: 4px solid <?= $borderColor ?>;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                        <div>
                            <strong style="color: <?= getUserColor($log['author_username']) ?>;">@<?= htmlspecialchars($log['author_username']) ?></strong>
                            <span style="font-size: 0.8rem; color: var(--text-muted); margin-left: 0.5rem;"><?= date('d M Y, H:i', strtotime($log['created_at'])) ?></span>
                        </div>
                        <?php if ($isAction): ?>
                            <?php if ($isClosed): ?>
                                <span style="font-size: 0.75rem; background: rgba(16, 185, 129, 0.1); color: #10B981; padding: 0.3rem 0.6rem; border-radius: 12px; font-weight: bold; border: 1px solid rgba(16, 185, 129, 0.3);">✅ Closed by @<?= htmlspecialchars($log['closer_username'] ?? 'Unknown') ?></span>
                            <?php else: ?>
                                <span style="font-size: 0.75rem; background: rgba(245, 158, 11, 0.1); color: #F59E0B; padding: 0.3rem 0.6rem; border-radius: 12px; font-weight: bold; border: 1px solid rgba(245, 158, 11, 0.3);">⏳ Pending Action for @<?= htmlspecialchars($log['assignee_username'] ?? 'Unknown') ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div style="font-size: 0.95rem; color: var(--text-primary); margin-bottom: <?= ($isAction && !$isClosed) ? '1rem' : '0' ?>;"><?= nl2br(htmlspecialchars($log['message'])) ?></div>
                    <?php if ($isAction && !$isClosed): ?>
                        <div style="display: flex; justify-content: flex-end;">
                            <form method="POST">
                                <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                <button type="submit" name="close_action" class="btn btn-sm" style="background: #10B981; color: white; border: none; padding: 0.4rem 1rem; margin: 0; display: flex; align-items: center; gap: 0.5rem;">Mark as Complete</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <details class="custom-accordion">
        <summary>📋 Pre-Construction & BCA Clearances</summary>
        <div class="accordion-content">
            <form method="POST" class="form-grid">
                <input type="hidden" name="action" value="update_mobilisation">

                <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem;">
                    <legend style="font-weight: 600;">📋 Non-Sequential Tasks</legend>
                    <div class="grid-container">
                        <div class="form-group"><label>Archaeologist Assigned</label><?= rSel('archaeologist_assigned', ['NA','Yes','No'], $mob['archaeologist_assigned']??'NA', $disabledAttr) ?></div>
                        <div class="form-group"><label>Change of Applicant</label><?= rSel('change_of_applicant', ['NA','Complete','Not Complete'], $mob['change_of_applicant']??'NA', $disabledAttr) ?></div>
                        <div class="form-group"><label>Geological Test</label><?= rSel('geological_test', ['NA','Complete','Not Complete','Awaiting Result'], $mob['geological_test']??'NA', $disabledAttr) ?></div>
                        <div class="form-group"><label>Cond. Report Contacts</label><?= rSel('condition_report_contacts', ['NA','Not Started','In Process','Complete'], $mob['condition_report_contacts']??'Not Started', $disabledAttr) ?></div>
                        <div class="form-group"><label>Condition Reports</label><?= rSel('condition_reports', ['NA','Not Started','In Process','Complete'], $mob['condition_reports']??'Not Started', $disabledAttr) ?></div>
                    </div>
                </fieldset>

                <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; opacity: <?= $canSequential ? '1' : '0.5' ?>; margin-bottom: 1.5rem;">
                    <legend style="font-weight: 600;">🔗 Sequential Chain</legend>
                    <div class="grid-container">
                        <?php $seqDis = !$canSequential ? 'disabled' : $disabledAttr; $optSeq = ['Not Started','In Process','Complete']; ?>
                        <div class="form-group"><label>Method Statements</label><?= rSel('method_statements', ['Not Complete','Complete'], $mob['method_statements']??'Not Complete', $seqDis) ?></div>
                        <div class="form-group"><label>Insurance</label><?= rSel('insurance_status', $optSeq, $mob['insurance_status']??'Not Started', $seqDis) ?></div>
                        <div class="form-group"><label>Pavement Guarantee</label><?= rSel('pavement_guarantee', $optSeq, $mob['pavement_guarantee']??'Not Started', $seqDis) ?></div>
                        <div class="form-group"><label>Wellbeing Guarantee</label><?= rSel('wellbeing_guarantee', $optSeq, $mob['wellbeing_guarantee']??'Not Started', $seqDis) ?></div>
                        <div class="form-group"><label>Umbrella Guarantee</label><?= rSel('umbrella_guarantee', $optSeq, $mob['umbrella_guarantee']??'Not Started', $seqDis) ?></div>
                    </div>
                </fieldset>

                <fieldset style="border: 1px solid var(--border-glass); border-radius: 12px; padding: 1.5rem; opacity: <?= $canFinal ? '1' : '0.5' ?>;">
                    <legend style="font-weight: 600;">🏗️ Clearances & Site Prep</legend>
                    
                    <div class="form-group" style="max-width: 300px;"><label>Responsibility Form</label><?= rSel('responsibility_form', ['Not Complete','Complete'], $mob['responsibility_form']??'Not Complete', (!$canFinal ? 'disabled' : $disabledAttr)) ?></div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-top: 1.5rem;">
                        <div style="background: rgba(239, 68, 68, 0.1); padding: 1rem; border-radius: 8px; border-left: 4px solid var(--danger);">
                            <div class="form-group"><label>Demolition Clearance</label><?= rSel('mob_demolition', ['No'=>'No Clearance', 'Yes'=>'Cleared', 'NA'=>'N/A'], $mob['mob_demolition']??'No', (!$canClearance ? 'disabled' : $disabledAttr)) ?></div>
                            <div class="form-group" style="margin:0;"><label>Demolition Execution</label><?= rSel('demo_status', ['Pending','In Progress','Complete','NA'], $mob['demo_status']??'Pending', (!$canClearance ? 'disabled' : $disabledAttr)) ?></div>
                        </div>
                        <div style="background: rgba(245, 158, 11, 0.1); padding: 1rem; border-radius: 8px; border-left: 4px solid var(--warning);">
                            <div class="form-group"><label>Excavation Clearance</label><?= rSel('mob_excavation', ['No'=>'No Clearance', 'Yes'=>'Cleared', 'NA'=>'N/A'], $mob['mob_excavation']??'No', (!$canClearance ? 'disabled' : $disabledAttr)) ?></div>
                            <div class="form-group" style="margin:0;"><label>Excavation Execution</label><?= rSel('excavation_status', ['Pending','In Progress','Complete','NA'], $mob['excavation_status']??'Pending', (!$canClearance ? 'disabled' : $disabledAttr)) ?></div>
                        </div>
                        <div style="background: rgba(34, 197, 94, 0.1); padding: 1rem; border-radius: 8px; border-left: 4px solid var(--success);">
                            <div class="form-group"><label>Construction Clearance</label><?= rSel('mob_construction', ['No'=>'No Clearance', 'Yes'=>'Cleared', 'NA'=>'N/A'], $mob['mob_construction']??'No', (!$canClearance ? 'disabled' : $disabledAttr)) ?></div>
                            <div style="font-size: 0.8rem; color: var(--text-muted); font-style: italic;">(Execution is tracked natively per Block below)</div>
                        </div>
                    </div>
                    
                    <h5 style="margin-top: 2rem; color: #0ea5e9; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px;">Temporary Site Connections</h5>
                    <div class="grid-container">
                        <div class="form-group"><label>Temporary Water</label><?= rSel('temporary_water', ['Not Started', 'In Process', 'Connected'], $mob['temporary_water']??'Not Started', (!$canClearance ? 'disabled' : $disabledAttr)) ?></div>
                        <div class="form-group"><label>Temporary Electricity</label><?= rSel('temporary_electricity', ['Not Started', 'In Process', 'Connected'], $mob['temporary_electricity']??'Not Started', (!$canClearance ? 'disabled' : $disabledAttr)) ?></div>
                        <div class="form-group"><label>Hoarding</label><?= rSel('hoarding', ['No', 'Yes'], $mob['hoarding']??'No', (!$canClearance ? 'disabled' : $disabledAttr)) ?></div>
                        <div class="form-group"><label>Site Toilet</label><?= rSel('site_toilet', ['No', 'Yes'], $mob['site_toilet']??'No', (!$canClearance ? 'disabled' : $disabledAttr)) ?></div>
                    </div>
                </fieldset>
                
                <?php if ($canUpdateStatus): ?><div style="margin-top: 1rem; text-align: right;"><button type="submit" class="btn btn-primary">Save BCA Updates</button></div><?php endif; ?>
            </form>
        </div>
    </details>

    <?php if (empty($projectBlocks)): ?>
        <div class="alert alert-info">No blocks defined. Edit project to add blocks.</div>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="action" value="update_blocks">
            
            <h2 style="border-bottom: 2px solid var(--border-glass); padding-bottom: 10px; margin-bottom: 1.5rem;">🏢 Master Block Execution Engine</h2>
            
            <?php 
            // Core Finishes Definitions
            $cp_blocks = [
                'Engineering Works' => ['icon' => '⚙️', 'color' => '#0ea5e9', 'fields' => [ 'fin_electrical'=>'Electrical Work', 'fin_plumbing'=>'Plumbing Work', 'fin_pumps'=>'Pumps: Lifts & Reservoirs', 'fin_lifts'=>'Lifts', 'fin_substation'=>'Substation', 'fin_septic'=>'Septic Tanks', 'fin_sewer'=>'Main Sewer Conn.' ]],
                'Fire and ELV' => ['icon' => '🔥', 'color' => '#ef4444', 'fields' => [ 'fin_fire_detection'=>'Fire Detection', 'fin_fire_fighting'=>'Fire Fighting', 'fin_fire_doors'=>'Metal Fire Doors', 'fin_intercoms'=>'Intercoms' ]],
                'Landscaping' => ['icon' => '🌳', 'color' => '#22c55e', 'fields' => [ 'fin_garden'=>'Garden Landscaping', 'fin_pool'=>'Common Pool' ]],
                'Rendering' => ['icon' => '🧱', 'color' => '#f97316', 'fields' => [ 'fin_rend_facade'=>'Rendering Façade', 'fin_rend_appogg'=>'Rendering Appogg', 'fin_rend_back'=>'Rendering Back Façade', 'fin_rend_cp'=>'Rendering Common Parts', 'fin_cladding'=>'Other Cladding' ]],
                'Flooring & Waterproofing' => ['icon' => '🛡️', 'color' => '#a855f7', 'fields' => [ 'fin_marble_cp'=>'Marble in Common Parts', 'fin_marble_sills'=>'Marble Sills', 'fin_wp_roof'=>'Waterproofing Roof', 'fin_wp_shafts'=>'Waterproofing Shafts', 'fin_wp_ext'=>'Waterproofing Other Ext.' ]],
                'Gypsum Works' => ['icon' => '🖌️', 'color' => '#14b8a6', 'fields' => [ 'fin_gypsum_cp'=>'Gypsum in Common Parts', 'fin_gypsum_facade'=>'Gypsum in Facades' ]],
                'Apertures & Railings' => ['icon' => '🚪', 'color' => '#eab308', 'fields' => [ 'fin_cp_doors_win'=>'C.P. Doors & Windows', 'fin_int_railings'=>'All Internal Railings', 'fin_partitions'=>'Terrace/Shaft Partitions' ]]
            ];
            $semi_blocks = [
                'Semi-Finished Additions' => ['icon' => '🏠', 'color' => '#6366f1', 'fields' => [ 'fin_water_tanks'=>'Water Tanks', 'fin_wp_balconies'=>'Waterproofing Balconies', 'fin_tile_balconies'=>'Tiling of Balconies', 'fin_apt_fire_doors'=>'Fire Rated Apt Doors', 'fin_apt_doors_win'=>'Apt Doors & Windows', 'fin_ext_railings'=>'All External Railings' ]]
            ];
            $cp_garages = [
                'Garage Common Parts' => ['icon' => '🚗', 'color' => '#64748b', 'fields' => [ 'fin_gar_rend_cp'=>'Rendering Garage C.P.', 'fin_gar_rend'=>'Rendering Garages', 'fin_gar_main_door'=>'Garage Main Door/Gate', 'fin_gar_vent'=>'Garage Vent Grilles' ]]
            ];
            $semi_garages = [
                'Garage Semi-Finished Additions' => ['icon' => '🚘', 'color' => '#475569', 'fields' => [ 'fin_gar_ind_doors'=>'Individual Garage Doors', 'fin_gar_win'=>'Garage Windows' ]]
            ];

            foreach ($projectBlocks as $block): 
                $bId = $block['id'];
                $bFinishLvl = !empty($block['finish_level']) ? $block['finish_level'] : ($project['finishlevel'] ?? 'Shell');
                $finLevelColor = getFinishLevelColor($bFinishLvl);
                
                $rawType = $block['block_type'] ?? 'Residential Block'; 
                $isGarage = (stripos($rawType, 'garage') !== false || stripos($rawType, 'basement') !== false || stripos($rawType, 'parking') !== false);
            ?>
                <details class="block-accordion" id="block-content-<?= $bId ?>">
                    <summary>
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <h3 style="margin:0; color: <?= $finLevelColor ?>; font-size: 1.15rem;">
                                <?= htmlspecialchars($block['block_name']) ?>
                            </h3>
                            <span class="badge" style="font-size: 0.65rem; background: <?= $finLevelColor ?>15; color: <?= $finLevelColor ?>; border: 1px solid <?= $finLevelColor ?>50;">
                                <?= htmlspecialchars($bFinishLvl) ?>
                            </span>
                            <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: normal;">
                                <?= htmlspecialchars($rawType) ?>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 15px; min-width: 250px;">
                            <div style="flex: 1; height: 8px; background: rgba(255,255,255,0.1); border-radius: 4px; overflow: hidden; box-shadow: inset 0 1px 3px rgba(0,0,0,0.5);">
                                <div id="progress-fill-<?= $bId ?>" style="height: 100%; width: <?= $block['progress'] ?>%; background: <?= $block['progress'] == 100 ? '#22c55e' : 'var(--primary-color)' ?>; transition: width 0.3s;"></div>
                            </div>
                            <span id="progress-label-<?= $bId ?>" style="font-weight: bold; color: #fff; width: 45px; text-align: right;"><?= $block['progress'] ?>%</span>
                            <span class="accordion-arrow">▼</span>
                        </div>
                    </summary>

                    <div style="padding: 1.5rem;">
                        
                        <div style="display: flex; flex-wrap: wrap; gap: 1rem; background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 1.5rem;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <label style="font-size: 0.75rem; color: var(--text-muted); font-weight: bold; text-transform: uppercase;">Finish Level Goal:</label>
                                <select name="blocks[<?= $bId ?>][finish_level]" class="block-finish-level" data-block-id="<?= $bId ?>" style="background: #1e1e2d; color: #fff; border: 1px solid var(--border-glass); padding: 6px; border-radius: 4px; font-weight: bold; cursor: pointer;">
                                    <option value="Shell" <?= $bFinishLvl === 'Shell' ? 'selected' : '' ?>>Shell (No Finishes)</option>
                                    <option value="Common Parts Only" <?= $bFinishLvl === 'Common Parts Only' ? 'selected' : '' ?>>Common Parts Only</option>
                                    <option value="Semi Finished" <?= $bFinishLvl === 'Semi Finished' ? 'selected' : '' ?>>Semi Finished</option>
                                    <option value="Finished" <?= $bFinishLvl === 'Finished' ? 'selected' : '' ?>>Finished (Turnkey)</option>
                                </select>
                            </div>
                            <div style="width: 1px; background: rgba(255,255,255,0.1); margin: 0 5px;"></div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <label style="font-size: 0.75rem; color: var(--text-muted); font-weight: bold; text-transform: uppercase;">Overall Block Stage:</label>
                                <select name="blocks[<?= $bId ?>][finishes_overall_status]" class="status-select" style="width: 150px;" <?= $disabledAttr ?>>
                                    <option value="Pending" <?= ($block['finishes_overall_status'] ?? 'Pending') == 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="In Progress" <?= ($block['finishes_overall_status'] ?? '') == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="Complete" <?= ($block['finishes_overall_status'] ?? '') == 'Complete' ? 'selected' : '' ?>>Complete</option>
                                    <option value="NA" <?= ($block['finishes_overall_status'] ?? '') == 'NA' ? 'selected' : '' ?>>NA</option>
                                </select>
                            </div>
                            <input type="hidden" name="blocks[<?= $bId ?>][progress]" class="progress-input" value="<?= $block['progress'] ?>">
                        </div>

                        <h4 style="margin-top: 0; color: #0ea5e9;">1. Structural Construction (Sequential)</h4>
                        <?php $levels = $blockLevels[$bId] ?? []; ?>
                        <?php if (empty($levels)): ?>
                            <div class="alert alert-warning">⚠️ Block levels missing. Please edit the project to set levels.</div>
                        <?php else: ?>
                            <table class="finishes-table mb-4">
                                <thead><tr><th style="width: 200px;">Floor / Level</th><th>Structural Status</th></tr></thead>
                                <tbody class="construction-table-body">
                                    <?php foreach ($levels as $lvl): ?>
                                        <tr>
                                            <td style="font-weight: bold; color: #fff;"><?= htmlspecialchars($lvl['level_name']) ?></td>
                                            <td><?= rSel("levels[{$lvl['id']}][construction_status]", ['Pending','In Progress','Complete','NA'], $lvl['construction_status'], $disabledAttr, 'const-status') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 2rem; margin-bottom: 10px; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
                            <h4 style="margin: 0; color: #f59e0b;">2. Finishes Tracker</h4>
                            <?php if ($canUpdateStatus): ?>
                            <div style="display: flex; gap: 10px; align-items: center; background: rgba(0,0,0,0.3); padding: 5px 10px; border-radius: 6px;">
                                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: bold;">⚡ BULK ACTIONS (AFFECTS DROPDOWNS):</span>
                                <button type="button" class="btn btn-sm" style="background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); margin: 0; padding: 4px 8px; font-size: 0.75rem;" onclick="quickFillBlock(<?= $bId ?>, 'Complete')">Mark Visible As Complete</button>
                                <button type="button" class="btn btn-sm" style="background: rgba(156, 163, 175, 0.1); color: #9ca3af; border: 1px solid rgba(156, 163, 175, 0.3); margin: 0; padding: 4px 8px; font-size: 0.75rem;" onclick="quickFillBlock(<?= $bId ?>, 'Not Required')">Set Visible As Not Required</button>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php
                        function renderScopeMatrix($blockData, $groupSet, $disabledAttr) {
                            $html = '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">';
                            foreach ($groupSet as $gName => $group) {
                                $html .= '<div style="background: rgba(255,255,255,0.02); padding: 16px; border-radius: 8px; border-left: 3px solid '.$group['color'].';">';
                                $html .= '<h5 style="margin: 0 0 12px 0; color: '.$group['color'].'; font-size: 0.95rem; font-weight: 600;">'.$group['icon'].' '.$gName.'</h5>';
                                $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">';
                                foreach ($group['fields'] as $dbKey => $label) {
                                    $val = $blockData[$dbKey] ?? 'Not Required';
                                    $opts = ($dbKey === 'fin_intercoms') ? ['Not Required','Not started','Ongoing CP','First Call','Second Call','Complete'] : ['Not Required','Required','In Progress','Complete'];
                                    
                                    $html .= '<div><label style="display:block; font-size:0.65rem; color:#94a3b8; margin-bottom:4px; font-weight:700; text-transform:uppercase;">'.$label.'</label>';
                                    $html .= '<select name="blocks['.$blockData['id'].']['.$dbKey.']" class="status-select fin-status" style="font-size:0.8rem;" '.$disabledAttr.'>';
                                    foreach ($opts as $opt) {
                                        $sel = ($val === $opt) ? 'selected' : '';
                                        $color = '';
                                        if ($opt === 'Complete') $color = 'color: #22c55e;';
                                        elseif (in_array($opt, ['In Progress','Ongoing CP','First Call','Second Call'])) $color = 'color: #f59e0b;';
                                        elseif ($opt === 'Not Required') $color = 'color: #9ca3af;';
                                        else $color = 'color: #ef4444;';
                                        $html .= '<option value="'.$opt.'" '.$sel.' style="'.$color.'">'.$opt.'</option>';
                                    }
                                    $html .= '</select></div>';
                                }
                                $html .= '</div></div>';
                            }
                            $html .= '</div>';
                            return $html;
                        }
                        ?>

                        <div id="cp-section-<?= $bId ?>" class="scope-section">
                            <h5 style="margin: 0 0 10px 0; color: #0ea5e9;">A. Common Parts Scope</h5>
                            <?= renderScopeMatrix($block, $isGarage ? $cp_garages : $cp_blocks, $disabledAttr) ?>
                        </div>

                        <div id="semi-section-<?= $bId ?>" class="scope-section">
                            <h5 style="margin: 0 0 10px 0; color: #f59e0b;">B. Semi-Finished Additions</h5>
                            <?= renderScopeMatrix($block, $isGarage ? $semi_garages : $semi_blocks, $disabledAttr) ?>
                        </div>

                        <div id="finished-section-<?= $bId ?>" class="scope-section">
                            <h5 style="margin: 0 0 10px 0; color: #22c55e;">C. Interior Turnkey Finishes (By Floor)</h5>
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
                        </div>

                        <h4 style="margin-top: 2rem; color: #10b981; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">3. Post-Construction & Handover</h4>
                        <div class="grid-container" style="background: rgba(16, 185, 129, 0.05); padding: 15px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.2);">
                            <?php $optYNN = ['No','Yes','NA']; ?>
                            <div class="form-group"><label>Compliance Submitted</label><?= rSel("blocks[{$bId}][compliance_submitted]", $optYNN, $block['compliance_submitted'] ?? 'No', $disabledAttr) ?></div>
                            <div class="form-group"><label>Compliance Certified</label><?= rSel("blocks[{$bId}][compliance_certified]", $optYNN, $block['compliance_certified'] ?? 'No', $disabledAttr) ?></div>
                            <div class="form-group"><label>Condominium Formed</label><?= rSel("blocks[{$bId}][condominium_formed]", $optYNN, $block['condominium_formed'] ?? 'No', $disabledAttr) ?></div>
                            <div class="form-group"><label>CP Meters Installed</label><?= rSel("blocks[{$bId}][cp_meters_installed]", $optYNN, $block['cp_meters_installed'] ?? 'No', $disabledAttr) ?></div>
                        </div>

                    </div>
                </details>
                <?php endforeach; ?>
            
            <?php if ($canUpdateStatus || $canEditServices): ?>
                <div class="sticky-save-bar">
                    <div style="display: flex; flex-direction: column;">
                        <span style="color: #fff; font-weight: bold; font-size: 1rem;">Ready to save?</span>
                        <span style="color: var(--text-muted); font-size: 0.75rem;">Progress percentages are automatically calculated and saved.</span>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin: 0; font-size: 1.1rem; padding: 12px 35px; box-shadow: 0 4px 15px rgba(14, 165, 233, 0.5); border-radius: 8px;">
                        💾 Save All Project Changes
                    </button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>

</div>

<script>
const canEditStatus = <?= $canUpdateStatus ? 'true' : 'false' ?>;

// --- Progress Auto-Calculation Engine ---
function recalculateProgress(blockId) {
    const blockDiv = document.getElementById('block-content-' + blockId);
    if (!blockDiv) return;
    
    let validCount = 0;
    let completeCount = 0;
    let inProgressCount = 0;
    
    const selects = blockDiv.querySelectorAll('select.fin-status, select.floor-fin-status');
    selects.forEach(sel => {
        const section = sel.closest('.scope-section');
        // Do not count inputs that are hidden by the Finish Level Logic!
        if (section && section.style.display === 'none') return;
        
        const val = sel.value;
        if (val !== 'Not Required' && val !== 'NA') {
            validCount++;
            if (val === 'Complete') completeCount++;
            if (val === 'In Progress' || val === 'Ongoing CP' || val === 'First Call' || val === 'Second Call') inProgressCount++;
        }
    });
    
    let progress = 0;
    if (validCount > 0) {
        progress = Math.round(((completeCount + (0.5 * inProgressCount)) / validCount) * 100);
    }
    
    const progressInput = blockDiv.querySelector('.progress-input');
    if (progressInput) progressInput.value = progress;
    
    const progressBar = document.getElementById('progress-fill-' + blockId);
    if (progressBar) {
        progressBar.style.width = progress + '%';
        progressBar.style.background = progress === 100 ? '#22c55e' : 'var(--primary-color)';
    }
    const progressLabel = document.getElementById('progress-label-' + blockId);
    if (progressLabel) progressLabel.innerText = progress + '%';
}

// --- Finish Level Toggle Logic ---
function updateBlockVisibility(blockId, level, runRecalc = true) {
    const cp = document.getElementById('cp-section-' + blockId);
    const semi = document.getElementById('semi-section-' + blockId);
    const fin = document.getElementById('finished-section-' + blockId);
    
    if (level === 'Shell') {
        if(cp) cp.style.display = 'none';
        if(semi) semi.style.display = 'none';
        if(fin) fin.style.display = 'none';
    } else if (level === 'Common Parts Only') {
        if(cp) cp.style.display = 'block';
        if(semi) semi.style.display = 'none';
        if(fin) fin.style.display = 'none';
    } else if (level === 'Semi Finished') {
        if(cp) cp.style.display = 'block';
        if(semi) semi.style.display = 'block';
        if(fin) fin.style.display = 'none';
    } else if (level === 'Finished') {
        if(cp) cp.style.display = 'block';
        if(semi) semi.style.display = 'block';
        if(fin) fin.style.display = 'block';
    }
    
    if (runRecalc) recalculateProgress(blockId);
}

// --- Quick Bulk Actions ---
function quickFillBlock(blockId, status) {
    if (!canEditStatus) return;
    const blockDiv = document.getElementById('block-content-' + blockId);
    if (!blockDiv) return;
    
    const selects = blockDiv.querySelectorAll('select.fin-status, select.floor-fin-status');
    selects.forEach(sel => {
        const section = sel.closest('.scope-section');
        if (section && section.style.display !== 'none') {
            let optionExists = Array.from(sel.options).some(opt => opt.value === status);
            if (optionExists) {
                sel.value = status;
                // Update color styling instantly
                sel.style.color = (status === 'Complete') ? '#22c55e' : '#9ca3af';
            }
        }
    });
    
    recalculateProgress(blockId);
}

// --- Sequential Construction Lock ---
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

// --- Init Event Listeners ---
document.addEventListener('DOMContentLoaded', () => {
    
    // Attach change listeners to finishes to drive progress bar
    document.querySelectorAll('select.fin-status, select.floor-fin-status').forEach(sel => {
        sel.addEventListener('change', function() {
            const blockId = this.closest('.block-accordion').id.replace('block-content-', '');
            
            // Adjust Select Color Instantly
            const val = this.value;
            if (val === 'Complete') this.style.color = '#22c55e';
            else if (['In Progress','Ongoing CP','First Call','Second Call'].includes(val)) this.style.color = '#f59e0b';
            else if (val === 'Not Required') this.style.color = '#9ca3af';
            else this.style.color = '#ef4444';
            
            recalculateProgress(blockId);
        });
    });
    
    // Init block visibility based on currently saved dropdowns
    document.querySelectorAll('.block-finish-level').forEach(select => {
        const blockId = select.dataset.blockId;
        updateBlockVisibility(blockId, select.value, false);
        
        select.addEventListener('change', function() {
            updateBlockVisibility(blockId, this.value, true);
            // Change badge color instantly
            const summaryBadge = document.getElementById('block-content-' + blockId).querySelector('.badge');
            if (summaryBadge) {
                summaryBadge.innerText = this.value;
                let color = '#9ca3af';
                if(this.value==='Finished') color='#22c55e';
                else if(this.value==='Semi Finished') color='#f59e0b';
                else if(this.value==='Common Parts Only') color='#0ea5e9';
                summaryBadge.style.color = color;
                summaryBadge.style.borderColor = color + '50';
                summaryBadge.style.background = color + '15';
            }
        });
    });

    if (canEditStatus) {
        document.querySelectorAll('.const-status').forEach(select => {
            select.addEventListener('change', enforceSequentialConstruction);
        });
        enforceSequentialConstruction();
    }
});
</script>

<?php require_once 'footer.php'; ?>
