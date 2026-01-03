<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Insert project
        $stmt = $pdo->prepare("INSERT INTO projects (client_id, name, city, type, finish_level, mobilisation_status) 
                             VALUES (?, ?, ?, ?, ?, 'Not Started')");
        $stmt->execute([
            $_POST['client_id'],
            $_POST['name'],
            $_POST['city'],
            $_POST['type'],
            $_POST['type'] === 'in-house' ? $_POST['finish_level'] : null
        ]);
        
        $project_id = $pdo->lastInsertId();
        
        // Handle multiple PA numbers
        $pa_numbers = array_filter(array_map('trim', explode(',', $_POST['pa_numbers'] ?? '')));
        if (!empty($pa_numbers)) {
            $pa_stmt = $pdo->prepare("INSERT INTO pa_numbers (project_id, pa_number, pa_status) VALUES (?, ?, 'endorsed')");
            foreach ($pa_numbers as $pa_number) {
                $pa_stmt->execute([$project_id, $pa_number]);
            }
        }
        
        // Initialize mobilisation steps tracking
        $init_stmt = $pdo->prepare("INSERT INTO mobilisation_steps (project_id) VALUES (?)");
        $init_stmt->execute([$project_id]);
        
        $message = '✅ Project created successfully!';
    } catch (PDOException $e) {
        $error = 'Error creating project: ' . $e->getMessage();
    }
}

$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Project - Estate Hub</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="header">
        <div class="header-container">
            <div class="header-left">
                <img src="logo.jpg" alt="Estate Hub" class="logo-nav">
                <div>
                    <div class="header-title">Estate Hub</div>
                    <div class="header-subtitle">Project Management System</div>
                </div>
            </div>
            <div class="header-right">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="projects.php" class="nav-link">Projects</a>
                <a href="mobilization.php" class="nav-link">Mobilisation</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </div>

    <div class="main-container">
        <h1 class="page-title">Create New Project</h1>

        <?php if (!empty($message)): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
            <a href="projects.php" class="btn" style="max-width: 200px; margin-bottom: 2rem;">Back to Projects</a>
        <?php elseif (!empty($error)): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (empty($message)): ?>
            <div class="form-section">
                <form method="POST" class="form-grid">
                    <div style="grid-column: 1 / -1;">
                        <label class="form-group">
                            <span>Client *</span>
                            <select name="client_id" required>
                                <option value="">Select a client</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo $client['id']; ?>">
                                        <?php echo htmlspecialchars($client['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <label class="form-group">
                        <span>Project Name *</span>
                        <input type="text" name="name" required placeholder="Project name">
                    </label>

                    <label class="form-group">
                        <span>City *</span>
                        <input type="text" name="city" required placeholder="City">
                    </label>

                    <label class="form-group">
                        <span>Project Type *</span>
                        <select name="type" id="project-type" required>
                            <option value="">Select type</option>
                            <option value="in-house">In-House</option>
                            <option value="3rd-party">3rd Party</option>
                        </select>
                    </label>

                    <div id="finish-level-group" style="display: none;">
                        <label class="form-group">
                            <span>Finish Level</span>
                            <select name="finish_level">
                                <option value="">Select finish level</option>
                                <option value="Common Parts Only">Common Parts Only</option>
                                <option value="Semi Finished">Semi Finished</option>
                                <option value="Finished">Finished</option>
                            </select>
                        </label>
                    </div>

                    <div style="grid-column: 1 / -1;">
                        <label class="form-group">
                            <span>PA Numbers (comma-separated) *</span>
                            <input type="text" name="pa_numbers" required placeholder="e.g., PA/2024/001, PA/2024/002">
                            <small style="color: var(--text-muted); margin-top: 0.5rem; display: block;">
                                Enter one or more PA numbers separated by commas
                            </small>
                        </label>
                    </div>

                    <div style="grid-column: 1 / -1;">
                        <button type="submit" class="btn">Create Project</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.getElementById('project-type').addEventListener('change', function() {
            const finishLevelGroup = document.getElementById('finish-level-group');
            finishLevelGroup.style.display = this.value === 'in-house' ? 'block' : 'none';
        });
    </script>
</body>
</html>
