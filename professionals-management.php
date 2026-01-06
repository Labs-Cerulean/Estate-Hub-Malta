<?php
require_once 'config.php';
require_once 'session-check.php';
require_once 'user-functions.php';

// Only managers and admins
if (!isAdmin() && getCurrentUserRole() !== 'manager') {
    header('Location: dashboard.php');
    exit;
}

// Handle form actions
$message = '';
$error = '';

if ($_POST) {
    try {
        if ($_POST['action'] === 'create') {
            $stmt = $pdo->prepare("
                INSERT INTO professionals (name, firm_name, email, phone, role_type) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['name'],
                $_POST['firm_name'] ?? null,
                $_POST['email'] ?? null,
                $_POST['phone'] ?? null,
                $_POST['role_type']
            ]);
            $message = 'Professional added successfully!';
        } elseif ($_POST['action'] === 'update') {
            $stmt = $pdo->prepare("
                UPDATE professionals 
                SET name = ?, firm_name = ?, email = ?, phone = ?, role_type = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['name'],
                $_POST['firm_name'] ?? null,
                $_POST['email'] ?? null,
                $_POST['phone'] ?? null,
                $_POST['role_type'],
                $_POST['id']
            ]);
            $message = 'Professional updated successfully!';
        } elseif ($_POST['action'] === 'delete') {
            $pdo->prepare("DELETE FROM professionals WHERE id = ?")->execute([$_POST['id']]);
            $message = 'Professional deleted successfully!';
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Get all professionals, grouped by role
$architects = $pdo->query("SELECT * FROM professionals WHERE role_type = 'architect' ORDER BY name")->fetchAll();
$engineers = $pdo->query("SELECT * FROM professionals WHERE role_type = 'structural_engineer' ORDER BY name")->fetchAll();

// Editing a specific professional?
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM professionals WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editing = $stmt->fetch();
}
?>

<?php include 'header.php'; ?>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h1>Professionals Management</h1>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Add/Edit Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3><?= $editing ? 'Edit Professional' : 'Add New Professional' ?></h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                        <?php if ($editing): ?>
                            <input type="hidden" name="id" value="<?= $editing['id'] ?>">
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" 
                                       value="<?= htmlspecialchars($editing['name'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="role_type" required>
                                    <option value="architect" <?= ($editing['role_type'] ?? '') === 'architect' ? 'selected' : '' ?>>
                                        Architect
                                    </option>
                                    <option value="structural_engineer" <?= ($editing['role_type'] ?? '') === 'structural_engineer' ? 'selected' : '' ?>>
                                        Structural Engineer
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Firm Name</label>
                                <input type="text" class="form-control" name="firm_name" 
                                       value="<?= htmlspecialchars($editing['firm_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" name="phone" 
                                       value="<?= htmlspecialchars($editing['phone'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" 
                                   value="<?= htmlspecialchars($editing['email'] ?? '') ?>">
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <?= $editing ? 'Update Professional' : 'Add Professional' ?>
                            </button>
                            <?php if ($editing): ?>
                                <a href="professionals-management.php" class="btn btn-secondary">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Architects List -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Architects (<?= count($architects) ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if ($architects): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
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
                                                <a href="?edit=<?= $pro['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <form method="POST" style="display: inline;" 
                                                      onsubmit="return confirm('Delete this professional?')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $pro['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No architects yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Structural Engineers List -->
            <div class="card">
                <div class="card-header">
                    <h3>Structural Engineers (<?= count($engineers) ?>)</h3>
                </div>
                <div class="card-body">
                    <?php if ($engineers): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
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
                                                <a href="?edit=<?= $pro['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <form method="POST" style="display: inline;" 
                                                      onsubmit="return confirm('Delete this professional?')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $pro['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No structural engineers yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
