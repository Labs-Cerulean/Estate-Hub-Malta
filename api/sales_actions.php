<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

$allowed_roles = ['admin', 'sales_manager', 'sales_agent', 'system_manager', 'director'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'];
$action = $_POST['action'] ?? '';
$property_id = (int)($_POST['property_id'] ?? 0);

if (!$property_id || !$action) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit;
}

function logSalesAction($pdo, $property_id, $user_id, $action_name, $old_status, $new_status, $justification = null) {
    $stmt = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$property_id, $user_id, $action_name, $old_status, $new_status, $justification]);
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT status, held_by_agent_id, hold_expiry, project_id" . (salesResaleExtendedColumnsAvailable($pdo) ? ', status_before_hold' : '') . " FROM sales_properties WHERE id = ?");
    $stmt->execute([$property_id]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        throw new Exception('Property not found.');
    }
    if (!hasSalesProjectAccess($pdo, (int)$property['project_id'])) {
        salesDenyJsonAccess();
    }

    $current_status = $property['status'];
    $new_status = $current_status;
    $log_action_name = '';
    $justification = trim($_POST['justification'] ?? '') ?: null;
    $alertResetSql = salesClearHoldAlertFlagsSql($pdo);

    if ($action === 'hold_property') {
        if (!in_array($current_status, ['Available', 'Resale'], true)) {
            throw new Exception('Only available or resale units can be placed on hold.');
        }

        $new_status = 'On Hold';
        $log_action_name = 'Placed on Hold';
        $rawExpiry = $_POST['hold_expiry'] ?? null;
        $statusBeforeHold = ($current_status === 'Resale' && salesResaleExtendedColumnsAvailable($pdo)) ? 'Resale' : null;

        if ($rawExpiry !== null && trim($rawExpiry) !== '') {
            $expirySql = salesParseHoldExpiryInput($rawExpiry);
            if (!$expirySql) {
                throw new Exception('A valid future deadline is required (YYYY-MM-DD HH:MM).');
            }
            if (!salesCanManageHoldDeadlines($user_role)) {
                throw new Exception('Only managers can set a custom hold deadline.');
            }
        } else {
            $expirySql = (new DateTime('now', new DateTimeZone('Europe/Malta')))
                ->modify('+7 days')
                ->format('Y-m-d H:i:s');
        }

        $sql = "UPDATE sales_properties SET status = ?, held_by_agent_id = ?, hold_expiry = ?";
        $params = [$new_status, $user_id, $expirySql];
        if (salesResaleExtendedColumnsAvailable($pdo)) {
            $sql .= ', status_before_hold = ?';
            $params[] = $statusBeforeHold;
        }
        $sql .= "{$alertResetSql} WHERE id = ?";
        $params[] = $property_id;

        $update = $pdo->prepare($sql);
        $update->execute($params);

    } elseif ($action === 'set_hold_deadline') {
        if (!salesCanManageHoldDeadlines($user_role)) {
            throw new Exception('Only managers can set hold deadlines.');
        }
        if ($current_status !== 'On Hold') {
            throw new Exception('Unit is not currently on hold.');
        }

        $expirySql = salesParseHoldExpiryInput($_POST['hold_expiry'] ?? null);
        if (!$expirySql) {
            throw new Exception('A valid future deadline is required (YYYY-MM-DD HH:MM).');
        }

        $log_action_name = 'Hold Deadline Set by Manager';
        $update = $pdo->prepare("UPDATE sales_properties SET hold_expiry = ?{$alertResetSql} WHERE id = ?");
        $update->execute([$expirySql, $property_id]);

    } elseif ($action === 'extend_hold') {
        if ($current_status !== 'On Hold' || (int)$property['held_by_agent_id'] !== $user_id) {
            throw new Exception('You do not hold this unit.');
        }
        if (empty($justification)) {
            throw new Exception('Justification is required for hold extension.');
        }

        $log_action_name = 'Hold Extended';
        $update = $pdo->prepare("UPDATE sales_properties SET hold_expiry = DATE_ADD(hold_expiry, INTERVAL 7 DAY){$alertResetSql} WHERE id = ?");
        $update->execute([$property_id]);

    } elseif ($action === 'release_hold') {
        if ($current_status !== 'On Hold') {
            throw new Exception('Unit is not currently on hold.');
        }
        if ($user_role === 'sales_agent' && (int)$property['held_by_agent_id'] !== $user_id) {
            throw new Exception('You are only authorized to release your own holds.');
        }

        $new_status = (salesResaleExtendedColumnsAvailable($pdo) && !empty($property['status_before_hold']))
            ? $property['status_before_hold']
            : 'Available';
        $log_action_name = 'Hold Released from Ledger';
        $clearSql = salesClearHoldFieldsSql($pdo);
        $update = $pdo->prepare("UPDATE sales_properties SET status = ?, {$clearSql} WHERE id = ?");
        $update->execute([$new_status, $property_id]);

    } elseif ($action === 'request_reserved') {
        $new_status = ($user_role === 'sales_manager') ? 'Proceeding' : 'Proceeding Pending Approval';
        $log_action_name = ($user_role === 'sales_manager') ? 'Marked as Proceeding' : 'Requested Proceeding Status';
        $clearSql = salesClearHoldFieldsSql($pdo);
        $update = $pdo->prepare("UPDATE sales_properties SET status = ?, {$clearSql} WHERE id = ?");
        $update->execute([$new_status, $property_id]);

    } elseif ($action === 'approve_reserved') {
        if ($user_role !== 'sales_manager' && $user_role !== 'system_manager' && $user_role !== 'director' && $user_role !== 'admin') {
            throw new Exception('Only managers can approve reservations.');
        }
        $new_status = 'Proceeding';
        $log_action_name = 'Approved Proceeding';
        $clearSql = salesClearHoldFieldsSql($pdo);
        $update = $pdo->prepare("UPDATE sales_properties SET status = ?, {$clearSql} WHERE id = ?");
        $update->execute([$new_status, $property_id]);

    } elseif ($action === 'mark_pos') {
        $new_status = ($user_role === 'sales_manager') ? 'Sold - POS' : 'POS Pending Approval';
        $log_action_name = ($user_role === 'sales_manager') ? 'Marked Sold (POS)' : 'Requested POS Status';
        $update = $pdo->prepare('UPDATE sales_properties SET status = ? WHERE id = ?');
        $update->execute([$new_status, $property_id]);

    } elseif ($action === 'mark_contract') {
        $new_status = ($user_role === 'sales_manager') ? 'Sold - Contract' : 'Contract Pending Approval';
        $log_action_name = ($user_role === 'sales_manager') ? 'Marked Sold (Contract)' : 'Requested Contract Status';
        $update = $pdo->prepare('UPDATE sales_properties SET status = ? WHERE id = ?');
        $update->execute([$new_status, $property_id]);

    } elseif (in_array($action, ['mark_resale', 'mark_bom'], true)) {
        if ($user_role !== 'sales_manager' && $user_role !== 'system_manager' && $user_role !== 'director' && $user_role !== 'admin') {
            throw new Exception('Only managers can do this.');
        }
        if ($current_status !== 'Sold - POS' && $current_status !== 'Sold - Contract') {
            throw new Exception('Property must be sold first.');
        }
        $new_status = ($action === 'mark_resale') ? 'Resale' : 'BOM';
        $log_action_name = ($action === 'mark_resale') ? 'Moved to Resale' : 'Moved Back on Market (BOM)';
        $clearSql = salesClearHoldFieldsSql($pdo);
        $update = $pdo->prepare("UPDATE sales_properties SET status = ?, {$clearSql} WHERE id = ?");
        $update->execute([$new_status, $property_id]);

    } else {
        throw new Exception('Invalid action.');
    }

    if ($log_action_name !== '') {
        logSalesAction($pdo, $property_id, $user_id, $log_action_name, $current_status, $new_status, $justification);
    }

    echo json_encode(['success' => true, 'message' => 'Action completed successfully.', 'new_status' => $new_status]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
