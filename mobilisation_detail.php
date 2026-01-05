<?php
// Replace the entire content of mobilisation_detail.php (file:16) with this updated version:

session_start();
if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] != true) {
    header('Location: index.php');
    exit;
}
if (!isset($_SESSION['userid']) || !is_numeric($_SESSION['userid'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

require_once 'config.php';
$pdo = getDB();

// Fetch ALL visible projects with their PA numbers and mobilisation data
$projects = getVisibleProjects($pdo);  // From user-functions.php[file:11]

foreach ($projects as &$project) {
    // Get PA numbers for this project
    $project['paNumbers'] = getProjectPANumbers($pdo, $project['id']);
    
    // Get mobilisation status
    $project['mobilisationStatus'] = deriveMobilisationStatus($pdo, $project['id']);
    
    // Get mobilisation data for progress calculation (optional)
    $mobStmt = $pdo->prepare("SELECT * FROM projectmobilisation WHERE projectid = ?");
    $mobStmt->execute([$project['id']]);
    $project['mob'] = $mobStmt->fetch();
}

unset($project);  // Break reference

// Handle PA status updates
$message = '';
if ($_POST['action'] ?? null == 'updatepa') {
    try {
        $paId = $_POST['paid'] ?? null;
        $paStatus = $_POST['pastatus'] ?? null;
        if ($paId && $paStatus) {
            $pdo->prepare('UPDATE projectpanumbers SET pastatus = ? WHERE id = ?')->execute([$paStatus, $paId]);
            $message = 'PA status updated!';
        }
        // Refresh data after update
        foreach ($projects as &$project) {
            $project['paNumbers'] = getProjectPANumbers($pdo, $project['id']);
            $project['mobilisationStatus'] = deriveMobilisationStatus($pdo, $project['id']);
        }
        unset($project);
    } catch (PDOException $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estate Hub Malta</title>
    <link rel="icon" href="logo.png">
    <link rel="stylesheet" href="styles.css">
    <style>
        .project-pa-list { margin-top: 1rem; padding: 1rem; background: rgba(255,255,255,0.05); border-radius: 8px; max-height: 200px; overflow-y: auto; }
        .pa-item { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .pa-item:last-child { border-bottom: none; }
        .pa-status { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 500; }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <img src="logo.png" alt="Estate Hub Malta" class="logo-nav" onerror="this.src='logo.jpg'">
                <div style="font-size: 1.4rem; font-weight: 700;">Estate Hub Malta</div>
            </div>
            <div class="header-right">
                <a href="mobilization.php" class="nav-link">Back</a>
                <a href="apilogout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </header>

    <div class="main-container">
        <h1 class="page-title">All Projects - Mobilisation Details</h1>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- **ONE CARD PER PROJECT** -->
        <?php if (!empty($projects)): ?>
            <section class="projects-section">
                <div class="section-title" style="margin-bottom: 1.5rem;">
                    Projects (<?php echo count($projects); ?>)
                </div>
                <div class="projects-grid" style="grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));">
                    <?php foreach ($projects as $project): ?>
                        <div class="card">
                            <div class="cardbody">
                                <!-- **Project-meta card (unique per project)** -->
                                <div class="project-meta" style="margin-bottom: 1rem;">
                                    <div class="meta-item">
                                        <span class="meta-label" style="font-weight: 600; font-size: 1.1rem;">
                                            <?php echo htmlspecialchars($project['name']); ?>
                                        </span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Client:</span>
                                        <span><?php echo htmlspecialchars($project['clientname'] ?? 'Unknown'); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Type:</span>
                                        <span><?php echo ucwords(str_replace('-', ' ', $project['type'])); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">City:</span>
                                        <span><?php echo htmlspecialchars($project['city']); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-label">Mobilisation:</span>
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $project['mobilisationStatus'])); ?>">
                                            <?php echo htmlspecialchars($project['mobilisationStatus']); ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- **PA Numbers List for this project** -->
                                <div class="project-pa-list">
                                    <?php if (!empty($project['paNumbers'])): ?>
                                        <?php foreach ($project['paNumbers'] as $pa): ?>
                                            <div class="pa-item">
                                                <span style="font-weight: 500;"><?php echo htmlspecialchars($pa['panumber']); ?></span>
                                                <form method="POST" style="display: inline;">
                                                    <select name="pastatus" onchange="this.form.submit()" style="font-size: 0.85rem; padding: 0.25rem;">
                                                        <option value="Endorsed" <?php echo ($pa['pastatus'] == 'Endorsed') ? 'selected' : ''; ?>>Endorsed</option>
                                                        <option value="Approved" <?php echo ($pa['pastatus'] == 'Approved') ? 'selected' : ''; ?>>Approved</option>
                                                        <option value="Fee Payment" <?php echo ($pa['pastatus'] == 'Fee Payment') ? 'selected' : ''; ?>>Fee Payment</option>
                                                        <option value="Not Approved" <?php echo ($pa['pastatus'] == 'Not Approved') ? 'selected' : ''; ?>>Not Approved</option>
                                                    </select>
                                                    <input type="hidden" name="action" value="updatepa">
                                                    <input type="hidden" name="paid" value="<?php echo $pa['id']; ?>">
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="text-align: center; color: var(--text-muted); font-style: italic;">No PA numbers</div>
                                    <?php endif; ?>
                                </div>

                                <!-- View Full Details -->
                                <a href="?projectid=<?php echo $project['id']; ?>" 
                                   class="btn" 
                                   style="width: 100%; margin-top: 1rem;">
                                   Full Project Details →
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php else: ?>
            <div style="text-align: center; padding: 4rem;">
                <h3>No projects found</h3>
                <p>You don't have access to any projects yet.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
