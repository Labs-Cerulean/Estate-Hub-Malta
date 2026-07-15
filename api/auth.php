<?php
session_start();

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['username']) && !empty($_POST['password'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    try {
        $stmt = $pdo->prepare("
            SELECT id, username, email, password_hash, role, first_name, last_name, is_active
            FROM users
            WHERE username = ? AND is_active = 'Yes'
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // SUCCESS: Set session
            $_SESSION['loggedin'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['email'] = $user['email'];

            // Update last login
            $update = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update->execute([$user['id']]);

            // REDIRECT LOGIC based on user role (Normalized to handle capitalization or spaces)
            $normalizedRole = strtolower(trim(str_replace(' ', '_', $user['role'])));
            if ($normalizedRole === 'external_agent') {
                header('Location: ../sales_library.php');
            } elseif ($normalizedRole === 'sales_agent') {
                header('Location: ../sales_hub.php');
            } else {
                header('Location: ../dashboard.php');
            }
            exit;
            
        } else {
            // FAILED
            $_SESSION['login_error'] = 'Invalid username or password';
            header('Location: ../index.php?error=1');
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['login_error'] = 'Database error: ' . $e->getMessage();
        header('Location: ../index.php?error=1');
        exit;
    }
} else {
    header('Location: ../index.php');
    exit;
}
?>
