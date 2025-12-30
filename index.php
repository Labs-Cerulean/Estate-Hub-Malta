<?php
session_start();
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin']) {
    header('Location: dashboard.php');
    exit;
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
    <div class="main-container" style="min-height: 100vh; display: flex; align-items: center; justify-content: center;">
        <div class="login-form" style="background: var(--bg-card); padding: 3rem; border-radius: 24px; border: 1px solid var(--border-glass); min-width: 400px;">
            <!-- Your login form here with class="form-grid" inputs/buttons -->
        </div>
    </div>
</body>
</html>
