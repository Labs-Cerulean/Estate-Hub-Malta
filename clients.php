<?php
$pageTitle = 'Clients';
include 'header.php';
$pdo = getDB();
$message = '';

// CREATE CLIENT
if (($_POST['action'] ?? null) === 'create') {
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
    $message = 'Client created successfully!';
  } catch (PDOException $e) {
    $message = 'Client name already exists!';
  }
}

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
            // Other users see assigned clients
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Clients – Estate Hub Malta</title>
  <link rel="icon" href="logo.png">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
 

  <div class="main-container">
    <h1 class="page-title">Client Management</h1>

    <?php if ($message): ?>
      <div class="message success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Clients List -->
    <section class="projects-section">
      <div class="projects-header">
        <div class="section-title">All Clients (<?php echo count($clients); ?>)</div>
        <button class="btn" onclick="document.getElementById('create-form').scrollIntoView({behavior: 'smooth'})" style="padding: 0.75rem 2rem;">
          + Add Client
        </button>
      </div>

      <div class="projects-grid">
        <?php foreach ($clients as $client): ?>
          <div class="project-card">
            <div class="project-header">
              <div class="project-name"><?php echo htmlspecialchars($client['name']); ?></div>
              <span class="client-type">
                <?php echo ucwords(str_replace('-', ' ', $client['type'])); ?>
              </span>
            </div>

            <div class="project-meta">
              <?php if ($client['city']): ?>
                <div class="meta-item">
                  <span class="meta-label">City</span>
                  <span><?php echo htmlspecialchars($client['city']); ?></span>
                </div>
              <?php endif; ?>

              <?php if ($client['contact']): ?>
                <div class="meta-item">
                  <span class="meta-label">Contact</span>
                  <span><?php echo htmlspecialchars($client['contact']); ?></span>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if (empty($clients)): ?>
          <div class="empty-state" style="grid-column: 1/-1;">
            <h3 style="font-size: 1.5rem; margin-bottom: 1rem;">No clients yet</h3>
            <p style="font-size: 1.1rem; margin-bottom: 2rem;">Add your first client to get started.</p>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- Add Client Form -->
    <section id="create-form" class="form-section">
      <div class="section-title" style="margin-bottom: 1.5rem;">Add New Client</div>
      
      <form method="POST" class="form-grid">
        <input type="hidden" name="action" value="create">

        <div class="form-group">
          <label>Client Name</label>
          <input type="text" name="name" placeholder="Enter client name" required>
        </div>

        <div class="form-group">
          <label>City</label>
          <input type="text" name="city" placeholder="Enter city">
        </div>

        <div class="form-group">
          <label>Contact</label>
          <input type="text" name="contact" placeholder="Contact person / phone">
        </div>

        <div class="form-group">
          <label>Type</label>
          <select name="type" required>
            <option value="3rd-party">3rd Party</option>
            <option value="in-house">In-House</option>
          </select>
        </div>

        <button type="submit" class="btn" style="grid-column: 1 / -1; padding: 1.25rem; font-size: 1.1rem;">
          Create Client
        </button>
      </form>
    </section>

  </div>
</body>
</html>
