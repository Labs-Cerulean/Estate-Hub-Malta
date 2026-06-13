<?php
require_once '../config.php';
require_once '../session-check.php';
require_once '../user-functions.php';

function logPlantAction($pdo, $userId, $actionType, $details, $bookingId = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    try {
        $stmt = $pdo->prepare("INSERT INTO plant_audit_log (user_id, booking_id, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $bookingId, $actionType, $details, $ip]);
    } catch(PDOException $e) {
        // Silently fail so a logging error never stops a live billing transaction
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

    $plantsRaw = $pdo->query("SELECT id, name, category, registration_plate, billing_company_id, pricing_type, nom_code_fixed, nom_code_variable, setup_fee, nom_code_setup FROM plants WHERE status='Active' ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);
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

if ($action == 'get_dashboard_stats' && in_array($role, ['admin', 'director'])) {
    $startDate = !empty($_POST['start']) ? date('Y-m-d', strtotime($_POST['start'])) : date('Y-m-d', strtotime('-1 month'));
    $endDate = !empty($_POST['end']) ? date('Y-m-d', strtotime($_POST['end'])) : date('Y-m-d', strtotime('+1 month'));

    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(id) as total_jobs,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_jobs,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_jobs,
            SUM(CASE WHEN payment_status IN ('Invoiced', 'Settled') THEN final_subtotal ELSE 0 END) as invoiced_revenue
        FROM plant_bookings 
        WHERE booking_date >= ? AND booking_date <= ?
    ");
    $statsStmt->execute([$startDate, $endDate]);
    $kpi = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $driverStmt = $pdo->prepare("
        SELECT 
            u.first_name, u.last_name, 
            COUNT(pb.id) as job_count,
            SUM(TIME_TO_SEC(TIMEDIFF(pb.end_time, pb.start_time))/3600) as scheduled_hours,
            SUM(pb.final_hours) as actual_hours
        FROM plant_bookings pb
        JOIN users u ON pb.driver_id = u.id
        WHERE pb.booking_date >= ? AND pb.booking_date <= ?
        GROUP BY u.id
        ORDER BY scheduled_hours DESC
    ");
    $driverStmt->execute([$startDate, $endDate]);
    $driverStats = $driverStmt->fetchAll(PDO::FETCH_ASSOC);

    $uninvoicedStmt = $pdo->prepare("
        SELECT pb.id, p.name as plant_name, pb.booking_date, pb.client_name, prj.name as project_name 
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id
        LEFT JOIN projects prj ON pb.project_id = prj.id
        WHERE pb.status = 'Completed' AND pb.payment_status = 'Pending'
        AND pb.booking_date >= ? AND pb.booking_date <= ?
        ORDER BY pb.booking_date ASC LIMIT 15
    ");
    $uninvoicedStmt->execute([$startDate, $endDate]);
    $uninvoicedJobs = $uninvoicedStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($uninvoicedJobs as &$uj) {
        $uj['formatted_date'] = date('d M', strtotime($uj['booking_date']));
    }

    echo json_encode(['kpi' => $kpi, 'drivers' => $driverStats, 'uninvoiced' => $uninvoicedJobs]);
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
            $statusInd = "✅ ";
        } elseif ($b['status'] == 'In Progress') {
            $statusInd = "⏳ ";
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
        
        if (strtotime($eTime) < strtotime($sTime)) {
            $endIso = date('Y-m-d', strtotime($b['booking_date'] . ' +1 day')) . 'T' . $eTime;
        } else {
            $endIso = $b['booking_date'] . 'T' . $eTime;
        }

        $events[] = [
            'id' => $b['id'], 
            'title' => $title, 
            'start' => $startIso, 
            'end' => $endIso, 
            'backgroundColor' => $color, 
            'borderColor' => $color
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
    } elseif ($pricingType === 'per_trip') { 
        $nomVar = null; 
    }

    $setupFee = empty($_POST['setup_fee']) ? 0.00 : (float)$_POST['setup_fee'];
    $nomSetup = empty($_POST['nom_code_setup']) ? null : $_POST['nom_code_setup'];

    $stmt = $pdo->prepare("INSERT INTO plants (category, name, registration_plate, developer_client_id, inhouse_rate, external_rate, pricing_type, min_hours, nom_code_fixed, nom_code_variable, setup_fee, nom_code_setup, billing_company_id) VALUES (?, ?, ?, ?, 0.00, 0.00, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['category'], $_POST['name'], empty($_POST['reg']) ? null : $_POST['reg'], 
        $_POST['billing_company_id'], $pricingType, $minHours, $nomFixed, $nomVar, 
        $setupFee, $nomSetup, $_POST['billing_company_id']
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
    } elseif ($pricingType === 'per_trip') { 
        $nomVar = null; 
    }

    $setupFee = empty($_POST['setup_fee']) ? 0.00 : (float)$_POST['setup_fee'];
    $nomSetup = empty($_POST['nom_code_setup']) ? null : $_POST['nom_code_setup'];

    $stmt = $pdo->prepare("UPDATE plants SET category=?, name=?, registration_plate=?, developer_client_id=?, inhouse_rate=0.00, external_rate=0.00, pricing_type=?, min_hours=?, nom_code_fixed=?, nom_code_variable=?, setup_fee=?, nom_code_setup=?, billing_company_id=? WHERE id=?");
    $stmt->execute([
        $_POST['category'], $_POST['name'], empty($_POST['reg']) ? null : $_POST['reg'], 
        $_POST['billing_company_id'], $pricingType, $minHours, $nomFixed, $nomVar, 
        $setupFee, $nomSetup, $_POST['billing_company_id'], $_POST['edit_plant_id']
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
    // Secure Server-side Time Generation
    $punchTime = date('Y-m-d H:i:s');
    
    $stmt = $pdo->prepare("UPDATE plant_bookings SET status='In Progress', punch_in_time=?, driver_id=COALESCE(driver_id, ?) WHERE id=?");
    $stmt->execute([$punchTime, $userId, $_GET['id']]); 
    logPlantAction($pdo, $userId, 'JOB_STARTED', "Driver punched in and started job", $_GET['id']);
    echo "OK"; 
    exit; 
}

if ($action == 'punch_out_complete') {
    // Secure Server-side Time Generation
    $punchTime = date('Y-m-d H:i:s');
    $bookingId = $_POST['id'];
    
    $stmt = $pdo->prepare("
        UPDATE plant_bookings 
        SET status='Completed', punch_out_time=?, qty_trips=?, client_rep_name=?, client_rep_id_card=?, signature_data=? 
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
    
    $domain = APP_URL; 
    $accEmail = $pdo->query("SELECT email FROM users WHERE role='accountant' AND is_active='Yes' LIMIT 1")->fetchColumn() ?: 'accounts@yourdomain.com'; 
    
    @mail($accEmail, "Plant Job Completed", "A heavy plant job has been completed. Review RFP: " . $domain . "/print_plant_invoice.php?booking_id=" . $bookingId, "From: system@yourdomain.com"); 
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

    $stmt = $pdo->prepare("
        SELECT pb.*, p.billing_company_id, p.pricing_type, p.nom_code_fixed, p.nom_code_variable, p.min_hours, 
               p.setup_fee, p.nom_code_setup,
               p.name as plant_name, u.first_name as driver_first, u.last_name as driver_last 
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id 
        LEFT JOIN users u ON pb.driver_id = u.id 
        WHERE pb.id = ?
    "); 
    $stmt->execute([$bookingId]); 
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    // Priority 1: Prevent finalisation if Client is still TBC
    if ($job['client_code'] === 'TBC' || empty($job['client_code'])) {
        echo "ERROR: Client details are marked as TBC. You must assign a valid ERP Client before finalising the delivery note.";
        exit;
    }

    $apiKey = getApiKey($job['billing_company_id']);
    
    // Process modified punch clock hours from Admin input
    $punchIn = null;
    $punchOut = null;
    if (!empty($_POST['time_in']) && !empty($_POST['time_out'])) {
        $tInTime = strtotime($_POST['time_in']);
        $tOutTime = strtotime($_POST['time_out']);
        $outDate = $job['booking_date'];
        
        if ($tOutTime < $tInTime) {
            $outDate = date('Y-m-d', strtotime($job['booking_date'] . ' +1 day'));
        }
        
        $punchIn = $job['booking_date'] . ' ' . $_POST['time_in'] . ':00';
        $punchOut = $outDate . ' ' . $_POST['time_out'] . ':00';
    }

    if ($punchIn && $punchOut) {
        $pdo->prepare("UPDATE plant_bookings SET punch_in_time=?, punch_out_time=? WHERE id=?")->execute([$punchIn, $punchOut, $bookingId]);
    }

    // Capture explicit rates from the UI
    $customRateFixed = isset($_POST['rate_fixed']) ? (float)$_POST['rate_fixed'] : null;
    $customRateVar = isset($_POST['rate_var']) ? (float)$_POST['rate_var'] : null;
    $customSetupFee = isset($_POST['setup_fee']) ? (float)$_POST['setup_fee'] : null;

    $isInternal = $job['booking_type'] == 'in-house'; 
    $fixedNom = getNominalDetails($job['nom_code_fixed'], $apiKey);
    $varNom = getNominalDetails($job['nom_code_variable'], $apiKey);
    $setupNom = getNominalDetails($job['nom_code_setup'], $apiKey);
    
    // Fallback logic to resolve the absolute rates
    $syncPriceFixed = $customRateFixed !== null ? $customRateFixed : ($fixedNom ? ($isInternal ? $fixedNom['NCDefSP1'] : $fixedNom['NCDefSP2']) : 0);
    $syncPriceVar = $customRateVar !== null ? $customRateVar : ($varNom ? ($isInternal ? $varNom['NCDefSP1'] : $varNom['NCDefSP2']) : 0);
    
    $syncSetupPrice = 0;
    $hasSetupFeeApplied = isset($job['apply_setup_fee']) ? $job['apply_setup_fee'] : 0;
    
    if ($hasSetupFeeApplied == 1 || $customSetupFee > 0) {
        $syncSetupPrice = $customSetupFee !== null ? $customSetupFee : (float)$job['setup_fee'];
    }

    // ABSOLUTE MATH ENGINE: Recalculate Subtotal securely on the server
    $lines = [];
    $backendSubtotal = 0;

    if ($syncSetupPrice > 0) {
        $setupCode = $setupNom ? trim($setupNom['NCCode']) : (!empty($job['nom_code_setup']) ? $job['nom_code_setup'] : '0000');
        $setupDesc = $setupNom ? substr(trim($setupNom['NCDesc']), 0, 35) : "Setup / Mobilisation Fee";
        
        $lines[] = [
            "Type" => "N", "Code" => $setupCode, "Description" => $setupDesc, "UOMLevel" => 1, "Location" => "01", 
            "Qty" => 1, "Price" => $syncSetupPrice, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0
        ];
        $backendSubtotal += $syncSetupPrice;
    }
    
    if ($job['pricing_type'] == 'fixed_then_hourly' && !empty($job['nom_code_fixed'])) {
        $fCode = $fixedNom ? trim($fixedNom['NCCode']) : trim($job['nom_code_fixed']);
        $fDesc = $fixedNom ? substr(trim($fixedNom['NCDesc']), 0, 35) : "Fixed Callout Charge";
        
        $lines[] = [
            "Type" => "N", "Code" => $fCode, "Description" => $fDesc, "UOMLevel" => 1, "Location" => "01", 
            "Qty" => 1, "Price" => $syncPriceFixed, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0
        ]; 
        $backendSubtotal += $syncPriceFixed;
        
        $extraHours = $finalHours - (float)$job['min_hours'];
        if ($extraHours > 0 && !empty($job['nom_code_variable'])) {
            $vCode = $varNom ? trim($varNom['NCCode']) : trim($job['nom_code_variable']);
            $vDesc = $varNom ? substr(trim($varNom['NCDesc']), 0, 35) : "Additional Hourly Rate";
            
            $lines[] = [
                "Type" => "N", "Code" => $vCode, "Description" => $vDesc, "UOMLevel" => 1, "Location" => "01", 
                "Qty" => $extraHours, "Price" => $syncPriceVar, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0
            ]; 
            $backendSubtotal += ($extraHours * $syncPriceVar);
        }
    } elseif ($job['pricing_type'] == 'per_trip' && !empty($job['nom_code_fixed'])) {
        $tCode = $fixedNom ? trim($fixedNom['NCCode']) : trim($job['nom_code_fixed']);
        $tDesc = $fixedNom ? substr(trim($fixedNom['NCDesc']), 0, 35) : "Trip Execution Charge";
        $qty = (float)$job['qty_trips'] > 0 ? (float)$job['qty_trips'] : 1;
        
        $lines[] = [
            "Type" => "N", "Code" => $tCode, "Description" => $tDesc, "UOMLevel" => 1, "Location" => "01", 
            "Qty" => $qty, "Price" => $syncPriceFixed, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0
        ]; 
        $backendSubtotal += ($qty * $syncPriceFixed);
    } else {
        $hCode = $varNom ? trim($varNom['NCCode']) : (!empty($job['nom_code_variable']) ? trim($job['nom_code_variable']) : '0000'); 
        $hDesc = $varNom ? substr(trim($varNom['NCDesc']), 0, 35) : "Plant Operation";
        
        $lines[] = [
            "Type" => "N", "Code" => $hCode, "Description" => $hDesc, "UOMLevel" => 1, "Location" => "01", 
            "Qty" => $finalHours, "Price" => $syncPriceVar, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0
        ];
        $backendSubtotal += ($finalHours * $syncPriceVar);
    }

    // SAFELY RECORD THE MATH TO THE DATABASE
    $stmtLocal = $pdo->prepare("UPDATE plant_bookings SET final_hours=?, final_subtotal=?, final_rate_fixed=?, final_rate_var=?, final_setup_fee=?, payment_status='Invoiced' WHERE id=?");
    $stmtLocal->execute([$finalHours, $backendSubtotal, $syncPriceFixed, $syncPriceVar, $syncSetupPrice, $bookingId]);
    
    if (empty($lines) || empty($job['client_code'])) { 
        $pdo->prepare("UPDATE plant_bookings SET invoice_sysref='N/A' WHERE id=?")->execute([$bookingId]);
        echo "OK_LOCAL_ONLY"; 
        exit; 
    }

    $totalVal = $backendSubtotal; 
    $totalTax = $totalVal * 0.18;
    $jobRef = sprintf("PRA-%s-%04d", date('Y', strtotime($job['booking_date'])), $bookingId);
    $driverName = trim(($job['driver_first'] ?? 'Unassigned') . ' ' . ($job['driver_last'] ?? ''));
    
    $lines[] = [ "Type" => "T", "Code" => "0000", "Description" => substr("Delivery Note: " . $jobRef, 0, 35), "Qty" => 1, "Location" => "01" ];
    $lines[] = [ "Type" => "T", "Code" => "0000", "Description" => substr("Driver: " . $driverName, 0, 35), "Qty" => 1, "Location" => "01" ];

    $payload = [ 
        "Type" => "IN", 
        "Transaction" => [ 
            "InvioceHeader" => [ 
                "THTranCode" => "IN", 
                "THDate" => $job['booking_date'], 
                "THUserID" => "API", 
                "THCSCode" => $job['client_code'], 
                "THName" => $job['client_name'], 
                "THTaxNumber" => "", 
                "THTotValueTIF" => (string)round($totalVal + $totalTax, 2), 
                "THExtRef" => $jobRef, 
                "THRevision" => "001", 
                "THTotDiscF" => 0.0, 
                "THTotDiscTIF" => 0.0, 
                "THTotTaxF" => round($totalTax, 2), 
                "THCurrency" => "EUR", 
                "THExchRate" => 1, 
                "THPayment" => "", 
                "THPayRef" => "" 
            ], 
            "InvioceItemLine" => [ "Lines" => $lines ], 
            "Ledger" => "S", 
            "OfflineDocRefs" => "" 
        ] 
    ];

    $erpResult = postJ2ApiData('/sales/transaction', $apiKey, $payload);
    
    // If it succeeds with the ERP:
    if ($erpResult['code'] >= 200 && $erpResult['code'] < 300) { 
        $sysRef = json_decode($erpResult['response'], true)['SysRef'] ?? 'SUCCESS_NO_REF'; 
        $pdo->prepare("UPDATE plant_bookings SET invoice_sysref = ? WHERE id = ?")->execute([$sysRef, $bookingId]); 
        
        logPlantAction($pdo, $userId, 'RFP_FINALIZED_SYNCED', "Synced invoice to ERP. SysRef: $sysRef", $bookingId); // LOG HERE
        echo "OK"; 
    } else { 
        $pdo->prepare("UPDATE plant_bookings SET invoice_sysref='N/A' WHERE id=?")->execute([$bookingId]);
        
        logPlantAction($pdo, $userId, 'RFP_FINALIZED_LOCAL', "Saved locally but ERP Sync Failed. Needs manual retry.", $bookingId); // LOG HERE
        echo "OK_LOCAL_ONLY"; 
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

if ($action == 'retry_erp_sync' && $canViewLedger) {
    $bookingId = $_POST['booking_id'];
    
    // 1. Fetch Job and check if it actually needs syncing
    $stmt = $pdo->prepare("
        SELECT pb.*, p.billing_company_id, p.pricing_type, p.nom_code_fixed, p.nom_code_variable, p.nom_code_setup, u.first_name as driver_first, u.last_name as driver_last
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id 
        LEFT JOIN users u ON pb.driver_id = u.id 
        WHERE pb.id = ?
    "); 
    $stmt->execute([$bookingId]); 
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (empty($job['client_code']) || $job['client_code'] === 'TBC') {
        echo "ERROR: Client details are still TBC. Please edit the client first."; exit;
    }

    $apiKey = getApiKey($job['billing_company_id']);
    $setupNom = getNominalDetails($job['nom_code_setup'], $apiKey);
    $fixedNom = getNominalDetails($job['nom_code_fixed'], $apiKey);
    $varNom = getNominalDetails($job['nom_code_variable'], $apiKey);

    // 2. Rebuild the exact lines using the strictly saved final_ data
    $lines = [];
    
    if ($job['final_setup_fee'] > 0) {
        $setupCode = $setupNom ? trim($setupNom['NCCode']) : (!empty($job['nom_code_setup']) ? $job['nom_code_setup'] : '0000');
        $lines[] = [ "Type" => "N", "Code" => $setupCode, "Description" => "Setup / Mobilisation Fee", "UOMLevel" => 1, "Location" => "01", "Qty" => 1, "Price" => (float)$job['final_setup_fee'], "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0 ];
    }
    
    if ($job['pricing_type'] == 'fixed_then_hourly') {
        $fCode = $fixedNom ? trim($fixedNom['NCCode']) : trim($job['nom_code_fixed']);
        $lines[] = [ "Type" => "N", "Code" => $fCode, "Description" => "Fixed Callout Charge", "UOMLevel" => 1, "Location" => "01", "Qty" => 1, "Price" => (float)$job['final_rate_fixed'], "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0 ]; 
        
        $extraHours = (float)$job['final_hours'] - (float)$job['min_hours'];
        if ($extraHours > 0) {
            $vCode = $varNom ? trim($varNom['NCCode']) : trim($job['nom_code_variable']);
            $lines[] = [ "Type" => "N", "Code" => $vCode, "Description" => "Additional Hourly Rate", "UOMLevel" => 1, "Location" => "01", "Qty" => $extraHours, "Price" => (float)$job['final_rate_var'], "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0 ]; 
        }
    } elseif ($job['pricing_type'] == 'per_trip') {
        $tCode = $fixedNom ? trim($fixedNom['NCCode']) : trim($job['nom_code_fixed']);
        $qty = (float)$job['qty_trips'] > 0 ? (float)$job['qty_trips'] : 1;
        $lines[] = [ "Type" => "N", "Code" => $tCode, "Description" => "Trip Execution Charge", "UOMLevel" => 1, "Location" => "01", "Qty" => $qty, "Price" => (float)$job['final_rate_fixed'], "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0 ]; 
    } else {
        $hCode = $varNom ? trim($varNom['NCCode']) : (!empty($job['nom_code_variable']) ? trim($job['nom_code_variable']) : '0000'); 
        $lines[] = [ "Type" => "N", "Code" => $hCode, "Description" => "Plant Operation", "UOMLevel" => 1, "Location" => "01", "Qty" => (float)$job['final_hours'], "Price" => (float)$job['final_rate_var'], "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0 ];
    }

    $totalVal = (float)$job['final_subtotal']; 
    $totalTax = $totalVal * 0.18;
    $jobRef = sprintf("PRA-%s-%04d", date('Y', strtotime($job['booking_date'])), $bookingId);
    $driverName = trim(($job['driver_first'] ?? 'Unassigned') . ' ' . ($job['driver_last'] ?? ''));
    
    $lines[] = [ "Type" => "T", "Code" => "0000", "Description" => substr("Delivery Note: " . $jobRef, 0, 35), "Qty" => 1, "Location" => "01" ];
    $lines[] = [ "Type" => "T", "Code" => "0000", "Description" => substr("Driver: " . $driverName, 0, 35), "Qty" => 1, "Location" => "01" ];

    // 3. Push to ERP
    $payload = [ 
        "Type" => "IN", 
        "Transaction" => [ 
            "InvioceHeader" => [ 
                "THTranCode" => "IN", "THDate" => $job['booking_date'], "THUserID" => "API", 
                "THCSCode" => $job['client_code'], "THName" => $job['client_name'], "THTaxNumber" => "", 
                "THTotValueTIF" => (string)round($totalVal + $totalTax, 2), "THExtRef" => $jobRef, 
                "THRevision" => "001", "THTotDiscF" => 0.0, "THTotDiscTIF" => 0.0, "THTotTaxF" => round($totalTax, 2), 
                "THCurrency" => "EUR", "THExchRate" => 1, "THPayment" => "", "THPayRef" => "" 
            ], 
            "InvioceItemLine" => [ "Lines" => $lines ], "Ledger" => "S", "OfflineDocRefs" => "" 
        ] 
    ];

    $erpResult = postJ2ApiData('/sales/transaction', $apiKey, $payload);
    
    if ($erpResult['code'] >= 200 && $erpResult['code'] < 300) { 
        $sysRef = json_decode($erpResult['response'], true)['SysRef'] ?? 'SUCCESS_NO_REF'; 
        $pdo->prepare("UPDATE plant_bookings SET invoice_sysref = ? WHERE id = ?")->execute([$sysRef, $bookingId]); 
        
        logPlantAction($pdo, $userId, 'RFP_RETRY_SUCCESS', "Manual retry sync successful. SysRef: $sysRef", $bookingId);
        echo "OK"; 
    } else { 
        logPlantAction($pdo, $userId, 'RFP_RETRY_FAILED', "Manual retry sync failed. ERP Response: " . htmlspecialchars($erpResult['response']), $bookingId);
        echo "ERP rejected the request. It might be a network error or missing client data in ERP."; 
    } 
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
?>
