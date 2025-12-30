<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['user']) || $_SESSION['user'] !== 'admin') {
    session_destroy();
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$error = '';
if ($_POST) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($username === 'admin' && $password === 'admin') {
        $_SESSION['loggedin'] = true;
        $_SESSION['user'] = 'admin';
        header('Location: mobilization.php');
        exit;
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Estate Hub Malta</title>
    <link rel="icon" href="logoicon.png">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="main-container" style="min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem;">
        <div class="login-card">
            <div class="login-header">
                <img src="logo.png" alt="Estate Hub Malta" class="login-logo" onerror="this.src='logoicon.png'">
                <h1 class="login-title">Estate Hub Malta</h1>
                <p class="login-subtitle">Project Management System</p>
            </div>
            
            <?php if ($error): ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" class="login-form">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required placeholder="Enter username" 
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="Enter password">
                </div>
                <button type="submit" class="btn">Sign In</button>
            </form>
            <div class="login-footer">
                <p>Demo: <strong>admin</strong> / <strong>admin</strong></p>
            </div>
        </div>
    </div>
</body>
</html>
