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
    $role = $_POST['role'] ?? 'viewer';

    // Map all capabilities checkboxes (0 if unchecked, 1 if checked)
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

    // 1. CREATE USER
    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Username, email, and password are required';
        } else {
            try {
                $pdo->beginTransaction();
                
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, 'Yes')");
                $stmt->execute([$username, $email, $hash, $role]);
                $newId = $pdo->lastInsertId();

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
    
    // 2. UPDATE USER
    elseif ($action === 'update_user') {
        $userId = $_POST['user_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Update primary user details
            $stmt1 = $pdo->prepare("
                UPDATE users SET 
                username=?, email=?, first_name=?, last_name=?, phone=?, role=?, is_active=? 
                WHERE id=?
            ");
            $stmt1->execute([
                $_POST['username'], $_POST['email'], $_POST['first_name'], 
                $_POST['last_name'], $_POST['phone'], $role, $_POST['is_active'], $userId
            ]);
            
            // Bulletproof Upsert for Capabilities
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
            $message = 'User and capabilities updated successfully!';
        } catch (PDOException $e) { 
            $pdo->rollBack();
            $error = 'Update Error: ' . $e->getMessage(); 
        }
    }
}

// Fetch Data for UI
$users = getAllUsers($pdo);
$selectedUser = null;
if (isset($_GET['user_id'])) {
    $stmt = $pdo->prepare("
        SELECT u.*, uc.* FROM users u 
        LEFT JOIN user_capabilities uc ON u.id = uc.user_id 
        WHERE u.id = ?
    ");
    $stmt->execute([$_GET['user_id']]);
    $selectedUser = $stmt->fetch();
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
                            <label>Username</
