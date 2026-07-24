<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

$allowed_roles = ['admin', 'sales_manager', 'system_manager', 'director'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
    salesDenyJsonAccess('Unauthorized. Managers only.');
}

$property_id = (int)($_POST['property_id'] ?? $_GET['property_id'] ?? 0);
$project_id = (int)($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
$new_status = trim($_POST['new_status'] ?? '');
$resale_pricing_mode = trim($_POST['resale_pricing_mode'] ?? '');
$resale_price = isset($_POST['resale_price']) && $_POST['resale_price'] !== '' ? (float)$_POST['resale_price'] : null;
$resale_shell_price = isset($_POST['resale_shell_price']) && $_POST['resale_shell_price'] !== '' ? (float)$_POST['resale_shell_price'] : null;
$resale_finishes_price = isset($_POST['resale_finishes_price']) && $_POST['resale_finishes_price'] !== '' ? (float)$_POST['resale_finishes_price'] : null;

$manualStatuses = ['Available', 'Proceeding', 'Sold - POS'];

try {
    // --- Manual manage: project picker ---
    if ($action === 'list_manual_projects') {
        $projects = salesGetAccessibleProjectsWithUnits($pdo, false);
        echo json_encode(['success' => true, 'projects' => $projects]);
        exit;
    }

    // --- Manual manage: units for one project ---
    if ($action === 'list_manual_units') {
        if ($project_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Project ID required.']);
            exit;
        }
        if (!hasSalesProjectAccess($pdo, $project_id)) {
            salesDenyJsonAccess();
        }

        $stmt = $pdo->prepare(
            'SELECT id, unit_name, block, unit_type, status, shell_price, finishes_price
             FROM sales_properties
             WHERE project_id = ?
             ORDER BY NULLIF(TRIM(block), \'\') ASC, unit_name ASC'
        );
        $stmt->execute([$project_id]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $nameStmt = $pdo->prepare('SELECT name FROM projects WHERE id = ?');
        $nameStmt->execute([$project_id]);
        $projectName = (string)($nameStmt->fetchColumn() ?: '');

        echo json_encode([
            'success' => true,
            'project_id' => $project_id,
            'project_name' => $projectName,
            'units' => $units,
            'allowed_statuses' => $manualStatuses,
        ]);
        exit;
    }

    // --- Manual manage: set Available / Proceeding / Sold - POS ---
    if ($action === 'manual_set_status') {
        if ($property_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Missing property ID.']);
            exit;
        }
        if (!in_array($new_status, $manualStatuses, true)) {
            echo json_encode(['success' => false, 'message' => 'Status must be Available, Proceeding, or Sold - POS.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT status, project_id FROM sales_properties WHERE id = ?');
        $stmt->execute([$property_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Property not found.']);
            exit;
        }
        if (!hasSalesProjectAccess($pdo, (int)$row['project_id'])) {
            salesDenyJsonAccess();
        }

        $old_status = trim((string)$row['status']);
        if ($old_status === $new_status) {
            echo json_encode(['success' => true, 'message' => 'Status unchanged.', 'status' => $new_status]);
            exit;
        }

        $clearHold = salesClearHoldFieldsSql($pdo);
        $extendedResale = salesResaleExtendedColumnsAvailable($pdo);
        $sql = "UPDATE sales_properties SET status = ?, {$clearHold}";
        if ($extendedResale) {
            $sql .= ', resale_price = NULL, resale_pricing_mode = NULL, resale_shell_price = NULL, resale_finishes_price = NULL, resale_prior_status = NULL';
        } else {
            $sql .= ', resale_price = NULL';
        }
        $sql .= ' WHERE id = ?';

        $update = $pdo->prepare($sql);
        $update->execute([$new_status, $property_id]);

        $justification = 'Manual status update (non-CSV site management)';
        $log = $pdo->prepare('INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)');
        $log->execute([$property_id, $_SESSION['user_id'], 'Manual Status Update', $old_status, $new_status, $justification]);

        echo json_encode([
            'success' => true,
            'message' => "Status set to {$new_status}.",
            'status' => $new_status,
            'old_status' => $old_status,
        ]);
        exit;
    }

    // --- Existing resale flows below ---
    if (!$property_id) {
        echo json_encode(['success' => false, 'message' => 'Missing property ID.']);
        exit;
    }

    $extendedResale = salesResaleExtendedColumnsAvailable($pdo);

    $stmt = $pdo->prepare('SELECT status, project_id FROM sales_properties WHERE id = ?');
    $stmt->execute([$property_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Property not found.']);
        exit;
    }

    if (!hasSalesProjectAccess($pdo, (int)$row['project_id'])) {
        salesDenyJsonAccess();
    }

    $old_status = trim((string)$row['status']);
    $justification = 'Updated via Sales Hub';

    if ($action === 'cancel_resale') {
        if ($old_status !== 'Resale') {
            echo json_encode(['success' => false, 'message' => 'Unit is not currently listed for resale.']);
            exit;
        }

        $restoreStatus = 'Sold - POS';
        if ($extendedResale) {
            $priorStmt = $pdo->prepare('SELECT resale_prior_status FROM sales_properties WHERE id = ?');
            $priorStmt->execute([$property_id]);
            $prior = trim((string)$priorStmt->fetchColumn());
            if ($prior !== '' && salesUnitStatusIsSoldListing($prior)) {
                $restoreStatus = $prior;
            }
            $update = $pdo->prepare('UPDATE sales_properties SET status = ?, resale_price = NULL, resale_pricing_mode = NULL, resale_shell_price = NULL, resale_finishes_price = NULL, resale_prior_status = NULL, held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?');
            $update->execute([$restoreStatus, $property_id]);
        } else {
            $update = $pdo->prepare('UPDATE sales_properties SET status = ?, resale_price = NULL, held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?');
            $update->execute([$restoreStatus, $property_id]);
        }

        $log = $pdo->prepare('INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)');
        $log->execute([$property_id, $_SESSION['user_id'], 'Resale Cancelled', $old_status, $restoreStatus, $justification]);

        echo json_encode(['success' => true, 'message' => 'Resale listing removed.']);
        exit;
    }

    if ($action === 'update_resale_pricing') {
        if ($old_status !== 'Resale') {
            echo json_encode(['success' => false, 'message' => 'Unit is not a resale listing.']);
            exit;
        }
        if (!$extendedResale) {
            $legacyPrice = $resale_price;
            if (($legacyPrice === null || $legacyPrice <= 0) && $resale_pricing_mode === 'split') {
                if ($resale_shell_price === null || $resale_shell_price < 0 || $resale_finishes_price === null || $resale_finishes_price < 0) {
                    echo json_encode(['success' => false, 'message' => 'Shell and finishes prices are required and cannot be negative.']);
                    exit;
                }
                $legacyPrice = $resale_shell_price + $resale_finishes_price;
            }
            if ($legacyPrice === null || $legacyPrice <= 0) {
                echo json_encode(['success' => false, 'message' => 'A valid asking price is required.']);
                exit;
            }
            $update = $pdo->prepare('UPDATE sales_properties SET resale_price = ? WHERE id = ?');
            $update->execute([$legacyPrice, $property_id]);
            $justification .= " (All-in: €{$legacyPrice})";
        } else {
            if (!in_array($resale_pricing_mode, ['single', 'split'], true)) {
                echo json_encode(['success' => false, 'message' => 'Choose single or split pricing.']);
                exit;
            }
            if ($resale_pricing_mode === 'single') {
                if ($resale_price === null || $resale_price <= 0) {
                    echo json_encode(['success' => false, 'message' => 'All-in asking price is required.']);
                    exit;
                }
                $update = $pdo->prepare('UPDATE sales_properties SET resale_pricing_mode = ?, resale_price = ?, resale_shell_price = NULL, resale_finishes_price = NULL WHERE id = ?');
                $update->execute(['single', $resale_price, $property_id]);
                $justification .= " (All-in: €{$resale_price})";
            } else {
                if ($resale_shell_price === null || $resale_shell_price < 0 || $resale_finishes_price === null || $resale_finishes_price < 0 || ($resale_shell_price + $resale_finishes_price) <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Shell and finishes prices are required, and their sum must be greater than 0.']);
                    exit;
                }
                $combined = $resale_shell_price + $resale_finishes_price;
                $update = $pdo->prepare('UPDATE sales_properties SET resale_pricing_mode = ?, resale_price = ?, resale_shell_price = ?, resale_finishes_price = ? WHERE id = ?');
                $update->execute(['split', $combined, $resale_shell_price, $resale_finishes_price, $property_id]);
                $justification .= " (Split: Shell €{$resale_shell_price} + Works €{$resale_finishes_price})";
            }
        }

        $log = $pdo->prepare('INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)');
        $log->execute([$property_id, $_SESSION['user_id'], 'Resale Pricing Updated', $old_status, $old_status, $justification]);

        echo json_encode(['success' => true, 'message' => 'Resale pricing updated.']);
        exit;
    }

    if ($new_status !== 'Resale') {
        echo json_encode(['success' => false, 'message' => 'Unsupported status change.']);
        exit;
    }

    if (!salesUnitStatusIsSoldListing($old_status)) {
        echo json_encode(['success' => false, 'message' => 'Only sold units (Sold - POS / Sold - Contract) can be listed for resale.']);
        exit;
    }

    if (!$extendedResale) {
        $legacyPrice = $resale_price;
        if (($legacyPrice === null || $legacyPrice <= 0) && $resale_pricing_mode === 'split') {
            if ($resale_shell_price === null || $resale_shell_price < 0 || $resale_finishes_price === null || $resale_finishes_price < 0) {
                echo json_encode(['success' => false, 'message' => 'Shell and finishes prices are required and cannot be negative.']);
                exit;
            }
            $legacyPrice = $resale_shell_price + $resale_finishes_price;
        }
        if ($legacyPrice === null || $legacyPrice <= 0) {
            echo json_encode(['success' => false, 'message' => 'A valid asking price is required.']);
            exit;
        }
        $update = $pdo->prepare('UPDATE sales_properties SET status = ?, resale_price = ?, held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?');
        $update->execute([$new_status, $legacyPrice, $property_id]);
        $justification .= " (All-in: €{$legacyPrice})";
    } else {
        if (!in_array($resale_pricing_mode, ['single', 'split'], true)) {
            echo json_encode(['success' => false, 'message' => 'Choose single or split pricing.']);
            exit;
        }
        if ($resale_pricing_mode === 'single') {
            if ($resale_price === null || $resale_price <= 0) {
                echo json_encode(['success' => false, 'message' => 'All-in asking price is required.']);
                exit;
            }
            $update = $pdo->prepare('UPDATE sales_properties SET status = ?, resale_prior_status = ?, resale_pricing_mode = ?, resale_price = ?, resale_shell_price = NULL, resale_finishes_price = NULL, held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?');
            $update->execute([$new_status, $old_status, 'single', $resale_price, $property_id]);
            $justification .= " (All-in: €{$resale_price})";
        } else {
            if ($resale_shell_price === null || $resale_shell_price < 0 || $resale_finishes_price === null || $resale_finishes_price < 0 || ($resale_shell_price + $resale_finishes_price) <= 0) {
                echo json_encode(['success' => false, 'message' => 'Shell and finishes prices are required, and their sum must be greater than 0.']);
                exit;
            }
            $combined = $resale_shell_price + $resale_finishes_price;
            $update = $pdo->prepare('UPDATE sales_properties SET status = ?, resale_prior_status = ?, resale_pricing_mode = ?, resale_price = ?, resale_shell_price = ?, resale_finishes_price = ?, held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?');
            $update->execute([$new_status, $old_status, 'split', $combined, $resale_shell_price, $resale_finishes_price, $property_id]);
            $justification .= " (Split: Shell €{$resale_shell_price} + Works €{$resale_finishes_price})";
        }
    }

    $log = $pdo->prepare('INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)');
    $log->execute([$property_id, $_SESSION['user_id'], 'Listed for Resale', $old_status, $new_status, $justification]);

    echo json_encode(['success' => true, 'message' => 'Unit listed for resale.']);
} catch (Exception $e) {
    error_log('Manager status update failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Update failed. Please try again.']);
}
