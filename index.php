<?php
/**
 * LOGIN PAGE - index.php
 * 
 * IMPORTANT: Do NOT include session-check.php here!
 * This is the PUBLIC login page - anyone should access it.
 */

// Start session only on login page
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: dashboard.php');
    exit;
}

// Get any error message from previous login attempt
$error = isset($_GET['error']) ? $_SESSION['login_error'] ?? '' : '';
if ($error) {
    unset($_SESSION['login_error']); // Clear after using
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Estate Hub</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-header">
            <img src="logo.jpg" alt="Estate Hub Logo" class="login-logo">
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
                <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
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
