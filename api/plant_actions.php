<?php
require_once '../config.php';
require_once '../session-check.php';
require_once '../user-functions.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Restrict Fleet Management to Admins only. Plant Managers can still manage bookings.
$isManager = in_array($role, ['admin', 'director', 'system_manager', 'plant_manager']);
$canManageFleet = in_array($role, ['admin', 'system_manager']);
$canViewLedger = in_array($role, ['admin', 'director', 'system_manager', 'accountant']);

$apiKey = 'o/7b6jY815wajiIhCBbvd69etum9GykU5IX1LSG9Zfs='; 
$apiUrlBase = 'https://j2api.agiusgroup.com/api/public';

function getJ2ApiData($endpoint, $apiKey) {
    global $apiUrlBase; $url = $apiUrlBase . $endpoint; $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Accept: application/json", "x-api-key: " . $apiKey, "Authorization: Bearer " . $apiKey]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($httpCode >= 200 && $httpCode < 300) ? json_decode($response, true) : [];
}

function postJ2ApiData($endpoint, $apiKey, $payload) {
    global $apiUrlBase; $url = $apiUrlBase . $endpoint; $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Accept: application/json", "x-api-key: " . $apiKey, "Authorization: Bearer " . $apiKey]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload)); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $httpCode, 'response' => $response];
}

// NEW: Endpoint to get Nominal Codes for the Fleet Manager
if ($action == 'get_nominals' && $canManageFleet) {
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
    // Force backend data integrity rules
    $minHours = ($_POST['pricing_type'] === 'fixed_then_hourly') ? max(1, (float)$_POST['min_hours']) : 0;
    $nomVar = ($_POST['pricing_type'] === 'fixed_then_hourly' && !empty($_POST['nom_code_variable'])) ? $_POST['nom_code_variable'] : null;

    $stmt = $pdo->prepare("INSERT INTO plants (category, name, registration_plate, developer_client_id, inhouse_rate, external_rate, pricing_type, min_hours, nom_code_fixed, nom_code_variable, billing_company_id) VALUES (?, ?, ?, ?, 0.00, 0.00, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['category'], $_POST['name'], empty($_POST['reg']) ? null : $_POST['reg'], 
        $_POST['billing_company_id'], // Owner automatically mirrors Billing Company
        $_POST['pricing_type'], $minHours, $_POST['nom_code_fixed'], $nomVar, $_POST['billing_company_id']
    ]);
    echo "OK"; exit;
}

if ($action == 'update_plant' && $canManageFleet) {
    // Force backend data integrity rules
    $minHours = ($_POST['pricing_type'] === 'fixed_then_hourly') ? max(1, (float)$_POST['min_hours']) : 0;
    $nomVar = ($_POST['pricing_type'] === 'fixed_then_hourly' && !empty($_POST['nom_code_variable'])) ? $_POST['nom_code_variable'] : null;

    $stmt = $pdo->prepare("UPDATE plants SET category=?, name=?, registration_plate=?, developer_client_id=?, inhouse_rate=0.00, external_rate=0.00, pricing_type=?, min_hours=?, nom_code_fixed=?, nom_code_variable=?, billing_company_id=? WHERE id=?");
    $stmt->execute([
        $_POST['category'], $_POST['name'], empty($_POST['reg']) ? null : $_POST['reg'], 
        $_POST['billing_company_id'], // Owner automatically mirrors Billing Company
        $_POST['pricing_type'], $minHours, $_POST['nom_code_fixed'], $nomVar, $_POST['billing_company_id'], $_POST['edit_plant_id']
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
    $plantsRaw = $pdo->query("SELECT id, name, category, registration_plate, billing_company_id FROM plants WHERE status='Active' ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);
    $plants = []; foreach($plantsRaw as $p) { $cat = empty($p['category']) ? 'General' : $p['category']; if(!isset($plants[$cat])) $plants[$cat] = []; $plants[$cat][] = $p; }
    echo json_encode(['plants' => $plants, 'drivers' => $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='plant_driver'")->fetchAll(PDO::FETCH_ASSOC), 'projects' => getAccessibleProjects($pdo, $userId)]); exit;
}

if ($action == 'get_company_clients' && $isManager) {
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
    $query = "SELECT pb.*, p.name as plant_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id";
    if (!$isManager) { $query .= " WHERE (pb.driver_id = $userId OR pb.driver_id IS NULL OR pb.driver_id = 0)"; }
    $events = [];
    foreach ($pdo->query($query)->fetchAll(PDO::FETCH_ASSOC) as $b) {
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
   if ($action == 'finalize_and_invoice' && $canViewLedger) {
    $bookingId = $_POST['booking_id'];
    
    // SAFEGUARD: Force inputs to be floats so MySQL doesn't crash on empty strings ""
    $finalHours = empty($_POST['hours']) ? 0 : (float)$_POST['hours'];
    $finalRate = empty($_POST['rate']) ? 0 : (float)$_POST['rate'];
    $finalSubtotal = empty($_POST['subtotal']) ? 0 : (float)$_POST['subtotal'];

    // Use the safe variables in the SQL statement
    $pdo->prepare("UPDATE plant_bookings SET final_hours=?, final_rate=?, final_subtotal=?, payment_status='Invoiced' WHERE id=?")
        ->execute([$finalHours, $finalRate, $finalSubtotal, $bookingId]);
    
    $stmt = $pdo->prepare("SELECT pb.*, p.billing_company_id, p.pricing_type, p.nom_code_fixed, p.nom_code_variable, p.inhouse_rate, p.external_rate, p.min_hours, p.name as plant_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id WHERE pb.id = ?"); 
    $stmt->execute([$bookingId]); 
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
       
    if(empty($job['client_code'])) { echo "LOCAL_SAVE_ONLY: Invoice generated locally, but missing Client Code prevented pushing to ERP."; exit; }

    $isInternal = $job['booking_type'] == 'in-house'; $totalVal = (float)$_POST['subtotal']; $totalTax = $totalVal * 0.18;
    $jobRef = sprintf("PRA-%s-%04d", date('Y', strtotime($job['booking_date'])), $bookingId);
    $lines = [];
    
    if ($job['pricing_type'] == 'fixed_then_hourly' && !empty($job['nom_code_fixed'])) {
        $fixedNom = getNominalDetails($job['nom_code_fixed'], $apiKey);
        if($fixedNom) { $lines[] = ["Type" => "N", "Code" => trim($fixedNom['NCCode']), "Description" => trim($fixedNom['NCDesc']) . " ($jobRef)", "UOMLevel" => 1, "Location" => "01", "Qty" => 1, "Price" => $isInternal ? $fixedNom['NCDefSP1'] : $fixedNom['NCDefSP2'], "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0]; }
        $extraHours = (float)$_POST['hours'] - (float)$job['min_hours'];
        if ($extraHours > 0 && !empty($job['nom_code_variable'])) {
            $varNom = getNominalDetails($job['nom_code_variable'], $apiKey);
            if($varNom) { $lines[] = ["Type" => "N", "Code" => trim($varNom['NCCode']), "Description" => trim($varNom['NCDesc']) . " (Extra Hrs)", "UOMLevel" => 1, "Location" => "01", "Qty" => $extraHours, "Price" => $isInternal ? $varNom['NCDefSP1'] : $varNom['NCDefSP2'], "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0]; }
        }
    } 
    elseif ($job['pricing_type'] == 'per_trip' && !empty($job['nom_code_fixed'])) {
        $tripNom = getNominalDetails($job['nom_code_fixed'], $apiKey);
        if($tripNom) { $lines[] = ["Type" => "N", "Code" => trim($tripNom['NCCode']), "Description" => trim($tripNom['NCDesc']) . " ($jobRef)", "UOMLevel" => 1, "Location" => "01", "Qty" => (float)$job['qty_trips'] > 0 ? (float)$job['qty_trips'] : 1, "Price" => $isInternal ? $tripNom['NCDefSP1'] : $tripNom['NCDefSP2'], "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0]; }
    }
    else {
        $standardCode = !empty($job['nom_code_variable']) ? $job['nom_code_variable'] : '0000'; $standardNom = getNominalDetails($standardCode, $apiKey);
        $lines[] = ["Type" => "N", "Code" => $standardNom ? trim($standardNom['NCCode']) : $standardCode, "Description" => ($standardNom ? trim($standardNom['NCDesc']) : "Plant Operation: " . $job['plant_name']) . " ($jobRef)", "UOMLevel" => 1, "Location" => "01", "Qty" => (float)$_POST['hours'], "Price" => (float)$_POST['rate'], "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0];
    }

    $payload = [ "Type" => "IN", "PaymentType" => null, "Description" => "Generated by Booking Portal", "Amount" => null, "Change" => null, "NominalAccount" => null, "Transaction" => [ "InvioceHeader" => [ "THTranCode" => "IN", "THDate" => date('Y-m-d'), "THUserID" => "API", "THCSCode" => $job['client_code'], "THName" => $job['client_name'], "THTaxNumber" => "", "THTotValueTIF" => (string)($totalVal + $totalTax), "THRevision" => "001", "THTotDiscF" => 0.0, "THTotDiscTIF" => 0.0, "THTotTaxF" => $totalTax, "THCurrency" => "EUR", "THExchRate" => 1, "THPayment" => "", "THPayRef" => $jobRef ], "InvioceItemLine" => [ "Lines" => $lines ], "Ledger" => "S", "OfflineDocRefs" => "" ] ];

    $erpResult = postJ2ApiData('/saletransactions', $apiKey, $payload);
    if ($erpResult['code'] >= 200 && $erpResult['code'] < 300) { $sysRef = json_decode($erpResult['response'], true)['SysRef'] ?? 'SUCCESS_NO_REF'; $pdo->prepare("UPDATE plant_bookings SET invoice_sysref = ? WHERE id = ?")->execute([$sysRef, $bookingId]); echo "OK"; } else { echo "ERP_SYNC_FAILED: " . htmlspecialchars($erpResult['response']); } exit;
}

if ($action == 'get_ledger' && $canViewLedger) {
    echo json_encode($pdo->query("SELECT pb.*, p.name as plant_name, bc.name as billing_company_name, prj.name as project_name, c.name as client_dev_name FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id LEFT JOIN projects prj ON pb.project_id = prj.id LEFT JOIN clients c ON p.developer_client_id = c.id LEFT JOIN clients bc ON p.billing_company_id = bc.id ORDER BY pb.booking_date DESC")->fetchAll(PDO::FETCH_ASSOC)); exit;
}

if ($action == 'mark_settled' && $canViewLedger) { $pdo->prepare("UPDATE plant_bookings SET payment_status='Settled' WHERE id=?")->execute([$_POST['id']]); echo "OK"; exit; }

if ($action == 'get_nominals_for_job') {
    $stmt = $pdo->prepare("SELECT p.nom_code_fixed, p.nom_code_variable FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id WHERE pb.id = ?"); $stmt->execute([$_GET['booking_id']]); $job = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['fixed' => getNominalDetails($job['nom_code_fixed'], $apiKey), 'variable' => getNominalDetails($job['nom_code_variable'], $apiKey)]); exit;
}
?>
