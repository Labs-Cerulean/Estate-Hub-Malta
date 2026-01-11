<?php
require_once 'init.php';
require_once 'session-check.php';

// Helper function to get next steps
function getNextSteps($mob) {
    if (!$mob) return ['Start mobilization process'];
    
    $steps = [];
    
    // Check sequential chain
    if ($mob['method_statements'] !== 'Complete') {
        $steps[] = 'Complete Method Statements';
    } elseif ($mob['insurance_status'] !== 'Complete') {
        $steps[] = 'Complete Insurance';
    } elseif ($mob['pavement_guarantee'] !== 'Complete') {
        $steps[] = 'Complete Pavement Guarantee';
    } elseif ($mob['wellbeing_guarantee'] !== 'Complete') {
        $steps[] = 'Complete Wellbeing Guarantee';
    } elseif ($mob['umbrella_guarantee'] !== 'Complete') {
        $steps[] = 'Complete Umbrella Guarantee';
    } elseif ($mob['responsibility_form'] !== 'Complete') {
        $steps[] = 'Complete Responsibility Form';
    } elseif ($mob['bca_clearance'] !== 'Yes') {
        $steps[] = 'Await BCA Clearance';
    }
    
    // Check non-sequential tasks
    if ($mob['archaeologist_assigned'] !== 'Yes' && $mob['archaeologist_assigned'] !== 'NA') {
        $steps[] = 'Assign Archaeologist';
    }
    if ($mob['change_of_applicant'] !== 'Complete' && $mob['change_of_applicant'] !== 'NA') {
        $steps[] = 'Change of Applicant';
    }
    if ($mob['geological_test'] !== 'Complete' && $mob['geological_test'] !== 'NA') {
        $steps[] = 'Geological Test';
    }
    if ($mob['condition_report_contacts'] !== 'Complete' && $mob['condition_report_contacts'] !== 'NA') {
        $steps[] = 'Condition Report Contacts';
    }
    if ($mob['condition_reports'] !== 'Complete' && $mob['condition_reports'] !== 'NA') {
        $steps[] = 'Condition Reports';
    }
    
    return empty($steps) ? ['All tasks complete'] : array_slice($steps, 0, 3);
}

// Get accessible projects - using correct session variable
$accessibleProjects = getAccessibleProjects($pdo, getCurrentUserId(), getCurrentRole());

// Calculate stats
$stats = [
    'total' => count($accessibleProjects),
    'mobilised' => 0,
    'pending' => 0,
    'inprocess' => 0,
];

foreach ($accessibleProjects as $project) {
    $status = deriveMobilisationStatus($pdo, $project['id']);
    if ($status === 'Mobilised') {
        $stats['mobilised']++;
    } elseif ($status === 'In Process') {
        $stats['inprocess']++;
    } else {
        $stats['pending']++;
    }
}

// Get filter values
$filterClient = $_GET['client'] ?? '';
$filterCity = $_GET['city'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterFinishLevel = $_GET['finishlevel'] ?? '';

// Apply filters
$filteredProjects = $accessibleProjects;

if ($filterClient) {
    $filteredProjects = array_filter($filteredProjects, function($project) use ($filterClient) {
        return $project['clientid'] == $filterClient;
    });
}

if ($filterCity) {
    $filteredProjects = array_filter($filteredProjects, function($project) use ($filterCity) {
        return $project['city'] === $filterCity;
    });
}

if ($filterFinishLevel) {
    $filteredProjects = array_filter($filteredProjects, function($project) use ($filterFinishLevel) {
        return $project['finishlevel'] === $filterFinishLevel;
    });
}

if ($filterStatus) {
    $filteredProjects = array_filter($filteredProjects, function($project) use ($pdo, $filterStatus) {
        $status = deriveMobilisationStatus($pdo, $project['id']);
        return $status === $filterStatus;
    });
}

// Get unique filter options
$clientIds = array_values(array_unique(array_column($accessibleProjects, 'clientid')));
$clients = [];
if (!empty($clientIds)) {
    $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
    $clientStmt = $pdo->prepare("SELECT id, name FROM clients WHERE id IN ($placeholders) ORDER BY name");
    $clientStmt->execute($clientIds);
    $clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC);
}

$cities = array_values(array_unique(array_filter(array_column($accessibleProjects, 'city'))));
sort($cities);

$finishLevels = array_values(array_unique(array_filter(array_column($accessibleProjects, 'finishlevel'))));
sort($finishLevels);

// Enrich project data
foreach ($filteredProjects as &$project) {
    // Get mobilization data
    $mobStmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
    $mobStmt->execute([$project['id']]);
    $mob = $mobStmt->fetch();
    
    // Get PA numbers
    $paStmt = $pdo->prepare("SELECT pa_number FROM project_pa_numbers WHERE project_id = ? ORDER BY pa_number");
    $paStmt->execute([$project['id']]);
    $paNumbers = $paStmt->fetchAll(PDO::FETCH_COLUMN);
    $project['pa_numbers'] = $paNumbers;
    
    // Check if user can edit this project
    $project['can_edit'] = canEditProject($pdo, $project['id']);
    
    if ($mob) {
        $completedSteps = 0;
        $totalSteps = 12;
        
        // Non-sequential tasks
        if ($mob['archaeologist_assigned'] === 'Yes' || $mob['archaeologist_assigned'] === 'NA') $completedSteps++;
        if ($mob['change_of_applicant'] === 'Complete' || $mob['change_of_applicant'] === 'NA') $completedSteps++;
        if ($mob['geological_test'] === 'Complete' || $mob['geological_test'] === 'NA') $completedSteps++;
        if ($mob['condition_report_contacts'] === 'Complete' || $mob['condition_report_contacts'] === 'NA') $completedSteps++;
        if ($mob['condition_reports'] === 'Complete' || $mob['condition_reports'] === 'NA') $completedSteps++;
        
        // Sequential chain
        if ($mob['method_statements'] === 'Complete') $completedSteps++;
        if ($mob['insurance_status'] === 'Complete') $completedSteps++;
        if ($mob['pavement_guarantee'] === 'Complete') $completedSteps++;
        if ($mob['wellbeing_guarantee'] === 'Complete') $completedSteps++;
        if ($mob['umbrella_guarantee'] === 'Complete') $completedSteps++;
        
        // Final clearance
        if ($mob['responsibility_form'] === 'Complete') $completedSteps++;
        if ($mob['bca_clearance'] === 'Yes') $completedSteps++;
        
        $project['mobilization_progress'] = round(($completedSteps / $totalSteps) * 100);
        $project['next_steps'] = getNextSteps($mob);
        
        // Determine status badge
        if ($mob['bca_clearance'] === 'Yes') {
            $project['status_badge'] = 'Mobilised';
            $project['status_class'] = 'status-Mobilised';
        } elseif ($mob['bca_clearance'] === 'No' || $mob['responsibility_form'] === 'Complete') {
            $project['status_badge'] = 'In Process';
            $project['status_class'] = 'status-In-Process';
        } else {
            $project['status_badge'] = 'Pending';
            $project['status_class'] = 'status-Pending';
        }
    } else {
        $project['mobilization_progress'] = 0;
        $project['status_badge'] = 'Pending';
        $project['status_class'] = 'status-Pending';
        $project['next_steps'] = ['Start mobilization process'];
    }
}
unset($project);

$pageTitle = 'Mobilization Dashboard';
require_once 'header.php';
?>

<div class="main-content">
    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= $stats['total'] ?></div>
            <div class="stat-label">Total Projects</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color: var(--success);"><?= $stats['mobilised'] ?></div>
            <div class="stat-label">Mobilised</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color: var(--warning);"><?= $stats['inprocess'] ?></div>
            <div class="stat-label">In Process</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color: var(--danger);"><?= $stats['pending'] ?></div>
            <div class="stat-label">Pending</div>
        </div>
    </div>

    <!-- Enhanced Filters Section -->
    <div class="filters-section">
        <form method="GET" action="mobilization.php" id="filterForm">
            <div class="filters-grid">
                <div class="filter-group">
                    <label>Client</label>
                    <select name="client" onchange="document.getElementById('filterForm').submit()">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= $client['id'] ?>" <?= $filterClient == $client['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($client['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>City</label>
                    <select name="city" onchange="document.getElementById('filterForm').submit()">
                        <option value="">All Cities</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?= htmlspecialchars($city) ?>" <?= $filterCity === $city ? 'selected' : '' ?>>
                                <?= htmlspecialchars($city) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Finish Level</label>
                    <select name="finishlevel" onchange="document.getElementById('filterForm').submit()">
                        <option value="">All Levels</option>
                        <?php foreach ($finishLevels as $level): ?>
                            <option value="<?= htmlspecialchars($level) ?>" <?= $filterFinishLevel === $level ? 'selected' : '' ?>>
                                <?= htmlspecialchars($level) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" onchange="document.getElementById('filterForm').submit()">
                        <option value="">All Statuses</option>
                        <option value="Mobilised" <?= $filterStatus === 'Mobilised' ? 'selected' : '' ?>>Mobilised</option>
                        <option value="In Process" <?= $filterStatus === 'In Process' ? 'selected' : '' ?>>In Process</option>
                        <option value="Pending" <?= $filterStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                </div>
            </div>

            <div class="filter-buttons">
                <a href="mobilization.php" class="reset-btn">Reset Filters</a>
            </div>
        </form>
    </div>

    <!-- Project Cards Grid -->
    <div class="projects-grid">
        <?php if (empty($filteredProjects)): ?>
            <div class="empty-state">
                <p>No projects found</p>
                <a href="edit-project.php" class="btn btn-primary">Create First Project</a>
            </div>
        <?php else: ?>
            <?php foreach ($filteredProjects as $project): ?>
                <div class="project-card">
                    <div class="project-header">
                        <div>
                            <h3 class="project-title"><?= htmlspecialchars($project['name']) ?></h3>
                            <div style="margin-top: 0.5rem;">
                                <span class="status-badge <?= $project['status_class'] ?>">
                                    <?= $project['status_badge'] ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Enhanced Project Info Grid -->
                    <div class="project-info" style="grid-template-columns: repeat(3, 1fr);">
                        <div class="info-item">
                            <span class="info-label">Client</span>
                            <span class="info-value"><?= htmlspecialchars($project['client_name'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">City</span>
                            <span class="info-value"><?= htmlspecialchars($project['city'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Finish Level</span>
                            <span class="info-value"><?= htmlspecialchars($project['finishlevel'] ?? 'N/A') ?></span>
                        </div>
                    </div>

                    <!-- PA Numbers Section -->
                    <?php if (!empty($project['pa_numbers'])): ?>
                        <div class="info-item" style="margin-bottom: 1rem;">
                            <span class="info-label">PA Number(s)</span>
                            <span class="info-value">
                                <?php 
                                $paLinks = array_map(function($pa) use ($project) {
                                    return '<a href="mobilisation_detail.php?id=' . $project['id'] . '" style="color: var(--primary-color); text-decoration: none;">' . 
                                           htmlspecialchars($pa) . '</a>';
                                }, $project['pa_numbers']);
                                echo implode(', ', $paLinks);
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <!-- Next Steps Section -->
                    <div class="info-item" style="margin-bottom: 1rem;">
                        <span class="info-label">Next Steps</span>
                        <div style="margin-top: 0.5rem;">
                            <?php foreach ($project['next_steps'] as $step): ?>
                                <div style="padding: 0.4rem 0.6rem; background: rgba(99, 102, 241, 0.1); border-radius: 4px; margin-bottom: 0.4rem; font-size: 0.85rem; color: var(--primary-color); border-left: 3px solid var(--primary-color);">
                                    • <?= htmlspecialchars($step) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Progress Bar Section - Repositioned -->
                    <div class="progress-section">
                        <div class="progress-label">
                            <span style="font-weight: 600; font-size: 0.85rem; color: var(--text-secondary);">Mobilization Progress</span>
                            <span style="font-weight: 700; color: var(--primary-color); font-size: 1rem;">
                                <?= $project['mobilization_progress'] ?>%
                            </span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar" style="width: <?= $project['mobilization_progress'] ?>%"></div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="project-actions">
                        <?php if ($project['can_edit']): ?>
                            <a href="edit-project.php?id=<?= $project['id'] ?>" class="btn btn-secondary btn-sm">
                                Edit Project
                            </a>
                        <?php endif; ?>
                        <a href="mobilisation_detail.php?id=<?= $project['id'] ?>" class="btn btn-primary btn-sm">
                            View Details
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
