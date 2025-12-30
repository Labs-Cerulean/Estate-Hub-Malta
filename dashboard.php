<?php
session_start();
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .header { 
            background: linear-gradient(135deg, #1f77b4, #155994); 
            color: white; padding: 1rem 2rem; 
            display: flex; justify-content: space-between; align-items: center; 
        }
        .logo-nav { width: 50px; }
        .user-info { font-weight: 500; }
        .logout { color: white; text-decoration: none; padding: 0.5rem 1rem; background: rgba(255,255,255,0.2); border-radius: 5px; transition: background 0.3s; }
        .logout:hover { background: rgba(255,255,255,0.3); }
        .container { max-width: 1400px; margin: 2rem auto; padding: 0 2rem; }
        .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .metric-card { background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
        .metric-value { font-size: 2.5rem; font-weight: bold; color: #1f77b4; }
        .metric-label { color: #666; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; }
        .tabs { display: flex; background: white; border-radius: 10px; padding: 0.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .tab { padding: 1rem 2rem; text-decoration: none; color: #666; border-radius: 8px; transition: all 0.3s; flex: 1; text-align: center; }
        .tab:hover, .tab.active { background: #1f77b4; color: white; }
    </style>
</head>
<body>
    <div class="header">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <img src="logo.png" alt="Logo" class="logo-nav" onerror="this.src='logo_icon.png'">
            <h2>Estate Hub Malta</h2>
        </div>
        <div class="user-info">
            👋 Welcome, <?php echo htmlspecialchars($_SESSION['user']); ?>!
            <a href="api/auth.php?logout=1" class="logout">🚪 Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="metrics">
            <div class="metric-card">
                <div class="metric-value" data-metric="total">6</div>
                <div class="metric-label">Total Projects</div>
            </div>
            <div class="metric-card">
                <div class="metric-value" data-metric="mobilised">1</div>
                <div class="metric-label">Mobilised</div>
            </div>
            <div class="metric-card">
                <div class="metric-value" data-metric="pending">3</div>
                <div class="metric-label">Pending</div>
            </div>
            <div class="metric-card">
                <div class="metric-value" data-metric="clients">4</div>
                <div class="metric-label">Clients</div>
            </div>
        </div>
        
        <div class="tabs">
            <a href="mobilization.php" class="tab active">🚧 Mobilization</a>
            <a href="#" class="tab">💰 Payments</a>
            <a href="#" class="tab">📋 Suppliers</a>
        </div>
    </div>
</body>
</html>
