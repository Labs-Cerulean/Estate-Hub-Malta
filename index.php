<?php
// START: Login Page (index.php)
session_start();

// Already logged in? Go to dashboard
if (!empty($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if (isset($_GET['error'])) {
    $error = $_SESSION['login_error'] ?? 'Login failed. Please try again.';
    if (isset($_SESSION['login_error'])) {
        unset($_SESSION['login_error']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estate Hub - Login</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-header">
            <img src="logo.jpg" alt="Logo" class="login-logo" style="width: 64px; height: 64px; margin-bottom: 1rem;">
            <h1>Estate Hub</h1>
            <p>Project Management System</p>
        </div>
        <?php if ($error): ?>
            <div class="error-message" style="background: #fee; color: #c33; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #c33;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <form method="POST" action="api/auth.php" class="login-form">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="admin" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Admin123!" required>
            </div>
            <button type="submit" class="login-button">Sign In</button>
        </form>
        <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #ddd; text-align: center; color: #666; font-size: 0.9rem;">
            <p><strong>Demo Credentials</strong></p>
            <p>Username: <strong>admin</strong></p>
            <p>Password: <strong>Admin123!</strong></p>
        </div>
    </div>
</body>
</html>
<?php
// END: index.php - NO requires, NO session-check
?>
