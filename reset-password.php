<?php
require_once 'init.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$message = '';
$error = '';
$validToken = false;
$userId = null;

if ($token) {
    date_default_timezone_set('Europe/Malta');
    $tokenHash = hash('sha256', $token);
    $nowMalta = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT user_id FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? LIMIT 1");
    $stmt->execute([$tokenHash, $nowMalta]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $validToken = true;
        $userId = (int)$row['user_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $newPass = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if ($newPass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $ctxStmt = $pdo->prepare('SELECT username, email, password_hash FROM users WHERE id = ?');
            $ctxStmt->execute([$userId]);
            $ctx = $ctxStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $policyError = validatePasswordStrength($newPass, [
                'username' => $ctx['username'] ?? null,
                'email' => $ctx['email'] ?? null,
                'current_hash' => $ctx['password_hash'] ?? null,
            ]);
            if ($policyError !== null) {
                $error = $policyError;
            } else {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
                $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE token_hash = ?')->execute([hash('sha256', $token)]);
                header('Location: index.php?reset=1');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Unable to reset password. Please try again.';
        }
    }
}

$pageTitle = 'Reset Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estate Hub - Reset Password</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <img src="logo.png" alt="Estate Hub Logo" class="login-logo">
                <h1 class="login-title">Set New Password</h1>
            </div>

            <?php if (!$validToken && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
                <div class="login-error">This reset link is invalid or has expired. Please request a new one.</div>
                <p style="text-align: center; margin-top: 1.25rem;">
                    <a href="forgot-password.php" style="color: var(--primary-color);">Request new link</a>
                </p>
            <?php else: ?>
                <?php if ($error): ?><div class="login-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <form method="POST" class="login-form">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" required autocomplete="new-password" minlength="<?= (int)passwordPolicyMinLength() ?>" maxlength="<?= (int)passwordPolicyMaxLength() ?>">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" minlength="<?= (int)passwordPolicyMinLength() ?>" maxlength="<?= (int)passwordPolicyMaxLength() ?>">
                    </div>
                    <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0 0 1rem;"><?= htmlspecialchars(passwordPolicyRequirementsText(), ENT_QUOTES, 'UTF-8') ?></p>
                    <button type="submit" class="login-btn">Update Password</button>
                </form>
            <?php endif; ?>

            <p style="text-align: center; margin-top: 1.25rem;">
                <a href="index.php" style="color: var(--primary-color); font-size: 0.9rem; text-decoration: none;">← Back to Sign In</a>
            </p>
        </div>
    </div>
</body>
</html>
