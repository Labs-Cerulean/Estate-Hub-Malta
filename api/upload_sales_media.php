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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['media_file']['tmp_name'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
    exit;
}

$project_id = (int)($_POST['project_id'] ?? 0);
$media_type = $_POST['media_type'] ?? ''; 
$floor_level = trim($_POST['floor_level'] ?? '');

if (!$project_id || !$media_type) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

try {
    $s3 = new S3FileManager();
    $file = $_FILES['media_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Upload to Cloudflare (Folder: sales)
    $fileKey = $s3->uploadFile($file['tmp_name'], $file['name'], $file['type'], 'sales');
    if (!$fileKey) {
        throw new Exception("Failed to upload file to Cloudflare. Check your R2 settings.");
    }
    
    // Build the Document Title
    $title = preg_replace('/\\.[^.\\s]{3,4}$/', '', $file['name']); // Remove extension
    
    // If it's a Floor Plan, tag the floor level to the title so it matches automatically later
    if ($media_type === 'Floor Plan' && $floor_level !== '') {
        $title = "Floor Plan - Level " . $floor_level;
    }
    
    // Insert directly into the Universal Document Vault under "Sales"
    $stmt = $pdo->prepare("INSERT INTO project_documents (project_id, category, sub_category, title, file_path, file_type, uploaded_by) VALUES (?, 'Sales', ?, ?, ?, ?, ?)");
    $stmt->execute([$project_id, $media_type, $title, $fileKey, $ext, $_SESSION['user_id']]);
    
    echo json_encode(['success' => true, 'message' => 'Media uploaded successfully!']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
