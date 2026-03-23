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
        
        // Prepare the insert statement for the property
        $stmt = $pdo->prepare("
            INSERT INTO sales_properties 
            (project_id, unit_name, unit_type, description, internal_sqm, external_sqm, shell_price, finishes_price, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // Prepare the insert statement for the individual audit log
        $log_stmt = $pdo->prepare("
            INSERT INTO sales_property_logs 
            (property_id, user_id, action, old_status, new_status, justification) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        // Loop through each row in the CSV
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // Check if row has enough data (avoids blank rows at the end)
            if(count($data) >= 8 && trim($data[0]) !== '') {
                
                $unit_name    = trim($data[0]);
                $unit_type    = strtolower(trim($data[1])); 
                $description  = trim($data[2]);
                $internal_sqm = (float) trim($data[3]);
                $external_sqm = (float) trim($data[4]);
                $shell_price  = (float) trim($data[5]);
                $cp_price     = (float) trim($data[6]);
                $status       = trim($data[7]);
                
                if(empty($status)) $status = 'Available';

                // 1. Insert the Property
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
                
                // 2. Get the new, real ID of the unit we just created
                $new_property_id = $pdo->lastInsertId();

                // 3. Log this specific unit's creation
                $log_stmt->execute([
                    $new_property_id, 
                    $_SESSION['user_id'], 
                    'Initial Frame Import', 
                    'None', 
                    $status, 
                    'Imported via CSV Frame Upload'
                ]);
                
                $inserted_count++;
            }
        }
        fclose($handle);
        
        echo json_encode(['success' => true, 'message' => "$inserted_count units successfully uploaded and logged!"]);
    } else {
        throw new Exception("Could not read the uploaded file.");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
