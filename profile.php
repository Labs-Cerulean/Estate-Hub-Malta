<?php
require_once 'init.php';
require_once 'session-check.php';

$userId = getCurrentUserId();
$message = '';
$error = '';

// 1. Fetch current user data
$stmt = $pdo->prepare("SELECT username, email, first_name, last_name, phone FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: api/logout.php');
    exit;
}

// 2. Handle Profile Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $phone = trim($_POST['phone'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');

        try {
            $stmt = $pdo->prepare("UPDATE users SET phone = ?, first_name = ?, last_name = ? WHERE id = ?");
            $stmt->execute([$phone, $firstName, $lastName, $userId]);
            
            // Update session for immediate UI change in header
            $_SESSION['first_name'] = $firstName;
            $_SESSION['last_name'] = $lastName;
            
            $message = "Profile details updated successfully.";
            $user['phone'] = $phone;
            $user['first_name'] = $firstName;
            $user['last_name'] = $lastName;
        } catch (Exception $e) {
            $error = "Update failed: " . $e->getMessage();
        }
    }

    if ($action === 'change_password') {
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (empty($newPass) || strlen($newPass) < 6) {
            $error = "Password must be at least 6 characters.";
        } elseif ($newPass !== $confirmPass) {
            $error = "Passwords do not match.";
        } else {
            try {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hash, $userId]);
                $message = "Password changed successfully.";
            } catch (Exception $e) {
                $error = "Error changing password.";
            }
        }
    }
}

$pageTitle = 'My Profile';
require_once 'header.php';
?>

<div class="main-container">
    <h1 class="page-title">Profile Settings</h1>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="two-column-layout">
        <div class="card">
            <h2 class="section-title">Personal Details</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled style="opacity: 0.6; cursor: not-allowed;">
                    <small class="info-text">Username cannot be changed.</small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" value="<?= htmlspecialchars($user['email']) ?>" disabled style="opacity: 0.6; cursor: not-allowed;">
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+356 ...">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">Update Details</button>
            </form>
        </div>

        <div class="card">
            <h2 class="section-title">Security</h2>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" required minlength="6">
                </div>

                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" required minlength="6">
                </div>

                <button type="submit" class="btn btn-secondary" style="width: 100%;">Update Password</button>
            </form>
            
            <div class="info-box" style="margin-top: 2rem; padding: 1rem; background: var(--info-bg); border-radius: 8px; border: 1px solid var(--info);">
                <p style="font-size: 0.85rem; color: var(--info); margin: 0;">
                    <strong>Tip:</strong> Use a strong password with at least 6 characters, including numbers and symbols.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
