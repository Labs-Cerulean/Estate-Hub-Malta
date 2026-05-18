<?php
require_once '../config.php';
require_once '../session-check.php';
require_once '../user-functions.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Admins get automatic access. Plant Managers get access IF you tick the box in User Management!
$isManager = in_array($role, ['admin', 'director', 'system_manager', 'plant_manager']);
$canManageFleet = in_array($role, ['admin', 'system_manager']) || hasPermission('manage_plant_fleet');
$canViewLedger = in_array($role, ['admin', 'director', 'system_manager', 'accountant']) || hasPermission('view_plant_ledger');

// Dynamic API Key Mapper - Pulled securely from Environment Variables
$praApiKey = getenv('J2_API_KEY_PRA');
$praxApiKey = getenv('J2_API_KEY_PRAX');

if (!$praApiKey || !$praxApiKey) {
    die(json_encode(['error' => 'Critical Error: ERP API keys are missing from environment configuration.']));
}

$apiKeys = [
    '24' => $praApiKey,  // PRA API Key
    '26' => $praxApiKey, // PRAX API Key
    'default' => $praApiKey
];
$apiUrlBase = 'https://j2api.agiusgroup.com/api/public';

function getApiKey($companyId) {
    global $apiKeys;
    return $apiKeys[$companyId] ?? $apiKeys['default'];
}

function getJ2ApiData($endpoint, $apiKey) {
    global $apiUrlBase; $url = $apiUrlBase . $endpoint; $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Accept: application/json", "x-api-key: " . $apiKey, "Authorization: Bearer " . $apiKey]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // SECURITY FIX: Enabled SSL
    $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($httpCode >= 200 && $httpCode < 300) ? json_decode($response, true) : [];
}

function postJ2ApiData($endpoint, $apiKey, $payload) {
    global $apiUrlBase; $url = $apiUrlBase . $endpoint; $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Accept: application/json", "x-api-key: " . $apiKey, "Authorization: Bearer " . $apiKey]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload)); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // SECURITY FIX: Enabled SSL
    $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $httpCode, 'response' => $response];
}

if ($action == 'get_nominals' && $canManageFleet) {
    $apiKey = getApiKey($_GET['company_id'] ?? '');
    echo json_encode(getJ2ApiData('/nominalcateg', $apiKey) ?: []); exit;
}

if ($action == 'get_clients' && $canManageFleet) {
    echo json_encode($pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC)); exit;
}

if ($action == 'get_fleet' && $canManageFleet) {
    $fleet = $pdo->query("SELECT p.*, c.name as owner_name, bc.name as billing_company_name FROM plants p LEFT JOIN clients c ON p.developer_client_id = c.id LEFT JOIN clients bc ON p.billing_company_id = bc.id WHERE p.status = 'Active' ORDER BY p.category, p.name ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($fleet); exit;
}

if ($action == 'save_plant' && $canManageFleet) {
    $minHours = ($_POST['pricing_type'] === 'fixed_then_hourly') ? max(1, (float)$_POST['min_hours']) : 0;
    $nomVar = ($_POST['pricing_type'] === 'fixed_then_hourly' && !empty($_POST['nom_code_variable'])) ? $_POST['nom_code_variable'] : null;

    $stmt = $pdo->prepare("INSERT INTO plants (category, name, registration_plate, developer_client_id, inhouse_rate, external_rate, pricing_type, min_hours, nom_code_fixed, nom_code_variable, billing_company_id) VALUES (?, ?, ?, ?, 0.00, 0.00, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['category'], $_POST['name'], empty($_POST['reg']) ? null : $_POST['reg'], 
        $_POST['billing_company_id'], $_POST['pricing_type'], $minHours, $_POST['nom_code_fixed'], $nomVar, $_POST['billing_company_id']
    ]);
    echo "OK"; exit;
}

if ($action == 'update_plant' && $canManageFleet) {
    $minHours = ($_POST['pricing_type'] === 'fixed_then_hourly') ? max(1, (float)$_POST['min_hours']) : 0;
    $nomVar = ($_POST['pricing_type'] === 'fixed_then_hourly' && !empty($_POST['nom_code_variable'])) ? $_POST['nom_code_variable'] : null;

    $stmt = $pdo->prepare("UPDATE plants SET category=?, name=?, registration_plate=?, developer_client_id=?, inhouse_rate=0.00, external_rate=0.00, pricing_type=?, min_hours=?, nom_code_fixed=?, nom_code_variable=?, billing_company_id=? WHERE id=?");
    $stmt->execute([
        $_POST['category'], $_POST['name'], empty($_POST['reg']) ? null : $_POST['reg'], 
        $_POST['billing_company_id'], $_POST['pricing_type'], $minHours, $_POST['nom_code_fixed'], $nomVar, $_POST['billing_company_id'], $_POST['edit_plant_id']
    ]);
    echo "OK"; exit;
}

if ($action == 'get_drivers' && $canManageFleet) {
    echo json_encode($pdo->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'plant_driver' AND is_active = 'Yes' ORDER BY first_name ASC")->fetchAll(PDO::FETCH_ASSOC)); exit;
}

if ($action == 'save_driver' && $canManageFleet) {
    $email = trim($_POST['email']); $username = explode('@', $email)[0]; $hashedPassword = password_hash(trim($_POST['pass']), PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?"); $stmt->execute([$email, $username]);
    if ($stmt->fetch()) { echo "User exists."; exit; }
    $pdo->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 'plant_driver', 'Yes')")->execute([trim($_POST['first']), trim($_POST['last']), $username, $email, $hashedPassword]);
    $pdo->prepare("INSERT INTO user_capabilities (user_id, view_plant_bookings) VALUES (?, 1)")->execute([$pdo->lastInsertId()]); echo "OK"; exit;
}

if ($action == 'form_data') {
    // 1. Fetch and group Plants
    $plantsRaw = $pdo->query("SELECT id, name, category, registration_plate, billing_company_id FROM plants WHERE status='Active' ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);
    $plants = []; 
    foreach($plantsRaw as $p) { 
        $cat = empty($p['category']) ? 'General' : $p['category']; 
        if(!isset($plants[$cat])) $plants[$cat] = []; 
        $plants[$cat][] = $p; 
    }
    
    // 2. Fetch Drivers
    $drivers = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='plant_driver'")->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Fetch Projects & inject the 'city' column as 'locality' for the frontend grouper
    $projects = getAccessibleProjects($pdo, $userId);
    
    if (!empty($projects)) {
        // Grab just the IDs to look up their cities
        $projectIds = array_column($projects, 'id');
        $in = str_repeat('?,', count($projectIds) - 1) . '?';
        
        $stmt = $pdo->prepare("SELECT id, city FROM projects WHERE id IN ($in)");
        $stmt->execute($projectIds);
        $cities = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // Returns an array of [id => city]
        
        // Loop through the projects and attach the city
        foreach ($projects as &$prj) {
            $city = $cities[$prj['id']] ?? '';
            // If city is missing/empty, default it safely
            $prj['locality'] = empty(trim($city)) ? 'General / Other Regions' : trim($city);
        }
    }
    
    echo json_encode(['plants' => $plants, 'drivers' => $drivers, 'projects' => $projects]); 
    exit;
}

if ($action == 'get_company_clients' && $isManager) {
    $apiKey = getApiKey($_GET['company_id'] ?? '');
    $apiClients = getJ2ApiData('/clients', $apiKey);
    $results = [];
    if (is_array($apiClients)) {
        foreach ($apiClients as $c) {
            $name = trim((string)($c['ClientName'] ?? '')); $code = trim((string)($c['ClientCode'] ?? ''));
            if (!empty($name)) { $results[] = ['code' => $code, 'name' => $name]; }
        }
    }
    echo json_encode($results); exit;
}

if ($action == 'fetch_bookings') {
    $events = [];
    
    // SECURITY FIX: Using Prepared Statements to prevent SQL injection
    if ($isManager) {
        $query = "SELECT pb.*, p.name as plant_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id";
        $stmt = $pdo->query($query);
    } else {
        $query = "SELECT pb.*, p.name as plant_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id WHERE (pb.driver_id = ? OR pb.driver_id IS NULL OR pb.driver_id = 0)";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
    }
    
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $color = ($b['status'] == 'In Progress') ? '#f59e0b' : (($b['status'] == 'Completed') ? '#10b981' : '#3b82f6');
        $events[] = ['id' => $b['id'], 'title' => $b['plant_name'], 'start' => $b['booking_date'] . 'T' . $b['start_time'], 'end' => $b['booking_date'] . 'T' . $b['end_time'], 'backgroundColor' => $color, 'borderColor' => $color];
    }
    echo json_encode($events); exit;
}

if ($action == 'create_booking' && $isManager) {
    $pdo->prepare("INSERT INTO plant_bookings (plant_id, driver_id, booking_type, project_id, client_name, client_code, location_lat, location_lng, booking_date, start_time, end_time, comments, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([ $_POST['plant_id'], empty($_POST['driver_id']) ? null : $_POST['driver_id'], $_POST['booking_type'], empty($_POST['project_id']) ? null : $_POST['project_id'], $_POST['client_name'], empty($_POST['client_code']) ? null : $_POST['client_code'], empty($_POST['loc_lat']) ? null : $_POST['loc_lat'], empty($_POST['loc_lng']) ? null : $_POST['loc_lng'], $_POST['booking_date'], $_POST['start_time'], $_POST['end_time'], $_POST['comments'], $userId ]); echo "OK"; exit;
}

if ($action == 'update_booking' && $isManager) {
    $pdo->prepare("UPDATE plant_bookings SET plant_id=?, driver_id=?, booking_type=?, project_id=?, client_name=?, client_code=?, location_lat=?, location_lng=?, booking_date=?, start_time=?, end_time=?, comments=? WHERE id=?")->execute([ $_POST['plant_id'], empty($_POST['driver_id']) ? null : $_POST['driver_id'], $_POST['booking_type'], empty($_POST['project_id']) ? null : $_POST['project_id'], $_POST['client_name'], empty($_POST['client_code']) ? null : $_POST['client_code'], empty($_POST['loc_lat']) ? null : $_POST['loc_lat'], empty($_POST['loc_lng']) ? null : $_POST['loc_lng'], $_POST['booking_date'], $_POST['start_time'], $_POST['end_time'], $_POST['comments'], $_POST['edit_id'] ]); echo "OK"; exit;
}

if ($action == 'cancel_booking' && $isManager) { $pdo->prepare("DELETE FROM plant_bookings WHERE id=?")->execute([$_POST['id']]); echo "OK"; exit; }
if ($action == 'get_project_location' && $isManager) { $stmt = $pdo->prepare("SELECT latitude, longitude FROM projects WHERE id = ?"); $stmt->execute([$_GET['project_id']]); echo json_encode($stmt->fetch(PDO::FETCH_ASSOC)); exit; }

if ($action == 'get_job') {
    $stmt = $pdo->prepare("SELECT pb.*, p.name as plant_name, p.category, p.pricing_type, prj.name as project_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id LEFT JOIN projects prj ON pb.project_id = prj.id WHERE pb.id = ?"); $stmt->execute([$_GET['id']]); $job = $stmt->fetch(PDO::FETCH_ASSOC); $job['location_text'] = $job['booking_type'] == 'in-house' ? "Project: " . $job['project_name'] : "External: " . $job['client_name']; echo json_encode($job); exit;
}

if ($action == 'punch_in') { $pdo->prepare("UPDATE plant_bookings SET status='In Progress', punch_in_time=NOW(), driver_id=? WHERE id=?")->execute([$userId, $_GET['id']]); echo "OK"; exit; }
if ($action == 'punch_out_complete') {
    $bookingId = $_POST['id'];
    $pdo->prepare("UPDATE plant_bookings SET status='Completed', punch_out_time=NOW(), qty_trips=?, client_rep_name=?, client_rep_id_card=?, signature_data=? WHERE id=?")->execute([empty($_POST['qty_trips']) ? null : $_POST['qty_trips'], $_POST['rep_name'], $_POST['rep_id'], $_POST['signature'], $bookingId]);
    $domain = "https://" . $_SERVER['HTTP_HOST']; $accEmail = $pdo->query("SELECT email FROM users WHERE role='accountant' AND is_active='Yes' LIMIT 1")->fetchColumn() ?: 'accounts@yourdomain.com'; @mail($accEmail, "Plant Job Completed", "A heavy plant job has been completed. Review RFP: " . $domain . "/print_plant_invoice.php?booking_id=" . $bookingId, "From: system@yourdomain.com"); echo "OK"; exit;
}

function getNominalDetails($nomCode, $apiKey) {
    if(empty($nomCode)) return null; $nominals = getJ2ApiData('/nominalcateg', $apiKey);
    foreach($nominals as $n) { if(trim($n['NCCode']) == $nomCode) return $n; } return null;
}

if ($action == 'finalize_and_invoice' && $canViewLedger) {
    $bookingId = $_POST['booking_id'];
    
    // SAFEGUARD: Force inputs to be floats so MySQL doesn't crash on empty strings ""
    $finalHours = empty($_POST['hours']) ? 0 : (float)$_POST['hours'];
    $finalRate = empty($_POST['rate']) ? 0 : (float)$_POST['rate'];
    $finalSubtotal = empty($_POST['subtotal']) ? 0 : (float)$_POST['subtotal'];

    $pdo->prepare("UPDATE plant_bookings SET final_hours=?, final_rate=?, final_subtotal=?, payment_status='Invoiced' WHERE id=?")->execute([$finalHours, $finalRate, $finalSubtotal, $bookingId]);
    
    // UPDATED QUERY: Added LEFT JOIN on users to fetch Driver's Name for the ERP Text Line
    $stmt = $pdo->prepare("SELECT pb.*, p.billing_company_id, p.pricing_type, p.nom_code_fixed, p.nom_code_variable, p.inhouse_rate, p.external_rate, p.min_hours, p.name as plant_name, u.first_name as driver_first, u.last_name as driver_last FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id LEFT JOIN users u ON pb.driver_id = u.id WHERE pb.id = ?"); 
    $stmt->execute([$bookingId]); 
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    $apiKey = getApiKey($job['billing_company_id']);
    
    if(empty($job['client_code'])) { echo "LOCAL_SAVE_ONLY: Invoice generated locally, but missing Client Code prevented pushing to ERP."; exit; }

    $isInternal = $job['booking_type'] == 'in-house'; 
    $totalVal = $finalSubtotal; 
    $totalTax = $totalVal * 0.18;
    $jobRef = sprintf("PRA-%s-%04d", date('Y', strtotime($job['booking_date'])), $bookingId);
    
    // Format the driver name safely
    $driverName = trim(($job['driver_first'] ?? 'Unassigned') . ' ' . ($job['driver_last'] ?? ''));
    
    // BUILD THE BILLING LINES
    $lines = [];
    
    if ($job['pricing_type'] == 'fixed_then_hourly' && !empty($job['nom_code_fixed'])) {
        $fixedNom = getNominalDetails($job['nom_code_fixed'], $apiKey);
        if($fixedNom) { $lines[] = ["Type" => "N", "Code" => trim($fixedNom['NCCode']), "Description" => substr(trim($fixedNom['NCDesc']), 0, 35), "UOMLevel" => 1, "Location" => "01", "Qty" => 1, "Price" => $isInternal ? $fixedNom['NCDefSP1'] : $fixedNom['NCDefSP2'], "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0]; }
        $extraHours = $finalHours - (float)$job['min_hours'];
        if ($extraHours > 0 && !empty($job['nom_code_variable'])) {
            $varNom = getNominalDetails($job['nom_code_variable'], $apiKey);
            if($varNom) { $lines[] = ["Type" => "N", "Code" => trim($varNom['NCCode']), "Description" => substr(trim($varNom['NCDesc']), 0, 35), "UOMLevel" => 1, "Location" => "01", "Qty" => $extraHours, "Price" => $isInternal ? $varNom['NCDefSP1'] : $varNom['NCDefSP2'], "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0]; }
        }
    } 
    elseif ($job['pricing_type'] == 'per_trip' && !empty($job['nom_code_fixed'])) {
        $tripNom = getNominalDetails($job['nom_code_fixed'], $apiKey);
        if($tripNom) { $lines[] = ["Type" => "N", "Code" => trim($tripNom['NCCode']), "Description" => substr(trim($tripNom['NCDesc']), 0, 35), "UOMLevel" => 1, "Location" => "01", "Qty" => (float)$job['qty_trips'] > 0 ? (float)$job['qty_trips'] : 1, "Price" => $isInternal ? $tripNom['NCDefSP1'] : $tripNom['NCDefSP2'], "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0]; }
    }
    else {
        $standardCode = !empty($job['nom_code_variable']) ? $job['nom_code_variable'] : '0000'; 
        $standardNom = getNominalDetails($standardCode, $apiKey);
        $lines[] = ["Type" => "N", "Code" => $standardNom ? trim($standardNom['NCCode']) : $standardCode, "Description" => substr($standardNom ? trim($standardNom['NCDesc']) : "Plant Operation", 0, 35), "UOMLevel" => 1, "Location" => "01", "Qty" => $finalHours, "Price" => $finalRate, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0];
    }

    if (empty($lines)) { echo "ERP_SYNC_FAILED: No Nominal Codes found. Cannot sync empty invoice."; exit; }

    // APPEND IT'S REQUESTED METADATA TEXT LINES
    $lines[] = [
        "Type" => "T",
        "Code" => "0000",
        "Description" => substr("Delivery Note: " . $jobRef, 0, 35),
        "Qty" => 1,
        "Location" => "01"
    ];

    $lines[] = [
        "Type" => "T",
        "Code" => "0000",
        "Description" => substr("Driver: " . $driverName, 0, 35),
        "Qty" => 1,
        "Location" => "01"
    ];

    // BULLETPROOF PAYLOAD: Mapped to IT's exactly supplied structure and casing
    $payload = [
        "Type" => "IN", 
        "PaymentType" => null, 
        "Description" => null, 
        "Amount" => null, 
        "Change" => null, 
        "NominalAccount" => null, 
        "Transaction" => [ 
            "InvioceHeader" => [ 
                "THTranCode" => "IN", 
                "THDate" => date('Y-m-d'), 
                "THUserID" => "API", 
                "THCSCode" => $job['client_code'], 
                "THName" => $job['client_name'], 
                "THTaxNumber" => "", 
                "THTotValueTIF" => (string)round($totalVal + $totalTax, 2), 
                "THExtRef" => $jobRef,   // IT Update: Inserted Job Ref here
                "THRevision" => "001", 
                "THTotDiscF" => 0.0, 
                "THTotDiscTIF" => 0.0, 
                "THTotTaxF" => round($totalTax, 2), 
                "THCurrency" => "EUR", 
                "THExchRate" => 1, 
                "THPayment" => "", 
                "THPayRef" => ""         // Emptied as per IT request
            ], 
            "InvioceItemLine" => [ 
                "Lines" => $lines 
            ], 
            "Ledger" => "S", 
            "OfflineDocRefs" => "" 
        ] 
    ];

    $erpResult = postJ2ApiData('/sales/transaction', $apiKey, $payload);
    
    if ($erpResult['code'] >= 200 && $erpResult['code'] < 300) { 
        $sysRef = json_decode($erpResult['response'], true)['SysRef'] ?? 'SUCCESS_NO_REF'; 
        $pdo->prepare("UPDATE plant_bookings SET invoice_sysref = ? WHERE id = ?")->execute([$sysRef, $bookingId]); 
        echo "OK"; 
    } else { 
        echo "ERP_SYNC_FAILED: " . htmlspecialchars($erpResult['response']); 
    } 
    exit;
}

if ($action == 'get_ledger' && $canViewLedger) {
    echo json_encode($pdo->query("SELECT pb.*, p.name as plant_name, bc.name as billing_company_name, prj.name as project_name, c.name as client_dev_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id LEFT JOIN projects prj ON pb.project_id = prj.id LEFT JOIN clients c ON p.developer_client_id = c.id LEFT JOIN clients bc ON p.billing_company_id = bc.id ORDER BY pb.booking_date DESC")->fetchAll(PDO::FETCH_ASSOC)); exit;
}

if ($action == 'mark_settled' && $canViewLedger) { $pdo->prepare("UPDATE plant_bookings SET payment_status='Settled' WHERE id=?")->execute([$_POST['id']]); echo "OK"; exit; }

if ($action == 'get_nominals_for_job') {
    $stmt = $pdo->prepare("SELECT p.nom_code_fixed, p.nom_code_variable, p.billing_company_id FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id WHERE pb.id = ?"); 
    $stmt->execute([$_GET['booking_id']]); 
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $apiKey = getApiKey($job['billing_company_id']);
    echo json_encode(['fixed' => getNominalDetails($job['nom_code_fixed'], $apiKey), 'variable' => getNominalDetails($job['nom_code_variable'], $apiKey)]); exit;
}
?>
