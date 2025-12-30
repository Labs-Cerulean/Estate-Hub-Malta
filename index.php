<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estate Hub Malta</title>
    <link rel="icon" href="logo_icon.png" type="image/png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
        }
        .hero { 
            background: white; 
            padding: 3rem; 
            border-radius: 20px; 
            box-shadow: 0 20px 40px rgba(0,0,0,0.1); 
            text-align: center; 
            max-width: 500px; 
            width: 90%; 
        }
        .logo { width: 120px; margin-bottom: 1rem; }
        h1 { color: #1f77b4; margin-bottom: 0.5rem; font-size: 2.5rem; }
        p { color: #666; margin-bottom: 2rem; }
        .login-form input { 
            width: 100%; padding: 1rem; margin: 0.5rem 0; 
            border: 2px solid #e1e5e9; border-radius: 10px; 
            font-size: 1rem; transition: border-color 0.3s; 
        }
        .login-form input:focus { 
            outline: none; border-color: #1f77b4; 
        }
        .login-form button { 
            width: 100%; padding: 1rem; background: #1f77b4; 
            color: white; border: none; border-radius: 10px; 
            font-size: 1.1rem; cursor: pointer; 
            transition: background 0.3s; margin-top: 1rem;
        }
        .login-form button:hover { background: #155994; }
        .credentials { 
            font-size: 0.9rem; color: #666; margin-top: 1rem; 
            background: rgba(0,0,0,0.05); padding: 0.5rem; 
            border-radius: 5px; 
        }
        .error { color: #dc3545; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="hero">
        <img src="logo.png" alt="Estate Hub Malta" class="logo" onerror="this.src='logo_icon.png'">
        <h1>Estate Hub Malta</h1>
        <p>Malta's Premier Property Dashboard</p>
        
        <form method="POST" action="api/auth.php" class="login-form">
            <input type="text" name="username" placeholder="👤 Username" required>
            <input type="password" name="password" placeholder="🔑 Password" required>
            <button type="submit">🚀 Enter Hub</button>
        </form>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="error">❌ Invalid credentials</div>
        <?php endif; ?>
        
        <div class="credentials">
            admin/Pra2026! | manager/Site2026! | viewer/View2026!
        </div>
    </div>
</body>
</html>
