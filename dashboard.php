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
    $sql = "
            SELECT 
            p.*,
            pan.pa_number,
            pan.pa_status,
            arch.name AS architect_name,
            se.name AS structural_engineer_name
        FROM projects p
        LEFT JOIN project_pa_numbers pan 
            ON pan.project_id = p.id
            AND pan.id = (
                SELECT MIN(pan2.id)
                FROM project_pa_numbers pan2
                WHERE pan2.project_id = p.id
            )
        LEFT JOIN professionals arch
            ON arch.id = pan.architect_id
        LEFT JOIN professionals se
            ON se.id = pan.structural_engineer_id
        ORDER BY p.name
    ";
    
    $stmt = $pdo->query($sql);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
<?php
function buildPaUrl(?string $paNumber): ?string {
    if (empty($paNumber)) {
        return null;
    }

    // Expect format like "PA/04937/22"
    if (!preg_match('#^PA/(\d{4,5})/(\d{2})$#', trim($paNumber), $m)) {
        return null;
    }

    $caseNumber = $m[1];
    $caseYear   = $m[2];

    return "https://eapps.pa.org.mt/Case/CaseDetails?caseType=PA&casenumber={$caseNumber}&caseYear={$caseYear}";
}
?>
            <?php if (count($projects) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Project Name</th>
                            <th>City</th>
                            <th>Type</th>
                            <th>PA Number</th>
                            <th>PA Status</th>
                            <th>Architect</th>
                            <th>Structural Engineer</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($projects) > 0): ?>
                            <?php foreach ($projects as $project): ?>
                                <tr>
                                    <td><?= htmlspecialchars($project['name']) ?></td>
                                    <td><?= htmlspecialchars($project['city']) ?></td>
                                    <td><?= htmlspecialchars($project['type']) ?></td>
                        
                                    <?php
                                    $paText = !empty($project['pa_number']) ? $project['pa_number'] : 'TBC';
                                    $paUrl  = buildPaUrl($project['pa_number'] ?? null);
                                    ?>
                                    <td>
                                        <?php if ($paUrl): ?>
                                            <a href="<?= htmlspecialchars($paUrl) ?>" 
                                               target="_blank" 
                                               rel="noopener noreferrer"
                                               class="text-decoration-none">
                                                <?= htmlspecialchars($paText) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($paText) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars(!empty($project['pa_status']) ? $project['pa_status'] : 'TBC') ?></td>
                                    <td><?= htmlspecialchars(!empty($project['architect_name']) ? $project['architect_name'] : 'TBC') ?></td>
                                    <td><?= htmlspecialchars(!empty($project['structural_engineer_name']) ? $project['structural_engineer_name'] : 'TBC') ?></td>
                        
                                    <td>
                                        <a href="mobilisation_detail.php?project_id=<?= $project['id'] ?>" class="btn btn-sm btn-primary">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center">No projects yet.</td></tr>
                        <?php endif; ?>
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
