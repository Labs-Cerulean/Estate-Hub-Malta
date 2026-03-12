<?php
require_once 'init.php';
require_once 'session-check.php';

if (!hasPermission('can_manage_clients') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = '';

// Handle Image Upload Logic
function handleLogoUpload($fileInputName) {
    if (isset($_FILES[$fileInputName]) && $_FILES[$fileInputName]['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/logos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $fileExt = strtolower(pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
        
        if (in_array($fileExt, $allowedExts)) {
            $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9.-]/', '', basename($_FILES[$fileInputName]['name']));
            $destPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $destPath)) {
                return '/' . $destPath; // Return absolute path for display
            }
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create Client
    if ($action === 'create_client') {
        try {
            $logoPath = handleLogoUpload('client_logo');
            $stmt = $pdo->prepare("INSERT INTO clients (name, city, contact, type, logo_path) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([ $_POST['name'], $_POST['city'] ?? null, $_POST['contact'] ?? null, $_POST['type'], $logoPath ]);
            $clientId = $pdo->lastInsertId();
            autoAssignCreatorToClient($pdo, $clientId, getCurrentUserId());
            $message = 'Client created successfully!';
        } catch (PDOException $e) { $message = 'Error: Client name may already exist!'; }
    }
    // Edit Client (Added so you can upload logos to existing clients)
    elseif ($action === 'edit_client') {
        try {
            $clientId = $_POST['client_id'];
            $logoPath = handleLogoUpload('edit_client_logo');
            
            if ($logoPath) {
                $stmt = $pdo->prepare("UPDATE clients SET name=?, city=?, contact=?, type=?, logo_path=? WHERE id=?");
                $stmt->execute([ $_POST['name'], $_POST['city'] ?? null, $_POST['contact'] ?? null, $_POST['type'], $logoPath, $clientId ]);
            } else {
                $stmt = $pdo->prepare("UPDATE clients SET name=?, city=?, contact=?, type=? WHERE id=?");
                $stmt->execute([ $_POST['name'], $_POST['city'] ?? null, $_POST['contact'] ?? null, $_POST['type'], $clientId ]);
            }
            $message = 'Client updated successfully!';
        } catch (PDOException $e) { $message = 'Update error!'; }
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
                WHERE ((? IS NOT NULL AND ppn.architect_id IN (SELECT id FROM professionals WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?) AND role_type = 'architect'))
                    OR (? IS NOT NULL AND ppn.structural_engineer_id IN (SELECT id FROM professionals WHERE firm_name = (SELECT firm_name FROM professionals WHERE id = ?) AND role_type = 'structural_engineer'))
                ) GROUP BY c.id ORDER BY c.name
            ");
            $stmt->execute([ $user['assigned_architect_firm_id'], $user['assigned_architect_firm_id'], $user['assigned_structural_firm_id'], $user['assigned_structural_firm_id'] ]);
            $clients = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare("SELECT c.*, COUNT(DISTINCT p.id) as project_count FROM clients c INNER JOIN user_client_access uca ON c.id = uca.client_id LEFT JOIN projects p ON c.id = p.clientid WHERE uca.user_id = ? GROUP BY c.id ORDER BY c.name");
            $stmt->execute([$userId]);
            $clients = $stmt->fetchAll();
        }
    }
} catch (Exception $e) { $clients = []; }

$pageTitle = 'Client Management';
require_once 'header.php';
?>

<div class="main-container">
    <h1 class="page-title">Client Management</h1>
    <?php if ($message): ?><div class="alert alert-info"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    
    <div class="clients-section">
        <div class="clients-header">
            <h2 class="section-title">All Clients (<?= count($clients) ?>)</h2>
            <button onclick="showAddClientModal()" class="btn">+ Add New Client</button>
        </div>
        
        <?php if (count($clients) > 0): ?>
            <div class="clients-grid">
                <?php foreach ($clients as $client): ?>
                    <div class="client-card" style="position: relative; overflow: hidden;">
                        <?php if(!empty($client['logo_path'])): ?>
                            <img src="<?= htmlspecialchars($client['logo_path']) ?>" alt="Logo" style="position: absolute; top: 15px; right: 15px; max-height: 40px; max-width: 80px; opacity: 0.8; border-radius: 4px;">
                        <?php endif; ?>
                        
                        <h3 class="project-title"><?= htmlspecialchars($client['name']) ?></h3>
                        <div class="client-details">
                            <p><strong>City:</strong> <?= htmlspecialchars($client['city'] ?? 'N/A') ?></p>
                            <p><strong>Contact:</strong> <?= htmlspecialchars($client['contact'] ?? 'N/A') ?></p>
                            <p><strong>Type:</strong> <span class="badge badge-<?= $client['type'] ?>"><?= htmlspecialchars($client['type']) ?></span></p>
                            <p><strong>Projects:</strong> <?= $client['project_count'] ?></p>
                        </div>
                        <button onclick='openEditClientModal(<?= json_encode($client, JSON_HEX_APOS) ?>)' class="btn btn-sm btn-secondary" style="margin-top: 15px; width: 100%;">Edit & Upload Logo</button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state"><h3>No clients yet</h3><p>Add your first client to get started.</p></div>
        <?php endif; ?>
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
            <div class="form-group">
                <label>Type:*</label>
                <select name="type" required><option value="in-house">In-House</option><option value="3rd-party">3rd Party</option></select>
            </div>
            <div class="form-group">
                <label>Company Logo (Optional)</label>
                <input type="file" name="client_logo" accept="image/*" style="padding: 10px; border: 1px dashed var(--primary-color); width: 100%; border-radius: 6px;">
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Create Client</button>
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
            <div class="form-group">
                <label>Type:*</label>
                <select name="type" id="edit_type" required><option value="in-house">In-House</option><option value="3rd-party">3rd Party</option></select>
            </div>
            <div class="form-group">
                <label>Upload New Logo</label>
                <input type="file" name="edit_client_logo" accept="image/*" style="padding: 10px; border: 1px dashed var(--primary-color); width: 100%; border-radius: 6px;">
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 5px;">Leave blank to keep existing logo.</p>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Save Changes</button>
        </form>
    </div>
</div>

<script>
    function showAddClientModal() { document.getElementById('addClientModal').style.display = 'block'; }
    function hideAddClientModal() { document.getElementById('addClientModal').style.display = 'none'; }
    
    function openEditClientModal(client) {
        document.getElementById('edit_client_id').value = client.id;
        document.getElementById('edit_name').value = client.name;
        document.getElementById('edit_city').value = client.city || '';
        document.getElementById('edit_contact').value = client.contact || '';
        document.getElementById('edit_type').value = client.type;
        document.getElementById('editClientModal').style.display = 'block';
    }
    function hideEditClientModal() { document.getElementById('editClientModal').style.display = 'none'; }
</script>

<?php require_once 'footer.php'; ?>
