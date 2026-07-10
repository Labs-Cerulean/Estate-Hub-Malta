<?php
require_once 'init.php';

// --- ENTERPRISE CRON BYPASS LOGIC ---
$providedToken = $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
$expectedToken = getenv('CRON_SECRET_TOKEN');
$isCron = (!empty($expectedToken) && hash_equals($expectedToken, $providedToken));

if (!$isCron) {
    require_once 'session-check.php';
}

require_once 'user-functions.php';
require_once 'S3FileManager.php';
require_once __DIR__ . '/includes/plant_schema_deploy.php';

plantDeploySchema($pdo);

$role = $_SESSION['role'] ?? '';
$isAdmin = ($role === 'admin');
$canDiscount = in_array($role, ['admin', 'system_manager', 'accountant']);
$hasPlantAccess = in_array($role, ['admin', 'director', 'system_manager', 'accountant', 'plant_manager', 'plant_driver']);

if (!$isCron && !$hasPlantAccess && !hasPermission('view_plant_bookings')) {
    die("Unauthorized Access to Invoice.");
}

$bookingId = (int)($_GET['booking_id'] ?? 0);

try {
    $stmt = $pdo->prepare("
        SELECT pb.*, p.name as plant_name, p.registration_plate, p.category,
               p.pricing_type, p.min_hours, p.nom_code_fixed, p.nom_code_variable, p.billing_company_id,
               p.setup_fee, p.nom_code_setup, p.requires_driver, p.lifecycle_type, p.has_configurations, p.configurations,
               bc.name as developer_name, bc.logo_path as developer_logo, 
               bc.bank_name, bc.iban, bc.swift_bic, 
               prj.name as project_name,
               drv.first_name, drv.last_name
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id
        LEFT JOIN clients bc ON p.billing_company_id = bc.id
        LEFT JOIN projects prj ON pb.project_id = prj.id
        LEFT JOIN users drv ON pb.driver_id = drv.id
        WHERE pb.id = ?
    ");
    $stmt->execute([$bookingId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stmt = $pdo->prepare("
        SELECT pb.*, p.name as plant_name, p.registration_plate, p.category,
               p.pricing_type, p.min_hours, p.nom_code_fixed, p.nom_code_variable, p.billing_company_id,
               p.setup_fee, p.nom_code_setup,
               bc.name as developer_name, bc.logo_path as developer_logo, 
               bc.bank_name, bc.iban, bc.swift_bic, 
               prj.name as project_name,
               drv.first_name, drv.last_name
        FROM plant_bookings pb 
        JOIN plants p ON pb.plant_id = p.id
        LEFT JOIN clients bc ON p.billing_company_id = bc.id
        LEFT JOIN projects prj ON pb.project_id = prj.id
        LEFT JOIN users drv ON pb.driver_id = drv.id
        WHERE pb.id = ?
    ");
    $stmt->execute([$bookingId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($job) {
        $job['requires_driver'] = 1;
        $job['lifecycle_type'] = 'Standard';
        $job['has_configurations'] = 0;
        $job['configurations'] = null;
    }
}

if (!$job) die("Job not found.");

$billingCompanyMissing = empty($job['billing_company_id']) || empty($job['developer_name']);
$billingCompanyLabel = $job['developer_name'] ?? 'Billing company not assigned';
$billingCompanyId = !empty($job['billing_company_id']) ? (string)$job['billing_company_id'] : 'default';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

$praApiKey = getenv('J2_API_KEY_PRA');
$praxApiKey = getenv('J2_API_KEY_PRAX');
$erpAvailable = !empty($praApiKey) && !empty($praxApiKey);

$apiKeys = [
    '24' => $praApiKey ?: '',
    '26' => $praxApiKey ?: '',
    'default' => $praApiKey ?: ''
];
$apiKey = $apiKeys[$billingCompanyId] ?? $apiKeys['default'];

if (!function_exists('getJ2ApiData')) {
    function getJ2ApiData($endpoint, $apiKey) {
        $url = "https://j2api.agiusgroup.com/api/public" . $endpoint;
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
            $decoded = json_decode($response, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}

$allNominals = $erpAvailable ? getJ2ApiData('/nominalcateg', $apiKey) : [];
$fixedNom = null; 
$varNom = null;

if (!empty($allNominals)) {
    foreach($allNominals as $n) {
        if (!empty($job['nom_code_fixed']) && trim($n['NCCode']) == trim($job['nom_code_fixed'])) $fixedNom = $n;
        if (!empty($job['nom_code_variable']) && trim($n['NCCode']) == trim($job['nom_code_variable'])) $varNom = $n;
    }
}

$isInternal = ($job['booking_type'] == 'in-house');

$s3 = new S3FileManager();
$logoPath = $job['developer_logo'] ?? null;
if (!empty($logoPath) && strpos($logoPath, 'http') === false) {
    try {
        $logoPath = $s3->getPresignedUrl($logoPath, '+60 minutes');
    } catch (Exception $e) {
        $logoPath = null;
    }
}

$prefix = ($billingCompanyId === '26') ? 'PRAX' : 'PRA';
$jobYear = date('Y', strtotime($job['booking_date']));
$jobRef = sprintf("%s-%s-%04d", $prefix, $jobYear, $bookingId);

$sessions = [];
try {
    $sessionsStmt = $pdo->prepare("SELECT * FROM plant_job_sessions WHERE booking_id = ? ORDER BY punch_in ASC");
    $sessionsStmt->execute([$bookingId]);
    $sessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sessions = [];
}

$totalSessionHours = 0;
foreach ($sessions as $s) {
    $totalSessionHours += (float)$s['hours'];
}

$activeSessionHours = 0;
if ($job['status'] === 'In Progress' && !empty($job['punch_in_time'])) {
    $activeIn = new DateTime($job['punch_in_time']);
    $activeOut = new DateTime(); 
    $activeInterval = $activeIn->diff($activeOut);
    $activeSessionHours = round($activeInterval->h + ($activeInterval->i / 60), 2);
}

$inTime = !empty($job['punch_in_time']) ? new DateTime($job['punch_in_time']) : new DateTime($job['booking_date'] . ' ' . $job['start_time']);
$outTime = !empty($job['punch_out_time']) ? new DateTime($job['punch_out_time']) : new DateTime($job['booking_date'] . ' ' . $job['end_time']);
$interval = $inTime->diff($outTime);
$legacyHoursWorked = round($interval->h + ($interval->i / 60), 2);

if (count($sessions) > 0) {
    $hoursWorked = $totalSessionHours + $activeSessionHours;
} else {
    $hoursWorked = $legacyHoursWorked;
}

// --- MULTI-MODE & ADD-ON BILLING BREAKDOWN ---
$modeBreakdown = [];
$addonBreakdown = [];
if ($job['has_configurations'] == 1 && !empty($job['configurations']) && count($sessions) > 0) {
    $cfgs = json_decode($job['configurations'], true);
    
    foreach ($sessions as $s) {
        // 1. Group Primary Operational Modes
        $mName = !empty($s['mode_name']) ? $s['mode_name'] : 'Standard Operation';
        if (!isset($modeBreakdown[$mName])) {
            $modeBreakdown[$mName] = ['hours' => 0, 'nom_code' => '', 'rate' => 0];
        }
        $modeBreakdown[$mName]['hours'] += (float)$s['hours'];

        // 2. Group Simultaneous Extra Add-ons
        if (!empty($s['addons_used'])) {
            $sAddons = json_decode($s['addons_used'], true);
            if (is_array($sAddons)) {
                foreach ($sAddons as $sa) {
                    $saName = $sa['name'];
                    $saQty = (int)$sa['qty'];
                    if ($saQty > 0) {
                        if (!isset($addonBreakdown[$saName])) {
                            $addonBreakdown[$saName] = ['qty_hours' => 0, 'nom_code' => '', 'rate' => 0];
                        }
                        $addonBreakdown[$saName]['qty_hours'] += ($saQty * (float)$s['hours']);
                    }
                }
            }
        }
    }
    
    foreach ($modeBreakdown as $mName => &$data) {
        $matchedCfg = null;
        if (is_array($cfgs)) {
            foreach ($cfgs as $c) { if ($c['name'] === $mName && $c['type'] === 'mode') { $matchedCfg = $c; break; } }
        }
        if ($matchedCfg) {
            $data['nom_code'] = $matchedCfg['nom_code'];
            $nCodeTrim = trim($matchedCfg['nom_code']); $erpRate = 0;
            if (!empty($allNominals)) {
                foreach($allNominals as $n) { if (trim($n['NCCode']) === $nCodeTrim) { $erpRate = $isInternal ? $n['NCDefSP1'] : $n['NCDefSP2']; break; } }
            }
            $data['rate'] = $erpRate > 0 ? $erpRate : (float)$matchedCfg['price'];
        } else {
            $data['nom_code'] = $job['nom_code_variable'];
            $data['rate'] = isset($job['final_rate_var']) ? $job['final_rate_var'] : ($varNom ? ($isInternal ? $varNom['NCDefSP1'] : $varNom['NCDefSP2']) : 0);
        }
    }
    unset($data);

    foreach ($addonBreakdown as $saName => &$data) {
        $matchedCfg = null;
        if (is_array($cfgs)) {
            foreach ($cfgs as $c) { if ($c['name'] === $saName && $c['type'] === 'addon') { $matchedCfg = $c; break; } }
        }
        if ($matchedCfg) {
            $data['nom_code'] = $matchedCfg['nom_code'];
            $nCodeTrim = trim($matchedCfg['nom_code']); $erpRate = 0;
            if (!empty($allNominals)) {
                foreach($allNominals as $n) { if (trim($n['NCCode']) === $nCodeTrim) { $erpRate = $isInternal ? $n['NCDefSP1'] : $n['NCDefSP2']; break; } }
            }
            $data['rate'] = $erpRate > 0 ? $erpRate : (float)$matchedCfg['price'];
        }
    }
    unset($data);
}

$jobStart = new DateTime($job['booking_date']);
$jobEnd = !empty($job['end_date']) ? new DateTime($job['end_date']) : clone $jobStart;
$diffDays = $jobStart->diff($jobEnd)->days + 1;

$isTripBased = ($job['pricing_type'] == 'per_trip');
$isDailyBased = ($job['pricing_type'] == 'daily');

if ($isTripBased) {
    $qtyValue = ($job['qty_trips'] > 0) ? $job['qty_trips'] : 1;
    $qtyLabel = "Trips Executed";
} elseif ($isDailyBased) {
    $qtyValue = (isset($job['final_hours']) && $job['final_hours'] > 0) ? $job['final_hours'] : $diffDays;
    $qtyLabel = "Total Days Billed";
} else {
    $qtyValue = $hoursWorked;
    $qtyLabel = "Total Hours Executed";
}

$clientDisplay = !empty($job['client_name']) ? htmlspecialchars($job['client_name']) : 'N/A';
$clientCodeDisplay = !empty($job['client_code']) ? htmlspecialchars($job['client_code']) : 'MISSING CODE';

if ($job['booking_type'] == 'in-house') {
    $projectDisplay = !empty($job['project_name']) ? htmlspecialchars($job['project_name']) : 'N/A';
} else {
    if (!empty($job['location_lat']) && !empty($job['location_lng']) && function_exists('getAddressFromCoordinates')) {
        $address = getAddressFromCoordinates($job['location_lat'], $job['location_lng']);
        $projectDisplay = $address ? htmlspecialchars($address) : 'Lat: ' . round($job['location_lat'], 4) . ', Lng: ' . round($job['location_lng'], 4);
    } else {
        $projectDisplay = 'External Location';
    }
}

$reqDriver = (int)($job['requires_driver'] ?? 1);
if ($reqDriver === 0) {
    $driverName = "<span style='color:#64748b; font-style:italic;'><i class='fas fa-robot'></i> Not Required (Static)</span>";
} else {
    $driverRaw = trim(($job['driver_first'] ?? 'Unassigned') . ' ' . ($job['driver_last'] ?? ''));
    $driverName = htmlspecialchars($driverRaw);
}

$sysRef = $job['invoice_sysref'] ?? '';
$isSynced = !empty($sysRef) && !in_array($sysRef, ['N/A', 'SUCCESS_NO_REF']);
$canEdit = (isset($_GET['readonly']) && $_GET['readonly'] == '1') ? false : (($job['payment_status'] === 'Pending') || ($isAdmin && !$isSynced));
$savedDiscountPct = isset($job['final_discount_pct']) ? (float)$job['final_discount_pct'] : 0.00;
?>

<!DOCTYPE html>
<html lang="mt">
<head>
    <meta charset="UTF-8">
    <title>RFP / Delivery Note - <?= $jobRef ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', 'DejaVu Sans', 'Segoe UI', 'Noto Sans', sans-serif; background: #fff; color: #000; padding: 40px; font-size: 0.95rem; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 20px; margin-bottom: 25px; }
        .logo { max-width: 200px; max-height: 80px; object-fit: contain; }
        .title { font-size: 1.8rem; font-weight: 900; text-transform: uppercase; color: #0f172a; }
        .grid { display: flex; justify-content: space-between; gap: 20px; margin-bottom: 30px; }
        .box { padding: 15px; border: 1px solid #cbd5e1; border-radius: 8px; flex: 1; background: #f8fafc; }
        .box h4 { margin-top: 0; margin-bottom: 10px; color: #3b82f6; text-transform: uppercase; font-size: 0.85rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px; }
        .data-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
        .data-label { font-weight: 600; color: #475569; }
        .data-val { font-weight: 700; text-align: right; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 25px; border: 1px solid #cbd5e1; }
        th, td { border-bottom: 1px solid #cbd5e1; padding: 12px; text-align: left; }
        th { background: #f1f5f9; color: #475569; font-weight: 700; text-transform: uppercase; font-size: 0.85rem; }
        .text-right { text-align: right; }
        
        .totals-box { width: 300px; float: right; border: 1px solid #cbd5e1; border-radius: 8px; padding: 15px; background: #f8fafc; }
        .totals-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 1.1rem; }
        .totals-final { border-top: 2px solid #000; padding-top: 8px; margin-top: 8px; font-size: 1.4rem; font-weight: 900; }
        
        .live-calc { border: 1px solid #cbd5e1; padding: 3px 5px; font-size: 1rem; font-family: inherit; border-radius: 6px; width: 80px; font-weight: bold; text-align: center; }
        .live-calc:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        
        @media print { 
            .no-print { display: none; } 
            body { padding: 0; font-size: 10px; } 
            .live-calc { border: none; padding: 0; text-align: left; background: transparent; width: auto; font-size: inherit; }
            .box { background: transparent; }
            .totals-box { background: transparent; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="background: #f8fafc; border: 1px solid #cbd5e1; padding: 15px; border-radius: 8px; margin-bottom: 30px; color: #475569;">
        <?php if ($billingCompanyMissing): ?>
            <div style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:12px 15px; border-radius:8px; margin-bottom:15px;">
                <i class="fas fa-exclamation-triangle"></i>
                <b>Billing company not configured</b> on this plant asset.
                Assign a billing company in Fleet setup before pushing to ERP. You can still view or print this delivery note locally.
            </div>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                <div><i class="fas fa-edit text-blue-500"></i> <b>Edit Mode:</b> You can adjust the times, quantities, and rates below before pushing the final RFP to the ERP.</div>
                <?php if ($erpAvailable): ?>
                <div style="background:#e0e7ff; color:#4f46e5; padding:5px 10px; border-radius:6px; font-weight:bold;"><i class="fas fa-plug"></i> ERP Live Sync</div>
                <?php else: ?>
                <div style="background:#fef3c7; color:#b45309; padding:5px 10px; border-radius:6px; font-weight:bold;"><i class="fas fa-exclamation-triangle"></i> ERP Offline — local RFP view only</div>
                <?php endif; ?>
            </div>
            
            <div style="background: #fff; padding: 15px; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <label style="font-weight:bold; margin:0;">Final <?= $qtyLabel ?>:</label>
                    <input type="number" id="calc_master_qty" class="live-calc" value="<?= $qtyValue ?>" step="0.25" oninput="renderTable()">
                </div>
                
                <?php if ($canDiscount): ?>
                <div style="border-left: 2px solid #e2e8f0; padding-left: 15px; display: flex; align-items: center; gap: 10px;">
                    <label style="font-weight:bold; color:#ef4444; margin:0;"><i class="fas fa-tag"></i> Discount %:</label>
                    <input type="number" id="edit_discount_pct" class="live-calc" value="<?= $savedDiscountPct ?>" step="0.1" min="0" style="border-color:#fca5a5; color:#ef4444;" onchange="validateAndRenderDiscount()">
                    <span id="max_disc_label" style="font-size:0.8rem; color:#94a3b8;">(Max: Loading ERP...)</span>
                </div>
                <?php else: ?>
                    <input type="hidden" id="edit_discount_pct" value="<?= $savedDiscountPct ?>">
                <?php endif; ?>

                <div style="border-left: 2px solid #e2e8f0; padding-left: 15px; display: flex; align-items: center; gap: 10px;">
                    <label style="font-weight:bold; margin:0;"><i class="fas fa-receipt"></i> Chit #:</label>
                    <input type="text" id="edit_delivery_chit_number" class="live-calc" style="width:120px; text-align:left;" maxlength="40" value="<?= htmlspecialchars($job['delivery_chit_number'] ?? '') ?>" placeholder="optional">
                </div>

                <button id="printBtn" onclick="saveAndPrint()" <?= $billingCompanyMissing ? 'disabled title="Assign a billing company on the plant asset first"' : '' ?> style="padding:10px 20px; background:<?= $billingCompanyMissing ? '#94a3b8' : '#10b981' ?>; color:#fff; border:none; font-weight:bold; cursor:<?= $billingCompanyMissing ? 'not-allowed' : 'pointer' ?>; border-radius: 8px; margin-left: auto;"><i class="fas fa-cloud-upload-alt"></i> Save RFP & Push to ERP</button>
            </div>
        <?php else: ?>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <?php if (empty($job['invoice_sysref']) || in_array($job['invoice_sysref'], ['N/A', 'SUCCESS_NO_REF'])): ?>
                    <div>
                        <i class="fas fa-exclamation-triangle" style="color: #f59e0b;"></i> <b style="color: #b45309;">RFP Finalised - Local Only.</b><br>
                        <span style="color: #475569; font-size: 0.9rem;">Manual ERP Invoice Generation Required.</span>
                    </div>
                <?php else: ?>
                    <div>
                        <i class="fas fa-lock" style="color: #475569;"></i> <b>Invoice Locked & Synced.</b> <br>
                        ERP Reference: <b><span style="color:#10b981;"><?= htmlspecialchars($job['invoice_sysref']) ?></span></b>
                    </div>
                <?php endif; ?>
                <button onclick="window.print()" style="padding:10px 20px; background:#64748b; color:#fff; border:none; font-weight:bold; cursor:pointer; border-radius: 8px;"><i class="fas fa-print"></i> Re-Print PDF</button>
            </div>
        <?php endif; ?>
    </div>

    <div class="header">
        <div>
            <?php if (!empty($logoPath)): ?>
                <img src="<?= htmlspecialchars($logoPath) ?>" class="logo">
            <?php else: ?>
                <h2 style="margin:0;"><?= htmlspecialchars($billingCompanyLabel) ?></h2>
            <?php endif; ?>
        </div>
        <div class="text-right">
            <div class="title">Delivery Note / RFP</div>
            <div style="margin-top: 5px; color: #475569;">Date: <b><?= date('d M Y', strtotime($job['booking_date'])) ?></b></div>
            <div style="color: #475569;">Job Ref: <b style="color: #000;"><?= $jobRef ?></b></div>
        </div>
    </div>

    <div class="grid">
        <div class="box">
            <h4>Billed To (Client Details)
                <?php if ($canEdit): ?>
                    <button class="no-print" onclick="openClientEdit()" style="float:right; font-size:0.75rem; background:#e2e8f0; color:#0f172a; border:none; padding:4px 8px; border-radius:4px; cursor:pointer; font-weight:bold;"><i class="fas fa-edit"></i> Edit</button>
                <?php endif; ?>
            </h4>
            
            <div id="client-display-block">
                <div class="data-row"><span class="data-label">ERP Account Name</span><span class="data-val" id="disp_client_name"><?= $clientDisplay ?></span></div>
                <div class="data-row"><span class="data-label">ERP Account Code</span><span class="data-val">
                    <?php if ($clientCodeDisplay === 'TBC'): ?>
                        <span style="background:#fef08a; color:#854d0e; padding:2px 6px; border-radius:4px; font-size:0.8rem;">TBC - Must Update</span>
                    <?php else: ?>
                        <?= $clientCodeDisplay ?>
                    <?php endif; ?>
                </span></div>
                <div class="data-row"><span class="data-label">Project / Location</span><span class="data-val"><?= $projectDisplay ?></span></div>
                <div class="data-row"><span class="data-label">Booking Type</span><span class="data-val" style="text-transform:uppercase;"><?= $job['booking_type'] ?></span></div>
            </div>

            <div id="client-edit-block" class="no-print" style="display:none; position:relative; margin-top:10px; border-top:1px dashed #cbd5e1; padding-top:10px;">
                <label style="font-size:0.8rem; font-weight:bold; color:#475569; display:block; margin-bottom:5px;">Search ERP Client</label>
                <input type="text" id="inv_client_search" class="live-calc" style="width:100%; text-align:left; padding:8px; border:1px solid #cbd5e1;" placeholder="Loading clients..." onkeyup="filterInvClients(this.value)" disabled autocomplete="off">
                <div id="inv_client_results" style="display:none; position:absolute; top:65px; left:0; right:0; background:#fff; border:2px solid #6366f1; z-index:100; max-height:200px; overflow-y:auto; box-shadow:0 10px 25px rgba(0,0,0,0.2); border-radius:8px;"></div>
                <button onclick="cancelClientEdit()" style="margin-top:10px; font-size:0.8rem; padding:6px 12px; background:#64748b; color:#fff; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
            </div>
        </div>
        <div class="box">
            <h4>Job Report (Execution Details)</h4>
            <div class="data-row"><span class="data-label">Machinery</span><span class="data-val"><?= htmlspecialchars($job['plant_name']) ?> (<?= htmlspecialchars($job['category']) ?>)</span></div>
            <div class="data-row"><span class="data-label">Reg Plate</span><span class="data-val"><?= htmlspecialchars($job['registration_plate'] ?? 'N/A') ?></span></div>
            <div class="data-row"><span class="data-label">Driver</span><span class="data-val"><?= $driverName ?></span></div>
            <?php if (!empty($job['delivery_chit_number'])): ?>
            <div class="data-row"><span class="data-label">Delivery Chit</span><span class="data-val"><?= htmlspecialchars($job['delivery_chit_number']) ?></span></div>
            <?php endif; ?>
            
            <div class="data-row" style="align-items: flex-start;">
                <span class="data-label">Time Logged</span>
                <span class="data-val">
                    <?php if ($isDailyBased || $job['lifecycle_type'] === 'Auto-Scheduled'): ?>
                        <div style="font-weight: bold; color: #0f172a;">
                            <?= date('d M Y', strtotime($job['booking_date'])) ?> to <?= !empty($job['end_date']) ? date('d M Y', strtotime($job['end_date'])) : date('d M Y', strtotime($job['booking_date'])) ?>
                        </div>
                        <input type="hidden" id="edit_time_in" value="">
                        <input type="hidden" id="edit_time_out" value="">
                    <?php else: ?>
                        <?php if (count($sessions) > 0): ?>
                            <div style="text-align: right; font-size: 0.85rem; color: #475569;">
                                <?php foreach($sessions as $idx => $s): ?>
                                    <div style="margin-bottom: 3px;">
                                        <b>Day <?= $idx+1 ?>:</b> <?= date('d M, H:i', strtotime($s['punch_in'])) ?> to <?= date('H:i', strtotime($s['punch_out'])) ?> 
                                        <span style="color:#000; font-weight:bold;">(<?= $s['hours'] ?> hrs)</span>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if ($job['status'] === 'In Progress' && !empty($job['punch_in_time'])): ?>
                                    <div style="color:#3b82f6; margin-top: 5px;">
                                        <b><i class="fas fa-clock fa-spin"></i> Active Now:</b> Since <?= date('d M, H:i', strtotime($job['punch_in_time'])) ?>
                                    </div>
                                <?php endif; ?>
                                
                                <input type="hidden" id="edit_time_in" value="">
                                <input type="hidden" id="edit_time_out" value="">
                            </div>
                        <?php else: ?>
                            <?php if ($canEdit && !$isTripBased): ?>
                                <input type="time" id="edit_time_in" class="live-calc" value="<?= $inTime->format('H:i') ?>" onchange="recalcHours()"> to 
                                <input type="time" id="edit_time_out" class="live-calc" value="<?= $outTime->format('H:i') ?>" onchange="recalcHours()">
                            <?php else: ?>
                                <?= $inTime->format('H:i') ?> to <?= $outTime->format('H:i') ?>
                                <input type="hidden" id="edit_time_in" value="<?= $inTime->format('H:i') ?>">
                                <input type="hidden" id="edit_time_out" value="<?= $outTime->format('H:i') ?>">
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <table id="invoice-lines">
        <thead>
            <tr>
                <th style="width: 15%;">ERP Code</th>
                <th style="width: 45%;">Description</th>
                <th class="text-right">Qty / Hrs</th>
                <th class="text-right">Rate (€)</th>
                <th class="text-right">Amount (€)</th>
            </tr>
        </thead>
        <tbody id="lines-body">
        </tbody>
    </table>

    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div style="width: 45%;">
            <?php if ($job['lifecycle_type'] !== 'Auto-Scheduled'): ?>
                <div style="border: 1px solid #cbd5e1; padding: 15px; border-radius: 8px; text-align: center; background: #f8fafc;">
                    <h4 style="margin-top:0; margin-bottom: 10px; color: #475569; text-transform: uppercase; font-size: 0.8rem;">Client Representative Verification</h4>
                    <?php if(!empty($job['signature_data'])): ?>
                        <img src="<?= htmlspecialchars($job['signature_data'], ENT_QUOTES, 'UTF-8') ?>" style="max-width: 100%; height: 80px; object-fit: contain;">
                    <?php else: ?>
                        <div style="height: 80px; line-height:80px; color: #94a3b8; font-style:italic;">No Signature on File</div>
                    <?php endif; ?>
                    <div style="border-top: 1px solid #cbd5e1; margin-top: 10px; padding-top: 10px; font-size: 0.85rem;">
                        <b>Name:</b> <?= htmlspecialchars($job['client_rep_name'] ?? 'N/A') ?> &nbsp;|&nbsp; 
                        <b>ID:</b> <?= htmlspecialchars($job['client_rep_id_card'] ?? 'N/A') ?>
                    </div>
                </div>
            <?php else: ?>
                <div style="border: 1px dashed #cbd5e1; padding: 15px; border-radius: 8px; text-align: center; background: #f8fafc; color: #64748b;">
                    <i class="fas fa-robot" style="font-size: 2rem; margin-bottom: 10px; color: #94a3b8;"></i>
                    <div style="font-size: 0.85rem; font-weight: 600; text-transform: uppercase; color: #475569;">Automated Deployment</div>
                    <div style="font-size: 0.75rem; margin-top: 4px;">No manual signature required for static assets.</div>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 20px; font-size: 0.85rem; color: #475569;">
                <b>Payment Instructions:</b> Payable to <?= htmlspecialchars($billingCompanyLabel) ?>.<br>
                Bank: <?= htmlspecialchars($job['bank_name'] ?? 'N/A') ?> | IBAN: <?= htmlspecialchars($job['iban'] ?? 'N/A') ?>
            </div>
        </div>
        
        <div class="totals-box">
            <div class="totals-row"><span class="data-label">Gross Subtotal</span><span class="data-val" id="tot_gross">€ 0.00</span></div>
            <div class="totals-row" id="discount_row" style="color: #ef4444; display: none;">
                <span class="data-label">Discount (<span id="disp_disc_pct">0</span>%)</span>
                <span class="data-val">- € <span id="tot_discount">0.00</span></span>
            </div>
            <div class="totals-row" style="border-top: 1px dashed #cbd5e1; padding-top: 5px; margin-top: 5px;"><span class="data-label">Net Subtotal</span><span class="data-val" id="tot_net">€ 0.00</span></div>
            <div class="totals-row"><span class="data-label">VAT (18%)</span><span class="data-val" id="tot_vat">€ 0.00</span></div>
            <div class="totals-row totals-final"><span class="data-label" style="color:#000;">Total Due</span><span class="data-val">€ <span id="tot_final">0.00</span></span></div>
        </div>
    </div>

    <script>
        const pricingType = '<?= $job['pricing_type'] ?>';
        const minHours = <?= (float)$job['min_hours'] ?>;
        const isInternal = <?= $isInternal ? 'true' : 'false' ?>;
        const jobRef = '<?= $jobRef ?>';
        
        const rawNomFixed = '<?= htmlspecialchars($job['nom_code_fixed'] ?? '') ?>';
        const rawNomVar = '<?= htmlspecialchars($job['nom_code_variable'] ?? '') ?>';
        const rawNomSetup = '<?= htmlspecialchars($job['nom_code_setup'] ?? '0000') ?>';
        const canEdit = <?= $canEdit ? 'true' : 'false' ?>;
        const canDiscount = <?= $canDiscount ? 'true' : 'false' ?>;
        
        const savedHours = <?= $job['final_hours'] ?? 0 ?>;
        const hasSetupFee = <?= (!empty($job['apply_setup_fee']) && $job['apply_setup_fee'] == 1) ? 'true' : 'false' ?>;
        const modeBreakdown = <?= json_encode($modeBreakdown) ?>;
        const addonBreakdown = <?= json_encode($addonBreakdown) ?>;

        let rateFixed = <?= isset($job['final_rate_fixed']) && $job['final_rate_fixed'] !== null ? (float)$job['final_rate_fixed'] : ($fixedNom ? (float)($isInternal ? $fixedNom['NCDefSP1'] : $fixedNom['NCDefSP2']) : 0) ?>;
        let rateVar = <?= isset($job['final_rate_var']) && $job['final_rate_var'] !== null ? (float)$job['final_rate_var'] : ($varNom ? (float)($isInternal ? $varNom['NCDefSP1'] : $varNom['NCDefSP2']) : 0) ?>;
        let rateSetup = <?= isset($job['final_setup_fee']) && $job['final_setup_fee'] !== null ? (float)$job['final_setup_fee'] : (float)($job['setup_fee'] ?? 0) ?>;
        
        let currentDiscountPct = <?= $savedDiscountPct ?>;
        let maxAllowedDiscount = 0;
        let grossSubtotal = 0;

        if (canEdit && canDiscount) {
            const clientCode = '<?= addslashes($job['client_code'] ?? '') ?>';
            const companyId = '<?= addslashes($job['billing_company_id'] ?? '') ?>';
            if (clientCode && clientCode !== 'TBC') {
                fetch(`api/plant_actions.php?action=get_client_max_discount&client_code=${clientCode}&company_id=${companyId}`)
                .then(r => r.json())
                .then(data => {
                    maxAllowedDiscount = parseFloat(data.max_discount) || 0;
                    document.getElementById('max_disc_label').innerText = `(Max allowed: ${maxAllowedDiscount}%)`;
                    validateAndRenderDiscount(); 
                });
            }
        }

        function validateAndRenderDiscount() {
            if (!canDiscount) return;
            const inputEl = document.getElementById('edit_discount_pct');
            let val = parseFloat(inputEl.value) || 0;
            
            if (val > maxAllowedDiscount) {
                alert(`The ERP system restricts discounts for this client to a maximum of ${maxAllowedDiscount}%.`);
                val = maxAllowedDiscount;
                inputEl.value = val;
            }
            if (val < 0) { val = 0; inputEl.value = 0; }
            
            currentDiscountPct = val;
            renderTable();
        }

        function recalcHours() {
            const tIn = document.getElementById('edit_time_in').value;
            const tOut = document.getElementById('edit_time_out').value;
            if (tIn && tOut) {
                const [hIn, mIn] = tIn.split(':').map(Number);
                const [hOut, mOut] = tOut.split(':').map(Number);
                let diff = (hOut + mOut/60) - (hIn + mIn/60);
                if (diff < 0) diff += 24; 
                
                const qtyInput = document.getElementById('calc_master_qty');
                if (qtyInput) qtyInput.value = diff.toFixed(2);
                renderTable();
            }
        }

        function renderTable() {
            const qtyInput = document.getElementById('calc_master_qty');
            let totalQty = parseFloat(qtyInput ? (parseFloat(qtyInput.value) || 0) : <?= $qtyValue ?>).toFixed(2);
            
            const tbody = document.getElementById('lines-body');
            let html = '';
            grossSubtotal = 0;

            const fRateInput = canEdit ? `<input type="number" class="live-calc text-right" style="width:75px;" value="${rateFixed.toFixed(4)}" onchange="rateFixed = parseFloat(this.value) || 0; renderTable();">` : rateFixed.toFixed(4);
            const vRateInput = canEdit ? `<input type="number" class="live-calc text-right" style="width:75px;" value="${rateVar.toFixed(4)}" onchange="rateVar = parseFloat(this.value) || 0; renderTable();">` : rateVar.toFixed(4);
            
            if (hasSetupFee) {
                const sRateInput = canEdit ? `<input type="number" class="live-calc text-right" style="width:75px;" value="${rateSetup.toFixed(4)}" onchange="rateSetup = parseFloat(this.value) || 0; renderTable();">` : rateSetup.toFixed(4);
                let sTotal = +(1 * rateSetup).toFixed(2);
                grossSubtotal += sTotal;
                
                html += `<tr>
                    <td><b>${rawNomSetup}</b></td>
                    <td>Setup / Mobilisation Fee<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">1.00</td>
                    <td class="text-right">${sRateInput}</td>
                    <td class="text-right"><b>${sTotal.toFixed(2)}</b></td>
                </tr>`;
            }

            if (pricingType === 'fixed_then_hourly') {
                const fCode = rawNomFixed || 'MISSING';
                const fDesc = 'Fixed Callout Charge';
                let fTotal = +(1 * rateFixed).toFixed(2);
                grossSubtotal += fTotal;
                
                html += `<tr>
                    <td><b>${fCode}</b></td>
                    <td>${fDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">1.00</td>
                    <td class="text-right">${fRateInput}</td>
                    <td class="text-right"><b>${fTotal.toFixed(2)}</b></td>
                </tr>`;

                const extraHours = Math.max(0, totalQty - minHours);
                if (extraHours > 0) {
                    const vCode = rawNomVar || 'MISSING';
                    const vDesc = 'Additional Hourly Rate';
                    let vTotal = +(extraHours * rateVar).toFixed(2);
                    grossSubtotal += vTotal;
                    
                    html += `<tr>
                        <td><b>${vCode}</b></td>
                        <td>${vDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Extra Hours > ${minHours})</i></td>
                        <td class="text-right">${extraHours.toFixed(2)}</td>
                        <td class="text-right">${vRateInput}</td>
                        <td class="text-right"><b>${vTotal.toFixed(2)}</b></td>
                    </tr>`;
                }
            } 
            else if (pricingType === 'per_trip') {
                const tCode = rawNomFixed || 'MISSING';
                const tDesc = 'Trip Execution Charge';
                let tTotal = +(totalQty * rateFixed).toFixed(2);
                grossSubtotal += tTotal;

                html += `<tr>
                    <td><b>${tCode}</b></td>
                    <td>${tDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">${totalQty} Trips</td>
                    <td class="text-right">${fRateInput}</td>
                    <td class="text-right"><b>${tTotal.toFixed(2)}</b></td>
                </tr>`;
            } 
            else if (pricingType === 'daily') {
                const dCode = rawNomFixed || 'MISSING';
                const dDesc = 'Daily Flat Rate';
                let dTotal = +(totalQty * rateFixed).toFixed(2);
                grossSubtotal += dTotal;

                html += `<tr>
                    <td><b>${dCode}</b></td>
                    <td>${dDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">${totalQty} Days</td>
                    <td class="text-right">${fRateInput}</td>
                    <td class="text-right"><b>${dTotal.toFixed(2)}</b></td>
                </tr>`;
            }
            else {
                if (Object.keys(modeBreakdown).length > 0 || Object.keys(addonBreakdown).length > 0) {
                    for (const [modeName, data] of Object.entries(modeBreakdown)) {
                        const mCode = data.nom_code || 'MISSING';
                        let mQty = parseFloat(data.hours).toFixed(2);
                        let mRate = parseFloat(data.rate);
                        let mTotal = +(mQty * mRate).toFixed(2);
                        grossSubtotal += mTotal;
                        
                        html += `<tr>
                            <td><b>${mCode}</b></td>
                            <td>Primary Mode: ${modeName}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                            <td class="text-right">${mQty} Hrs</td>
                            <td class="text-right">${mRate.toFixed(4)}</td>
                            <td class="text-right"><b>${mTotal.toFixed(2)}</b></td>
                        </tr>`;
                    }

                    for (const [addonName, data] of Object.entries(addonBreakdown)) {
                        const aCode = data.nom_code || 'MISSING';
                        let aQtyHours = parseFloat(data.qty_hours).toFixed(2);
                        let aRate = parseFloat(data.rate);
                        let aTotal = +(aQtyHours * aRate).toFixed(2);
                        grossSubtotal += aTotal;
                        
                        html += `<tr>
                            <td><b>${aCode}</b></td>
                            <td>Extra Add-on Surcharge: ${addonName}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                            <td class="text-right">${aQtyHours} Qty-Hrs</td>
                            <td class="text-right">${aRate.toFixed(4)}</td>
                            <td class="text-right"><b>${aTotal.toFixed(2)}</b></td>
                        </tr>`;
                    }
                } else {
                    const hCode = rawNomVar || 'MISSING';
                    const hDesc = 'Standard Hourly Operation';
                    let hTotal = +(totalQty * rateVar).toFixed(2);
                    grossSubtotal += hTotal;

                    html += `<tr>
                        <td><b>${hCode}</b></td>
                        <td>${hDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                        <td class="text-right">${totalQty} Hrs</td>
                        <td class="text-right">${vRateInput}</td>
                        <td class="text-right"><b>${hTotal.toFixed(2)}</b></td>
                    </tr>`;
                }
            }

            tbody.innerHTML = html;

            let totalDiscount = +(grossSubtotal * (currentDiscountPct / 100)).toFixed(2);
            let netSubtotal = +(grossSubtotal - totalDiscount).toFixed(2);
            let vat = +(netSubtotal * 0.18).toFixed(2);
            let finalTotal = +(netSubtotal + vat).toFixed(2);

            document.getElementById('tot_gross').innerText = '€ ' + grossSubtotal.toFixed(2);
            document.getElementById('tot_net').innerText = '€ ' + netSubtotal.toFixed(2);
            document.getElementById('tot_vat').innerText = '€ ' + vat.toFixed(2);
            document.getElementById('tot_final').innerText = finalTotal.toFixed(2);

            const discRow = document.getElementById('discount_row');
            if (totalDiscount > 0) {
                discRow.style.display = 'flex';
                document.getElementById('disp_disc_pct').innerText = currentDiscountPct;
                document.getElementById('tot_discount').innerText = totalDiscount.toFixed(2);
            } else {
                discRow.style.display = 'none';
            }
        }

        renderTable();

        function saveAndPrint() {
            <?php if ($billingCompanyMissing): ?>
            alert('Assign a billing company on this plant asset in Fleet setup before pushing to ERP.');
            return;
            <?php endif; ?>
            const btn = document.getElementById('printBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            const finalQty = document.getElementById('calc_master_qty').value;

            const fd = new FormData();
            fd.append('action', 'finalize_and_invoice');
            fd.append('booking_id', <?= $bookingId ?>);
            fd.append('hours', finalQty); 
            fd.append('rate_fixed', rateFixed);
            fd.append('rate_var', rateVar);
            fd.append('discount_pct', currentDiscountPct);

            const chitEl = document.getElementById('edit_delivery_chit_number');
            if (chitEl) fd.append('delivery_chit_number', chitEl.value.trim());
            
            if (hasSetupFee) {
                fd.append('setup_fee', rateSetup);
            }
            
            const timeIn = document.getElementById('edit_time_in');
            const timeOut = document.getElementById('edit_time_out');
            if (timeIn && timeOut) {
                fd.append('time_in', timeIn.value);
                fd.append('time_out', timeOut.value);
            }

            fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r => r.text()).then(res => {
                if (res.includes('OK')) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Saved!';
                    setTimeout(() => { location.reload(); }, 1200);
                } else { 
                    alert(res); 
                    btn.disabled = false; 
                    btn.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Save RFP & Push to ERP';
                }
            });
        }

        let invoiceErpClients = [];
        const invCompId = '<?= addslashes($job['billing_company_id'] ?? '') ?>';
        const invBookingId = <?= $bookingId ?>;

        function openClientEdit() {
            document.getElementById('client-display-block').style.display = 'none';
            document.getElementById('client-edit-block').style.display = 'block';
            const input = document.getElementById('inv_client_search');
            
            if (invoiceErpClients.length === 0) {
                input.disabled = true;
                input.placeholder = "Loading ERP clients...";
                fetch(`api/plant_actions.php?action=get_company_clients&company_id=${invCompId}`)
                .then(r => r.json())
                .then(res => {
                    invoiceErpClients = res;
                    input.disabled = false;
                    input.placeholder = "Start typing client name...";
                    input.focus();
                });
            } else {
                input.focus();
            }
        }

        function filterInvClients(query) {
            const resultsDiv = document.getElementById('inv_client_results');
            if(query.length < 2) { resultsDiv.style.display = 'none'; return; }
            
            const q = query.toLowerCase().trim();
            const filtered = invoiceErpClients.filter(c => (c.name || '').toLowerCase().includes(q)).slice(0, 15);
            
            if(filtered.length === 0) {
                resultsDiv.innerHTML = '<div style="padding:15px; color:#ef4444; font-weight:bold;">No client found.</div>';
            } else {
                resultsDiv.innerHTML = filtered.map(c => {
                    if (c.status === 1) {
                        return `<div style="padding:12px; cursor:pointer; border-bottom:1px solid #e2e8f0; font-weight:bold; color:#0f172a; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'" onclick="saveNewClient('${c.code}', '${c.name.replace(/'/g, "\\'")}')">${c.name} <br><span style="color:#64748b; font-weight:normal; font-size:0.8rem;">Code: ${c.code}</span></div>`;
                    } else {
                        return `<div style="padding:12px; cursor:not-allowed; background:#f1f5f9; opacity: 0.65;"><span style="font-weight:bold; color:#64748b; text-decoration: line-through;">${c.name}</span><br><span style="color:#ef4444; font-weight:bold; font-size:0.8rem;"><i class="fas fa-lock"></i> Blocked</span></div>`;
                    }
                }).join('');
            }
            resultsDiv.style.display = 'block';
        }

        function saveNewClient(code, name) {
            if(!confirm(`Update this RFP to bill to ${name}?`)) return;
            
            document.getElementById('inv_client_results').style.display = 'none';
            document.getElementById('inv_client_search').value = 'Saving...';
            document.getElementById('inv_client_search').disabled = true;
            
            const fd = new FormData();
            fd.append('action', 'update_job_client');
            fd.append('booking_id', invBookingId);
            fd.append('client_code', code);
            fd.append('client_name', name);
            
            fetch('api/plant_actions.php', { method: 'POST', body: fd })
            .then(r => r.text())
            .then(res => {
                if(res === 'OK') { location.reload(); } else { alert(res); cancelClientEdit(); }
            });
        }

        function cancelClientEdit() {
            document.getElementById('client-edit-block').style.display = 'none';
            document.getElementById('client-display-block').style.display = 'block';
            document.getElementById('inv_client_results').style.display = 'none';
            document.getElementById('inv_client_search').value = '';
        }
    </script>
</body>
</html>
