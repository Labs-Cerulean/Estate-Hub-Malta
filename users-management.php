<?php
require_once 'init.php';
require_once 'session-check.php';

// Only admins can access this page
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. HANDLE USER CREATION
    if ($_POST['action'] === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'viewer';
        
        // Rev 2.0 Granular Permissions
        $canAdd = ($role !== 'viewer') ? (isset($_POST['can_add_project']) ? 1 : 0) : 0;
        $canEdit = ($role !== 'viewer') ? (isset($_POST['can_edit_project']) ? 1 : 0) : 0;
        $canViewTracking = isset($_POST['can_view_tracking']) ? 1 : 0;
        $canAssign = isset($_POST['can_assign_actions']) ? 1 : 0;

        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Username, email, and password are required';
        } else {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, role, first_name, last_name, phone, 
                                     can_add_project, can_edit_project, can_view_tracking, can_assign_actions)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $username, $email, $passwordHash, $role, 
                    $_POST['first_name'], $_POST['last_name'], $_POST['phone'],
                    $canAdd, $canEdit, $canViewTracking, $canAssign
                ]);
                $message = 'User created successfully with granular permissions!';
            } catch (PDOException $e) {
                $error = 'Failed to create user: ' . $e->getMessage();
            }
        }
    }
    
    // 2. HANDLE USER UPDATE
    elseif ($_POST['action'] === 'update_user') {
        $userId = $_POST['user_id'];
        $role = $_POST['role'];

        // Enforce Rev 2.0 Rule: Viewers never get Add/Edit
        $canAdd = ($role !== 'viewer') ? (isset($_POST['can_add_project']) ? 1 : 0) : 0;
        $canEdit = ($role !== 'viewer') ? (isset($_POST['can_edit_project']) ? 1 : 0) : 0;
        $canViewTracking = isset($_POST['can_view_tracking']) ? 1 : 0;
        $canAssign = isset($_POST['can_assign_actions']) ? 1 : 0;

        try {
            $stmt = $pdo->prepare("
                UPDATE users SET 
                    username = ?, email = ?, first_name = ?, last_name = ?, 
                    phone = ?, role = ?, is_active = ?,
                    can_add_project = ?, can_edit_project = ?, 
                    can_view_tracking = ?, can_assign_actions = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['username'], $_POST['email'], $_POST['first_name'], $_POST['last_name'],
                $_POST['phone'], $role, $_POST['is_active'],
                $canAdd, $canEdit, $canViewTracking, $canAssign,
                $userId
            ]);
            $message = 'User updated successfully!';
        } catch (PDOException $e) {
            $error = 'Update failed: ' . $e->getMessage();
        }
    }

    // (Keep your existing assign_client, remove_client, etc. logic here)
    // ...
}

$users = getAllUsers($pdo);
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

$selectedUser = null;
if (isset($_GET['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['user_id']]);
    $selectedUser = $stmt->fetch();
}

$pageTitle = 'User Management Rev 2.0';
require_once 'header.php';
?>

<div class="main-container">
    <div class="two-column-layout">
        
        <div class="user-list">
            <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2>Users</h2>
                <button onclick="showCreateUserForm()" class="btn btn-primary">Add New</button>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><span class="role-badge role-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
                        <td><?= $u['is_active'] ?></td>
                        <td><a href="?user_id=<?= $u['id'] ?>" class="btn btn-sm">Edit</a></td>
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
                    
                    <h3>Edit: <?= htmlspecialchars($selectedUser['username']) ?></h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Role</label>
                            <select name="role" id="editRole" onchange="togglePermissions('edit')">
                                <option value="viewer" <?= $selectedUser['role'] == 'viewer' ? 'selected' : '' ?>>Viewer</option>
                                <option value="manager" <?= $selectedUser['role'] == 'manager' ? 'selected' : '' ?>>Manager</option>
                                <option value="director" <?= $selectedUser['role'] == 'director' ? 'selected' : '' ?>>Director</option>
                                <option value="architect" <?= $selectedUser['role'] == 'architect' ? 'selected' : '' ?>>Architect</option>
                                <option value="admin" <?= $selectedUser['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($selectedUser['phone'] ?? '') ?>">
                        </div>
                    </div>

                    <div style="background: rgba(255,255,255,0.05); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <h4 style="margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--primary-color);">System Capabilities</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <label class="checkbox-item">
                                <input type="checkbox" name="can_add_project" id="edit_can_add" <?= $selectedUser['can_add_project'] ? 'checked' : '' ?>> 
                                Add Projects
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="can_edit_project" id="edit_can_edit" <?= $selectedUser['can_edit_project'] ? 'checked' : '' ?>> 
                                Edit Projects
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="can_view_tracking" id="edit_can_track" <?= $selectedUser['can_view_tracking'] ? 'checked' : '' ?>> 
                                View Tracking
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="can_assign_actions" id="edit_can_assign" <?= $selectedUser['can_assign_actions'] ? 'checked' : '' ?>> 
                                Assign Actions
                            </label>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group"><label>First Name</label><input type="text" name="first_name" value="<?= $selectedUser['first_name'] ?>"></div>
                        <div class="form-group"><label>Last Name</label><input type="text" name="last_name" value="<?= $selectedUser['last_name'] ?>"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= $selectedUser['email'] ?>"></div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="is_active">
                                <option value="Yes" <?= $selectedUser['is_active'] == 'Yes' ? 'selected' : '' ?>>Active</option>
                                <option value="No" <?= $selectedUser['is_active'] == 'No' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            <?php else: ?>
                <div class="placeholder"><p>Select a user to manage permissions.</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="createUserModal" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="close" onclick="hideCreateUserForm()">&times;</span>
        <h2>Create New User</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create_user">
            <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
            <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
            <div class="form-group">
                <label>Role</label>
                <select name="role" id="createRole" onchange="togglePermissions('create')">
                    <option value="viewer">Viewer</option>
                    <option value="manager">Manager</option>
                    <option value="director">Director</option>
                    <option value="architect">Architect</option>
                </select>
            </div>
            
            <div style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                <label class="checkbox-item"><input type="checkbox" name="can_add_project" id="create_can_add"> Can Add Projects</label><br>
                <label class="checkbox-item"><input type="checkbox" name="can_edit_project" id="create_can_edit"> Can Edit Projects</label><br>
                <label class="checkbox-item"><input type="checkbox" name="can_view_tracking" id="create_can_track"> Can View Tracking</label><br>
                <label class="checkbox-item"><input type="checkbox" name="can_assign_actions" id="create_can_assign"> Can Assign Actions</label>
            </div>

            <button type="submit" class="btn btn-primary">Create User</button>
        </form>
    </div>
</div>

<script>
function showCreateUserForm() { document.getElementById('createUserModal').style.display = 'block'; }
function hideCreateUserForm() { document.getElementById('createUserModal').style.display = 'none'; }

/**
 * Rev 2.0 Logic: If role is 'viewer', force-disable Add/Edit checkboxes
 */
function togglePermissions(type) {
    const role = document.getElementById(type + 'Role').value;
    const addCheck = document.getElementById(type + '_can_add');
    const editCheck = document.getElementById(type + '_can_edit');

    if (role === 'viewer') {
        addCheck.checked = false;
        addCheck.disabled = true;
        editCheck.checked = false;
        editCheck.disabled = true;
    } else {
        addCheck.disabled = false;
        editCheck.disabled = false;
    }
}

// Run once on load for edit page
document.addEventListener('DOMContentLoaded', function() {
    if(document.getElementById('editRole')) togglePermissions('edit');
});
</script>

<?php require_once 'footer.php'; ?>
