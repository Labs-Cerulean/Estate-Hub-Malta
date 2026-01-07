<?php
require_once 'init.php';
require_once 'session-check.php';

// Check if user is admin or manager
if (!isAdmin() && getCurrentRole() !== 'manager') {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$editing = false;
$editProfessional = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $firmName = trim($_POST['firm_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $roleType = $_POST['role_type'] ?? '';
        
        if (empty($name) || empty($roleType)) {
            $message = 'Name and role type are required';
        } else {
            try {
                if ($action === 'create') {
                    $stmt = $pdo->prepare("
                        INSERT INTO professionals (name, firm_name, email, phone, role_type)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$name, $firmName, $email, $phone, $roleType]);
                    $message = 'Professional created successfully!';
                } else {
                    $id = $_POST['id'] ?? null;
                    $stmt = $pdo->prepare("
                        UPDATE professionals 
                        SET name = ?, firm_name = ?, email = ?, phone = ?, role_type = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $firmName, $email, $phone, $roleType, $id]);
                    $message = 'Professional updated successfully!';
                }
            } catch (PDOException $e) {
                $message = 'Error: ' . $e->getMessage();
            }
        }
    }
}

// Handle edit request
if (isset($_GET['edit'])) {
    $editId = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM professionals WHERE id = ?");
    $stmt->execute([$editId]);
    $editProfessional = $stmt->fetch();
    if ($editProfessional) {
        $editing = true;
    }
}

// Get all professionals grouped by role
$architects = $pdo->query("
    SELECT * FROM professionals 
    WHERE role_type = 'architect' 
    ORDER BY name
")->fetchAll();

$engineers = $pdo->query("
    SELECT * FROM professionals 
    WHERE role_type = 'structural_engineer' 
    ORDER BY name
")->fetchAll();

// Set page title
$pageTitle = 'Professionals Management';

// Now output HTML
require_once 'header.php';
?>


    <style>
        :root { color-scheme: dark !important; }
        body { background: #0a0e17 !important; color: #ffffff !important; }
        .card { background: rgba(255,255,255,0.05) !important; }
        table { background: rgba(255,255,255,0.05) !important; }
    </style>

    <main class="main-container">
        <h1 class="page-title">Professionals Management</h1>


       <!-- Add/Edit Form -->
        <div class="card mb-8">
            <div class="card-header">
                <h3><?= $editing ? 'Edit Professional' : 'Add New Professional' ?></h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                    <?php if ($editing): ?>
                        <input type="hidden" name="id" value="<?= $editProfessional['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Name <span style="color: #22c55e">*</span></label>
                            <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($editProfessional['name'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Role Type <span style="color: #22c55e">*</span></label>
                            <select class="form-select" name="roletype" required>
                                <option value="architect" <?= ($editProfessional['roletype'] ?? '') == 'architect' ? 'selected' : '' ?>>Architect</option>
                                <option value="structuralengineer" <?= ($editProfessional['roletype'] ?? '') == 'structuralengineer' ? 'selected' : '' ?>>Structural Engineer</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Firm Name</label>
                            <input type="text" class="form-control" name="firmname" value="<?= htmlspecialchars($editProfessional['firmname'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars($editProfessional['phone'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($editProfessional['email'] ?? '') ?>">
                    </div>
                    
                    <div class="flex gap-8">
                        <button type="submit" class="btn"><?= $editing ? 'Update Professional' : 'Add Professional' ?></button>
                        <?php if ($editing): ?>
                            <a href="professionals-management.php" class="btn btn--secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Architects -->
        <div class="card mb-8">
            <div class="card-header">
                <h3>Architects (<?= count($architects) ?>)</h3>
            </div>
            <div class="card-body">
                <?php if ($architects): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Firm</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($architects as $pro): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($pro['name']) ?></td>
                                        <td><?= htmlspecialchars($pro['firm_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($pro['email'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($pro['phone'] ?? '-') ?></td>
                                        <td>
                                            <a href="?edit=<?= $pro['id'] ?>" class="btn btn--sm">Edit</a>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $pro['id'] ?>">
                                                <button type="submit" class="btn btn--sm">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>No architects yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Structural Engineers -->
        <div class="card">
            <div class="card-header">
                <h3>Structural Engineers (<?= count($engineers) ?>)</h3>
            </div>
            <div class="card-body">
                <?php if ($engineers): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Firm</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($engineers as $pro): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($pro['name']) ?></td>
                                        <td><?= htmlspecialchars($pro['firm_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($pro['email'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($pro['phone'] ?? '-') ?></td>
                                        <td>
                                            <a href="?edit=<?= $pro['id'] ?>" class="btn btn--sm">Edit</a>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $pro['id'] ?>">
                                                <button type="submit" class="btn btn--sm">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>No structural engineers yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>
<?php require_once 'footer.php'; ?>
