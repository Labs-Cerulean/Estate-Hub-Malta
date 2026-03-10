<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('view_projects') && !hasPermission('view_all_projects') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$canEdit = hasPermission('edit_project_details') || isAdmin();

// ==========================================
// AJAX LIVE EDITING ENDPOINTS (Saves instantly)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    if (!$canEdit) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    try {
        $pid = (int)$_POST['project_id'];
        $field = $_POST['field'];
        $val = $_POST['value'] !== '' ? $_POST['value'] : null;

        $allowed = ['project_status', 'completion_target_date', 'actual_completion_date'];
        if (in_array($field, $allowed)) {
            $stmt = $pdo->prepare("UPDATE projects SET $field = ? WHERE id = ?");
            $stmt->execute([$val, $pid]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid field']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// TRADITIONAL POST HANDLERS (Create, Edit, Assign, Delete)
// ==========================================

// 1. Create New Project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
    if (!hasPermission('create_project') && !isAdmin()) {
        $error = "You do not have permission to create projects.";
    } else {
        $name = trim($_POST['name']);
        $type = $_POST['type'];
        $clientid = !empty($_POST['clientid']) ? $_POST['clientid'] : null;
        $rooms = !empty($_POST['rooms']) ? $_POST['rooms'] : null;
        $gross_area = !empty($_POST['gross_area']) ? $_POST['gross_area'] : null;
        $city = trim($_POST['city']);

        if (empty($name) || empty($type)) {
            $error = "Name and Type are required.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO projects (name, type, clientid, rooms, gross_area, city) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $type, $clientid, $rooms, $gross_area, $city]);
                
                $newProjectId = $pdo->lastInsertId();
                
                // Initialize modules if 3rd party
                if ($type === '3rd-party') {
                    $pdo->prepare("INSERT INTO project_capital_financials (project_id) VALUES (?)")->execute([$newProjectId]);
                    $pdo->prepare("INSERT INTO project_services (project_id) VALUES (?)")->execute([$newProjectId]);
                    $pdo->prepare("INSERT INTO project_mobilisation (project_id) VALUES (?)")->execute([$newProjectId]);
                }
                $success = "Project created successfully.";
            } catch (PDOException $e) {
                $error = "Error creating project: " . $e->getMessage();
            }
        }
    }
}

// 2. Edit Project Details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project'])) {
    if (!$canEdit) {
        $error = "Unauthorized.";
    } else {
        $pid = (int)$_POST['project_id'];
        $name = trim($_POST['name']);
        $type = $_POST['type'];
        $clientid = !empty($_POST['clientid']) ? $_POST['clientid'] : null;
        $rooms = !empty($_POST['rooms']) ? $_POST['rooms'] : null;
        $gross_area = !empty($_POST['gross_area']) ? $_POST['gross_area'] : null;
        $city = trim($_POST['city']);

        try {
            $stmt = $pdo->prepare("UPDATE projects SET name=?, type=?, clientid=?, rooms=?, gross_area=?, city=? WHERE id=?");
            $stmt->execute([$name, $type, $clientid, $rooms, $gross_area, $city, $pid]);
            $success = "Project details updated successfully.";
        } catch (PDOException $e) {
            $error = "Error updating project: " . $e->getMessage();
        }
    }
}

// 3. Assign Team
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_team'])) {
    if (!$canEdit) {
        $error = "Unauthorized.";
    } else {
        $projectId = (int)$_POST['project_id'];
        $assignedUsers = $_POST['users'] ?? []; // Array of user IDs
        
        try {
            $pdo->beginTransaction();
            // Clear existing team
            $stmt = $pdo->prepare("DELETE FROM project_users WHERE project_id = ?");
            $stmt->execute([$projectId]);
            
            // Insert new selections
            if (!empty($assignedUsers)) {
                $stmt = $pdo->prepare("INSERT INTO project_users (project_id, user_id) VALUES (?, ?)");
                foreach ($assignedUsers as $uid) {
                    $stmt->execute([$projectId, $uid]);
                }
            }
            $pdo->commit();
            $success = "Team updated successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error assigning team: " . $e->getMessage();
        }
    }
}

// 4. Delete Project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
    if (!isAdmin()) {
        $error = "Only Administrators can delete projects.";
    } else {
        $pid = (int)$_POST['project_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->execute([$pid]);
            $success = "Project deleted successfully.";
        } catch (PDOException $e) {
            $error = "Cannot delete project: Ensure all financial logs and tasks linked to it are removed first.";
        }
    }
}

// ==========================================
// DATA FETCHING & FILTER LOGIC
// ==========================================
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'Active'; // Default to active
$typeFilter = $_GET['type'] ?? '';
$clientFilter = $_GET['client'] ?? '';

// Build query
$where = ["1=1"];
$params = [];

if (!isAdmin() && !hasPermission('view_all_projects')) {
    // Only show projects assigned to the user
    $where[] = "p.id IN (SELECT project_id FROM project_users WHERE user_id = ?)";
    $params[] = getCurrentUserId();
}

if ($search) {
    $where[] = "(p.name LIKE ? OR p.city LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($statusFilter && $statusFilter !== 'All') {
    $where[] = "p.project_status = ?";
    $params[] = $statusFilter;
}

if ($typeFilter) {
    $where[] = "p.type = ?";
    $params[] = $typeFilter;
}

if ($clientFilter) {
    $where[] = "p.clientid = ?";
    $params[] = $clientFilter;
}

$whereSql = implode(' AND ', $where);

// Fetch Main Projects List
$query = "
    SELECT p.*, c.name AS client_name,
    (SELECT COUNT(*) FROM project_users pu WHERE pu.project_id = p.id) as user_count
    FROM projects p
    LEFT JOIN clients c ON p.clientid = c.id
    WHERE $whereSql
    ORDER BY p.name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Pre-Fetch Data for Modals
$clientsStmt = $pdo->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clientsStmt->fetchAll();

// Fetch all active users for the Team Assignment Modal
$usersStmt = $pdo->query("SELECT id, username, first_name, last_name, role FROM users WHERE status = 'active' ORDER BY first_name ASC");
$allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Map exactly who is assigned to which project for quick JS loading
$puStmt = $pdo->query("SELECT project_id, user_id FROM project_users");
$projectUsersMap = [];
while ($row = $puStmt->fetch(PDO::FETCH_ASSOC)) {
    $projectUsersMap[$row['project_id']][] = $row['user_id'];
}

$pageTitle = 'Master Project List';
require_once 'header.php';
?>

<style>
/* Base Styles */
.currency { font-variant-numeric: tabular-nums; }
.badge { padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.7rem; font-weight: 600; display: inline-block; text-transform: uppercase; letter-spacing: 0.5px; }
.badge-green { background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); }
.badge-yellow { background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
.badge-red { background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
.badge-blue { background: rgba(59, 130, 246, 0.1); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3); }
.badge-gray { background: rgba(107, 114, 128, 0.1); color: #9ca3af; border: 1px solid rgba(107, 114, 128, 0.3); }

/* Stacked Table Layout */
.stacked-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; background: var(--bg-card); border-radius: var(--radius-md); border: 1px solid var(--border-glass); }
.stacked-table th { background: #1e1e2d; padding: 1rem; color: var(--text-muted); font-weight: 600; text-align: left; text-transform: uppercase; font-size: 0.75rem; border-bottom: 2px solid var(--border-glass); }
.stacked-table td { padding: 1rem; border-bottom: 1px solid var(--border-glass); vertical-align: top; color: var(--text-secondary); position: relative; }
.stacked-table tr:hover td { background: rgba(255,255,255,0.05); z-index: 50; }

.cell-stack { display: flex; flex-direction: column; gap: 6px; }
.micro-lbl { font-size: 0.65rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; width: 60px; display: inline-block; }
.val-txt { font-size: 0.85rem; font-weight: 600; color: #fff; display: flex; align-items: center; gap: 8px; }
.val-sub { color: var(--text-muted); font-weight: normal; flex: 1; }

/* Live Inputs */
.live-input-bare { background: transparent; border: 1px dashed transparent; color: #fff; font-family: inherit; font-size: inherit; font-weight: inherit; padding: 2px 4px; border-radius: 4px; transition: 0.2s; outline: none; cursor: pointer; }
.live-input-bare:hover:not(:disabled) { border-color: rgba(255,255,255,0.3); background: rgba(0,0,0,0.2); }
.live-input-bare:focus { border-color: var(--primary-color); background: #1e1e2d; cursor: text; }
.live-input-bare:disabled { cursor: default; }
input[type="date"].live-input-bare::-webkit-calendar-picker-indicator { cursor: pointer; filter: invert(1); opacity: 0.5; }

.badge-select { appearance: none; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; cursor: pointer; text-align: center; outline: none; border: 1px solid; transition: 0.2s; }
.badge-select option { background: #1e1e2d; color: #fff; text-transform: none; font-weight: normal; }

/* Modals */
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }

/* Action Hub Grid */
.hub-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1.5rem; }
.hub-card { background: rgba(0,0,0,0.2); border: 1px solid var(--border-glass); border-radius: 8px; padding: 1.5rem 1rem; text-align: center; text-decoration: none; color: #fff; transition: 0.2s; display: flex; flex-direction: column; align-items: center; gap: 10px; }
.hub-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.3); border-color: var(--primary-color); background: rgba(255,255,255,0.05); }
.hub-icon { font-size: 2rem; }
.hub-title { font-weight: 800; font-size: 0.9rem; letter-spacing: 0.5px; }

#toast { visibility: hidden; min-width: 250px; background-color: #22c55e; color: #fff; text-align: center; border-radius: 4px; padding: 12px; position: fixed; z-index: 9999; left: 50%; bottom: 30px; transform: translateX(-50%); font-weight: bold; box-shadow: 0px 4px 10px rgba(0,0,0,0.5); opacity: 0; transition: opacity 0.3s, visibility 0.3s; }
#toast.show { visibility: visible; opacity: 1; }
</style>

<div class="main-container" style="max-width: 100%; padding: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;">Master Project Control</h1>
            <p style="color: var(--text-secondary); margin-top: 0.25rem;">Click any project name to open the Action Hub.</p>
        </div>
        <?php if (hasPermission('create_project') || isAdmin()): ?>
            <button onclick="document.getElementById('createModal').style.display='block'" class="btn btn-primary">➕ New Project</button>
        <?php endif; ?>
    </div>

    <?php if (isset($success)): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (isset($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="filters-section" style="margin-bottom: 1.5rem; background: rgba(0,0,0,0.15); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-glass);">
        <form method="GET" class="filters-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; align-items: end;">
            <div class="form-group" style="margin: 0;">
                <label style="font-size: 0.75rem; color: var(--text-muted);">Search</label>
                <input type="text" name="search" placeholder="Name or City..." value="<?= htmlspecialchars($search) ?>" style="width: 100%; padding: 0.5rem; border-radius: 4px; background: #1e1e2d; color: #fff; border: 1px solid var(--border-glass);">
            </div>
            <div class="form-group" style="margin: 0;">
                <label style="font-size: 0.75rem; color: var(--text-muted);">Status</label>
                <select name="status" style="width: 100%; padding: 0.5rem; border-radius: 4px; background: #1e1e2d; color: #fff; border: 1px solid var(--border-glass);">
                    <option value="All" <?= $statusFilter == 'All' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="Active" <?= $statusFilter == 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Completed" <?= $statusFilter == 'Completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="On Hold" <?= $statusFilter == 'On Hold' ? 'selected' : '' ?>>On Hold</option>
                    <option value="Cancelled" <?= $statusFilter == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="form-group" style="margin: 0;">
                <label style="font-size: 0.75rem; color: var(--text-muted);">Type</label>
                <select name="type" style="width: 100%; padding: 0.5rem; border-radius: 4px; background: #1e1e2d; color: #fff; border: 1px solid var(--border-glass);">
                    <option value="">All Types</option>
                    <option value="Internal" <?= $typeFilter == 'Internal' ? 'selected' : '' ?>>Internal</option>
                    <option value="3rd-party" <?= $typeFilter == '3rd-party' ? 'selected' : '' ?>>3rd Party</option>
                </select>
            </div>
            <div class="form-group" style="margin: 0;">
                <label style="font-size: 0.75rem; color: var(--text-muted);">Client</label>
                <select name="client" style="width: 100%; padding: 0.5rem; border-radius: 4px; background: #1e1e2d; color: #fff; border: 1px solid var(--border-glass);">
                    <option value="">All Clients</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $clientFilter == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin: 0;">
                <button type="submit" class="btn btn-secondary" style="width: 100%; margin: 0; padding: 0.5rem;">Search</button>
            </div>
        </form>
    </div>

    <table class="stacked-table">
        <thead>
            <tr>
                <th style="width: 30%;">Project Info</th>
                <th style="width: 25%;">Timelines</th>
                <th style="width: 20%;">Current Status</th>
                <th style="width: 25%; text-align: center;">Team & Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($projects)): ?>
                <tr><td colspan="4" style="text-align: center; padding: 3rem; color: var(--text-muted);">No projects found matching your criteria.</td></tr>
            <?php else: ?>
                <?php foreach ($projects as $p): 
                    // Calculate Status Color
                    $sClass = 'badge-gray'; $sHex = '#9ca3af';
                    if ($p['project_status'] == 'Active') { $sClass = 'badge-green'; $sHex = '#22c55e'; }
                    if ($p['project_status'] == 'On Hold') { $sClass = 'badge-yellow'; $sHex = '#f59e0b'; }
                    if ($p['project_status'] == 'Completed') { $sClass = 'badge-blue'; $sHex = '#3b82f6'; }
                    if ($p['project_status'] == 'Cancelled') { $sClass = 'badge-red'; $sHex = '#ef4444'; }
                    
                    // Package project data safely for JS
                    $assignedArray = $projectUsersMap[$p['id']] ?? [];
                    $pJson = json_encode([
                        "id" => $p['id'],
                        "name" => $p['name'],
                        "type" => $p['type'],
                        "clientid" => $p['clientid'],
                        "city" => $p['city'],
                        "rooms" => $p['rooms'],
                        "gross_area" => $p['gross_area'],
                        "assignedUsers" => $assignedArray
                    ], JSON_HEX_APOS); 
                ?>
                <tr class="main-row">
                    <td>
                        <div class="cell-stack">
                            <div style="font-weight: 800; font-size: 1.15rem; color: var(--primary-color); cursor: pointer; text-decoration: underline; text-decoration-style: dotted; text-underline-offset: 4px;" 
                                 onclick='openActionHub(<?= $pJson ?>)'>
                                <?= htmlspecialchars($p['name']) ?>
                            </div>
                            <div class="val-txt"><span class="micro-lbl">Type:</span> <span class="val-sub"><?= htmlspecialchars($p['type']) ?></span></div>
                            <div class="val-txt"><span class="micro-lbl">Client:</span> <span class="val-sub"><?= htmlspecialchars($p['client_name'] ?? 'Internal') ?></span></div>
                            <div class="val-txt"><span class="micro-lbl">City:</span> <span class="val-sub" style="color: #0ea5e9;">📍 <?= htmlspecialchars($p['city'] ?? 'N/A') ?></span></div>
                            <div class="val-txt"><span class="micro-lbl">Units:</span> <span class="val-sub">
                                <?= $p['rooms'] ? htmlspecialchars($p['rooms']) . ' Rooms' : '-' ?> 
                                <?= $p['gross_area'] ? '| ' . htmlspecialchars($p['gross_area']) . ' sqm' : '' ?>
                            </span></div>
                        </div>
                    </td>

                    <td style="border-left: 1px solid var(--border-glass);">
                        <div class="cell-stack">
                            <div class="val-txt" style="align-items: center;">
                                <span class="micro-lbl">Target:</span> 
                                <input type="date" class="live-input-bare val-sub" style="flex:none; width:130px; font-weight:bold; color:#f59e0b;" 
                                       value="<?= htmlspecialchars($p['completion_target_date'] ?? '') ?>" 
                                       onchange="quickUpdate(<?= $p['id'] ?>, 'completion_target_date', this.value)" <?= $canEdit ? '' : 'disabled' ?>>
                            </div>
                            <div class="val-txt" style="align-items: center; margin-top: 4px;">
                                <span class="micro-lbl">Actual:</span> 
                                <input type="date" class="live-input-bare val-sub" style="flex:none; width:130px; font-weight:bold; color:#10B981;" 
                                       value="<?= htmlspecialchars($p['actual_completion_date'] ?? '') ?>" 
                                       onchange="quickUpdate(<?= $p['id'] ?>, 'actual_completion_date', this.value)" <?= $canEdit ? '' : 'disabled' ?>>
                            </div>
                        </div>
                    </td>

                    <td style="border-left: 1px solid var(--border-glass);">
                        <div class="cell-stack">
                            <div class="val-txt">
                                <?php if ($canEdit): ?>
                                    <select class="badge-select" style="background: <?= $sHex ?>15; color: <?= $sHex ?>; border-color: <?= $sHex ?>50; width: 120px;" 
                                            onchange="quickUpdate(<?= $p['id'] ?>, 'project_status', this.value); updateSelectColor(this);">
                                        <option value="Active" <?= $p['project_status'] == 'Active' ? 'selected' : '' ?>>🟢 Active</option>
                                        <option value="On Hold" <?= $p['project_status'] == 'On Hold' ? 'selected' : '' ?>>🟡 On Hold</option>
                                        <option value="Completed" <?= $p['project_status'] == 'Completed' ? 'selected' : '' ?>>🔵 Completed</option>
                                        <option value="Cancelled" <?= $p['project_status'] == 'Cancelled' ? 'selected' : '' ?>>🔴 Cancelled</option>
                                    </select>
                                <?php else: ?>
                                    <span class="badge <?= $sClass ?>"><?= htmlspecialchars($p['project_status']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 8px;">
                                Project ID: <span style="font-family: monospace; font-weight: bold; color: #fff;">#<?= $p['id'] ?></span>
                            </div>
                        </div>
                    </td>

                    <td style="border-left: 1px solid var(--border-glass); text-align: center; vertical-align: middle;">
                        <div style="margin-bottom: 12px;">
                            <span class="badge badge-gray" style="border-radius: 20px; padding: 4px 10px;">
                                👥 <?= $p['user_count'] ?> Team Members
                            </span>
                        </div>
                        <button class="btn btn-sm btn-primary" style="margin: 0; padding: 0.6rem 1rem; width: 80%; border-radius: 6px;" 
                                onclick='openActionHub(<?= $pJson ?>)'>
                            Open Dashboard 🚀
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="actionHubModal" class="modal">
    <div class="modal-content" style="max-width: 700px; padding: 2.5rem;">
        <span class="close-modal" onclick="closeModal('actionHubModal')">&times;</span>
        <h2 id="hubTitle" style="margin-top: 0; margin-bottom: 0.5rem; color: var(--primary-color); font-size: 1.8rem;">Project Name</h2>
        <p style="color: var(--text-muted); margin-top: 0; margin-bottom: 2rem;">Select a dashboard to manage this project.</p>
        
        <div class="hub-grid">
            <a id="hubLinkExecution" href="#" class="hub-card">
                <span class="hub-icon">🏗️</span>
                <span class="hub-title">Execution & Tasks</span>
            </a>
            <a id="hubLinkEngineering" href="#" class="hub-card">
                <span class="hub-icon">🔌</span>
                <span class="hub-title">Engineering & ARMS</span>
            </a>
            <a id="hubLinkFinancials" href="#" class="hub-card">
                <span class="hub-icon">💶</span>
                <span class="hub-title">Capital & Financials</span>
            </a>
            <a id="hubLinkSettings" href="#" class="hub-card">
                <span class="hub-icon">📄</span>
                <span class="hub-title">Mobilisation Form</span>
            </a>
        </div>

        <?php if ($canEdit): ?>
        <div style="margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border-glass); display: flex; gap: 10px; flex-wrap: wrap;">
            <button class="btn btn-secondary" style="margin:0; flex:1;" onclick="openTeamModalFromHub()">👥 Assign Team</button>
            <button class="btn btn-secondary" style="margin:0; flex:1;" onclick="openEditModalFromHub()">📝 Edit Details</button>
            
            <?php if (isAdmin()): ?>
            <form method="POST" style="margin:0; flex: 0.5;" onsubmit="return confirm('WARNING: This will permanently delete this project. Continue?');">
                <input type="hidden" name="project_id" id="hubDeleteId" value="">
                <button type="submit" name="delete_project" class="btn" style="background: var(--danger); border-color: var(--danger); color: white; width: 100%; margin:0; padding: 0.6rem;">🗑️ Delete</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div id="teamModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('teamModal')">&times;</span>
        <h2>Assign Team Members</h2>
        <form method="POST">
            <input type="hidden" name="project_id" id="teamProjectId" value="">
            <div style="max-height: 350px; overflow-y: auto; background: #1e1e2d; padding: 1rem; border-radius: 6px; border: 1px solid var(--border-glass); margin-bottom: 1rem;">
                <?php foreach ($allUsers as $u): ?>
                    <label style="display: flex; align-items: center; gap: 10px; padding: 8px; border-bottom: 1px solid rgba(255,255,255,0.05); cursor: pointer;">
                        <input type="checkbox" name="users[]" value="<?= $u['id'] ?>" class="user-checkbox" data-uid="<?= $u['id'] ?>" style="cursor: pointer; width: 16px; height: 16px;">
                        <span style="color: #fff; font-weight: bold; flex: 1;"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></span>
                        <span class="badge badge-gray" style="font-size: 0.6rem;"><?= htmlspecialchars($u['role']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="update_team" class="btn btn-primary" style="width: 100%;">Save Assigned Team</button>
        </form>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
        <h2>Edit Project Details</h2>
        <form method="POST">
            <input type="hidden" name="project_id" id="editProjectId" value="">
            <div class="form-group">
                <label>Project Name *</label>
                <input type="text" name="name" id="editName" required>
            </div>
            <div class="form-group">
                <label>Project Type *</label>
                <select name="type" id="editType" required>
                    <option value="Internal">Internal</option>
                    <option value="3rd-party">3rd Party</option>
                </select>
            </div>
            <div class="form-group">
                <label>Client</label>
                <select name="clientid" id="editClient">
                    <option value="">None (Internal)</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>City / Location</label>
                <input type="text" name="city" id="editCity">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>Number of Rooms</label>
                    <input type="number" name="rooms" id="editRooms">
                </div>
                <div class="form-group">
                    <label>Gross Area (sqm)</label>
                    <input type="number" step="0.01" name="gross_area" id="editGrossArea">
                </div>
            </div>
            <button type="submit" name="update_project" class="btn btn-primary" style="width: 100%;">Save Changes</button>
        </form>
    </div>
</div>

<div id="createModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('createModal')">&times;</span>
        <h2>Create New Project</h2>
        <form method="POST">
            <div class="form-group">
                <label>Project Name *</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-group">
                <label>Project Type *</label>
                <select name="type" required>
                    <option value="Internal">Internal</option>
                    <option value="3rd-party">3rd Party</option>
                </select>
            </div>
            <div class="form-group">
                <label>Client</label>
                <select name="clientid">
                    <option value="">None (Internal)</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>City / Location</label>
                <input type="text" name="city">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>Number of Rooms</label>
                    <input type="number" name="rooms">
                </div>
                <div class="form-group">
                    <label>Gross Area (sqm)</label>
                    <input type="number" step="0.01" name="gross_area">
                </div>
            </div>
            <button type="submit" name="create_project" class="btn btn-primary" style="width: 100%;">Create Project</button>
        </form>
    </div>
</div>

<div id="toast">✅ Saved Successfully</div>

<script>
// --- AJAX Quick Edit Function ---
async function quickUpdate(projectId, field, value) {
    const formData = new URLSearchParams();
    formData.append('ajax_action', 'quick_update');
    formData.append('project_id', projectId);
    formData.append('field', field);
    formData.append('value', value);

    try {
        const response = await fetch('projects.php', { method: 'POST', body: formData.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        const data = await response.json();
        if (data.success) { 
            showToast('✅ Updated Successfully'); 
        } else { 
            alert('Error: ' + data.error); 
        }
    } catch (e) { alert('Connection error while saving.'); }
}

// --- Status Badge Color Updater ---
function updateSelectColor(select) {
    const val = select.value;
    let hex = '#9ca3af'; // gray
    if (val === 'Active') hex = '#22c55e'; // green
    if (val === 'On Hold') hex = '#f59e0b'; // yellow
    if (val === 'Completed') hex = '#3b82f6'; // blue
    if (val === 'Cancelled') hex = '#ef4444'; // red
    
    select.style.color = hex;
    select.style.borderColor = hex + '50';
    select.style.background = hex + '15';
}

// --- Action Hub & Sub-Modal Logic ---
let currentHubProject = null;

function openActionHub(project) {
    currentHubProject = project;
    document.getElementById('hubTitle').innerText = project.name;
    
    // Link Dashboards
    document.getElementById('hubLinkExecution').href = 'execution.php?project_id=' + project.id;
    document.getElementById('hubLinkEngineering').href = 'engineering.php?filter_project=' + project.id;
    document.getElementById('hubLinkSettings').href = 'project_details.php?id=' + project.id; // Formally settings, points to mobilisation
    
    let finLink = document.getElementById('hubLinkFinancials');
    if (project.type === '3rd-party') {
        finLink.href = 'capital_projects.php?project_id=' + project.id;
        finLink.style.display = 'flex';
    } else {
        finLink.style.display = 'none';
    }

    if (document.getElementById('hubDeleteId')) {
        document.getElementById('hubDeleteId').value = project.id;
    }

    document.getElementById('actionHubModal').style.display = 'block';
}

// Open Team Modal FROM inside the Action Hub
function openTeamModalFromHub() {
    closeModal('actionHubModal'); // Close the hub
    
    document.getElementById('teamProjectId').value = currentHubProject.id;
    
    // Reset Checkboxes
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
    
    // Check currently assigned users
    if (currentHubProject.assignedUsers) {
        currentHubProject.assignedUsers.forEach(uid => {
            let cb = document.querySelector(`.user-checkbox[data-uid="${uid}"]`);
            if (cb) cb.checked = true;
        });
    }
    
    document.getElementById('teamModal').style.display = 'block';
}

// Open Edit Modal FROM inside the Action Hub
function openEditModalFromHub() {
    closeModal('actionHubModal'); // Close the hub
    
    document.getElementById('editProjectId').value = currentHubProject.id;
    document.getElementById('editName').value = currentHubProject.name;
    document.getElementById('editType').value = currentHubProject.type;
    document.getElementById('editClient').value = currentHubProject.clientid || '';
    document.getElementById('editCity').value = currentHubProject.city || '';
    document.getElementById('editRooms').value = currentHubProject.rooms || '';
    document.getElementById('editGrossArea').value = currentHubProject.gross_area || '';
    
    document.getElementById('editModal').style.display = 'block';
}

// --- Modal Utilities ---
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.onclick = function(event) { if (event.target.classList.contains('modal')) event.target.style.display = "none"; }

function showToast(msg) {
    const toast = document.getElementById("toast");
    toast.innerText = msg;
    toast.className = "show";
    setTimeout(function(){ toast.className = toast.className.replace("show", ""); }, 2000);
}
</script>

<?php require_once 'footer.php'; ?>
