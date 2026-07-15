<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

$allowed_roles = ['admin', 'sales_manager', 'sales_agent', 'director', 'system_manager'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
    salesDenyJsonAccess('Unauthorized API Access');
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'];

try {
    $access = salesProjectAccessWhereClause($pdo, 'p');
    $sql = "SELECT pu.id, pu.unit_name, p.name AS project_name,
                   pu.status, pu.held_by_agent_id, pu.hold_expiry,
                   u.first_name, u.last_name
            FROM sales_properties pu
            JOIN projects p ON pu.project_id = p.id
            LEFT JOIN users u ON pu.held_by_agent_id = u.id
            WHERE pu.status = 'On Hold'
            AND {$access['sql']}";

    $params = $access['params'];

    if ($user_role === 'sales_agent') {
        $sql .= " AND pu.held_by_agent_id = ? ORDER BY pu.hold_expiry ASC";
        $params[] = $user_id;
    } else {
        $sql .= " ORDER BY pu.hold_expiry ASC";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $holds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $current_time = new DateTime();

    foreach ($holds as &$hold) {
        if (!empty($hold['hold_expiry'])) {
            $expiry_time = new DateTime($hold['hold_expiry']);
            $interval = $current_time->diff($expiry_time);
            $hours_left = ($interval->days * 24) + $interval->h;
            if ($interval->invert == 1) {
                $hours_left = 0;
            }

            $hold['is_legacy'] = false;
            $hold['hours_remaining'] = $hours_left;
            $hold['is_expiring_soon'] = ($hours_left <= 24 && $hours_left > 0);
        } else {
            $hold['is_legacy'] = true;
            $hold['hours_remaining'] = null;
            $hold['is_expiring_soon'] = false;
        }
    }
    unset($hold);

    echo json_encode(['success' => true, 'holds' => $holds, 'role' => $user_role]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
