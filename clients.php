<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['user'] !== 'admin') {
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

// READ ALL
$clients = $pdo->query("SELECT * FROM clients ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clients - Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Same professional Agius-style CSS as mobilization.php */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #0a0e17; color: #ffffff; }
        .header, .main-container, .stats-grid, .stat-card { /* Same as above */ }
        .clients-section { background: rgba(255,255,255,0.02); border-radius: 24px; padding: 3rem; backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.08); margin-bottom: 3rem; }
        .clients-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 1.5rem; }
        .client-card { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 2rem; }
        .client-name { font-size: 1.3rem; font-weight: 600; margin-bottom: 1rem; }
        .client-meta { display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.95rem; color: rgba(255,255,255,0.8); }
        .client-type { padding: 0.25rem 0.75rem; background: rgba(255,255,255,0.1); border-radius: 12px; font-size: 0.8rem; width: fit-content; }
        .create-client-form { background: rgba(255,255,255,0.05); border-radius: 20px; padding: 2.5rem; border: 1px solid rgba(255,255,255,0.1); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; }
        input, select { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: 12px; padding: 1rem; color: #ffffff; width: 100%; backdrop-filter: blur(10px); }
        input::placeholder { color: rgba(255,255,255,0.5); }
        input:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,0.2); }
        .btn { background: linear-gradient(135deg, #4f46e5, #7c3aed); color: white; border: none; padding: 1rem 2.5rem; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(79,70,229,0.4); }
    </style>
</head>
<body>
    <!-- Header same as mobilization.php -->
    <div class="main-container">
        <h1 class="page-title">Client Management</h1>
        
        <section class="clients-section">
            <div class="projects-header">
                <div class="section-title">All Clients (<?php echo count($clients); ?>)</div>
                <button class="btn" onclick="document.getElementById('create-form').scrollIntoView()">➕ Add Client</button>
            </div>
            
            <?php if ($message): ?>
                <div style="padding: 1rem 2rem; background: rgba(34,197,94,0.2); border: 1px solid rgba(34,197,94,0.3); border-radius: 12px; margin-bottom: 2rem; color: #22c55e;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <div class="clients-grid">
                <?php foreach ($clients as $client): ?>
                <div class="client-card">
                    <div class="client-name"><?php echo htmlspecialchars($client['name']); ?></div>
                    <div class="client-meta">
                        <div><?php echo htmlspecialchars($client['city'] ?? 'N/A'); ?></div>
                        <?php if ($client['contact']): ?>
                        <div>📞 <?php echo htmlspecialchars($client['contact']); ?></div>
                        <?php endif; ?>
                        <div class="client-type"><?php echo ucwords(str_replace('-', ' ', $client['type'])); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        
        <section id="create-form" class="clients-section">
            <h2 style="font-size: 1.8rem; margin-bottom: 2rem;">➕ Add New Client</h2>
            <form method="POST" class="create-client-form">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div>
                        <input name="name" placeholder="Client Name *" required>
                    </div>
                    <div>
                        <input name="city" placeholder="City">
                    </div>
                    <div>
                        <input name="contact" placeholder="Contact Person / Phone">
                    </div>
                    <div>
                        <select name="type" required>
                            <option value="3rd-party">👥 3rd Party</option>
                            <option value="in-house">🏠 In-House</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn" style="width: 100%; padding: 1.25rem; font-size: 1.1rem; margin-top: 2rem;">Create Client</button>
            </form>
        </section>
    </div>
</body>
</html>

