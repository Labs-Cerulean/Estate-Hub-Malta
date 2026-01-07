<?php
require_once 'init.php';
require_once 'session-check.php';

// Get current user
$userId = getCurrentUserId();
$userName = getCurrentUserFullName();
$userRole = getCurrentRole();
$isAdmin = isAdmin();

// Get filter and sort parameters
$filterType = $_GET['filtertype'] ?? 'all';
$filterStatus = $_GET['filterstatus'] ?? 'all';
$filterCity = $_GET['filtercity'] ?? 'all';
$filterClient = $_GET['filterclient'] ?? 'all';
$filterArchitect = $_GET['filterarchitect'] ?? 'all';
$filterEngineer = $_GET['filterengineer'] ?? 'all';
$filterIsland = $_GET['filterisland'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'name';
$sortOrder = $_GET['order'] ?? 'ASC';

// Validate sort parameters
$allowedSorts = ['name', 'client', 'city', 'type'];
$allowedOrders = ['ASC', 'DESC'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'name';
if (!in_array($sortOrder, $allowedOrders)) $sortOrder = 'ASC';

try {
    // Get filter options - but only for accessible clients/projects
    if ($isAdmin) {
        // Admins see all filter options
        $cities = $pdo->query("SELECT DISTINCT city FROM projects ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);
        $clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Non-admins see only their accessible clients/projects
        $user = getUserById($pdo, $userId);
        
        if ($user['role'] === 'architect') {
            // Architects see clients/cities from their firm's projects
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, c.name
                FROM clients c
                INNER JOIN projects p ON c.id = p.clientid
                INNER JOIN project_pa_numbers ppn ON p.id = ppn.project_id
                WHERE (
                    (? IS NOT NULL AND ppn.architect_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (
                            SELECT firm_name FROM professionals WHERE id = ?
                        ) AND role_type = 'architect'
                    ))
                    OR
                    (? IS NOT NULL AND ppn.structural_engineer_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (
                            SELECT firm_name FROM professionals WHERE id = ?
                        ) AND role_type = 'structural_engineer'
                    ))
                )
                ORDER BY c.name
            ");
            $stmt->execute([
                $user['assigned_architect_firm_id'],
                $user['assigned_architect_firm_id'],
                $user['assigned_structural_firm_id'],
                $user['assigned_structural_firm_id']
            ]);
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("
                SELECT DISTINCT p.city
                FROM projects p
                INNER JOIN project_pa_numbers ppn ON p.id = ppn.project_id
                WHERE (
                    (? IS NOT NULL AND ppn.architect_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (
                            SELECT firm_name FROM professionals WHERE id = ?
                        ) AND role_type = 'architect'
                    ))
                    OR
                    (? IS NOT NULL AND ppn.structural_engineer_id IN (
                        SELECT id FROM professionals 
                        WHERE firm_name = (
                            SELECT firm_name FROM professionals WHERE id = ?
                        ) AND role_type = 'structural_engineer'
                    ))
                )
                ORDER BY p.city
            ");
            $stmt->execute([
                $user['assigned_architect_firm_id'],
                $user['assigned_architect_firm_id'],
                $user['assigned_structural_firm_id'],
                $user['assigned_structural_firm_id']
            ]);
            $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Other users see only assigned clients
            $clients = getUserClients($pdo, $userId);
            
            $stmt = $pdo->prepare("
                SELECT DISTINCT p.city
                FROM projects p
                INNER JOIN user_client_access uca ON p.clientid = uca.client_id
                WHERE uca.user_id = ?
                ORDER BY p.city
            ");
            $stmt->execute([$userId]);
            $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    
    // Get all architects and engineers (for filters)
    $architects = $pdo->query("SELECT DISTINCT id, name FROM professionals WHERE role_type = 'architect' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $engineers = $pdo->query("SELECT DISTINCT id, name FROM professionals WHERE role_type = 'structural_engineer' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // USE getAccessibleProjects() INSTEAD OF DIRECT QUERY
    $projects = getAccessibleProjects($pdo, $userId);
    
    // Apply additional filters to accessible projects
    if ($filterType !== 'all') {
        $projects = array_filter($projects, function($project) use ($filterType) {
            return $project['type'] === $filterType;
        });
    }
    
    if ($filterCity !== 'all') {
        $projects = array_filter($projects, function($project) use ($filterCity) {
            return $project['city'] === $filterCity;
        });
    }
    
    if ($filterClient !== 'all') {
        $projects = array_filter($projects, function($project) use ($filterClient) {
            return $project['clientid'] == $filterClient;
        });
    }
    
    if ($filterIsland !== 'all') {
        $projects = array_filter($projects, function($project) use ($filterIsland) {
            return $project['island'] === $filterIsland;
        });
    }
    
    // Get PA numbers for all accessible projects
    $projectIds = array_column($projects, 'id');
    $paByProject = [];
    
    if (!empty($projectIds)) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $paSql = "
            SELECT pan.project_id, pan.pa_number, pan.pa_status, pan.architect_id, pan.structural_engineer_id,
                   arch.name AS architect_name, se.name AS structural_engineer_name
            FROM project_pa_numbers pan
            LEFT JOIN professionals arch ON arch.id = pan.architect_id
            LEFT JOIN professionals se ON se.id = pan.structural_engineer_id
            WHERE pan.project_id IN ($placeholders)
            ORDER BY pan.project_id, pan.id
        ";
        $paStmt = $pdo->prepare($paSql);
        $paStmt->execute($projectIds);
        $paNumbers = $paStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group PA numbers by project_id
        foreach ($paNumbers as $pa) {
            $paByProject[$pa['project_id']][] = $pa;
        }
    }
    
    // Apply PA status filter
    if ($filterStatus !== 'all') {
        $projects = array_filter($projects, function($project) use ($paByProject, $filterStatus) {
            $projectPAs = $paByProject[$project['id']] ?? [];
            foreach ($projectPAs as $pa) {
                if ($pa['pa_status'] === $filterStatus) {
                    return true;
                }
            }
            return empty($projectPAs) && $filterStatus === 'TBC';
        });
    }
    
    // Apply architect filter
    if ($filterArchitect !== 'all') {
        $projects = array_filter($projects, function($project) use ($paByProject, $filterArchitect) {
            $projectPAs = $paByProject[$project['id']] ?? [];
            foreach ($projectPAs as $pa) {
                if ($pa['architect_id'] == $filterArchitect) {
                    return true;
                }
            }
            return empty($projectPAs) && $filterArchitect === 'none';
        });
    }
    
    // Apply engineer filter
    if ($filterEngineer !== 'all') {
        $projects = array_filter($projects, function($project) use ($paByProject, $filterEngineer) {
            $projectPAs = $paByProject[$project['id']] ?? [];
            foreach ($projectPAs as $pa) {
                if ($pa['structural_engineer_id'] == $filterEngineer) {
                    return true;
                }
            }
            return empty($projectPAs) && $filterEngineer === 'none';
        });
    }
    
    // Sort projects
    usort($projects, function($a, $b) use ($sortBy, $sortOrder) {
        $valA = $sortBy === 'client' ? ($a['client_name'] ?? '') : $a[$sortBy];
        $valB = $sortBy === 'client' ? ($b['client_name'] ?? '') : $b[$sortBy];
        
        $comparison = strcasecmp($valA, $valB);
        return $sortOrder === 'ASC' ? $comparison : -$comparison;
    });
    
    // Get stats
    $projectCount = count($projects);
    $userCount = $isAdmin ? $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;
    
    // Count mobilised projects (only accessible ones)
    $mobilisedCount = 0;
    foreach ($projects as $project) {
        $mobStatus = deriveMobilisationStatus($pdo, $project['id']);
        if ($mobStatus === 'Mobilised') {
            $mobilisedCount++;
        }
    }
    
} catch (Exception $e) {
    $projects = [];
    $paByProject = [];
    $cities = [];
    $clients = [];
    $architects = [];
    $engineers = [];
    $projectCount = 0;
    $userCount = 0;
    $mobilisedCount = 0;
}

// Helper functions
function getSortUrl($column) {
    global $sortBy, $sortOrder, $filterType, $filterStatus, $filterCity, $filterClient, $filterArchitect, $filterEngineer, $filterIsland;
    $newOrder = ($sortBy === $column && $sortOrder === 'ASC') ? 'DESC' : 'ASC';
    $params = [
        'sort' => $column,
        'order' => $newOrder,
        'filtertype' => $filterType,
        'filterstatus' => $filterStatus,
        'filtercity' => $filterCity,
        'filterclient' => $filterClient,
        'filterarchitect' => $filterArchitect,
        'filterengineer' => $filterEngineer,
        'filterisland' => $filterIsland
    ];
    return 'mobilization.php?' . http_build_query($params);
}

function getSortIndicator($column) {
    global $sortBy, $sortOrder;
    if ($sortBy === $column) {
        return $sortOrder === 'ASC' ? ' ▲' : ' ▼';
    }
    return '';
}

function buildPaUrl(?string $paNumber): ?string {
    if (empty($paNumber)) return null;
    if (!preg_match('/PA(\d{4,5})\/(\d{2})/', trim($paNumber), $m)) return null;
    $caseNumber = $m[1];
    $caseYear = $m[2];
    return "https://eapps.pa.org.mt/Case/CaseDetails?caseType=PA&casenumber={$caseNumber}&caseyear={$caseYear}";
}

// Set page title
$pageTitle = 'Mobilization';

// Now output HTML
require_once 'header.php';
?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?php echo $projectCount ?></div>
        <div class="stat-label">Total Projects</div>
    </div>
    <div class="stat-card">
        <div class="stat-number mobilised"><?php echo $mobilisedCount ?></div>
        <div class="stat-label">Mobilised</div>
    </div>
    <div class="stat-card">
        <div class="stat-number pending"><?php echo $projectCount - $mobilisedCount ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <?php if ($isAdmin): ?>
        <div class="stat-card">
            <div class="stat-number"><?php echo $userCount ?></div>
            <div class="stat-label">Users</div>
        </div>
    <?php endif; ?>
</div>

<!-- Page Title -->
<h2 class="page-title">Project Mobilization</h2>

<!-- Filters Section -->
<div class="projects-section" style="margin-bottom: 2rem;">
    <form method="GET" action="mobilization.php" class="filters-form">
        <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
            <!-- Type Filter -->
            <div class="form-group">
                <label>Type</label>
                <select name="filtertype" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterType === 'all' ? 'selected' : '' ?>>All Types</option>
                    <option value="in-house" <?php echo $filterType === 'in-house' ? 'selected' : '' ?>>In-House</option>
                    <option value="client" <?php echo $filterType === 'client' ? 'selected' : '' ?>>Client</option>
                </select>
            </div>

            <!-- PA Status Filter -->
            <div class="form-group">
                <label>PA Status</label>
                <select name="filterstatus" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="Endorsed" <?php echo $filterStatus === 'Endorsed' ? 'selected' : '' ?>>Endorsed</option>
                    <option value="Approved" <?php echo $filterStatus === 'Approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="Fee Payment" <?php echo $filterStatus === 'Fee Payment' ? 'selected' : '' ?>>Fee Payment</option>
                    <option value="Not Approved" <?php echo $filterStatus === 'Not Approved' ? 'selected' : '' ?>>Not Approved</option>
                    <option value="TBC" <?php echo $filterStatus === 'TBC' ? 'selected' : '' ?>>TBC</option>
                </select>
            </div>

            <!-- City Filter -->
            <div class="form-group">
                <label>City</label>
                <select name="filtercity" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterCity === 'all' ? 'selected' : '' ?>>All Cities</option>
                    <?php foreach ($cities as $city): ?>
                        <option value="<?php echo htmlspecialchars($city) ?>" <?php echo $filterCity === $city ? 'selected' : '' ?>>
                            <?php echo htmlspecialchars($city) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Client Filter -->
            <div class="form-group">
                <label>Client</label>
                <select name="filterclient" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterClient === 'all' ? 'selected' : '' ?>>All Clients</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?php echo $client['id'] ?>" <?php echo $filterClient == $client['id'] ? 'selected' : '' ?>>
                            <?php echo htmlspecialchars($client['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Architect Filter -->
            <div class="form-group">
                <label>Architect</label>
                <select name="filterarchitect" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterArchitect === 'all' ? 'selected' : '' ?>>All Architects</option>
                    <?php foreach ($architects as $architect): ?>
                        <option value="<?php echo $architect['id'] ?>" <?php echo $filterArchitect == $architect['id'] ? 'selected' : '' ?>>
                            <?php echo htmlspecialchars($architect['name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="none" <?php echo $filterArchitect === 'none' ? 'selected' : '' ?>>No Architect</option>
                </select>
            </div>

            <!-- Structural Engineer Filter -->
            <div class="form-group">
                <label>Structural Engineer</label>
                <select name="filterengineer" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterEngineer === 'all' ? 'selected' : '' ?>>All Engineers</option>
                    <?php foreach ($engineers as $engineer): ?>
                        <option value="<?php echo $engineer['id'] ?>" <?php echo $filterEngineer == $engineer['id'] ? 'selected' : '' ?>>
                            <?php echo htmlspecialchars($engineer['name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="none" <?php echo $filterEngineer === 'none' ? 'selected' : '' ?>>No Engineer</option>
                </select>
            </div>

            <!-- Island Filter -->
            <div class="form-group">
                <label>Island</label>
                <select name="filterisland" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterIsland === 'all' ? 'selected' : '' ?>>All Islands</option>
                    <option value="Malta" <?php echo $filterIsland === 'Malta' ? 'selected' : '' ?>>Malta</option>
                    <option value="Gozo" <?php echo $filterIsland === 'Gozo' ? 'selected' : '' ?>>Gozo</option>
                </select>
            </div>
        </div>

        <!-- Hidden inputs to preserve sort state -->
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy) ?>">
        <input type="hidden" name="order" value="<?php echo htmlspecialchars($sortOrder) ?>">

        <?php if ($filterType !== 'all' || $filterStatus !== 'all' || $filterCity !== 'all' || $filterClient !== 'all' || $filterArchitect !== 'all' || $filterEngineer !== 'all' || $filterIsland !== 'all'): ?>
            <a href="mobilization.php" class="btn" style="display: inline-block; padding: 0.75rem 1.5rem; width: auto;">Clear Filters</a>
        <?php endif; ?>
    </form>
</div>

<!-- Projects Table -->
<div class="projects-section">
    <div class="projects-header" style="margin-bottom: 2rem;">
        <h3 class="section-title">Projects (<?php echo $projectCount ?>)</h3>
    </div>

    <?php if (empty($projects)): ?>
        <div class="empty-state">
            <p>No projects found matching your filters.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th><a href="<?php echo getSortUrl('name') ?>" style="color: inherit; text-decoration: none;">Project Name<?php echo getSortIndicator('name') ?></a></th>
                        <th><a href="<?php echo getSortUrl('client') ?>" style="color: inherit; text-decoration: none;">Client<?php echo getSortIndicator('client') ?></a></th>
                        <th><a href="<?php echo getSortUrl('city') ?>" style="color: inherit; text-decoration: none;">City<?php echo getSortIndicator('city') ?></a></th>
                        <th><a href="<?php echo getSortUrl('type') ?>" style="color: inherit; text-decoration: none;">Type<?php echo getSortIndicator('type') ?></a></th>
                        <th>Island</th>
                        <th>PA Numbers</th>
                        <th>Architect</th>
                        <th>Structural Eng.</th>
                        <th>Mobilisation</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $project): ?>
                        <?php
                        $projectPAs = $paByProject[$project['id']] ?? [];
                        $mobStatus = deriveMobilisationStatus($pdo, $project['id']);
                        $statusClass = 'status-' . str_replace(' ', '-', $mobStatus);
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($project['name']) ?></strong></td>
                            <td><?php echo htmlspecialchars($project['client_name'] ?? 'N/A') ?></td>
                            <td><?php echo htmlspecialchars($project['city']) ?></td>
                            <td>
                                <span class="status-badge" style="<?php echo $project['type'] === 'in-house' ? 'background: rgba(34,197,94,0.2); color: var(--success);' : 'background: rgba(59,130,246,0.2); color: var(--info);' ?>">
                                    <?php echo htmlspecialchars(ucfirst($project['type'])) ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($project['island']) ?></td>
                            <td>
                                <?php if (empty($projectPAs)): ?>
                                    <span class="pa-badge pa-status-endorsed" style="opacity: 0.5;">TBC</span>
                                <?php else: ?>
                                    <?php foreach ($projectPAs as $pa): ?>
                                        <?php
                                        $paUrl = buildPaUrl($pa['pa_number']);
                                        $statusClassMap = [
                                            'Endorsed' => 'pa-status-endorsed',
                                            'Approved' => 'pa-status-approved',
                                            'Fee Payment' => 'pa-status-fee-payment',
                                            'Not Approved' => 'pa-status-not-approved'
                                        ];
                                        $paStatusClass = $statusClassMap[$pa['pa_status']] ?? 'pa-status-endorsed';
                                        ?>
                                        <?php if ($paUrl): ?>
                                            <a href="<?php echo htmlspecialchars($paUrl) ?>" target="_blank" class="pa-badge <?php echo $paStatusClass ?>" style="text-decoration: none;">
                                                <?php echo htmlspecialchars($pa['pa_number']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="pa-badge <?php echo $paStatusClass ?>">
                                                <?php echo htmlspecialchars($pa['pa_number']) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $architects = array_unique(array_filter(array_column($projectPAs, 'architect_name')));
                                echo !empty($architects) ? htmlspecialchars(implode(', ', $architects)) : '-';
                                ?>
                            </td>
                            <td>
                                <?php
                                $engineers = array_unique(array_filter(array_column($projectPAs, 'structural_engineer_name')));
                                echo !empty($engineers) ? htmlspecialchars(implode(', ', $engineers)) : '-';
                                ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $statusClass ?>">
                                    <?php echo htmlspecialchars($mobStatus) ?>
                                </span>
                            </td>
                            <td>
                                <a href="mobilisation_detail.php?projectid=<?php echo $project['id'] ?>" class="action-btn">
                                    View Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
