<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

try {
    // Note: We use project_units here, assuming the unification fix is applied.
    $sql = "SELECT pu.id, pu.unit_name, p.name AS project_name, 
                   pu.status, pu.held_by_agent_id, pu.hold_expiry,
                   u.first_name, u.last_name 
            FROM project_units pu
            JOIN projects p ON pu.project_id = p.id
            JOIN users u ON pu.held_by_agent_id = u.id
            WHERE pu.status = 'On Hold'";
            
    // If it's an agent, ONLY show their holds. Otherwise, managers see all.
    if ($user_role === 'sales_agent') {
        $sql .= " AND pu.held_by_agent_id = ? ORDER BY pu.hold_expiry ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
    } else {
        $sql .= " ORDER BY pu.hold_expiry ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }

    $holds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate hours remaining for the 24-hour alert system
    $current_time = new DateTime();
    foreach ($holds as &$hold) {
        if ($hold['hold_expiry']) {
            $expiry_time = new DateTime($hold['hold_expiry']);
            $interval = $current_time->diff($expiry_time);
            
            // Convert difference to total hours
            $hours_left = ($interval->days * 24) + $interval->h;
            
            // If the time has already passed
            if ($interval->invert == 1) {
                $hours_left = 0; 
            }
            
            $hold['hours_remaining'] = $hours_left;
            $hold['is_expiring_soon'] = ($hours_left <= 24 && $hours_left > 0);
        } else {
            $hold['hours_remaining'] = 999; // Fallback
            $hold['is_expiring_soon'] = false;
        }
    }

    echo json_encode(['success' => true, 'holds' => $holds, 'role' => $user_role]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
