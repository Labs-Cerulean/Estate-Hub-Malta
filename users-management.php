<?php
require_once 'init.php';
require_once 'session-check.php';

if (!isAdmin()) { header('Location: dashboard.php'); exit; }

$message = ''; $error = '';

// Check and fix missing capabilities records for old users automatically
$pdo->exec("INSERT IGNORE INTO user_capabilities (user_id) SELECT id FROM users");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_POST['user_id'] ?? null;
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

    if ($_POST['action'] === 'update_user') {
        try {
            $pdo->beginTransaction();
            
            // Update Base User
            $stmt1 = $pdo->prepare("UPDATE users SET username=?, email=?, first_name=?, last_name=?, phone=?, role=?, is_active=? WHERE id=?");
            $stmt1->execute([$_POST['username'], $_POST['email'], $_POST['first_name'], $_POST['last_name'], $_POST['phone'], $role, $_POST['is_active'], $userId]);
            
            // Update Capabilities
            $stmt2 = $pdo->prepare("
                UPDATE user_capabilities SET 
                view_tracking=?, add_project=?, edit_project_details=?, update_project_status=?, 
                edit_services=?, assign_actions=?, manage_clients=?, manage_professionals=?, 
                manage_users=?, manage_subcontractors=? WHERE user_id=?
            ");
            $params = array_values($caps);
            $params[] = $userId;
            $stmt2->execute($params);
            
            $pdo->commit();
            $message = 'User and permissions updated successfully!';
        } catch (PDOException $e) { 
            $pdo->rollBack();
            $error = 'Error: ' . $e->getMessage(); 
        }
    }
}

$users = getAllUsers($pdo);
$selectedUser = null;
$selectedCaps = [];
if (isset($_GET['user_id'])) {
    $stmt = $pdo->prepare("SELECT u.*, uc.* FROM users u LEFT JOIN user_capabilities uc ON u.id = uc.user_id WHERE u.id = ?");
    $stmt->execute([$_GET['user_id']]);
    $selectedUser = $stmt->fetch();
}

$pageTitle = 'User Management Rev 2.0';
require_once 'header.php';
?>

<div class="main-container">
    <div class="two-column-layout">
        <div class="user-list">
            <h2>Users</h2>
            <table class="data-table">
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><span class="role-badge role-<?= $u['role'] ?>"><?= str_replace('_', ' ', $u['role']) ?></span></td>
                    <td><a href="?user_id=<?= $u['id'] ?>" class="btn btn-sm">Edit</a></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="user-details">
            <?php if ($selectedUser): ?>
                <form method="POST" class="form-section">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                    <h3>Edit Account: <?= htmlspecialchars($selectedUser['username']) ?></h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Role / User Type</label>
                            <select name="role" id="roleSelector" onchange="applyRoleDefaults()">
                                <?php 
                                $roles = ['admin', 'director', 'system_manager', 'architect', 'structural_engineer', 'services_engineer', 'quality_controller', 'pmo_staff', 'ohsa_rep', 'site_technical_officer', 'subcontractor', 'condominium_agent', 'sales_manager', 'sales_agent', 'end_customer', 'viewer'];
                                foreach($roles as $r): ?>
                                    <option value="<?= $r ?>" <?= $selectedUser['role'] === $r ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $r)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="background: rgba(99,102,241,0.1); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--primary-color);">
                        <h4 style="margin-bottom: 1rem; color: var(--primary-color);">Capabilities Matrix</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <label class="checkbox-item"><input type="checkbox" class="cap-check" name="view_tracking" id="cap_view_tracking" <?= $selectedUser['view_tracking'] ? 'checked' : '' ?>> View Tracking</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check" name="add_project" id="cap_add_project" <?= $selectedUser['add_project'] ? 'checked' : '' ?>> Add Projects</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check" name="edit_project_details" id="cap_edit_project_details" <?= $selectedUser['edit_project_details'] ? 'checked' : '' ?>> Edit Core Details</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check" name="update_project_status" id="cap_update_project_status" <?= $selectedUser['update_project_status'] ? 'checked' : '' ?>> Update BCA/Status</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check" name="edit_services" id="cap_edit_services" <?= $selectedUser['edit_services'] ? 'checked' : '' ?>> Edit Services</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check" name="assign_actions" id="cap_assign_actions" <?= $selectedUser['assign_actions'] ? 'checked' : '' ?>> Assign Actions</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check" name="manage_clients" id="cap_manage_clients" <?= $selectedUser['manage_clients'] ? 'checked' : '' ?>> Manage Clients</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check" name="manage_professionals" id="cap_manage_professionals" <?= $selectedUser['manage_professionals'] ? 'checked' : '' ?>> Manage Professionals</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check" name="manage_subcontractors" id="cap_manage_subcontractors" <?= $selectedUser['manage_subcontractors'] ? 'checked' : '' ?>> Manage Subcontractors</label>
                            <label class="checkbox-item"><input type="checkbox" class="cap-check" name="manage_users" id="cap_manage_users" <?= $selectedUser['manage_users'] ? 'checked' : '' ?>> Manage Users</label>
                        </div>
                        <p style="font-size: 0.8rem; margin-top: 1rem; color: var(--text-secondary);">Note: Admin role always bypasses these checks.</p>
                    </div>

                    <div class="form-row" style="margin-top:1rem;">
                        <div class="form-group"><label>First Name</label><input type="text" name="first_name" value="<?= htmlspecialchars($selectedUser['first_name'] ?? '') ?>"></div>
                        <div class="form-group"><label>Last Name</label><input type="text" name="last_name" value="<?= htmlspecialchars($selectedUser['last_name'] ?? '') ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($selectedUser['email']) ?>"></div>
                        <div class="form-group"><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($selectedUser['phone'] ?? '') ?>"></div>
                    </div>

                    <div class="form-group">
                        <label>Account Status</label>
                        <select name="is_active">
                            <option value="Yes" <?= $selectedUser['is_active'] == 'Yes' ? 'selected' : '' ?>>Active</option>
                            <option value="No" <?= $selectedUser['is_active'] == 'No' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Save Profile & Permissions</button>
                </form>
            <?php else: ?>
                <div class="placeholder"><p>Select a user to manage.</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Role Default Matrix Based on User PDF specs
const roleDefaults = {
    'admin': ['view_tracking', 'add_project', 'edit_project_details', 'update_project_status', 'edit_services', 'assign_actions', 'manage_clients', 'manage_professionals', 'manage_users', 'manage_subcontractors'],
    'director': ['view_tracking', 'add_project', 'edit_project_details', 'manage_professionals', 'manage_subcontractors', 'assign_actions'],
    'system_manager': ['view_tracking', 'add_project', 'edit_project_details', 'update_project_status', 'manage_professionals', 'manage_subcontractors', 'assign_actions'],
    'architect': ['view_tracking', 'assign_actions'],
    'structural_engineer': ['view_tracking', 'assign_actions'],
    'services_engineer': ['edit_services', 'assign_actions'],
    'quality_controller': ['assign_actions'],
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

function applyRoleDefaults() {
    const role = document.getElementById('roleSelector').value;
    const defaults = roleDefaults[role] || [];
    
    // Uncheck all first
    document.querySelectorAll('.cap-check').forEach(box => {
        box.checked = false;
    });

    // Check defaults
    defaults.forEach(cap => {
        const box = document.getElementById('cap_' + cap);
        if (box) box.checked = true;
    });
}
</script>

<?php require_once 'footer.php'; ?>
