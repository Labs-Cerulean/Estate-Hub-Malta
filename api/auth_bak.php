<?php
session_start();
header('Content-Type: application/json');

$users = [
    'admin' => password_hash('Pra2026!', PASSWORD_DEFAULT),
    'manager' => password_hash('Site2026!', PASSWORD_DEFAULT),
    'viewer' => password_hash('View2026!', PASSWORD_DEFAULT)
];

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (isset($users[$username]) && password_verify($password, $users[$username])) {
        $_SESSION['user'] = $username;
        $_SESSION['loggedin'] = true;
        header('Location: ../dashboard.php');
        exit;
    } else {
        header('Location: ../index.php?error=1');
        exit;
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
}
?>
