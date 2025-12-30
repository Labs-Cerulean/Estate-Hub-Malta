<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: index.php'); exit;
}
require_once 'config.php';

$pdo = getDB();
$is_admin = $_SESSION['user'] === 'admin';

$stats = [
    'total' => $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn(),
    'mobilised' => $pdo->query("SELECT COUNT(*) FROM projects WHERE status='Mobilised'")->fetchColumn(),
    'pending' => $pdo->query("SELECT COUNT(*) FROM projects WHERE status='Pending'")->fetchColumn(),
    'in_process' => $pdo->query("SELECT COUNT(*) FROM projects WHERE status='In Process'")->fetchColumn(),
];

$projects = $pdo->query("
    SELECT p.*, c.name as client_name 
    FROM projects p 
    JOIN clients c ON p.client_id = c.id 
    ORDER BY p.created_at DESC LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mobilization Dashboard - Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; 
            background: #0a0e17; color: #ffffff; line-height: 1.6; 
        }
        .header { 
            background: rgba(255,255,255,0.05); backdrop-filter: blur(20px); 
            border-bottom: 1px solid rgba(255,255,255,0.1); 
            padding: 1.5rem 0; position: sticky; top: 0; z-index: 100;
        }
        .header-container { 
            max-width: 1440px; margin: 0 auto; padding: 0 2rem; 
            display: flex; justify-content: space-between; align-items: center; 
        }
        .logo-nav { width: 48px; height: 48px; border-radius: 12px; }
        .header-right { display: flex; align-items: center; gap: 1.5rem; }
        .nav-link { 
            color: #ffffff; text-decoration: none; padding: 0.75rem 1.5rem; 
            background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.1); 
            border-radius: 12px; font-weight: 500; font-size: 0.9rem; 
            transition: all 0.3s ease; backdrop-filter: blur(10px);
        }
        .nav-link:hover { background: rgba(255,255,255,0.15); transform: translateY(-2px); }
        .main-container { max-width: 1440px; margin: 0 auto; padding: 4rem 2rem 2rem; }
        .page-title { 
            font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 700; 
            margin-bottom: 3rem; background: linear-gradient(135deg, #ffffff, #a0a0a0); 
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; 
            background-clip: text;
        }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 2rem; margin-bottom: 4rem; }
        .stat-card { 
            background: rgba(255,255,255,0.05); backdrop-filter: blur(20px); 
            border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; 
            padding: 2.5rem; text-align: center; transition: all 0.4s ease; 
            position: relative; overflow: hidden;
        }
        .stat-card::before { 
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; 
            background: linear-gradient(90deg, #4f46e5, #ec4899, #f59e0b); 
        }
        .stat-card:hover { transform: translateY(-8px); border-color: rgba(255,255,255,0.2); }
        .stat-number { font-size: clamp(2.5rem, 8vw, 4.5rem); font-weight: 700; color: #ffffff; margin-bottom: 0.5rem; }
        .stat-label { color: rgba(255,255,255,0.7); font-size: 1rem; font-weight: 500; text-transform: uppercase; letter-spacing: 1px; }
        .projects-section { background: rgba(255,255,255,0.02); border-radius: 24px; padding: 3rem; backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.08); }
        .projects-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.5rem; }
        .section-title { font-size: 2rem; font-weight: 600; color: #ffffff; }
        .projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 2rem; }
        .project-card { 
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); 
            border-radius: 20px; padding: 2rem; transition: all 0.3s ease; 
            backdrop-filter: blur(10px);
        }
        .project-card:hover { border-color: rgba(255,255,255,0.2); transform: translateY(-4px); }
        .project-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
        .project-name { font-size: 1.4rem; font-weight: 600; color: #ffffff; }
        .status-badge { 
            padding: 0.5rem 1.2rem; border-radius: 50px; font-size: 0.85rem; font-weight: 600; 
            backdrop-filter: blur(10px);
        }
        .status.Mobilised { background: rgba(34,197,94,0.2); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
        .status.Pending { background: rgba(251,191,36,0.2); color: #fbbc2b; border: 1px solid rgba(251,191,36,0.3); }
        .status[data-status="In Process"] { background: rgba(59,130,246,0.2); color: #3b82f6; border: 1px solid rgba(59,130,246,0.3); }
        .project-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; font-size: 0.95rem; }
        .meta-item { color: rgba(255,255,255,0.8); }
        .meta-label { font-weight: 500; color: rgba(255,255,255,0.6); display: block; font-size: 0.8rem; }
        .empty-state { 
            grid-column: 1 / -1; text-align: center; padding: 4rem 2rem; 
            color: rgba(255,255,255,0.5); border: 2px dashed rgba(255,255,255,0.1); 
            border-radius: 20px; backdrop-filter: blur(10px);
        }
        @media (max-width: 768px) { 
            .main-container { padding: 2rem 1rem; } 
            .projects-grid { grid-template-columns: 1fr; }
            .projects-header { flex-direction: column; gap: 1rem; align-items: stretch; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-container">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <img src="logo.png" alt="Estate Hub Malta" class="logo-nav" onerror="this.src='logo_icon.png'">
                <div>
                    <div style="font-size: 1.4rem; font-weight: 700; color: #ffffff;">Estate Hub Malta</div>
                    <div style="font-size: 0.85rem; color: rgba(255,255,255,0.6);">Mobilization Dashboard</div>
                </div>
            </div>
            <div class="header-right">
                <a href="dashboard.php" class="nav-link">📊 Dashboard</a>
                <?php if ($is_admin): ?>
                    <a href="clients.php" class="nav-link">👥 Clients</a>
                    <a href="create-project.php" class="nav-link">➕ Projects</a>
                <?php endif; ?>
                <a href="api/auth.php?logout=1" class="nav-link">🚪 Logout</a>
            </div>
        </div>
    </header>
    
    <div class="main-container">
        <h1 class="page-title">Mobilization Dashboard</h1>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stat-label">Total Projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #22c55e;"><?php echo number_format($stats['mobilised']); ?></div>
                <div class="stat-label">Mobilised</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #fbbc2b;"><?php echo number_format($stats['pending']); ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #3b82f6;"><?php echo number_format($stats['in_process']); ?></div>
                <div class="stat-label">In Process</div>
            </div>
        </div>
        
        <section class="projects-section">
            <div class="projects-header">
                <div class="section-title">Recent Projects</div>
                <?php if ($is_admin): ?>
                    <a href="create-project.php" class="nav-link" style="padding: 0.75rem 2rem;">➕ New Project</a>
                <?php endif; ?>
            </div>
            <div class="projects-grid">
                <?php foreach ($projects as $project): ?>
                <div class="project-card">
                    <div class="project-header">
                        <div class="project-name"><?php echo htmlspecialchars($project['name']); ?></div>
                        <span class="status-badge status <?php echo $project['status']; ?>" data-status="<?php echo $project['status']; ?>">
                            <?php echo $project['status']; ?>
                        </span>
                    </div>
                    <div class="project-meta">
                        <div class="meta-item">
                            <span class="meta-label">Client</span>
                            <?php echo htmlspecialchars($project['client_name']); ?>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Location</span>
                            <?php echo htmlspecialchars($project['city']); ?>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">PA Number</span>
                            <?php echo htmlspecialchars($project['pa_number'] ?? 'N/A'); ?>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">BCA Status</span>
                            <?php echo htmlspecialchars($project['bca_status'] ?? 'N/A'); ?>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Type</span>
                            <span style="padding: 0.25rem 0.75rem; background: rgba(255,255,255,0.1); border-radius: 12px; font-size: 0.8rem;">
                                <?php echo ucwords(str_replace('-', ' ', $project['type'])); ?>
                            </span>
                        </div>
                        <?php if ($project['finish_level']): ?>
                        <div class="meta-item">
                            <span class="meta-label">Finish Level</span>
                            <?php echo $project['finish_level']; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($projects)): ?>
                <div class="empty-state">
                    <h3 style="font-size: 1.5rem; margin-bottom: 1rem;">No projects yet</h3>
                    <p style="font-size: 1.1rem; margin-bottom: 2rem;">Get started by creating your first project.</p>
                    <?php if ($is_admin): ?>
                        <a href="create-project.php" class="nav-link" style="padding: 1rem 2.5rem; font-size: 1.1rem;">➕ Create First Project</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</body>
</html>
