<?php
require_once '../init.php';

// 1. Setup CORS so the Chrome Extension is allowed to talk to Railway
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight requests from Chrome
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 2. Security: Verify the API Key
$apiKey = "ESTATE-HUB-SECURE-KEY-2026"; // You can change this later if you want
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ($authHeader !== "Bearer " . $apiKey) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized access."]);
    exit;
}

// 3. Get the data sent from the Chrome Extension
$data = json_decode(file_get_contents("php://input"), true);
$paNumber = trim($data['pa_number'] ?? '');
$paStatus = trim($data['pa_status'] ?? '');

if (empty($paNumber) || empty($paStatus)) {
    http_response_code(400);
    echo json_encode(["error" => "Missing PA Number or Status."]);
    exit;
}

try {
    // 4. Check if we actually track this PA number in Estate Hub
    $stmt = $pdo->prepare("SELECT id FROM project_pa_numbers WHERE pa_number = ?");
    $stmt->execute([$paNumber]);
    
    if ($stmt->rowCount() === 0) {
        echo json_encode(["success" => false, "message" => "$paNumber is not tracked in Estate Hub. Ignoring."]);
        exit;
    }

    // 5. Update the Database
    $update = $pdo->prepare("UPDATE project_pa_numbers SET pa_status = ? WHERE pa_number = ?");
    $update->execute([$paStatus, $paNumber]);

    echo json_encode(["success" => true, "message" => "$paNumber successfully updated to: $paStatus"]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database error: " . $e->getMessage()]);
}
