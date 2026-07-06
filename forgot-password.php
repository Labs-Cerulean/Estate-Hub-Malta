<?php
require_once 'init.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    if ($identifier === '') {
        $error = 'Please enter your username or email address.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, email, first_name FROM users WHERE (username = ? OR email = ?) AND is_active = 'Yes'");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && !empty($user['email'])) {
                date_default_timezone_set('Europe/Malta');
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expires = date('Y-m-d H:i:s', time() + 3600);

                $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$user['id']]);
                $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)")->execute([$user['id'], $tokenHash, $expires]);

                require_once __DIR__ . '/email_helper.php';
                $resetLink = rtrim(APP_URL, '/') . '/reset-password.php?token=' . urlencode($token);
                $name = htmlspecialchars($user['first_name'] ?: 'User');
                $html = "<p>Hello {$name},</p><p>We received a request to reset your Estate Hub password.</p><p><a href=\"{$resetLink}\">Reset your password</a></p><p>This link expires in 1 hour. If you did not request this, you can ignore this email.</p>";
                sendSystemEmail($user['email'], 'Estate Hub — Password Reset', $html);
            }

            $message = 'If an account exists with that username or email, we have sent a password reset link.';
        } catch (PDOException $e) {
            $error = 'Unable to process request. Please try again later.';
        }
    }
}

$pageTitle = 'Forgot Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estate Hub - Forgot Password</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <img src="logo.png" alt="Estate Hub Logo" class="login-logo">
                <h1 class="login-title">Reset Password</h1>
                <p class="login-subtitle">Enter your username or email</p>
            </div>

            <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="login-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="POST" class="login-form">
                <div class="form-group">
                    <label for="identifier">Username or Email</label>
                    <input type="text" id="identifier" name="identifier" required autofocus>
                </div>
                <button type="submit" class="login-btn">Send Reset Link</button>
            </form>

            <p style="text-align: center; margin-top: 1.25rem;">
                <a href="index.php" style="color: var(--primary-color); font-size: 0.9rem; text-decoration: none;">← Back to Sign In</a>
            </p>
        </div>
    </div>
</body>
</html>
