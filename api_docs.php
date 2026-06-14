<?php
// Turn on error reporting for diagnostics
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// Fetch API Keys
$praApiKey = getenv('J2_API_KEY_PRA') ?: 'o/7b6jY815wajiIhCBbvd69etum9GykU5IX1LSG9Zfs='; 
$praxApiKey = getenv('J2_API_KEY_PRAX') ?: 'PRAX_KEY_NOT_SET_IN_ENV';

$apiUrlBase = 'https://j2api.agiusgroup.com/api/public';

$companies = [
    'PRA' => $praApiKey,
    'PRAX' => $praxApiKey
];

$endpoints = [
    '/clients' => 'Clients (List all ERP Clients & Discounts)',
    '/nominalcateg' => 'Nominal Categories (List all Nominal Codes & Standard Rates)'
];

$responseBody = null;
$httpCode = null;
$selectedCompany = $_POST['company'] ?? 'PRA';
$selectedEndpoint = $_POST['endpoint'] ?? '/clients';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = $companies[$selectedCompany];
    $url = $apiUrlBase . $selectedEndpoint;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json", 
        "Accept: application/json", 
        "x-api-key: " . $apiKey, 
        "Authorization: Bearer " . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    
    $rawResponse = curl_exec($ch); 
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
    curl_close($ch);

    $decoded = json_decode($rawResponse, true);
    
    if (is_array($decoded)) {
        // Slice the array to show only the first 5 records to prevent browser hanging
        $responseBody = array_slice($decoded, 0, 5);
        $totalRecords = count($decoded);
    } else {
        $responseBody = $rawResponse; // Output raw string if not JSON
        $totalRecords = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERP API Diagnostic Tool</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #f8fafc; padding: 30px; margin: 0; }
        .container { max-width: 900px; margin: 0 auto; }
        h2 { margin-top: 0; color: #38bdf8; font-weight: 900; letter-spacing: -0.5px; }
        .control-panel { background: #1e293b; padding: 20px; border-radius: 12px; border: 1px solid #334155; display: flex; gap: 15px; align-items: flex-end; margin-bottom: 25px; }
        .form-group { display: flex; flex-direction: column; flex: 1; }
        label { font-size: 0.85rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 8px; }
        select { padding: 12px; background: #0f172a; color: #fff; border: 1px solid #475569; border-radius: 8px; font-size: 1rem; outline: none; }
        select:focus { border-color: #38bdf8; }
        button { background: #10b981; color: #fff; border: none; padding: 12px 20px; border-radius: 8px; font-weight: bold; font-size: 1rem; cursor: pointer; transition: 0.2s; }
        button:hover { background: #059669; }
        
        .result-box { background: #1e293b; padding: 20px; border-radius: 12px; border: 1px solid #334155; }
        .status { display: inline-block; padding: 5px 10px; border-radius: 6px; font-weight: bold; font-size: 0.9rem; margin-bottom: 15px; }
        .status.success { background: #064e3b; color: #34d399; }
        .status.error { background: #7f1d1d; color: #f87171; }
        .status.neutral { background: #334155; color: #cbd5e1; }
        
        pre { background: #000; padding: 15px; border-radius: 8px; overflow-x: auto; color: #a3e635; border: 1px solid #334155; font-size: 0.9rem; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="container">
        <h2><i class="fas fa-network-wired"></i> ERP API Diagnostic Hub</h2>
        
        <form method="POST" class="control-panel">
            <div class="form-group">
                <label>Company API Key</label>
                <select name="company">
                    <?php foreach ($companies as $name => $key): ?>
                        <option value="<?= $name ?>" <?= $selectedCompany === $name ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>ERP Endpoint</label>
                <select name="endpoint">
                    <?php foreach ($endpoints as $path => $desc): ?>
                        <option value="<?= $path ?>" <?= $selectedEndpoint === $path ? 'selected' : '' ?>><?= $path ?> - <?= $desc ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit">Send Test Request</button>
        </form>

        <div class="result-box">
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div class="status <?= $httpCode >= 200 && $httpCode < 300 ? 'success' : 'error' ?>">
                        HTTP Status: <?= $httpCode ?>
                    </div>
                    <?php if (isset($totalRecords)): ?>
                        <div style="color: #94a3b8; font-size: 0.9rem; font-weight: bold;">
                            Total Records in ERP: <span style="color: #fff;"><?= $totalRecords ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <p style="color: #94a3b8; font-size: 0.85rem; margin-top: 0;">Showing preview of the first 5 records:</p>
                <pre><?= htmlspecialchars(json_encode($responseBody, JSON_PRETTY_PRINT)) ?></pre>
            <?php else: ?>
                <div class="status neutral">Waiting for request...</div>
                <p style="color: #94a3b8; font-size: 0.9rem; margin: 0;">Select an endpoint and click "Send Test Request" to view live ERP data.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
