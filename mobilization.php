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
    <title>Mobilization - Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .header { 
            background: linear-gradient(135deg, #1f77b4, #155994); 
            color: white; padding: 1rem 2rem; display: flex; 
            justify-content: space-between; align-items: center; 
        }
        .logo-nav { width: 50px; }
        .nav-link { color: white; text-decoration: none; padding: 0.5rem 1rem; margin-left: 1rem; background: rgba(255,255,255,0.2); border-radius: 5px; }
        .nav-link:hover { background: rgba(255,255,255,0.3); }
        .container { max-width: 1400px; margin: 2rem auto; padding: 0 2rem; }
        h1 { color: #1f77b4; margin-bottom: 2rem; }
        .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .metric-card { background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
        .metric-value { font-size: 2.5rem; font-weight: bold; color: #1f77b4; }
        .metric-label { color: #666; font-size: 0.9rem; text-transform: uppercase; }
        table { width: 100%; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; color: #333; }
        .status { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 500; }
        .status.Mobilised { background: #d4edda; color: #155724; }
        .status.Pending { background: #fff3cd; color: #856404; }
        .status.In { background: #cce5ff; color: #004085; }
    </style>
</head>
<body>
    <div class="header">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <img src="logo.png" alt="Logo" class="logo-nav" onerror="this.src='logo_icon.png'">
            <h2>Estate Hub Malta</h2>
        </div>
        <div>
            <a href="dashboard.php" class="nav-link">📊 Dashboard</a>
            <a href="api/auth.php?logout=1" class="nav-link">🚪 Logout</a>
        </div>
    </div>
    
    <div class="container">
        <h1>🚧 Mobilization Tracker</h1>
        
        <div class="metrics">
            <div class="metric-card">
                <div class="metric-value">6</div>
                <div class="metric-label">Total Projects</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">1</div>
                <div class="metric-label">✅ Mobilised</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">3</div>
                <div class="metric-label">⏳ Pending</div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Client</th>
                    <th>City</th>
                    <th>PA Number</th>
                    <th>BCA Status</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Dirjanu Supermarket</td>
                    <td>Agius</td>
                    <td>Ghajnsielem</td>
                    <td>7246/22</td>
                    <td>DONE</td>
                    <td><span class="status Mobilised">Mobilised</span></td>
                </tr>
                <tr>
                    <td>Hotel All Season</td>
                    <td>Blue Clay</td>
                    <td>Victoria</td>
                    <td>7298/24</td>
                    <td>AWAITING</td>
                    <td><span class="status In">In Process</span></td>
                </tr>
                <tr>
                    <td>Cutajar Houses</td>
                    <td>Excel Investments</td>
                    <td>Xaghra</td>
                    <td>4893/23</td>
                    <td>NO</td>
                    <td><span class="status Pending">Pending</span></td>
                </tr>
                <tr>
                    <td>Ex BOV Nadur</td>
                    <td>Excel Investments</td>
                    <td>Nadur</td>
                    <td>575/24</td>
                    <td>AWAITING</td>
                    <td><span class="status In">In Process</span></td>
                </tr>
                <tr>
                    <td>Hotel Ghajnsielem</td>
                    <td>Blue Clay</td>
                    <td>Ghajnsielem</td>
                    <td>753/25</td>
                    <td>AWAITING</td>
                    <td><span class="status Pending">Pending</span></td>
                </tr>
                <tr>
                    <td>Hotel Qala</td>
                    <td>Blue Clay</td>
                    <td>Qala</td>
                    <td>3698/24</td>
                    <td>AWAITING</td>
                    <td><span class="status Pending">Pending</span></td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>
