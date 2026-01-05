<?php
/**
 * Updated Dashboard Example
 * Shows how to integrate user management into existing dashboard
 */

session_start();
require_once 'config.php';
require_once 'includes/user-functions.php';

// Require login - users must be logged in
requireLogin();

// Get projects visible to current user (respects assignments)
// This replaces: $pdo->query("SELECT * FROM projects")
$projects = getVisibleProjects($pdo);

// Get counts for dashboard
$projectCount = count($projects);
$userCount = isAdmin() ? $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;
$mobilisedCount = $pdo->query("
    SELECT COUNT(*) FROM project_mobilisation 
    WHERE bca_clearance = 'Yes'
")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Estate Hub</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .user-greeting {
            color: var(--color-text-secondary);
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
        }
        
        .user-role-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: capitalize;
        }
        
        .user-role-badge.admin {
            background: rgba(192, 21, 47, 0.15);
            color: var(--color-error);
        }
        
        .user-role-badge.manager {
            background: rgba(230, 129, 97, 0.15);
            color: var(--color-warning);
        }
        
        .user-role-badge.architect {
            background: rgba(45, 166, 178, 0.15);
            color: var(--color-primary);
        }
        
        .user-role-badge.viewer {
            background: rgba(98, 108, 113, 0.15);
            color: var(--color-text-secondary);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--color-surface);
            border-radius: 8px;
            padding: 1.5rem;
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-sm);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--color-primary);
        }
        
        .stat-label {
            color: var(--color-text-secondary);
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header with User Info -->
        <header class="dashboard-header">
            <div>
                <h1>Project Overview</h1>
                <div class="user-greeting">
                    Welcome, <?php echo htmlspecialchars(getCurrentUserFullName()); ?>
                    <span class="user-role-badge <?php echo htmlspecialchars($_SESSION['role']); ?>">
                        <?php echo htmlspecialchars($_SESSION['role']); ?>
                    </span>
                </div>
            </div>
            <div class="action-buttons">
                <!-- User Management Link (Admin Only) -->
                <?php if (isAdmin()): ?>
                    <a href="users-management.php" class="btn btn--secondary">Manage Users</a>
                <?php endif; ?>
                
                <!-- Create Project Button (Admin & Manager Only) -->
                <?php if (canEditGlobally()): ?>
                    <a href="create-project.php" class="btn btn--primary">+ New Project</a>
                <?php endif; ?>
                
                <!-- Logout Button -->
                <a href="includes/auth.php?logout" class="btn btn--outline">Logout</a>
            </div>
        </header>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $projectCount; ?></div>
                <div class="stat-label">Active Projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $mobilisedCount; ?></div>
                <div class="stat-label">Mobilised</div>
            </div>
            <?php if (isAdmin()): ?>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $userCount; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Projects Table -->
        <div class="card">
            <div class="card__header">
                <h3>Projects</h3>
            </div>
            <div class="card__body">
                <?php if (empty($projects)): ?>
                    <p style="color: var(--color-text-secondary); text-align: center; padding: 2rem;">
                        <?php if (isAdmin()): ?>
                            No projects yet. <a href="create-project.php">Create your first project</a>
                        <?php else: ?>
                            You are not assigned to any projects yet. Contact an administrator.
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--color-border);">
                                <th style="padding: 1rem; text-align: left; font-weight: 600;">Project Name</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600;">Client</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600;">Type</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600;">Status</th>
                                <th style="padding: 1rem; text-align: center; font-weight: 600;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $project): 
                                $canEdit = canEditProject($pdo, $project['id']);
                                $mobilisationStatus = deriveMobilisationStatus($pdo, $project['id']);
                            ?>
                                <tr style="border-bottom: 1px solid var(--color-border);">
                                    <td style="padding: 1rem;">
                                        <strong><?php echo htmlspecialchars($project['name']); ?></strong>
                                    </td>
                                    <td style="padding: 1rem;">
                                        <?php echo htmlspecialchars($project['client_name'] ?? 'N/A'); ?>
                                    </td>
                                    <td style="padding: 1rem;">
                                        <span style="text-transform: capitalize;">
                                            <?php echo htmlspecialchars($project['type']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 1rem;">
                                        <span style="
                                            display: inline-block;
                                            padding: 0.25rem 0.75rem;
                                            border-radius: 20px;
                                            font-size: 0.85rem;
                                            background: 
                                                <?php echo $mobilisationStatus === 'Mobilised' 
                                                    ? 'rgba(33, 128, 141, 0.15)' 
                                                    : ($mobilisationStatus === 'In Process' 
                                                        ? 'rgba(230, 129, 97, 0.15)' 
                                                        : 'rgba(98, 108, 113, 0.15)'); ?>;
                                            color: 
                                                <?php echo $mobilisationStatus === 'Mobilised' 
                                                    ? 'var(--color-success)' 
                                                    : ($mobilisationStatus === 'In Process' 
                                                        ? 'var(--color-warning)' 
                                                        : 'var(--color-text-secondary)'); ?>;
                                        ">
                                            <?php echo htmlspecialchars($mobilisationStatus); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 1rem; text-align: center;">
                                        <a href="mobilization.php?project_id=<?php echo $project['id']; ?>" 
                                           class="btn btn--outline btn--sm">View</a>
                                        
                                        <?php if ($canEdit): ?>
                                            <a href="mobilisation_detail.php?project_id=<?php echo $project['id']; ?>" 
                                               class="btn btn--secondary btn--sm">Edit</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Info Section for Non-Admins -->
        <?php if (!isAdmin() && empty($projects)): ?>
            <div class="card" style="margin-top: 2rem;">
                <div class="card__body">
                    <h4>How to Get Started</h4>
                    <p>You are currently logged in as <strong><?php echo htmlspecialchars(getCurrentUserFullName()); ?></strong> 
                    with role <strong><?php echo htmlspecialchars($_SESSION['role']); ?></strong>.</p>
                    <p>You will need to be assigned to at least one project by an administrator to see project data. 
                    Please contact your system administrator.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
