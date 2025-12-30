<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['user'] !== 'admin') {
    header('Location: mobilization.php'); exit;
}
require_once 'config.php';

$pdo = getDB();
$message = '';

// CREATE PROJECT
if ($_POST['action'] ?? '' === 'create') {
    $stmt = $pdo->prepare("INSERT INTO projects (client_id, name, city, pa_number, bca_status, type, finish_level, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['client_id'], $_POST['name'], $_POST['city'], 
        $_POST['pa_number'], $_POST['bca_status'],
        $_POST['type'], $_POST['type'] === 'in-house' ? $_POST['finish_level'] : null,
        $_POST['status'] ?? 'Pending'
    ]);
    $message = '✅ Project created successfully!';
}

$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Project - Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <img src="logo.png" alt="Estate Hub Malta" class="logo-nav" onerror="this.src='logo_icon.png'">
                <div style="font-size: 1.4rem; font-weight: 700;">Estate Hub Malta</div>
            </div>
            <div class="header-right">
                <a href="mobilization.php" class="nav-link">📊 Dashboard</a>
                <a href="clients.php" class="nav-link">👥 Clients</a>
                <a href="api/auth.php?logout=1" class="nav-link">🚪 Logout</a>
            </div>
        </div>
    </header>
    
    <div class="main-container">
        <h1 class="page-title">Create New Project</h1>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <section class="form-section">
            <form method="POST" class="form-grid">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>Client *</label>
                    <select name="client_id" required>
                        <option value="">Select Client</option>
                        <?php foreach ($clients as $client): ?>
                        <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Project Name *</label>
                    <input name="name" placeholder="Project name" required>
                </div>
                <div class="form-group">
                    <label>City *</label>
                    <input name="city" placeholder="City" required>
                </div>
                <div class="form-group">
                    <label>PA Number</label>
                    <input name="pa_number" placeholder="PA Number">
                </div>
                <div class="form-group">
                    <label>BCA Status</label>
                    <input name="bca_status" placeholder="BCA Status">
                </div>
                <div class="form-group">
                    <label>Project Type *</label>
                    <select name="type" id="project-type" onchange="toggleFinishLevel()" required>
                        <option value="">Select Type</option>
                        <option value="in-house">🏠 In-House</option>
                        <option value="3rd-party">👥 3rd Party</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="Pending">⏳ Pending</option>
                        <option value="In Process">🔄 In Process</option>
                        <option value="Mobilised">✅ Mobilised</option>
                    </select>
                </div>
                <div class="form-group" id="finish-level-group" style="display: none;">
                    <label>Finish Level *</label>
                    <select name="finish_level" id="finish-level">
                        <option value="">Select Finish Level</option>
                        <option value="Common Parts Only">🏗️ Common Parts Only</option>
                        <option value="Semi Finished">⚒️ Semi Finished</option>
                        <option value="Finished">✅ Finished</option>
                    </select>
                </div>
                <button type="submit" class="btn" style="grid-column: 1 / -1; padding: 1.25rem; font-size: 1.1rem;">🚀 Create Project</button>
            </form>
        </section>
        
        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <a href="mobilization.php" class="nav-link" style="padding: 1rem 2rem; flex: 1; text-align: center;">← Back to Dashboard</a>
        </div>
    </div>

    <script>
        function toggleFinishLevel() {
            const type = document.getElementById('project-type').value;
            const finishGroup = document.getElementById('finish-level-group');
            const finishLevel = document.getElementById('finish-level');
            if (type === 'in-house') {
                finishGroup.style.display = 'block';
                finishLevel.required = true;
            } else {
                finishGroup.style.display = 'none';
                finishLevel.required = false;
                finishLevel.value = '';
            }
        }
    </script>
</body>
</html>
