<?php
/**
 * Plant Hub entry — routes users to Operations or Fleet Dashboard.
 * Dashboard-eligible users see a choice; everyone else goes straight to bookings.
 */
require_once 'config.php';
require_once 'session-check.php';
require_once 'user-functions.php';
require_once __DIR__ . '/includes/nav_config.php';

if (!navCanAccessPlantHub()) {
    die('Unauthorized Access.');
}

$go = $_GET['go'] ?? '';
if ($go === 'bookings') {
    header('Location: plant_bookings.php');
    exit;
}
if ($go === 'dashboard') {
    if (!navCanAccessPlantDashboard()) {
        header('Location: plant_bookings.php');
        exit;
    }
    header('Location: plant_dashboard.php');
    exit;
}

if (!navCanAccessPlantDashboard()) {
    header('Location: plant_bookings.php');
    exit;
}

$canReturnEstate = navCanAccessEstateHub();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plant Hub - Estate Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/styles.css?v=<?= time() ?>">
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(160deg, #0f172a 0%, #134e4a 100%); font-family: 'Inter', sans-serif; padding: 24px; box-sizing: border-box; }
        .plant-entry { width: 100%; max-width: 520px; }
        .plant-entry-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; color: #fff; }
        .plant-entry-top h1 { margin: 0; font-size: 1.6rem; font-weight: 900; }
        .plant-entry-top p { margin: 6px 0 0; color: #99f6e4; font-size: 0.95rem; }
        .plant-entry-back { color: #cbd5e1; text-decoration: none; font-weight: 600; font-size: 0.9rem; }
        .plant-entry-back:hover { color: #fff; }
        .plant-entry-grid { display: grid; gap: 16px; }
        .plant-entry-card { display: block; text-decoration: none; background: rgba(255,255,255,0.98); border-radius: 18px; padding: 28px 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.18); transition: transform 0.15s ease, box-shadow 0.15s ease; border: 2px solid transparent; }
        .plant-entry-card:hover { transform: translateY(-2px); box-shadow: 0 24px 48px rgba(0,0,0,0.22); }
        .plant-entry-card.ops { border-color: #14b8a6; }
        .plant-entry-card.dash { border-color: #6366f1; }
        .plant-entry-icon { font-size: 2rem; margin-bottom: 12px; }
        .plant-entry-card.ops .plant-entry-icon { color: #0d9488; }
        .plant-entry-card.dash .plant-entry-icon { color: #4f46e5; }
        .plant-entry-card h2 { margin: 0 0 8px; font-size: 1.25rem; color: #0f172a; }
        .plant-entry-card p { margin: 0; color: #64748b; line-height: 1.5; font-size: 0.95rem; }
    </style>
</head>
<body>
    <div class="plant-entry">
        <div class="plant-entry-top">
            <div>
                <h1><i class="fas fa-tractor"></i> Plant Hub</h1>
                <p>Choose where you want to go</p>
            </div>
            <?php if ($canReturnEstate): ?>
                <a href="dashboard.php" class="plant-entry-back"><i class="fas fa-arrow-left"></i> Estate Hub</a>
            <?php endif; ?>
        </div>

        <div class="plant-entry-grid">
            <a href="plant_hub.php?go=bookings" class="plant-entry-card ops">
                <div class="plant-entry-icon"><i class="fas fa-calendar-alt"></i></div>
                <h2>Plant Operations</h2>
                <p>Bookings calendar, driver punch-in/out, create and manage jobs.</p>
            </a>
            <a href="plant_hub.php?go=dashboard" class="plant-entry-card dash">
                <div class="plant-entry-icon"><i class="fas fa-chart-line"></i></div>
                <h2>Fleet Dashboard</h2>
                <p>Director command centre — KPIs, live map, and fleet overview.</p>
            </a>
        </div>
    </div>
</body>
</html>
