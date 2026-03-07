<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('view_services') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// ==========================================
// AJAX LIVE EDITING ENDPOINTS (Saves instantly)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    try {
        $action = $_POST['ajax_action'];
        
        // 1. Update Site Supplies (Water/Elec)
        if ($action === 'update_temp') {
            $field = $_POST['field']; 
            $val = $_POST['value'];
            $pid = (int)$_POST['project_id'];
            if(in_array($field, ['temporary_water', 'temporary_electricity'])) {
                $stmt = $pdo->prepare("UPDATE project_mobilisation SET $field = ? WHERE project_id = ?");
                $stmt->execute([$val, $pid]);
                echo json_encode(['success' => true]);
            }
            exit;
        }

        // 2. Update Services Matrix Dots
        if ($action === 'update_service') {
            $pid = (int)$_POST['project_id'];
            $srv = $_POST['service_key'];
            $state = $_POST['state']; // 'not_required', 'pending', 'complete'

            $req = 'Not Required'; $comp = 'Not Complete';
            if ($state === 'pending') { $req = 'Required'; }
            if ($state === 'complete') { $req = 'Required'; $comp = 'Complete'; }

            $stmt = $pdo->prepare("INSERT INTO project_services (project_id, {$srv}_required, {$srv}_complete) 
                                   VALUES (?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE {$srv}_required=VALUES({$srv}_required), {$srv}_complete=VALUES({$srv}_complete)");
            $stmt->execute([$pid, $req, $comp]);
            echo json_encode(['success' => true]);
            exit;
        }
        
        // 3. Update ARMS Table
        if ($action === 'update_arms') {
            $meterId = (int)$_POST['meter_id'];
            $field = $_POST['field'];
            $val = $_POST['value'] !== '' ? $_POST['value'] : null;
            $allowed = ['meter_type', 'account_no', 'meter_no_elec', 'meter_no_water', 'electrician', 'applicant', 'exp_date', 'status', 'notes'];
            if(in_array($field, $allowed)) {
                $stmt = $pdo->prepare("UPDATE project_arms_meters SET $field = ? WHERE id = ?");
                $stmt->execute([$val, $meterId]);
                echo json_encode(['success' => true]);
            }
            exit;
        }
        
        // 4. Add ARMS Row
        if ($action === 'add_arms') {
            $pid = (int)$_POST['project_id'];
            $stmt = $pdo->prepare("INSERT INTO project_arms_meters (project_id, meter_type, status) VALUES (?, 'Temporary', 'Applied')");
            $stmt->execute([$pid]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            exit;
        }
        
        // 5. Delete ARMS Row
        if ($action === 'delete_arms') {
            $meterId = (int)$_POST['meter_id'];
            $stmt = $pdo->prepare("DELETE FROM project_arms_meters WHERE id = ?");
            $stmt->execute([$meterId]);
            echo json_encode(['success' => true]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ==========================================
// PAGE LOAD & DATA FETCHING
// ==========================================
$userId = getCurrentUserId();
$userRole = getCurrentRole();
$isAdmin = isAdmin();

// Filtering Logic
$filterClient = $_GET['filter_client'] ?? 'all';
$filterCity = $_GET['filter_city'] ?? 'all';
$filterDbStatus = $_GET['filter_db_status'] ?? 'Active'; 

$projects = getAccessibleProjects($pdo, $userId);

$cities = array_unique(array_filter(array_column($projects, 'city'))); sort($cities);
$clientIds = array_unique(array_column($projects, 'clientid'));
$clients = [];
if (!empty($clientIds)) {
    $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
    $clientStmt = $pdo->prepare("SELECT id, name FROM clients WHERE id IN ($placeholders) ORDER BY name");
    $clientStmt->execute(array_values($clientIds));
    $clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Apply Filters
foreach ($projects as $key => $p) {
    if ($filterDbStatus !== 'All' && ($p['project_status'] ?? 'Active') !== $filterDbStatus) unset($projects[$key]);
    if ($filterCity !== 'all' && $p['city'] !== $filterCity) unset($projects[$key]);
    if ($filterClient !== 'all' && $p['clientid'] != $filterClient) unset($projects[$key]);
}
$projects = array_values($projects);

// Fetch Engineering Data
$projectIds = array_column($projects, 'id');
$mobData = []; $armsData = []; $srvData = [];

if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    
    // Mobilisation (Temp Water/Elec)
    $mobStmt = $pdo->prepare("SELECT project_id, temporary_water, temporary_electricity FROM project_mobilisation WHERE project_id IN ($placeholders)");
    $mobStmt->execute($projectIds);
    while ($row = $mobStmt->fetch(PDO::FETCH_ASSOC)) { $mobData[$row['project_id']] = $row; }

    // Services Matrix Data
    $srvStmt = $pdo->prepare("SELECT * FROM project_services WHERE project_id IN ($placeholders)");
    $srvStmt->execute($projectIds);
    while ($row = $srvStmt->fetch(PDO::FETCH_ASSOC)) { $srvData[$row['project_id']] = $row; }

    // ARMS Meters
    $armsStmt = $pdo->prepare("SELECT * FROM project_arms_meters WHERE project_id IN ($placeholders) ORDER BY id ASC");
    $armsStmt->execute($projectIds);
    while ($row = $armsStmt->fetch(PDO::FETCH_ASSOC)) { $armsData[$row['project_id']][] = $row; }
}

// Configuration for the Services Matrix
$matrixFields = [
    'existing_meters' => 'Existing Meters Removal',
    'enemalta_deviation' => 'Enemalta Deviation',
    'go_deviation' => 'GO Lines Deviation',
    'melita_deviation' => 'Melita Deviation',
    'lc_lamps' => 'LC Lamps',
    'temp_elec_meter' => 'Temp Elec Meter Inst.',
    'temp_wsc_meter' => 'Temp WSC Meter Inst.'
];

function getSrvState($srvRow, $key) {
    $req = $srvRow[$key.'_required'] ?? 'Not Required';
    $comp = $srvRow[$key.'_complete'] ?? 'Not Complete';
    if ($req === 'Not Required') return 'not_required';
    if ($comp === 'Complete') return 'complete';
    return 'pending';
}
function getSrvColor($state) {
    if ($state === 'complete') return '#22c55e'; 
    if ($state === 'pending') return '#f59e0b'; 
    return '#64748b'; 
}
function getSrvLabel($state) {
    if ($state === 'complete') return 'Required (Complete)';
    if ($state === 'pending') return 'Required (Pending)';
    return 'Not Required';
}

function getTempColor($val) {
    if (in_array($val, ['Connected', 'Yes', 'Complete'])) return '#22c55e'; 
    if (in_array($val, ['In Process'])) return '#f59e0b'; 
    return '#ef4444'; 
}

$pageTitle = 'Live Engineering & Utilities';
require_once 'header.php';
?>

<style>
    /* Fixed Layout Table - Redesigned Proportions */
    .dashboard-wrapper td { vertical-align: top; padding: 1rem 0.75rem; }
    .main-table { width: 100%; min-width: 1100px; table-layout: fixed; border-collapse: collapse; }
    .main-table th.col-proj { width: 17%; }
    .main-table th.col-supply { width: 5%; text-align: center; }
    .main-table th.col-arms { width: 78%; }
    
    /* Inline Editing Styles */
    .live-input { width: 100%; background: transparent; border: 1px solid transparent; color: inherit; font-size: 0.8rem; padding: 4px; border-radius: 4px; transition: all 0.2s; box-sizing: border-box; }
    .live-input:hover { border-color: rgba(255,255,255,0.2); background: rgba(0,0,0,0.1); }
    .live-input:focus { border-color: var(--primary-color); background: #1e1e2d; outline: none; color: #fff; box-shadow: 0 0 5px rgba(14, 165, 233, 0.5); }
    
    .live-select { width: 100%; background: transparent; border: 1px solid transparent; color: inherit; font-size: 0.8rem; padding: 4px; border-radius: 4px; cursor: pointer; appearance: none; }
    .live-select:hover { border-color: rgba(255,255,255,0.2); background: rgba(0,0,0,0.1); }
    .live-select:focus { background: #1e1e2d; border-color: var(--primary-color); outline: none; }
    .live-select option { background: #1e1e2d; color: #fff; }

    /* Interactive Matrix */
    .srv-matrix { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
    .matrix-dot { width: 14px; height: 14px; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.5); cursor: pointer; transition: transform 0.1s, box-shadow 0.2s; flex-shrink: 0; }
    .matrix-dot:hover { transform: scale(1.3); box-shadow: 0 0 8px rgba(255,255,255,0.5); }

    /* Ultra-Compact Temp Buttons */
    .temp-btn { display: flex; align-items: center; justify-content: center; gap: 6px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); padding: 4px 6px; border-radius: 6px; cursor: pointer; user-select: none; transition: 0.2s; width: 100%; box-sizing: border-box; font-size: 1rem; }
    .temp-btn:hover { background: rgba(255,255,255,0.1); }
    .temp-indicator { width: 10px; height: 10px; border-radius: 50%; box-shadow: 0 0 4px rgba(0,0,0,0.5); flex-shrink: 0; }

    /* ARMS Sub-table */
    .arms-wrapper { background: rgba(0,0,0,0.15); border-radius: 6px; padding: 0.5rem; border: 1px solid var(--border-glass); width: 100%; overflow-x: auto; box-sizing: border-box; }
    .arms-table { width: 100%; border-collapse: collapse; min-width: 850px; }
    .arms-table th { font-size: 0.65rem; text-transform: uppercase; color: var(--text-muted); padding: 4px; border-bottom: 1px solid rgba(255,255,255,0.05); text-align: left; }
    .arms-table td { padding: 2px; }

    /* Compact Delete Button (Like Edit PA) */
    .delete-btn { background: #ef4444; border: none; color: white; cursor: pointer; font-size: 0.9rem; width: 20px; height: 20px; border-radius: 4px; display: flex; align-items: center; justify-content: center; transition: 0.2s; margin: 0 auto; padding: 0; }
    .delete-btn:hover { background: #dc2626; transform: scale(1.1); }

    /* Toast Notification */
    #toast { visibility: hidden; min-width: 250px; background-color: var(--success); color: #fff; text-align: center; border-radius: 8px; padding: 12px; position: fixed; z-index: 10000; left: 50%; bottom: 30px; font-weight: bold; transform: translateX(-50%); box-shadow: 0 4px 15px rgba(0,0,0,0.4); opacity: 0; transition: opacity 0.3s, bottom 0.3s; }
    #toast.show { visibility: visible; opacity: 1; bottom: 50px; }
</style>

<div class="main-container" style="max-width: 100%; padding-bottom: 100px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <div>
            <h1 class="page-title" style="margin: 0;">🔌 Live Engineering Hub</h1>
            <p style="color: var(--text-muted); margin: 0; font-size: 0.85rem;">Click the dots and icons to toggle status. Edit ARMS details directly in the table. Changes save instantly.</p>
        </div>
        <div style="display: flex; gap: 1rem; align-items: center; font-size: 0.75rem; color: var(--text-muted); background: rgba(0,0,0,0.2); padding: 0.5rem 1rem; border-radius: 8px;">
            <strong>Legend:</strong>
            <span style="display: flex; align-items: center; gap: 4px;"><div style="width:10px;height:10px;border-radius:50%;background:#64748b;"></div> Not Required</span>
            <span style="display: flex; align-items: center; gap: 4px;"><div style="width:10px;height:10px;border-radius:50%;background:#f59e0b;"></div> Pending</span>
            <span style="display: flex; align-items: center; gap: 4px;"><div style="width:10px;height:10px;border-radius:50%;background:#22c55e;"></div> Complete</span>
        </div>
    </div>

    <div class="filters-section" style="margin-bottom: 1.5rem;">
        <form method="GET" id="engFilters">
            <div class="filters-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
                <div class="filter-group">
                    <label>Client</label>
                    <select name="filter_client">
                        <option value="all">All Clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= $client['id'] ?>" <?= $filterClient == $client['id'] ? 'selected' : '' ?>><?= htmlspecialchars($client['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Locality</label>
                    <select name="filter_city">
                        <option value="all">All Cities</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?= $city ?>" <?= $filterCity == $city ? 'selected' : '' ?>><?= htmlspecialchars($city) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (in_array($userRole, ['admin', 'director'])): ?>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="filter_db_status">
                        <option value="Active" <?= $filterDbStatus === 'Active' ? 'selected' : '' ?>>Active Only</option>
                        <option value="All" <?= $filterDbStatus === 'All' ? 'selected' : '' ?>>All Projects</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filter-group" style="display: flex; flex-direction: column; justify-content: flex-end;">
                    <label style="visibility: hidden;">Apply</label>
                    <button type="submit" class="btn btn-sm" style="width: 100%; height: 35px;">Apply Filters</button>
                </div>
            </div>
        </form>
    </div>

    <div class="dashboard-wrapper" style="overflow-x: auto; width: 100%;">
        <table class="main-table">
            <thead>
                <tr>
                    <th class="col-proj">Project & Services</th>
                    <th class="col-supply">Supply</th>
                    <th class="col-arms">ARMS Applications & Meters (Live Edit)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $project): 
                    $pId = $project['id'];
                    $mob = $mobData[$pId] ?? [];
                    $srv = $srvData[$pId] ?? [];
                    $meters = $armsData[$pId] ?? [];
                ?>
                <tr>
                    <td>
                        <div style="font-weight: 800; color: var(--text-primary); margin-bottom: 4px; font-size: 1.05rem;"><?= htmlspecialchars($project['name']) ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 12px;">📍 <?= htmlspecialchars($project['city']) ?></div>
                        
                        <div style="background: rgba(0,0,0,0.2); padding: 8px 10px; border-radius: 6px; display: inline-block; border: 1px solid rgba(255,255,255,0.05);">
                            <div style="font-size: 0.65rem; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; letter-spacing: 0.5px; font-weight: bold;">Services Required</div>
                            <div class="srv-matrix">
                                <?php foreach ($matrixFields as $dbKey => $label): 
                                    $state = getSrvState($srv, $dbKey);
                                ?>
                                    <div class="matrix-dot" 
                                         data-pid="<?= $pId ?>" 
                                         data-srv="<?= $dbKey ?>" 
                                         data-state="<?= $state ?>" 
                                         data-name="<?= $label ?>"
                                         style="background: <?= getSrvColor($state) ?>" 
                                         title="<?= $label ?>: <?= getSrvLabel($state) ?> (Click to toggle)"
                                         onclick="cycleService(this)">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </td>

                    <td style="vertical-align: middle; text-align: center;">
                        <?php 
                        $tW = $mob['temporary_water'] ?? 'Not Started';
                        $tE = $mob['temporary_electricity'] ?? 'Not Started';
                        ?>
                        <div class="temp-btn" style="margin-bottom: 6px;" onclick="cycleTemp('temporary_water', <?= $pId ?>, this)" data-state="<?= $tW ?>" title="Water - Currently: <?= $tW ?> (Click to toggle)">
                            <span style="pointer-events: none;">💧</span>
                            <div class="temp-indicator" style="background: <?= getTempColor($tW) ?>; pointer-events: none;"></div>
                        </div>
                        <div class="temp-btn" onclick="cycleTemp('temporary_electricity', <?= $pId ?>, this)" data-state="<?= $tE ?>" title="Electricity - Currently: <?= $tE ?> (Click to toggle)">
                            <span style="pointer-events: none;">⚡</span>
                            <div class="temp-indicator" style="background: <?= getTempColor($tE) ?>; pointer-events: none;"></div>
                        </div>
                    </td>

                    <td>
                        <div class="arms-wrapper">
                            <table class="arms-table" id="arms-table-<?= $pId ?>">
                                <thead>
                                    <tr>
                                        <th style="width: 85px;">Type</th>
                                        <th style="width: 100px;">Account No.</th>
                                        <th style="width: 100px;">Electrician</th>
                                        <th style="width: 100px;">Applicant</th>
                                        <th style="width: 100px;">Elec. Meter</th>
                                        <th style="width: 100px;">Water Meter</th>
                                        <th style="width: 105px;">Exp. Date</th>
                                        <th style="width: 90px;">Status</th>
                                        <th>Notes</th>
                                        <th style="width: 25px; text-align: center;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($meters as $m): $mId = $m['id']; ?>
                                    <tr id="meter-row-<?= $mId ?>">
                                        <td>
                                            <select class="live-select" style="color: #0ea5e9; font-weight: bold; padding-left: 0;" onchange="updateRecord('update_arms', 'meter_type', <?= $pId ?>, this.value, <?= $mId ?>)">
                                                <option value="Temporary" <?= $m['meter_type']=='Temporary'?'selected':'' ?>>Temp.</option>
                                                <option value="Common Parts" <?= $m['meter_type']=='Common Parts'?'selected':'' ?>>Common P.</option>
                                                <option value="Apartment" <?= $m['meter_type']=='Apartment'?'selected':'' ?>>Apartment</option>
                                            </select>
                                        </td>
                                        <td><input type="text" class="live-input" value="<?= htmlspecialchars($m['account_no'] ?? '') ?>" onblur="updateRecord('update_arms', 'account_no', <?= $pId ?>, this.value, <?= $mId ?>)" placeholder="411..."></td>
                                        <td><input type="text" class="live-input" value="<?= htmlspecialchars($m['electrician'] ?? '') ?>" onblur="updateRecord('update_arms', 'electrician', <?= $pId ?>, this.value, <?= $mId ?>)" placeholder="Name..."></td>
                                        <td><input type="text" class="live-input" value="<?= htmlspecialchars($m['applicant'] ?? '') ?>" onblur="updateRecord('update_arms', 'applicant', <?= $pId ?>, this.value, <?= $mId ?>)" placeholder="Applicant..."></td>
                                        <td><input type="text" class="live-input" value="<?= htmlspecialchars($m['meter_no_elec'] ?? '') ?>" onblur="updateRecord('update_arms', 'meter_no_elec', <?= $pId ?>, this.value, <?= $mId ?>)" placeholder="EL: ..."></td>
                                        <td><input type="text" class="live-input" value="<?= htmlspecialchars($m['meter_no_water'] ?? '') ?>" onblur="updateRecord('update_arms', 'meter_no_water', <?= $pId ?>, this.value, <?= $mId ?>)" placeholder="W: ..."></td>
                                        <td><input type="date" class="live-input" value="<?= htmlspecialchars($m['exp_date'] ?? '') ?>" onchange="updateRecord('update_arms', 'exp_date', <?= $pId ?>, this.value, <?= $mId ?>)" style="color: #f59e0b; padding-left:0;"></td>
                                        <td>
                                            <select class="live-select" style="padding-left: 0;" onchange="updateRecord('update_arms', 'status', <?= $pId ?>, this.value, <?= $mId ?>)">
                                                <option value="Applied" <?= $m['status']=='Applied'?'selected':'' ?>>🟡 Applied</option>
                                                <option value="Active" <?= $m['status']=='Active'?'selected':'' ?>>🟢 Active</option>
                                                <option value="Removal Done" <?= $m['status']=='Removal Done'?'selected':'' ?>>⚫ Removed</option>
                                                <option value="Transferred" <?= $m['status']=='Transferred'?'selected':'' ?>>🔵 Transferred</option>
                                            </select>
                                        </td>
                                        <td><input type="text" class="live-input" value="<?= htmlspecialchars($m['notes'] ?? '') ?>" onblur="updateRecord('update_arms', 'notes', <?= $pId ?>, this.value, <?= $mId ?>)" placeholder="Notes..."></td>
                                        <td>
                                            <button onclick="deleteMeter(<?= $mId ?>)" class="delete-btn" title="Remove Record">&times;</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <button onclick="addMeter(<?= $pId ?>)" style="background: transparent; border: 1px dashed rgba(255,255,255,0.2); color: #0ea5e9; font-size: 0.75rem; padding: 4px 8px; border-radius: 4px; cursor: pointer; width: 100%; margin-top: 4px; transition: 0.2s;">+ Add ARMS Record</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toast">✅ Saved Successfully</div>

<script>
// --- 1. Cycle Services Matrix Dots ---
async function cycleService(el) {
    const pid = el.dataset.pid;
    const srv = el.dataset.srv;
    const name = el.dataset.name;
    const currentState = el.dataset.state;

    // The Cycle Logic
    const nextStateMap = { 'not_required': 'pending', 'pending': 'complete', 'complete': 'not_required' };
    const colorMap = { 'not_required': '#64748b', 'pending': '#f59e0b', 'complete': '#22c55e' };
    const labelMap = { 'not_required': 'Not Required', 'pending': 'Required (Pending)', 'complete': 'Required (Complete)' };

    const newState = nextStateMap[currentState];

    // Optimistic UI Update
    el.dataset.state = newState;
    el.style.background = colorMap[newState];
    el.title = `${name}: ${labelMap[newState]} (Click to toggle)`;

    const formData = new URLSearchParams();
    formData.append('ajax_action', 'update_service');
    formData.append('project_id', pid);
    formData.append('service_key', srv);
    formData.append('state', newState);

    try {
        const response = await fetch('engineering.php', { method: 'POST', body: formData.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        const data = await response.json();
        if (data.success) showToast('✅ ' + name + ' Updated');
    } catch (e) {
        el.dataset.state = currentState; el.style.background = colorMap[currentState];
        alert('Connection error.');
    }
}

// --- 2. Cycle Temp Water/Electricity Icons ---
async function cycleTemp(field, projectId, el) {
    const currentState = el.dataset.state;
    const indicator = el.querySelector('.temp-indicator');
    
    // The Cycle Logic
    const nextStateMap = { 'Not Started': 'In Process', 'In Process': 'Connected', 'Connected': 'Not Started' };
    const colorMap = { 'Not Started': '#ef4444', 'In Process': '#f59e0b', 'Connected': '#22c55e' };
    
    let safeCurrent = ['Not Started', 'In Process', 'Connected'].includes(currentState) ? currentState : 'Not Started';
    const newState = nextStateMap[safeCurrent];

    // Optimistic UI Update
    el.dataset.state = newState;
    indicator.style.background = colorMap[newState];
    el.title = `${field === 'temporary_water' ? 'Water' : 'Electricity'} - Currently: ${newState} (Click to toggle)`;

    updateRecord('update_temp', field, projectId, newState, null);
}

// --- 3. Live ARMS Table Logic ---
async function updateRecord(action, field, projectId, value, meterId) {
    const formData = new URLSearchParams();
    formData.append('ajax_action', action);
    formData.append('project_id', projectId);
    formData.append('field', field);
    formData.append('value', value);
    if (meterId) formData.append('meter_id', meterId);

    try {
        const response = await fetch('engineering.php', { method: 'POST', body: formData.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        const data = await response.json();
        if (data.success) { showToast('✅ Saved'); } else { alert('Error: ' + data.error); }
    } catch (e) { alert('Connection error while saving.'); }
}

async function addMeter(projectId) {
    const formData = new URLSearchParams();
    formData.append('ajax_action', 'add_arms');
    formData.append('project_id', projectId);
    try {
        const response = await fetch('engineering.php', { method: 'POST', body: formData.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        const data = await response.json();
        if (data.success) window.location.reload(); 
    } catch (e) { alert('Connection error.'); }
}

async function deleteMeter(meterId) {
    if (!confirm('Are you sure you want to delete this meter record?')) return;
    const formData = new URLSearchParams();
    formData.append('ajax_action', 'delete_arms');
    formData.append('meter_id', meterId);
    try {
        const response = await fetch('engineering.php', { method: 'POST', body: formData.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        const data = await response.json();
        if (data.success) { document.getElementById('meter-row-' + meterId).remove(); showToast('🗑️ Deleted'); }
    } catch (e) { alert('Connection error.'); }
}

function showToast(msg) {
    const toast = document.getElementById("toast");
    toast.innerText = msg;
    toast.className = "show";
    setTimeout(function(){ toast.className = toast.className.replace("show", ""); }, 1500);
}
</script>

<?php require_once 'footer.php'; ?>
