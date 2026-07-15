<?php
require_once '../config.php';
require_once '../session-check.php';
require_once '../S3FileManager.php'; 

header('Content-Type: application/json');

$allowed_roles = ['admin', 'sales_manager', 'system_manager', 'director'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    $s3 = new S3FileManager();

    // STEP 1: Generate the Secure Cloudflare Link
    if ($action === 'get_upload_url') {
        $filename = $_POST['filename'] ?? 'file';
        $mime_type = $_POST['mime_type'] ?? 'application/octet-stream';
        
        // SECURITY FIX 5: Prevent uploading executables/malware to S3
        $blocked_mimes = ['application/x-msdownload', 'application/x-sh', 'application/x-php', 'text/x-php'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $blocked_exts = ['php', 'exe', 'sh', 'bat', 'js'];
        
        if (in_array($mime_type, $blocked_mimes) || in_array($ext, $blocked_exts)) {
            echo json_encode(['success' => false, 'message' => 'Upload Blocked: This file type is restricted for security reasons.']);
            exit;
        }

        $urlData = $s3->getPresignedUploadUrl($filename, $mime_type, 'sales');
        
        if ($urlData) {
            echo json_encode(['success' => true, 'url' => $urlData['url'], 'key' => $urlData['key']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to generate secure upload link.']);
        }
        exit;
    }

    // STEP 2: Save the file record to the Database
    if ($action === 'save_record') {
        $project_id = (int)($_POST['project_id'] ?? 0);
        $media_type = $_POST['media_type'] ?? ''; 
        $floor_level = trim($_POST['floor_level'] ?? '');
        $fileKey = $_POST['file_key'];
        $filename = $_POST['filename'];
        
        if (!$project_id || !$media_type || !$fileKey) {
            throw new Exception("Missing required fields to save record.");
        }

        salesAssertProjectAccess($pdo, $project_id);

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $title = preg_replace('/\\.[^.\\s]{3,4}$/', '', $filename); // Remove extension
        
        // Auto-tag the Floor Plan
        if ($media_type === 'Floor Plan' && $floor_level !== '') {
            $title = "Floor Plan - Level " . $floor_level;
        }
        
        $stmt = $pdo->prepare("INSERT INTO project_documents (project_id, category, sub_category, title, file_path, file_type, uploaded_by) VALUES (?, 'Sales', ?, ?, ?, ?, ?)");
        $stmt->execute([$project_id, $media_type, $title, $fileKey, $ext, $_SESSION['user_id']]);
        
        // --- LOG THE UPLOAD SAFELY ---
        // 1. Ensure the database allows a blank property_id for project-level logs
        try {
            $pdo->exec("ALTER TABLE sales_property_logs MODIFY COLUMN property_id INT NULL");
        } catch (Exception $e) { /* Silently ignore if already nullable */ }
        
        // 2. Insert the log using 'null' instead of '0'
        $logStmt = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
        $logStmt->execute([null, $_SESSION['user_id'], 'Media Uploaded', 'None', 'Active', "[Project {$project_id} Media] Uploaded {$media_type}: {$title}"]);
        
        echo json_encode(['success' => true, 'message' => 'Media successfully uploaded to Cloudflare!']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action request.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
