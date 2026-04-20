<?php
require_once 'init.php';
require_once 'session-check.php';
require_once 'S3FileManager.php'; // Include Cloudflare R2

if (!hasPermission('can_manage_clients') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = '';
$s3 = new S3FileManager(); // Initialize R2

// Handle Image Upload Logic directly to Cloudflare R2
function handleLogoUpload($fileInputName, $s3) {
    if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES[$fileInputName]['tmp_name'];
        $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.-]/", "_", $_FILES[$fileInputName]['name']);
        
        // Upload directly to Cloudflare R2 via S3FileManager
        $uploadUrl = $s3->uploadFile($fileTmpPath, 'logos/' . $fileName);
        
        if ($uploadUrl) {
            return $uploadUrl; // Return the full R2 URL
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create Client
    if ($action === 'create_client') {
        try {
            $logoPath = handleLogoUpload('client_logo', $s3);
            $stmt = $pdo->prepare("INSERT INTO clients (name, city, contact, type, logo_path, bank_name, iban, swift_bic) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([ $_POST['name'], $_POST['city'] ?? null, $_POST['contact'] ?? null, $_POST['type'], $logoPath, $_POST['bank_name'] ?? null, $_POST['iban'] ?? null, $_POST['swift_bic'] ?? null ]);
            $clientId = $pdo->lastInsertId();
            autoAssignCreatorToClient($pdo, $clientId, getCurrentUserId());
            $message = 'Client created successfully!';
        } catch (PDOException $e) { $message = 'Error: Client name may already exist!'; }
    }
    // Edit Client 
    elseif ($action === 'edit_client') {
        try {
            $clientId = $_POST['client_id'];
            $logoPath = handleLogoUpload('edit_client_logo', $s3);
            
            if ($logoPath) {
                $stmt = $pdo->prepare("UPDATE clients SET name=?, city=?, contact=?, type=?, logo_path=?, bank_name=?, iban=?, swift_bic=? WHERE id=?");
                $stmt->execute([ $_POST['name'], $_POST['city'] ?? null, $_POST['contact'] ?? null, $_POST['type'], $logoPath, $_POST['bank_name'] ?? null, $_POST['iban'] ?? null, $_POST['swift_bic'] ?? null, $clientId ]);
            } else {
                $stmt = $pdo->prepare("UPDATE clients SET name=?, city=?, contact=?, type=?, bank_name=?, iban=?, swift_bic=? WHERE id=?");
                $stmt->execute([ $_POST['name'], $_POST['city'] ?? null, $_POST['contact'] ?? null, $_POST['type'], $_POST['bank_name'] ?? null, $_POST['iban'] ?? null, $_POST['swift_bic'] ?? null, $clientId ]);
            }
            $message = 'Client updated successfully!';
        } catch (PDOException $e) { $message = 'Error updating client!'; }
    }
    // Delete Client
    elseif ($action === 'delete_client') {
        try {
            $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$_POST['client_id']]);
            $message = 'Client deleted successfully!';
        } catch (PDOException $e) { $message = 'Error: Cannot delete client (likely linked to projects).'; }
    }
}

$userId = getCurrentUserId();
$isAdmin = isAdmin();

try {
    if ($isAdmin) {
        $clients = $pdo->query("SELECT c.*, COUNT(p.id) as project_count FROM clients c LEFT JOIN projects p ON c.id = p.clientid GROUP BY c.id ORDER BY c.name")->fetchAll();
    } else {
        $user = getUserById($pdo, $userId);
        if ($user['role'] === 'architect') {
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.*, COUNT(DISTINCT p.id) as project_count FROM clients c
                INNER JOIN projects p ON c.id = p.clientid INNER JOIN project_pa_numbers ppn ON p.id = ppn.project_id
                WHERE ((? IS NOT NULL AND ppn.architect_id IN (SELECT id FROM professionals WHERE firm_id = ?))
                   OR (? IS NOT NULL AND ppn.structural_id IN (SELECT id FROM professionals WHERE firm_id = ?)))
                GROUP BY c.id ORDER BY c.name
            ");
            $stmt->execute([ $user['assigned_architect_firm_id'], $user['assigned_architect_firm_id'], $user['assigned_structural_firm_id'], $user['assigned_structural_firm_id'] ]);
            $clients = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare("SELECT c.*, COUNT(DISTINCT p.id) as project_count FROM clients c INNER JOIN user_client_access uca ON c.id = uca.client_id LEFT JOIN projects p ON c.id = p.clientid WHERE uca.user_id = ? GROUP BY c.id ORDER BY c.name");
            $stmt->execute([$userId]);
            $clients = $stmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    die("Error fetching clients: " . $e->getMessage());
}

$pageTitle = "Client Directory";
require_once 'header.php';
?>

<div class="content-header">
    <h2><i class="fas fa-handshake"></i> Client Directory</h2>
    <div>
        <?php if ($isAdmin || hasPermission('can_manage_clients')): ?>
            <button class="btn btn-primary" onclick="showAddClientModal()"><i class="fas fa-plus"></i> Add New Client</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert <?= strpos($message, 'Error') !== false ? 'alert-danger' : 'alert-success' ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body" style="overflow-x: auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 80px;">Logo</th>
                    <th>Client Name</th>
                    <th>City</th>
                    <th>Contact</th>
                    <th>Type</th>
                    <th style="text-align: center;">Active Projects</th>
                    <?php if ($isAdmin || hasPermission('can_manage_clients')): ?>
                        <th style="text-align: center;">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clients as $c): ?>
                <tr>
                    <td style="text-align: center;">
                        <?php if (!empty($c['logo_path'])): ?>
                            <img src="<?= htmlspecialchars($c['logo_path']) ?>" alt="Logo" style="height: 40px; max-width: 60px; object-fit: contain; border-radius: 4px;">
                        <?php else: ?>
                            <div style="height: 40px; width: 40px; background: #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: center; margin: 0 auto; color: #94a3b8; font-weight: bold;">
                                <?= strtoupper(substr($c['name'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight: 600; color: var(--primary-color);"><?= htmlspecialchars($c['name']) ?></td>
                    <td><?= htmlspecialchars($c['city']) ?></td>
                    <td><?= htmlspecialchars($c['contact']) ?></td>
                    <td><span class="badge <?= $c['type'] === 'in-house' ? 'badge-primary' : 'badge-warning' ?>"><?= htmlspecialchars($c['type']) ?></span></td>
                    <td style="text-align: center;"><span class="badge badge-success" style="font-size: 0.9rem; padding: 4px 10px; border-radius: 12px;"><?= $c['project_count'] ?></span></td>
                    
                    <?php if ($isAdmin || hasPermission('can_manage_clients')): ?>
                    <td style="text-align: center;">
                        <button class="btn btn-sm btn-info" onclick='showEditClientModal(<?= json_encode($c) ?>)' title="Edit Client"><i class="fas fa-edit"></i></button>
                        <?php if ($c['project_count'] == 0): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this client?');">
                            <input type="hidden" name="action" value="delete_client">
                            <input type="hidden" name="client_id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete Client"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($clients)): ?>
                    <tr><td colspan="<?= ($isAdmin || hasPermission('can_manage_clients')) ? 7 : 6 ?>" style="text-align: center; padding: 2rem; color: var(--text-muted);">No clients found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="addClientModal" class="modal" style="display:none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px);">
    <div class="modal-content" style="background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); position: relative;">
        <span class="close" onclick="hideAddClientModal()" style="position: absolute; right: 15px; top: 10px; cursor: pointer; font-size: 1.5rem; color: var(--text-muted);">&times;</span>
        <h2 style="color: var(--primary-color); margin-top: 0;">Add New Client</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_client">
            <div class="form-group"><label>Client Name:*</label><input type="text" name="name" required></div>
            <div class="form-group"><label>City:</label><input type="text" name="city"></div>
            <div class="form-group"><label>Contact Person:</label><input type="text" name="contact"></div>
            <div class="form-group"><label>Bank Name:</label><input type="text" name="bank_name" placeholder="e.g. Bank of Valletta"></div>
            <div class="form-group"><label>IBAN:</label><input type="text" name="iban" placeholder="e.g. MT1234..."></div>
            <div class="form-group"><label>SWIFT / BIC:</label><input type="text" name="swift_bic" placeholder="e.g. BOVLMXXX"></div>
            <div class="form-group">
                <label>Type:*</label>
                <select name="type" required><option value="in-house">In-House</option><option value="3rd-party">3rd Party</option></select>
            </div>
            <div class="form-group">
                <label>Company Logo (Optional)</label>
                <input type="file" name="client_logo" accept="image/*" class="form-control" style="padding: 5px;">
                <small style="color:var(--text-muted);">Max 2MB. Replaces old logo if exists.</small>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;"><i class="fas fa-save"></i> Save Client</button>
        </form>
    </div>
</div>

<div id="editClientModal" class="modal" style="display:none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px);">
    <div class="modal-content" style="background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); position: relative;">
        <span class="close" onclick="hideEditClientModal()" style="position: absolute; right: 15px; top: 10px; cursor: pointer; font-size: 1.5rem; color: var(--text-muted);">&times;</span>
        <h2 style="color: var(--primary-color); margin-top: 0;">Edit Client</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_client">
            <input type="hidden" name="client_id" id="edit_client_id">
            <div class="form-group"><label>Client Name:*</label><input type="text" name="name" id="edit_name" required></div>
            <div class="form-group"><label>City:</label><input type="text" name="city" id="edit_city"></div>
            <div class="form-group"><label>Contact Person:</label><input type="text" name="contact" id="edit_contact"></div>
            <div class="form-group"><label>Bank Name:</label><input type="text" name="bank_name" id="edit_bank_name"></div>
            <div class="form-group"><label>IBAN:</label><input type="text" name="iban" id="edit_iban"></div>
            <div class="form-group"><label>SWIFT / BIC:</label><input type="text" name="swift_bic" id="edit_swift_bic"></div>
            <div class="form-group">
                <label>Type:*</label>
                <select name="type" id="edit_type" required><option value="in-house">In-House</option><option value="3rd-party">3rd Party</option></select>
            </div>
            <div class="form-group">
                <label>Company Logo (Optional)</label>
                <input type="file" name="edit_client_logo" accept="image/*" class="form-control" style="padding: 5px;">
                <small style="color:var(--text-muted);">Upload a new file to replace the current logo.</small>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;"><i class="fas fa-save"></i> Update Client</button>
        </form>
    </div>
</div>

<script>
    function showAddClientModal() { document.getElementById('addClientModal').style.display = 'block'; }
    function hideAddClientModal() { document.getElementById('addClientModal').style.display = 'none'; }
    function showEditClientModal(client) {
        document.getElementById('edit_client_id').value = client.id;
        document.getElementById('edit_name').value = client.name;
        document.getElementById('edit_city').value = client.city || '';
        document.getElementById('edit_contact').value = client.contact || '';
        document.getElementById('edit_bank_name').value = client.bank_name || '';
        document.getElementById('edit_iban').value = client.iban || '';
        document.getElementById('edit_swift_bic').value = client.swift_bic || '';
        document.getElementById('edit_type').value = client.type;
        document.getElementById('editClientModal').style.display = 'block';
    }
    function hideEditClientModal() { document.getElementById('editClientModal').style.display = 'none'; }
</script>

<?php require_once 'footer.php'; ?>
