<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config.php';
require_once '../session-check.php';
require_once '../includes/nav_config.php';
require_once '../includes/sales_daily_sync_analyze.php';
require_once '../includes/sales_daily_sync_runs.php';
require_once '../S3FileManager.php';

if (!navCanAccessSalesProjectManager()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (!salesDailySyncSchemaReady($pdo)) {
    echo json_encode([
        'success' => false,
        'message' => 'Daily sync inbox is not configured. Run sql/2026-07-17_sales_daily_sync_inbox.sql in phpMyAdmin first.',
        'schema_ready' => false,
    ]);
    exit;
}

if ($action === 'list') {
    try {
        $filesStmt = $pdo->query("
            SELECT f.id, f.report_date, f.received_at, f.ingest_source, f.file_status,
                   f.original_filename, f.applied_run_id,
                   r.started_at AS applied_at,
                   TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS applied_by_name
            FROM sales_daily_sync_files f
            LEFT JOIN sales_daily_sync_runs r ON r.id = f.applied_run_id
            LEFT JOIN users u ON u.id = r.started_by_user_id
            ORDER BY f.report_date DESC, f.received_at DESC
            LIMIT 100
        ");
        $files = $filesStmt->fetchAll(PDO::FETCH_ASSOC);

        $runsStmt = $pdo->query("
            SELECT r.id, r.sync_file_id, r.started_at, r.completed_at, r.outcome,
                   r.price_updates_count, r.status_updates_count, r.translation_updates_count,
                   TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS started_by_name,
                   f.report_date AS file_report_date
            FROM sales_daily_sync_runs r
            INNER JOIN users u ON u.id = r.started_by_user_id
            LEFT JOIN sales_daily_sync_files f ON f.id = r.sync_file_id
            WHERE r.outcome IN ('committed', 'failed')
            ORDER BY r.started_at DESC
            LIMIT 50
        ");
        $runs = $runsStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'files' => $files, 'runs' => $runs, 'schema_ready' => true]);
    } catch (Throwable $e) {
        error_log('sales_daily_sync_inbox list: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not load inbox.']);
    }
    exit;
}

if ($action === 'analyze_pending') {
    $fileId = (int)($_POST['sync_file_id'] ?? 0);
    if ($fileId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid file.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare('SELECT id, r2_storage_key, file_status FROM sales_daily_sync_files WHERE id = ?');
        $stmt->execute([$fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['file_status'] !== 'ready') {
            echo json_encode(['success' => false, 'message' => 'This report is not available for sync.']);
            exit;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'eh_sync_');
        if ($tmp === false) {
            throw new RuntimeException('Could not create temp file.');
        }

        try {
            $s3 = new S3FileManager();
            if (!$s3->downloadObjectToPath($row['r2_storage_key'], $tmp)) {
                throw new RuntimeException('Could not retrieve stored CSV.');
            }
            $result = salesAnalyzeDailySyncCsv($pdo, $tmp);
        } finally {
            @unlink($tmp);
        }

        if (!$result['success']) {
            echo json_encode($result);
            exit;
        }

        $result['sync_file_id'] = $fileId;
        $jsonResponse = defined('JSON_INVALID_UTF8_SUBSTITUTE')
            ? json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE)
            : json_encode($result);

        if ($jsonResponse === false) {
            echo json_encode(['success' => false, 'message' => 'JSON encoding failed.']);
        } else {
            echo $jsonResponse;
        }
    } catch (Throwable $e) {
        error_log('sales_daily_sync_inbox analyze_pending: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Analyze failed.']);
    }
    exit;
}

if ($action === 'upload_pending') {
    if (!isset($_FILES['sync_csv']) || $_FILES['sync_csv']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
        exit;
    }

    $reportDateRaw = trim($_POST['report_date'] ?? '');
    $dt = DateTime::createFromFormat('Y-m-d', $reportDateRaw, new DateTimeZone('Europe/Malta'));
    if (!$dt || $dt->format('Y-m-d') !== $reportDateRaw) {
        echo json_encode(['success' => false, 'message' => 'Report date must be YYYY-MM-DD.']);
        exit;
    }

    $tmpPath = $_FILES['sync_csv']['tmp_name'];
    $hash = hash_file('sha256', $tmpPath);
    if ($hash === false) {
        echo json_encode(['success' => false, 'message' => 'Could not read uploaded file.']);
        exit;
    }

    $dup = $pdo->prepare('SELECT id FROM sales_daily_sync_files WHERE content_sha256 = ?');
    $dup->execute([$hash]);
    if ($dup->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'This exact file was already received.']);
        exit;
    }

    $originalName = basename($_FILES['sync_csv']['name'] ?? 'report.csv');
    $originalName = preg_replace('/[^\w.\- ]+/u', '', $originalName) ?: 'report.csv';

    try {
        $s3 = new S3FileManager();
        $key = $s3->uploadFile($tmpPath, $originalName, 'text/csv', 'sales-daily-sync');
        if (!$key) {
            throw new RuntimeException('Storage upload failed.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO sales_daily_sync_files
                (report_date, ingest_source, r2_storage_key, content_sha256, original_filename)
            VALUES (?, 'manual', ?, ?, ?)
        ");
        $stmt->execute([$reportDateRaw, $key, $hash, substr($originalName, 0, 255)]);

        echo json_encode(['success' => true, 'file_id' => (int)$pdo->lastInsertId()]);
    } catch (Throwable $e) {
        error_log('sales_daily_sync_inbox upload_pending: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not store report.']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
