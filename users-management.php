<?php
require_once 'init.php';
require_once 'session-check.php';

if (!isAdmin()) { header('Location: dashboard.php'); exit; }

$message = ''; $error = '';

// Ensure all users have a capabilities record and support the new Training Docs Access
try { $pdo->exec("ALTER TABLE users ADD COLUMN doc_training TINYINT(1) DEFAULT 0"); } catch (PDOException $e) { }
$pdo->exec("INSERT IGNORE INTO user_capabilities (user_id) SELECT id FROM users");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Core Capabilities + Module Access + Commercial Sales Access + Approval Access
    $caps = [
        'view_tracking' => isset($_POST['view_tracking']) ? 1 : 0,
        'add_project' => isset($_POST['add_project']) ? 1 : 0,
        'edit_project_details' => isset($_POST['edit_project_details']) ? 1 : 0,
        'update_project_status' => isset($_POST['update_project_status']) ? 1 : 0,
        'edit_services' => isset($_POST['edit_services']) ? 1 : 0,
        'assign_actions' => isset($_POST['assign_actions']) ? 1 : 0,
        'manage_clients' => isset($_POST['manage_clients']) ? 1 : 0,
        'manage_professionals' => isset($_POST['manage_professionals']) ? 1 : 0,
        'manage_users' => isset($_POST['manage_users']) ? 1 : 0,
        'manage_subcontractors' => isset($_POST['manage_subcontractors']) ? 1 : 0,
        'view_subcontractor_accounts' => isset($_POST['view_subcontractor_accounts']) ? 1 : 0,
        'manage_subcontractor_accounts' => isset($_POST['manage_subcontractor_accounts']) ? 1 : 0,
        'view_mobilisation' => isset($_POST['view_mobilisation']) ? 1 : 0,
        'view_projects' => isset($_POST['view_projects']) ? 1 : 0,
        'view_ohsa' => isset($_POST['view_ohsa']) ? 1 : 0,
        'view_works_sales' => isset($_POST['view_works_sales']) ? 1 : 0,
        'view_documentation' => isset($_POST['view_documentation']) ? 1 : 0,
        'view_drawings' => isset($_POST['view_drawings']) ? 1 : 0,
        'view_property_sales' => isset($_POST['view_property_sales']) ? 1 : 0,
        'view_capital_projects' => isset($_POST['view_capital_projects']) ? 1 : 0,
        'view_nav_subcontractors' => isset($_POST['view_nav_subcontractors']) ? 1 : 0,
        
        // Commercial Sales Granular Access
        'view_sales_demo_exc' => isset($_POST['view_sales_demo_exc']) ? 1 : 0,
        'manage_sales_demo_exc' => isset($_POST['manage_sales_demo_exc']) ? 1 : 0,
        'view_sales_const' => isset($_POST['view_sales_const']) ? 1 : 0,
        'manage_sales_const' => isset($_POST['manage_sales_const']) ? 1 : 0,
        'view_sales_finishes' => isset($_POST['view_sales_finishes']) ? 1 : 0,
        'manage_sales_finishes' => isset($_POST['manage_sales_finishes']) ? 1 : 0,
        
        // Approval Workflow Bypass
        'approve_quotes' => isset($_POST['approve_quotes']) ? 1 : 0,

        // NEW: Plant Bookings Access
        'view_plant_bookings' => isset($_POST['view_plant_bookings']) ? 1 : 0,
    ];

    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'viewer';
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');

        if (empty($username) || empty($password)) {
            $error = 'Username and password are required';
        } else {
            try {
                $pdo->beginTransaction();
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, ?, 'Yes')");
                $stmt->execute([$first_name, $last_name, $username, $email, $hash, $role]);
                $newId = $pdo->lastInsertId();

                $stmtCaps = $pdo->prepare("
                    INSERT INTO user_capabilities (
                        user_id, view_tracking, add_project, edit_project_details, update_project_status, 
                        edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors,
                        view_subcontractor_accounts, manage_subcontractor_accounts,
                        view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors,
                        view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, approve_quotes,
                        view_plant_bookings
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $params = array_values($caps);
                array_unshift($params, $newId);
                $stmtCaps->execute($params);

                $pdo->commit();
                $message = 'User created successfully! Select them from the list to configure their project access levels.';
            } catch (PDOException $e) { 
                $pdo->rollBack(); 
                $error = 'Error: ' . $e->getMessage(); 
            }
        }
    }
    
    elseif ($action === 'update_user') {
        $userId = $_POST['user_id'];
        $role = $_POST['role'] ?? 'viewer';
        $architectFirmId = !empty($_POST['architect_firm_id']) ? $_POST['architect_firm_id'] : null;
        $structuralFirmId = !empty($_POST['structural_firm_id']) ? $_POST['structural_firm_id'] : null;

        // 4-Tier Document Vault Permissions (Now Including Training)
        $doc_bca = isset($_POST['doc_bca']) ? (int)$_POST['doc_bca'] : 0;
        $doc_ohsa = isset($_POST['doc_ohsa']) ? (int)$_POST['doc_ohsa'] : 0;
        $doc_drawings = isset($_POST['doc_drawings']) ? (int)$_POST['doc_drawings'] : 0;
        $doc_engineering = isset($_POST['doc_engineering']) ? (int)$_POST['doc_engineering'] : 0;
        $doc_commercial = isset($_POST['doc_commercial']) ? (int)$_POST['doc_commercial'] : 0;
        $doc_sales = isset($_POST['doc_sales']) ? (int)$_POST['doc_sales'] : 0;
        $doc_training = isset($_POST['doc_training']) ? (int)$_POST['doc_training'] : 0;

        try {
            $pdo->beginTransaction();
            $stmt1 = $pdo->prepare("UPDATE users SET username=?, email=?, first_name=?, last_name=?, phone=?, role=?, is_active=?, assigned_architect_firm_id=?, assigned_structural_firm_id=?, doc_bca=?, doc_ohsa=?, doc_drawings=?, doc_engineering=?, doc_commercial=?, doc_sales=?, doc_training=? WHERE id=?");
            $stmt1->execute([$_POST['username'], $_POST['email'], $_POST['first_name'], $_POST['last_name'], $_POST['phone'], $role, $_POST['is_active'], $architectFirmId, $structuralFirmId, $doc_bca, $doc_ohsa, $doc_drawings, $doc_engineering, $doc_commercial, $doc_sales, $doc_training, $userId]);
            
            if (!empty($_POST['new_password'])) {
                $pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$pass, $userId]);
            }

            $stmt2 = $pdo->prepare("
                INSERT INTO user_capabilities (
                    user_id, view_tracking, add_project, edit_project_details, update_project_status, 
                    edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors,
                    view_subcontractor_accounts, manage_subcontractor_accounts,
                    view_mobilisation, view_projects, view_ohsa, view_works_sales, view_documentation, view_drawings, view_property_sales, view_capital_projects, view_nav_subcontractors,
                    view_sales_demo_exc, manage_sales_demo_exc, view_sales_const, manage_sales_const, view_sales_finishes, manage_sales_finishes, approve_quotes,
                    view_plant_bookings
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    view_tracking=VALUES(view_tracking), add_project=VALUES(add_project), edit_project_details=VALUES(edit_project_details), 
                    update_project_status=VALUES(update_project_status), edit_services=VALUES(edit_services), assign_actions=VALUES(assign_actions), 
                    manage_clients=VALUES(manage_clients), manage_professionals=VALUES(manage_professionals), manage_users=VALUES(manage_users), 
                    manage_subcontractors=VALUES(manage_subcontractors), 
                    view_subcontractor_accounts=VALUES(view_subcontractor_accounts), manage_subcontractor_accounts=VALUES(manage_subcontractor_accounts),
                    view_mobilisation=VALUES(view_mobilisation), view_projects=VALUES(view_projects), 
                    view_ohsa=VALUES(view_ohsa), view_works_sales=VALUES(view_works_sales), view_documentation=VALUES(view_documentation), 
                    view_drawings=VALUES(view_drawings), view_property_sales=VALUES(view_property_sales), view_capital_projects=VALUES(view_capital_projects),
                    view_nav_subcontractors=VALUES(view_nav_subcontractors),
                    view_sales_demo_exc=VALUES(view_sales_demo_exc), manage_sales_demo_exc=VALUES(manage_sales_demo_exc),
                    view_sales_const=VALUES(view_sales_const), manage_sales_const=VALUES(manage_sales_const),
                    view_sales_finishes=VALUES(view_sales_finishes), manage_sales_finishes=VALUES(manage_sales_finishes),
                    approve_quotes=VALUES(approve_quotes),
                    view_plant_bookings=VALUES(view_plant_bookings)
            ");
            $params = array_values($caps);
            array_unshift($params, $userId);
            $stmt2->execute($params);
            
            $pdo->commit();
            $message = 'User profile & permissions updated successfully!';
        } catch (PDOException $e) { 
            $pdo->rollBack(); 
            $error = 'Update Error: ' . $e->getMessage(); 
        }
    }

    // Access Logic Level 2 & 3
    elseif ($action === 'assign_client') { if ($_POST['user_id'] && $_POST['client_id']) if (assignUserToClient($pdo, $_POST['user_id'], $_POST['client_id'])) $message = 'Assigned!'; }
    elseif ($action === 'assign_all_clients') { if ($_POST['user_id']) { $all = $pdo->query("SELECT id FROM clients")->fetchAll(PDO::FETCH_COLUMN); foreach ($all as $cid) assignUserToClient($pdo, $_POST['user_id'], $cid); $message = "Assigned all clients."; } }
    elseif ($action === 'remove_all_clients') { if ($_POST['user_id']) { $pdo->prepare("DELETE FROM user_client_access WHERE user_id = ?")->execute([$_POST['user_id']]); $message = "Removed all."; } }
    elseif ($action === 'remove_client') { if ($_POST['user_id'] && $_POST['client_id']) { removeUserFromClient($pdo, $_POST['user_id'], $_POST['client_id']); $message = 'Removed.'; } }
    elseif ($action === 'exclude_project') { if ($_POST['user_id'] && $_POST['project_id']) { excludeProjectFromUser($pdo, $_POST['user_id'], $_POST['project_id']); $message = 'Excluded.'; } }
    elseif ($action === 'restore_project') { if ($_POST['user_id'] && $_POST['project_id']) { removeProjectExclusion($pdo, $_POST['user_id'], $_POST['project_id']); $message = 'Restored.'; } }
    elseif ($action === 'assign_project') { if ($_POST['user_id'] && $_POST['project_id']) { assignUserToProject($pdo, $_POST['user_id'], $_POST['project_id']); $message = 'Assigned explicitly.'; } }
    elseif ($action === 'remove_assigned_project') { if ($_POST['user_id'] && $_POST['project_id']) { removeUserFromProject($pdo, $_POST['user_id'], $_POST['project_id']); $message = 'Access removed.'; } }
}

$users = getAllUsers($pdo);
$clients = $pdo->query("SELECT id, name, type FROM clients ORDER BY name")->fetchAll();
$allProjectsDb = $pdo->query("SELECT p.id, p.name, c.name as client_name FROM projects p LEFT JOIN clients c ON p.clientid = c.id ORDER BY p.name ASC")->fetchAll();
$firms = getAllFirms($pdo);
$architectFirms = []; $structuralFirms = [];
foreach ($firms['architects'] as $firmName) { if ($pid = getProfessionalIdByFirm($pdo, $firmName, 'architect')) $architectFirms[$pid] = $firmName; }
foreach ($firms['structural_engineers'] as $firmName) { if ($pid = getProfessionalIdByFirm($pdo, $firmName, 'structural_engineer')) $structuralFirms[$pid] = $firmName; }

$selectedUser = null; $userClients = []; $userExcludedProjects = []; $userAccessibleProjects = []; $userSpecificallyAssignedProjects = [];
if (!empty($_GET['user_id'])) {
    $stmt = $pdo->prepare("SELECT u.*, uc.* FROM users u LEFT JOIN user_capabilities uc ON u.id = uc.user_id WHERE u.id = ?");
    $stmt->execute([$_GET['user_id']]);
    $selectedUser = $stmt->fetch();
    if ($selectedUser) {
        $userClients = getUserClients($pdo, $selectedUser['id']);
        $userExcludedProjects = getUserExcludedProjects($pdo, $selectedUser['id']);
        $userAccessibleProjects = getAccessibleProjects($pdo, $selectedUser['id']);
        $userSpecificallyAssignedProjects = getUserAssignedProjects($pdo, $selectedUser['id']);
    }
}

$rolesList = [
    'admin', 'director', 'system_manager', 'project_manager', 'accountant', 'architect', 'structural_engineer', 
    'services_engineer', 'quality_controller', 'pmo_staff', 'ohsa_rep', 
    'site_technical_officer', 'subcontractor', 'condominium_agent', 
    'sales_manager', 'sales_agent', 'end_customer', 'viewer',
    'plant_manager', 'plant_driver' // NEW ROLES
];

$pageTitle = 'User Management';
require_once 'header.php';
?>

<style>
.custom-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.75); backdrop-filter: blur(4px); }
.custom-modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); position: relative; }
.custom-close-btn { position: absolute; top: 1.5rem; right: 1.5rem; color: var(--text-muted); font-size: 1.5rem; font-weight: bold; cursor: pointer; line-height: 1; }
.custom-close-btn:hover { color: var(--text-primary); }
</style>

<div class="main-container">
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="two-column-layout">
        
        <div class="user-list">
            <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2>All Users</h2>
                <button type="button" onclick="openCreateModal()" class="btn btn-primary btn-sm">+ Add New User</button>
            </div>
            <table class="data-table">
                <thead><tr><th>Username</th><th>User Type</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td style="font-weight: 600;">@<?= htmlspecialchars($u['username']) ?></td>
                        <td><span style="font-size: 0.75rem; text-transform: uppercase;"><?= ucwords(str_replace('_', ' ', $u['role'])) ?></span></td>
                        <td><span style="color: <?= $u['is_active'] === 'Yes' ? '#10B981' : '#EF4444' ?>; font-weight: bold;"><?= $u['is_active'] ?></span></td>
                        <td><a href="?user_id=<?= $u['id'] ?>" class="btn btn-sm btn-secondary">Edit</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="user-details">
            <?php if ($selectedUser): ?>
                <form method="POST" class="form-section">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                    
                    <h3 style="margin-bottom: 1.5rem;">Edit Profile: <?= htmlspecialchars($selectedUser['username']) ?></h3>
                    
                    <div class="form-row">
                        <div class="form-group"><label>Username</label><input type="text" name="username" value="<?= htmlspecialchars($selectedUser['username']) ?>" required></div>
                        <div class="form-group">
                            <label>User Type (Role)</label>
                            <div style="display:flex; gap: 0.5rem;">
                                <select name="role" id="editRole" style="flex: 1;" onchange="toggleAccessSections('edit')">
                                    <?php foreach($rolesList as $r): ?>
                                        <option value="<?= $r ?>" <?= $selectedUser['role'] === $r ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $r)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="applyRoleDefaults('edit')" title="Load defaults">Load Defaults</button>
                            </div>
                        </div>
                    </div>

                    <div style="background: rgba(99,102,241,0.1); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--primary-color); margin-bottom: 1.5rem;">
                        
                        <h4 style="margin-bottom: 1rem; color: var(--primary-color);">Action Permissions</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 1.5rem;">
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_tracking" id="edit_cap_view_tracking" <?= !empty($selectedUser['view_tracking']) ? 'checked' : '' ?>> View Tracking Stage</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="add_project" id="edit_cap_add_project" <?= !empty($selectedUser['add_project']) ? 'checked' : '' ?>> Create New Projects</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="edit_project_details" id="edit_cap_edit_project_details" <?= !empty($selectedUser['edit_project_details']) ? 'checked' : '' ?>> Edit Project Details</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="update_project_status" id="edit_cap_update_project_status" <?= !empty($selectedUser['update_project_status']) ? 'checked' : '' ?>> Execution Checklists</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="edit_services" id="edit_cap_edit_services" <?= !empty($selectedUser['edit_services']) ? 'checked' : '' ?>> Services & Utilities</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="assign_actions" id="edit_cap_assign_actions" <?= !empty($selectedUser['assign_actions']) ? 'checked' : '' ?>> Assign Actions</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="manage_clients" id="edit_cap_manage_clients" <?= !empty($selectedUser['manage_clients']) ? 'checked' : '' ?>> Manage Clients</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="manage_professionals" id="edit_cap_manage_professionals" <?= !empty($selectedUser['manage_professionals']) ? 'checked' : '' ?>> Manage Professionals</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="manage_subcontractors" id="edit_cap_manage_subcontractors" <?= !empty($selectedUser['manage_subcontractors']) ? 'checked' : '' ?>> Manage Subcontractors</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="manage_users" id="edit_cap_manage_users" <?= !empty($selectedUser['manage_users']) ? 'checked' : '' ?>> Manage Users</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_subcontractor_accounts" id="edit_cap_view_subcontractor_accounts" <?= !empty($selectedUser['view_subcontractor_accounts']) ? 'checked' : '' ?>> View Subcon. Accounts</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="manage_subcontractor_accounts" id="edit_cap_manage_subcontractor_accounts" <?= !empty($selectedUser['manage_subcontractor_accounts']) ? 'checked' : '' ?>> Manage Subcon. Accounts</label>
                        </div>

                        <h4 style="margin-bottom: 1rem; color: var(--primary-color);">Work Sales & Commercial Access</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 1.5rem; background: rgba(255,255,255,0.02); padding: 1rem; border-radius: 6px;">
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_sales_demo_exc" id="edit_cap_view_sales_demo_exc" <?= !empty($selectedUser['view_sales_demo_exc']) ? 'checked' : '' ?>> View Demo & Exc Quotes</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="manage_sales_demo_exc" id="edit_cap_manage_sales_demo_exc" <?= !empty($selectedUser['manage_sales_demo_exc']) ? 'checked' : '' ?>> <span style="color: #f59e0b;">Manage</span> Demo & Exc Quotes</label>

                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_sales_const" id="edit_cap_view_sales_const" <?= !empty($selectedUser['view_sales_const']) ? 'checked' : '' ?>> View Construction Quotes</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="manage_sales_const" id="edit_cap_manage_sales_const" <?= !empty($selectedUser['manage_sales_const']) ? 'checked' : '' ?>> <span style="color: #f59e0b;">Manage</span> Construction Quotes</label>

                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_sales_finishes" id="edit_cap_view_sales_finishes" <?= !empty($selectedUser['view_sales_finishes']) ? 'checked' : '' ?>> View Finishes Quotes</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="manage_sales_finishes" id="edit_cap_manage_sales_finishes" <?= !empty($selectedUser['manage_sales_finishes']) ? 'checked' : '' ?>> <span style="color: #f59e0b;">Manage</span> Finishes Quotes</label>
                            
                            <label class="checkbox-item" style="grid-column: span 2; margin-top: 10px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 10px;">
                                <input type="checkbox" class="cap-check-edit" name="approve_quotes" id="edit_cap_approve_quotes" <?= !empty($selectedUser['approve_quotes']) ? 'checked' : '' ?>> 
                                <span style="color: #10b981; font-weight: bold;">Approve Commercial Quotes (Bypass / Authorization)</span>
                            </label>
                        </div>

                        <h4 style="margin-bottom: 1rem; color: var(--primary-color);">Menu Navigation Visibility</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 1.5rem;">
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_projects" id="edit_cap_view_projects" <?= !empty($selectedUser['view_projects']) ? 'checked' : '' ?>> Projects</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_mobilisation" id="edit_cap_view_mobilisation" <?= !empty($selectedUser['view_mobilisation']) ? 'checked' : '' ?>> Mobilisation</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_ohsa" id="edit_cap_view_ohsa" <?= !empty($selectedUser['view_ohsa']) ? 'checked' : '' ?>> OHSA</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_documentation" id="edit_cap_view_documentation" <?= !empty($selectedUser['view_documentation']) ? 'checked' : '' ?>> Documentation</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_drawings" id="edit_cap_view_drawings" <?= !empty($selectedUser['view_drawings']) ? 'checked' : '' ?>> Drawings</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_works_sales" id="edit_cap_view_works_sales" <?= !empty($selectedUser['view_works_sales']) ? 'checked' : '' ?>> Works Sales</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_property_sales" id="edit_cap_view_property_sales" <?= !empty($selectedUser['view_property_sales']) ? 'checked' : '' ?>> Property Sales</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_capital_projects" id="edit_cap_view_capital_projects" <?= !empty($selectedUser['view_capital_projects']) ? 'checked' : '' ?>> Capital Projects</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_nav_subcontractors" id="edit_cap_view_nav_subcontractors" <?= !empty($selectedUser['view_nav_subcontractors']) ? 'checked' : '' ?>> Subcon. Accounts</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_plant_bookings" id="edit_cap_view_plant_bookings" <?= !empty($selectedUser['view_plant_bookings']) ? 'checked' : '' ?>> <span style="color: #FF9800; font-weight: bold;">Plant Bookings Hub</span></label>
                        </div>

                        <h4 style="margin-bottom: 1rem; color: var(--primary-color);">Document Vault Access Levels</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group" style="margin: 0;">
                                <label style="font-size: 0.8rem; margin-bottom: 2px;">BCA Documents</label>
                                <select name="doc_bca" id="edit_cap_doc_bca" class="doc-select-edit" style="font-size: 0.85rem; padding: 4px 8px;">
                                    <option value="0" <?= $selectedUser['doc_bca'] == 0 ? 'selected' : '' ?>>0. No Access</option>
                                    <option value="1" <?= $selectedUser['doc_bca'] == 1 ? 'selected' : '' ?>>1. View Online Only</option>
                                    <option value="2" <?= $selectedUser['doc_bca'] == 2 ? 'selected' : '' ?>>2. View & Download</option>
                                    <option value="3" <?= $selectedUser['doc_bca'] == 3 ? 'selected' : '' ?>>3. View, Download, Upload</option>
                                    <option value="4" <?= $selectedUser['doc_bca'] == 4 ? 'selected' : '' ?>>4. Full Control (Inc. Delete)</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label style="font-size: 0.8rem; margin-bottom: 2px;">Engineering (ARMS, PA)</label>
                                <select name="doc_engineering" id="edit_cap_doc_engineering" class="doc-select-edit" style="font-size: 0.85rem; padding: 4px 8px;">
                                    <option value="0" <?= $selectedUser['doc_engineering'] == 0 ? 'selected' : '' ?>>0. No Access</option>
                                    <option value="1" <?= $selectedUser['doc_engineering'] == 1 ? 'selected' : '' ?>>1. View Online Only</option>
                                    <option value="2" <?= $selectedUser['doc_engineering'] == 2 ? 'selected' : '' ?>>2. View & Download</option>
                                    <option value="3" <?= $selectedUser['doc_engineering'] == 3 ? 'selected' : '' ?>>3. View, Download, Upload</option>
                                    <option value="4" <?= $selectedUser['doc_engineering'] == 4 ? 'selected' : '' ?>>4. Full Control (Inc. Delete)</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label style="font-size: 0.8rem; margin-bottom: 2px;">OHSA Documents</label>
                                <select name="doc_ohsa" id="edit_cap_doc_ohsa" class="doc-select-edit" style="font-size: 0.85rem; padding: 4px 8px;">
                                    <option value="0" <?= $selectedUser['doc_ohsa'] == 0 ? 'selected' : '' ?>>0. No Access</option>
                                    <option value="1" <?= $selectedUser['doc_ohsa'] == 1 ? 'selected' : '' ?>>1. View Online Only</option>
                                    <option value="2" <?= $selectedUser['doc_ohsa'] == 2 ? 'selected' : '' ?>>2. View & Download</option>
                                    <option value="3" <?= $selectedUser['doc_ohsa'] == 3 ? 'selected' : '' ?>>3. View, Download, Upload</option>
                                    <option value="4" <?= $selectedUser['doc_ohsa'] == 4 ? 'selected' : '' ?>>4. Full Control (Inc. Delete)</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label style="font-size: 0.8rem; margin-bottom: 2px;">Drawings & Plans</label>
                                <select name="doc_drawings" id="edit_cap_doc_drawings" class="doc-select-edit" style="font-size: 0.85rem; padding: 4px 8px;">
                                    <option value="0" <?= $selectedUser['doc_drawings'] == 0 ? 'selected' : '' ?>>0. No Access</option>
                                    <option value="1" <?= $selectedUser['doc_drawings'] == 1 ? 'selected' : '' ?>>1. View Online Only</option>
                                    <option value="2" <?= $selectedUser['doc_drawings'] == 2 ? 'selected' : '' ?>>2. View & Download</option>
                                    <option value="3" <?= $selectedUser['doc_drawings'] == 3 ? 'selected' : '' ?>>3. View, Download, Upload</option>
                                    <option value="4" <?= $selectedUser['doc_drawings'] == 4 ? 'selected' : '' ?>>4. Full Control (Inc. Delete)</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label style="font-size: 0.8rem; margin-bottom: 2px;">Commercial Docs</label>
                                <select name="doc_commercial" id="edit_cap_doc_commercial" class="doc-select-edit" style="font-size: 0.85rem; padding: 4px 8px;">
                                    <option value="0" <?= $selectedUser['doc_commercial'] == 0 ? 'selected' : '' ?>>0. No Access</option>
                                    <option value="1" <?= $selectedUser['doc_commercial'] == 1 ? 'selected' : '' ?>>1. View Online Only</option>
                                    <option value="2" <?= $selectedUser['doc_commercial'] == 2 ? 'selected' : '' ?>>2. View & Download</option>
                                    <option value="3" <?= $selectedUser['doc_commercial'] == 3 ? 'selected' : '' ?>>3. View, Download, Upload</option>
                                    <option value="4" <?= $selectedUser['doc_commercial'] == 4 ? 'selected' : '' ?>>4. Full Control (Inc. Delete)</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label style="font-size: 0.8rem; margin-bottom: 2px;">Sales Docs (Pricing/Renders)</label>
                                <select name="doc_sales" id="edit_cap_doc_sales" class="doc-select-edit" style="font-size: 0.85rem; padding: 4px 8px;">
                                    <option value="0" <?= $selectedUser['doc_sales'] == 0 ? 'selected' : '' ?>>0. No Access</option>
                                    <option value="1" <?= $selectedUser['doc_sales'] == 1 ? 'selected' : '' ?>>1. View Online Only</option>
                                    <option value="2" <?= $selectedUser['doc_sales'] == 2 ? 'selected' : '' ?>>2. View & Download</option>
                                    <option value="3" <?= $selectedUser['doc_sales'] == 3 ? 'selected' : '' ?>>3. View, Download, Upload</option>
                                    <option value="4" <?= $selectedUser['doc_sales'] == 4 ? 'selected' : '' ?>>4. Full Control (Inc. Delete)</option>
                                </select>
                            </div>
                            
                            <div class="form-group" style="margin: 0; grid-column: span 2; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 10px;">
                                <label style="font-size: 0.8rem; margin-bottom: 2px; color: #10b981;">Training & Company HR Docs (Client-Level)</label>
                                <select name="doc_training" id="edit_cap_doc_training" class="doc-select-edit" style="font-size: 0.85rem; padding: 4px 8px; border-color: #10b981;">
                                    <option value="0" <?= $selectedUser['doc_training'] == 0 ? 'selected' : '' ?>>0. No Access</option>
                                    <option value="1" <?= $selectedUser['doc_training'] == 1 ? 'selected' : '' ?>>1. View Online Only</option>
                                    <option value="2" <?= $selectedUser['doc_training'] == 2 ? 'selected' : '' ?>>2. View & Download</option>
                                    <option value="3" <?= $selectedUser['doc_training'] == 3 ? 'selected' : '' ?>>3. View, Download, Upload</option>
                                    <option value="4" <?= $selectedUser['doc_training'] == 4 ? 'selected' : '' ?>>4. Full Control (Inc. Delete)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group"><label>First Name</label><input type="text" name="first_name" value="<?= htmlspecialchars($selectedUser['first_name'] ?? '') ?>"></div>
                        <div class="form-group"><label>Last Name</label><input type="text" name="last_name" value="<?= htmlspecialchars($selectedUser['last_name'] ?? '') ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($selectedUser['email']) ?>"></div>
                        <div class="form-group"><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($selectedUser['phone'] ?? '') ?>"></div>
                    </div>

                    <div id="editLevel1Fields" style="background: rgba(139, 92, 246, 0.1); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--secondary-color); margin-bottom: 1.5rem; display: none;">
                        <h4 style="margin-bottom: 1rem; color: var(--secondary-color);">Level 1 Access: Firm Assignments</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Architect Firm:</label>
                                <select name="architect_firm_id"><option value="">-- None --</option><?php foreach ($architectFirms as $id => $name): ?><option value="<?= $id ?>" <?= $selectedUser['assigned_architect_firm_id'] == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select>
                            </div>
                            <div class="form-group">
                                <label>Structural Engineer Firm:</label>
                                <select name="structural_firm_id"><option value="">-- None --</option><?php foreach ($structuralFirms as $id => $name): ?><option value="<?= $id ?>" <?= $selectedUser['assigned_structural_firm_id'] == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group"><label>Account Status</label><select name="is_active"><option value="Yes" <?= $selectedUser['is_active'] == 'Yes' ? 'selected' : '' ?>>Active</option><option value="No" <?= $selectedUser['is_active'] == 'No' ? 'selected' : '' ?>>Inactive</option></select></div>
                        <div class="form-group"><label>Change Password</label><input type="password" name="new_password" placeholder="Leave blank to keep current"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.1rem;">Save Profile & Permissions</button>
                </form>

                <div id="editLevel2Fields" style="display: none;">
                    <div class="form-section">
                        <h3>Client Assignments (Level 2 Access)</h3>
                        <div class="bulk-actions">
                            <form method="POST" style="flex: 1;" onsubmit="return confirm('Assign ALL clients?');"><input type="hidden" name="action" value="assign_all_clients"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>"><button type="submit" class="btn btn-primary btn-bulk">Assign All Clients</button></form>
                            <form method="POST" style="flex: 1;" onsubmit="return confirm('Remove ALL clients?');"><input type="hidden" name="action" value="remove_all_clients"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>"><button type="submit" class="btn btn-danger btn-bulk">Remove All Clients</button></form>
                        </div>
                        <form method="POST" class="inline-form" style="margin-bottom: 1rem;">
                            <input type="hidden" name="action" value="assign_client"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                            <select name="client_id" required><option value="">-- Select Client --</option><?php foreach ($clients as $client): ?><option value="<?= $client['id'] ?>"><?= htmlspecialchars($client['name']) ?> (<?= htmlspecialchars($client['type']) ?>)</option><?php endforeach; ?></select>
                            <button type="submit" class="btn btn-sm">Assign</button>
                        </form>
                        <?php if (!empty($userClients)): ?>
                            <table class="data-table"><thead><tr><th>Client Name</th><th>Type</th><th>Actions</th></tr></thead><tbody>
                                <?php foreach ($userClients as $client): ?><tr><td><?= htmlspecialchars($client['name']) ?></td><td><?= htmlspecialchars($client['type']) ?></td><td><form method="POST" style="display:inline;"><input type="hidden" name="action" value="remove_client"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>"><input type="hidden" name="client_id" value="<?= $client['id'] ?>"><button type="submit" class="btn btn-sm btn-danger">Remove</button></form></td></tr><?php endforeach; ?>
                            </tbody></table>
                        <?php endif; ?>
                    </div>
                    <div class="form-section">
                        <h3>Project Exclusions</h3>
                        <form method="POST" class="inline-form" style="margin-bottom: 1rem;">
                            <input type="hidden" name="action" value="exclude_project"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                            <select name="project_id" required><option value="">-- Select Project to Exclude --</option><?php foreach ($userAccessibleProjects as $project): ?><option value="<?= $project['id'] ?>"><?= htmlspecialchars($project['name']) ?> (<?= htmlspecialchars($project['client_name']) ?>)</option><?php endforeach; ?></select>
                            <button type="submit" class="btn btn-sm btn-warning">Exclude</button>
                        </form>
                        <?php if (!empty($userExcludedProjects)): ?>
                            <table class="data-table"><thead><tr><th>Project Name</th><th>Client</th><th>Actions</th></tr></thead><tbody>
                                <?php foreach ($userExcludedProjects as $project): ?><tr><td><?= htmlspecialchars($project['name']) ?></td><td><?= htmlspecialchars($project['client_name']) ?></td><td><form method="POST" style="display:inline;"><input type="hidden" name="action" value="restore_project"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>"><input type="hidden" name="project_id" value="<?= $project['id'] ?>"><button type="submit" class="btn btn-sm btn-success">Restore</button></form></td></tr><?php endforeach; ?>
                            </tbody></table>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="editLevel3Fields" style="display: none;">
                    <div class="form-section" style="border: 1px solid var(--info); background: rgba(59, 130, 246, 0.05);">
                        <h3 style="color: var(--info);">Project Inclusions (Level 3 Access)</h3>
                        <form method="POST" class="inline-form" style="margin-bottom: 1rem;">
                            <input type="hidden" name="action" value="assign_project"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                            <select name="project_id" required><option value="">-- Select Project to Assign --</option><?php foreach ($allProjectsDb as $project): ?><option value="<?= $project['id'] ?>"><?= htmlspecialchars($project['name']) ?> (<?= htmlspecialchars($project['client_name']) ?>)</option><?php endforeach; ?></select>
                            <button type="submit" class="btn btn-sm btn-primary">Assign Project</button>
                        </form>
                        <?php if (!empty($userSpecificallyAssignedProjects)): ?>
                            <table class="data-table"><thead><tr><th>Assigned Project Name</th><th>Client</th><th>Actions</th></tr></thead><tbody>
                                <?php foreach ($userSpecificallyAssignedProjects as $project): ?><tr><td><?= htmlspecialchars($project['name']) ?></td><td><?= htmlspecialchars($project['client_name']) ?></td><td><form method="POST" style="display:inline;"><input type="hidden" name="action" value="remove_assigned_project"><input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>"><input type="hidden" name="project_id" value="<?= $project['id'] ?>"><button type="submit" class="btn btn-sm btn-danger">Remove Access</button></form></td></tr><?php endforeach; ?>
                            </tbody></table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state"><p>Select a user from the list to edit their profile and permissions.</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="createModal" class="custom-modal">
    <div class="custom-modal-content">
        <span class="custom-close-btn" onclick="closeCreateModal()">&times;</span>
        <h2 style="margin-top: 0; color: var(--primary-color);">Create New User</h2>
        <p style="color: var(--text-secondary); margin-bottom: 1.5rem; font-size: 0.9rem;">
            Create the basic profile here. Once created, you can edit their specific permissions and project access levels from the list.
        </p>
        
        <form method="POST">
            <input type="hidden" name="action" value="create_user">
            
            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label>First Name</label>
                    <input type="text" name="first_name" required>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label>Last Name</label>
                    <input type="text" name="last_name" required>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label>Username (Login ID)</label>
                <input type="text" name="username" required>
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label>Email Address</label>
                <input type="email" name="email">
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label>System Role</label>
                <select name="role" required>
                    <?php foreach($rolesList as $r): ?>
                        <option value="<?= $r ?>"><?= ucwords(str_replace('_', ' ', $r)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label>Initial Password</label>
                <input type="password" name="password" placeholder="Required" required>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.1rem;">Create User</button>
        </form>
    </div>
</div>

<script>
function openCreateModal() { document.getElementById('createModal').style.display = 'block'; }
function closeCreateModal() { document.getElementById('createModal').style.display = 'none'; }
window.onclick = function(event) { if (event.target == document.getElementById('createModal')) closeCreateModal(); }

const roleDefaults = {
    'admin': ['view_tracking', 'add_project', 'edit_project_details', 'update_project_status', 'edit_services', 'assign_actions', 'manage_clients', 'manage_professionals', 'manage_users', 'manage_subcontractors', 'view_subcontractor_accounts', 'manage_subcontractor_accounts', 'view_projects', 'view_mobilisation', 'view_ohsa', 'view_documentation', 'view_drawings', 'view_works_sales', 'view_property_sales', 'view_capital_projects', 'view_nav_subcontractors', 'view_sales_demo_exc', 'manage_sales_demo_exc', 'view_sales_const', 'manage_sales_const', 'view_sales_finishes', 'manage_sales_finishes', 'approve_quotes'],
    'director': ['view_tracking', 'add_project', 'edit_project_details', 'update_project_status', 'edit_services', 'assign_actions', 'manage_professionals', 'manage_subcontractors', 'view_projects', 'view_mobilisation', 'view_ohsa', 'view_documentation', 'view_drawings', 'view_works_sales', 'view_property_sales', 'view_capital_projects', 'view_sales_demo_exc', 'manage_sales_demo_exc', 'view_sales_const', 'manage_sales_const', 'view_sales_finishes', 'manage_sales_finishes', 'approve_quotes'],
    'system_manager': ['view_tracking', 'add_project', 'edit_project_details', 'update_project_status', 'edit_services', 'assign_actions', 'manage_professionals', 'manage_subcontractors', 'view_projects', 'view_mobilisation', 'view_ohsa', 'view_documentation', 'view_drawings'],
    'project_manager': ['update_project_status', 'assign_actions', 'view_projects', 'view_mobilisation', 'view_ohsa', 'view_documentation', 'view_drawings'],
    'accountant': ['assign_actions', 'view_projects', 'view_mobilisation', 'view_ohsa', 'view_documentation', 'view_works_sales', 'view_capital_projects', 'view_subcontractor_accounts', 'view_nav_subcontractors', 'view_sales_demo_exc', 'view_sales_const', 'view_sales_finishes'],
    'architect': ['view_tracking', 'assign_actions', 'view_projects', 'view_mobilisation', 'view_drawings', 'view_documentation'],
    'structural_engineer': ['view_tracking', 'assign_actions', 'view_projects', 'view_mobilisation', 'view_drawings', 'view_documentation'],
    'services_engineer': ['edit_services', 'assign_actions', 'view_projects', 'view_mobilisation', 'view_drawings', 'view_documentation'],
    'site_technical_officer': ['assign_actions', 'view_projects', 'view_mobilisation', 'view_ohsa', 'view_documentation', 'view_drawings'],
    'quality_controller': ['update_project_status', 'assign_actions', 'view_projects', 'view_mobilisation'],
    'pmo_staff': ['manage_subcontractors', 'assign_actions', 'view_projects', 'view_mobilisation', 'view_documentation'],
    'ohsa_rep': ['assign_actions', 'view_projects', 'view_ohsa'],
    'subcontractor': ['assign_actions', 'view_projects', 'view_drawings'],
    'sales_manager': ['view_works_sales', 'view_property_sales'],
    'sales_agent': ['view_property_sales'],
    'condominium_agent': [], 'end_customer': [], 'viewer': ['view_projects'],
    'plant_manager': ['view_plant_bookings'],
    'plant_driver': ['view_plant_bookings']
};

const docDefaults = {
    'admin': { doc_bca: 4, doc_ohsa: 4, doc_drawings: 4, doc_engineering: 4, doc_commercial: 4, doc_sales: 4, doc_training: 4 },
    'director': { doc_bca: 4, doc_ohsa: 4, doc_drawings: 4, doc_engineering: 4, doc_commercial: 4, doc_sales: 4, doc_training: 4 },
    'system_manager': { doc_bca: 4, doc_ohsa: 4, doc_drawings: 4, doc_engineering: 4, doc_commercial: 0, doc_sales: 0, doc_training: 4 },
    'project_manager': { doc_bca: 3, doc_ohsa: 3, doc_drawings: 3, doc_engineering: 3, doc_commercial: 0, doc_sales: 0, doc_training: 3 },
    'accountant': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 4, doc_sales: 0, doc_training: 0 },
    'architect': { doc_bca: 2, doc_ohsa: 0, doc_drawings: 3, doc_engineering: 2, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'structural_engineer': { doc_bca: 2, doc_ohsa: 0, doc_drawings: 3, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'services_engineer': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 3, doc_engineering: 3, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'site_technical_officer': { doc_bca: 2, doc_ohsa: 3, doc_drawings: 2, doc_engineering: 2, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'quality_controller': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 2, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'pmo_staff': { doc_bca: 3, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 2 },
    'ohsa_rep': { doc_bca: 0, doc_ohsa: 3, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 3 },
    'subcontractor': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 2, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'sales_manager': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 4, doc_training: 0 },
    'sales_agent': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 2, doc_training: 0 },
    'plant_manager': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 0 },
    'plant_driver': { doc_bca: 0, doc_ohsa: 0, doc_drawings: 0, doc_engineering: 0, doc_commercial: 0, doc_sales: 0, doc_training: 0 }
};
};

function toggleAccessSections(type) {
    const roleSelect = document.getElementById(type + 'Role');
    if (!roleSelect) return;
    const role = roleSelect.value;
    
    const level1Div = document.getElementById(type + 'Level1Fields');
    const level2Div = document.getElementById(type + 'Level2Fields');
    const level3Div = document.getElementById(type + 'Level3Fields');
    
    const level1Roles = ['architect', 'structural_engineer', 'site_technical_officer'];
    const level3Roles = ['subcontractor', 'condominium_agent', 'end_customer', 'project_manager'];
    const level0Roles = ['admin']; // Only Admin is Level 0
    const pureIsolatedRoles = ['plant_driver']; // Drivers don't need ANY project access
    
    if (level1Div) level1Div.style.display = level1Roles.includes(role) ? 'block' : 'none';
    
    // Plant Managers see Level 2 (Clients) and Level 3 (Projects). Drivers see nothing.
    if (level2Div) level2Div.style.display = (level0Roles.includes(role) || level1Roles.includes(role) || level3Roles.includes(role) || pureIsolatedRoles.includes(role)) ? 'none' : 'block';
    
    // Plant Managers DO NOT see Level 3 (Explicit Projects) by default unless you want them to.
    // If you want them to assign specific projects, you can add 'plant_manager' here.
    if (level3Div) level3Div.style.display = level3Roles.includes(role) ? 'block' : 'none';
}

function applyRoleDefaults(type) {
    const roleSelect = document.getElementById(type + 'Role');
    if (!roleSelect) return;
    const role = roleSelect.value;
    
    const defaults = roleDefaults[role] || [];
    document.querySelectorAll('.cap-check-' + type).forEach(box => box.checked = false);
    defaults.forEach(cap => { const box = document.getElementById(type + '_cap_' + cap); if (box) box.checked = true; });

    document.querySelectorAll('.doc-select-' + type).forEach(sel => sel.value = '0');
    if (docDefaults[role]) {
        Object.keys(docDefaults[role]).forEach(key => {
            const sel = document.getElementById(type + '_cap_' + key);
            if (sel) sel.value = docDefaults[role][key];
        });
    }
}

document.addEventListener('DOMContentLoaded', function() { toggleAccessSections('edit'); });
</script>

<?php require_once 'footer.php'; ?>
