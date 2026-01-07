<?php
$pageTitle = 'Dashboard';
include 'header.php';

// Get current user
$userId = getCurrentUserId();
$userName = getCurrentUserFullName();
$userRole = getCurrentRole();
$isAdmin = isAdmin();

try {
    // Get all projects
    $sql = "SELECT * FROM projects ORDER BY name";
    $stmt = $pdo->query($sql);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all PA numbers grouped by project
    $paSql = "
        SELECT 
            pan.project_id,
            pan.pa_number,
            pan.pa_status,
            arch.name AS architect_name,
            se.name AS structural_engineer_name
        FROM project_pa_numbers pan
        LEFT JOIN professionals arch ON arch.id = pan.architect_id
        LEFT JOIN professionals se ON se.id = pan.structural_engineer_id
        ORDER BY pan.project_id, pan.id
    ";
    $paStmt = $pdo->query($paSql);
    $paNumbers = $paStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group PA numbers by project_id
    $paByProject = [];
    foreach ($paNumbers as $pa) {
        $paByProject[$pa['project_id']][] = $pa;
    }

    // Get stats
    $projectCount = count($projects);
    $userCount = $isAdmin ? $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;
    $mobilisedCount = $pdo->query("SELECT COUNT(*) FROM project_mobilisation WHERE bca_clearance = 'Yes'")->fetchColumn();

} catch (Exception $e) {
    $projects = [];
    $paByProject = [];
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
                <span></span>
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
                        <?php foreach ($projects as $project): ?>
                            <tr>
                                <td><?= htmlspecialchars($project['name']) ?></td>
                                <td><?= htmlspecialchars($project['city']) ?></td>
                                <td><?= htmlspecialchars($project['type']) ?></td>
                    
                                <?php
                                // Get all PA numbers for this project
                                $projectPAs = $paByProject[$project['id']] ?? [];
                                ?>
                                
                                <!-- PA Number Column -->
                                <td>
                                    <?php if (!empty($projectPAs)): ?>
                                        <?php foreach ($projectPAs as $index => $pa): ?>
                                            <?php 
                                            $paText = htmlspecialchars($pa['pa_number']);
                                            $paUrl = buildPaUrl($pa['pa_number']);
                                            ?>
                                            <?php if ($paUrl): ?>
                                                <a href="<?= htmlspecialchars($paUrl) ?>" 
                                                   target="_blank" 
                                                   rel="noopener noreferrer"
                                                   class="text-decoration-none">
                                                    <?= $paText ?>
                                                </a>
                                            <?php else: ?>
                                                <?= $paText ?>
                                            <?php endif; ?>
                                            <?php if ($index < count($projectPAs) - 1): ?>
                                                <br>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        TBC
                                    <?php endif; ?>
                                </td>
                                
                                <!-- PA Status Column -->
                                <td>
                                    <?php if (!empty($projectPAs)): ?>
                                        <?php foreach ($projectPAs as $index => $pa): ?>
                                            <?= htmlspecialchars($pa['pa_status']) ?>
                                            <?php if ($index < count($projectPAs) - 1): ?>
                                                <br>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        TBC
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Architect Column -->
                                <td>
                                    <?php if (!empty($projectPAs)): ?>
                                        <?php foreach ($projectPAs as $index => $pa): ?>
                                            <?= htmlspecialchars(!empty($pa['architect_name']) ? $pa['architect_name'] : 'TBC') ?>
                                            <?php if ($index < count($projectPAs) - 1): ?>
                                                <br>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        TBC
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Structural Engineer Column -->
                                <td>
                                    <?php if (!empty($projectPAs)): ?>
                                        <?php foreach ($projectPAs as $index => $pa): ?>
                                            <?= htmlspecialchars(!empty($pa['structural_engineer_name']) ? $pa['structural_engineer_name'] : 'TBC') ?>
                                            <?php if ($index < count($projectPAs) - 1): ?>
                                                <br>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        TBC
                                    <?php endif; ?>
                                </td>
                    
                                <td>
                                    <a href="mobilisation_detail.php?project_id=<?= $project['id'] ?>" class="btn btn-sm btn-primary">View</a>
                                    <?php if (hasRole('admin') || hasRole('manager')): ?>
                                        <a href="edit-project.php?id=<?= $project['id'] ?>" class="btn btn-sm" style="margin-left: 0.5rem;">Edit</a>
                                    <?php endif; ?>
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
