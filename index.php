<?php
require_once 'config.php';
session_start();

// 1. If user is already logged in, redirect them immediately so they don't see the login screen
if (isset($_SESSION['user_id'])) {
    $normalizedRole = strtolower(trim(str_replace(' ', '_', $_SESSION['role'] ?? '')));
    if ($normalizedRole === 'legal_representative') {
        header("Location: projects.php");
    } elseif (in_array($normalizedRole, ['sales_agent', 'sales_manager', 'external_agent'])) {
        header("Location: sales_hub.php");
    } else {
        header("Location: dashboard.php");
    }
    exit;
}

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, username, password_hash, role, first_name, last_name, is_active
                FROM users
                WHERE username = ? OR email = ?
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['is_active'] === 'Yes') {
                    // SECURITY FIX: Regenerate ID to prevent session fixation
                    session_regenerate_id(true);
                    
                    // Set session variables
                    $_SESSION['loggedin'] = true;
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['last_activity'] = time();
                    
                    // Update last login
                    $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $updateStmt->execute([$user['id']]);
                    
                    // --- ROLE-BASED REDIRECT ---
                    $normalizedRole = strtolower(trim(str_replace(' ', '_', $user['role'])));
                    if ($normalizedRole === 'legal_representative') {
                        header('Location: projects.php');
                    } elseif (in_array($normalizedRole, ['sales_agent', 'sales_manager', 'external_agent'])) {
                        header('Location: sales_hub.php');
                    } else {
                        header('Location: dashboard.php');
                    }
                    exit;
                    
                } else {
                    $error = 'Your account has been deactivated';
                }
            } else {
                $error = 'Invalid username or password';
            }
        } catch (PDOException $e) {
            $error = 'Login failed. Please try again.';
        }
    }
}

// Check for timeout message
if (isset($_GET['timeout'])) {
    $error = 'Your session has expired. Please login again.';
}
if (isset($_GET['reset'])) {
    $error = '';
    $message = 'Your password has been reset. Please sign in with your new password.';
}

// Set page title
$pageTitle = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estate Hub - Login</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <img src="logo.png" alt="Estate Hub Logo" class="login-logo">
                <h1 class="login-title">Estate Hub</h1>
                <p class="login-subtitle">Project Management System</p>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="login-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="login-form">
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit" class="login-btn">Sign In</button>
            </form>
            
            <p style="text-align: center; margin-top: 1.25rem;">
                <a href="forgot-password.php" style="color: var(--primary-color); font-size: 0.9rem; text-decoration: none;">Forgot your password?</a>
            </p>
        </div>
    </div>
</body>
</html>
