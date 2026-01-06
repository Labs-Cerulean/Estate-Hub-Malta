<?php
$pageTitle = 'Dashboard';
include 'header.php';

// Get current user
$userId = getCurrentUserId();
$userName = getCurrentUserFullName();
$userRole = hasRole();
$isAdmin = isAdmin();

try {
    // Get projects
    $stmt = $pdo->query("SELECT * FROM projects ORDER BY name");
    $projects = $stmt->fetchAll();
    
    // Get stats
    $projectCount = count($projects);
    $userCount = $isAdmin ? $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;
    $mobilisedCount = $pdo->query("SELECT COUNT(*) FROM project_mobilisation WHERE bca_clearance = 'Yes'")->fetchColumn();
    
} catch (Exception $e) {
    $projects = [];
    $projectCount = 0;
    $userCount = 0;
    $mobilisedCount = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Estate Hub</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div class="header-left">
                <img src="logo.png" alt="Estate Hub" class="logo-nav">
                <div>
                    <h1 class="header-title">Estate Hub</h1>
                    <p class="header-subtitle">Project Management System</p>
                </div>
            </div>
            <div class="header-right">
                <span class="header-title"><?php echo htmlspecialchars($userName); ?></span>
                <span class="header-subtitle"><?php echo htmlspecialchars($userRole); ?></span>
                <a href="api/logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </header>

    <nav style="border-bottom: 1px solid #e1e4e8; padding: 1rem 2rem;">
        <div style="max-width: 1440px; margin: 0 auto; display: flex; gap: 1rem;">
            <a href="dashboard.php" class="nav-link" style="background: #2563eb; color: white; border-color: #2563eb;">Dashboard</a>
            <a href="clients.php" class="nav-link">Clients</a>
            <a href="mobilization.php" class="nav-link">Mobilization</a>
            <?php if ($isAdmin): ?>
                <a href="users-management.php" class="nav-link">Users</a>
                <a href="create-project.php" class="nav-link">New Project</a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="main-container">
        <h1 class="page-title">Dashboard</h1>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $projectCount; ?></div>
                <div class="stat-label">Total Projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-number mobilised"><?php echo $mobilisedCount; ?></div>
                <div class="stat-label">Mobilised</div>
            </div>
            <?php if ($isAdmin): ?>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $userCount; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
            <?php endif; ?>
        </div>

        <div class="projects-section">
            <div class="projects-header">
                <h2 class="section-title">Projects</h2>
                <?php if ($isAdmin): ?>
                    <a href="create-project.php" class="btn">Add Project</a>
                <?php endif; ?>
            </div>

            <?php if (count($projects) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Project Name</th>
                            <th>City</th>
                            <th>Type</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $project): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($project['name']); ?></td>
                                <td><?php echo htmlspecialchars($project['city']); ?></td>
                                <td><?php echo htmlspecialchars($project['type']); ?></td>
                                <td>
                                    <a href="mobilisation_detail.php?project_id=<?php echo $project['id']; ?>" class="action-btn view">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <p>No projects yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
