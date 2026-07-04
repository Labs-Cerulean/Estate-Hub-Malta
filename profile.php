<?php
require_once 'init.php';
require_once 'session-check.php';

$userId = getCurrentUserId();
$message = '';
$error = '';

$stmt = $pdo->prepare("SELECT username, email, first_name, last_name, phone, avatar_key FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: api/logout.php');
    exit;
}

$avatarUrl = null;
if (!empty($user['avatar_key'])) {
    require_once 'S3FileManager.php';
    try {
        $s3 = new S3FileManager();
        $avatarUrl = $s3->getPresignedUrl($user['avatar_key'], '+24 hours');
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $phone = trim($_POST['phone'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');

        try {
            $stmt = $pdo->prepare("UPDATE users SET phone = ?, first_name = ?, last_name = ? WHERE id = ?");
            $stmt->execute([$phone, $firstName, $lastName, $userId]);
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

    if ($action === 'upload_avatar' && !empty($_FILES['avatar']['tmp_name'])) {
        $file = $_FILES['avatar'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($file['type'], $allowed)) {
            $error = 'Please upload a JPG, PNG, or WebP image.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $error = 'Image must be under 2MB.';
        } else {
            try {
                require_once 'S3FileManager.php';
                $s3 = new S3FileManager();
                if (!empty($user['avatar_key'])) {
                    try { $s3->deleteFile($user['avatar_key']); } catch (Exception $e) {}
                }
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
                $key = $s3->uploadFile($file['tmp_name'], 'avatar_' . $userId . '.' . $ext, $file['type'], 'avatars');
                $pdo->prepare("UPDATE users SET avatar_key = ? WHERE id = ?")->execute([$key, $userId]);
                $_SESSION['avatar_key'] = $key;
                $user['avatar_key'] = $key;
                $avatarUrl = $s3->getPresignedUrl($key, '+24 hours');
                $message = 'Profile photo updated.';
            } catch (Exception $e) {
                $error = 'Upload failed: ' . $e->getMessage();
            }
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

function profileInitials($first, $last, $username) {
    $f = trim($first); $l = trim($last);
    if ($f && $l) return strtoupper(substr($f, 0, 1) . substr($l, 0, 1));
    if ($f) return strtoupper(substr($f, 0, 2));
    return strtoupper(substr($username ?? 'U', 0, 2));
}

$pageTitle = 'My Profile';
require_once 'header.php';
?>

<div class="main-container">
    <h1 class="page-title">Profile Settings</h1>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card" style="margin-bottom: 1.5rem; text-align: center; padding: 2rem;">
        <div style="width: 96px; height: 96px; border-radius: 50%; margin: 0 auto 1rem; overflow: hidden; border: 3px solid var(--primary-color); background: rgba(99,102,241,0.2); display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 700; color: #fff;">
            <?php if ($avatarUrl): ?>
                <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
                <?= htmlspecialchars(profileInitials($user['first_name'] ?? '', $user['last_name'] ?? '', $user['username'])) ?>
            <?php endif; ?>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_avatar">
            <label class="btn btn-secondary btn-sm" style="cursor:pointer;">
                Upload Photo
                <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="this.form.submit()">
            </label>
        </form>
        <p style="font-size:0.8rem;color:var(--text-muted);margin-top:0.75rem;">JPG, PNG or WebP — max 2MB</p>
    </div>

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
