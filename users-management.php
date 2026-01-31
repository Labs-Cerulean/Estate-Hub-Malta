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
        
        // Granular Permissions
        $canAdd = ($role !== 'viewer') ? (isset($_POST['can_add_project']) ? 1 : 0) : 0;
        $canEdit = ($role !== 'viewer') ? (isset($_POST['can_edit_project']) ? 1 : 0) : 0;
        $canViewTracking = isset($_POST['can_view_tracking']) ? 1 : 0;
        $canAssign = isset($_POST['can_assign_actions']) ? 1 : 0;
        $canClients = isset($_POST['can_manage_clients']) ? 1 : 0;
        $canPros = isset($_POST['can_manage_pros']) ? 1 : 0;
        $canServices = isset($_POST['can_edit_services']) ? 1 : 0;

        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Username, email, and password are required';
        } else {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, role, first_name, last_name, phone, 
                                     can_add_project, can_edit_project, can_view_tracking, can_assign_actions,
                                     can_manage_clients, can_manage_pros, can_edit_services)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $username, $email, $passwordHash, $role, 
                    $_POST['first_name'], $_POST['last_name'], $_POST['phone'],
                    $canAdd, $canEdit, $canViewTracking, $canAssign,
                    $canClients, $canPros, $canServices
                ]);
                $message = 'User created successfully!';
            } catch (PDOException $e) {
                $error = 'Failed to create user: ' . $e->getMessage();
            }
        }
    }
    
    // 2. HANDLE USER UPDATE (Fixes the "Username" error)
    elseif ($_POST['action'] === 'update_user') {
        $userId = $_POST['user_id'];
        $role = $_POST['role'];

        // Enforce Rev 2.0 Rule: Viewers never get Add/Edit
        $canAdd = ($role !== 'viewer') ? (isset($_POST['can_add_project']) ? 1 : 0) : 0;
        $canEdit = ($role !== 'viewer') ? (isset($_POST['can_edit_project']) ? 1 : 0) : 0;
        
        $canViewTracking = isset($_POST['can_view_tracking']) ? 1 : 0;
        $canAssign = isset($_POST['can_assign_actions']) ? 1 : 0;
        $canClients = isset($_POST['can_manage_clients']) ? 1 : 0;
        $canPros = isset($_POST['can_manage_pros']) ? 1 : 0;
        $canServices = isset($_POST['can_edit_services']) ? 1 : 0;

        try {
            $stmt = $pdo->prepare("
                UPDATE users SET 
                    username = ?, email = ?, first_name = ?, last_name = ?, 
                    phone = ?, role = ?, is_active = ?,
                    can_add_project = ?, can_edit_project = ?, 
                    can_view_tracking = ?, can_assign_actions = ?,
                    can_manage_clients = ?, can_manage_pros = ?, can_edit_services = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['username'], $_POST['email'], $_POST['first_name'], $_POST['last_name'],
                $_POST['phone'], $role, $_POST['is_active'],
                $canAdd, $canEdit, $canViewTracking, $canAssign, 
                $canClients, $canPros, $canServices,
                $userId
            ]);
            $message = 'User updated successfully!';
        } catch (PDOException $e) {
            $error = 'Update failed: ' . $e->getMessage();
        }
    }
}

$users = getAllUsers($pdo);
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
            <h2>Users</h2>
            <table class="data-table">
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><span class="role-badge role-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
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
                    <h3>Edit Permissions: <?= htmlspecialchars($selectedUser['username']) ?></h3>
                    
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" value="<?= htmlspecialchars($selectedUser['username']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" id="editRole" onchange="togglePermissions('edit')">
                            <option value="viewer" <?= $selectedUser['role'] == 'viewer' ? 'selected' : '' ?>>Viewer</option>
                            <option value="manager" <?= $selectedUser['role'] == 'manager' ? 'selected' : '' ?>>Manager</option>
                            <option value="director" <?= $selectedUser['role'] == 'director' ? 'selected' : '' ?>>Director</option>
                            <option value="admin" <?= $selectedUser['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>

                    <div style="background: rgba(99,102,241,0.1); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--primary-color);">
                        <h4 style="margin-bottom: 1rem; color: var(--primary-color);">Capabilities Matrix</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <label class="checkbox-item"><input type="checkbox" name="can_add_project" id="edit_can_add" <?= $selectedUser['can_add_project'] ? 'checked' : '' ?>> Add Project</label>
                            <label class="checkbox-item"><input type="checkbox" name="can_edit_details" id="edit_can_edit" <?= $selectedUser['can_edit_details'] ? 'checked' : '' ?>> Edit Core Details</label>
                            <label class="checkbox-item"><input type="checkbox" name="can_update_status" id="edit_can_status" <?= $selectedUser['can_update_status'] ? 'checked' : '' ?>> Update BCA/Status</label>
                            <label class="checkbox-item"><input type="checkbox" name="can_view_tracking" <?= $selectedUser['can_view_tracking'] ? 'checked' : '' ?>> View Tracking Projects</label>
                            <label class="checkbox-item"><input type="checkbox" name="can_manage_clients" <?= $selectedUser['can_manage_clients'] ? 'checked' : '' ?>> Manage Clients</label>
                            <label class="checkbox-item"><input type="checkbox" name="can_manage_pros" <?= $selectedUser['can_manage_pros'] ? 'checked' : '' ?>> Manage Pros</label>
                        </div>
                    </div>
                    <br>
                    <button type="submit" class="btn btn-primary">Save Permissions</button>
                </form>
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
            <button type="submit" class="btn btn-primary">Create User</button>
        </form>
    </div>
</div>

<script>
function showCreateUserForm() { document.getElementById('createUserModal').style.display = 'block'; }
function hideCreateUserForm() { document.getElementById('createUserModal').style.display = 'none'; }

<script>
function togglePermissions(type) {
    const role = document.getElementById(type + 'Role').value;
    const isViewer = role === 'viewer';
    const checks = ['_can_add', '_can_edit', '_can_status'];
    checks.forEach(id => {
        const el = document.getElementById(type + id);
        if(el) { el.disabled = isViewer; if(isViewer) el.checked = false; }
    });
}
</script>

document.addEventListener('DOMContentLoaded', function() {
    if(document.getElementById('editRole')) togglePermissions('edit');
});
</script>

<?php require_once 'footer.php'; ?>
