<?php
require_once 'config.php';
require_once 'session-check.php';

// Security: Only allow Admins to run this test
if (!in_array($_SESSION['role'], ['admin', 'system_manager'])) {
    die("Unauthorized Access.");
}

// 1. Set your credentials
$apiKey = 'PASTE_YOUR_API_KEY_HERE';
$companyId = 'IBS999PRA';

// 2. Define the endpoint. 
// Note: Depending on their API design, the company ID might need to be in the URL query string like this.
$url = "https://j2api.agiusgroup.com/api/public/stocks?companyId=" . urlencode($companyId);

// Initialize cURL
$ch = curl_init($url);

// 3. Set the headers
// Check your Swagger docs! Different APIs expect the key in different ways. 
// "x-api-key" and "Authorization: Bearer" are the two most common. I have included both below; the server will usually just ignore the one it doesn't need.
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Accept: application/json",
    "x-api-key: " . $apiKey,
    "Authorization: Bearer " . $apiKey,
    "Company: " . $companyId // Sometimes they expect the company ID as a header instead of in the URL
]);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// If their server has a strict/internal SSL setup that blocks the connection, uncomment this:
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

// 4. Execute the request
echo "<div style='font-family: sans-serif; padding: 20px; max-width: 800px; margin: 0 auto;'>";
echo "<h2 style='color: #0f172a;'>API Connection Test</h2>";
echo "<p><strong>Target:</strong> <code>" . htmlspecialchars($url) . "</code></p>";

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// 5. Output the results
if (curl_errno($ch)) {
    // This happens if the firewall is STILL blocking you, or the URL is unreachable.
    echo "<div style='background: #fef2f2; border: 1px solid #f87171; color: #991b1b; padding: 15px; border-radius: 8px;'>";
    echo "<strong>Connection Failed!</strong><br>cURL Error: " . curl_error($ch);
    echo "</div>";
} else {
    // The connection reached the server! Now we check what the server thought of our request.
    $statusColor = ($httpCode >= 200 && $httpCode < 300) ? '#10b981' : '#f59e0b';
    if ($httpCode >= 400) $statusColor = '#ef4444';

    echo "<div style='margin-bottom: 20px;'><strong>HTTP Status Code:</strong> <span style='background: $statusColor; color: white; padding: 3px 8px; border-radius: 4px; font-weight: bold;'>$httpCode</span></div>";
    
    if ($httpCode == 200) {
        echo "<div style='color: #065f46; background: #d1fae5; padding: 10px; border-radius: 6px; font-weight: bold; margin-bottom: 15px;'>Success! The API accepted the key and returned data.</div>";
    } elseif ($httpCode == 401 || $httpCode == 403) {
        echo "<div style='color: #991b1b; background: #fee2e2; padding: 10px; border-radius: 6px; font-weight: bold; margin-bottom: 15px;'>Authentication Failed. The API Key is incorrect, or we are passing it in the wrong header format. Check Swagger to see what header name it expects!</div>";
    }

    echo "<h3>Raw Server Response:</h3>";
    echo "<pre style='background: #1e293b; color: #38bdf8; padding: 15px; border-radius: 8px; overflow-x: auto; max-height: 500px;'>";
    
    // Attempt to format the JSON beautifully
    $jsonDecoded = json_decode($response);
    if ($jsonDecoded) {
        echo htmlspecialchars(json_encode($jsonDecoded, JSON_PRETTY_PRINT));
    } else {
        echo htmlspecialchars($response ?: 'No data returned (Empty Response)');
    }
    
    echo "</pre>";
}

curl_close($ch);
echo "</div>";
?>
