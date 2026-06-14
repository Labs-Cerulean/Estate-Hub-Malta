<?php
require_once 'config.php';

// Fallback to the PRA API key we know works, or try fetching from the environment
$apiKey = getenv('J2_API_KEY_PRA') ?: 'o/7b6jY815wajiIhCBbvd69etum9GykU5IX1LSG9Zfs='; 
$apiUrl = 'https://j2api.agiusgroup.com/api/public/clients';

// Initialize cURL to fetch the clients
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json", 
    "Accept: application/json", 
    "x-api-key: " . $apiKey, 
    "Authorization: Bearer " . $apiKey
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
$response = curl_exec($ch); 
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
curl_close($ch);

// Decode the JSON
$clients = json_decode($response, true);
$firstFewClients = is_array($clients) ? array_slice($clients, 0, 3) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ERP API Diagnostic Tool</title>
    <style>
        body { font-family: monospace; background: #0f172a; color: #38bdf8; padding: 20px; }
        .box { background: #1e293b; padding: 20px; border-radius: 8px; border: 1px solid #334155; margin-top: 20px; }
        h2 { color: #fff; margin-top: 0; }
        pre { white-space: pre-wrap; word-wrap: break-word; color: #a3e635; }
    </style>
</head>
<body>
    <h2>ERP Endpoint: /clients</h2>
    <p>HTTP Status Code: <b style="color: <?= $httpCode == 200 ? '#10b981' : '#ef4444' ?>;"><?= $httpCode ?></b></p>
    
    <div class="box">
        <p style="color: #fff;">Look closely at the fields below for anything resembling "Discount", "DefDisc", "DefaultDiscount", etc.</p>
        <hr style="border-color: #334155;">
        <pre><?= htmlspecialchars(json_encode($firstFewClients, JSON_PRETTY_PRINT)) ?></pre>
    </div>
</body>
</html>
