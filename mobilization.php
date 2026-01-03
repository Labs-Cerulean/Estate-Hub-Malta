<?php
require_once 'config.php';

// Check if user has permission to update
$can_update = isset($_SESSION['user']) && $_SESSION['role'] !== 'viewer';

// Handle mobilisation step updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_update && isset($_POST['update_steps'])) {
    try {
        $project_id = $_POST['project_id'];
        
        // Validate BCA clearance logic before updating
        $updates = [];
        $params = [$project_id];
        
        // Collect POST values
        foreach ($_POST as $key => $value) {
            if ($key !== 'project_id' && $key !== 'update_steps' && $value !== '') {
                $updates[] = "$key = ?";
                $params[] = $value;
            }
        }
        
        // Add updated timestamp
        $updates[] = "updated_by = ?";
        $updates[] = "updated_at = CURRENT_TIMESTAMP";
        $params[] = $_SESSION['user'] ?? 'system';
        
        if (!empty($updates)) {
            $sql = "UPDATE mobilisation_steps SET " . implode(', ', array_slice($updates, 0, count($updates)-1)) . ", updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE project_id = ?";
            $stmt = $pdo->prepare($sql);
            
            // Rebuild params properly
            $final_params = [];
            $update_count = 0;
            foreach ($_POST as $key => $value) {
                if ($key !== 'project_id' && $key !== 'update_steps' && $value !== '') {
                    $final_params[] = $value;
                    $update_count++;
                }
            }
            $final_params[] = $_SESSION['user'] ?? 'system';
            $final_params[] = $project_id;
            
            $stmt->execute($final_params);
            
            // Log activity
            $log_stmt = $pdo->prepare("INSERT INTO activity_log (project_id, user, action, details) VALUES (?, ?, 'Update Mobilisation Steps', ?)");
            $log_stmt->execute([$project_id, $_SESSION['user'] ?? 'system', json_encode($_POST)]);
        }
        
        $success_message = '✅ Mobilisation steps updated successfully!';
    } catch (PDOException $e) {
        $error_message = 'Error updating steps: ' . $e->getMessage();
    }
}

// Get projects with client names
$projects = $pdo->query("
    SELECT p.*, c.name as client_name, c.type as client_type,
           COUNT(DISTINCT pa.id) as pa_count
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN pa_numbers pa ON p.id = pa.project_id
    GROUP BY p.id
    ORDER BY p.name
")->fetchAll();

$selected_project = null;
$mobilisation_steps = null;
$pa_numbers = null;

if (!empty($_GET['project_id'])) {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as client_name, c.type as client_type
        FROM projects p
        LEFT JOIN clients c ON p.client_id = c.id
        WHERE p.id = ?
    ");
    $stmt->execute([$_GET['project_id']]);
    $selected_project = $stmt->fetch();
    
    if ($selected_project) {
        $steps_stmt = $pdo->prepare("SELECT * FROM mobilisation_steps WHERE project_id = ?");
        $steps_stmt->execute([$_GET['project_id']]);
        $mobilisation_steps = $steps_stmt->fetch();
        
        $pa_stmt = $pdo->prepare("SELECT * FROM pa_numbers WHERE project_id = ? ORDER BY pa_number");
        $pa_stmt->execute([$_GET['project_id']]);
        $pa_numbers = $pa_stmt->fetchAll();
    }
}

// Calculate mobilisation status and BCA clearance
function calculateBCAClearance($steps, $project_type) {
    // Check all required fields are complete
    $required_fields = [
        'archeologist_assigned',
        'change_of_applicant',
        'geological_test',
        'condition_report_contacts',
        'condition_reports',
        'responsibility_form'
    ];
    
    // For in-house, also check acquisition_complete
    if ($project_type === 'in-house') {
        if (!$steps['acquisition_complete']) return false;
    }
    
    // Check if all required fields are either Complete, Yes, or NA
    foreach ($required_fields as $field) {
        $value = $steps[$field];
        if (!in_array($value, ['Complete', 'Yes', 'NA'])) {
            return false;
        }
    }
    
    // Check condition reports and geological test are both complete
    if ($steps['condition_reports'] !== 'Complete' || $steps['geological_test'] !== 'Complete') {
        return false;
    }
    
    // If above met, method_statements must be complete for guarantees
    if ($steps['method_statements'] !== 'Complete') {
        return false;
    }
    
    // All guarantees must be at least started or not needed
    $guarantees = ['insurance', 'pavement_guarantee', 'wellbeing_guarantee', 'umbrella_guarantee'];
    foreach ($guarantees as $field) {
        if ($steps[$field] === 'Not Started') {
            return false;
        }
    }
    
    return true;
}

$should_auto_clear_bca = false;
if ($mobilisation_steps) {
    $should_auto_clear_bca = calculateBCAClearance($mobilisation_steps, $selected_project['type']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mobilisation Dashboard - Estate Hub</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Mobilisation Flow Styles */
        .mobilisation-container {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .project-selector {
            background: var(--bg-card);
            border: 1px solid var(--border-glass);
            border-radius: 20px;
            padding: 1.5rem;
            height: fit-content;
            max-height: 600px;
            overflow-y: auto;
        }

        .project-item {
            padding: 1rem;
            margin-bottom: 0.5rem;
            background: var(--bg-secondary);
            border: 2px solid transparent;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .project-item:hover {
            border-color: var(--accent-blue);
            transform: translateX(4px);
        }

        .project-item.active {
            background: var(--accent-blue);
            border-color: var(--accent-blue);
            color: white;
        }

        .project-item-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .project-item-meta {
            font-size: 0.85rem;
            opacity: 0.8;
        }

        .mobilisation-flow {
            background: var(--bg-secondary);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid var(--border-glass);
        }

        .flow-section {
            margin-bottom: 2.5rem;
        }

        .flow-section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--accent-blue);
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--border-glass);
        }

        .flow-step {
            margin-bottom: 1.5rem;
            padding: 1.25rem;
            background: var(--bg-card);
            border: 1px solid var(--border-glass);
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .flow-step:hover {
            border-color: var(--accent-blue);
            background: rgba(79, 70, 229, 0.05);
        }

        .flow-step-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .flow-step-label {
            font-weight: 500;
            font-size: 0.95rem;
        }

        .flow-step-control {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .acquisition-toggle {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--bg-glass);
            border-radius: 12px;
            margin-bottom: 1rem;
        }

        .status-indicator {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .status-complete {
            background: var(--success);
        }

        .status-incomplete {
            background: var(--warning);
        }

        .date-input {
            width: 150px;
            padding: 0.5rem;
            background: rgba(255,255,255,0.1);
            border: 1px solid var(--border-glass);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .bca-status-box {
            padding: 2rem;
            border-radius: 16px;
            text-align: center;
            margin-top: 2rem;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .bca-approved {
            background: rgba(34, 197, 94, 0.2);
            color: var(--success);
            border: 2px solid var(--success);
        }

        .bca-pending {
            background: rgba(251, 191, 36, 0.2);
            color: var(--warning);
            border: 2px solid var(--warning);
        }

        .pa-section {
            background: var(--bg-card);
            border: 1px solid var(--border-glass);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .pa-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: var(--bg-glass);
            border-radius: 8px;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .pa-status-select {
            padding: 0.4rem 0.8rem;
            background: var(--bg-secondary);
            border: 1px solid var(--border-glass);
            border-radius: 6px;
            color: var(--text-primary);
            cursor: pointer;
            font-size: 0.85rem;
        }

        select[disabled] {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .form-grid-mobilisation {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group-mobilisation {
            display: flex;
            flex-direction: column;
        }

        .form-group-mobilisation label {
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.4rem;
            color: var(--text-secondary);
        }

        .form-group-mobilisation select,
        .form-group-mobilisation input {
            padding: 0.6rem;
            background: rgba(255,255,255,0.1);
            border: 1px solid var(--border-glass);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .sequential-arrow {
            text-align: center;
            color: var(--text-muted);
            font-size: 1.5rem;
            margin: 1rem 0;
        }

        .dependent-section {
            opacity: 0.6;
            pointer-events: none;
            background: var(--bg-glass);
            padding: 1rem;
            border-radius: 12px;
            margin-top: 1rem;
        }

        .dependent-section.active {
            opacity: 1;
            pointer-events: auto;
            background: transparent;
        }

        .permission-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            background: rgba(79, 70, 229, 0.2);
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--accent-blue);
        }

        .view-only-notice {
            background: rgba(251, 191, 36, 0.2);
            border: 1px solid var(--warning);
            padding: 1rem;
            border-radius: 12px;
            color: var(--warning);
            margin-bottom: 1.5rem;
            text-align: center;
            font-weight: 500;
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
                    <div class="header-subtitle">Mobilisation Tracking</div>
                </div>
            </div>
            <div class="header-right">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="projects.php" class="nav-link">Projects</a>
                <a href="mobilization.php" class="nav-link" style="background: var(--accent-blue);">Mobilisation</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
        </div>
    </div>

    <div class="main-container">
        <h1 class="page-title">Mobilisation Status Tracker</h1>

        <?php if (!$can_update): ?>
            <div class="view-only-notice">
                📋 You have view-only access. Contact an administrator to make changes.
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="message success" style="margin-bottom: 2rem;"><?php echo htmlspecialchars($success_message); ?></div>
        <?php elseif (!empty($error_message)): ?>
            <div class="message error" style="margin-bottom: 2rem;"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if (count($projects) === 0): ?>
            <div class="empty-state">
                <h3>No projects yet</h3>
                <p>Get started by creating your first project.</p>
                <a href="create-project.php" class="btn" style="max-width: 200px;">Create First Project</a>
            </div>
        <?php else: ?>
            <div class="mobilisation-container">
                <!-- Project Selector -->
                <div class="project-selector">
                    <h3 style="margin-bottom: 1rem; font-size: 1rem;">Projects</h3>
                    <?php foreach ($projects as $project): ?>
                        <a href="?project_id=<?php echo $project['id']; ?>" 
                           class="project-item <?php echo ($selected_project && $selected_project['id'] === $project['id']) ? 'active' : ''; ?>">
                            <div class="project-item-name"><?php echo htmlspecialchars($project['name']); ?></div>
                            <div class="project-item-meta">
                                <?php echo htmlspecialchars($project['client_name']); ?> 
                                (<?php echo $project['pa_count']; ?> PA<?php echo $project['pa_count'] !== 1 ? 's' : ''; ?>)
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Mobilisation Flow -->
                <div class="mobilisation-flow">
                    <?php if ($selected_project): ?>
                        <h2 style="margin-bottom: 1.5rem;"><?php echo htmlspecialchars($selected_project['name']); ?></h2>
                        
                        <!-- PA Numbers Section -->
                        <?php if ($pa_numbers): ?>
                            <div class="pa-section">
                                <div style="font-weight: 600; margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.9rem;">PA NUMBERS</div>
                                <?php foreach ($pa_numbers as $pa): ?>
                                    <div class="pa-item">
                                        <span><?php echo htmlspecialchars($pa['pa_number']); ?></span>
                                        <span class="status-badge status-<?php echo str_replace(' ', '-', $pa['pa_status']); ?>">
                                            <?php echo ucfirst($pa['pa_status']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="project_id" value="<?php echo $selected_project['id']; ?>">
                            <input type="hidden" name="update_steps" value="1">

                            <!-- In-House Projects: Acquisition Complete -->
                            <?php if ($selected_project['type'] === 'in-house'): ?>
                                <div class="flow-section">
                                    <div class="flow-section-title">📋 Acquisition Status</div>
                                    <div class="acquisition-toggle">
                                        <div class="status-indicator <?php echo $mobilisation_steps['acquisition_complete'] ? 'status-complete' : 'status-incomplete'; ?>"></div>
                                        <div style="flex: 1;">
                                            <label style="display: block; font-weight: 500; margin-bottom: 0.5rem;">
                                                Acquisition Complete
                                                <?php if ($can_update): ?>
                                                    <input type="checkbox" name="acquisition_complete" value="1" 
                                                           <?php echo $mobilisation_steps['acquisition_complete'] ? 'checked' : ''; ?> 
                                                           style="margin-left: 1rem; cursor: pointer; width: 18px; height: 18px;">
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="form-grid-mobilisation" style="margin-top: 1rem;">
                                        <div class="form-group-mobilisation">
                                            <label>Target Date</label>
                                            <input type="date" name="acquisition_target_date" 
                                                   value="<?php echo $mobilisation_steps['acquisition_target_date']; ?>"
                                                   <?php echo !$can_update ? 'disabled' : ''; ?>>
                                        </div>
                                        <div class="form-group-mobilisation">
                                            <label>Actual Date</label>
                                            <input type="date" name="acquisition_actual_date" 
                                                   value="<?php echo $mobilisation_steps['acquisition_actual_date']; ?>"
                                                   <?php echo !$can_update ? 'disabled' : ''; ?>>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Applicable to All Projects -->
                            <div class="flow-section">
                                <div class="flow-section-title">🔄 Initial Steps</div>
                                
                                <div class="flow-step">
                                    <div class="flow-step-header">
                                        <label class="flow-step-label">Archeologist Assigned</label>
                                        <select name="archeologist_assigned" class="pa-status-select" <?php echo !$can_update ? 'disabled' : ''; ?>>
                                            <option value="NA" <?php echo $mobilisation_steps['archeologist_assigned'] === 'NA' ? 'selected' : ''; ?>>NA</option>
                                            <option value="Yes" <?php echo $mobilisation_steps['archeologist_assigned'] === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                            <option value="No" <?php echo $mobilisation_steps['archeologist_assigned'] === 'No' ? 'selected' : ''; ?>>No</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="flow-step">
                                    <div class="flow-step-header">
                                        <label class="flow-step-label">Change of Applicant</label>
                                        <select name="change_of_applicant" class="pa-status-select" <?php echo !$can_update ? 'disabled' : ''; ?>>
                                            <option value="NA" <?php echo $mobilisation_steps['change_of_applicant'] === 'NA' ? 'selected' : ''; ?>>NA</option>
                                            <option value="Complete" <?php echo $mobilisation_steps['change_of_applicant'] === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                                            <option value="Not Complete" <?php echo $mobilisation_steps['change_of_applicant'] === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Parallel Steps -->
                            <div class="flow-section">
                                <div class="flow-section-title">⚡ Parallel Activities (Independent)</div>
                                
                                <div class="flow-step">
                                    <div class="flow-step-header">
                                        <label class="flow-step-label">Geological Test</label>
                                        <select name="geological_test" class="pa-status-select" <?php echo !$can_update ? 'disabled' : ''; ?>>
                                            <option value="NA" <?php echo $mobilisation_steps['geological_test'] === 'NA' ? 'selected' : ''; ?>>NA</option>
                                            <option value="Complete" <?php echo $mobilisation_steps['geological_test'] === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                                            <option value="Not Complete" <?php echo $mobilisation_steps['geological_test'] === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                                            <option value="Awaiting Result" <?php echo $mobilisation_steps['geological_test'] === 'Awaiting Result' ? 'selected' : ''; ?>>Awaiting Result</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="flow-step">
                                    <div class="flow-step-header">
                                        <label class="flow-step-label">Condition Report Contacts</label>
                                        <select name="condition_report_contacts" class="pa-status-select" <?php echo !$can_update ? 'disabled' : ''; ?>>
                                            <option value="Not Started" <?php echo $mobilisation_steps['condition_report_contacts'] === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                                            <option value="In Process" <?php echo $mobilisation_steps['condition_report_contacts'] === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                                            <option value="Complete" <?php echo $mobilisation_steps['condition_report_contacts'] === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="flow-step">
                                    <div class="flow-step-header">
                                        <label class="flow-step-label">Condition Reports</label>
                                        <select name="condition_reports" class="pa-status-select" <?php echo !$can_update ? 'disabled' : ''; ?>>
                                            <option value="Not Started" <?php echo $mobilisation_steps['condition_reports'] === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                                            <option value="In Process" <?php echo $mobilisation_steps['condition_reports'] === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                                            <option value="Complete" <?php echo $mobilisation_steps['condition_reports'] === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Sequential: Method Statements (Dependent on Geological Test + Condition Reports) -->
                            <div class="flow-section">
                                <div class="flow-section-title">📄 Method Statements</div>
                                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                                    ⚠️ Available once Geological Test AND Condition Reports are Complete
                                </p>
                                <div class="dependent-section" id="method-statements-section">
                                    <div class="flow-step">
                                        <div class="flow-step-header">
                                            <label class="flow-step-label">Method Statements</label>
                                            <select name="method_statements" class="pa-status-select" <?php echo !$can_update ? 'disabled' : ''; ?>>
                                                <option value="Not Complete" <?php echo $mobilisation_steps['method_statements'] === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                                                <option value="Complete" <?php echo $mobilisation_steps['method_statements'] === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Guarantees (Dependent on Method Statements Complete) -->
                            <div class="flow-section">
                                <div class="flow-section-title">🏛️ Guarantees & Insurance</div>
                                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                                    ⚠️ Available once Method Statements are Complete
                                </p>
                                <div class="dependent-section" id="guarantees-section">
                                    <div class="flow-step">
                                        <div class="flow-step-header">
                                            <label class="flow-step-label">Insurance</label>
                                            <select name="insurance" class="pa-status-select" <?php echo !$can_update ? 'disabled' : ''; ?>>
                                                <option value="Not Started" <?php echo $mobilisation_steps['insurance'] === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                                                <option value="In Process" <?php echo $mobilisation_steps['insurance'] === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                                                <option value="Complete" <?php echo $mobilisation_steps['insurance'] === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="flow-step">
                                        <div class="flow-step-header">
                                            <label class="flow-step-label">Pavement Guarantee</label>
                                            <select name="pavement_guarantee" class="pa-status-select" <?php echo !$can_update ? 'disabled' : ''; ?>>
                                                <option value="Not Started" <?php echo $mobilisation_steps['pavement_guarantee'] === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                                                <option value="In Process" <?php echo $mobilisation_steps['pavement_guarantee'] === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                                                <option value="Complete" <?php echo $mobilisation_steps['pavement_guarantee'] === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="flow-step">
                                        <div class="flow-step-header">
                                            <label class="flow-step-label">Wellbeing Guarantee</label>
                                            <select name="wellbeing_guarantee" class="pa-status-select" <?php echo !$can_update ? 'disabled' : ''; ?>>
                                                <option value="Not Started" <?php echo $mobilisation_steps['wellbeing_guarantee'] === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                                                <option value="In Process" <?php echo $mobilisation_steps['wellbeing_guarantee'] === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                                                <option value="Complete" <?php echo $mobilisation_steps['wellbeing_guarantee'] === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="flow-step">
                                        <div class="flow-step-header">
                                            <label class="flow-step-label">Umbrella Guarantee</label>
                                            <select name="umbrella_guarantee" class="pa-status-select" <?php echo !$can_update ? 'disabled' : ''; ?>>
                                                <option value="Not Started" <?php echo $mobilisation_steps['umbrella_guarantee'] === 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                                                <option value="In Process" <?php echo $mobilisation_steps['umbrella_guarantee'] === 'In Process' ? 'selected' : ''; ?>>In Process</option>
                                                <option value="Complete" <?php echo $mobilisation_steps['umbrella_guarantee'] === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Final Step: Responsibility Form -->
                            <div class="flow-section">
                                <div class="flow-section-title">📋 Final Clearance</div>
                                <div class="flow-step">
                                    <div class="flow-step-header">
                                        <label class="flow-step-label">Responsibility Form</label>
                                        <select name="responsibility_form" class="pa-status-select" <?php echo !$can_update ? 'disabled' : ''; ?>>
                                            <option value="Not Complete" <?php echo $mobilisation_steps['responsibility_form'] === 'Not Complete' ? 'selected' : ''; ?>>Not Complete</option>
                                            <option value="Complete" <?php echo $mobilisation_steps['responsibility_form'] === 'Complete' ? 'selected' : ''; ?>>Complete</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- BCA Clearance Status -->
                                <div class="bca-status-box <?php echo $should_auto_clear_bca ? 'bca-approved' : 'bca-pending'; ?>">
                                    <div>🔒 BCA Clearance: <strong><?php echo $should_auto_clear_bca ? 'YES ✅' : 'PENDING ⏳'; ?></strong></div>
                                    <div style="font-size: 0.85rem; font-weight: 400; margin-top: 0.5rem; opacity: 0.9;">
                                        <?php if ($should_auto_clear_bca): ?>
                                            All requirements met - project ready for BCA clearance
                                        <?php else: ?>
                                            Complete all required steps to unlock BCA clearance
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($can_update): ?>
                                <button type="submit" class="btn" style="margin-top: 2rem; width: 100%;">💾 Save Changes</button>
                            <?php endif; ?>
                        </form>
                    <?php else: ?>
                        <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                            <p style="font-size: 1.1rem; margin-bottom: 1rem;">Select a project to view mobilisation details</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function updateDependentSections() {
            const methodStatementsSection = document.getElementById('method-statements-section');
            const guaranteesSection = document.getElementById('guarantees-section');
            
            // Get current values from form
            const form = document.querySelector('form');
            if (!form) return;
            
            const geologicalTest = form.querySelector('select[name="geological_test"]')?.value;
            const conditionReports = form.querySelector('select[name="condition_reports"]')?.value;
            const methodStatements = form.querySelector('select[name="method_statements"]')?.value;
            
            // Enable Method Statements if both conditions are met
            const canDoMethodStatements = geologicalTest === 'Complete' && conditionReports === 'Complete';
            if (methodStatementsSection) {
                methodStatementsSection.classList.toggle('active', canDoMethodStatements);
                const selects = methodStatementsSection.querySelectorAll('select');
                selects.forEach(s => s.disabled = !canDoMethodStatements);
            }
            
            // Enable Guarantees if Method Statements is Complete
            const canDoGuarantees = methodStatements === 'Complete';
            if (guaranteesSection) {
                guaranteesSection.classList.toggle('active', canDoGuarantees);
                const selects = guaranteesSection.querySelectorAll('select');
                selects.forEach(s => s.disabled = !canDoGuarantees);
            }
        }

        // Initialize and listen for changes
        if (document.querySelector('form')) {
            updateDependentSections();
            
            document.querySelectorAll('select').forEach(select => {
                select.addEventListener('change', updateDependentSections);
            });
        }
    </script>
</body>
</html>
