<?php
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
            <img src="logo.png" alt="Estate Hub Logo" class="login-logo">
            <h1>Estate Hub</h1>
            <p>Project Management System</p>
        </div>

        <?php if ($error): ?>
            <div class="error-message">
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

    </div>
</body>
</html>
