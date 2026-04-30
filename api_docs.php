<?php
require_once 'config.php';
require_once 'session-check.php';

// Only allow Admins to view the API documentation
if (!in_array($_SESSION['role'], ['admin', 'system_manager'])) {
    die("Unauthorized Access.");
}

// 1. If the page is asking for the JSON data, fetch it through the Railway IP
if (isset($_GET['fetch_json'])) {
    
    // We can confidently guess the hidden JSON path based on standard Swagger setups
    $possibleUrls = [
        'https://j2api.agiusgroup.com/swagger/docs/J2',
        'https://j2api.agiusgroup.com/swagger/J2/swagger.json',
        'https://j2api.agiusgroup.com/swagger/docs/v1',
        'https://j2api.agiusgroup.com/swagger/v1/swagger.json',
        'https://j2api.agiusgroup.com/swagger.json'
    ];

    foreach ($possibleUrls as $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Ignore SSL strictness for the proxy
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // If we get a 200 OK and it looks like a Swagger file, output it and stop searching!
        if ($httpCode == 200 && (strpos($response, '"swagger"') !== false || strpos($response, '"openapi"') !== false || strpos($response, '"paths"') !== false)) {
            header('Content-Type: application/json');
            echo $response;
            exit;
        }
    }
    
    // If it fails to find the JSON
    header('Content-Type: application/json');
    echo json_encode(["error" => "Could not automatically locate the Swagger JSON file. Firewall may be blocking it, or the path is non-standard."]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>J2 API Documentation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/4.18.3/swagger-ui.css" />
    <style>
        body { margin: 0; padding: 0; background: #fafafa; }
        .topbar { display: none; } /* Hides the default Swagger top bar */
    </style>
</head>
<body>
    <div style="background: #0f172a; color: #fff; padding: 15px 20px; font-family: sans-serif; font-weight: bold;">
        <a href="dashboard.php" style="color: #38bdf8; text-decoration: none; margin-right: 20px;">&larr; Back to App</a>
        J2 API Vehicle & Invoice Endpoints (Proxied securely via Railway)
    </div>
    
    <div id="swagger-ui"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/4.18.3/swagger-ui-bundle.js"></script>
    <script>
        window.onload = () => {
            window.ui = SwaggerUIBundle({
                // This tells the UI to fetch the data using the PHP proxy logic above!
                url: "api_docs.php?fetch_json=1", 
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [ SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset ],
                layout: "BaseLayout"
            });
        };
    </script>
</body>
</html>
