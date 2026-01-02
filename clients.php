<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['user'] !== 'admin') {
    header('Location: mobilization.php'); exit;
}
require_once 'config.php';

$pdo = getDB();
$message = '';

// CREATE CLIENT
if ($_POST['action'] ?? '' === 'create') {
    try {
        $stmt = $pdo->prepare("INSERT INTO clients (name, city, contact, type) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['city'], $_POST['contact'], $_POST['type']]);
        $message = '✅ Client created successfully!';
    } catch (PDOException $e) {
        $message = '❌ Client name already exists!';
    }
}

$clients = $pdo->query("SELECT * FROM clients ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clients - Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <img src="logo.png" alt="Estate Hub Malta" class="logo-nav" onerror="this.src='logo_icon.png'">
                <div>
                    <div style="font-size: 1.4rem; font-weight: 700;">Estate Hub Malta</div>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">Client Management</div>
                </div>
            </div>
            <div class="header-right">
                <a href="mobilization.php" class="nav-link">📊 Dashboard</a>
                <a href="create-project.php" class="nav-link">➕ Projects</a>
                <a href="api/auth.php?logout=1" class="nav-link">🚪 Logout</a>
            </div>
        </div>
    </header>
    
    <div class="main-container">
        <h1 class="page-title">Client Management</h1>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <section class="clients-section">
            <div class="projects-header">
                <div class="section-title">All Clients (<?php echo count($clients); ?>)</div>
                <button class="btn" onclick="document.getElementById('create-form').scrollIntoView({behavior: 'smooth'})" style="padding: 0.75rem 2rem;">➕ Add Client</button>
            </div>
            
            <div class="clients-grid">
                <?php foreach ($clients as $client): ?>
                <div class="client-card">
                    <div class="client-header">
                        <div class="client-name"><?php echo htmlspecialchars($client['name']); ?></div>
                        <span class="client-type"><?php echo ucwords(str_replace('-', ' ', $client['type'])); ?></span>
                    </div>
                    <div class="client-meta">
                        <?php if ($client['city']): ?>
                        <div class="meta-item">
                            <span class="meta-label">City</span>
                            <?php echo htmlspecialchars($client['city']); ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($client['contact']): ?>
                        <div class="meta-item">
                            <span class="meta-label">Contact</span>
                            <?php echo htmlspecialchars($client['contact']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($clients)): ?>
                <div class="empty-state">
                    <h3 style="font-size: 1.5rem; margin-bottom: 1rem;">No clients yet</h3>
                    <p style="font-size: 1.1rem; margin-bottom: 2rem;">Add your first client to get started.</p>
                </div>
                <?php endif; ?>
            </div>
        </section>
        
        <section id="create-form" class="form-section">
            <div class="section-title">➕ Add New Client</div>
            <form method="POST" class="form-grid">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>Client Name *</label>
                    <input name="name" placeholder="Enter client name" required>
                </div>
                <div class="form-group">
                    <label>City</label>
                    <input name="city" placeholder="Enter city">
                </div>
                <div class="form-group">
                    <label>Contact</label>
                    <input name="contact" placeholder="Contact person / phone">
                </div>
                <div class="form-group">
                    <label>Type *</label>
                    <select name="type" required>
                        <option value="3rd-party">👥 3rd Party</option>
                        <option value="in-house">🏠 In-House</option>
                    </select>
                </div>
                <button type="submit" class="btn" style="grid-column: 1 / -1; padding: 1.25rem; font-size: 1.1rem;">✅ Create Client</button>
            </form>
        </section>
    </div>
</body>
</html>
