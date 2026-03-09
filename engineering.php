<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('edit_services') && !isAdmin()) {
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
        
        // 1. Update Site Supplies
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

        // 2. Update Services Matrix
        if ($action === 'update_service') {
            $pid = (int)$_POST['project_id'];
            $srv = $_POST['service_key'];
            $state = $_POST['state']; 

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
        
        // 3. Update ARMS Tracker
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
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ==========================================
// GLOBAL FILTER ENGINE
// ==========================================
$dbStatus = $_GET['filter_db_status'] ?? 'Active'; // Default to active projects
$filterCity = $_GET['filter_city'] ?? 'All';

$whereClauses = ["type = '3rd-party'"];
$params = [];

if ($dbStatus !== 'All') {
    $whereClauses[] = "project_status = ?";
    $params[] = $dbStatus;
}
if ($filterCity !== 'All') {
    $whereClauses[] = "city = ?";
    $params[] = $filterCity;
}

$whereSql = implode(' AND ', $whereClauses);

// Fetch matching projects
$stmt = $pdo->prepare("SELECT id, name, city, project_status FROM projects WHERE $whereSql ORDER BY name ASC");
$stmt->execute($params);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Extract unique cities for dropdown
$citiesQuery = $pdo->query("SELECT DISTINCT city FROM projects WHERE type = '3rd-party' AND city IS NOT NULL AND city != '' ORDER BY city ASC");
$allCities = $citiesQuery->fetchAll(PDO::FETCH_COLUMN);

// Pre-fetch related data for matching projects to avoid N+1 queries
$projectIds = array_column($projects, 'id');
$mobData = []; $srvData = []; $armsData = [];

if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    
    // Mobilisation (Temp Utilities)
    $mobStmt = $pdo->prepare("SELECT project_id, temporary_water, temporary_electricity FROM project_mobilisation WHERE project_id IN ($placeholders)");
    $mobStmt->execute($projectIds);
    while ($row = $mobStmt->fetch(PDO::FETCH_ASSOC)) { $mobData[$row['project_id']] = $row; }

    // Services Matrix
    $srvStmt = $pdo->prepare("SELECT * FROM project_services WHERE project_id IN ($placeholders)");
    $srvStmt->execute($projectIds);
    while ($row = $srvStmt->fetch(PDO::FETCH_ASSOC)) { $srvData[$row['project_id']] = $row; }

    // ARMS Meters
    $armsStmt = $pdo->prepare("SELECT * FROM project_arms_meters WHERE project_id IN ($placeholders) ORDER BY id ASC");
    $armsStmt->execute($projectIds);
    while ($row = $armsStmt->fetch(PDO::FETCH_ASSOC)) { $armsData[$row['project_id']][] = $row; }
}

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
    if ($state === 'complete') return '#22c55e'; // Green
    if ($state === 'pending') return '#f59e0b'; // Yellow
    return 'rgba(255,255,255,0.1)'; // Gray/Off
}
function getSrvLabel($state) {
    if ($state === 'complete') return 'Complete';
    if ($state === 'pending') return 'Pending (Action Required)';
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


        <?php
        // Query active projects for meters expiring within 30 days (or already expired)
        $expiringMetersStmt = $pdo->query("
            SELECT a.id as meter_id, a.project_id, a.meter_type, a.exp_date, p.name as project_name 
            FROM project_arms_meters a
            JOIN projects p ON a.project_id = p.id
            WHERE a.exp_date IS NOT NULL 
              AND a.exp_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
              AND (a.status IS NULL OR a.status NOT IN ('Removed', 'Closed'))
              AND p.project_status = 'Active'
            ORDER BY a.exp_date ASC
        ");
        $expiringMeters = $expiringMetersStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($expiringMeters)): 
        ?>
        <div style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.3); border-left: 4px solid #ef4444; border-radius: 8px; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.2);">
            <h3 style="margin-top: 0; color: #ef4444; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                ⚠️ Action Required: ARMS Meters Expiring Soon
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <?php foreach($expiringMeters as $meter): 
                    $now = new DateTime();
                    $exp = new DateTime($meter['exp_date']);
                    
                    // Reset time to midnight so day calculations are perfectly accurate
                    $now->setTime(0,0,0);
                    $exp->setTime(0,0,0);
                    
                    $days = (int)$now->diff($exp)->format('%r%a');
                    $isExpired = $days < 0;
                ?>
                    <a href="#project-<?= $meter['project_id'] ?>" onclick="highlightProject('project-<?= $meter['project_id'] ?>')" style="display: block; text-decoration: none; background: #1e1e2d; padding: 1rem; border-radius: 6px; border: 1px solid var(--border-glass); transition: 0.2s;">
                        <div style="font-weight: 800; color: var(--primary-color); margin-bottom: 4px;"><?= htmlspecialchars($meter['project_name']) ?></div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 8px;">Type: <?= htmlspecialchars($meter['meter_type'] ?: 'Unknown') ?></div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <?php if($isExpired): ?>
                                <span class="badge badge-red">Expired <?= abs($days) ?>d ago</span>
                            <?php elseif($days === 0): ?>
                                <span class="badge badge-red">Expires TODAY</span>
                            <?php else: ?>
                                <span class="badge badge-yellow">Expires in <?= $days ?>d</span>
                            <?php endif; ?>
                            <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: bold;"><?= date('d M Y', strtotime($meter['exp_date'])) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        // This adds smooth scrolling and flashes the row red so her eyes go right to it
        document.documentElement.style.scrollBehavior = "smooth";
        function highlightProject(targetId) {
            setTimeout(() => {
                const target = document.getElementById(targetId);
                if (target) {
                    const originalBg = target.style.backgroundColor;
                    target.style.transition = 'background-color 0.4s ease';
                    target.style.backgroundColor = 'rgba(239, 68, 68, 0.3)';
                    setTimeout(() => { target.style.backgroundColor = originalBg; }, 1500);
                }
            }, 500); // Wait for the scroll to finish before flashing
        }
        </script>
        <?php endif; ?>
        <style>
    /* Main Layout Table */
    .dashboard-wrapper td { vertical-align: top; padding: 1.25rem 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.05); }
    .main-table { width: 100%; border-collapse: collapse; }
    .col-proj { width: 22%; min-width: 200px; }
    .col-supply { width: 80px; text-align: center; }
    .col-arms { width: auto; }

    /* Interactive Matrix */
    .srv-matrix { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
    .matrix-dot { width: 14px; height: 14px; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.5); cursor: pointer; transition: transform 0.1s, box-shadow 0.2s; flex-shrink: 0; }
    .matrix-dot:hover { transform: scale(1.3); box-shadow: 0 0 8px rgba(255,255,255,0.5); }

    /* Ultra-Compact Temp Buttons */
    .temp-btn { display: flex; align-items: center; justify-content: center; gap: 6px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); padding: 6px; border-radius: 6px; cursor: pointer; user-select: none; transition: 0.2s; width: 100%; box-sizing: border-box; font-size: 1.1rem; }
    .temp-btn:hover { background: rgba(255,255,255,0.15); }
    .temp-indicator { width: 10px; height: 10px; border-radius: 50%; box-shadow: 0 0 4px rgba(0,0,0,0.5); flex-shrink: 0; }

    /* ==========================================
       THE NEW FLEXBOX ARMS CARDS
       ========================================== */
    .arms-wrapper { display: flex; flex-direction: column; gap: 10px; overflow-x: auto; padding-bottom: 5px; }
    
    .meter-card { 
        display: flex; gap: 12px; background: rgba(0,0,0,0.2); 
        border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; 
        padding: 12px; align-items: stretch; min-width: 900px; 
    }
    
    .meter-col { display: flex; flex-direction: column; gap: 10px; flex: 1; }
    .meter-col.col-notes { flex: 1.5; }
    .meter-col.col-action { flex: 0 0 30px; justify-content: center; align-items: center; }

    .input-group { display: flex; flex-direction: column; gap: 4px; }
    .input-group label { font-size: 0.65rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
    
    /* Input Styling */
    .live-input, .live-select { 
        width: 100%; box-sizing: border-box; background: #1e1e2d; 
        border: 1px solid var(--border-glass); color: #fff; padding: 6px 8px; 
        border-radius: 4px; font-size: 0.8rem; transition: border-color 0.2s; 
    }
    .live-input:focus, .live-select:focus { border-color: var(--primary-color); outline: none; }
    .live-input[type="date"] { padding: 5px 8px; }

    /* Type Badge Select */
    .type-select { font-weight: bold; background: rgba(0,0,0,0.4); border-color: rgba(255,255,255,0.2); }
    .type-select option[value="Permanent"] { color: #10B981; }
    .type-select option[value="Temporary"] { color: #f59e0b; }

    /* Status Coloring */
    .status-select { font-weight: bold; }
    .status-select.s-applied { color: #0ea5e9; border-color: rgba(14, 165, 233, 0.4); background: rgba(14, 165, 233, 0.1); }
    .status-select.s-processing { color: #f59e0b; border-color: rgba(245, 158, 11, 0.4); background: rgba(245, 158, 11, 0.1); }
    .status-select.s-installed { color: #10B981; border-color: rgba(16, 185, 129, 0.4); background: rgba(16, 185, 129, 0.1); }
    .status-select.s-removed { color: #9ca3af; border-color: rgba(156, 163, 175, 0.4); background: rgba(156, 163, 175, 0.1); }
    .status-select.s-closed { color: #ef4444; border-color: rgba(239, 68, 68, 0.4); background: rgba(239, 68, 68, 0.1); }

    .btn-add-meter { background: rgba(255,255,255,0.05); border: 1px dashed rgba(255,255,255,0.2); color: var(--text-muted); font-size: 0.8rem; padding: 8px; width: 100%; border-radius: 6px; cursor: pointer; transition: 0.2s; text-align: center; }
    .btn-add-meter:hover { background: rgba(255,255,255,0.1); color: #fff; border-color: var(--primary-color); }
    
    .btn-del { background: transparent; border: none; color: #ef4444; cursor: pointer; font-size: 1.1rem; opacity: 0.5; transition: 0.2s; padding: 4px; border-radius: 4px; }
    .btn-del:hover { opacity: 1; background: rgba(239, 68, 68, 0.1); }

    /* Toast Notification */
    #toast { visibility: hidden; min-width: 250px; background-color: #22c55e; color: #fff; text-align: center; border-radius: 4px; padding: 12px; position: fixed; z-index: 9999; left: 50%; bottom: 30px; transform: translateX(-50%); font-weight: bold; box-shadow: 0px 4px 10px rgba(0,0,0,0.5); opacity: 0; transition: opacity 0.3s, visibility 0.3s; }
    #toast.show { visibility: visible; opacity: 1; }
</style>

<div class="main-container" style="max-width: 100%; padding: 1.5rem;">
    
    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0.25rem;">Engineering & Live Services Matrix</h1>
            <p style="color: var(--text-secondary); margin: 0;">Click indicators to cycle status. Inputs save automatically on blur.</p>
        </div>
        
        <form method="GET" style="background: rgba(0,0,0,0.2); border: 1px solid var(--border-glass); border-radius: 8px; padding: 0.75rem 1rem;">
            <div style="display: flex; gap: 1rem; align-items: center;">
                <div class="filter-group" style="margin: 0;">
                    <label style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px; display: block;">City/Location</label>
                    <select name="filter_city" style="background: #1e1e2d; border: 1px solid var(--border-glass); color: #fff; padding: 6px 12px; border-radius: 4px;">
                        <option value="All">All Locations</option>
                        <?php foreach($allCities as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $filterCity === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (hasPermission('view_all_projects') || isAdmin()): ?>
                <div class="filter-group" style="margin: 0;">
                    <label style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px; display: block;">Project Status</label>
                    <select name="filter_db_status" style="background: #1e1e2d; border: 1px solid var(--border-glass); color: #fff; padding: 6px 12px; border-radius: 4px;">
                        <option value="Active" <?= $dbStatus === 'Active' ? 'selected' : '' ?>>Active Only</option>
                        <option value="Completed" <?= $dbStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="All" <?= $dbStatus === 'All' ? 'selected' : '' ?>>All Projects</option>
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

    <div class="dashboard-wrapper" style="width: 100%;">
        <table class="main-table">
            <thead>
                <tr>
                    <th class="col-proj" style="text-align: left; padding-bottom: 10px; color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Project & Services</th>
                    <th class="col-supply" style="padding-bottom: 10px; color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">Supply</th>
                    <th class="col-arms" style="text-align: left; padding-bottom: 10px; color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase;">ARMS Applications (Live Cards)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $project): 
                    $pId = $project['id'];
                    $mob = $mobData[$pId] ?? [];
                    $srv = $srvData[$pId] ?? [];
                    $meters = $armsData[$pId] ?? [];
                ?>
                <tr id="project-<?= $pId ?>">
                    <td>
                        <div style="font-weight: 800; color: var(--text-primary); margin-bottom: 4px; font-size: 1.05rem;"><?= htmlspecialchars($project['name']) ?></div>
                        <div style="font-size: 0.8rem; color: #0ea5e9; margin-bottom: 12px; font-weight: 600;">📍 <?= htmlspecialchars($project['city']) ?></div>
                        
                        <div style="background: rgba(0,0,0,0.2); padding: 10px; border-radius: 6px; display: inline-block; border: 1px solid rgba(255,255,255,0.05); width: 100%; box-sizing: border-box;">
                            <div style="font-size: 0.65rem; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px; letter-spacing: 0.5px; font-weight: bold;">Required Matrix</div>
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
                                         title="<?= $label ?>: <?= getSrvLabel($state) ?>"
                                         onclick="cycleService(this)">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </td>

                    <td style="vertical-align: top; padding-top: 1.25rem;">
                        <?php 
                        $tW = $mob['temporary_water'] ?? 'Not Started';
                        $tE = $mob['temporary_electricity'] ?? 'Not Started';
                        ?>
                        <div class="temp-btn" style="margin-bottom: 8px;" onclick="cycleTemp('temporary_water', <?= $pId ?>, this)" data-state="<?= $tW ?>" title="Water - Currently: <?= $tW ?>">
                            <span style="pointer-events: none;">💧</span>
                            <div class="temp-indicator" style="background: <?= getTempColor($tW) ?>; pointer-events: none;"></div>
                        </div>
                        <div class="temp-btn" onclick="cycleTemp('temporary_electricity', <?= $pId ?>, this)" data-state="<?= $tE ?>" title="Electricity - Currently: <?= $tE ?>">
                            <span style="pointer-events: none;">⚡</span>
                            <div class="temp-indicator" style="background: <?= getTempColor($tE) ?>; pointer-events: none;"></div>
                        </div>
                    </td>

                    <td style="padding-top: 1.25rem; padding-left: 1rem;">
                        <div class="arms-wrapper">
                            <?php if (empty($meters)): ?>
                                <div style="color: var(--text-muted); font-size: 0.8rem; padding: 8px; font-style: italic;">No ARMS applications tracked.</div>
                            <?php else: ?>
                                <?php foreach ($meters as $m): 
                                    $mId = $m['id'];
                                    // Calculate status color class
                                    $sClass = 's-applied';
                                    if($m['status'] == 'Processing') $sClass = 's-processing';
                                    if($m['status'] == 'Installed') $sClass = 's-installed';
                                    if($m['status'] == 'Removed') $sClass = 's-removed';
                                    if($m['status'] == 'Closed') $sClass = 's-closed';
                                ?>
                                    <div class="meter-card" id="meter-row-<?= $mId ?>">
                                        <div class="meter-col" style="flex: 0.8;">
                                            <div class="input-group">
                                                <label>Type</label>
                                                <select class="live-select type-select" onchange="saveArms(<?= $mId ?>, 'meter_type', this.value)">
                                                    <option value="Temporary" <?= $m['meter_type']=='Temporary'?'selected':'' ?>>Temp</option>
                                                    <option value="Permanent" <?= $m['meter_type']=='Permanent'?'selected':'' ?>>Perm</option>
                                                </select>
                                            </div>
                                            <div class="input-group">
                                                <label>Status</label>
                                                <select class="live-select status-select <?= $sClass ?>" onchange="updateStatusColor(this); saveArms(<?= $mId ?>, 'status', this.value)">
                                                    <option value="Applied" <?= $m['status']=='Applied'?'selected':'' ?>>Applied</option>
                                                    <option value="Processing" <?= $m['status']=='Processing'?'selected':'' ?>>Processing</option>
                                                    <option value="Installed" <?= $m['status']=='Installed'?'selected':'' ?>>Installed</option>
                                                    <option value="Removed" <?= $m['status']=='Removed'?'selected':'' ?>>Removed</option>
                                                    <option value="Closed" <?= $m['status']=='Closed'?'selected':'' ?>>Closed</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="meter-col">
                                            <div class="input-group"><label>Account No.</label><input type="text" class="live-input" value="<?= htmlspecialchars($m['account_no'] ?? '') ?>" onblur="saveArms(<?= $mId ?>, 'account_no', this.value)"></div>
                                            <div class="input-group"><label>Elec Meter No.</label><input type="text" class="live-input" value="<?= htmlspecialchars($m['meter_no_elec'] ?? '') ?>" onblur="saveArms(<?= $mId ?>, 'meter_no_elec', this.value)"></div>
                                        </div>

                                        <div class="meter-col">
                                            <div class="input-group"><label>Water Meter No.</label><input type="text" class="live-input" value="<?= htmlspecialchars($m['meter_no_water'] ?? '') ?>" onblur="saveArms(<?= $mId ?>, 'meter_no_water', this.value)"></div>
                                            <div class="input-group"><label>Electrician</label><input type="text" class="live-input" value="<?= htmlspecialchars($m['electrician'] ?? '') ?>" onblur="saveArms(<?= $mId ?>, 'electrician', this.value)"></div>
                                        </div>

                                        <div class="meter-col">
                                            <div class="input-group"><label>Applicant</label><input type="text" class="live-input" value="<?= htmlspecialchars($m['applicant'] ?? '') ?>" onblur="saveArms(<?= $mId ?>, 'applicant', this.value)"></div>
                                            <div class="input-group"><label>Expiry Date</label><input type="date" class="live-input" value="<?= $m['exp_date'] ?? '' ?>" onblur="saveArms(<?= $mId ?>, 'exp_date', this.value)"></div>
                                        </div>

                                        <div class="meter-col col-notes">
                                            <div class="input-group" style="height: 100%;">
                                                <label>Notes</label>
                                                <textarea class="live-input" style="height: 100%; resize: none; min-height: 50px;" onblur="saveArms(<?= $mId ?>, 'notes', this.value)"><?= htmlspecialchars($m['notes'] ?? '') ?></textarea>
                                            </div>
                                        </div>
                                        <div class="meter-col col-action">
                                            <button type="button" class="btn-del" onclick="deleteMeter(<?= $mId ?>)" title="Delete Meter">🗑️</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <button type="button" class="btn-add-meter" onclick="addMeter(<?= $pId ?>)">+ Add Meter Application</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="toast">Saved Successfully</div>

<script>
// --- Matrix Logic ---
const states = ['not_required', 'pending', 'complete'];
const colors = { 'not_required': 'rgba(255,255,255,0.1)', 'pending': '#f59e0b', 'complete': '#22c55e' };
const labels = { 'not_required': 'Not Required', 'pending': 'Pending', 'complete': 'Complete' };

async function cycleService(el) {
    let cur = el.getAttribute('data-state');
    let idx = states.indexOf(cur);
    let next = states[(idx + 1) % states.length];
    
    el.setAttribute('data-state', next);
    el.style.background = colors[next];
    el.title = el.getAttribute('data-name') + ': ' + labels[next];

    const formData = new URLSearchParams();
    formData.append('ajax_action', 'update_service');
    formData.append('project_id', el.getAttribute('data-pid'));
    formData.append('service_key', el.getAttribute('data-srv'));
    formData.append('state', next);

    try {
        const response = await fetch('engineering.php', { method: 'POST', body: formData.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        const data = await response.json();
        if(data.success) showToast('Matrix Updated');
    } catch(e) { alert('Save failed.'); }
}

// --- Temp Logic ---
const tempStates = ['Not Started', 'In Process', 'Connected', 'Closed'];
const tempColors = { 'Not Started':'#ef4444', 'In Process':'#f59e0b', 'Connected':'#22c55e', 'Closed':'#9ca3af' };

async function cycleTemp(field, pid, el) {
    let cur = el.getAttribute('data-state');
    let idx = tempStates.indexOf(cur);
    let next = tempStates[(idx + 1) % tempStates.length];
    
    el.getAttribute('data-state', next);
    el.setAttribute('data-state', next); // Update attribute correctly
    el.querySelector('.temp-indicator').style.background = tempColors[next];
    
    let typeName = field === 'temporary_water' ? 'Water' : 'Electricity';
    el.title = typeName + ' - Currently: ' + next;

    const formData = new URLSearchParams();
    formData.append('ajax_action', 'update_temp');
    formData.append('project_id', pid);
    formData.append('field', field);
    formData.append('value', next);

    try {
        const response = await fetch('engineering.php', { method: 'POST', body: formData.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        const data = await response.json();
        if(data.success) showToast(typeName + ' Updated');
    } catch(e) { alert('Save failed.'); }
}

// --- ARMS Logic ---
function updateStatusColor(selectEl) {
    selectEl.classList.remove('s-applied', 's-processing', 's-installed', 's-removed', 's-closed');
    const v = selectEl.value;
    if(v === 'Applied') selectEl.classList.add('s-applied');
    if(v === 'Processing') selectEl.classList.add('s-processing');
    if(v === 'Installed') selectEl.classList.add('s-installed');
    if(v === 'Removed') selectEl.classList.add('s-removed');
    if(v === 'Closed') selectEl.classList.add('s-closed');
}

async function saveArms(meterId, field, value) {
    const formData = new URLSearchParams();
    formData.append('ajax_action', 'update_arms');
    formData.append('meter_id', meterId);
    formData.append('field', field);
    formData.append('value', value);

    try {
        const response = await fetch('engineering.php', { method: 'POST', body: formData.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        const data = await response.json();
        if (data.success) { showToast('💾 Saved'); } else { alert(data.error); }
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
    setTimeout(function(){ toast.className = toast.className.replace("show", ""); }, 2500);
}
</script>

<?php require_once 'footer.php'; ?>
