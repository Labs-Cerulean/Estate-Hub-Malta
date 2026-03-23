<?php
require_once '../config.php';
require_once '../session-check.php';

header('Content-Type: application/json');

// Only allow managers to upload project frames
$allowed_roles = ['sales_manager', 'system_manager', 'admin', 'director'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$project_id = $_POST['project_id'] ?? 0;

if (!$project_id || empty($_FILES['frame_csv']['tmp_name'])) {
    echo json_encode(['success' => false, 'message' => 'Project ID and CSV file are required.']);
    exit;
}

try {
    $file = $_FILES['frame_csv']['tmp_name'];
    $handle = fopen($file, "r");
    
    if ($handle !== FALSE) {
        // Skip the header row (Row 1)
        fgetcsv($handle, 1000, ",");
        
        $inserted_count = 0;
        
        // Prepare the insert statement to match the 8 columns
        $stmt = $pdo->prepare("
            INSERT INTO sales_properties 
            (project_id, unit_name, unit_type, description, internal_sqm, external_sqm, shell_price, finishes_price, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        // Loop through each row in the CSV
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // Check if row has enough data (avoids blank rows at the end)
            if(count($data) >= 8 && trim($data[0]) !== '') {
                
                $unit_name    = trim($data[0]);
                $unit_type    = strtolower(trim($data[1])); // apartment, commercial, or garage
                $description  = trim($data[2]);
                $internal_sqm = (float) trim($data[3]);
                $external_sqm = (float) trim($data[4]);
                $shell_price  = (float) trim($data[5]);
                $cp_price     = (float) trim($data[6]);
                $status       = trim($data[7]);
                
                // Fallback for empty status
                if(empty($status)) $status = 'Available';

                $stmt->execute([
                    $project_id, 
                    $unit_name, 
                    $unit_type, 
                    $description, 
                    $internal_sqm, 
                    $external_sqm, 
                    $shell_price, 
                    $cp_price, 
                    $status
                ]);
                
                $inserted_count++;
            }
        }
        fclose($handle);
        
        // Log the bulk action (Rule #3)
        $log = $pdo->prepare("INSERT INTO sales_property_logs (property_id, user_id, action, old_status, new_status, justification) VALUES (?, ?, ?, ?, ?, ?)");
        $log->execute([0, $_SESSION['user_id'], 'Bulk Project Frame Upload', 'None', 'Available/Hold', "Uploaded $inserted_count units to Project $project_id"]);

        echo json_encode(['success' => true, 'message' => "$inserted_count units successfully uploaded!"]);
    } else {
        throw new Exception("Could not read the uploaded file.");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
