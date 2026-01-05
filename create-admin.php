<?php
require_once 'config.php';

try {
    $username = 'admin';
    $email = 'admin@estate-hub.local';
    $password = 'Admin123!';
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, role, first_name, last_name, is_active)
        VALUES (?, ?, ?, 'admin', 'Administrator', 'Account', 'Yes')
    ");
    
    $stmt->execute([$username, $email, $passwordHash]);
    
    echo "✓ Admin user created!<br>";
    echo "Username: admin<br>";
    echo "Password: Admin123!<br>";
    
} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage();
}
?>
