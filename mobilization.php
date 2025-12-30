<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: index.php'); exit;
}
require_once 'config.php';

$pdo = getDB();
$message = '';

// CREATE
if ($_POST['action'] ?? '' === 'create') {
    $stmt = $pdo->prepare("INSERT INTO projects (name, client, city, pa_number, bca_status, type, finish_level) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['name'], $_POST['client'], $_POST['city'], 
        $_POST['pa_number'], $_POST['bca_status'],
        $_POST['type'], $_POST['type'] === 'in-house' ? $_POST['finish_level'] : null
    ]);
    $message = '✅ Project created!';
}

// READ
$projects = $pdo->query("SELECT * FROM projects ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mobilization - Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .header { background: linear-gradient(135deg, #1f77b4, #155994); color: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .logo-nav { width: 50px; }
        .nav-link { color: white; text-decoration: none; padding: 0.5rem 1rem; margin-left: 1rem; background: rgba(255,255,255,0.2); border-radius: 5px; }
        .nav-link:hover { background: rgba(255,255,255,0.3); }
        .container { max-width: 1400px; margin: 2rem auto; padding: 0 2rem; }
        h1 { color: #1f77b4; margin-bottom: 1rem; }
        .message { padding: 1rem; border-radius: 8px; margin-bottom: 2rem; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .create-form { background: white; padding: 2rem; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
        input, select { width: 100%; padding: 0.75rem; border: 2px solid #e1e5e9; border-radius: 8px; font-size: 1rem; }
        input:focus, select:focus { outline: none; border-color: #1f77b4; }
        .form-row { display: flex; gap: 1rem; }
        .form-row > * { flex: 1; }
        button { background: #1f77b4; color: white; border: none; padding: 0.75rem 2rem; border-radius: 8px; cursor: pointer; font-size: 1rem; transition: background 0.3s; }
        button:hover { background: #155994; }
        .metrics, table { background: white; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 2rem; }
        .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; padding: 2rem; }
        .metric-value { font-size: 2.5rem; font-weight: bold; color: #1f77b4; }
        .metric-label { color: #666; font-size: 0.9rem; text-transform: uppercase; }
        table { width: 100%; }
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; color: #333; }
        .status { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 500; }
        .status.Mobilised { background: #d4edda; color: #155724; }
        .status.Pending { background: #fff3cd; color: #856404; }
        .status['In Process'] { background: #cce5ff; color: #004085; }
        .type-badge { padding: 0.25rem 0.5rem; border-radius: 12px; font-size: 0.75rem; font-weight: 500; }
        .type.in-house { background: #d1ecf1; color: #0c5460; }
        .type['3rd-party'] { background: #f8d7da; color: #721c24; }
        @media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } .form-row { flex-direction: column; } }
    </style>
</head>
<body>
    <div class="header">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <img src="logo.png" alt="Logo" class="logo-nav" onerror="this.src='logo_icon.png'">
            <h2>Estate Hub Malta</h2>
        </div>
        <div>
            <a href="dashboard.php" class="nav-link">📊 Dashboard</a>
            <a href="api/auth.php?logout=1" class="nav-link">🚪 Logout</a>
        </div>
    </div>
    
    <div class="container">
        <h1>🚧 Mobilization Tracker</h1>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <div class="create-form">
            <h3>➕ Create New Project</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="form-row">
                        <input name="name" placeholder="Project Name *" required>
                        <input name="client" placeholder="Client *" required>
                    </div>
                    <div class="form-row">
                        <input name="city" placeholder="City *" required>
                        <input name="pa_number" placeholder="PA Number">
                    </div>
                    <input name="bca_status" placeholder="BCA Status">
                    
                    <div style="display: flex; gap: 1rem;">
                        <select name="type" id="project-type" required onchange="toggleFinishLevel()">
                            <option value="">Project Type *</option>
                            <option value="in-house">🏠 In-House</option>
                            <option value="3rd-party">👥 3rd Party</option>
                        </select>
                        <select name="finish_level" id="finish-level" style="display: none;">
                            <option value="">Finish Level</option>
                            <option value="Common Parts Only">Common Parts Only</option>
                            <option value="Semi Finished">Semi Finished</option>
                            <option value="Finished">Finished</option>
                        </select>
                    </div>
                </div>
                <button type="submit" style="margin-top: 1.5rem;">✅ Create Project</button>
            </form>
        </div>
        
        <div class="metrics">
            <div class="metric-card">
                <div class="metric-value"><?php echo count($projects); ?></div>
                <div class="metric-label">Total Projects</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?php echo count(array_filter($projects, fn($p) => $p['status'] === 'Mobilised')); ?></div>
                <div class="metric-label">✅ Mobilised</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?php echo count(array_filter($projects, fn($p) => $p['status'] === 'Pending')); ?></div>
                <div class="metric-label">⏳ Pending</div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Client</th>
                    <th>City</th>
                    <th>PA #</th>
                    <th>BCA</th>
                    <th>Type</th>
                    <th>Finish</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $project): ?>
                <tr>
                    <td><?php echo htmlspecialchars($project['name']); ?></td>
                    <td><?php echo htmlspecialchars($project['client']); ?></td>
                    <td><?php echo htmlspecialchars($project['city']); ?></td>
                    <td><?php echo htmlspecialchars($project['pa_number'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($project['bca_status'] ?? ''); ?></td>
                    <td><span class="type-badge type <?php echo $project['type']; ?>"><?php echo ucwords(str_replace('-', ' ', $project['type'])); ?></span></td>
                    <td><?php echo $project['finish_level'] ?? ''; ?></td>
                    <td><span class="status <?php echo $project['status']; ?>"><?php echo $project['status']; ?></span></td>
                    <td><?php echo date('M j', strtotime($project['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
        function toggleFinishLevel() {
            const type = document.getElementById('project-type').value;
            const finishLevel = document.getElementById('finish-level');
            finishLevel.style.display = type === 'in-house' ? 'block' : 'none';
            finishLevel.required = type === 'in-house';
        }
    </script>
</body>
</html>
