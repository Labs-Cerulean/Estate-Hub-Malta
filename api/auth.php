<?php
/**
 * Authentication Handler
 * Estate Hub - Project Management System
 */

session_start();

// Include database configuration
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/user-functions.php';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// Handle login request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    // Validate input
    if (empty($username) || empty($password)) {
        $_SESSION['login_error'] = 'Username and password are required';
        header('Location: ../index.php?error=1');
        exit;
    }
    
    try {
        // Query user from database
        $stmt = $pdo->prepare("
            SELECT id, username, email, password_hash, role, first_name, last_name, is_active
            FROM users
            WHERE username = ? AND is_active = 'Yes'
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Password correct and user is active
            $_SESSION['loggedin'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['email'] = $user['email'];
            
            // Update last login timestamp
            $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $updateStmt->execute([$user['id']]);
            
            header('Location: ../dashboard.php');
            exit;
        } else {
            // Invalid credentials
            $_SESSION['login_error'] = 'Invalid username or password';
            header('Location: ../index.php?error=1');
            exit;
        }
        
    } catch (PDOException $e) {
        $_SESSION['login_error'] = 'Database error: ' . $e->getMessage();
        header('Location: ../index.php?error=1');
        exit;
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}
?>
