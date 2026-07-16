<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

$allowed_roles = ['admin', 'sales_manager', 'system_manager', 'director'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Managers only.']);
    exit;
}

$property_id = (int)($_POST['property_id'] ?? 0);
$shell_price = (float)($_POST['shell_price'] ?? 0);
$finishes_price = (float)($_POST['finishes_price'] ?? 0);

if (!$property_id) {
    echo json_encode(['success' => false, 'message' => 'Missing data.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT shell_price, finishes_price, project_id FROM sales_properties WHERE id = ?");
    $stmt->execute([$property_id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$old) {
        echo json_encode(['success' => false, 'message' => 'Property not found.']);
        exit;
    }

    if (!hasSalesProjectAccess($pdo, (int)$old['project_id'])) {
        salesDenyJsonAccess();
    }

    // Update the prices
    $update = $pdo->prepare("UPDATE sales_properties SET shell_price = ?, finishes_price = ? WHERE id = ?");
    $update->execute([$shell_price, $finishes_price, $property_id]);

    // Log the change for your peace of mind
    $log = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
    $old_str = "Shell: €{$old['shell_price']}, Fin: €{$old['finishes_price']}";
    $new_str = "Shell: €{$shell_price}, Fin: €{$finishes_price}";
    $log->execute([$property_id, $_SESSION['user_id'], 'Manager Price Update', $old_str, $new_str, 'Price updated directly via UI']);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
