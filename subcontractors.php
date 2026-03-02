<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('manage_subcontractors') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = ''; $error = '';

// Handle POST Requests (Add, Edit, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Convert the checkbox array into a comma-separated string
    $specialtyArray = $_POST['specialty'] ?? [];
    $specialtyString = is_array($specialtyArray) ? implode(', ', $specialtyArray) : '';

    try {
        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO subcontractors (name, specialty, contact_person, phone, email) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                trim($_POST['name']),
                $specialtyString,
                trim($_POST['contact_person'] ?? ''),
                trim($_POST['phone'] ?? ''),
                trim($_POST['email'] ?? '')
            ]);
            $message = "Subcontractor added successfully!";
        } 
        elseif ($action === 'edit') {
            $stmt = $pdo->prepare("UPDATE subcontractors SET name=?, specialty=?, contact_person=?, phone=?, email=? WHERE id=?");
            $stmt->execute([
                trim($_POST['name']),
                $specialtyString,
                trim($_POST['contact_person'] ?? ''),
                trim($_POST['phone'] ?? ''),
                trim($_POST['email'] ?? ''),
                $_POST['id']
            ]);
            $message = "Subcontractor updated successfully!";
        } 
        elseif ($action === 'delete') {
            // Check if subcontractor is assigned to any projects before deleting
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE sub_demolition_id = ? OR sub_excavation_id = ? OR sub_construction_id = ?");
            $checkStmt->execute([$_POST['id'], $_POST['id'], $_POST['id']]);
            if ($checkStmt->fetchColumn() > 0) {
                $error = "Cannot delete this subcontractor because they are currently assigned to one or more projects.";
            } else {
                $pdo->prepare("DELETE FROM subcontractors WHERE id=?")->execute([$_POST['id']]);
                $message = "Subcontractor deleted successfully!";
            }
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Fetch all subcontractors
$subcontractors = $pdo->query("SELECT * FROM subcontractors ORDER BY name ASC")->fetchAll();

$pageTitle = 'Subcontractor Management';
require_once 'header.php';
?>

<style>
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }

/* Checkbox Grid UI */
.checkbox-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    background: rgba(255,255,255,0.02);
    padding: 1rem;
    border-radius: 8px;
    border: 1px solid var(--border-glass);
}
.checkbox-grid label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin: 0;
}
.checkbox-grid input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

/* Specialty Tags in Table */
.spec-tag {
    display: inline-block;
    background: rgba(99, 102, 241, 0.1);
    color: var(--primary-color);
    border: 1px solid rgba(99, 102, 241, 0.2);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    margin: 0.1rem;
    white-space: nowrap;
}
</style>

<div class="main-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title" style="margin-bottom: 0;">Subcontractor Directory</h1>
            <p style="color: var(--text-secondary); margin-top: 0.25rem;">Manage execution partners and define their multiple specialties.</p>
        </div>
        <button onclick="openModal('add')" class="btn btn-primary">+ Add Subcontractor</button>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Company Name</th>
                    <th>Specialties</th>
                    <th>Contact Person</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($subcontractors)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">No subcontractors found. Click 'Add Subcontractor' to begin.</td></tr>
                <?php else: ?>
                    <?php foreach ($subcontractors as $sub): ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--primary-color);"><?= htmlspecialchars($sub['name']) ?></td>
                            <td>
                                <?php 
                                if (!empty($sub['specialty'])) {
                                    $specs = explode(', ', $sub['specialty']);
                                    foreach ($specs as $spec) {
                                        echo "<span class='spec-tag'>" . htmlspecialchars($spec) . "</span> ";
                                    }
                                } else {
                                    echo "<span style='color: var(--text-muted);'>-</span>";
                                }
                                ?>
                            </td>
                            <td><?= htmlspecialchars($sub['contact_person'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($sub['phone'] ?? '-') ?></td>
                            <td>
                                <?php if (!empty($sub['email'])): ?>
                                    <a href="mailto:<?= htmlspecialchars($sub['email']) ?>" style="color: var(--text-primary); text-decoration: underline;"><?= htmlspecialchars($sub['email']) ?></a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 0.5rem;">
                                    <button onclick='openModal("edit", <?= json_encode($sub, JSON_HEX_APOS) ?>)' class="btn btn-sm btn-secondary" style="margin: 0;">Edit</button>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this subcontractor?');" style="margin: 0;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $sub['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="margin: 0;">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="subModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <h2 id="modalTitle" style="margin-bottom: 1.5rem; color: var(--primary-color);">Add Subcontractor</h2>
        
        <form method="POST" id="subForm">
            <input type="hidden" name="action" id="modalAction" value="add">
            <input type="hidden" name="id" id="modalId" value="">
            
            <div class="form-group">
                <label>Company Name <span style="color: #ef4444;">*</span></label>
                <input type="text" name="name" id="modalName" required placeholder="e.g. Next Construction Ltd">
            </div>
            
            <div class="form-group">
                <label>Disciplines / Specialties (Select all that apply)</label>
                <div class="checkbox-grid">
                    <label><input type="checkbox" name="specialty[]" value="Demolition" class="spec-checkbox"> Demolition</label>
                    <label><input type="checkbox" name="specialty[]" value="Excavation" class="spec-checkbox"> Excavation</label>
                    <label><input type="checkbox" name="specialty[]" value="Turnkey Construction" class="spec-checkbox"> Turnkey Construction</label>
                    <label><input type="checkbox" name="specialty[]" value="Finishes" class="spec-checkbox"> Finishes</label>
                    <label><input type="checkbox" name="specialty[]" value="Plumbing & Electrical" class="spec-checkbox"> Plumbing & Electrical</label>
                    <label><input type="checkbox" name="specialty[]" value="General Multi-Discipline" class="spec-checkbox"> General Multi-Discipline</label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Contact Person</label>
                <input type="text" name="contact_person" id="modalContact" placeholder="e.g. Jason Saliba">
            </div>
            
            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" id="modalPhone" placeholder="+356 ...">
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" id="modalEmail" placeholder="info@company.com">
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.1rem; margin-top: 1rem;">Save Subcontractor</button>
        </form>
    </div>
</div>

<script>
function openModal(mode, data = null) {
    const modal = document.getElementById('subModal');
    const title = document.getElementById('modalTitle');
    const action = document.getElementById('modalAction');
    const id = document.getElementById('modalId');
    const name = document.getElementById('modalName');
    const contact = document.getElementById('modalContact');
    const phone = document.getElementById('modalPhone');
    const email = document.getElementById('modalEmail');
    
    // Clear all checkboxes first
    document.querySelectorAll('.spec-checkbox').forEach(cb => cb.checked = false);

    if (mode === 'add') {
        title.textContent = 'Add Subcontractor';
        action.value = 'add';
        id.value = '';
        name.value = '';
        contact.value = '';
        phone.value = '';
        email.value = '';
    } else if (mode === 'edit' && data) {
        title.textContent = 'Edit Subcontractor';
        action.value = 'edit';
        id.value = data.id;
        name.value = data.name;
        contact.value = data.contact_person || '';
        phone.value = data.phone || '';
        email.value = data.email || '';
        
        // Re-check the boxes based on saved string
        if (data.specialty) {
            const savedSpecs = data.specialty.split(', ').map(s => s.trim());
            document.querySelectorAll('.spec-checkbox').forEach(cb => {
                if (savedSpecs.includes(cb.value)) {
                    cb.checked = true;
                }
            });
        }
    }

    modal.style.display = 'block';
}

function closeModal() {
    document.getElementById('subModal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('subModal');
    if (event.target === modal) {
        closeModal();
    }
}
</script>

<?php require_once 'footer.php'; ?>
