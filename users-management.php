<?php
require_once 'init.php';
require_once 'session-check.php';

// Only admins can access this page
if (!isAdmin()) { header('Location: dashboard.php'); exit; }

$message = ''; $error = '';

// Safety Check: Ensure EVERY user has a row in the user_capabilities table
$pdo->exec("INSERT IGNORE INTO user_capabilities (user_id) SELECT id FROM users");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. CREATE USER
    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'viewer';

        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Username, email, and password are required';
        } else {
            try {
                $pdo->beginTransaction();
                
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, 'Yes')");
                $stmt->execute([$username, $email, $hash, $role]);
                $newId = $pdo->lastInsertId();

                // Map capabilities from form
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
                ];

                $stmtCaps = $pdo->prepare("
                    INSERT INTO user_capabilities (
                        user_id, view_tracking, add_project, edit_project_details, update_project_status, 
                        edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $params = array_values($caps);
                array_unshift($params, $newId);
                $stmtCaps->execute($params);

                $pdo->commit();
                $message = 'User created successfully!';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Failed to create user: ' . $e->getMessage();
            }
        }
    }
    
    // 2. UPDATE USER PROFILE & CAPABILITIES
    elseif ($action === 'update_user') {
        $userId = $_POST['user_id'];
        $role = $_POST['role'] ?? 'viewer';
        
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
        ];
        
        $architectFirmId = !empty($_POST['architect_firm_id']) ? $_POST['architect_firm_id'] : null;
        $structuralFirmId = !empty($_POST['structural_firm_id']) ? $_POST['structural_firm_id'] : null;

        try {
            $pdo->beginTransaction();
            
            // Update primary user details + Firm Assignments
            $stmt1 = $pdo->prepare("
                UPDATE users SET 
                username=?, email=?, first_name=?, last_name=?, phone=?, role=?, is_active=?,
                assigned_architect_firm_id=?, assigned_structural_firm_id=?
                WHERE id=?
            ");
            $stmt1->execute([
                $_POST['username'], $_POST['email'], $_POST['first_name'], $_POST['last_name'], 
                $_POST['phone'], $role, $_POST['is_active'], $architectFirmId, $structuralFirmId, $userId
            ]);
            
            // Update Capabilities
            $stmt2 = $pdo->prepare("
                INSERT INTO user_capabilities (
                    user_id, view_tracking, add_project, edit_project_details, update_project_status, 
                    edit_services, assign_actions, manage_clients, manage_professionals, manage_users, manage_subcontractors
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    view_tracking=VALUES(view_tracking), add_project=VALUES(add_project), 
                    edit_project_details=VALUES(edit_project_details), update_project_status=VALUES(update_project_status), 
                    edit_services=VALUES(edit_services), assign_actions=VALUES(assign_actions), 
                    manage_clients=VALUES(manage_clients), manage_professionals=VALUES(manage_professionals), 
                    manage_users=VALUES(manage_users), manage_subcontractors=VALUES(manage_subcontractors)
            ");
            $params = array_values($caps);
            array_unshift($params, $userId);
            $stmt2->execute($params);
            
            $pdo->commit();
            $message = 'User profile and permissions updated successfully!';
        } catch (PDOException $e) { 
            $pdo->rollBack();
            $error = 'Update Error: ' . $e->getMessage(); 
        }
    }

    // 3. CLIENT & PROJECT ASSIGNMENTS (RESTORED)
    elseif ($action === 'assign_client') {
        if ($_POST['user_id'] && $_POST['client_id']) {
            if (assignUserToClient($pdo, $_POST['user_id'], $_POST['client_id'])) {
                $message = 'Client assigned successfully!';
            } else { $error = 'Failed to assign client'; }
        }
    }
    elseif ($action === 'assign_all_clients') {
        if ($_POST['user_id']) {
            try {
                $allClients = $pdo->query("SELECT id FROM clients")->fetchAll(PDO::FETCH_COLUMN);
                $assignedCount = 0;
                foreach ($allClients as $clientId) {
                    if (assignUserToClient($pdo, $_POST['user_id'], $clientId)) $assignedCount++;
                }
                $message = "Assigned {$assignedCount} new client(s).";
            } catch (PDOException $e) { $error = 'Error: ' . $e->getMessage(); }
        }
    }
    elseif ($action === 'remove_all_clients') {
        if ($_POST['user_id']) {
            $stmt = $pdo->prepare("DELETE FROM user_client_access WHERE user_id = ?");
            $stmt->execute([$_POST['user_id']]);
            $message = "All clients removed.";
        }
    }
    elseif ($action === 'remove_client') {
        if ($_POST['user_id'] && $_POST['client_id']) {
            removeUserFromClient($pdo, $_POST['user_id'], $_POST['client_id']);
            $message = 'Client removed.';
        }
    }
    elseif ($action === 'exclude_project') {
        if ($_POST['user_id'] && $_POST['project_id']) {
            excludeProjectFromUser($pdo, $_POST['user_id'], $_POST['project_id']);
            $message = 'Project excluded.';
        }
    }
    elseif ($action === 'restore_project') {
        if ($_POST['user_id'] && $_POST['project_id']) {
            removeProjectExclusion($pdo, $_POST['user_id'], $_POST['project_id']);
            $message = 'Project access restored.';
        }
    }
    elseif ($action === 'change_password') {
        if (strlen($_POST['new_password']) >= 6) {
            changePassword($pdo, $_POST['user_id'], $_POST['new_password']);
            $message = 'Password changed successfully.';
        } else {
            $error = 'Password must be at least 6 characters.';
        }
    }
    elseif ($action === 'delete_user') {
        if ($_POST['user_id'] !== getCurrentUserId()) {
            deleteUser($pdo, $_POST['user_id']);
            $message = 'User deleted.';
            $_GET['user_id'] = null; // Clear selection
        } else {
            $error = 'Cannot delete yourself.';
        }
    }
}

// Fetch Data for UI
$users = getAllUsers($pdo);
$clients = $pdo->query("SELECT id, name, type FROM clients ORDER BY name")->fetchAll();
$firms = getAllFirms($pdo);

$architectFirms = [];
$structuralFirms = [];
foreach ($firms['architects'] as $firmName) {
    $profId = getProfessionalIdByFirm($pdo, $firmName, 'architect');
    if ($profId) $architectFirms[$profId] = $firmName;
}
foreach ($firms['structural_engineers'] as $firmName) {
    $profId = getProfessionalIdByFirm($pdo, $firmName, 'structural_engineer');
    if ($profId) $structuralFirms[$profId] = $firmName;
}

$selectedUser = null;
$userClients = [];
$userExcludedProjects = [];
$userAccessibleProjects = [];

if (isset($_GET['user_id']) && !empty($_GET['user_id'])) {
    $stmt = $pdo->prepare("SELECT u.*, uc.* FROM users u LEFT JOIN user_capabilities uc ON u.id = uc.user_id WHERE u.id = ?");
    $stmt->execute([$_GET['user_id']]);
    $selectedUser = $stmt->fetch();
    
    if ($selectedUser) {
        $userClients = getUserClients($pdo, $selectedUser['id']);
        $userExcludedProjects = getUserExcludedProjects($pdo, $selectedUser['id']);
        $userAccessibleProjects = getAccessibleProjects($pdo, $selectedUser['id']);
    }
}

$rolesList = [
    'admin', 'director', 'system_manager', 'architect', 'structural_engineer', 
    'services_engineer', 'quality_controller', 'pmo_staff', 'ohsa_rep', 
    'site_technical_officer', 'subcontractor', 'condominium_agent', 
    'sales_manager', 'sales_agent', 'end_customer', 'viewer'
];

$pageTitle = 'User Management Rev 2.0';
require_once 'header.php';
?>

<div class="main-container">
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="two-column-layout">
        
        <div class="user-list">
            <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2>All Users</h2>
                <button onclick="showCreateUserForm()" class="btn btn-primary btn-sm">Add New User</button>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>User Type</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><span class="role-badge role-viewer"><?= ucwords(str_replace('_', ' ', $u['role'])) ?></span></td>
                        <td><?= $u['is_active'] ?></td>
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
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" name="username" value="<?= htmlspecialchars($selectedUser['username']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>User Type (Role)</label>
                            <div style="display:flex; gap: 0.5rem;">
                                <select name="role" id="editRole" style="flex: 1;" onchange="toggleFirmFields('edit')">
                                    <?php foreach($rolesList as $r): ?>
                                        <option value="<?= $r ?>" <?= $selectedUser['role'] === $r ? 'selected' : '' ?>>
                                            <?= ucwords(str_replace('_', ' ', $r)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="applyRoleDefaults('edit')" title="Load PDF default capabilities">
                                    Load Defaults
                                </button>
                            </div>
                        </div>
                    </div>

                    <div style="background: rgba(99,102,241,0.1); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--primary-color); margin-bottom: 1.5rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary-color);">Capabilities Matrix</h4>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="view_tracking" id="edit_cap_view_tracking" <?= !empty($selectedUser['view_tracking']) ? 'checked' : '' ?>> View Tracking Stage</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="add_project" id="edit_cap_add_project" <?= !empty($selectedUser['add_project']) ? 'checked' : '' ?>> Create New Projects</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="edit_project_details" id="edit_cap_edit_project_details" <?= !empty($selectedUser['edit_project_details']) ? 'checked' : '' ?>> Edit Project Info & PAs</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="update_project_status" id="edit_cap_update_project_status" <?= !empty($selectedUser['update_project_status']) ? 'checked' : '' ?>> Update BCA/Status Ticks</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="edit_services" id="edit_cap_edit_services" <?= !empty($selectedUser['edit_services']) ? 'checked' : '' ?>> Edit Services & Utilities</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="assign_actions" id="edit_cap_assign_actions" <?= !empty($selectedUser['assign_actions']) ? 'checked' : '' ?>> Assign Actions</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="manage_clients" id="edit_cap_manage_clients" <?= !empty($selectedUser['manage_clients']) ? 'checked' : '' ?>> Manage Clients</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="manage_professionals" id="edit_cap_manage_professionals" <?= !empty($selectedUser['manage_professionals']) ? 'checked' : '' ?>> Manage Professionals</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="manage_subcontractors" id="edit_cap_manage_subcontractors" <?= !empty($selectedUser['manage_subcontractors']) ? 'checked' : '' ?>> Manage Subcontractors</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check-edit" name="manage_users" id="edit_cap_manage_users" <?= !empty($selectedUser['manage_users']) ? 'checked' : '' ?>> Manage Users</label>
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

                    <div id="editFirmFields" style="background: rgba(139, 92, 246, 0.1); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--secondary-color); margin-bottom: 1.5rem; display: none;">
                        <h4 style="margin-bottom: 1rem; color: var(--secondary-color);">Level 1 Access: Firm Assignments</h4>
                        <p class="info-text" style="margin-bottom: 1rem;">This user will ONLY see projects assigned to these firms.</p>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Architect Firm:</label>
                                <select name="architect_firm_id">
                                    <option value="">-- None --</option>
                                    <?php foreach ($architectFirms as $id => $name): ?>
                                        <option value="<?= $id ?>" <?= $selectedUser['assigned_architect_firm_id'] == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Structural Engineer Firm:</label>
                                <select name="structural_firm_id">
                                    <option value="">-- None --</option>
                                    <?php foreach ($structuralFirms as $id => $name): ?>
                                        <option value="<?= $id ?>" <?= $selectedUser['assigned_structural_firm_id'] == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Account Status</label>
                        <select name="is_active">
                            <option value="Yes" <?= $selectedUser['is_active'] == 'Yes' ? 'selected' : '' ?>>Active</option>
                            <option value="No" <?= $selectedUser['is_active'] == 'No' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.1rem;">Save Profile & Permissions</button>
                </form>

                <div class="form-section">
                    <h3>Client Assignments (Level 2 Access)</h3>
                    <p class="info-text">Assigning a client gives the user access to ALL projects for that client.</p>
                    
                    <div class="bulk-actions">
                        <form method="POST" style="flex: 1;" onsubmit="return confirm('Assign ALL clients?');">
                            <input type="hidden" name="action" value="assign_all_clients">
                            <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                            <button type="submit" class="btn btn-primary btn-bulk">Assign All Clients</button>
                        </form>
                        <form method="POST" style="flex: 1;" onsubmit="return confirm('Remove ALL clients?');">
                            <input type="hidden" name="action" value="remove_all_clients">
                            <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-bulk">Remove All Clients</button>
                        </form>
                    </div>
                    
                    <form method="POST" class="inline-form" style="margin-bottom: 1rem;">
                        <input type="hidden" name="action" value="assign_client">
                        <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                        <select name="client_id" required>
                            <option value="">-- Select Client --</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= $client['id'] ?>"><?= htmlspecialchars($client['name']) ?> (<?= htmlspecialchars($client['type']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm">Assign</button>
                    </form>
                    
                    <?php if (!empty($userClients)): ?>
                        <table class="data-table">
                            <thead>
                                <tr><th>Client Name</th><th>Type</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userClients as $client): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($client['name']) ?></td>
                                        <td><?= htmlspecialchars($client['type']) ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="remove_client">
                                                <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                                                <input type="hidden" name="client_id" value="<?= $client['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="no-data">No clients assigned.</p>
                    <?php endif; ?>
                </div>

                <div class="form-section">
                    <h3>Project Exclusions</h3>
                    <p class="info-text">Remove specific projects from user's access.</p>
                    
                    <form method="POST" class="inline-form" style="margin-bottom: 1rem;">
                        <input type="hidden" name="action" value="exclude_project">
                        <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                        <select name="project_id" required>
                            <option value="">-- Select Project to Exclude --</option>
                            <?php foreach ($userAccessibleProjects as $project): ?>
                                <option value="<?= $project['id'] ?>"><?= htmlspecialchars($project['name']) ?> (<?= htmlspecialchars($project['client_name']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-warning">Exclude</button>
                    </form>
                    
                    <?php if (!empty($userExcludedProjects)): ?>
                        <table class="data-table">
                            <thead>
                                <tr><th>Project Name</th><th>Client</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userExcludedProjects as $project): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($project['name']) ?></td>
                                        <td><?= htmlspecialchars($project['client_name']) ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="restore_project">
                                                <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                                                <input type="hidden" name="project_id" value="<?= $project['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success">Restore</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="form-section">
                    <h3>Change Password</h3>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                        <input type="password" name="new_password" required minlength="6" placeholder="New Password (min 6 chars)">
                        <button type="submit" class="btn btn-sm btn-primary">Update Password</button>
                    </form>
                </div>

                <?php if ($selectedUser['id'] !== getCurrentUserId()): ?>
                    <div class="form-section danger-zone">
                        <h3>Danger Zone</h3>
                        <form method="POST" onsubmit="return confirm('Delete this user completely?');">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                            <button type="submit" class="btn btn-danger">Delete User</button>
                        </form>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state">
                    <p>Select a user from the list to manage their profile and permissions.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="createUserModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 800px;">
        <span class="close" onclick="hideCreateUserForm()">&times;</span>
        <h2>Create New User</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create_user">
            
            <div class="form-row">
                <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
                <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
            </div>

            <div class="form-group">
                <label>User Type (Role)</label>
                <div style="display: flex; gap: 0.5rem;">
                    <select name="role" id="createRole" style="flex:1;" onchange="applyRoleDefaults('create'); toggleFirmFields('create');">
                        <?php foreach($rolesList as $r): ?>
                            <option value="<?= $r ?>"><?= ucwords(str_replace('_', ' ', $r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div style="background: rgba(0,0,0,0.2); padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
                <h4 style="margin-bottom: 1rem;">Capabilities Matrix (Auto-fills based on role)</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <label class="checkbox-item"><input type="checkbox" class="cap-check-create" name="view_tracking" id="create_cap_view_tracking"> View Tracking Stage</label>
                    <label class="checkbox-item"><input type="checkbox" class="cap-check-create" name="add_project" id="create_cap_add_project"> Add Projects</label>
                    <label class="checkbox-item"><input type="checkbox" class="cap-check-create" name="edit_project_details" id="create_cap_edit_project_details"> Edit Core Details</label>
                    <label class="checkbox-item"><input type="checkbox" class="cap-check-create" name="update_project_status" id="create_cap_update_project_status"> Update BCA/Status Ticks</label>
                    <label class="checkbox-item"><input type="checkbox" class="cap-check-create" name="edit_services" id="create_cap_edit_services"> Edit Services</label>
                    <label class="checkbox-item"><input type="checkbox" class="cap-check-create" name="assign_actions" id="create_cap_assign_actions"> Assign Actions</label>
                    <label class="checkbox-item"><input type="checkbox" class="cap-check-create" name="manage_clients" id="create_cap_manage_clients"> Manage Clients</label>
                    <label class="checkbox-item"><input type="checkbox" class="cap-check-create" name="manage_professionals" id="create_cap_manage_professionals"> Manage Professionals</label>
                    <label class="checkbox-item"><input type="checkbox" class="cap-check-create" name="manage_subcontractors" id="create_cap_manage_subcontractors"> Manage Subcontractors</label>
                    <label class="checkbox-item"><input type="checkbox" class="cap-check-create" name="manage_users" id="create_cap_manage_users"> Manage Users</label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">Create User</button>
        </form>
    </div>
</div>

<script>
function showCreateUserForm() { document.getElementById('createUserModal').style.display = 'block'; }
function hideCreateUserForm() { document.getElementById('createUserModal').style.display = 'none'; }

// Show Firm Dropdowns only for Level 1 Access Roles
function toggleFirmFields(type) {
    const role = document.getElementById(type + 'Role').value;
    const firmDiv = document.getElementById(type + 'FirmFields');
    const level1Roles = ['architect', 'structural_engineer', 'site_technical_officer'];
    
    if (firmDiv) {
        firmDiv.style.display = level1Roles.includes(role) ? 'block' : 'none';
    }
}

// Map from PDF
const roleDefaults = {
    'admin': ['view_tracking', 'add_project', 'edit_project_details', 'update_project_status', 'edit_services', 'assign_actions', 'manage_clients', 'manage_professionals', 'manage_users', 'manage_subcontractors'],
    'director': ['view_tracking', 'add_project', 'edit_project_details', 'update_project_status', 'edit_services', 'assign_actions', 'manage_professionals', 'manage_subcontractors'],
    'system_manager': ['view_tracking', 'add_project', 'edit_project_details', 'update_project_status', 'edit_services', 'assign_actions', 'manage_professionals', 'manage_subcontractors'],
    'architect': ['view_tracking', 'assign_actions'],
    'structural_engineer': ['view_tracking', 'assign_actions'],
    'services_engineer': ['edit_services', 'assign_actions'],
    'quality_controller': ['update_project_status', 'assign_actions'],
    'pmo_staff': ['manage_subcontractors', 'assign_actions'],
    'ohsa_rep': ['assign_actions'],
    'site_technical_officer': ['assign_actions'],
    'subcontractor': ['assign_actions'],
    'condominium_agent': [],
    'sales_manager': [],
    'sales_agent': [],
    'end_customer': [],
    'viewer': []
};

function applyRoleDefaults(type) {
    const roleSelect = document.getElementById(type + 'Role');
    if (!roleSelect) return;
    const defaults = roleDefaults[roleSelect.value] || [];
    
    document.querySelectorAll('.cap-check-' + type).forEach(box => box.checked = false);
    defaults.forEach(cap => {
        const box = document.getElementById(type + '_cap_' + cap);
        if (box) box.checked = true;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    toggleFirmFields('edit');
});
</script>

<?php require_once 'footer.php'; ?>
