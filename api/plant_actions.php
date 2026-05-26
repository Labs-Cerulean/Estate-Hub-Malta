<?php
require_once '../config.php';
require_once '../session-check.php';
require_once '../user-functions.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

session_write_close(); 

$isManager = in_array($role, ['admin', 'director', 'system_manager', 'plant_manager']);
$canManageFleet = in_array($role, ['admin', 'system_manager']) || hasPermission('manage_plant_fleet');
$canViewLedger = in_array($role, ['admin', 'director', 'system_manager', 'accountant']) || hasPermission('view_plant_ledger');

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

    $plantsRaw = $pdo->query("SELECT id, name, category, registration_plate, billing_company_id, pricing_type, nom_code_fixed, nom_code_variable FROM plants WHERE status='Active' ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);
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
        'Other Trucks' => '#64748b', 'Scarifier' => '#ec4899', 'General' => '#6366f1'
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

    $stmt = $pdo->prepare("INSERT INTO plants (category, name, registration_plate, developer_client_id, inhouse_rate, external_rate, pricing_type, min_hours, nom_code_fixed, nom_code_variable, billing_company_id) VALUES (?, ?, ?, ?, 0.00, 0.00, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['category'], $_POST['name'], empty($_POST['reg']) ? null : $_POST['reg'], 
        $_POST['billing_company_id'], $pricingType, $minHours, $nomFixed, $nomVar, $_POST['billing_company_id']
    ]);
    
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

    $stmt = $pdo->prepare("UPDATE plants SET category=?, name=?, registration_plate=?, developer_client_id=?, inhouse_rate=0.00, external_rate=0.00, pricing_type=?, min_hours=?, nom_code_fixed=?, nom_code_variable=?, billing_company_id=? WHERE id=?");
    $stmt->execute([
        $_POST['category'], $_POST['name'], empty($_POST['reg']) ? null : $_POST['reg'], 
        $_POST['billing_company_id'], $pricingType, $minHours, $nomFixed, $nomVar, $_POST['billing_company_id'], $_POST['edit_plant_id']
    ]);
    
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
    
    if (!isDriverAvailable($pdo, $driverId, $_POST['booking_date'], $_POST['start_time'], $_POST['end_time'])) {
        echo "ERROR_OVERLAP"; 
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO plant_bookings (plant_id, driver_id, booking_type, project_id, client_name, client_code, location_lat, location_lng, booking_date, start_time, end_time, comments, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        $userId 
    ]); 
    
    echo "OK"; 
    exit;
}

if ($action == 'update_booking' && $isManager) {
    $driverId = empty($_POST['driver_id']) ? null : $_POST['driver_id'];
    $editId = $_POST['edit_id'];
    
    // We strictly DO NOT allow editing a Completed job here anymore. That happens on the RFP page.
    $checkStmt = $pdo->prepare("SELECT status FROM plant_bookings WHERE id = ?");
    $checkStmt->execute([$editId]);
    $existingJob = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingJob && $existingJob['status'] === 'Completed') {
        echo "ERROR: Completed jobs cannot be edited here. Use the RFP Edit function instead.";
        exit;
    }
    
    if (!isDriverAvailable($pdo, $driverId, $_POST['booking_date'], $_POST['start_time'], $_POST['end_time'], $editId)) {
        echo "ERROR_OVERLAP"; 
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE plant_bookings 
        SET plant_id=?, driver_id=?, booking_type=?, project_id=?, client_name=?, client_code=?, location_lat=?, location_lng=?, booking_date=?, start_time=?, end_time=?, comments=? 
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
        $editId 
    ]); 
    
    echo "OK"; 
    exit;
}

if ($action == 'cancel_booking' && $isManager) { 
    $stmt = $pdo->prepare("DELETE FROM plant_bookings WHERE id=?");
    $stmt->execute([$_POST['id']]); 
    echo "OK"; 
    exit; 
}

if ($action == 'get_job') {
    $stmt = $pdo->prepare("
        SELECT pb.*, p.name as plant_name, p.category, p.pricing_type, 
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
    
    echo "OK"; 
    exit;
}

if ($action == 'punch_in') { 
    $stmt = $pdo->prepare("UPDATE plant_bookings SET status='In Progress', punch_in_time=NOW(), driver_id=COALESCE(driver_id, ?) WHERE id=?");
    $stmt->execute([$userId, $_GET['id']]); 
    
    echo "OK"; 
    exit; 
}

if ($action == 'punch_out_complete') {
    $bookingId = $_POST['id'];
    
    $stmt = $pdo->prepare("
        UPDATE plant_bookings 
        SET status='Completed', punch_out_time=NOW(), qty_trips=?, client_rep_name=?, client_rep_id_card=?, signature_data=? 
        WHERE id=?
    ");
    
    $stmt->execute([
        empty($_POST['qty_trips']) ? null : $_POST['qty_trips'], 
        $_POST['rep_name'], 
        $_POST['rep_id'], 
        $_POST['signature'], 
        $bookingId
    ]);
    
    $domain = APP_URL; 
    $accEmail = $pdo->query("SELECT email FROM users WHERE role='accountant' AND is_active='Yes' LIMIT 1")->fetchColumn() ?: 'accounts@yourdomain.com'; 
    
    @mail($accEmail, "Plant Job Completed", "A heavy plant job has been completed. Review RFP: " . $domain . "/print_plant_invoice.php?booking_id=" . $bookingId, "From: system@yourdomain.com"); 
    
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
    $finalSubtotal = empty($_POST['subtotal']) ? 0 : (float)$_POST['subtotal'];

    // Fetch full job data
    $stmt = $pdo->prepare("
        SELECT pb.*, p.billing_company_id, p.pricing_type, p.nom_code_fixed, p.nom_code_variable, p.min_hours, 
               p.name as plant_name, u.first_name as driver_first, u.last_name as driver_last 
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id 
        LEFT JOIN users u ON pb.driver_id = u.id 
        WHERE pb.id = ?
    "); 
    $stmt->execute([$bookingId]); 
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    $apiKey = getApiKey($job['billing_company_id']);
    
    // NEW: Update punch times from the RFP edit screen
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

    // Mark as invoiced locally
    $stmtLocal = $pdo->prepare("UPDATE plant_bookings SET final_hours=?, final_subtotal=?, payment_status='Invoiced' WHERE id=?");
    $stmtLocal->execute([$finalHours, $finalSubtotal, $bookingId]);
    
    if(empty($job['client_code'])) { 
        $pdo->prepare("UPDATE plant_bookings SET invoice_sysref='N/A' WHERE id=?")->execute([$bookingId]);
        echo "OK_LOCAL_ONLY"; 
        exit; 
    }

    $isInternal = $job['booking_type'] == 'in-house'; 
    $totalVal = $finalSubtotal; 
    $totalTax = $totalVal * 0.18;
    $jobRef = sprintf("PRA-%s-%04d", date('Y', strtotime($job['booking_date'])), $bookingId);
    $driverName = trim(($job['driver_first'] ?? 'Unassigned') . ' ' . ($job['driver_last'] ?? ''));
    
    // Extract explicitly overridden rates from the RFP
    $fixedNom = getNominalDetails($job['nom_code_fixed'], $apiKey);
    $varNom = getNominalDetails($job['nom_code_variable'], $apiKey);
    
    $customRateFixed = isset($_POST['rate_fixed']) ? (float)$_POST['rate_fixed'] : ($fixedNom ? ($isInternal ? $fixedNom['NCDefSP1'] : $fixedNom['NCDefSP2']) : 0);
    $customRateVar = isset($_POST['rate_var']) ? (float)$_POST['rate_var'] : ($varNom ? ($isInternal ? $varNom['NCDefSP1'] : $varNom['NCDefSP2']) : 0);
    
    $lines = [];
    
    if ($job['pricing_type'] == 'fixed_then_hourly' && !empty($job['nom_code_fixed'])) {
        if($fixedNom) { 
            $lines[] = [
                "Type" => "N", 
                "Code" => trim($fixedNom['NCCode']), 
                "Description" => substr(trim($fixedNom['NCDesc']), 0, 35), 
                "UOMLevel" => 1, 
                "Location" => "01", 
                "Qty" => 1, 
                "Price" => $customRateFixed, 
                "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0
            ]; 
        }
        
        $extraHours = $finalHours - (float)$job['min_hours'];
        if ($extraHours > 0 && !empty($job['nom_code_variable'])) {
            if($varNom) { 
                $lines[] = [
                    "Type" => "N", 
                    "Code" => trim($varNom['NCCode']), 
                    "Description" => substr(trim($varNom['NCDesc']), 0, 35), 
                    "UOMLevel" => 1, 
                    "Location" => "01", 
                    "Qty" => $extraHours, 
                    "Price" => $customRateVar, 
                    "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0
                ]; 
            }
        }
    } elseif ($job['pricing_type'] == 'per_trip' && !empty($job['nom_code_fixed'])) {
        if($fixedNom) { 
            $lines[] = [
                "Type" => "N", 
                "Code" => trim($fixedNom['NCCode']), 
                "Description" => substr(trim($fixedNom['NCDesc']), 0, 35), 
                "UOMLevel" => 1, 
                "Location" => "01", 
                "Qty" => (float)$job['qty_trips'] > 0 ? (float)$job['qty_trips'] : 1, 
                "Price" => $customRateFixed, 
                "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0
            ]; 
        }
    } else {
        $standardCode = !empty($job['nom_code_variable']) ? $job['nom_code_variable'] : '0000'; 
        $standardNom = getNominalDetails($standardCode, $apiKey);
        $lines[] = [
            "Type" => "N", 
            "Code" => $standardNom ? trim($standardNom['NCCode']) : $standardCode, 
            "Description" => substr($standardNom ? trim($standardNom['NCDesc']) : "Plant Operation", 0, 35), 
            "UOMLevel" => 1, 
            "Location" => "01", 
            "Qty" => $finalHours, 
            "Price" => $customRateVar, 
            "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => 0.0, "DR" => 0, "CR" => 0
        ];
    }

    if (empty($lines)) { 
        $pdo->prepare("UPDATE plant_bookings SET invoice_sysref='N/A' WHERE id=?")->execute([$bookingId]);
        echo "OK_LOCAL_ONLY"; 
        exit; 
    }
    
    $lines[] = [ "Type" => "T", "Code" => "0000", "Description" => substr("Delivery Note: " . $jobRef, 0, 35), "Qty" => 1, "Location" => "01" ];
    $lines[] = [ "Type" => "T", "Code" => "0000", "Description" => substr("Driver: " . $driverName, 0, 35), "Qty" => 1, "Location" => "01" ];

    $payload = [ 
        "Type" => "IN", 
        "Transaction" => [ 
            "InvioceHeader" => [ 
                "THTranCode" => "IN", 
                "THDate" => date('Y-m-d'), 
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
    
    if ($erpResult['code'] >= 200 && $erpResult['code'] < 300) { 
        $sysRef = json_decode($erpResult['response'], true)['SysRef'] ?? 'SUCCESS_NO_REF'; 
        $pdo->prepare("UPDATE plant_bookings SET invoice_sysref = ? WHERE id = ?")->execute([$sysRef, $bookingId]); 
        echo "OK"; 
    } else { 
        $pdo->prepare("UPDATE plant_bookings SET invoice_sysref='N/A' WHERE id=?")->execute([$bookingId]);
        echo "OK_LOCAL_ONLY"; 
    } 
    exit;
}

if ($action == 'get_ledger' && $canViewLedger) {
    $stmt = $pdo->query("
        SELECT pb.*, p.name as plant_name, bc.name as billing_company_name, prj.name as project_name, c.name as client_dev_name 
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id 
        LEFT JOIN projects prj ON pb.project_id = prj.id 
        LEFT JOIN clients c ON p.developer_client_id = c.id 
        LEFT JOIN clients bc ON p.billing_company_id = bc.id 
        ORDER BY pb.booking_date DESC
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); 
    exit;
}

if ($action == 'mark_settled' && $canViewLedger) { 
    $stmt = $pdo->prepare("UPDATE plant_bookings SET payment_status='Settled' WHERE id=?");
    $stmt->execute([$_POST['id']]); 
    echo "OK"; 
    exit; 
}

if ($action == 'get_nominals_for_job') {
    $stmt = $pdo->prepare("
        SELECT p.nom_code_fixed, p.nom_code_variable, p.billing_company_id 
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id 
        WHERE pb.id = ?
    "); 
    $stmt->execute([$_GET['booking_id']]); 
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $apiKey = getApiKey($job['billing_company_id']);
    
    echo json_encode([
        'fixed' => getNominalDetails($job['nom_code_fixed'], $apiKey), 
        'variable' => getNominalDetails($job['nom_code_variable'], $apiKey)
    ]); 
    exit;
}
?>
