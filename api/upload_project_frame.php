<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

$allowed_roles = ['sales_manager', 'system_manager', 'admin', 'director'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$project_id = $_POST['project_id'] ?? 0;

if (!$project_id || empty($_FILES['frame_csv']['tmp_name'])) {
    echo json_encode(['success' => false, 'message' => 'Project ID and CSV file are required.']);
    exit;
}

// SECURITY FIX 5: Prevent DoS by strictly capping the file size to 5MB
$maxCsvSize = 5 * 1024 * 1024; // 5 MB
if ($_FILES['frame_csv']['size'] > $maxCsvSize) {
    echo json_encode(['success' => false, 'message' => 'Upload Blocked: File exceeds the maximum allowed size of 5MB.']);
    exit;
}

// SECURITY FIX 5: Validate MIME type to prevent malicious executable uploads (.php, .exe)
$ext = strtolower(pathinfo($_FILES['frame_csv']['name'], PATHINFO_EXTENSION));
$fileMimeType = mime_content_type($_FILES['frame_csv']['tmp_name']);
$allowedMimeTypes = ['text/csv', 'text/plain', 'application/vnd.ms-excel'];

if ($ext !== 'csv' && !in_array($fileMimeType, $allowedMimeTypes)) {
    echo json_encode(['success' => false, 'message' => 'Upload Blocked: Only valid CSV files are allowed.']);
    exit;
}

try {
    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_properties WHERE project_id = ?");
    $check_stmt->execute([$project_id]);
    if ($check_stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => "Upload Blocked: This project already has units."]);
        exit;
    }

    $file = $_FILES['frame_csv']['tmp_name'];
    $handle = fopen($file, "r");
    
    if ($handle !== FALSE) {
        fgetcsv($handle, 1000, ","); // Skip header
        $inserted_count = 0;
        
        $stmt = $pdo->prepare("INSERT INTO sales_properties (project_id, unit_name, unit_type, floor_level, description, internal_sqm, external_sqm, shell_price, finishes_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $log_stmt = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // Now expecting 9 columns minimum!
            if(count($data) >= 9 && trim($data[0]) !== '') {
                $unit_name    = trim($data[0]);
                $unit_type    = strtolower(trim($data[1])); 
                $floor_level  = trim($data[2]); // NEW FIELD
                $description  = trim($data[3]);
                $internal_sqm = (float) trim($data[4]);
                $external_sqm = (float) trim($data[5]);
                $shell_price  = (float) trim($data[6]);
                $cp_price     = (float) trim($data[7]);
                $status       = trim($data[8]);
                
                if(empty($status)) $status = 'Available';

                $stmt->execute([$project_id, $unit_name, $unit_type, $floor_level, $description, $internal_sqm, $external_sqm, $shell_price, $cp_price, $status]);
                $new_property_id = $pdo->lastInsertId();

                $log_stmt->execute([$new_property_id, $_SESSION['user_id'], 'Initial Frame Import', 'None', $status, 'Imported via CSV Frame Upload']);
                $inserted_count++;
            }
        }
        fclose($handle);
        echo json_encode(['success' => true, 'message' => "$inserted_count units successfully uploaded!"]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
