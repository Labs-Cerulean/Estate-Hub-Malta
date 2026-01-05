<?php
/**
 * Password Hash Debug Script
 * Estate Hub - Troubleshooting Login Issues
 */

require_once 'config.php';

echo "<h2>Password Hash Debug</h2>";

// Test the exact password
$testPassword = 'Admin123!';

// Get admin user from database
$stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = 'admin'");
$stmt->execute();
$user = $stmt->fetch();

if (!$user) {
    echo "<p style='color:red;'>✗ ERROR: Admin user not found in database!</p>";
    exit;
}

echo "<p><strong>Admin user found:</strong></p>";
echo "<ul>";
echo "<li>ID: " . $user['id'] . "</li>";
echo "<li>Username: " . $user['username'] . "</li>";
echo "<li>Stored Hash: " . substr($user['password_hash'], 0, 50) . "...</li>";
echo "</ul>";

// Test if password verifies
$verifyResult = password_verify($testPassword, $user['password_hash']);
echo "<p><strong>Password Verification:</strong></p>";
echo "<p style='color:" . ($verifyResult ? "green" : "red") . ";'>";
echo ($verifyResult ? "✓ PASSWORD MATCHES!" : "✗ PASSWORD DOES NOT MATCH!");
echo "</p>";

// Generate a new hash for reference
$newHash = password_hash($testPassword, PASSWORD_DEFAULT);
echo "<p><strong>New Hash (for comparison):</strong></p>";
echo "<p>" . $newHash . "</p>";

// If password doesn't match, offer to update it
if (!$verifyResult) {
    echo "<p style='color:orange;'><strong>⚠️ Password hash is invalid. Update it?</strong></p>";
    echo "<p>If you want to reset the password to 'Admin123!', run this SQL:</p>";
    echo "<pre style='background:#f0f0f0; padding:10px;'>";
    echo "UPDATE users SET password_hash = '" . addslashes($newHash) . "' WHERE username = 'admin';";
    echo "</pre>";
    echo "<p><strong>Or click below to auto-update:</strong></p>";
    echo "<form method='POST'>";
    echo "<input type='hidden' name='action' value='reset_password'>";
    echo "<button type='submit' style='padding:10px 20px; background:#007bff; color:white; border:none; border-radius:4px; cursor:pointer;'>Reset Admin Password to Admin123!</button>";
    echo "</form>";
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $newHash = password_hash('Admin123!', PASSWORD_DEFAULT);
    $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'admin'");
    $updateStmt->execute([$newHash]);
    
    echo "<p style='color:green; font-weight:bold;'>✓ Password reset successfully!</p>";
    echo "<p>You can now login with:</p>";
    echo "<ul>";
    echo "<li>Username: <strong>admin</strong></li>";
    echo "<li>Password: <strong>Admin123!</strong></li>";
    echo "</ul>";
    echo "<p><a href='index.php'>Go to login page</a></p>";
}

?>
