<?php
// cron_sales_holds.php - Run this hourly via cron/scheduler
require_once 'config.php';

// 1. Send the 24-hour warning alarm
// Find properties on hold where the expiry is exactly between 24 and 25 hours from now
$stmt = $pdo->query("
    SELECT sp.id, sp.unit_name, u.email, u.first_name, sp.hold_expiry 
    FROM sales_properties sp
    JOIN users u ON sp.held_by_agent_id = u.id
    WHERE sp.status = 'On Hold' 
    AND sp.hold_expiry BETWEEN DATE_ADD(NOW(), INTERVAL 24 HOUR) AND DATE_ADD(NOW(), INTERVAL 25 HOUR)
");

$warnings = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($warnings as $alert) {
    $to = $alert['email'];
    $subject = "Action Required: Hold on {$alert['unit_name']} expires in 24 hours";
    $message = "Hi {$alert['first_name']},\n\nYour hold on property unit {$alert['unit_name']} will expire at {$alert['hold_expiry']}. Please request an extension with justification or finalize the reservation.\n\nRegards,\nThe Estate Hub";
    
    // Use your mailer class here (mail(), PHPMailer, etc.)
    mail($to, $subject, $message);
}

// 2. Auto-release expired holds
// Find properties where the hold expiry has passed
$stmt = $pdo->query("
    SELECT id, status, held_by_agent_id 
    FROM sales_properties 
    WHERE status = 'On Hold' AND hold_expiry <= NOW()
");

$expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($expired as $prop) {
    // Update status back to available
    $update = $pdo->prepare("UPDATE sales_properties SET status = 'Available', held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?");
    $update->execute([$prop['id']]);

    // Log the automated change
    $log = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
    // Using user_id 0 or a specific system user ID to denote "System Action"
    $log->execute([$prop['id'], 0, 'Auto-Released Hold Expiration', 'On Hold', 'Available', 'Hold time expired automatically.']);
}

echo "Sales holds processed at " . date('Y-m-d H:i:s');
?>
