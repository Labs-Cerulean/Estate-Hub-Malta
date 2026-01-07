<?php
require_once 'init.php';
require_once 'session-check.php';

// Get current user
$userId = getCurrentUserId();
$userRole = getCurrentRole();

// Calculate summary stats - based on accessible projects only
$accessibleProjects = getAccessibleProjects($pdo, $userId);

$stats = [
    'total' => count($accessibleProjects),
    'mobilised' => 0,
    'pending' => 0,
    'inprocess' => 0,
];

// Count mobilization statuses for accessible projects
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

// Get filter values from GET
$filterClient = $_GET['client'] ?? '';
$filterCity = $_GET['city'] ?? '';
$filterStatus = $_GET['status'] ?? '';

// Apply filters to accessible projects
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

// Status filter using mobilization data
if ($filterStatus) {
    $filteredProjects = array_filter($filteredProjects, function($project) use ($pdo, $filterStatus) {
        $status = deriveMobilisationStatus($pdo, $project['id']);
        return $status === $filterStatus;
    });
}

// Get unique clients from accessible projects for filter dropdown
$clientIds = array_values(array_unique(array_column($accessibleProjects, 'clientid')));
$clients = [];
if (!empty($clientIds)) {
    $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
    $clientStmt = $pdo->prepare("SELECT id, name FROM clients WHERE id IN ($placeholders) ORDER BY name");
    $clientStmt->execute($clientIds);
    $clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get all unique cities from accessible projects
$cities = array_values(array_unique(array_filter(array_column($accessibleProjects, 'city'))));
sort($cities);

// Enrich filtered project data with mobilization progress
foreach ($filteredProjects as &$project) {
    // Calculate mobilization progress and status
    $mobStmt = $pdo->prepare("SELECT * FROM project_mobilisation WHERE project_id = ?");
    $mobStmt->execute([$project['id']]);
    $mob = $mobStmt->fetch();
    
    if ($mob) {
        // Count completed mobilization steps
        $completedSteps = 0;
        $totalSteps = 12;
        
        // Non-sequential tasks
        if ($mob['archaeologist_assigned'] === 'Yes' || $mob['archaeologist_assigned'] === 'NA') $completedSteps++;
        if ($mob['change_of_applicant'] === 'Complete' || $mob['change_of_applicant'] === 'NA') $completedSteps++;
        if ($mob['geological_test'] === 'Complete' || $mob['geological_test'] === 'NA') $completedSteps++;
        if ($mob['condition_report_contacts'] === 'Complete' || $mob['condition_report_contacts'] === 'NA') $completedSteps++;
        if ($mob['condition_reports'] === 'Complete' || $mob['condition_reports'] === 'NA') $completedSteps++;
        
        // Sequential chain (note: method_statements uses 'Complete' not 'Completed')
        if ($mob['method_statements'] === 'Complete') $completedSteps++;
        if ($mob['insurance_status'] === 'Complete') $completedSteps++;
        if ($mob['pavement_guarantee'] === 'Complete') $completedSteps++;
        if ($mob['wellbeing_guarantee'] === 'Complete') $completedSteps++;
        if ($mob['umbrella_guarantee'] === 'Complete') $completedSteps++;
        
        // Final clearance
        if ($mob['responsibility_form'] === 'Complete') $completedSteps++;
        if ($mob['bca_clearance'] === 'Yes') $completedSteps++;
        
        $project['mobilization_progress'] = round(($completedSteps / $totalSteps) * 100);
        
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
    }
}
unset($project);

// Set page title
$pageTitle = 'Mobilization Dashboard';

// Output HTML
require_once 'header.php';
?>

<style>
.mobilization-dashboard {
    padding: 2rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-glass);
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
}

.stat-number {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--primary-color);
    margin-bottom: 0.5rem;
}

.stat-label {
    font-size: 0.9rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filters-section {
    background: var(--bg-card);
    border: 1px solid var(--border-glass);
    padding: 1.5rem;
    margin-bottom: 2rem;
    border-radius: 16px;
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-group label {
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--text-secondary);
}

.filter-group select {
    padding: 0.6rem;
    border: 1px solid var(--border-glass);
    border-radius: 8px;
    background: var(--bg-secondary);
    color: var(--text-primary);
    font-size: 0.9rem;
}

.filter-buttons {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-start;
    margin-top: 0.5rem;
}

.reset-btn {
    padding: 0.6rem 1.2rem;
    border: 1px solid var(--border-glass);
    border-radius: 8px;
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
    cursor: pointer;
    font-size: 0.9rem;
    text-decoration: none;
    display: inline-block;
}

.reset-btn:hover {
    background: rgba(239, 68, 68, 0.3);
}

.projects-grid {
    display: grid;
    gap: 1.5rem;
}

.project-card {
    background: var(--bg-card);
    border: 1px solid var(--border-glass);
    border-radius: 12px;
    padding: 1.5rem;
    transition: transform 0.2s, box-shadow 0.2s;
}

.project-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
}

.project-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
}

.project-title {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.status-badge {
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-Mobilised {
    background: rgba(34, 197, 94, 0.2);
    color: #22c55e;
}

.status-In-Process {
    background: rgba(251, 191, 36, 0.2);
    color: #fbbf24;
}

.status-Pending {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

.project-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}

.info-label {
    font-size: 0.8rem;
    color: var(--text-secondary);
    text-transform: uppercase;
}

.info-value {
    font-size: 0.95rem;
    color: var(--text-primary);
    font-weight: 500;
}

.progress-section {
    margin-top: 1rem;
}

.progress-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.5rem;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.progress-bar-container {
    height: 8px;
    background: var(--bg-secondary);
    border-radius: 4px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    transition: width 0.3s ease;
}

.project-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--text-secondary);
}

.empty-state p {
    font-size: 1.1rem;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .filters-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .project-info {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="mobilization-dashboard">
    <h1 class="page-title">Mobilization Dashboard</h1>
    
    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Projects</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $stats['mobilised']; ?></div>
            <div class="stat-label">Mobilised</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $stats['inprocess']; ?></div>
            <div class="stat-label">In Process</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending</div>
        </div>
    </div>
    
    <!-- Filters Section -->
    <div class="filters-section">
        <form method="GET">
            <div class="filters-grid">
                <div class="filter-group">
                    <label>Client</label>
                    <select name="client">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>" <?php echo $filterClient == $client['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($client['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>City</label>
                    <select name="city">
                        <option value="">All Cities</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?php echo htmlspecialchars($city); ?>" <?php echo $filterCity === $city ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($city); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="Mobilised" <?php echo $filterStatus === 'Mobilised' ? 'selected' : ''; ?>>Mobilised</option>
                        <option value="In Process" <?php echo $filterStatus === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                        <option value="Pending" <?php echo $filterStatus === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                </div>
            </div>
            
            <div class="filter-buttons">
                <button type="submit" class="btn" style="padding: 0.6rem 1.5rem">Apply Filters</button>
                <a href="mobilization.php" class="reset-btn">Reset</a>
            </div>
        </form>
    </div>
    
    <!-- Projects Grid -->
    <?php if (count($filteredProjects) > 0): ?>
        <div class="projects-grid">
            <?php foreach ($filteredProjects as $project): ?>
                <div class="project-card">
                    <div class="project-header">
                        <h3 class="project-title"><?php echo htmlspecialchars($project['name']); ?></h3>
                        <span class="status-badge <?php echo $project['status_class']; ?>">
                            <?php echo $project['status_badge']; ?>
                        </span>
                    </div>
                    
                    <div class="project-info">
                        <div class="info-item">
                            <span class="info-label">Client</span>
                            <span class="info-value"><?php echo htmlspecialchars($project['client_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">City</span>
                            <span class="info-value"><?php echo htmlspecialchars($project['city']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Type</span>
                            <span class="info-value"><?php echo htmlspecialchars($project['type']); ?></span>
                        </div>
                    </div>
                    
                    <div class="progress-section">
                        <div class="progress-label">
                            <span>Mobilization Progress</span>
                            <span><?php echo $project['mobilization_progress']; ?>%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar" style="width: <?php echo $project['mobilization_progress']; ?>%"></div>
                        </div>
                    </div>
                    
                    <div class="project-actions">
                        <a href="mobilisation_detail.php?project_id=<?php echo $project['id']; ?>" class="btn btn-sm btn-primary">View Details</a>
                        <?php if (canEditProject($pdo, $project['id'])): ?>
                            <a href="mobilisation_detail.php?project_id=<?php echo $project['id']; ?>" class="btn btn-sm">Edit</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <p>No projects found</p>
            <?php if (isAdmin()): ?>
                <a href="create-project.php" class="btn btn-primary">Create First Project</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
