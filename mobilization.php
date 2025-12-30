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

$projects = $pdo->query("SELECT * FROM projects ORDER BY created_at DESC LIMIT 10")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mobilization - Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); min-height: 100vh; }
        .header { background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .logo-nav { width: 50px; }
        .nav-link { color: #1f77b4; text-decoration: none; padding: 0.5rem 1rem; margin-left: 1rem; background: rgba(31,119,180,0.1); border-radius: 25px; font-weight: 500; transition: all 0.3s; }
        .nav-link:hover { background: rgba(31,119,180,0.2); transform: translateY(-2px); }
        .container { max-width: 1400px; margin: 2rem auto; padding: 0 2rem; }
        h1 { color: white; text-shadow: 0 2px 10px rgba(0,0,0,0.3); margin-bottom: 2rem; font-size: 3rem; text-align: center; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem; margin-bottom: 3rem; }
        .stat-card { background: rgba(255,255,255,0.95); backdrop-filter: blur(20px); padding: 2.5rem; border-radius: 25px; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.1); transition: transform 0.3s; position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #1f77b4, #ff6b6b); }
        .stat-card:hover { transform: translateY(-10px); }
        .stat-number { font-size: 4rem; font-weight: 800; background: linear-gradient(135deg, #1f77b4, #ff6b6b); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin-bottom: 0.5rem; }
        .stat-label { color: #666; font-size: 1.1rem; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; }
        .projects-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 2rem; }
        .project-card { background: rgba(255,255,255,0.95); backdrop-filter: blur(20px); border-radius: 20px; padding: 2rem; box-shadow: 0 20px 40px rgba(0,0,0,0.1); transition: all 0.3s; border-top: 5px solid #1f77b4; }
        .project-card:hover { transform: translateY(-5px); box-shadow: 0 30px 60px rgba(0,0,0,0.15); }
        .project-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
        .project-title { font-size: 1.5rem; font-weight: 700; color: #1f77b4; }
        .status-badge { padding: 0.5rem 1rem; border-radius: 25px; font-weight: 600; font-size: 0.9rem; }
        .status.Mobilised { background: linear-gradient(135deg, #d4edda, #c3e6cb); color: #155724; }
        .status.Pending { background: linear-gradient(135deg, #fff3cd, #ffeaa7); color: #856404; }
        .status['In Process'] { background: linear-gradient(135deg, #cce5ff, #99ccff); color: #004085; }
        .project-details { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: 0.95rem; }
        .detail-label { font-weight: 600; color: #666; }
        .create-btn { position: fixed; top: 120px; right: 2rem; background: linear-gradient(135deg, #1f77b4, #155994); color: white; border: none; padding: 1rem 1.5rem; border-radius: 50px; font-size: 1rem; font-weight: 600; cursor: pointer; box-shadow: 0 10px 30px rgba(31,119,180,0.4); transition: all 0.3s; z-index: 1000; }
        .create-btn:hover { transform: scale(1.05); box-shadow: 0 15px 40px rgba(31,119,180,0.5); }
        @media (max-width: 768px) { .projects-grid { grid-template-columns: 1fr; } .project-details { grid-template-columns: 1fr; } .create-btn { position: static; width: 100%; margin: 2rem 0; } }
    </style>
</head>
<body>
    <div class="header">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <img src="logo.png" alt="Logo" class="logo-nav" onerror="this.src='logo_icon.png'">
            <h2 style="background: linear-gradient(135deg, #1f77b4, #ff6b6b); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Estate Hub Malta</h2>
        </div>
        <div>
            <a href="dashboard.php" class="nav-link">📊 Dashboard</a>
            <?php if ($is_admin): ?>
                <a href="create-project.php" class="nav-link">➕ New Project</a>
            <?php endif; ?>
            <a href="api/auth.php?logout=1" class="nav-link">🚪 Logout</a>
        </div>
    </div>
    
    <div class="container">
        <h1>🚧 Mobilization Overview</h1>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="background: linear-gradient(135deg, #28a745, #20c997); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?php echo $stats['mobilised']; ?></div>
                <div class="stat-label">✅ Mobilised</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="background: linear-gradient(135deg, #ffc107, #ffed4a); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?php echo $stats['pending']; ?></div>
                <div class="stat-label">⏳ Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="background: linear-gradient(135deg, #007bff, #0056b3); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?php echo $stats['in_process']; ?></div>
                <div class="stat-label">🔄 In Process</div>
            </div>
        </div>
        
        <div class="projects-grid">
            <?php foreach ($projects as $project): ?>
            <div class="project-card">
                <div class="project-header">
                    <div class="project-title"><?php echo htmlspecialchars($project['name']); ?></div>
                    <span class="status-badge status <?php echo $project['status']; ?>"><?php echo $project['status']; ?></span>
                </div>
                <div class="project-details">
                    <div><span class="detail-label">Client:</span> <?php echo htmlspecialchars($project['client']); ?></div>
                    <div><span class="detail-label">City:</span> <?php echo htmlspecialchars($project['city']); ?></div>
                    <div><span class="detail-label">PA #:</span> <?php echo htmlspecialchars($project['pa_number'] ?? 'N/A'); ?></div>
                    <div><span class="detail-label">BCA:</span> <?php echo htmlspecialchars($project['bca_status'] ?? 'N/A'); ?></div>
                    <div><span class="detail-label">Type:</span> 
                        <span style="padding: 0.25rem 0.75rem; background: <?php echo $project['type'] === 'in-house' ? '#d1ecf1' : '#f8d7da'; ?>; border-radius: 15px; font-size: 0.8rem; font-weight: 500;">
                            <?php echo ucwords(str_replace('-', ' ', $project['type'])); ?>
                        </span>
                    </div>
                    <?php if ($project['finish_level']): ?>
                    <div><span class="detail-label">Finish:</span> <?php echo $project['finish_level']; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($projects)): ?>
            <div class="project-card" style="grid-column: 1 / -1; text-align: center; color: #666;">
                <h3>🎉 No projects yet!</h3>
                <?php if ($is_admin): ?>
                    <p><a href="create-project.php" style="color: #1f77b4; font-weight: 600; font-size: 1.2rem;">➕ Create your first project</a></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
