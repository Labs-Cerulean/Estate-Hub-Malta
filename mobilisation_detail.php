<?php
require_once 'init.php';
require_once 'session-check.php';

// AUTO-DEPLOY DATABASE UPDATES FOR ESCALATION BLOCKER (Fixed Cascade)
try { $pdo->exec("ALTER TABLE projects ADD COLUMN is_blocked TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE projects ADD COLUMN blocked_reason TEXT"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE projects ADD COLUMN blocked_resolution TEXT"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE projects ADD COLUMN blocker_status VARCHAR(20) DEFAULT 'None'"); } catch(PDOException $e) {}
try { $pdo->exec("UPDATE projects SET blocker_status = 'Active' WHERE is_blocked = 1 AND blocker_status = 'None'"); } catch(PDOException $e) {}

$projectId = $_GET['project_id'] ?? $_GET['projectid'] ?? null;
if (!$projectId) { header('Location: dashboard.php'); exit; }

if (!hasProjectAccess($pdo, $projectId)) { header('Location: dashboard.php?error=access_denied'); exit; }

$project = getProjectWithClient($pdo, $projectId);
if (!$project) { header('Location: dashboard.php'); exit; }

// CAPITAL PROJECT FLAG
$isCapital = in_array(strtolower($project['type'] ?? ''), ['3rd-party', 'capital', '3rd party']);

try {
    $paStmt = $pdo->prepare("SELECT pa_number FROM project_pa_numbers WHERE project_id = ?");
    $paStmt->execute([$projectId]);
    $fetchedPas = $paStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($fetchedPas) { $project['pa_numbers'] = $fetchedPas; }
} catch(PDOException $e) {}

if ($project['is_tracking'] == 1 && !hasPermission('view_tracking') && !isAdmin()) {
    header('Location: dashboard.php?error=access_denied'); exit;
}

$canUpdateStatus = canUpdateStatus($pdo, $projectId);
$canEditServices = hasPermission('edit_services') || isAdmin();
$disabledAttr = $canUpdateStatus ? '' : 'disabled';
$servicesDisabledAttr = $canEditServices ? '' : 'disabled';

$message = ''; $error = '';

function getFinishLevelColor($level) {
    if ($level === 'Finished') return '#22c55e'; 
    if ($level === 'Semi Finished') return '#f59e0b'; 
    if ($level === 'Common Parts Only') return '#0ea5e9'; 
    return '#9ca3af'; 
}

function rSel($n, $opts, $v, $dis, $cls='') {
    $h = "<select name=\"$n\" $dis class=\"$cls\" style=\"padding:0.4rem; font-size:0.8rem; width:100%; border:1px solid var(--border-glass); border-radius:4px; background:var(--bg-secondary); color:var(--text-primary);\">";
    foreach ($opts as $ov => $ol) {
        if (is_numeric($ov)) $ov = $ol;
        $s = ((string)$v === (string)$ov) ? 'selected' : '';
        $h .= "<option value=\"$ov\" $s>$ol</option>";
    }
    return $h . "</select>";
}

function renderScopeMatrix($blockData, $groupSet, $disabledAttr, $isGarage) {
    $html = '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">';
    $typeMatch = $isGarage ? 'Garage Complex' : 'Residential Block';
    
    foreach ($groupSet as $gName => $group) {
        if (!in_array($typeMatch, $group['types'])) continue;
        
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

$sectionA_groups = [
    'Engineering Works' => ['icon' => '⚙️', 'color' => '#0ea5e9', 'types' => ['Residential Block', 'Garage Complex'], 'fields' => ['fin_electrical'=>'Electrical Work', 'fin_plumbing'=>'Plumbing Work', 'fin_pumps'=>'Pumps: Lifts & Reservoirs', 'fin_lifts'=>'Lifts', 'fin_substation'=>'Substation', 'fin_septic'=>'Septic Tanks', 'fin_sewer'=>'Main Sewer Conn.']],
    'Fire and ELV' => ['icon' => '🔥', 'color' => '#ef4444', 'types' => ['Residential Block', 'Garage Complex'], 'fields' => ['fin_fire_detection'=>'Fire Detection', 'fin_fire_fighting'=>'Fire Fighting', 'fin_fire_doors'=>'Metal Fire Doors', 'fin_intercoms'=>'Intercoms']],
    'Landscaping' => ['icon' => '🌳', 'color' => '#22c55e', 'types' => ['Residential Block'], 'fields' => ['fin_garden'=>'Garden Landscaping', 'fin_pool'=>'Common Pool']],
    'Rendering' => ['icon' => '🧱', 'color' => '#f97316', 'types' => ['Residential Block'], 'fields' => ['fin_rend_facade'=>'Rendering Façade', 'fin_rend_appogg'=>'Rendering Appogg', 'fin_rend_back'=>'Rendering Back Façade', 'fin_rend_cp'=>'Rendering Common Parts', 'fin_cladding'=>'Other Cladding']],
    'Flooring & Waterproofing' => ['icon' => '🛡️', 'color' => '#a855f7', 'types' => ['Residential Block'], 'fields' => ['fin_marble_cp'=>'Marble in Common Parts', 'fin_marble_sills'=>'Marble Sills', 'fin_wp_roof'=>'Waterproofing Roof', 'fin_wp_shafts'=>'Waterproofing Shafts', 'fin_wp_ext'=>'Waterproofing Other Ext.']],
    'Gypsum Works' => ['icon' => '🖌️', 'color' => '#14b8a6', 'types' => ['Residential Block'], 'fields' => ['fin_gypsum_cp'=>'Gypsum in Common Parts', 'fin_gypsum_facade'=>'Gypsum in Facades']],
    'Apertures & Railings' => ['icon' => '🚪', 'color' => '#eab308', 'types' => ['Residential Block'], 'fields' => ['fin_cp_doors_win'=>'C.P. Doors & Windows', 'fin_int_railings'=>'All Internal Railings', 'fin_partitions'=>'Terrace/Shaft Partitions']],
    'Garage Common Parts' => ['icon' => '🚗', 'color' => '#64748b', 'types' => ['Garage Complex'], 'fields' => ['fin_gar_rend_cp'=>'Rendering Garage C.P.', 'fin_gar_rend'=>'Rendering Garages', 'fin_gar_main_door'=>'Garage Main Door/Gate', 'fin_gar_vent'=>'Garage Vent Grilles']]
];

$sectionB_groups = [
    'Semi-Finished Additions' => ['icon' => '🏠', 'color' => '#6366f1', 'types' => ['Residential Block'], 'fields' => ['fin_water_tanks'=>'Water Tanks', 'fin_wp_balconies'=>'Waterproofing Balconies', 'fin_tile_balconies'=>'Tiling of Balconies', 'fin_apt_fire_doors'=>'Fire Rated Apt Doors', 'fin_apt_doors_win'=>'Apt Doors & Windows', 'fin_ext_railings'=>'All External Railings']],
    'Garage Semi-Finished Additions' => ['icon' => '🚘', 'color' => '#475569', 'types' => ['Garage Complex'], 'fields' => ['fin_gar_ind_doors'=>'Individual Garage Doors', 'fin_gar_win'=>'Garage Windows']]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ==========================================
    // 1000-INPUT LIMIT BYPASS DECODER
    // ==========================================
    if (isset($_POST['bypass_json_payload'])) {
        $parsed = json_decode($_POST['bypass_json_payload'], true);
        if (is_array($parsed)) {
            $_POST = array_merge($_POST, $parsed);
        }
    }
    
    // --- NEW: EXTRAORDINARY BLOCKER HANDLER ---
    if (($_POST['action'] ?? null) === 'update_blocker' && $canUpdateStatus) {
        $bStatus = $_POST['blocker_status'] ?? 'None';
        $reason = trim($_POST['blocked_reason'] ?? '');
        $resolution = trim($_POST['blocked_resolution'] ?? '');
        
        $pdo->prepare("UPDATE projects SET blocker_status=?, blocked_reason=?, blocked_resolution=? WHERE id=?")->execute([$bStatus, $reason, $resolution, $projectId]);
        $message = "Escalation status updated successfully.";
        $project = getProjectWithClient($pdo, $projectId); // Refresh project data
    }
    
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
    
    if (($_POST['action'] ?? null) === 'update_mobilisation' && $canUpdateStatus) {
        try {
            $updates = []; $values = [];
            $allowedFields = [
                'acquisition_complete', 'acquisition_date', 'archaeologist_assigned', 'change_of_applicant', 
                'geological_test', 'condition_report_contacts', 'condition_reports', 'method_statements', 
                'insurance_status', 'pavement_guarantee', 'wellbeing_guarantee', 'umbrella_guarantee', 
                'responsibility_form', 'mob_demolition', 'mob_excavation', 'mob_construction', 
                'demo_status', 'excavation_status', 'temporary_water', 'temporary_electricity'
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

    if (($_POST['action'] ?? null) === 'update_blocks' && $canUpdateStatus) {
        try {
            $pdo->beginTransaction();

            if (isset($_POST['blocks']) && is_array($_POST['blocks'])) {
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
                        $pdo->prepare($sql)->execute($params);
                    }
                }
            }
            
            if (isset($_POST['levels']) && is_array($_POST['levels'])) {
                $lStmt = $pdo->prepare("UPDATE block_levels SET construction_status=? WHERE id=?");
                foreach ($_POST['levels'] as $lId => $lData) {
                    $lStmt->execute([$lData['construction_status'] ?? 'Pending', $lId]);
                }
            }
            
            if (isset($_POST['floor_finishes']) && is_array($_POST['floor_finishes'])) {
                $stmtFloorFin = $pdo->prepare("INSERT INTO block_levels_statuses (project_id, block_id, level_id, finish_type_id, status, updated_by) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by)");
                foreach ($_POST['floor_finishes'] as $bId => $levelsArray) {
                    foreach ($levelsArray as $lvlId => $types) {
                        foreach ($types as $tId => $status) {
                            $dbStatus = ($status === 'Not Required') ? 'NA' : $status;
                            $stmtFloorFin->execute([$projectId, $bId, $lvlId, $tId, $dbStatus, getCurrentUserId()]);
                        }
                    }
                }
            }
            
            $pdo->commit();
            $message = 'Block execution progress saved securely!';
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
$logsStmt = $pdo->prepare("SELECT pl.*, u.username as author_username, au.username as assignee_username, cu.username as closer_username FROM project_logs pl JOIN users u ON pl.user_id = u.id LEFT JOIN users au ON pl.assigned_to = au.id LEFT JOIN users cu ON pl.closed_by = cu.id WHERE pl.project_id = ? ORDER BY pl.created_at DESC LIMIT 100");
$logsStmt->execute([$projectId]);
$projectLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

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

$stmtFinTypes = $pdo->query("SELECT * FROM finish_types WHERE is_active=1 ORDER BY name ASC");
$finishTypes = $stmtFinTypes->fetchAll(PDO::FETCH_ASSOC);

$stmtAllStatuses = $pdo->prepare("SELECT level_id, finish_type_id, status FROM block_levels_statuses WHERE project_id = ?");
$stmtAllStatuses->execute([$projectId]);
$floorStatusesRaw = $stmtAllStatuses->fetchAll(PDO::FETCH_ASSOC);
$floorStatuses = [];
foreach ($floorStatusesRaw as $r) { $floorStatuses[$r['level_id']][$r['finish_type_id']] = $r['status']; }

$currentStageName = getAccurateProjectStage($pdo, $projectId);
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

$pageTitle = 'Execution - ' . $project['name'];
$isModal = isset($_GET['modal']) && $_GET['modal'] == '1';

require_once 'header.php';

if ($isModal) {
    echo '<style>
        .sidebar, .top-header, .navbar, nav, header { display: none !important; }
        .main-content, .content-wrapper, body { margin-left: 0 !important; margin-top: 0 !important; padding-top: 0 !important; }
        .btn-secondary { display: none !important; }
    </style>';
}
?>

<style>
.status-select { font-weight: bold; background: #1e1e2d; color: #fff; border: 1px solid var(--border-glass); padding: 5px; border-radius: 4px; width: 100%; cursor: pointer;}
.status-select option[value="Pending"], .status-select option[value="No"], .status-select option[value="Not Started"] { color: #ef4444; }
.status-select option[value="In Progress"], .status-select option[value="Ongoing CP"] { color: #f59e0b; }
.status-select option[value="Complete"], .status-select option[value="Yes"], .status-select option[value="Connected"] { color: #22c55e; }
.status-select option[value="Not Required"], .status-select option[value="NA"] { color: #9ca3af; }

.grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem; }
.form-group label { display: block; margin-bottom: 5px; font-size: 0.8rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }

.finishes-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 10px; }
.finishes-table th, .finishes-table td { border: 1px solid var(--border-glass); padding: 8px; text-align: left; }
.finishes-table th { background: rgba(0,0,0,0.2); color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; }

details.custom-accordion { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: 8px; margin-bottom: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
details.custom-accordion > summary { padding: 1.5rem; font-size: 1.3rem; font-weight: 800; color: #fff; cursor: pointer; display: flex; justify-content: space-between; align-items: center; list-style: none; user-select: none; transition: background 0.2s; border-radius: 8px; background: rgba(14, 165, 233, 0.1); border-left: 5px solid #0ea5e9; }
details.custom-accordion > summary:hover { background: rgba(14, 165, 233, 0.15); }
details.custom-accordion > summary::-webkit-details-marker { display: none; }
details.custom-accordion > summary::after { content: '▼'; font-size: 1.2rem; color: #0ea5e9; transition: transform 0.3s ease; }
details.custom-accordion[open] > summary::after { transform: rotate(180deg); }
details.custom-accordion[open] > summary { border-bottom-left-radius: 0; border-bottom-right-radius: 0; border-bottom: 1px solid var(--border-glass); background: rgba(14, 165, 233, 0.15); }
.accordion-content { padding: 1.5rem; }

details.block-accordion { background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: 8px; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); }
details.block-accordion > summary { padding: 1.25rem 1.5rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center; list-style: none; user-select: none; transition: background 0.2s; border-radius: 8px; }
details.block-accordion > summary:hover { background: rgba(255,255,255,0.02); }
details.block-accordion > summary::-webkit-details-marker { display: none; }
.accordion-arrow { transition: transform 0.3s; font-size: 1rem; color: var(--primary-color); }
details[open].block-accordion .accordion-arrow { transform: rotate(180deg); }
details[open].block-accordion > summary { border-bottom: 1px solid var(--border-glass); border-bottom-left-radius: 0; border-bottom-right-radius: 0; }

.stage-tracker { display: flex; align-items: center; justify-content: space-between; background: var(--bg-card); border: 1px solid var(--border-glass); border-radius: var(--radius-md); padding: 1.5rem; margin-bottom: 2rem; }
.stage-badge { display: inline-block; padding: 0.5rem 1rem; border-radius: 20px; background: rgba(99, 102, 241, 0.15); color: var(--primary-color); font-weight: 700; font-size: 1.1rem; border: 1px solid rgba(99, 102, 241, 0.3); }

.sticky-save-bar { position: sticky; bottom: 0; background: rgba(30, 30, 45, 0.95); backdrop-filter: blur(10px); padding: 15px 30px; border-top: 1px solid var(--border-glass); z-index: 1000; display: flex; justify-content: space-between; align-items: center; gap: 15px; box-shadow: 0 -10px 25px rgba(0,0,0,0.5); border-radius: 12px 12px 0 0; margin-top: 2rem; }
</style>

<div class="main-container" style="padding-bottom: 100px;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h1 class="page-title" style="margin: 0;"><?= htmlspecialchars($project['name']) ?></h1>
        <?php if(!$isModal): ?><a href="projects.php" class="btn btn-secondary">← Back to Projects</a><?php endif; ?>
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

    <div class="section-card" style="margin-bottom: 2rem; border-top: 4px solid #dc2626; background: rgba(220,38,38,0.02);">
        <div class="section-header">
            <h2 style="color: #dc2626; margin: 0; display: flex; align-items: center; gap: 10px;">🚨 Extraordinary Escalation / Blocker</h2>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_blocker">
            
            <div style="margin-bottom: 1.5rem; margin-top: 1rem;">
                <label style="color: #dc2626; font-weight: bold; font-size: 1.1rem; display:block;">Escalation Status</label>
                <select name="blocker_status" style="padding: 0.5rem; border-radius: 6px; background: var(--bg-card); color: #fff; border: 1px solid var(--border-glass); margin-top: 5px; width: 100%; max-width: 300px; display: block; font-weight:bold;" onchange="document.getElementById('blocker_details').style.display = (this.value !== 'None') ? 'block' : 'none';">
                    <option value="None" <?= ($project['blocker_status'] ?? 'None') === 'None' ? 'selected' : '' ?>>No Escalation (Normal)</option>
                    <option value="Active" <?= ($project['blocker_status'] ?? '') === 'Active' ? 'selected' : '' ?>>🚨 Active Blocker</option>
                    <option value="Cleared" <?= ($project['blocker_status'] ?? '') === 'Cleared' ? 'selected' : '' ?>>✅ Blocker Cleared</option>
                </select>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 5px;">Use this to highlight delays outside standard operational progress. Marking as "Cleared" preserves the history for the report.</p>
            </div>
            
            <div id="blocker_details" style="display: <?= ($project['blocker_status'] ?? 'None') !== 'None' ? 'block' : 'none' ?>;">
                <div class="form-group">
                    <label style="color: #fca5a5;">Reason for Blocker</label>
                    <textarea name="blocked_reason" rows="2" placeholder="e.g. Neighbor injunction, awaiting court decision..." style="border-color: rgba(220,38,38,0.5);"><?= htmlspecialchars($project['blocked_reason'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label style="color: #fca5a5;">Resolution Plan / Action Being Taken</label>
                    <textarea name="blocked_resolution" rows="2" placeholder="e.g. Legal team engaged, expected court date 12th Oct..." style="border-color: rgba(220,38,38,0.5);"><?= htmlspecialchars($project['blocked_resolution'] ?? '') ?></textarea>
                </div>
                <?php if ($canUpdateStatus): ?>
                    <button type="submit" class="btn" style="background: #dc2626; color: #fff; border: none; margin-top: 10px;">Save Escalation Status</button>
                <?php endif; ?>
            </div>
        </form>
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
        <summary>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.5rem;">📋</span> Pre-Construction & BCA Clearances
            </div>
        </summary>
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
                    </div>
                </fieldset>
                
                <?php if ($canUpdateStatus): ?><div style="margin-top: 1rem; text-align: right;"><button type="submit" class="btn btn-primary">Save BCA Updates</button></div><?php endif; ?>
            </form>
        </div>
    </details>

    <?php if (empty($projectBlocks)): ?>
        <div class="alert alert-info">No blocks defined. Edit project to add blocks.</div>
    <?php else: ?>
        <form method="POST" id="masterBlocksForm">
            <input type="hidden" name="action" value="update_blocks">
            <h2 style="border-bottom: 2px solid var(--border-glass); padding-bottom: 10px; margin-bottom: 1.5rem; margin-top: 2.5rem;">🏢 Master Block Execution Engine</h2>
            
            <?php 
            foreach ($projectBlocks as $block): 
                $bId = $block['id'];
                $bFinishLvl = !empty($block['finish_level']) ? $block['finish_level'] : ($project['finishlevel'] ?? 'Shell');
                if ($bFinishLvl === 'Semi-Finished') { $bFinishLvl = 'Semi Finished'; } 
                
                $finLevelColor = getFinishLevelColor($bFinishLvl);
                $rawType = $block['block_type'] ?? 'Residential Block'; 
                $isGarage = (stripos($rawType, 'garage') !== false || stripos($rawType, 'basement') !== false || stripos($rawType, 'parking') !== false);
                
                $showA = in_array($bFinishLvl, ['Common Parts Only', 'Semi Finished', 'Finished']) ? 'block' : 'none';
                $showB = in_array($bFinishLvl, ['Semi Finished', 'Finished']) ? 'block' : 'none';
                $showC = ($bFinishLvl === 'Finished' && !$isGarage) ? 'block' : 'none';
            ?>
                <details class="block-accordion" id="block-content-<?= $bId ?>">
                    <summary>
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <h3 style="margin:0; color: <?= $finLevelColor ?>; font-size: 1.15rem;">
                                <?= htmlspecialchars($block['block_name']) ?>
                            </h3>
                            <span class="badge badge-dynamic" style="font-size: 0.65rem; background: <?= $finLevelColor ?>15; color: <?= $finLevelColor ?>; border: 1px solid <?= $finLevelColor ?>50;">
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
                                <select name="blocks[<?= $bId ?>][finish_level]" class="block-finish-level" data-block-id="<?= $bId ?>" data-is-garage="<?= $isGarage ? 'true' : 'false' ?>" style="background: #1e1e2d; color: #fff; border: 1px solid var(--border-glass); padding: 6px; border-radius: 4px; font-weight: bold; cursor: pointer;">
                                    <option value="Shell" <?= $bFinishLvl === 'Shell' ? 'selected' : '' ?>>Shell (No Finishes)</option>
                                    <option value="Common Parts Only" <?= $bFinishLvl === 'Common Parts Only' ? 'selected' : '' ?>>Common Parts Only</option>
                                    <option value="Semi Finished" <?= $bFinishLvl === 'Semi Finished' ? 'selected' : '' ?>>Semi Finished</option>
                                    <option value="Finished" <?= $bFinishLvl === 'Finished' ? 'selected' : '' ?>>Finished (Turnkey)</option>
                                </select>
                            </div>
                            
                            <div style="width: 1px; background: rgba(255,255,255,0.1); margin: 0 5px;"></div>
                            
                            <div id="overall-fin-container-<?= $bId ?>" style="display: flex; align-items: center; gap: 8px; <?= $bFinishLvl === 'Shell' ? 'display:none;' : '' ?>">
                                <label style="font-size: 0.75rem; color: var(--text-muted); font-weight: bold; text-transform: uppercase;">Overall Finishes Status:</label>
                                <select name="blocks[<?= $bId ?>][finishes_overall_status]" class="status-select fin-master-status" style="width: auto; padding: 6px; cursor: pointer;" <?= $disabledAttr ?>>
                                    <?php $mfs = $block['finishes_overall_status'] ?? 'Pending'; ?>
                                    <option value="Pending" <?= $mfs === 'Pending' ? 'selected' : '' ?> style="color:#ef4444;">Pending</option>
                                    <option value="In Progress" <?= $mfs === 'In Progress' ? 'selected' : '' ?> style="color:#f59e0b;">In Progress</option>
                                    <option value="Complete" <?= $mfs === 'Complete' ? 'selected' : '' ?> style="color:#22c55e;">Complete</option>
                                    <option value="NA" <?= $mfs === 'NA' ? 'selected' : '' ?> style="color:#9ca3af;">N/A</option>
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

                        <div id="cp-section-<?= $bId ?>" class="scope-section" style="display: <?= $showA ?>;">
                            <h5 style="margin: 0 0 10px 0; color: #0ea5e9;">A. Common Parts Scope</h5>
                            <?= renderScopeMatrix($block, $sectionA_groups, $disabledAttr, $isGarage) ?>
                        </div>

                        <div id="semi-section-<?= $bId ?>" class="scope-section" style="display: <?= $showB ?>;">
                            <h5 style="margin: 0 0 10px 0; color: #f59e0b;">B. Semi-Finished Scope</h5>
                            <?= renderScopeMatrix($block, $sectionB_groups, $disabledAttr, $isGarage) ?>
                        </div>

                        <div id="finished-section-<?= $bId ?>" class="scope-section" style="display: <?= $showC ?>;">
                            <h5 style="margin: 0 0 10px 0; color: #22c55e;">C. Interior Turnkey Finishes (By Floor)</h5>
                            <div style="overflow-x: auto; margin-bottom: 1.5rem;">
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
                                                    if ($currStatus === 'NA') $currStatus = 'Not Required';
                                                ?>
                                                    <td>
                                                        <select name="floor_finishes[<?= $bId ?>][<?= $lvl['id'] ?>][<?= $ft['id'] ?>]" class="status-select floor-fin-status" style="font-size: 0.75rem;" <?= $disabledAttr ?>>
                                                            <option value="Not Required" <?= $currStatus == 'Not Required' ? 'selected' : '' ?>>Not Required</option>
                                                            <option value="Pending" <?= $currStatus == 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                            <option value="In Progress" <?= $currStatus == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                                            <option value="Complete" <?= $currStatus == 'Complete' ? 'selected' : '' ?>>Complete</option>
                                                        </select>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <?php if (!$isCapital): ?>
                        <h4 style="margin-top: 2rem; color: #10b981; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">3. Post-Construction Milestones</h4>
                        <div class="grid-container" style="background: rgba(16, 185, 129, 0.05); padding: 15px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.2);">
                            <?php $optYNN = ['No','Yes','NA']; ?>
                            <div class="form-group"><label>Compliance Submitted</label><?= rSel("blocks[{$bId}][compliance_submitted]", $optYNN, $block['compliance_submitted'] ?? 'No', $disabledAttr) ?></div>
                            <div class="form-group"><label>Compliance Certified</label><?= rSel("blocks[{$bId}][compliance_certified]", $optYNN, $block['compliance_certified'] ?? 'No', $disabledAttr) ?></div>
                            <div class="form-group"><label>Condominium Formed</label><?= rSel("blocks[{$bId}][condominium_formed]", $optYNN, $block['condominium_formed'] ?? 'No', $disabledAttr) ?></div>
                            <div class="form-group"><label>CP Meters Installed</label><?= rSel("blocks[{$bId}][cp_meters_installed]", $optYNN, $block['cp_meters_installed'] ?? 'No', $disabledAttr) ?></div>
                        </div>
                        <?php else: ?>
                            <div style="display: none;">
                                <input type="hidden" name="blocks[<?= $bId ?>][compliance_submitted]" value="<?= $block['compliance_submitted'] ?? 'NA' ?>">
                                <input type="hidden" name="blocks[<?= $bId ?>][compliance_certified]" value="<?= $block['compliance_certified'] ?? 'NA' ?>">
                                <input type="hidden" name="blocks[<?= $bId ?>][condominium_formed]" value="<?= $block['condominium_formed'] ?? 'NA' ?>">
                                <input type="hidden" name="blocks[<?= $bId ?>][cp_meters_installed]" value="<?= $block['cp_meters_installed'] ?? 'NA' ?>">
                            </div>
                        <?php endif; ?>

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

    <details class="custom-accordion">
        <summary>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.5rem;">⚡</span> Services Engineer Utilities
            </div>
        </summary>
        <div class="accordion-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_services">
                <div class="grid-container">
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
const canEditStatus = <?= $canUpdateStatus ? 'true' : 'false' ?>;

document.querySelectorAll('select.req-toggle').forEach(function(select) {
    select.addEventListener('change', function() {
        const compSelect = this.parentElement.querySelector('select.comp-status');
        if (this.value === 'Required') { compSelect.disabled = false; } 
        else { compSelect.disabled = true; compSelect.value = 'Not Complete'; }
    });
});

function recalculateProgress(blockId) {
    const blockDiv = document.getElementById('block-content-' + blockId);
    if (!blockDiv) return;
    
    let validCount = 0;
    let completeCount = 0;
    let inProgressCount = 0;
    
    const selects = blockDiv.querySelectorAll('select.fin-status, select.floor-fin-status, select.const-status');
    selects.forEach(sel => {
        const section = sel.closest('.scope-section');
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

function updateBlockVisibility(blockId, level, isGarage, runRecalc = true) {
    const cp = document.getElementById('cp-section-' + blockId);
    const semi = document.getElementById('semi-section-' + blockId);
    const fin = document.getElementById('finished-section-' + blockId);
    const mstr = document.getElementById('overall-fin-container-' + blockId);
    
    if (level === 'Shell') {
        if(cp) cp.style.display = 'none';
        if(semi) semi.style.display = 'none';
        if(fin) fin.style.display = 'none';
        if(mstr) mstr.style.display = 'none';
    } else if (level === 'Common Parts Only') {
        if(cp) cp.style.display = 'block';
        if(semi) semi.style.display = 'none';
        if(fin) fin.style.display = 'none';
        if(mstr) mstr.style.display = 'flex';
    } else if (level === 'Semi Finished') {
        if(cp) cp.style.display = 'block';
        if(semi) semi.style.display = 'block';
        if(fin) fin.style.display = 'none';
        if(mstr) mstr.style.display = 'flex';
    } else if (level === 'Finished') {
        if(cp) cp.style.display = 'block';
        if(semi) semi.style.display = 'block';
        if(fin) fin.style.display = (isGarage === 'true') ? 'none' : 'block';
        if(mstr) mstr.style.display = 'flex';
    }
    
    if (runRecalc) recalculateProgress(blockId);
}

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
                sel.style.color = (status === 'Complete') ? '#22c55e' : '#9ca3af';
            }
        }
    });
    recalculateProgress(blockId);
}

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

document.addEventListener('DOMContentLoaded', () => {
    
    // FORCE UPDATE ON LOAD TO FIX DATABASE BUGS
    document.querySelectorAll('.block-accordion').forEach(block => {
        const bId = block.id.replace('block-content-', '');
        const lvlSelect = block.querySelector('.block-finish-level');
        if (lvlSelect) {
            updateBlockVisibility(bId, lvlSelect.value, lvlSelect.dataset.isGarage, true);
        } else {
            recalculateProgress(bId);
        }
    });
    
    document.querySelectorAll('select.fin-status, select.floor-fin-status, select.const-status').forEach(sel => {
        sel.addEventListener('change', function() {
            const blockId = this.closest('.block-accordion').id.replace('block-content-', '');
            const val = this.value;
            if (val === 'Complete') this.style.color = '#22c55e';
            else if (['In Progress','Ongoing CP','First Call','Second Call'].includes(val)) this.style.color = '#f59e0b';
            else if (val === 'Not Required' || val === 'NA') this.style.color = '#9ca3af';
            else this.style.color = '#ef4444';
            
            recalculateProgress(blockId);
        });
    });
    
    document.querySelectorAll('.block-finish-level').forEach(select => {
        const blockId = select.dataset.blockId;
        const isGarage = select.dataset.isGarage;
        
        select.addEventListener('change', function() {
            updateBlockVisibility(blockId, this.value, isGarage, true);
            const summaryBadge = document.getElementById('block-content-' + blockId).querySelector('.badge-dynamic');
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
    
    document.querySelectorAll('.fin-master-status').forEach(sel => {
        sel.addEventListener('change', function() {
            const val = this.value;
            if (val === 'Complete') this.style.color = '#22c55e';
            else if (val === 'In Progress') this.style.color = '#f59e0b';
            else if (val === 'NA') this.style.color = '#9ca3af';
            else this.style.color = '#ef4444';
        });
    });

    if (canEditStatus) {
        document.querySelectorAll('.const-status').forEach(select => {
            select.addEventListener('change', enforceSequentialConstruction);
        });
        enforceSequentialConstruction();
    }
    
    const masterForm = document.getElementById('masterBlocksForm');
    if (masterForm) {
        masterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.innerHTML = '💾 Compressing & Saving...';
                btn.style.opacity = '0.7';
                btn.disabled = true;
            }

            var obj = {};
            var formData = new FormData(this);
            for (var [key, value] of formData.entries()) {
                var keys = key.replace(/\]/g, "").split(/\[/);
                var current = obj;
                for (var i = 0; i < keys.length; i++) {
                    var k = keys[i];
                    if (k === "") k = Object.keys(current).length;
                    if (i === keys.length - 1) {
                        current[k] = value;
                    } else {
                        current[k] = current[k] || {};
                        current = current[k];
                    }
                }
            }

            var bypassForm = document.createElement('form');
            bypassForm.method = 'POST';
            bypassForm.action = window.location.href; 
            
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'bypass_json_payload';
            input.value = JSON.stringify(obj);
            bypassForm.appendChild(input);
            
            document.body.appendChild(bypassForm);
            bypassForm.submit();
        });
    }
});
</script>

<?php require_once 'footer.php'; ?>
