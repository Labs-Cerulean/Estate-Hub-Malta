<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config.php';
require_once '../session-check.php';
require_once '../includes/sales_daily_sync_analyze.php';
require_once '../includes/sales_daily_sync_runs.php';

if (!in_array($_SESSION['role'], ['admin', 'sales_manager', 'director', 'system_manager'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$action = $_POST['action'] ?? 'analyze';

// =========================================================================
// ACTION: GET IGNORED LEDGER
// =========================================================================
if ($action === 'get_ignored_ledger') {
    try {
        $stmt = $pdo->query("SELECT id, csv_name FROM sync_translations WHERE db_unit_id = -1 ORDER BY csv_name ASC");
        $ignored = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'ignored' => $ignored]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// ACTION: RESTORE SINGLE IGNORED ROW
// =========================================================================
if ($action === 'restore_ignored_row') {
    try {
        $id = (int)$_POST['translation_id'];
        $stmt = $pdo->prepare("DELETE FROM sync_translations WHERE id = ? AND db_unit_id = -1");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// ACTION 1: COMMIT THE APPROVED MATRIX REPORT
// =========================================================================
if ($action === 'commit') {
    $runId = null;
    $syncFileId = isset($_POST['sync_file_id']) ? (int)$_POST['sync_file_id'] : 0;
    $userId = (int)$_SESSION['user_id'];

    try {
        $payload = json_decode($_POST['payload'], true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Invalid sync payload.');
        }

        $priceCount = !empty($payload['prices']) ? count($payload['prices']) : 0;
        $statusCount = !empty($payload['statuses']) ? count($payload['statuses']) : 0;
        $translationCount = !empty($payload['translations']) ? count($payload['translations']) : 0;

        $runId = salesDailySyncStartRun($pdo, $userId, $syncFileId > 0 ? $syncFileId : null);

        $pdo->beginTransaction();

        $stmtLog = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");

        if (!empty($payload['translations'])) {
            $stmtTrans = $pdo->prepare("INSERT INTO sync_translations (csv_name, db_unit_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE db_unit_id = ?");
            foreach ($payload['translations'] as $t) {
                if ((int)$t['db_unit_id'] > 0) {
                    salesAssertPropertyAccess($pdo, (int)$t['db_unit_id']);
                }
                $stmtTrans->execute([$t['csv_name'], $t['db_unit_id'], $t['db_unit_id']]);
            }
        }

        if (!empty($payload['prices'])) {
            $stmtPrice = $pdo->prepare("UPDATE sales_properties SET shell_price = ?, finishes_price = ? WHERE id = ?");
            $stmtGetOldPrices = $pdo->prepare("SELECT shell_price, finishes_price FROM sales_properties WHERE id = ?");

            foreach ($payload['prices'] as $p) {
                salesAssertPropertyAccess($pdo, (int)$p['id']);
                $stmtGetOldPrices->execute([$p['id']]);
                $oldPriceData = $stmtGetOldPrices->fetch(PDO::FETCH_ASSOC);
                $stmtPrice->execute([$p['shell'], $p['finishes'], $p['id']]);

                $justification = "Sync Matrix: Shell €{$oldPriceData['shell_price']} -> €{$p['shell']} | Fin: €{$oldPriceData['finishes_price']} -> €{$p['finishes']}";
                $stmtLog->execute([
                    $p['id'],
                    $userId,
                    'CSV Sync Price Update',
                    'Price Override',
                    'Price Override',
                    salesDailySyncTruncateJustification($runId, $justification),
                ]);
            }
        }

        if (!empty($payload['statuses'])) {
            $stmtClearAgent = $pdo->prepare("UPDATE sales_properties SET status = ?, held_by_agent_id = NULL, hold_expiry = NULL WHERE id = ?");
            $stmtKeepAgent = $pdo->prepare("UPDATE sales_properties SET status = ? WHERE id = ?");

            foreach ($payload['statuses'] as $s) {
                salesAssertPropertyAccess($pdo, (int)$s['id']);
                if (in_array($s['new_status'], ['Available', 'Sold', 'Sold - POS', 'Sold - Contract', 'Resale'], true)) {
                    $stmtClearAgent->execute([$s['new_status'], $s['id']]);
                } else {
                    $stmtKeepAgent->execute([$s['new_status'], $s['id']]);
                }
                $stmtLog->execute([
                    $s['id'],
                    $userId,
                    'CSV Sync Status Update',
                    $s['old_status'],
                    $s['new_status'],
                    salesDailySyncTruncateJustification($runId, 'Updated via Sync Matrix Approval'),
                ]);
            }
        }

        $pdo->commit();

        salesDailySyncFinishRun($pdo, $runId, 'committed', $priceCount, $statusCount, $translationCount, null);
        if ($syncFileId > 0 && $runId !== null) {
            salesDailySyncMarkFileApplied($pdo, $syncFileId, $runId);
        }

        echo json_encode([
            'success' => true,
            'sync_run_id' => $runId,
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        salesDailySyncFinishRun($pdo, $runId, 'failed', 0, 0, 0, $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// ACTION 2: DRY RUN / ANALYZE CSV (Strict Integrity Engine)
// =========================================================================
if ($action === 'analyze') {
    if (!isset($_FILES['sync_csv']) || $_FILES['sync_csv']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
        exit;
    }

    $result = salesAnalyzeDailySyncCsv($pdo, $_FILES['sync_csv']['tmp_name']);
    if (!$result['success']) {
        echo json_encode($result);
        exit;
    }

    $jsonResponse = defined('JSON_INVALID_UTF8_SUBSTITUTE')
        ? json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE)
        : json_encode($result);

    if ($jsonResponse === false) {
        echo json_encode(['success' => false, 'message' => 'System JSON Encoding Failed. Check for illegal characters in CSV.']);
    } else {
        echo $jsonResponse;
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit;
