<?php
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && 
    isset($_SESSION['user']) && $_SESSION['user'] === 'admin') {
  header("Location: dashboard.php");
  exit;
}

$error = '';

// Handle login
if ($_POST) {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  
  // Simple hardcoded auth (replace with DB query if needed)
  if ($username === 'admin' && $password === 'admin') {
    $_SESSION['logged_in'] = true;
    $_SESSION['user'] = 'admin';
    header("Location: dashboard.php");
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
  <title>Login – Estate Hub Malta</title>
  <link rel="icon" href="logo.jpg">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="main-container" style="min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem;">
    <div class="login-card">
      <div class="login-header">
        <img src="logo.jpg" alt="Estate Hub Malta" class="login-logo" onerror="this.src='logo.jpg'">
        <h1 class="login-title">Estate Hub Malta</h1>
        <p class="login-subtitle">Project Management System</p>
      </div>

      <?php if ($error): ?>
        <div class="message error"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <form method="POST" class="login-form">
        <div class="form-group">
          <label>Username</label>
          <input type="text" name="username" required placeholder="admin" value="admin">
        </div>

        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" required placeholder="admin" value="admin">
        </div>

        <button type="submit" class="btn">Sign In</button>
      </form>

      <div class="login-footer">
        <p>Demo credentials: <strong>admin</strong> / <strong>admin</strong></p>
      </div>
    </div>
  </div>
</body>
</html>
