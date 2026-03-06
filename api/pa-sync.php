<?php
require_once '../init.php';

// 1. Setup CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

// 2. Security: Verify API Key
$apiKey = "ESTATE-HUB-SECURE-KEY-2026";
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ($authHeader !== "Bearer " . $apiKey) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized access."]); exit;
}

// 3. Get Data
$data = json_decode(file_get_contents("php://input"), true);
$paNumber = trim($data['pa_number'] ?? '');
$paStatus = trim($data['pa_status'] ?? '');

if (empty($paNumber) || empty($paStatus)) {
    http_response_code(400);
    echo json_encode(["error" => "Missing PA Number or Status."]); exit;
}

try {
    // 4. Fetch the CURRENT status from the database
    $stmt = $pdo->prepare("SELECT pa_status FROM project_pa_numbers WHERE pa_number = ?");
    $stmt->execute([$paNumber]);
    $currentRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentRecord) {
        echo json_encode(["success" => false, "message" => "$paNumber is not tracked in Estate Hub. Ignoring."]);
        exit;
    }

    $currentStatus = $currentRecord['pa_status'];

    // 5. THE SAFETY LOCK: Prevent "Decided" from overriding "Endorsed"
    if ($currentStatus === 'Endorsed' && $paStatus === 'Decided') {
        echo json_encode([
            "success" => true, 
            "message" => "Ignored 'Decided'. Project is already marked as Endorsed!"
        ]);
        exit;
    }

    // 6. Update the Database
    $update = $pdo->prepare("UPDATE project_pa_numbers SET pa_status = ? WHERE pa_number = ?");
    $update->execute([$paStatus, $paNumber]);

    echo json_encode(["success" => true, "message" => "$paNumber successfully updated to: $paStatus"]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database error: " . $e->getMessage()]);
}
