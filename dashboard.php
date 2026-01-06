<?php
$pageTitle = 'Dashboard';
include 'header.php';

// Get current user
$userId = getCurrentUserId();
$userName = getCurrentUserFullName();
$userRole = getCurrentRole();
$isAdmin = isAdmin();

try {
    // Get projects
    $stmt = $pdo->prepare("
    SELECT 
        p.*,
        c.name as clientname, 
        ppn.panumber,
        ppn.pastatus,
        arch.name as architect_name, 
        arch.firm_name as architect_firm,
        se.name as structural_name,
        se.firm_name as structural_firm
    FROM projects p
    LEFT JOIN clients c ON p.clientid = c.id
    LEFT JOIN project_pa_numbers ppn ON p.id = ppn.projectid
    LEFT JOIN professionals arch ON ppn.architect_id = arch.id
    LEFT JOIN professionals se ON ppn.structural_engineer_id = se.id
    ORDER BY p.name_at DESC
");
    $stmt->execute();
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
                        <th>Project</th>
                        <th>PA Number</th>
                        <th>Status</th>
                        <th>Architect</th>
                        <th>Engineer</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $project): ?>
                        <?php if (empty($project['panumber'])) continue; // Skip no-PA ?>
                        <tr>
                            <td><?php echo htmlspecialchars($project['name']); ?></td>
                            <td><?php echo htmlspecialchars($project['panumber']); ?></td>
                            <td><?php echo htmlspecialchars($project['pastatus']); ?></td>
                            <td>
                                <?php if ($project['architect_name']): ?>
                                    <?php echo htmlspecialchars($project['architect_name']); ?>
                                    <?php if ($project['architect_firm']): echo ' (' . htmlspecialchars($project['architect_firm']) . ')'; endif; ?>
                                <?php else: echo 'None'; endif; ?>
                            </td>
                            <td>
                                <?php if ($project['structural_name']): ?>
                                    <?php echo htmlspecialchars($project['structural_name']); ?>
                                    <?php if ($project['structural_firm']): echo ' (' . htmlspecialchars($project['structural_firm']) . ')'; endif; ?>
                                <?php else: echo 'None'; endif; ?>
                            </td>
                            <td>
                                <a href="mobilisation_detail.php?projectid=<?php echo $project['id']; ?>" class="action-btn">View</a>
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
