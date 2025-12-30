<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem;">
    <div style="background: white; padding: 3rem; border-radius: 25px; box-shadow: var(--shadow); max-width: 500px; width: 100%;">
        <div style="text-align: center; margin-bottom: 2rem;">
            <img src="logo.png" alt="Estate Hub Malta" style="width: 120px; margin-bottom: 1rem;" onerror="this.src='logo_icon.png'">
            <h1 style="color: #1f77b4; font-size: 2.5rem; margin-bottom: 0.5rem;">Estate Hub Malta</h1>
            <p style="color: #666; font-size: 1.1rem;">Malta's Premier Property Dashboard</p>
        </div>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="message" style="background: #fee2e2; color: #dc2626; border-color: #fecaca;">❌ Invalid credentials</div>
        <?php endif; ?>
        
        <form method="POST" action="api/auth.php" style="display: flex; flex-direction: column; gap: 1rem;">
            <input type="text" name="username" placeholder="👤 Username" required style="padding: 1rem; border: 2px solid #e1e5e9; border-radius: 12px; font-size: 1rem;">
            <input type="password" name="password" placeholder="🔑 Password" required style="padding: 1rem; border: 2px solid #e1e5e9; border-radius: 12px; font-size: 1rem;">
            <button type="submit" style="background: linear-gradient(135deg, #1f77b4, #155994); color: white; border: none; padding: 1rem; border-radius: 12px; font-size: 1.1rem; font-weight: 600; cursor: pointer; transition: all 0.3s;">🚀 Enter Hub</button>
        </form>
        
        <div style="font-size: 0.9rem; color: #666; margin-top: 1.5rem; background: #f8f9fa; padding: 1rem; border-radius: 8px;">
            admin/Pra2026! | manager/Site2026! | viewer/View2026!
        </div>
    </div>
</body>
</html>
