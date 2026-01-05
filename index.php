<?php
session_start();
require_once 'config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error = isset($_GET['error']) ? true : false;
$errorMessage = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : '';
unset($_SESSION['login_error']); // Clear error after displaying
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estate Hub - Login</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header h1 {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 28px;
        }
        .login-header p {
            color: #666;
            margin: 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            box-sizing: border-box;
            transition: border-color 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .login-button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        .login-button:active {
            transform: translateY(0);
        }
        .demo-credentials {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            text-align: center;
            font-size: 14px;
            color: #666;
        }
        .demo-credentials strong {
            color: #333;
        }
        .error-message {
            background: #fee;
            color: #c00;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid #c00;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>Estate Hub</h1>
            <p>Project Management System</p>
        </div>

        <?php if ($error && $errorMessage): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="api/auth.php">
            <div class="form-group">
                <label for="username">Username</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    required 
                    autofocus
                    autocomplete="username"
                    placeholder="Enter your username"
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    required
                    autocomplete="current-password"
                    placeholder="Enter your password"
                >
            </div>

            <button type="submit" class="login-button">Sign In</button>
        </form>

        <div class="demo-credentials">
            <p><strong>Demo Credentials:</strong></p>
            <p>Username: <strong>admin</strong></p>
            <p>Password: <strong>Admin123!</strong></p>
        </div>
    </div>
</body>
</html>
