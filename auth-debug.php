<?php
/**
 * Auth Debug Script
 * Simulates the exact login process
 */

session_start();
require_once 'config.php';

echo "<h2>Auth Debug - Simulating Login Process</h2>";

$username = 'admin';
$password = 'Admin123!';

echo "<p><strong>Step 1: Check if POST data would be received</strong></p>";
echo "<pre>";
echo "POST username: " . (isset($_POST['username']) ? $_POST['username'] : 'NOT SET') . "\n";
echo "POST password: " . (isset($_POST['password']) ? '***' : 'NOT SET') . "\n";
echo "</pre>";

echo "<p><strong>Step 2: Manually query the database</strong></p>";
try {
    $stmt = $pdo->prepare("
        SELECT id, username, email, password_hash, role, first_name, last_name, is_active
        FROM users
        WHERE username = ? AND is_active = 'Yes'
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user) {
        echo "<p style='color:green;'>✓ User found in database</p>";
        echo "<ul>";
        echo "<li>ID: " . $user['id'] . "</li>";
        echo "<li>Username: " . $user['username'] . "</li>";
        echo "<li>Email: " . $user['email'] . "</li>";
        echo "<li>Role: " . $user['role'] . "</li>";
        echo "<li>Is Active: " . $user['is_active'] . "</li>";
        echo "<li>Password Hash: " . substr($user['password_hash'], 0, 30) . "...</li>";
        echo "</ul>";
    } else {
        echo "<p style='color:red;'>✗ User NOT found in database</p>";
        echo "<p>This could mean:</p>";
        echo "<ul>";
        echo "<li>Username doesn't match</li>";
        echo "<li>User is not active (is_active != 'Yes')</li>";
        echo "</ul>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>✗ Database query failed: " . $e->getMessage() . "</p>";
    exit;
}

echo "<p><strong>Step 3: Verify password</strong></p>";
if ($user) {
    $verifyResult = password_verify($password, $user['password_hash']);
    echo "<p style='color:" . ($verifyResult ? "green" : "red") . ";'>";
    echo ($verifyResult ? "✓ Password matches!" : "✗ Password does NOT match!");
    echo "</p>";
} else {
    echo "<p>Cannot verify - user not found</p>";
}

echo "<p><strong>Step 4: Check if condition would pass</strong></p>";
if ($user) {
    $conditionResult = $user && password_verify($password, $user['password_hash']);
    echo "<p style='color:" . ($conditionResult ? "green" : "red") . ";'>";
    echo "If condition (\$user && password_verify()): " . ($conditionResult ? "✓ TRUE" : "✗ FALSE");
    echo "</p>";
    
    if ($conditionResult) {
        echo "<p style='color:green;'><strong>✓ Login WOULD succeed! Setting session variables...</strong></p>";
        $_SESSION['loggedin'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['email'] = $user['email'];
        
        echo "<p>Session variables set:</p>";
        echo "<ul>";
        echo "<li>loggedin: " . $_SESSION['loggedin'] . "</li>";
        echo "<li>user_id: " . $_SESSION['user_id'] . "</li>";
        echo "<li>username: " . $_SESSION['username'] . "</li>";
        echo "<li>role: " . $_SESSION['role'] . "</li>";
        echo "</ul>";
        
        echo "<p><strong>You should be able to login now!</strong></p>";
        echo "<p><a href='index.php'>Try login again</a></p>";
    } else {
        echo "<p style='color:red;'><strong>✗ Login would FAIL</strong></p>";
        echo "<p>The condition is FALSE, which means auth.php would show 'Invalid username or password'</p>";
    }
}

echo "<p><strong>Step 5: Check api/auth.php file</strong></p>";
if (file_exists('api/auth.php')) {
    $authFile = file_get_contents('api/auth.php');
    if (strpos($authFile, 'session_start()') !== false) {
        echo "<p style='color:green;'>✓ api/auth.php contains session_start()</p>";
    } else {
        echo "<p style='color:red;'>✗ api/auth.php MISSING session_start()</p>";
    }
    
    if (strpos($authFile, 'password_verify') !== false) {
        echo "<p style='color:green;'>✓ api/auth.php contains password_verify()</p>";
    } else {
        echo "<p style='color:red;'>✗ api/auth.php MISSING password_verify()</p>";
    }
    
    if (strpos($authFile, '<?php') !== false) {
        echo "<p style='color:green;'>✓ api/auth.php starts with &lt;?php</p>";
    } else {
        echo "<p style='color:red;'>✗ api/auth.php does NOT start with &lt;?php</p>";
    }
} else {
    echo "<p style='color:red;'>✗ api/auth.php file not found!</p>";
}

?>
