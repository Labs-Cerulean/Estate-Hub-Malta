<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

$allowed_roles = ['sales_manager', 'sales_agent', 'system_manager', 'director'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$action = $_POST['action'] ?? '';
$property_id = $_POST['property_id'] ?? 0;

if (!$property_id || !$action) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit;
}

function logSalesAction($pdo, $property_id, $user_id, $action_name, $old_status, $new_status, $justification = null) {
    $stmt = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$property_id, $user_id, $action_name, $old_status, $new_status, $justification]);
}

try {
    $stmt = $pdo->prepare("SELECT status, held_by_agent_id FROM project_sales_units WHERE id = ?");
    $stmt->execute([$property_id]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$property) throw new Exception("Property not found.");

    $current_status = $property['status'];
    $new_status = $current_status;
    $log_action_name = '';
    $justification = $_POST['justification'] ?? null;

    if ($action === 'hold_property') {
        if ($current_status !== 'Available') throw new Exception("Unit is not available to be held.");
        $new_status = 'On Hold';
        $log_action_name = 'Placed on Hold';
        $update = $pdo->prepare("UPDATE project_sales_units SET status = ?, held_by_agent_id = ?, hold_expiry = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?");
        $update->execute([$new_status, $user_id, $property_id]);

    } elseif ($action === 'extend_hold') {
        if ($current_status !== 'On Hold' || $property['held_by_agent_id'] != $user_id) throw new Exception("You do not hold this unit.");
        if (empty($justification)) throw new Exception("Justification is required for hold extension.");
        $log_action_name = 'Hold Extended';
        $update = $pdo->prepare("UPDATE project_sales_units SET hold_expiry = DATE_ADD(hold_expiry, INTERVAL 7 DAY) WHERE id = ?");
        $update->execute([$property_id]);

    } elseif ($action === 'request_reserved') {
        $new_status = ($user_role === 'sales_manager') ? 'Proceeding' : 'Proceeding Pending Approval';
        $log_action_name = ($user_role === 'sales_manager') ? 'Marked as Proceeding' : 'Requested Proceeding Status';
        $update = $pdo->prepare("UPDATE project_sales_units SET status = ? WHERE id = ?");
        $update->execute([$new_status, $property_id]);

    } elseif ($action === 'approve_reserved') {
        if ($user_role !== 'sales_manager' && $user_role !== 'system_manager') throw new Exception("Only managers can approve reservations.");
        $new_status = 'Proceeding';
        $log_action_name = 'Approved Proceeding';
        $update = $pdo->prepare("UPDATE project_sales_units SET status = ? WHERE id = ?");
        $update->execute([$new_status, $property_id]);

    } elseif ($action === 'mark_pos') {
        $new_status = ($user_role === 'sales_manager') ? 'Sold - POS' : 'POS Pending Approval';
        $log_action_name = ($user_role === 'sales_manager') ? 'Marked Sold (POS)' : 'Requested POS Status';
        $update = $pdo->prepare("UPDATE project_sales_units SET status = ? WHERE id = ?");
        $update->execute([$new_status, $property_id]);

    } elseif ($action === 'mark_contract') {
        $new_status = ($user_role === 'sales_manager') ? 'Sold - Contract' : 'Contract Pending Approval';
        $log_action_name = ($user_role === 'sales_manager') ? 'Marked Sold (Contract)' : 'Requested Contract Status';
        $update = $pdo->prepare("UPDATE project_sales_units SET status = ? WHERE id = ?");
        $update->execute([$new_status, $property_id]);

    } elseif (in_array($action, ['mark_resale', 'mark_bom'])) {
        if ($user_role !== 'sales_manager' && $user_role !== 'system_manager') throw new Exception("Only managers can do this.");
        if ($current_status !== 'Sold - POS' && $current_status !== 'Sold - Contract') throw new Exception("Property must be sold first.");
        $new_status = ($action === 'mark_resale') ? 'Resale' : 'BOM';
        $log_action_name = ($action === 'mark_resale') ? 'Moved to Resale' : 'Moved Back on Market (BOM)';
        $update = $pdo->prepare("UPDATE project_sales_units SET status = ?, held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?");
        $update->execute([$new_status, $property_id]);

    } else {
        throw new Exception("Invalid action.");
    }

    logSalesAction($pdo, $property_id, $user_id, $log_action_name, $current_status, $new_status, $justification);
    echo json_encode(['success' => true, 'message' => 'Action completed successfully.', 'new_status' => $new_status]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
