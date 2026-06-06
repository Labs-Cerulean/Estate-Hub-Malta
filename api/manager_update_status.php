<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

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
    $stmt = $pdo->prepare("SELECT status FROM sales_properties WHERE id = ?");
    $stmt->execute([$property_id]);
    $old_status = $stmt->fetchColumn();

    if ($new_status === 'Resale') {
        $update = $pdo->prepare("UPDATE sales_properties SET status = ?, resale_price = ?, held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?");
        $update->execute([$new_status, $resale_price, $property_id]);
    } else {
        $update = $pdo->prepare("UPDATE sales_properties SET status = ?, resale_price = NULL, held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?");
        $update->execute([$new_status, $property_id]);
    }

    $justification = 'Updated via Map UI Dropdown';
    if ($new_status === 'Resale' && $resale_price > 0) {
        $justification .= " (Asking: €{$resale_price})";
    }

    $log = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
    $log->execute([$property_id, $_SESSION['user_id'], 'Manager Direct Status Override', $old_status, $new_status, $justification]);

    echo json_encode(['success' => true, 'message' => 'Status updated successfully!']);

    echo json_encode(['success' => true, 'message' => 'Status updated successfully!']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
