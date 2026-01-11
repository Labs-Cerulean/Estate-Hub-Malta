<?php
require_once 'init.php';
require_once 'session-check.php';

// Check if user is admin
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle user creation
    if ($_POST['action'] === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'viewer';
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $architectFirmId = !empty($_POST['architect_firm_id']) ? $_POST['architect_firm_id'] : null;
        $structuralFirmId = !empty($_POST['structural_firm_id']) ? $_POST['structural_firm_id'] : null;
        
        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Username, email, and password are required';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters';
        } else {
            $userId = createUser($pdo, $username, $email, $password, $role, $firstName, $lastName, $architectFirmId, $structuralFirmId);
            if ($userId) {
                $message = 'User created successfully! Default: No clients or projects assigned.';
            } else {
                $error = 'Failed to create user. Username or email may already exist.';
            }
        }
    }
    
    // Handle user update
    elseif ($_POST['action'] === 'update_user') {
        $userId = $_POST['user_id'] ?? null;
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'viewer';
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $isActive = $_POST['is_active'] ?? 'Yes';
        $architectFirmId = !empty($_POST['architect_firm_id']) ? $_POST['architect_firm_id'] : null;
        $structuralFirmId = !empty($_POST['structural_firm_id']) ? $_POST['structural_firm_id'] : null;
        
        if (empty($username) || empty($email)) {
            $error = 'Username and email are required';
        } else {
            $updated = updateUser($pdo, $userId, [
                'username' => $username,
                'email' => $email,
                'role' => $role,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => $isActive,
                'assigned_architect_firm_id' => $architectFirmId,
                'assigned_structural_firm_id' => $structuralFirmId
            ]);
            
            if ($updated) {
                $message = 'User updated successfully!';
            } else {
                $error = 'Failed to update user';
            }
        }
    }
    
    // Handle client assignment
    elseif ($_POST['action'] === 'assign_client') {
        $userId = $_POST['user_id'] ?? null;
        $clientId = $_POST['client_id'] ?? null;
        
        if ($userId && $clientId) {
            if (assignUserToClient($pdo, $userId, $clientId)) {
                $message = 'Client assigned successfully! User now has access to all projects for this client.';
            } else {
                $error = 'Failed to assign client';
            }
        }
    }
    
    // Handle assign all clients
    elseif ($_POST['action'] === 'assign_all_clients') {
        $userId = $_POST['user_id'] ?? null;
        
        if ($userId) {
            try {
                $currentUserId = getCurrentUserId();
                
                // Get all clients
                $allClients = $pdo->query("SELECT id FROM clients")->fetchAll(PDO::FETCH_COLUMN);
                
                $assignedCount = 0;
                $skippedCount = 0;
                
                foreach ($allClients as $clientId) {
                    // Check if already assigned
                    $check = $pdo->prepare("
                        SELECT id FROM user_client_access
                        WHERE user_id = ? AND client_id = ?
                    ");
                    $check->execute([$userId, $clientId]);
                    
                    if (!$check->fetch()) {
                        // Assign if not already assigned
                        $stmt = $pdo->prepare("
                            INSERT INTO user_client_access (user_id, client_id, assigned_by)
                            VALUES (?, ?, ?)
                        ");
                        if ($stmt->execute([$userId, $clientId, $currentUserId])) {
                            $assignedCount++;
                        }
                    } else {
                        $skippedCount++;
                    }
                }
                
                if ($assignedCount > 0) {
                    $message = "Successfully assigned {$assignedCount} client(s). {$skippedCount} were already assigned.";
                } else {
                    $message = "All clients were already assigned to this user.";
                }
            } catch (PDOException $e) {
                $error = 'Failed to assign all clients: ' . $e->getMessage();
            }
        }
    }
    
    // Handle remove all clients
    elseif ($_POST['action'] === 'remove_all_clients') {
        $userId = $_POST['user_id'] ?? null;
        
        if ($userId) {
            try {
                $stmt = $pdo->prepare("
                    DELETE FROM user_client_access
                    WHERE user_id = ?
                ");
                $stmt->execute([$userId]);
                $removedCount = $stmt->rowCount();
                
                $message = "Successfully removed {$removedCount} client assignment(s).";
            } catch (PDOException $e) {
                $error = 'Failed to remove all clients: ' . $e->getMessage();
            }
        }
    }
    
    // Handle client removal
    elseif ($_POST['action'] === 'remove_client') {
        $userId = $_POST['user_id'] ?? null;
        $clientId = $_POST['client_id'] ?? null;
        
        if ($userId && $clientId) {
            if (removeUserFromClient($pdo, $userId, $clientId)) {
                $message = 'Client removed successfully!';
            } else {
                $error = 'Failed to remove client';
            }
        }
    }
    
    // Handle project exclusion
    elseif ($_POST['action'] === 'exclude_project') {
        $userId = $_POST['user_id'] ?? null;
        $projectId = $_POST['project_id'] ?? null;
        
        if ($userId && $projectId) {
            if (excludeProjectFromUser($pdo, $userId, $projectId)) {
                $message = 'Project excluded successfully!';
            } else {
                $error = 'Failed to exclude project';
            }
        }
    }
    
    // Handle project exclusion removal
    elseif ($_POST['action'] === 'restore_project') {
        $userId = $_POST['user_id'] ?? null;
        $projectId = $_POST['project_id'] ?? null;
        
        if ($userId && $projectId) {
            if (removeProjectExclusion($pdo, $userId, $projectId)) {
                $message = 'Project access restored!';
            } else {
                $error = 'Failed to restore project access';
            }
        }
    }
    
    // Handle password change
    elseif ($_POST['action'] === 'change_password') {
        $userId = $_POST['user_id'] ?? null;
        $newPassword = $_POST['new_password'] ?? '';
        
        if (empty($newPassword) || strlen($newPassword) < 6) {
            $error = 'Password must be at least 6 characters';
        } else {
            if (changePassword($pdo, $userId, $newPassword)) {
                $message = 'Password changed successfully!';
            } else {
                $error = 'Failed to change password';
            }
        }
    }
    
    // Handle user deletion
    elseif ($_POST['action'] === 'delete_user') {
        $userId = $_POST['user_id'] ?? null;
        
        if ($userId && $userId !== getCurrentUserId()) {
            if (deleteUser($pdo, $userId)) {
                $message = 'User deleted successfully!';
            } else {
                $error = 'Failed to delete user';
            }
        } elseif ($userId === getCurrentUserId()) {
            $error = 'Cannot delete your own account';
        }
    }
}

// Get all users, clients, projects, and firms
$users = getAllUsers($pdo);
$clients = $pdo->query("SELECT id, name, type FROM clients ORDER BY name")->fetchAll();
$projects = $pdo->query("SELECT id, name, clientid FROM projects ORDER BY name")->fetchAll();
$firms = getAllFirms($pdo);

// Get professional IDs for firms
$architectFirms = [];
$structuralFirms = [];
foreach ($firms['architects'] as $firmName) {
    $profId = getProfessionalIdByFirm($pdo, $firmName, 'architect');
    if ($profId) {
        $architectFirms[$profId] = $firmName;
    }
}
foreach ($firms['structural_engineers'] as $firmName) {
    $profId = getProfessionalIdByFirm($pdo, $firmName, 'structural_engineer');
    if ($profId) {
        $structuralFirms[$profId] = $firmName;
    }
}

// Get selected user details
$selectedUser = null;
$userClients = [];
$userExcludedProjects = [];
$userAccessibleProjects = [];

if (isset($_GET['user_id'])) {
    $selectedUser = getUserById($pdo, $_GET['user_id']);
    if ($selectedUser) {
        $userClients = getUserClients($pdo, $_GET['user_id']);
        $userExcludedProjects = getUserExcludedProjects($pdo, $_GET['user_id']);
        $userAccessibleProjects = getAccessibleProjects($pdo, $_GET['user_id']);
    }
}

// Set page title
$pageTitle = 'User Management';

// Now output HTML
require_once 'header.php';
?>


    <style>
        .bulk-actions {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            padding: 1rem;
            background: var(--bg-secondary);
            border-radius: 8px;
            border: 1px solid var(--border-glass);
        }
        
        .bulk-actions .btn-bulk {
            flex: 1;
        }
        
        .client-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 0.75rem;
            background: var(--bg-secondary);
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .client-stats span {
            color: var(--text-secondary);
        }
        
        .client-stats strong {
            color: var(--primary-color);
        }
    </style>

    <div class="container">
        <h1>User Management</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="two-column-layout">
            <!-- Left Column: User List -->
            <div class="user-list">
                <h2>All Users</h2>
                <button onclick="showCreateUserForm()" class="btn btn-primary">Create New User</button>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= htmlspecialchars($user['username']) ?></td>
                                <td><?= htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])) ?></td>
                                <td><span class="role-badge role-<?= $user['role'] ?>"><?= htmlspecialchars($user['role']) ?></span></td>
                                <td><span class="status-<?= strtolower($user['is_active']) ?>"><?= htmlspecialchars($user['is_active']) ?></span></td>
                                <td>
                                    <a href="?user_id=<?= $user['id'] ?>" class="btn btn-small">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Right Column: User Details -->
            <div class="user-details">
                <?php if ($selectedUser): ?>
                    <h2>Edit User: <?= htmlspecialchars($selectedUser['username']) ?></h2>
                    
                    <!-- User Information Form -->
                    <form method="POST" class="form-section">
                        <input type="hidden" name="action" value="update_user">
                        <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                        
                        <h3>User Information</h3>
                        
                        <div class="form-group">
                            <label>Username:</label>
                            <input type="text" name="username" value="<?= htmlspecialchars($selectedUser['username']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Email:</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($selectedUser['email']) ?>" required>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>First Name:</label>
                                <input type="text" name="first_name" value="<?= htmlspecialchars($selectedUser['first_name'] ?? '') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Last Name:</label>
                                <input type="text" name="last_name" value="<?= htmlspecialchars($selectedUser['last_name'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Role:</label>
                                <select name="role" required id="userRole">
                                    <option value="viewer" <?= $selectedUser['role'] === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                                    <option value="manager" <?= $selectedUser['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
                                    <option value="architect" <?= $selectedUser['role'] === 'architect' ? 'selected' : '' ?>>Architect</option>
                                    <option value="services_engineer" <?= $selectedUser['role'] === 'services_engineer' ? 'selected' : '' ?>>Service Engineer</option>
                                    <option value="admin" <?= $selectedUser['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Status:</label>
                                <select name="is_active">
                                    <option value="Yes" <?= $selectedUser['is_active'] === 'Yes' ? 'selected' : '' ?>>Active</option>
                                    <option value="No" <?= $selectedUser['is_active'] === 'No' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Architect Firm Assignments (only show for architect role) -->
                        <div id="firmAssignments" style="display: <?= $selectedUser['role'] === 'architect' ? 'block' : 'none' ?>;">
                            <h4>Firm Assignments (for Architects only)</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Architect Firm:</label>
                                    <select name="architect_firm_id">
                                        <option value="">-- None --</option>
                                        <?php foreach ($architectFirms as $id => $name): ?>
                                            <option value="<?= $id ?>" <?= $selectedUser['assigned_architect_firm_id'] == $id ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($name) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label>Structural Engineer Firm:</label>
                                    <select name="structural_firm_id">
                                        <option value="">-- None --</option>
                                        <?php foreach ($structuralFirms as $id => $name): ?>
                                            <option value="<?= $id ?>" <?= $selectedUser['assigned_structural_firm_id'] == $id ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($name) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <p class="info-text">Architects will only see projects where their assigned firm is listed as architect or structural engineer.</p>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Update User</button>
                    </form>
                    
                    <!-- Client Assignments (hide for architects) -->
                    <?php if ($selectedUser['role'] !== 'architect'): ?>
                        <div class="form-section">
                            <h3>Client Assignments</h3>
                            <p class="info-text">Assigning a client gives the user access to ALL projects for that client.</p>
                            
                            <!-- Client Stats -->
                            <div class="client-stats">
                                <span>Assigned: <strong><?= count($userClients) ?></strong> of <strong><?= count($clients) ?></strong> total clients</span>
                            </div>
                            
                            <!-- Bulk Actions -->
                            <div class="bulk-actions">
                                <form method="POST" style="flex: 1;" onsubmit="return confirm('Assign ALL clients to this user? This will give them access to all projects.');">
                                    <input type="hidden" name="action" value="assign_all_clients">
                                    <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                                    <button type="submit" class="btn btn-primary btn-bulk">
                                        <span>📋</span> Assign All Clients
                                    </button>
                                </form>
                                
                                <form method="POST" style="flex: 1;" onsubmit="return confirm('Remove ALL client assignments? This will revoke access to all projects.');">
                                    <input type="hidden" name="action" value="remove_all_clients">
                                    <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-bulk">
                                        <span>🗑️</span> Remove All Clients
                                    </button>
                                </form>
                            </div>
                            
                            <!-- Individual Client Assignment -->
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="action" value="assign_client">
                                <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                                
                                <select name="client_id" required>
                                    <option value="">-- Select Client --</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['id'] ?>"><?= htmlspecialchars($client['name']) ?> (<?= htmlspecialchars($client['type']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <button type="submit" class="btn btn-small">Assign Client</button>
                            </form>
                            
                            <?php if (!empty($userClients)): ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Client Name</th>
                                            <th>Type</th>
                                            <th>Assigned Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($userClients as $client): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($client['name']) ?></td>
                                                <td><?= htmlspecialchars($client['type']) ?></td>
                                                <td><?= htmlspecialchars(date('Y-m-d', strtotime($client['assigned_at']))) ?></td>
                                                <td>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this client?');">
                                                        <input type="hidden" name="action" value="remove_client">
                                                        <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                                                        <input type="hidden" name="client_id" value="<?= $client['id'] ?>">
                                                        <button type="submit" class="btn btn-small btn-danger">Remove</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p class="no-data">No clients assigned yet.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Project Exclusions -->
                    <div class="form-section">
                        <h3>Project Exclusions</h3>
                        <p class="info-text">Remove specific projects from user's access (even if they have access via client assignment or firm).</p>
                        
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="action" value="exclude_project">
                            <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                            
                            <select name="project_id" required>
                                <option value="">-- Select Project to Exclude --</option>
                                <?php foreach ($userAccessibleProjects as $project): ?>
                                    <option value="<?= $project['id'] ?>"><?= htmlspecialchars($project['name']) ?> (<?= htmlspecialchars($project['client_name']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            
                            <button type="submit" class="btn btn-small btn-warning">Exclude Project</button>
                        </form>
                        
                        <?php if (!empty($userExcludedProjects)): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Project Name</th>
                                        <th>Client</th>
                                        <th>Excluded Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userExcludedProjects as $project): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($project['name']) ?></td>
                                            <td><?= htmlspecialchars($project['client_name']) ?></td>
                                            <td><?= htmlspecialchars(date('Y-m-d', strtotime($project['excluded_at']))) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="restore_project">
                                                    <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                                                    <input type="hidden" name="project_id" value="<?= $project['id'] ?>">
                                                    <button type="submit" class="btn btn-small btn-success">Restore Access</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="no-data">No excluded projects.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Password Change -->
                    <div class="form-section">
                        <h3>Change Password</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="change_password">
                            <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                            
                            <div class="form-group">
                                <label>New Password:</label>
                                <input type="password" name="new_password" required minlength="6">
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Change Password</button>
                        </form>
                    </div>
                    
                    <!-- Delete User -->
                    <?php if ($selectedUser['id'] !== getCurrentUserId()): ?>
                        <div class="form-section danger-zone">
                            <h3>Danger Zone</h3>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                                <button type="submit" class="btn btn-danger">Delete User</button>
                            </form>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="placeholder">
                        <p>Select a user from the list to view and edit their details, or create a new user.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Create User Modal (unchanged) -->
    <div id="createUserModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close" onclick="hideCreateUserForm()">&times;</span>
            <h2>Create New User</h2>
            
            <form method="POST">
                <input type="hidden" name="action" value="create_user">
                
                <div class="form-group">
                    <label>Username:*</label>
                    <input type="text" name="username" required>
                </div>
                
                <div class="form-group">
                    <label>Email:*</label>
                    <input type="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label>Password:*</label>
                    <input type="password" name="password" required minlength="6">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name:</label>
                        <input type="text" name="first_name">
                    </div>
                    
                    <div class="form-group">
                        <label>Last Name:</label>
                        <input type="text" name="last_name">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Role:*</label>
                    <select name="role" required id="createUserRole" onchange="toggleFirmFields()">
                        <option value="viewer">Viewer</option>
                        <option value="manager">Manager</option>
                        <option value="architect">Architect</option>
                        <option value="services_engineer">Services Engineer</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                
                <div id="createFirmFields" style="display:none;">
                    <h4>Firm Assignments (for Architects)</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Architect Firm:</label>
                            <select name="architect_firm_id">
                                <option value="">-- None --</option>
                                <?php foreach ($architectFirms as $id => $name): ?>
                                    <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Structural Engineer Firm:</label>
                            <select name="structural_firm_id">
                                <option value="">-- None --</option>
                                <?php foreach ($structuralFirms as $id => $name): ?>
                                    <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <p class="info-text">New users start with NO access to clients or projects. You must assign them after creation.</p>
                
                <button type="submit" class="btn btn-primary">Create User</button>
            </form>
        </div>
    </div>
    
    <script>
        function showCreateUserForm() {
            document.getElementById('createUserModal').style.display = 'block';
        }
        
        function hideCreateUserForm() {
            document.getElementById('createUserModal').style.display = 'none';
        }
        
        function toggleFirmFields() {
            const role = document.getElementById('createUserRole').value;
            const firmFields = document.getElementById('createFirmFields');
            firmFields.style.display = (role === 'architect') ? 'block' : 'none';
        }
        
        // Toggle firm fields on edit page
        document.getElementById('userRole')?.addEventListener('change', function() {
            const firmDiv = document.getElementById('firmAssignments');
            firmDiv.style.display = (this.value === 'architect') ? 'block' : 'none';
        });
    </script>
<?php require_once 'footer.php'; ?>
