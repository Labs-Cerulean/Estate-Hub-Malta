<?php
require_once '../config.php';
require_once '../session-check.php';
require_once '../user-functions.php';
require_once '../includes/plant_schema_deploy.php';

function logPlantAction($pdo, $userId, $actionType, $details, $bookingId = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    try {
        $stmt = $pdo->prepare("INSERT INTO plant_audit_log (user_id, booking_id, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $bookingId, $actionType, $details, $ip]);
    } catch(PDOException $e) {
        // Silently fail so a logging error never stops a live billing transaction
    }
}

function pushBookingToERP($pdo, $bookingId, $userId) {
    $stmt = $pdo->prepare("
        SELECT pb.*, p.billing_company_id, p.pricing_type, p.nom_code_fixed, p.nom_code_variable, p.nom_code_setup, p.min_hours, 
               p.has_configurations, p.configurations, p.requires_driver,
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
    } elseif ($job['pricing_type'] == 'daily') {
        $dCode = $fixedNom ? trim($fixedNom['NCCode']) : trim($job['nom_code_fixed']);
        $dDesc = $fixedNom ? substr(trim($fixedNom['NCDesc']), 0, 35) : "Daily Flat Rate";
        $qty = round((float)$job['final_hours'] > 0 ? (float)$job['final_hours'] : 1, 2);
        $price = round((float)$job['final_rate_fixed'], 4);
        $grossSubtotal += round($qty * $price, 2);
        
        $lines[] = [ "Type" => "N", "Code" => $dCode, "Description" => $dDesc, "UOMLevel" => 1, "Location" => "01", 
                     "Qty" => $qty, "Price" => $price, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => round($discountPct, 2), "DR" => 0, "CR" => 0 ]; 
    } else {
        // --- INTELLIGENT MULTI-MODE & ADD-ON SYNC LOGIC ---
        $cfgs = ($job['has_configurations'] == 1 && !empty($job['configurations'])) ? json_decode($job['configurations'], true) : null;
        
        $sessStmt = $pdo->prepare("SELECT * FROM plant_job_sessions WHERE booking_id = ?");
        $sessStmt->execute([$bookingId]);
        $sessions = $sessStmt->fetchAll(PDO::FETCH_ASSOC);

        if (is_array($cfgs) && count($sessions) > 0) {
            $modeBreakdown = [];
            $addonBreakdown = [];
            foreach ($sessions as $s) {
                $mName = !empty($s['mode_name']) ? $s['mode_name'] : 'Standard Operation';
                if (!isset($modeBreakdown[$mName])) $modeBreakdown[$mName] = 0;
                $modeBreakdown[$mName] += (float)$s['hours'];
                
                if (!empty($s['addons_used'])) {
                    $sAddons = json_decode($s['addons_used'], true);
                    if (is_array($sAddons)) {
                        foreach ($sAddons as $sa) {
                            $saName = $sa['name'];
                            $saQty = (int)$sa['qty'];
                            if ($saQty > 0) {
                                if (!isset($addonBreakdown[$saName])) $addonBreakdown[$saName] = 0;
                                $addonBreakdown[$saName] += ($saQty * (float)$s['hours']);
                            }
                        }
                    }
                }
            }

            $allNominals = getJ2ApiData('/nominalcateg', $apiKey);
            $isInternal = ($job['booking_type'] == 'in-house');

            // 1. Primary Modes Lines
            foreach ($modeBreakdown as $mName => $mHours) {
                $matchedCfg = null;
                foreach ($cfgs as $c) { if ($c['name'] === $mName && $c['type'] === 'mode') { $matchedCfg = $c; break; } }
                
                if ($matchedCfg) {
                    $mCode = trim($matchedCfg['nom_code']);
                    $erpRate = 0;
                    if (!empty($allNominals)) {
                        foreach($allNominals as $n) { if (trim($n['NCCode']) === $mCode) { $erpRate = $isInternal ? $n['NCDefSP1'] : $n['NCDefSP2']; break; } }
                    }
                    $mPrice = $erpRate > 0 ? $erpRate : (float)$matchedCfg['price'];
                    
                    $qty = round($mHours, 2);
                    $price = round($mPrice, 4);
                    $grossSubtotal += round($qty * $price, 2);
                    
                    $lines[] = [ "Type" => "N", "Code" => $mCode, "Description" => substr("Mode: ".$mName, 0, 35), "UOMLevel" => 1, "Location" => "01", 
                                 "Qty" => $qty, "Price" => $price, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => round($discountPct, 2), "DR" => 0, "CR" => 0 ];
                } else {
                    $hCode = $varNom ? trim($varNom['NCCode']) : (!empty($job['nom_code_variable']) ? trim($job['nom_code_variable']) : '0000'); 
                    $qty = round($mHours, 2);
                    $price = round((float)$job['final_rate_var'], 4);
                    $grossSubtotal += round($qty * $price, 2);
                    
                    $lines[] = [ "Type" => "N", "Code" => $hCode, "Description" => substr($mName, 0, 35), "UOMLevel" => 1, "Location" => "01", 
                                 "Qty" => $qty, "Price" => $price, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => round($discountPct, 2), "DR" => 0, "CR" => 0 ];
                }
            }

            // 2. Extra Add-ons Lines
            foreach ($addonBreakdown as $saName => $saQtyHours) {
                $matchedCfg = null;
                foreach ($cfgs as $c) { if ($c['name'] === $saName && $c['type'] === 'addon') { $matchedCfg = $c; break; } }
                
                if ($matchedCfg) {
                    $aCode = trim($matchedCfg['nom_code']);
                    $erpRate = 0;
                    if (!empty($allNominals)) {
                        foreach($allNominals as $n) { if (trim($n['NCCode']) === $aCode) { $erpRate = $isInternal ? $n['NCDefSP1'] : $n['NCDefSP2']; break; } }
                    }
                    $aPrice = $erpRate > 0 ? $erpRate : (float)$matchedCfg['price'];
                    
                    $qty = round($saQtyHours, 2);
                    $price = round($aPrice, 4);
                    $grossSubtotal += round($qty * $price, 2);
                    
                    $lines[] = [ "Type" => "N", "Code" => $aCode, "Description" => substr("Addon: ".$saName, 0, 35), "UOMLevel" => 1, "Location" => "01", 
                                 "Qty" => $qty, "Price" => $price, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => round($discountPct, 2), "DR" => 0, "CR" => 0 ];
                }
            }
        } else {
            $hCode = $varNom ? trim($varNom['NCCode']) : (!empty($job['nom_code_variable']) ? trim($job['nom_code_variable']) : '0000'); 
            $hDesc = $varNom ? substr(trim($varNom['NCDesc']), 0, 35) : "Plant Operation";
            $qty = round((float)$job['final_hours'], 2);
            $price = round((float)$job['final_rate_var'], 4);
            $grossSubtotal += round($qty * $price, 2);
            
            $lines[] = [ "Type" => "N", "Code" => $hCode, "Description" => $hDesc, "UOMLevel" => 1, "Location" => "01", 
                         "Qty" => $qty, "Price" => $price, "VATCode" => "VF", "DiscCalcOn" => "P", "DiscPer" => round($discountPct, 2), "DR" => 0, "CR" => 0 ];
        }
    }

    $totalDiscount = round($grossSubtotal * ($discountPct / 100), 2);
    $netSubtotal = round($grossSubtotal - $totalDiscount, 2);
    $totalTax = round($netSubtotal * 0.18, 2);
    
    $prefix = ($job['billing_company_id'] == '26') ? 'PRAX' : 'PRA';
    $jobRef = sprintf("%s-%s-%04d", $prefix, date('Y', strtotime($job['booking_date'])), $bookingId);
    
    if ((int)($job['requires_driver'] ?? 1) === 0) { $driverName = "Not Required (Static Asset)"; } 
    else { $driverName = trim(($job['driver_first'] ?? 'Unassigned') . ' ' . ($job['driver_last'] ?? '')); }
    if (!empty($job['delivery_chit_number'])) {
        $driverName = trim($driverName . ' | Chit: ' . trim($job['delivery_chit_number']));
    }

    $locationText = ($job['booking_type'] == 'in-house') ? "Project: " . ($job['project_name'] ?? 'N/A') : "Client: " . ($job['client_name'] ?? 'N/A');

    $lines[] = [ "Type" => "T", "Code" => "0000", "Description" => substr("Delivery Note: " . $jobRef, 0, 35), "Qty" => round(1, 2), "Location" => "01" ];
    $lines[] = [ "Type" => "T", "Code" => "0000", "Description" => substr($locationText, 0, 35), "Qty" => round(1, 2), "Location" => "01" ];
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

// Auto-deploy Plant Hub schema (safe to re-run; duplicate column errors are swallowed)
plantDeploySchema($pdo);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (!canUsePlantHubApi()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized access to Plant Hub API.']);
    exit;
}

$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

session_write_close(); // Prevent browser hanging

$isManager = in_array($role, ['admin', 'director', 'system_manager', 'plant_manager']);
$canManageFleet = in_array($role, ['admin', 'system_manager']) || hasPermission('manage_plant_fleet');
$canViewLedger = in_array($role, ['admin', 'director', 'system_manager', 'accountant']) || hasPermission('view_plant_ledger');

// Dynamic API Key Mapper
$praApiKey = getenv('J2_API_KEY_PRA');
$praxApiKey = getenv('J2_API_KEY_PRAX');

$plantErpRequiredActions = ['get_nominals', 'get_company_clients', 'get_client_max_discount', 'finalize_and_invoice', 'retry_erp_sync'];

if (in_array($action, $plantErpRequiredActions, true) && (!$praApiKey || !$praxApiKey)) {
    header('Content-Type: application/json; charset=utf-8');
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
    header('Content-Type: application/json; charset=utf-8');
    $nominalCache = [];
    if ($praApiKey && $praxApiKey) {
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
    }

    try {
        $plantsRaw = $pdo->query("SELECT id, name, category, registration_plate, billing_company_id, pricing_type, nom_code_fixed, nom_code_variable, setup_fee, nom_code_setup, requires_driver, lifecycle_type, has_configurations, configurations, billing_unit FROM plants WHERE status='Active' ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $plantsRaw = $pdo->query("SELECT id, name, category, registration_plate, billing_company_id, pricing_type, nom_code_fixed, nom_code_variable, setup_fee, nom_code_setup FROM plants WHERE status='Active' ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($plantsRaw as &$row) {
            $row['requires_driver'] = 1;
            $row['lifecycle_type'] = 'Standard';
            $row['has_configurations'] = 0;
            $row['configurations'] = null;
            $row['billing_unit'] = 'Hourly';
        }
        unset($row);
    }
    $plants = [];
    
    foreach($plantsRaw as $p) { 
        $bcId = $p['billing_company_id'] ?? 'default';
        $catCache = $nominalCache[$bcId] ?? [];
        
        $isValidPricing = in_array($p['pricing_type'], ['fixed_then_hourly', 'per_trip', 'hourly', 'daily']);
        $isFixedReq = in_array($p['pricing_type'], ['fixed_then_hourly', 'per_trip', 'daily']);
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
    header('Content-Type: application/json; charset=utf-8');
    $events = [];
    $startDate = !empty($_GET['start']) ? date('Y-m-d', strtotime($_GET['start'])) : date('Y-m-d', strtotime('-1 month'));
    $endDate = !empty($_GET['end']) ? date('Y-m-d', strtotime($_GET['end'])) : date('Y-m-d', strtotime('+1 month'));

    try {
        $query = "SELECT pb.*, p.name as plant_name, p.category, prj.name as project_name, prj.city as locality 
                  FROM plant_bookings pb 
                  JOIN plants p ON pb.plant_id = p.id 
                  LEFT JOIN projects prj ON pb.project_id = prj.id
                  WHERE pb.booking_date <= ? AND COALESCE(pb.end_date, pb.booking_date) >= ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$endDate, $startDate]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $query = "SELECT pb.*, p.name as plant_name, p.category, prj.name as project_name, prj.city as locality 
                  FROM plant_bookings pb 
                  JOIN plants p ON pb.plant_id = p.id 
                  LEFT JOIN projects prj ON pb.project_id = prj.id
                  WHERE pb.booking_date <= ? AND pb.booking_date >= ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$endDate, $startDate]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $catColors = [
        'Cranes' => '#eab308', 'Pumps' => '#3b82f6', 'Booms' => '#f97316', 
        'Excavator' => '#ef4444', 'Piling' => '#8b5cf6', 'Drum Cutter' => '#14b8a6', 
        'Rock Saw' => '#10b981', 'Other Trucks' => '#64748b', 'Scarifier' => '#ec4899', 'General' => '#6366f1'
    ];

    foreach ($bookings as $b) {
        $cat = $b['category'] ?: 'General';
        $color = $catColors[$cat] ?? '#6366f1';
        
        $details = [];
        if (!empty($b['project_name'])) {
            $details[] = $b['project_name'];
            if (!empty($b['locality'])) { $details[] = $b['locality']; }
            if (!empty($b['client_name'])) { $details[] = $b['client_name']; }
        } else {
            if (!empty($b['client_name'])) { $details[] = $b['client_name']; }
            if (!empty($b['comments'])) { $details[] = '"' . substr($b['comments'], 0, 40) . '..."'; }
        }
        
        $statusInd = "";
        if ($b['status'] == 'Completed') {
            if ((float)$b['final_subtotal'] > 0 || in_array($b['payment_status'], ['Invoiced', 'Settled'])) {
                $statusInd = "🧾 ";
            } else {
                $statusInd = "✅ ";
            }
        } elseif ($b['status'] == 'In Progress') {
            $statusInd = "⏳ ";
        } elseif ($b['status'] == 'Paused') {
            $statusInd = "⏸️ ";
        }
        
        $baseTitle = $statusInd . $b['plant_name'];
        if (!empty($details)) {
            $baseTitle .= "\n" . implode(" | ", $details);
        }

        $sTime = !empty($b['start_time']) ? $b['start_time'] : '08:00:00';
        $eTime = !empty($b['end_time']) ? $b['end_time'] : '17:00:00';
        
        $jobStart = $b['booking_date'];
        $jobEnd = !empty($b['end_date']) ? $b['end_date'] : $b['booking_date'];

        // SAFE DATE PARSING
        $unixStart = strtotime($jobStart);
        $unixEnd = strtotime($jobEnd);
        if (!$unixStart || !$unixEnd || $unixEnd < $unixStart) {
            $jobEnd = $jobStart;
        }

        try {
            $currentDate = new DateTime($jobStart);
            $lastDate = new DateTime($jobEnd);
            $lastDate->modify('+1 day'); // Include the final day in the loop
            $period = new DatePeriod($currentDate, new DateInterval('P1D'), $lastDate);
        } catch (Exception $e) {
            continue; 
        }
        
        foreach ($period as $dt) {
            $dateStr = $dt->format('Y-m-d');
            $startIso = $dateStr . 'T' . $sTime;
            
            if (strtotime($eTime) < strtotime($sTime)) {
                $endIso = date('Y-m-d', strtotime($dateStr . ' +1 day')) . 'T' . $eTime;
            } else {
                $endIso = $dateStr . 'T' . $eTime;
            }

            // Apply the Setup Fee badge ONLY to the very first day of the booking
            $dailyTitle = $baseTitle;
            if ($dateStr === $jobStart && isset($b['apply_setup_fee']) && $b['apply_setup_fee'] == 1) {
                $dailyTitle = str_replace($b['plant_name'], $b['plant_name'] . " [SETUP FEE]", $baseTitle);
            }

            $actualTimeStr = '';
            if ($b['status'] == 'Completed') {
                if (!empty($b['punch_in_time']) && !empty($b['punch_out_time'])) {
                    $actualTimeStr = date('H:i', strtotime($b['punch_in_time'])) . ' - ' . date('H:i', strtotime($b['punch_out_time']));
                } else {
                    $actualTimeStr = date('H:i', strtotime($sTime)) . ' - ' . date('H:i', strtotime($eTime));
                }
            }
            
            $events[] = [
                'id' => $b['id'], 
                'title' => $dailyTitle,
                'start' => $startIso, 
                'end' => $endIso, 
                'backgroundColor' => $color, 
                'borderColor' => $color,
                'extendedProps' => [
                    'actualTime' => $actualTimeStr,
                    'finalValue' => (float)$b['final_subtotal']
                ]
            ];
        }
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

    $endDateStr = !empty($_POST['end_date']) ? $_POST['end_date'] : $_POST['booking_date'];

    $stmt = $pdo->prepare("
        INSERT INTO plant_bookings (plant_id, driver_id, booking_type, project_id, client_name, client_code, location_lat, location_lng, booking_date, end_date, start_time, end_time, comments, apply_setup_fee, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        $endDateStr,
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

    $endDateStr = !empty($_POST['end_date']) ? $_POST['end_date'] : $_POST['booking_date'];

    $stmt = $pdo->prepare("
        UPDATE plant_bookings 
        SET plant_id=?, driver_id=?, booking_type=?, project_id=?, client_name=?, client_code=?, location_lat=?, location_lng=?, booking_date=?, end_date=?, start_time=?, end_time=?, comments=?, apply_setup_fee=? 
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
        $endDateStr,
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
    header('Content-Type: application/json; charset=utf-8');
    $jobId = (int)($_GET['id'] ?? 0);
    if ($jobId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid booking ID.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT pb.*, p.name as plant_name, p.category, p.pricing_type, p.setup_fee, 
                   p.requires_driver, p.lifecycle_type, p.has_configurations, p.configurations,
                   prj.name as project_name, u.first_name as driver_first, u.last_name as driver_last 
            FROM plant_bookings pb 
            JOIN plants p ON pb.plant_id = p.id 
            LEFT JOIN projects prj ON pb.project_id = prj.id 
            LEFT JOIN users u ON pb.driver_id = u.id 
            WHERE pb.id = ?
        ");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $pdo->prepare("
            SELECT pb.*, p.name as plant_name, p.category, p.pricing_type, p.setup_fee,
                   prj.name as project_name, u.first_name as driver_first, u.last_name as driver_last 
            FROM plant_bookings pb 
            JOIN plants p ON pb.plant_id = p.id 
            LEFT JOIN projects prj ON pb.project_id = prj.id 
            LEFT JOIN users u ON pb.driver_id = u.id 
            WHERE pb.id = ?
        ");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($job) {
            $job['requires_driver'] = 1;
            $job['lifecycle_type'] = 'Standard';
            $job['has_configurations'] = 0;
            $job['configurations'] = null;
        }
    }

    if (!$job) {
        http_response_code(404);
        echo json_encode(['error' => 'Booking not found.']);
        exit;
    }

    $job['location_text'] = $job['booking_type'] == 'in-house'
        ? 'Project: ' . ($job['project_name'] ?? 'Unknown')
        : 'External: ' . ($job['client_name'] ?? 'Unknown');

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
    $activeMode = !empty($_POST['active_mode']) ? $_POST['active_mode'] : null;
    $activeAddons = !empty($_POST['active_addons']) ? $_POST['active_addons'] : null;

    if ($lat && $lng) {
        $stmt = $pdo->prepare("UPDATE plant_bookings SET status='In Progress', punch_in_time=?, driver_id=COALESCE(driver_id, ?), location_lat=?, location_lng=?, active_mode=?, active_addons=? WHERE id=?");
        $stmt->execute([$punchTime, $userId, $lat, $lng, $activeMode, $activeAddons, $bookingId]); 
    } else {
        $stmt = $pdo->prepare("UPDATE plant_bookings SET status='In Progress', punch_in_time=?, driver_id=COALESCE(driver_id, ?), active_mode=?, active_addons=? WHERE id=?");
        $stmt->execute([$punchTime, $userId, $activeMode, $activeAddons, $bookingId]); 
    }
    logPlantAction($pdo, $userId, 'JOB_STARTED', "Driver punched in.", $bookingId);
    echo "OK"; 
    exit; 
}

if ($action == 'pause_job') {
    $punchOut = date('Y-m-d H:i:s');
    $bookingId = $_POST['id'];

    // FIXED: Now fetching active_mode and active_addons
    $stmt = $pdo->prepare("SELECT punch_in_time, active_mode, active_addons FROM plant_bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    // Save the daily session
    if (!empty($job['punch_in_time'])) {
        $inTime = new DateTime($job['punch_in_time']);
        $outTime = new DateTime($punchOut);
        $interval = $inTime->diff($outTime);
        $hours = round($interval->h + ($interval->i / 60), 2);

        // FIXED: Replaced undefined $punchOutTime with $punchOut
        $pdo->prepare("INSERT INTO plant_job_sessions (booking_id, punch_in, punch_out, hours, mode_name, addons_used) VALUES (?, ?, ?, ?, ?, ?)")->execute([$bookingId, $job['punch_in_time'], $punchOut, $hours, $job['active_mode'], $job['active_addons']]);
    }

    $pdo->prepare("UPDATE plant_bookings SET status='Paused', punch_in_time=NULL WHERE id=?")->execute([$bookingId]);
    logPlantAction($pdo, $userId, 'JOB_PAUSED', "Driver paused the excavator job for the day", $bookingId);
    echo "OK";
    exit;
}

if ($action == 'punch_out_complete') {
    $punchTime = date('Y-m-d H:i:s');
    $bookingId = $_POST['id'];
    
    // Log the final session - FIXED: Now pulling active_mode and active_addons
    $stmt = $pdo->prepare("SELECT punch_in_time, active_mode, active_addons FROM plant_bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($job['punch_in_time'])) {
        $inTime = new DateTime($job['punch_in_time']);
        $outTime = new DateTime($punchTime);
        $interval = $inTime->diff($outTime);
        $hours = round($interval->h + ($interval->i / 60), 2);

        // FIXED: Replaced $punchOutTime with $punchTime to eliminate log crashes
        $pdo->prepare("INSERT INTO plant_job_sessions (booking_id, punch_in, punch_out, hours, mode_name, addons_used) VALUES (?, ?, ?, ?, ?, ?)")->execute([$bookingId, $job['punch_in_time'], $punchTime, $hours, $job['active_mode'], $job['active_addons']]);
    }

    $deliveryChit = trim((string)($_POST['delivery_chit_number'] ?? ''));

    $stmt = $pdo->prepare("
        UPDATE plant_bookings 
        SET status='Completed', punch_out_time=?, qty_trips=?, delivery_chit_number=?, client_rep_name=?, client_rep_id_card=?, signature_data=?, punch_in_time=NULL 
        WHERE id=?
    ");
    
    $stmt->execute([
        $punchTime,
        empty($_POST['qty_trips']) ? null : $_POST['qty_trips'], 
        $deliveryChit !== '' ? $deliveryChit : null,
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
    } elseif ($job['pricing_type'] == 'daily') {
        $qty = round((float)$finalHours > 0 ? (float)$finalHours : 1, 2); 
        $backendSubtotal += round($qty * $syncPriceFixed, 2);
    } else {
        $cfgs = ($job['has_configurations'] == 1 && !empty($job['configurations'])) ? json_decode($job['configurations'], true) : null;
        
        $sessStmt = $pdo->prepare("SELECT * FROM plant_job_sessions WHERE booking_id = ?");
        $sessStmt->execute([$bookingId]);
        $sessions = $sessStmt->fetchAll(PDO::FETCH_ASSOC);

        if (is_array($cfgs) && count($sessions) > 0) {
            $modeBreakdown = []; $addonBreakdown = [];
            foreach ($sessions as $s) {
                $mName = !empty($s['mode_name']) ? $s['mode_name'] : 'Standard Operation';
                if (!isset($modeBreakdown[$mName])) $modeBreakdown[$mName] = 0;
                $modeBreakdown[$mName] += (float)$s['hours'];
                
                if (!empty($s['addons_used'])) {
                    $sAddons = json_decode($s['addons_used'], true);
                    if (is_array($sAddons)) {
                        foreach ($sAddons as $sa) {
                            $saName = $sa['name']; $saQty = (int)$sa['qty'];
                            if ($saQty > 0) {
                                if (!isset($addonBreakdown[$saName])) $addonBreakdown[$saName] = 0;
                                $addonBreakdown[$saName] += ($saQty * (float)$s['hours']);
                            }
                        }
                    }
                }
            }
            foreach ($modeBreakdown as $mName => $mHours) {
                $matchedCfg = null;
                foreach ($cfgs as $c) { if ($c['name'] === $mName && $c['type'] === 'mode') { $matchedCfg = $c; break; } }
                if ($matchedCfg) {
                    $mCode = trim($matchedCfg['nom_code']); $erpRate = 0;
                    if (!empty($allNominals)) {
                        foreach($allNominals as $n) { if (trim($n['NCCode']) === $mCode) { $erpRate = $isInternal ? $n['NCDefSP1'] : $n['NCDefSP2']; break; } }
                    }
                    $backendSubtotal += round(round($mHours, 2) * ($erpRate > 0 ? $erpRate : (float)$matchedCfg['price']), 2);
                } else { $backendSubtotal += round(round($mHours, 2) * $syncPriceVar, 2); }
            }
            foreach ($addonBreakdown as $saName => $saQtyHours) {
                $matchedCfg = null;
                foreach ($cfgs as $c) { if ($c['name'] === $saName && $c['type'] === 'addon') { $matchedCfg = $c; break; } }
                if ($matchedCfg) {
                    $aCode = trim($matchedCfg['nom_code']); $erpRate = 0;
                    if (!empty($allNominals)) {
                        foreach($allNominals as $n) { if (trim($n['NCCode']) === $aCode) { $erpRate = $isInternal ? $n['NCDefSP1'] : $n['NCDefSP2']; break; } }
                    }
                    $backendSubtotal += round(round($saQtyHours, 2) * ($erpRate > 0 ? $erpRate : (float)$matchedCfg['price']), 2);
                }
            }
        } else { $backendSubtotal += round(round($finalHours, 2) * $syncPriceVar, 2); }
    }

    $deliveryChitNumber = trim((string)($_POST['delivery_chit_number'] ?? ''));
    $stmtLocal = $pdo->prepare("UPDATE plant_bookings SET final_hours=?, final_subtotal=?, final_rate_fixed=?, final_rate_var=?, final_setup_fee=?, final_discount_pct=?, delivery_chit_number=?, payment_status='Invoiced' WHERE id=?");
    $stmtLocal->execute([$finalHours, $backendSubtotal, $syncPriceFixed, $syncPriceVar, $syncSetupPrice, $customDiscountPct, $deliveryChitNumber !== '' ? $deliveryChitNumber : null, $bookingId]);
    
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

function extractCoordsFromMapUrl($url) {
    $patterns = [
        '/!3d(-?\d+\.?\d*)!4d(-?\d+\.?\d*)/',
        '/@(-?\d+\.?\d*),(-?\d+\.?\d*)/',
        '/[?&](?:query|q)=(-?\d+\.?\d*)%2C(-?\d+\.?\d*)/i',
        '/[?&](?:query|q)=(-?\d+\.?\d*),(-?\d+\.?\d*)/i',
        '/[?&]ll=(-?\d+\.?\d*),(-?\d+\.?\d*)/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return ['lat' => (float)$matches[1], 'lng' => (float)$matches[2]];
        }
    }
    return null;
}

function isAllowedGoogleMapsHost($host) {
    $host = strtolower(trim($host ?? ''));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
        return false;
    }

    static $allowedExact = [
        'maps.app.goo.gl',
        'goo.gl',
        'maps.google.com',
        'www.google.com',
        'google.com',
        'www.google.com.mt',
        'google.com.mt',
    ];
    if (in_array($host, $allowedExact, true)) {
        return true;
    }

    return (bool)preg_match('/^[a-z0-9-]+\.google\.(com|com\.mt)$/', $host);
}

function isAllowedGoogleMapsUrl($url) {
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }

    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return false;
    }

    if (!isAllowedGoogleMapsHost($parts['host'])) {
        return false;
    }

    if (strtolower($parts['host']) === 'goo.gl') {
        $path = $parts['path'] ?? '';
        if ($path === '' || $path[0] !== '/' || strncmp($path, '/maps', 5) !== 0) {
            return false;
        }
    }

    return true;
}

function resolveRedirectTarget($baseUrl, $locationHeader) {
    $locationHeader = trim($locationHeader ?? '');
    if ($locationHeader === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $locationHeader)) {
        return $locationHeader;
    }

    $baseParts = parse_url($baseUrl);
    if (!$baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return null;
    }

    if ($locationHeader[0] === '/') {
        return $baseParts['scheme'] . '://' . $baseParts['host'] . $locationHeader;
    }

    $basePath = $baseParts['path'] ?? '/';
    $directory = rtrim(substr($basePath, 0, strrpos($basePath . '/', '/')), '/');
    return $baseParts['scheme'] . '://' . $baseParts['host'] . $directory . '/' . $locationHeader;
}

function extractRedirectLocation($responseHeaders) {
    if (!is_string($responseHeaders) || $responseHeaders === '') {
        return null;
    }

    if (preg_match('/^Location:\s*(.+)$/im', $responseHeaders, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function resolveGoogleMapsUrlSafely($startUrl, $maxRedirects = 5) {
    if (!isAllowedGoogleMapsUrl($startUrl)) {
        return null;
    }

    $currentUrl = $startUrl;
    for ($attempt = 0; $attempt <= $maxRedirects; $attempt++) {
        $coords = extractCoordsFromMapUrl($currentUrl);
        if ($coords) {
            return $coords;
        }

        $ch = curl_init($currentUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_USERAGENT, 'EstateHubMalta/1.0 (Plant Hub Map Resolver)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($httpCode < 300 || $httpCode >= 400) {
            return null;
        }

        if (empty($redirectUrl)) {
            $redirectUrl = extractRedirectLocation($response);
            $redirectUrl = resolveRedirectTarget($currentUrl, $redirectUrl);
        }

        if (empty($redirectUrl) || !isAllowedGoogleMapsUrl($redirectUrl)) {
            return null;
        }

        $currentUrl = $redirectUrl;
    }

    return extractCoordsFromMapUrl($currentUrl);
}

function fetchNominatimJson($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'EstateHubMalta/1.0 (Plant Hub Booking Geocoder)');
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300 || !$response) {
        return [];
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : [];
}

if ($action == 'geocode_search' && $isManager) {
    header('Content-Type: application/json');
    $query = trim($_GET['q'] ?? '');
    if (strlen($query) < 3) {
        echo json_encode([]);
        exit;
    }

    $searchUrl = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'format' => 'json',
        'q' => $query,
        'countrycodes' => 'mt',
        'limit' => 6,
        'addressdetails' => 1,
    ]);

    $results = [];
    foreach (fetchNominatimJson($searchUrl) as $row) {
        if (!isset($row['lat'], $row['lon'])) {
            continue;
        }

        $label = trim($row['display_name'] ?? '');
        if ($label === '') {
            continue;
        }

        $results[] = [
            'lat' => (float)$row['lat'],
            'lng' => (float)$row['lon'],
            'label' => $label,
        ];
    }

    echo json_encode($results);
    exit;
}

if ($action == 'resolve_map_url' && $isManager) {
    header('Content-Type: application/json');
    $url = trim($_GET['url'] ?? '');

    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        echo json_encode(['error' => 'Invalid URL']);
        exit;
    }

    if (!isAllowedGoogleMapsUrl($url)) {
        echo json_encode(['error' => 'Only Google Maps links are supported']);
        exit;
    }

    $coords = extractCoordsFromMapUrl($url);
    if (!$coords) {
        $coords = resolveGoogleMapsUrlSafely($url);
    }

    if (!$coords) {
        echo json_encode(['error' => 'Could not extract coordinates from link. Try copying coordinates directly.']);
        exit;
    }

    echo json_encode($coords);
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
