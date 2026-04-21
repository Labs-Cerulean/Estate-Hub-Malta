<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

// Ensure ONLY Managers/Admins can do this
$allowed_roles = ['admin', 'sales_manager', 'system_manager', 'director'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Managers only.']);
    exit;
}

$property_id = $_POST['property_id'] ?? 0;
$new_status = $_POST['new_status'] ?? '';
$resale_price = !empty($_POST['resale_price']) ? (float)$_POST['resale_price'] : null;

if (!$property_id || !$new_status) {
    echo json_encode(['success' => false, 'message' => 'Missing data.']);
    exit;
}

try {
    // Get old status for logging
    $stmt = $pdo->prepare("SELECT status FROM project_units WHERE id = ?");
    $stmt->execute([$property_id]);
    $old_status = $stmt->fetchColumn();

    // Update the status (and clear agent holds if they manually push it to available/sold)
    if ($new_status === 'Resale') {
        $update = $pdo->prepare("UPDATE project_units SET status = ?, resale_price = ?, held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?");
        $update->execute([$new_status, $resale_price, $property_id]);
    } else {
        $update = $pdo->prepare("UPDATE project_units SET status = ?, resale_price = NULL, held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?");
        $update->execute([$new_status, $property_id]);
    }

    // Log the manual override
    $log = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
    $log->execute([$property_id, $_SESSION['user_id'], 'Manager Direct Status Override', $old_status, $new_status, 'Updated via Map UI Dropdown']);

    echo json_encode(['success' => true, 'message' => 'Status updated successfully!']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
