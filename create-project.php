<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['user'] !== 'admin') {
    header('Location: mobilization.php'); exit;
}
require_once 'config.php';

$pdo = getDB();
$message = '';

// CREATE
if ($_POST['action'] ?? '' === 'create') {
    $stmt = $pdo->prepare("INSERT INTO projects (name, client, city, pa_number, bca_status, type, finish_level, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['name'], $_POST['client'], $_POST['city'], 
        $_POST['pa_number'], $_POST['bca_status'],
        $_POST['type'], $_POST['type'] === 'in-house' ? $_POST['finish_level'] : null,
        $_POST['status'] ?? 'Pending'
    ]);
    $message = '✅ Project created successfully!';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Project - Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .form-container { background: white; padding: 3rem; border-radius: 25px; box-shadow: 0 30px 60px rgba(0,0,0,0.2); max-width: 700px; width: 100%; }
        .header { text-align: center; margin-bottom: 2rem; }
        .logo { width: 80px; margin-bottom: 1rem; }
        h1 { color: #1f77b4; margin-bottom: 0.5rem; }
        .message { padding: 1rem; border-radius: 12px; margin-bottom: 2rem; text-align: center; font-weight: 600; }
        .message.success { background: #d4edda; color: #155724; border: 2px solid #c3e6cb; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .form-group { display: flex; flex-direction: column; }
        label { font-weight: 600; color: #333; margin-bottom: 0.5rem; }
        input, select { padding: 1rem; border: 2px solid #e1e5e9; border-radius: 12px; font-size: 1rem; transition: border-color 0.3s; }
        input:focus, select:focus { outline: none; border-color: #1f77b4; box-shadow: 0 0 0 3px rgba(31,119,180,0.1); }
        .form-row { display: flex; gap: 1rem; }
        .form-row > * { flex: 1; }
        .btn { background: linear-gradient(135deg, #1f77b4, #155994); color: white; border: none; padding: 1rem 2.5rem; border-radius: 12px; font-size: 1.1rem; font-weight: 600; cursor: pointer; transition: all 0.3s; width: 100%; margin-top: 1rem; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(31,119,180,0.4); }
        .back-link { display: inline-block; margin-top: 1rem; color: #1f77b4; text-decoration: none; font-weight: 500; }
        .back-link:hover { text-decoration: underline; }
        @media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } .form-row { flex-direction: column; } }
    </style>
</head>
<body>
    <div class="form-container">
        <div class="header">
            <img src="logo.png" alt="Logo" class="logo" onerror="this.src='logo_icon.png'">
            <h1>➕ Create New Project</h1>
            <p style="color: #666;">Admin only - <?php echo date('F j, Y'); ?></p>
        </div>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <div class="form-group">
                    <label>Project Name *</label>
                    <input name="name" required>
                </div>
                <div class="form-group">
                    <label>Client *</label>
                    <input name="client" required>
                </div>
                <div class="form-group">
                    <label>City *</label>
                    <input name="city" required>
                </div>
                <div class="form-group">
                    <label>PA Number</label>
                    <input name="pa_number">
                </div>
                <div class="form-group">
                    <label>BCA Status</label>
                    <input name="bca_status">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Project Type *</label>
                        <select name="type" id="project-type" onchange="toggleFinishLevel()" required>
                            <option value="">Select Type</option>
                            <option value="in-house">🏠 In-House</option>
                            <option value="3rd-party">👥 3rd Party</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Initial Status</label>
                        <select name="status">
                            <option value="Pending">⏳ Pending</option>
                            <option value="In Process">🔄 In Process</option>
                            <option value="Mobilised">✅ Mobilised</option>
                        </select>
                    </div>
                </div>
                <div class="form-group" id="finish-level-group" style="display: none;">
                    <label>Finish Level *</label>
                    <select name="finish_level" id="finish-level" required>
                        <option value="">Select Finish Level</option>
                        <option value="Common Parts Only">🏗️ Common Parts Only</option>
                        <option value="Semi Finished">⚒️ Semi Finished</option>
                        <option value="Finished">✅ Finished</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn">🚀 Create Project</button>
        </form>
        
        <a href="mobilization.php" class="back-link">← Back to Dashboard</a>
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
