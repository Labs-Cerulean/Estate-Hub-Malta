<?php
require_once 'init.php';
require_once 'session-check.php';

// Check if user has permission to manage clients (admin or manager)
if (!hasPermission('can_manage_clients')) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$message = '';

// Handle client creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_client') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO clients (name, city, contact, type)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['name'],
            $_POST['city'] ?? null,
            $_POST['contact'] ?? null,
            $_POST['type']
        ]);
        
      $clientId = $pdo->lastInsertId();
        
        // Auto-assign creator to client (Pass the current user ID as the 3rd argument)
        autoAssignCreatorToClient($pdo, $clientId, getCurrentUserId());
    
        
        $message = 'Client created successfully! You now have access to this client.';
    } catch (PDOException $e) {
        $message = 'Client name already exists!';
    }
}

// Get current user
$userId = getCurrentUserId();
$isAdmin = isAdmin();

try {
    if ($isAdmin) {
        // Admins see all clients
        $clients = $pdo->query("
            SELECT c.*, COUNT(p.id) as project_count
            FROM clients c
            LEFT JOIN projects p ON c.id = p.clientid
            GROUP BY c.id
            ORDER BY c.name
        ")->fetchAll();
    } else {
        // Non-admins see only assigned clients
        $user = getUserById($pdo, $userId);
        
        if ($user['role'] === 'architect') {
            // Architects see clients from their projects
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.*, COUNT(DISTINCT p.id) as project_count
                FROM clients c
                INNER JOIN projects p ON c.id = p.clientid
                INNER JOIN project_pa_numbers ppn ON p.id = ppn.project_id
                WHERE (
                    (? IS NOT NULL AND ppn.architect_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (
                            SELECT firm_name FROM professionals WHERE id = ?
                        ) AND role_type = 'architect'
                    ))
                    OR
                    (? IS NOT NULL AND ppn.structural_engineer_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (
                            SELECT firm_name FROM professionals WHERE id = ?
                        ) AND role_type = 'structural_engineer'
                    ))
                )
                GROUP BY c.id
                ORDER BY c.name
            ");
            $stmt->execute([
                $user['assigned_architect_firm_id'],
                $user['assigned_architect_firm_id'],
                $user['assigned_structural_firm_id'],
                $user['assigned_structural_firm_id']
            ]);
            $clients = $stmt->fetchAll();
        } else {
            // Managers and other users see assigned clients
            $stmt = $pdo->prepare("
                SELECT c.*, COUNT(DISTINCT p.id) as project_count
                FROM clients c
                INNER JOIN user_client_access uca ON c.id = uca.client_id
                LEFT JOIN projects p ON c.id = p.clientid
                WHERE uca.user_id = ?
                GROUP BY c.id
                ORDER BY c.name
            ");
            $stmt->execute([$userId]);
            $clients = $stmt->fetchAll();
        }
    }
} catch (Exception $e) {
    $clients = [];
}

// Set page title
$pageTitle = 'Client Management';

// Now output HTML
require_once 'header.php';
?>


    <div class="main-container">
        <h1 class="page-title">Client Management</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <div class="clients-section">
            <div class="clients-header">
                <h2 class="section-title">All Clients (<?= count($clients) ?>)</h2>
                <button onclick="showAddClientModal()" class="btn">Add New Client</button>
            </div>
            
            <?php if (count($clients) > 0): ?>
                <div class="clients-grid">
                    <?php foreach ($clients as $client): ?>
                        <div class="client-card">
                            <h3 class="project-title"><?= htmlspecialchars($client['name']) ?></h3>
                            <div class="client-details">
                                <p><strong>City:</strong> <?= htmlspecialchars($client['city'] ?? 'N/A') ?></p>
                                <p><strong>Contact:</strong> <?= htmlspecialchars($client['contact'] ?? 'N/A') ?></p>
                                <p><strong>Type:</strong> <span class="badge badge-<?= $client['type'] ?>"><?= htmlspecialchars($client['type']) ?></span></p>
                                <p><strong>Projects:</strong> <?= $client['project_count'] ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No clients yet</h3>
                    <p>Add your first client to get started.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Add Client Modal -->
    <div id="addClientModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close" onclick="hideAddClientModal()">&times;</span>
            <h2>Add New Client</h2>
            
            <form method="POST">
                <input type="hidden" name="action" value="create_client">
                
                <div class="form-group">
                    <label>Client Name:*</label>
                    <input type="text" name="name" required>
                </div>
                
                <div class="form-group">
                    <label>City:</label>
                    <input type="text" name="city">
                </div>
                
                <div class="form-group">
                    <label>Contact Person:</label>
                    <input type="text" name="contact">
                </div>
                
                <div class="form-group">
                    <label>Type:*</label>
                    <select name="type" required>
                        <option value="in-house">In-House</option>
                        <option value="3rd-party">3rd Party</option>
                    </select>
                </div>
                
                <?php if (!$isAdmin): ?>
                    <p class="info-text">Note: You will automatically be assigned to this client and have access to all its projects.</p>
                <?php endif; ?>
                
                <button type="submit" class="btn btn-primary">Create Client</button>
            </form>
        </div>
    </div>
    
    <script>
        function showAddClientModal() {
            document.getElementById('addClientModal').style.display = 'block';
        }
        
        function hideAddClientModal() {
            document.getElementById('addClientModal').style.display = 'none';
        }
    </script>

<?php require_once 'footer.php'; ?>
