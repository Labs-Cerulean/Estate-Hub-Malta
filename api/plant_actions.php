<?php
require_once '../config.php';
require_once '../session-check.php';
require_once '../user-functions.php';

function logPlantAction($pdo, $userId, $actionType, $details, $bookingId = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    try {
        $stmt = $pdo->prepare("INSERT INTO plant_audit_log (user_id, booking_id, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $bookingId, $actionType, $details, $ip]);
        $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN final_discount_pct DECIMAL(5,2) DEFAULT 0.00");
        $pdo->exec("CREATE TABLE IF NOT EXISTS plant_job_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        punch_in DATETIME NOT NULL,
        punch_out DATETIME NOT NULL,
        hours DECIMAL(10,2) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    } catch(PDOException $e) {
        // Silently fail so a logging error never stops a live billing transaction
    }
}

function pushBookingToERP($pdo, $bookingId, $userId) {
    $stmt = $pdo->prepare("
        SELECT pb.*, p.billing_company_id, p.pricing_type, p.nom_code_fixed, p.nom_code_variable, p.nom_code_setup, p.min_hours, 
               u.first_name as driver_first, u.last_name as driver_last,
               prj.name as project_name
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id 
        LEFT JOIN users u ON pb.driver_id = u.id 
        LEFT JOIN projects prj ON pb.project_id = prj.id
        WHERE pb.id = ?
    "); 
    $stmt->execute([$bookingId]); 
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->execute([$bookingId]); 
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($job['client_code']) || $job['client_code'] === 'TBC') {
        return "ERROR: Client details are still TBC.";
    }

    $apiKey = getApiKey($job['billing_company_id']);
    $setupNom = getNominalDetails($job['nom_code_setup'], $apiKey);
    $fixedNom = getNominalDetails($job['nom_code_fixed'], $apiKey);
    $varNom = getNominalDetails($job['nom_code_variable'], $apiKey);

    $lines = [];
    $grossSubtotal = 0;
    $discountPct = isset($job['final_discount_pct']) ? (float)$job['final_discount_pct'] : 0.00;
    
    if ((float)$job['final_setup_fee'] > 0) {
        $setupCode = $setupNom ? trim($setupNom['NCCode']) : (!empty($job['nom_code_setup']) ? $job['nom_code_setup'] : '0000');
        $setupDesc = $setupNom ? substr(trim($setupNom['NCDesc']), 0, 35) : "Setup / Mobilisation Fee";
        $price = round((float)$job['final_setup_fee'], 4);
        $qty = round(1, 2);
        $grossSubtotal += round($qty * $price, 2);
        
        $lines[] = [ "Type" => "N", "Code" => $setupCode, "Description" => $setupDesc, "UOMLevel" => 1, "Location" => "01", 
                     "Qty" => $qty, "Price" => $price, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => round($discountPct, 2), "DR" => 0, "CR" => 0 ];
    }
    
    if ($job['pricing_type'] == 'fixed_then_hourly') {
        $fCode = $fixedNom ? trim($fixedNom['NCCode']) : trim($job['nom_code_fixed']);
        $fDesc = $fixedNom ? substr(trim($fixedNom['NCDesc']), 0, 35) : "Fixed Callout Charge";
        $price = round((float)$job['final_rate_fixed'], 4);
        $qty = round(1, 2);
        $grossSubtotal += round($qty * $price, 2);
        
        $lines[] = [ "Type" => "N", "Code" => $fCode, "Description" => $fDesc, "UOMLevel" => 1, "Location" => "01", 
                     "Qty" => $qty, "Price" => $price, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => round($discountPct, 2), "DR" => 0, "CR" => 0 ]; 
        
        $extraHours = round((float)$job['final_hours'] - (float)$job['min_hours'], 2);
        if ($extraHours > 0) {
            $vCode = $varNom ? trim($varNom['NCCode']) : trim($job['nom_code_variable']);
            $vDesc = $varNom ? substr(trim($varNom['NCDesc']), 0, 35) : "Additional Hourly Rate";
            $vPrice = round((float)$job['final_rate_var'], 4);
            $grossSubtotal += round($extraHours * $vPrice, 2);
            
            $lines[] = [ "Type" => "N", "Code" => $vCode, "Description" => $vDesc, "UOMLevel" => 1, "Location" => "01", 
                         "Qty" => $extraHours, "Price" => $vPrice, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => round($discountPct, 2), "DR" => 0, "CR" => 0 ]; 
        }
    } elseif ($job['pricing_type'] == 'per_trip') {
        $tCode = $fixedNom ? trim($fixedNom['NCCode']) : trim($job['nom_code_fixed']);
        $tDesc = $fixedNom ? substr(trim($fixedNom['NCDesc']), 0, 35) : "Trip Execution Charge";
        $qty = round((float)$job['qty_trips'] > 0 ? (float)$job['qty_trips'] : 1, 2);
        $price = round((float)$job['final_rate_fixed'], 4);
        $grossSubtotal += round($qty * $price, 2);
        
        $lines[] = [ "Type" => "N", "Code" => $tCode, "Description" => $tDesc, "UOMLevel" => 1, "Location" => "01", 
                     "Qty" => $qty, "Price" => $price, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => round($discountPct, 2), "DR" => 0, "CR" => 0 ]; 
    } else {
        $hCode = $varNom ? trim($varNom['NCCode']) : (!empty($job['nom_code_variable']) ? trim($job['nom_code_variable']) : '0000'); 
        $hDesc = $varNom ? substr(trim($varNom['NCDesc']), 0, 35) : "Plant Operation";
        $qty = round((float)$job['final_hours'], 2);
        $price = round((float)$job['final_rate_var'], 4);
        $grossSubtotal += round($qty * $price, 2);
        
        $lines[] = [ "Type" => "N", "Code" => $hCode, "Description" => $hDesc, "UOMLevel" => 1, "Location" => "01", 
                     "Qty" => $qty, "Price" => $price, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => round($discountPct, 2), "DR" => 0, "CR" => 0 ];
    }

    $totalDiscount = round($grossSubtotal * ($discountPct / 100), 2);
    $netSubtotal = round($grossSubtotal - $totalDiscount, 2);
    $totalTax = round($netSubtotal * 0.18, 2);
    
    $jobRef = sprintf("PRA-%s-%04d", date('Y', strtotime($job['booking_date'])), $bookingId);
    $driverName = trim(($job['driver_first'] ?? 'Unassigned') . ' ' . ($job['driver_last'] ?? ''));
    
    $jobRef = sprintf("PRA-%s-%04d", date('Y', strtotime($job['booking_date'])), $bookingId);
    $driverName = trim(($job['driver_first'] ?? 'Unassigned') . ' ' . ($job['driver_last'] ?? ''));
    
    // --- FIX 2: DYNAMIC PRA/PRAX PREFIX ---
    $prefix = ($job['billing_company_id'] == '26') ? 'PRAX' : 'PRA';
    $jobRef = sprintf("%s-%s-%04d", $prefix, date('Y', strtotime($job['booking_date'])), $bookingId);
    
    // --- FIX 1: DYNAMIC LOCATION TEXT WITH REVERSE GEOCODING ---
    $locationText = ($job['booking_type'] == 'in-house') 
        ? "Project: " . ($job['project_name'] ?? 'N/A') 
        : "Client: " . ($job['client_name'] ?? 'N/A');

    // Text lines do not get discounts applied to them
    $lines[] = [ "Type" => "T", "Code" => "0000", "Description" => substr("Delivery Note: " . $jobRef, 0, 35), "Qty" => round(1, 2), "Location" => "01" ];
    $lines[] = [ "Type" => "T", "Code" => "0000", "Description" => substr($locationText, 0, 35), "Qty" => round(1, 2), "Location" => "01" ];
    
    // NEW: Add the physical street address as a dedicated line for External Jobs!
    if ($job['booking_type'] !== 'in-house' && !empty($job['location_lat']) && !empty($job['location_lng'])) {
        $address = getAddressFromCoordinates($job['location_lat'], $job['location_lng']);
        if ($address) {
            $lines[] = [ "Type" => "T", "Code" => "0000", "Description" => substr("Loc: " . $address, 0, 35), "Qty" => round(1, 2), "Location" => "01" ];
        }
    }

    $lines[] = [ "Type" => "T", "Code" => "0000", "Description" => substr("Driver: " . $driverName, 0, 35), "Qty" => round(1, 2), "Location" => "01" ];

    $payload = [ 
        "Type" => "IN", 
        "Transaction" => [ 
            "InvioceHeader" => [ 
                "THTranCode" => "IN", "THDate" => $job['booking_date'], "THUserID" => "API", 
                "THCSCode" => $job['client_code'], "THName" => $job['client_name'], "THTaxNumber" => "", 
                "THTotValueTIF" => (string)round($netSubtotal + $totalTax, 2), "THExtRef" => $jobRef, 
                "THRevision" => "001", "THTotDiscF" => $totalDiscount, "THTotDiscTIF" => $totalDiscount, "THTotTaxF" => $totalTax, 
                "THCurrency" => "EUR", "THExchRate" => 1, "THPayment" => "", "THPayRef" => "" 
            ], 
            "InvioceItemLine" => [ "Lines" => $lines ], "Ledger" => "S", "OfflineDocRefs" => "" 
        ] 
    ];

    $erpResult = postJ2ApiData('/sales/transaction', $apiKey, $payload);
    
    if ($erpResult['code'] >= 200 && $erpResult['code'] < 300) { 
        $sysRef = json_decode($erpResult['response'], true)['SysRef'] ?? 'SUCCESS_NO_REF'; 
        $pdo->prepare("UPDATE plant_bookings SET invoice_sysref = ? WHERE id = ?")->execute([$sysRef, $bookingId]); 
        logPlantAction($pdo, $userId, 'RFP_SYNCED_SUCCESS', "Synced invoice to ERP. SysRef: $sysRef", $bookingId);
        return "OK"; 
    } else { 
        $pdo->prepare("UPDATE plant_bookings SET invoice_sysref='N/A' WHERE id=?")->execute([$bookingId]);
        logPlantAction($pdo, $userId, 'RFP_SYNC_FAILED', "ERP Sync Failed. Response: " . htmlspecialchars($erpResult['response']), $bookingId);
        return "ERROR: " . htmlspecialchars($erpResult['response']); 
    } 
}

// Force Malta Timezone strictly for all operations in this file
date_default_timezone_set('Europe/Malta');

// Auto-deploy database updates for Setup Fee and Local Rate Overrides
try { 
    $pdo->exec("ALTER TABLE plants ADD COLUMN setup_fee DECIMAL(10,2) DEFAULT 0.00"); 
    $pdo->exec("ALTER TABLE plants ADD COLUMN nom_code_setup VARCHAR(50) DEFAULT NULL"); 
    $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN apply_setup_fee TINYINT(1) DEFAULT 0"); 
    $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN final_setup_fee DECIMAL(10,2) DEFAULT NULL"); 
    $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN final_rate_fixed DECIMAL(10,2) DEFAULT NULL"); 
    $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN final_rate_var DECIMAL(10,2) DEFAULT NULL"); 
} catch(PDOException $e) {}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

session_write_close(); // Prevent browser hanging

$isManager = in_array($role, ['admin', 'director', 'system_manager', 'plant_manager']);
$canManageFleet = in_array($role, ['admin', 'system_manager']) || hasPermission('manage_plant_fleet');
$canViewLedger = in_array($role, ['admin', 'director', 'system_manager', 'accountant']) || hasPermission('view_plant_ledger');

// Dynamic API Key Mapper
$praApiKey = getenv('J2_API_KEY_PRA');
$praxApiKey = getenv('J2_API_KEY_PRAX');

if (!$praApiKey || !$praxApiKey) {
    die(json_encode(['error' => 'Critical Error: ERP API keys are missing from environment configuration.']));
}

$apiKeys = [
    '24' => $praApiKey,  
    '26' => $praxApiKey, 
    'default' => $praApiKey
];

$apiUrlBase = 'https://j2api.agiusgroup.com/api/public';

function getApiKey($companyId) {
    global $apiKeys;
    return $apiKeys[$companyId] ?? $apiKeys['default'];
}

function getJ2ApiData($endpoint, $apiKey) {
    global $apiUrlBase; 
    $url = $apiUrlBase . $endpoint; 
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json", 
        "Accept: application/json", 
        "x-api-key: " . $apiKey, 
        "Authorization: Bearer " . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
    
    $response = curl_exec($ch); 
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    return [];
}

function postJ2ApiData($endpoint, $apiKey, $payload) {
    global $apiUrlBase; 
    $url = $apiUrlBase . $endpoint; 
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json", 
        "Accept: application/json", 
        "x-api-key: " . $apiKey, 
        "Authorization: Bearer " . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
    curl_setopt($ch, CURLOPT_POST, true); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload)); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
    
    $response = curl_exec($ch); 
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
    curl_close($ch);
    
    return ['code' => $httpCode, 'response' => $response];
}

// ==========================================
// Driver Overlap Validation Logic
// ==========================================
function isDriverAvailable($pdo, $driverId, $date, $startTime, $endTime, $excludeBookingId = null) {
    if (empty($driverId)) {
        return true; 
    }
    
    $sql = "SELECT id, start_time, end_time FROM plant_bookings 
            WHERE driver_id = ? AND booking_date = ? AND status != 'Cancelled'";
    $params = [$driverId, $date];
    
    if ($excludeBookingId) {
        $sql .= " AND id != ?";
        $params[] = $excludeBookingId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $newStart = strtotime($date . ' ' . $startTime);
    $newEnd = strtotime($date . ' ' . $endTime);
    
    if ($newEnd <= $newStart) {
        $newEnd += 86400; 
    }
    
    foreach ($existing as $b) {
        $exStart = strtotime($date . ' ' . $b['start_time']);
        $exEnd = strtotime($date . ' ' . $b['end_time']);
        
        if ($exEnd <= $exStart) {
            $exEnd += 86400;
        }
        
        if ($newStart < $exEnd && $newEnd > $exStart) {
            return false; 
        }
    }
    return true;
}

if ($action == 'get_nominals' && $canManageFleet) {
    $apiKey = getApiKey($_GET['company_id'] ?? '');
    $data = getJ2ApiData('/nominalcateg', $apiKey);
    echo json_encode($data ?: []); 
    exit;
}

if ($action == 'get_clients' && $canManageFleet) {
    $stmt = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); 
    exit;
}

if ($action == 'get_fleet' && $canManageFleet) {
    $query = "SELECT p.*, c.name as owner_name, bc.name as billing_company_name 
              FROM plants p 
              LEFT JOIN clients c ON p.developer_client_id = c.id 
              LEFT JOIN clients bc ON p.billing_company_id = bc.id 
              WHERE p.status = 'Active' 
              ORDER BY p.category, p.name ASC";
              
    $fleet = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($fleet); 
    exit;
}

if ($action == 'form_data') {
    $nominalCache = [];
    foreach (['24', '26'] as $compId) {
        $noms = getJ2ApiData('/nominalcateg', getApiKey($compId));
        $indexed = [];
        if (is_array($noms)) {
            foreach ($noms as $n) {
                if (isset($n['NCCode'])) {
                    $indexed[trim((string)$n['NCCode'])] = true;
                }
            }
        }
        $nominalCache[$compId] = $indexed;
    }

    $plantsRaw = $pdo->query("SELECT id, name, category, registration_plate, billing_company_id, pricing_type, nom_code_fixed, nom_code_variable, setup_fee, nom_code_setup, requires_driver, lifecycle_type, has_configurations, configurations, billing_unit FROM plants WHERE status='Active' ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);
    $plants = []; 
    
    foreach($plantsRaw as $p) { 
        $bcId = $p['billing_company_id'] ?? 'default';
        $catCache = $nominalCache[$bcId] ?? [];
        
        $isValidPricing = in_array($p['pricing_type'], ['fixed_then_hourly', 'per_trip', 'hourly']);
        $isFixedReq = in_array($p['pricing_type'], ['fixed_then_hourly', 'per_trip']);
        $isVarReq = in_array($p['pricing_type'], ['fixed_then_hourly', 'hourly']);
        
        $fCode = trim((string)$p['nom_code_fixed']);
        $vCode = trim((string)$p['nom_code_variable']);
        
        $erpIsOnline = !empty($catCache);
        
        if ($erpIsOnline) {
            $hasFixed = $fCode !== '' && isset($catCache[$fCode]);
            $hasVar = $vCode !== '' && isset($catCache[$vCode]);
        } else {
            $hasFixed = $fCode !== '';
            $hasVar = $vCode !== '';
        }
        
        $p['is_misconfigured'] = !$isValidPricing || ($isFixedReq && !$hasFixed) || ($isVarReq && !$hasVar);
        
        $cat = empty($p['category']) ? 'General' : $p['category']; 
        if(!isset($plants[$cat])) {
            $plants[$cat] = []; 
        }
        $plants[$cat][] = $p; 
    }
    
    $drivers = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role='plant_driver'")->fetchAll(PDO::FETCH_ASSOC);
    
    $projects = getAccessibleProjects($pdo, $userId);
    if (!empty($projects)) {
        $projectIds = array_column($projects, 'id');
        $in = str_repeat('?,', count($projectIds) - 1) . '?';
        
        $stmt = $pdo->prepare("SELECT id, city FROM projects WHERE id IN ($in)");
        $stmt->execute($projectIds);
        $cities = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        foreach ($projects as &$prj) {
            $city = $cities[$prj['id']] ?? '';
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
            $name = trim((string)($c['ClientName'] ?? '')); 
            $code = trim((string)($c['ClientCode'] ?? ''));
            $status = isset($c['CliStatus']) ? (int)$c['CliStatus'] : (isset($c['clistatus']) ? (int)$c['clistatus'] : 1);
            
            if (!empty($name)) { 
                $results[] = ['code' => $code, 'name' => $name, 'status' => $status]; 
            }
        }
    }
    echo json_encode($results); 
    exit;
}

if ($action == 'get_last_project_client') {
    $projectId = $_GET['project_id'] ?? '';
    $companyId = $_GET['company_id'] ?? '';
    
    if (empty($projectId)) {
        echo json_encode([]);
        exit;
    }
    
    // We now strictly match the billing company to prevent cross-company ERP code mixups!
    $query = "SELECT pb.client_code, pb.client_name 
              FROM plant_bookings pb 
              JOIN plants p ON pb.plant_id = p.id 
              WHERE pb.project_id = ? AND p.billing_company_id = ? 
              AND pb.client_code IS NOT NULL AND pb.client_code != '' 
              ORDER BY pb.id DESC LIMIT 1";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute([$projectId, $companyId]);
    
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    exit;
}

$canViewDashboard = false;
if (in_array($role, ['admin', 'director'])) {
    $canViewDashboard = true;
} elseif ($role === 'system_manager' && hasPermission('manage_plant_fleet')) {
    $canViewDashboard = true;
}

if ($action == 'get_dashboard_stats' && $canViewDashboard) {
    $startDate = !empty($_POST['start']) ? date('Y-m-d', strtotime($_POST['start'])) : date('Y-m-d', strtotime('-1 month'));
    $endDate = !empty($_POST['end']) ? date('Y-m-d', strtotime($_POST['end'])) : date('Y-m-d', strtotime('+1 month'));

    $stmt = $pdo->prepare("SELECT pb.*, p.category, p.name as plant_name, p.billing_company_id FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id WHERE pb.booking_date >= ? AND pb.booking_date <= ? ORDER BY pb.booking_date ASC");
    $stmt->execute([$startDate, $endDate]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $kpi = [ 'completed_bookings' => 0, 'planned_bookings' => 0, 'executed_hours' => 0, 'planned_hours' => 0 ];

    $kpi_pra = ['rev_gen' => 0, 'rev_pipe' => 0, 'rev_total' => 0, 'rfps' => 0, 'erp' => 0, 'fin_rev' => 0, 'fin_hrs' => 0, 'unfin_hrs' => 0, 'plan_hrs' => 0];
    $kpi_prax = ['rev_gen' => 0, 'rev_pipe' => 0, 'rev_total' => 0, 'rfps' => 0, 'erp' => 0, 'fin_rev' => 0, 'fin_hrs' => 0, 'unfin_hrs' => 0, 'plan_hrs' => 0];

    $plantsMap = [];

    foreach ($jobs as $j) {
        $cat = $j['category'] ?: 'General';
        $pName = $j['plant_name'];
        if (!isset($plantsMap[$cat])) $plantsMap[$cat] = [];
        if (!isset($plantsMap[$cat][$pName])) $plantsMap[$cat][$pName] = ['comp_jobs'=>0, 'comp_hrs'=>0, 'comp_rev'=>0, 'plan_jobs'=>0, 'plan_hrs'=>0, 'plan_rev'=>0];

        $sTime = strtotime($j['booking_date'].' '.$j['start_time']);
        $eTime = strtotime($j['booking_date'].' '.$j['end_time']);
        $schHours = ($eTime - $sTime) / 3600; if ($schHours < 0) $schHours += 24;

        $comp = $j['billing_company_id'];
        $compKey = ($comp == '24') ? 'pra' : (($comp == '26') ? 'prax' : null);

        if ($j['status'] === 'Completed') {
            $kpi['completed_bookings']++;
            $hrs = ((float)$j['final_hours'] > 0 ? (float)$j['final_hours'] : $schHours);
            $kpi['executed_hours'] += $hrs;
            
            $plantsMap[$cat][$pName]['comp_jobs']++;
            $plantsMap[$cat][$pName]['comp_hrs'] += $hrs;
            
            if ($compKey) {
                if ((float)$j['final_subtotal'] > 0) {
                    ${"kpi_".$compKey}['fin_rev'] += (float)$j['final_subtotal'];
                    ${"kpi_".$compKey}['fin_hrs'] += $hrs;
                    $plantsMap[$cat][$pName]['comp_rev'] += (float)$j['final_subtotal'];
                } else {
                    ${"kpi_".$compKey}['unfin_hrs'] += $hrs;
                    if(!isset($plantsMap[$cat][$pName]['unfin_hrs'])) $plantsMap[$cat][$pName]['unfin_hrs'] = 0;
                    $plantsMap[$cat][$pName]['unfin_hrs'] += $hrs;
                }
                if (in_array($j['payment_status'], ['Invoiced', 'Settled'])) { ${"kpi_".$compKey}['rfps']++; }
                if (!empty($j['invoice_sysref']) && !in_array($j['invoice_sysref'], ['N/A', 'SUCCESS_NO_REF'])) { ${"kpi_".$compKey}['erp'] += (float)$j['final_subtotal']; }
            }
        } elseif (in_array($j['status'], ['Pending', 'In Progress', 'Paused'])) {
            $kpi['planned_bookings']++;
            $kpi['planned_hours'] += $schHours;
            $plantsMap[$cat][$pName]['plan_jobs']++;
            $plantsMap[$cat][$pName]['plan_hrs'] += $schHours;
            if ($compKey) {
                ${"kpi_".$compKey}['plan_hrs'] += $schHours;
            }
        }
    }

    $avgYieldPra = $kpi_pra['fin_hrs'] > 0 ? ($kpi_pra['fin_rev'] / $kpi_pra['fin_hrs']) : 65.00; 
    $kpi_pra['rev_gen'] = $kpi_pra['fin_rev'] + ($kpi_pra['unfin_hrs'] * $avgYieldPra);
    $kpi_pra['rev_pipe'] = $kpi_pra['plan_hrs'] * $avgYieldPra;
    $kpi_pra['rev_total'] = $kpi_pra['rev_gen'] + $kpi_pra['rev_pipe'];

    $avgYieldPrax = $kpi_prax['fin_hrs'] > 0 ? ($kpi_prax['fin_rev'] / $kpi_prax['fin_hrs']) : 65.00; 
    $kpi_prax['rev_gen'] = $kpi_prax['fin_rev'] + ($kpi_prax['unfin_hrs'] * $avgYieldPrax);
    $kpi_prax['rev_pipe'] = $kpi_prax['plan_hrs'] * $avgYieldPrax;
    $kpi_prax['rev_total'] = $kpi_prax['rev_gen'] + $kpi_prax['rev_pipe'];

    $avgYieldGlobal = ($kpi_pra['fin_hrs'] + $kpi_prax['fin_hrs']) > 0 ? (($kpi_pra['fin_rev'] + $kpi_prax['fin_rev']) / ($kpi_pra['fin_hrs'] + $kpi_prax['fin_hrs'])) : 65.00;

    $drilldown = [ 'completed_book' => [], 'completed_hrs' => [], 'rev_gen_pra' => [], 'rev_gen_prax' => [], 'planned_book' => [], 'planned_hrs' => [], 'rev_pipe_pra' => [], 'rev_pipe_prax' => [], 'rfps_pra' => [], 'rfps_prax' => [], 'erp_pra' => [], 'erp_prax' => [], 'rev_total_pra' => [], 'rev_total_prax' => [] ];

    foreach ($jobs as $j) {
        $client = !empty($j['client_name']) ? $j['client_name'] : 'TBC / Unknown';
        $comp = $j['billing_company_id'];
        $compKey = ($comp == '24') ? 'pra' : (($comp == '26') ? 'prax' : 'other');
        $tag = ($compKey === 'pra') ? '<span style="color:#3b82f6;">[PRA]</span> ' : (($compKey === 'prax') ? '<span style="color:#f59e0b;">[PRAX]</span> ' : '');
        $desc = "<b>" . $tag . $j['plant_name'] . "</b><br><span style='color:#64748b; font-size:0.8rem;'>" . $client . "</span>";
        $date = date('d M', strtotime($j['booking_date']));
        $sTime = strtotime($j['booking_date'].' '.$j['start_time']); $eTime = strtotime($j['booking_date'].' '.$j['end_time']);
        $schHours = ($eTime - $sTime) / 3600; if ($schHours < 0) $schHours += 24;

        if ($j['status'] === 'Completed') {
            $hrs = ((float)$j['final_hours'] > 0 ? (float)$j['final_hours'] : $schHours);
            $yieldToUse = ($compKey === 'pra') ? $avgYieldPra : (($compKey === 'prax') ? $avgYieldPrax : $avgYieldGlobal);
            $rev = round((float)$j['final_subtotal'] > 0 ? (float)$j['final_subtotal'] : ($hrs * $yieldToUse));
            
            $drilldown['completed_book'][] = ['date' => $date, 'desc' => $desc, 'val' => "1 Job"];
            $drilldown['completed_hrs'][] = ['date' => $date, 'desc' => $desc, 'val' => number_format($hrs, 1) . " Hrs"];
            
            if ($compKey !== 'other') {
                $drilldown['rev_gen_'.$compKey][] = ['date' => $date, 'desc' => $desc, 'val' => "€" . number_format($rev, 0) . ((float)$j['final_subtotal'] <= 0 ? ' <i>(Est)</i>' : '')];
                $drilldown['rev_total_'.$compKey][] = ['date' => $date, 'desc' => $desc, 'val' => "€" . number_format($rev, 0)];
                
                if (in_array($j['payment_status'], ['Invoiced', 'Settled'])) {
                    $drilldown['rfps_'.$compKey][] = ['date' => $date, 'desc' => $desc, 'val' => "Finalized"];
                }
                if (!empty($j['invoice_sysref']) && !in_array($j['invoice_sysref'], ['N/A', 'SUCCESS_NO_REF'])) {
                    $drilldown['erp_'.$compKey][] = ['date' => $date, 'desc' => $desc, 'val' => "€" . number_format(round((float)$j['final_subtotal']), 0)];
                }
            }
        } elseif (in_array($j['status'], ['Pending', 'In Progress', 'Paused'])) {
            $yieldToUse = ($compKey === 'pra') ? $avgYieldPra : (($compKey === 'prax') ? $avgYieldPrax : $avgYieldGlobal);
            $rev = round($schHours * $yieldToUse);
            $drilldown['planned_book'][] = ['date' => $date, 'desc' => $desc, 'val' => "1 Job"];
            $drilldown['planned_hrs'][] = ['date' => $date, 'desc' => $desc, 'val' => number_format($schHours, 1) . " Hrs"];
            
            if ($compKey !== 'other') {
                $drilldown['rev_pipe_'.$compKey][] = ['date' => $date, 'desc' => $desc, 'val' => "€" . number_format($rev, 0) . " <i>(Est)</i>"];
                $drilldown['rev_total_'.$compKey][] = ['date' => $date, 'desc' => $desc, 'val' => "€" . number_format($rev, 0) . " <i>(Est)</i>"];
            }
        }
    }

    $flatPlants = [];
    ksort($plantsMap);
    foreach ($plantsMap as $cat => $plants) {
        ksort($plants);
        foreach ($plants as $name => $d) {
            $c_rev = $d['comp_rev'] + (($d['unfin_hrs'] ?? 0) * $avgYieldGlobal);
            $p_rev = $d['plan_hrs'] * $avgYieldGlobal;
            if ($d['comp_jobs'] > 0 || $d['plan_jobs'] > 0) {
                $flatPlants[] = [ 'category' => $cat, 'plant_name' => $name, 'c_qty' => $d['comp_jobs'], 'c_hrs' => $d['comp_hrs'], 'c_rev' => $c_rev, 'p_qty' => $d['plan_jobs'], 'p_hrs' => $d['plan_hrs'], 'p_rev' => $p_rev ];
            }
        }
    }

    echo json_encode(['kpi' => $kpi, 'kpi_pra' => $kpi_pra, 'kpi_prax' => $kpi_prax, 'plants' => $flatPlants, 'drilldown' => $drilldown]);
    exit;
}

if ($action == 'fetch_bookings') {
    $events = [];
    $startDate = !empty($_GET['start']) ? date('Y-m-d', strtotime($_GET['start'])) : date('Y-m-d', strtotime('-1 month'));
    $endDate = !empty($_GET['end']) ? date('Y-m-d', strtotime($_GET['end'])) : date('Y-m-d', strtotime('+1 month'));
    
    // GLOBAL VISIBILITY: All users pull the full schedule without driver restrictions
    $query = "SELECT pb.*, p.name as plant_name, p.category, prj.name as project_name, prj.city as locality 
              FROM plant_bookings pb 
              JOIN plants p ON pb.plant_id = p.id 
              LEFT JOIN projects prj ON pb.project_id = prj.id
              WHERE pb.booking_date >= ? AND pb.booking_date <= ?";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute([$startDate, $endDate]);
    
    $catColors = [
        'Cranes' => '#eab308', 'Pumps' => '#3b82f6', 'Booms' => '#f97316', 
        'Excavator' => '#ef4444', 'Piling' => '#8b5cf6', 'Drum Cutter' => '#14b8a6', 
        'Rock Saw' => '#10b981', 'Other Trucks' => '#64748b', 'Scarifier' => '#ec4899', 'General' => '#6366f1'
    ];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $cat = $b['category'] ?: 'General';
        $color = $catColors[$cat] ?? '#6366f1';
        
        $details = [];
        if (!empty($b['project_name'])) {
            $details[] = $b['project_name'];
            if (!empty($b['locality'])) {
                $details[] = $b['locality'];
            }
            if (!empty($b['client_name'])) {
                $details[] = $b['client_name'];
            }
        } else {
            if (!empty($b['client_name'])) {
                $details[] = $b['client_name'];
            }
            if (!empty($b['comments'])) {
                $details[] = '"' . substr($b['comments'], 0, 40) . '..."';
            }
        }
        
        $statusInd = "";
        if ($b['status'] == 'Completed') {
            // Check if it has a saved RFP subtotal or an invoice status
            if ((float)$b['final_subtotal'] > 0 || in_array($b['payment_status'], ['Invoiced', 'Settled'])) {
                $statusInd = "🧾 "; // Has RFP
            } else {
                $statusInd = "✅ "; // Completed but missing RFP
            }
        } elseif ($b['status'] == 'In Progress') {
            $statusInd = "⏳ ";
        } elseif ($b['status'] == 'Paused') {
            $statusInd = "⏸️ ";
        }
        
        $title = $statusInd . $b['plant_name'];
        
        if (isset($b['apply_setup_fee']) && $b['apply_setup_fee'] == 1) {
            $title .= " [SETUP FEE]";
        }
        
        if (!empty($details)) {
            $title .= "\n" . implode(" | ", $details);
        }

        $sTime = !empty($b['start_time']) ? $b['start_time'] : '08:00:00';
        $eTime = !empty($b['end_time']) ? $b['end_time'] : '17:00:00';
        $startIso = $b['booking_date'] . 'T' . $sTime;
        
        // Retained your exact logic here:
        if (in_array($b['status'], ['In Progress', 'Paused']) && $cat === 'Excavator') {
            $endIso = date('Y-m-d') . 'T' . $eTime; 
        } else {
            if (strtotime($eTime) < strtotime($sTime)) {
                $endIso = date('Y-m-d', strtotime($b['booking_date'] . ' +1 day')) . 'T' . $eTime;
            } else {
                $endIso = $b['booking_date'] . 'T' . $eTime;
            }
        }

        // NEW: Grab the actual execution times and final value to pass to the UI
        $actualTimeStr = '';
        if ($b['status'] == 'Completed') {
            if (!empty($b['punch_in_time']) && !empty($b['punch_out_time'])) {
                $actualTimeStr = date('H:i', strtotime($b['punch_in_time'])) . ' - ' . date('H:i', strtotime($b['punch_out_time']));
            } else {
                $actualTimeStr = date('H:i', strtotime($b['start_time'])) . ' - ' . date('H:i', strtotime($b['end_time']));
            }
        }
        
        $subtotal = (float)$b['final_subtotal'];

        $events[] = [
            'id' => $b['id'], 
            'title' => $title, 
            'start' => $startIso, 
            'end' => $endIso, 
            'backgroundColor' => $color, 
            'borderColor' => $color,
            'extendedProps' => [
                'actualTime' => $actualTimeStr,
                'finalValue' => $subtotal
            ]
        ];
    }
    echo json_encode($events); 
    exit;
}

if ($action == 'save_plant' && $canManageFleet) {
    $pricingType = $_POST['pricing_type'];
    $minHours = ($pricingType === 'fixed_then_hourly') ? max(1, (float)$_POST['min_hours']) : 0;
    
    $nomFixed = !empty($_POST['nom_code_fixed']) ? $_POST['nom_code_fixed'] : null;
    $nomVar = !empty($_POST['nom_code_variable']) ? $_POST['nom_code_variable'] : null;

    if ($pricingType === 'hourly') { 
        if (empty($nomVar) && !empty($nomFixed)) { 
            $nomVar = $nomFixed; 
        } 
        $nomFixed = null; 
    } elseif ($pricingType === 'per_trip' || $pricingType === 'daily') { 
        $nomVar = null; 
    }

    $setupFee = empty($_POST['setup_fee']) ? 0.00 : (float)$_POST['setup_fee'];
    $nomSetup = empty($_POST['nom_code_setup']) ? null : $_POST['nom_code_setup'];

    // --- NEW CAPABILITY FIELDS ---
    $reqDriver = isset($_POST['requires_driver']) ? (int)$_POST['requires_driver'] : 1;
    $lifecycle = !empty($_POST['lifecycle_type']) ? $_POST['lifecycle_type'] : 'Standard';
    $hasConfigs = isset($_POST['has_configurations']) ? (int)$_POST['has_configurations'] : 0;
    $configsJson = !empty($_POST['configurations']) ? $_POST['configurations'] : null;
    $billingUnit = !empty($_POST['billing_unit']) ? $_POST['billing_unit'] : 'Hourly';

    $stmt = $pdo->prepare("INSERT INTO plants (category, name, registration_plate, developer_client_id, inhouse_rate, external_rate, pricing_type, min_hours, nom_code_fixed, nom_code_variable, setup_fee, nom_code_setup, billing_company_id, requires_driver, lifecycle_type, has_configurations, configurations, billing_unit) VALUES (?, ?, ?, ?, 0.00, 0.00, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->execute([
        $_POST['category'], $_POST['name'], empty($_POST['reg']) ? null : $_POST['reg'], 
        $_POST['billing_company_id'], $pricingType, $minHours, $nomFixed, $nomVar, 
        $setupFee, $nomSetup, $_POST['billing_company_id'],
        $reqDriver, $lifecycle, $hasConfigs, $configsJson, $billingUnit
    ]);
    
    logPlantAction($pdo, $userId, 'PLANT_ADDED', "Added new machinery to fleet: " . $_POST['name']);
    echo "OK"; 
    exit;
}

if ($action == 'update_plant' && $canManageFleet) {
    $pricingType = $_POST['pricing_type'];
    $minHours = ($pricingType === 'fixed_then_hourly') ? max(1, (float)$_POST['min_hours']) : 0;
    
    $nomFixed = !empty($_POST['nom_code_fixed']) ? $_POST['nom_code_fixed'] : null;
    $nomVar = !empty($_POST['nom_code_variable']) ? $_POST['nom_code_variable'] : null;

    if ($pricingType === 'hourly') { 
        if (empty($nomVar) && !empty($nomFixed)) { 
            $nomVar = $nomFixed; 
        } 
        $nomFixed = null; 
    } elseif ($pricingType === 'per_trip' || $pricingType === 'daily') { 
        $nomVar = null; 
    }

    $setupFee = empty($_POST['setup_fee']) ? 0.00 : (float)$_POST['setup_fee'];
    $nomSetup = empty($_POST['nom_code_setup']) ? null : $_POST['nom_code_setup'];

    // --- NEW CAPABILITY FIELDS ---
    $reqDriver = isset($_POST['requires_driver']) ? (int)$_POST['requires_driver'] : 1;
    $lifecycle = !empty($_POST['lifecycle_type']) ? $_POST['lifecycle_type'] : 'Standard';
    $hasConfigs = isset($_POST['has_configurations']) ? (int)$_POST['has_configurations'] : 0;
    $configsJson = !empty($_POST['configurations']) ? $_POST['configurations'] : null;
    $billingUnit = !empty($_POST['billing_unit']) ? $_POST['billing_unit'] : 'Hourly';

    $stmt = $pdo->prepare("UPDATE plants SET category=?, name=?, registration_plate=?, developer_client_id=?, inhouse_rate=0.00, external_rate=0.00, pricing_type=?, min_hours=?, nom_code_fixed=?, nom_code_variable=?, setup_fee=?, nom_code_setup=?, billing_company_id=?, requires_driver=?, lifecycle_type=?, has_configurations=?, configurations=?, billing_unit=? WHERE id=?");
    
    $stmt->execute([
        $_POST['category'], $_POST['name'], empty($_POST['reg']) ? null : $_POST['reg'], 
        $_POST['billing_company_id'], $pricingType, $minHours, $nomFixed, $nomVar, 
        $setupFee, $nomSetup, $_POST['billing_company_id'],
        $reqDriver, $lifecycle, $hasConfigs, $configsJson, $billingUnit, 
        $_POST['edit_plant_id']
    ]);
    
    logPlantAction($pdo, $userId, 'PLANT_UPDATED', "Updated fleet details for Plant ID: " . $_POST['edit_plant_id']);
    echo "OK"; 
    exit;
}

if ($action == 'get_drivers' && $canManageFleet) {
    $stmt = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'plant_driver' AND is_active = 'Yes' ORDER BY first_name ASC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); 
    exit;
}

if ($action == 'create_booking' && $isManager) {
    $driverId = empty($_POST['driver_id']) ? null : $_POST['driver_id'];
    $applySetupFee = isset($_POST['apply_setup_fee']) && $_POST['apply_setup_fee'] == '1' ? 1 : 0;
    
    if (!isDriverAvailable($pdo, $driverId, $_POST['booking_date'], $_POST['start_time'], $_POST['end_time'])) {
        echo "ERROR_OVERLAP"; 
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO plant_bookings (plant_id, driver_id, booking_type, project_id, client_name, client_code, location_lat, location_lng, booking_date, start_time, end_time, comments, apply_setup_fee, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([ 
        $_POST['plant_id'], 
        $driverId, 
        $_POST['booking_type'], 
        empty($_POST['project_id']) ? null : $_POST['project_id'], 
        $_POST['client_name'], 
        empty($_POST['client_code']) ? null : $_POST['client_code'], 
        empty($_POST['loc_lat']) ? null : $_POST['loc_lat'], 
        empty($_POST['loc_lng']) ? null : $_POST['loc_lng'], 
        $_POST['booking_date'], 
        $_POST['start_time'], 
        $_POST['end_time'], 
        $_POST['comments'], 
        $applySetupFee,
        $userId 
    ]); 
    logPlantAction($pdo, $userId, 'BOOKING_CREATED', "Created new booking for Plant ID: " . $_POST['plant_id'], $pdo->lastInsertId());
    echo "OK"; 
    exit;
}

if ($action == 'update_booking' && $isManager) {
    $driverId = empty($_POST['driver_id']) ? null : $_POST['driver_id'];
    $editId = $_POST['edit_id'];
    
    $checkStmt = $pdo->prepare("SELECT status, apply_setup_fee FROM plant_bookings WHERE id = ?");
    $checkStmt->execute([$editId]);
    $existingJob = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingJob) {
        if ($existingJob['status'] === 'Completed') {
            echo "ERROR: Completed jobs cannot be edited here. Admins must use the View RFP screen to modify completed jobs.";
            exit;
        }
    }
    
    $applySetupFee = isset($_POST['apply_setup_fee']) ? ($_POST['apply_setup_fee'] == '1' ? 1 : 0) : ($existingJob['apply_setup_fee'] ?? 0);
    
    if (!isDriverAvailable($pdo, $driverId, $_POST['booking_date'], $_POST['start_time'], $_POST['end_time'], $editId)) {
        echo "ERROR_OVERLAP"; 
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE plant_bookings 
        SET plant_id=?, driver_id=?, booking_type=?, project_id=?, client_name=?, client_code=?, location_lat=?, location_lng=?, booking_date=?, start_time=?, end_time=?, comments=?, apply_setup_fee=? 
        WHERE id=?
    ");
    
    $stmt->execute([ 
        $_POST['plant_id'], 
        $driverId, 
        $_POST['booking_type'], 
        empty($_POST['project_id']) ? null : $_POST['project_id'], 
        $_POST['client_name'], 
        empty($_POST['client_code']) ? null : $_POST['client_code'], 
        empty($_POST['loc_lat']) ? null : $_POST['loc_lat'], 
        empty($_POST['loc_lng']) ? null : $_POST['loc_lng'], 
        $_POST['booking_date'], 
        $_POST['start_time'], 
        $_POST['end_time'], 
        $_POST['comments'], 
        $applySetupFee,
        $editId 
    ]); 
    logPlantAction($pdo, $userId, 'BOOKING_UPDATED', "Updated booking details", $editId);
    echo "OK"; 
    exit;
}

if ($action == 'cancel_booking' && $isManager) { 
    $stmt = $pdo->prepare("DELETE FROM plant_bookings WHERE id=?");
    $stmt->execute([$_POST['id']]); 
    logPlantAction($pdo, $userId, 'BOOKING_CANCELLED', "Cancelled booking", $_POST['id']);
    echo "OK"; 
    exit; 
}

if ($action == 'get_job') {
    $stmt = $pdo->prepare("
        SELECT pb.*, p.name as plant_name, p.category, p.pricing_type, p.setup_fee, 
               prj.name as project_name, u.first_name as driver_first, u.last_name as driver_last 
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id 
        LEFT JOIN projects prj ON pb.project_id = prj.id 
        LEFT JOIN users u ON pb.driver_id = u.id 
        WHERE pb.id = ?
    "); 
    
    $stmt->execute([$_GET['id']]); 
    $job = $stmt->fetch(PDO::FETCH_ASSOC); 
    
    $job['location_text'] = $job['booking_type'] == 'in-house' ? "Project: " . $job['project_name'] : "External: " . $job['client_name']; 
    
    echo json_encode($job); 
    exit;
}

if ($action == 'claim_job') {
    $bookingId = $_POST['id'];
    
    $stmt = $pdo->prepare("SELECT booking_date, start_time, end_time FROM plant_bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!isDriverAvailable($pdo, $userId, $b['booking_date'], $b['start_time'], $b['end_time'])) {
        echo "ERROR_OVERLAP"; 
        exit;
    }
    
    $stmtUpdate = $pdo->prepare("UPDATE plant_bookings SET driver_id = ? WHERE id = ?");
    $stmtUpdate->execute([$userId, $bookingId]);
    logPlantAction($pdo, $userId, 'JOB_CLAIMED', "Driver self-assigned to job", $bookingId);
    echo "OK"; 
    exit;
}

// ---------------------------------------------------------
// SECURE PUNCH-IN/OUT (FORCING MALTA TIMEZONE)
// ---------------------------------------------------------
if ($action == 'punch_in') { 
    $punchTime = date('Y-m-d H:i:s');
    $bookingId = $_GET['id'] ?? $_POST['id'];
    
    $lat = $_POST['lat'] ?? null;
    $lng = $_POST['lng'] ?? null;

    // If the driver's phone successfully sent GPS coordinates, update the map location!
    if ($lat && $lng) {
        $stmt = $pdo->prepare("UPDATE plant_bookings SET status='In Progress', punch_in_time=?, driver_id=COALESCE(driver_id, ?), location_lat=?, location_lng=? WHERE id=?");
        $stmt->execute([$punchTime, $userId, $lat, $lng, $bookingId]); 
        logPlantAction($pdo, $userId, 'JOB_STARTED', "Driver punched in and logged live GPS coordinates.", $bookingId);
    } else {
        // Fallback for managers or if the driver's phone lost GPS signal
        $stmt = $pdo->prepare("UPDATE plant_bookings SET status='In Progress', punch_in_time=?, driver_id=COALESCE(driver_id, ?) WHERE id=?");
        $stmt->execute([$punchTime, $userId, $bookingId]); 
        logPlantAction($pdo, $userId, 'JOB_STARTED', "Driver punched in / resumed job (No GPS provided)", $bookingId);
    }
    
    echo "OK"; 
    exit; 
}

if ($action == 'pause_job') {
    $punchOut = date('Y-m-d H:i:s');
    $bookingId = $_POST['id'];

    $stmt = $pdo->prepare("SELECT punch_in_time FROM plant_bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    // Save the daily session
    if (!empty($job['punch_in_time'])) {
        $inTime = new DateTime($job['punch_in_time']);
        $outTime = new DateTime($punchOut);
        $interval = $inTime->diff($outTime);
        $hours = round($interval->h + ($interval->i / 60), 2);

        $pdo->prepare("INSERT INTO plant_job_sessions (booking_id, punch_in, punch_out, hours) VALUES (?, ?, ?, ?)")->execute([$bookingId, $job['punch_in_time'], $punchOut, $hours]);
    }

    $pdo->prepare("UPDATE plant_bookings SET status='Paused', punch_in_time=NULL WHERE id=?")->execute([$bookingId]);
    logPlantAction($pdo, $userId, 'JOB_PAUSED', "Driver paused the excavator job for the day", $bookingId);
    echo "OK";
    exit;
}

if ($action == 'punch_out_complete') {
    $punchTime = date('Y-m-d H:i:s');
    $bookingId = $_POST['id'];
    
    // Log the final session
    $stmt = $pdo->prepare("SELECT punch_in_time FROM plant_bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($job['punch_in_time'])) {
        $inTime = new DateTime($job['punch_in_time']);
        $outTime = new DateTime($punchTime);
        $interval = $inTime->diff($outTime);
        $hours = round($interval->h + ($interval->i / 60), 2);

        $pdo->prepare("INSERT INTO plant_job_sessions (booking_id, punch_in, punch_out, hours) VALUES (?, ?, ?, ?)")->execute([$bookingId, $job['punch_in_time'], $punchTime, $hours]);
    }

    $stmt = $pdo->prepare("
        UPDATE plant_bookings 
        SET status='Completed', punch_out_time=?, qty_trips=?, client_rep_name=?, client_rep_id_card=?, signature_data=?, punch_in_time=NULL 
        WHERE id=?
    ");
    
    $stmt->execute([
        $punchTime,
        empty($_POST['qty_trips']) ? null : $_POST['qty_trips'], 
        $_POST['rep_name'], 
        $_POST['rep_id'], 
        $_POST['signature'], 
        $bookingId
    ]);
    
    logPlantAction($pdo, $userId, 'JOB_COMPLETED', "Driver completed job and submitted client signature", $bookingId);
    echo "OK"; 
    exit;
}

function getNominalDetails($nomCode, $apiKey) {
    if(empty($nomCode)) {
        return null; 
    }
    
    $nominals = getJ2ApiData('/nominalcateg', $apiKey);
    
    foreach($nominals as $n) { 
        if(trim($n['NCCode']) == $nomCode) {
            return $n; 
        }
    } 
    return null;
}

if ($action == 'finalize_and_invoice' && $canViewLedger) {
    $bookingId = $_POST['booking_id'];
    $finalHours = empty($_POST['hours']) ? 0 : (float)$_POST['hours'];

    $stmt = $pdo->prepare("SELECT pb.*, p.billing_company_id, p.pricing_type, p.nom_code_fixed, p.nom_code_variable, p.min_hours, p.setup_fee, p.nom_code_setup FROM plant_bookings pb JOIN plants p ON pb.plant_id = p.id WHERE pb.id = ?"); 
    $stmt->execute([$bookingId]); 
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($job['client_code'] === 'TBC' || empty($job['client_code'])) {
        echo "ERROR: Client details are marked as TBC. You must assign a valid ERP Client before finalising the delivery note."; exit;
    }

    $apiKey = getApiKey($job['billing_company_id']);
    
    $punchIn = null; $punchOut = null;
    if (!empty($_POST['time_in']) && !empty($_POST['time_out'])) {
        $tInTime = strtotime($_POST['time_in']); $tOutTime = strtotime($_POST['time_out']);
        $outDate = $job['booking_date'];
        if ($tOutTime < $tInTime) { $outDate = date('Y-m-d', strtotime($job['booking_date'] . ' +1 day')); }
        $punchIn = $job['booking_date'] . ' ' . $_POST['time_in'] . ':00';
        $punchOut = $outDate . ' ' . $_POST['time_out'] . ':00';
    }
    if ($punchIn && $punchOut) {
        $pdo->prepare("UPDATE plant_bookings SET punch_in_time=?, punch_out_time=? WHERE id=?")->execute([$punchIn, $punchOut, $bookingId]);
    }

    $customRateFixed = isset($_POST['rate_fixed']) ? (float)$_POST['rate_fixed'] : null;
    $customRateVar = isset($_POST['rate_var']) ? (float)$_POST['rate_var'] : null;
    $customSetupFee = isset($_POST['setup_fee']) ? (float)$_POST['setup_fee'] : null;
    $customDiscountPct = isset($_POST['discount_pct']) ? (float)$_POST['discount_pct'] : 0.00;

    $isInternal = $job['booking_type'] == 'in-house'; 
    $fixedNom = getNominalDetails($job['nom_code_fixed'], $apiKey);
    $varNom = getNominalDetails($job['nom_code_variable'], $apiKey);
    
    $syncPriceFixed = $customRateFixed !== null ? $customRateFixed : ($fixedNom ? ($isInternal ? $fixedNom['NCDefSP1'] : $fixedNom['NCDefSP2']) : 0);
    $syncPriceVar = $customRateVar !== null ? $customRateVar : ($varNom ? ($isInternal ? $varNom['NCDefSP1'] : $varNom['NCDefSP2']) : 0);
    
    $syncSetupPrice = 0;
    if ((isset($job['apply_setup_fee']) && $job['apply_setup_fee'] == 1) || $customSetupFee > 0) {
        $syncSetupPrice = $customSetupFee !== null ? $customSetupFee : (float)$job['setup_fee'];
    }

    // Backend calculation for local database saving
    $backendSubtotal = round($syncSetupPrice, 2);
    if ($job['pricing_type'] == 'fixed_then_hourly') {
        $backendSubtotal += round($syncPriceFixed, 2);
        $extraHours = round($finalHours - (float)$job['min_hours'], 2);
        if ($extraHours > 0) $backendSubtotal += round($extraHours * $syncPriceVar, 2);
    } elseif ($job['pricing_type'] == 'per_trip') {
        $qty = round((float)$job['qty_trips'] > 0 ? (float)$job['qty_trips'] : 1, 2);
        $backendSubtotal += round($qty * $syncPriceFixed, 2);
    } else {
        $backendSubtotal += round(round($finalHours, 2) * $syncPriceVar, 2);
    }

    $stmtLocal = $pdo->prepare("UPDATE plant_bookings SET final_hours=?, final_subtotal=?, final_rate_fixed=?, final_rate_var=?, final_setup_fee=?, final_discount_pct=?, payment_status='Invoiced' WHERE id=?");
    $stmtLocal->execute([$finalHours, $backendSubtotal, $syncPriceFixed, $syncPriceVar, $syncSetupPrice, $customDiscountPct, $bookingId]);
    
    $erpResult = pushBookingToERP($pdo, $bookingId, $userId);
    
    if ($erpResult === "OK") {
        echo "OK"; 
    } else {
        echo "OK_LOCAL_ONLY"; 
    }
    exit;
}

if ($action == 'retry_erp_sync' && $canViewLedger) {
    $erpResult = pushBookingToERP($pdo, $_POST['booking_id'], $userId);
    if ($erpResult === "OK") {
        echo "OK";
    } else {
        echo $erpResult; 
    }
    exit;
}

if ($action == 'get_ledger' && $canViewLedger) {
    $query = "
        SELECT pb.*, p.name as plant_name, bc.name as billing_company_name, prj.name as project_name, c.name as client_dev_name 
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id 
        LEFT JOIN projects prj ON pb.project_id = prj.id 
        LEFT JOIN clients c ON p.developer_client_id = c.id 
        LEFT JOIN clients bc ON p.billing_company_id = bc.id 
        WHERE 1=1
    ";
    
    $params = [];

    if (!empty($_GET['start'])) { 
        $query .= " AND pb.booking_date >= ?"; 
        $params[] = $_GET['start']; 
    }
    if (!empty($_GET['end'])) { 
        $query .= " AND pb.booking_date <= ?"; 
        $params[] = $_GET['end']; 
    }
    if (!empty($_GET['plant_type'])) { 
        $query .= " AND p.category = ?"; 
        $params[] = $_GET['plant_type']; 
    }
    if (!empty($_GET['status'])) { 
        $query .= " AND pb.status = ?"; 
        $params[] = $_GET['status']; 
    }
    if (!empty($_GET['payment_status'])) { 
        $query .= " AND pb.payment_status = ?"; 
        $params[] = $_GET['payment_status']; 
    }
    if (!empty($_GET['client'])) { 
        $query .= " AND pb.client_name LIKE ?"; 
        $params[] = '%' . $_GET['client'] . '%'; 
    }
    if (!empty($_GET['project'])) { 
        $query .= " AND pb.project_id = ?"; 
        $params[] = $_GET['project']; 
    }
    if (!empty($_GET['company'])) { 
        $query .= " AND p.billing_company_id = ?"; 
        $params[] = $_GET['company']; 
    }

    $query .= " ORDER BY pb.booking_date DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); 
    exit;
}

if ($action == 'mark_settled' && $canViewLedger) { 
    $stmt = $pdo->prepare("UPDATE plant_bookings SET payment_status='Settled' WHERE id=?");
    $stmt->execute([$_POST['id']]);
    logPlantAction($pdo, $userId, 'PAYMENT_SETTLED', "Ledger marked as Settled", $_POST['id']);
    echo "OK"; 
    exit; 
}

if ($action == 'get_project_location' && $isManager) { 
    // Added 'city as address' to satisfy the Javascript requirement
    $stmt = $pdo->prepare("SELECT latitude, longitude, city as address FROM projects WHERE id = ?"); 
    $stmt->execute([$_GET['project_id']]); 
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC)); 
    exit; 
}

if ($action == 'update_job_client' && $isManager) {
    $bookingId = $_POST['booking_id'];
    $clientCode = $_POST['client_code'];
    $clientName = $_POST['client_name'];
    
    // Prevent changing the client if the invoice has already been successfully synced to the ERP
    $stmt = $pdo->prepare("SELECT invoice_sysref FROM plant_bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $sysRef = $stmt->fetchColumn();
    
    if (!empty($sysRef) && !in_array($sysRef, ['N/A', 'SUCCESS_NO_REF'])) {
        echo "ERROR: Cannot change client. This RFP is already synced to the ERP.";
        exit;
    }

    $update = $pdo->prepare("UPDATE plant_bookings SET client_code = ?, client_name = ? WHERE id = ?");
    $update->execute([$clientCode, $clientName, $bookingId]);
    logPlantAction($pdo, $userId, 'CLIENT_EDITED', "Changed ERP Client to: $clientCode - $clientName", $bookingId);
    echo "OK";
    exit;
}

if ($action == 'get_client_max_discount') {
    $clientCode = $_GET['client_code'] ?? '';
    $companyId = $_GET['company_id'] ?? '';
    
    $apiKey = getApiKey($companyId);
    $clients = getJ2ApiData('/clients', $apiKey);
    
    $maxDisc = 0;
    if (is_array($clients)) {
        foreach ($clients as $c) {
            if (trim((string)($c['ClientCode'] ?? '')) === trim($clientCode)) {
                $maxDisc = isset($c['CliDefDisc']) ? (float)$c['CliDefDisc'] : 0;
                break;
            }
        }
    }
    echo json_encode(['max_discount' => $maxDisc]);
    exit;
}
    
?>
