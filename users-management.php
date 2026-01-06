<?php
$pageTitle = 'User Management';
include 'header.php';

// Only admins can access this page
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$error = '';

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'viewer';
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        
        // Validate inputs
        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Username, email, and password are required';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format';
        } else {
            $userId = createUser($pdo, $username, $email, $password, $role, $firstName, $lastName);
            if ($userId) {
                $message = "User '$username' created successfully!";
            } else {
                $error = 'Failed to create user (username or email may already exist)';
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
        
        if (empty($username) || empty($email)) {
            $error = 'Username and email are required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format';
        } else {
            $updated = updateUser($pdo, $userId, [
                'username' => $username,
                'email' => $email,
                'role' => $role,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => $isActive
            ]);
            if ($updated) {
                $message = 'User updated successfully!';
            } else {
                $error = 'Failed to update user';
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
    
    // Handle project assignment
    elseif ($_POST['action'] === 'assign_project') {
        $userId = $_POST['user_id'] ?? null;
        $projectId = $_POST['project_id'] ?? null;
        $accessLevel = $_POST['access_level'] ?? 'viewer';
        
        if ($userId && $projectId) {
            if (assignUserToProject($pdo, $userId, $projectId, $accessLevel)) {
                $message = 'User assigned to project successfully!';
            } else {
                $error = 'Failed to assign project';
            }
        } else {
            $error = 'User and project are required';
        }
    }
    
    // Handle project removal
    elseif ($_POST['action'] === 'remove_project') {
        $userId = $_POST['user_id'] ?? null;
        $projectId = $_POST['project_id'] ?? null;
        
        if ($userId && $projectId) {
            if (removeUserFromProject($pdo, $userId, $projectId)) {
                $message = 'User removed from project successfully!';
            } else {
                $error = 'Failed to remove user from project';
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

// Get all users and projects
$users = getAllUsers($pdo);
$projects = $pdo->query("SELECT id, name FROM projects ORDER BY name")->fetchAll();

// Get selected user details if editing
$selectedUser = null;
$userProjects = [];
if (isset($_GET['user_id'])) {
    $selectedUser = getUserById($pdo, $_GET['user_id']);
    if ($selectedUser) {
        $userProjects = getUserProjects($pdo, $_GET['user_id']);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Estate Hub</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="logo-section">
                <h1>User Management</h1>
                <p class="subtitle">Manage users and project assignments</p>
            </div>
            <div class="user-info">
                <span><?php echo getCurrentUserFullName(); ?></span>
                <!-- Logout Button -->
                <a href="api/logout.php" class="nav-link">Logout</a>
            </div>
        </header>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Main Content -->
        <div class="user-management">
            <!-- Users List -->
            <div class="user-list">
                <h3>Users</h3>
                <button class="btn btn--primary btn--full-width" onclick="showCreateUserForm()">
                    + Add New User
                </button>
                
                <div id="user-list-container" style="margin-top: 1rem;">
                    <?php foreach ($users as $user): ?>
                        <div class="user-item <?php echo (isset($_GET['user_id']) && $_GET['user_id'] == $user['id']) ? 'active' : ''; ?>"
                             onclick="selectUser(<?php echo $user['id']; ?>)">
                            <strong><?php echo htmlspecialchars($user['first_name'] ?? $user['username']); ?></strong>
                            <div style="font-size: 0.85rem; margin-top: 0.25rem;">
                                <span class="role-badge <?php echo htmlspecialchars($user['role']); ?>">
                                    <?php echo htmlspecialchars($user['role']); ?>
                                </span>
                                <span style="color: var(--color-text-secondary); font-size: 0.8rem;">
                                    <?php echo $user['is_active'] === 'Yes' ? '✓ Active' : '✗ Inactive'; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Details Panel -->
            <div class="user-details">
                <div id="details-container">
                    <?php if ($selectedUser): ?>
                        <!-- User Details Form -->
                        <div class="form-section">
                            <h3>User Information</h3>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_user">
                                <input type="hidden" name="user_id" value="<?php echo $selectedUser['id']; ?>">
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Username</label>
                                        <input type="text" name="username" class="form-control" 
                                               value="<?php echo htmlspecialchars($selectedUser['username']); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" 
                                               value="<?php echo htmlspecialchars($selectedUser['email']); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">First Name</label>
                                        <input type="text" name="first_name" class="form-control" 
                                               value="<?php echo htmlspecialchars($selectedUser['first_name'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Last Name</label>
                                        <input type="text" name="last_name" class="form-control" 
                                               value="<?php echo htmlspecialchars($selectedUser['last_name'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Global Role</label>
                                        <select name="role" class="form-control">
                                            <option value="admin" <?php echo $selectedUser['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                            <option value="manager" <?php echo $selectedUser['role'] === 'manager' ? 'selected' : ''; ?>>Manager</option>
                                            <option value="architect" <?php echo $selectedUser['role'] === 'architect' ? 'selected' : ''; ?>>Architect</option>
                                            <option value="viewer" <?php echo $selectedUser['role'] === 'viewer' ? 'selected' : ''; ?>>Viewer</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Status</label>
                                        <select name="is_active" class="form-control">
                                            <option value="Yes" <?php echo $selectedUser['is_active'] === 'Yes' ? 'selected' : ''; ?>>Active</option>
                                            <option value="No" <?php echo $selectedUser['is_active'] === 'No' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn--primary">Update User</button>
                            </form>
                        </div>

                        <!-- Change Password -->
                        <div class="form-section">
                            <h3>Change Password</h3>
                            <form method="POST">
                                <input type="hidden" name="action" value="change_password">
                                <input type="hidden" name="user_id" value="<?php echo $selectedUser['id']; ?>">
                                
                                <div class="form-group">
                                    <label class="form-label">New Password</label>
                                    <input type="password" name="new_password" class="form-control" 
                                           placeholder="Minimum 6 characters" required>
                                </div>
                                
                                <button type="submit" class="btn btn--secondary">Change Password</button>
                            </form>
                        </div>

                        <!-- Project Assignments -->
                        <div class="form-section">
                            <h3>Project Assignments</h3>
                            
                            <form method="POST" style="margin-bottom: 1.5rem;">
                                <input type="hidden" name="action" value="assign_project">
                                <input type="hidden" name="user_id" value="<?php echo $selectedUser['id']; ?>">
                                
                                <div class="form-grid full">
                                    <div class="form-group">
                                        <label class="form-label">Select Project</label>
                                        <select name="project_id" class="form-control" required>
                                            <option value="">-- Choose a project --</option>
                                            <?php foreach ($projects as $project): ?>
                                                <option value="<?php echo $project['id']; ?>">
                                                    <?php echo htmlspecialchars($project['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Access Level</label>
                                        <select name="access_level" class="form-control">
                                            <option value="admin">Admin</option>
                                            <option value="manager" selected>Manager</option>
                                            <option value="architect">Architect</option>
                                            <option value="viewer">Viewer</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn--primary">Assign Project</button>
                            </form>

                            <!-- Current Assignments -->
                            <?php if (!empty($userProjects)): ?>
                                <div class="project-access-list">
                                    <h4>Current Assignments (<?php echo count($userProjects); ?>)</h4>
                                    <?php foreach ($userProjects as $project): ?>
                                        <div class="project-item">
                                            <div>
                                                <strong><?php echo htmlspecialchars($project['name']); ?></strong>
                                                <span class="role-badge <?php echo htmlspecialchars($project['access_level']); ?>" 
                                                      style="margin-left: 0.5rem;">
                                                    <?php echo htmlspecialchars($project['access_level']); ?>
                                                </span>
                                            </div>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="remove_project">
                                                <input type="hidden" name="user_id" value="<?php echo $selectedUser['id']; ?>">
                                                <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                                <button type="submit" class="btn btn--outline btn--small">Remove</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p style="color: var(--color-text-secondary); font-style: italic;">
                                    Not assigned to any projects yet.
                                </p>
                            <?php endif; ?>
                        </div>

                        <!-- Delete User -->
                        <div class="form-section">
                            <h3>Danger Zone</h3>
                            <form method="POST" onsubmit="return confirm('Are you sure? This action cannot be undone.');">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?php echo $selectedUser['id']; ?>">
                                
                                <button type="submit" class="btn" style="background: var(--color-error); color: white;">
                                    Delete User
                                </button>
                                <p style="font-size: 0.85rem; color: var(--color-text-secondary); margin-top: 0.5rem;">
                                    Deleting a user will remove all their project assignments.
                                </p>
                            </form>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 3rem 1rem; color: var(--color-text-secondary);">
                            <p>Select a user from the list to view and edit their details.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function selectUser(userId) {
            window.location.href = '?user_id=' + userId;
        }
        
        function showCreateUserForm() {
            document.getElementById('details-container').innerHTML = `
                <div class="form-section">
                    <h3>Create New User</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_user">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" 
                                   placeholder="Minimum 6 characters" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-control">
                                <option value="viewer">Viewer</option>
                                <option value="architect">Architect</option>
                                <option value="manager">Manager</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn--primary">Create User</button>
                        <button type="button" class="btn btn--outline" onclick="location.reload()">Cancel</button>
                    </form>
                </div>
            `;
        }
    </script>
</body>
</html>
