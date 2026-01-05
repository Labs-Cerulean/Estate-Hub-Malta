<?php
session_start();
require_once 'config.php';

// Handle project deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->execute([$_POST['project_id']]);
        $success_message = '✅ Project deleted successfully!';
    } catch (PDOException $e) {
        $error_message = 'Error deleting project: ' . $e->getMessage();
    }
}

// Fetch all projects with their PA numbers and counts
$projects = $pdo->query("
    SELECT p.*, c.name as client_name, c.type as client_type,
           COUNT(DISTINCT pa.id) as pa_count,
           GROUP_CONCAT(pa.pa_number ORDER BY pa.pa_number SEPARATOR ', ') as pa_numbers
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN pa_numbers pa ON p.id = pa.project_id
    GROUP BY p.id
    ORDER BY p.created_at DESC
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projects - Estate Hub</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .pa-numbers-list {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: rgba(79, 70, 229, 0.1);
            border-radius: 8px;
            border-left: 3px solid var(--accent-blue);
        }

        .pa-badge {
            display: inline-block;
            background: var(--accent-blue);
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            margin: 0.25rem 0.25rem 0.25rem 0;
        }

        .project-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            flex: 1;
            text-align: center;
        }

        .btn-mobilise {
            background: var(--accent-blue) !important;
        }

        .btn-delete {
            background: rgba(239, 68, 68, 0.2) !important;
            color: #ef4444;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-container">
            <div class="header-left">
                <img src="logo.jpg" alt="Estate Hub" class="logo-nav">
                <div>
                    <div class="header-title">Estate Hub</div>
                    <div class="header-subtitle">Project Management</div>
                </div>
            </div>
            <div class="header-right">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="projects.php" class="nav-link" style="background: var(--accent-blue);">Projects</a>
                <a href="mobilization.php" class="nav-link">Mobilisation</a>
                <a href="create-project.php" class="nav-link">+ New Project</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </div>

    <div class="main-container">
        <h1 class="page-title">Projects</h1>

        <?php if (!empty($success_message)): ?>
            <div class="message success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php elseif (!empty($error_message)): ?>
            <div class="message error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="projects-section">
            <div class="projects-header">
                <h2 class="section-title">All Projects (<?php echo count($projects); ?>)</h2>
                <a href="create-project.php" class="btn" style="max-width: 200px;">+ Create Project</a>
            </div>

            <?php if (count($projects) === 0): ?>
                <div class="empty-state">
                    <h3>No projects yet</h3>
                    <p>Get started by creating your first project.</p>
                    <a href="create-project.php" class="btn" style="max-width: 200px;">Create First Project</a>
                </div>
            <?php else: ?>
                <div class="projects-grid">
                    <?php foreach ($projects as $project): ?>
                        <div class="project-card">
                            <div class="project-header">
                                <h3 class="project-name"><?php echo htmlspecialchars($project['name']); ?></h3>
                                <span class="status-badge status-<?php echo $project['mobilisation_status']; ?>">
                                    <?php echo htmlspecialchars($project['mobilisation_status']); ?>
                                </span>
                            </div>

                            <div class="project-meta">
                                <div class="meta-item">
                                    <span class="meta-label">Client</span>
                                    <?php echo htmlspecialchars($project['client_name'] ?? 'N/A'); ?>
                                </div>
                                <div class="meta-item">
                                    <span class="meta-label">City</span>
                                    <?php echo htmlspecialchars($project['city']); ?>
                                </div>
                                <div class="meta-item">
                                    <span class="meta-label">Type</span>
                                    <span class="client-type"><?php echo ucfirst(str_replace('-', ' ', $project['type'])); ?></span>
                                </div>
                                <?php if ($project['type'] === 'in-house' && $project['finish_level']): ?>
                                    <div class="meta-item">
                                        <span class="meta-label">Finish Level</span>
                                        <?php echo htmlspecialchars($project['finish_level']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- PA Numbers Display -->
                            <?php if ($project['pa_numbers']): ?>
                                <div class="pa-numbers-list">
                                    <strong style="display: block; margin-bottom: 0.5rem; color: var(--text-primary);">
                                        📋 PA Numbers (<?php echo $project['pa_count']; ?>)
                                    </strong>
                                    <?php foreach (explode(', ', $project['pa_numbers']) as $pa): ?>
                                        <span class="pa-badge"><?php echo htmlspecialchars($pa); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="project-actions">
                                <a href="mobilization.php?project_id=<?php echo $project['id']; ?>" class="btn btn-small btn-mobilise">
                                    📊 Track Mobilisation
                                </a>
                                <form method="POST" style="flex: 1;">
                                    <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                    <button type="submit" name="delete_project" value="1" class="btn btn-small btn-delete" 
                                            onclick="return confirm('Are you sure you want to delete this project?');">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
