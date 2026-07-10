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

$setupNom = null;
if (!empty($allNominals) && !empty($job['nom_code_setup'])) {
    foreach ($allNominals as $n) {
        if (trim($n['NCCode']) === trim($job['nom_code_setup'])) {
            $setupNom = $n;
            break;
        }
    }
}

$isInternal = ($job['booking_type'] == 'in-house');

$erpNominalMap = [];
if (!empty($allNominals)) {
    foreach ($allNominals as $n) {
        $code = trim((string)($n['NCCode'] ?? ''));
        if ($code === '') {
            continue;
        }
        $erpNominalMap[$code] = [
            'rate' => (float)($isInternal ? ($n['NCDefSP1'] ?? 0) : ($n['NCDefSP2'] ?? 0)),
            'desc' => (string)($n['NCDesc'] ?? ''),
        ];
    }
}

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
    $activeSessionHours = $activeIn > $activeOut ? 0.0 : round(($activeInterval->days * 24) + $activeInterval->h + ($activeInterval->i / 60), 2);
}

$inTime = !empty($job['punch_in_time']) ? new DateTime($job['punch_in_time']) : new DateTime($job['booking_date'] . ' ' . $job['start_time']);
$outTime = !empty($job['punch_out_time']) ? new DateTime($job['punch_out_time']) : new DateTime($job['booking_date'] . ' ' . $job['end_time']);
$interval = $inTime->diff($outTime);
$legacyHoursWorked = $inTime > $outTime ? 0.0 : round(($interval->days * 24) + $interval->h + ($interval->i / 60), 2);

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
                        $addonBreakdown[$saName]['qty_hours'] += $saQty;
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
$plantConfigurations = [];
if (!empty($job['configurations'])) {
    $decodedConfigs = json_decode($job['configurations'], true);
    $plantConfigurations = is_array($decodedConfigs) ? $decodedConfigs : [];
}
$savedBillingOverrides = !empty($job['billing_overrides']) ? json_decode($job['billing_overrides'], true) : null;
$savedBillingNote = trim((string)($job['billing_note'] ?? ''));
$savedDeliveryChitNumber = trim((string)($job['delivery_chit_number'] ?? ''));
$qtyTripsValue = ($job['qty_trips'] > 0) ? (float)$job['qty_trips'] : 1;
$hasSetupFeeFlag = (!empty($job['apply_setup_fee']) && $job['apply_setup_fee'] == 1) || ((float)($job['final_setup_fee'] ?? 0) > 0);
$hasConfiguredBilling = ($job['has_configurations'] == 1 && (count($modeBreakdown) > 0 || count($addonBreakdown) > 0));
$erpSetupRate = $setupNom ? (float)($isInternal ? $setupNom['NCDefSP1'] : $setupNom['NCDefSP2']) : (float)($job['setup_fee'] ?? 0);
$erpRateFixed = $fixedNom ? (float)($isInternal ? $fixedNom['NCDefSP1'] : $fixedNom['NCDefSP2']) : 0;
$erpRateVar = $varNom ? (float)($isInternal ? $varNom['NCDefSP1'] : $varNom['NCDefSP2']) : 0;
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
        .edit-panel { background: #fffbeb; border: 2px solid #f59e0b; border-radius: 12px; padding: 18px; margin-bottom: 24px; }
        .edit-panel h3 { margin: 0 0 6px; color: #92400e; font-size: 1rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .edit-panel p { margin: 0 0 16px; color: #78716c; font-size: 0.85rem; }
        .edit-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 16px; }
        .edit-field label { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #57534e; margin-bottom: 4px; }
        .edit-field input, .edit-field select { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d6d3d1; border-radius: 6px; font: inherit; background: #fff; }
        .edit-field input[type="checkbox"] { width: auto; }
        .edit-section { border-top: 1px dashed #d6d3d1; padding-top: 14px; margin-top: 14px; }
        .edit-section h4 { margin: 0 0 10px; font-size: 0.8rem; color: #44403c; text-transform: uppercase; }
        .edit-row-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 10px; }
        .edit-row-table th, .edit-row-table td { border: 1px solid #e7e5e4; padding: 8px; text-align: left; }
        .edit-row-table th { background: #fafaf9; font-size: 0.72rem; text-transform: uppercase; color: #57534e; }
        .edit-actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; margin-top: 16px; }
        .btn-preview { padding: 10px 18px; background: #0f172a; color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .btn-final { padding: 10px 18px; background: #10b981; color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .preview-wrap { border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; background: #fff; }
        .preview-label { font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: #64748b; margin-bottom: 12px; letter-spacing: 0.05em; }
        .client-results { display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:2px solid #6366f1; z-index:100; max-height:200px; overflow-y:auto; box-shadow:0 10px 25px rgba(0,0,0,0.2); border-radius:8px; }
        
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
            <div class="edit-panel no-print">
                <h3><i class="fas fa-sliders-h"></i> Billing adjustments</h3>
                <p>Edit all billable values here, update the preview below, then submit when ready. Nothing is saved until you push the final RFP.</p>

                <div class="edit-grid">
                    <div class="edit-field" style="position:relative;">
                        <label>ERP client</label>
                        <input type="text" id="edit_client_search" placeholder="Search ERP client..." autocomplete="off" onkeyup="filterEditClients(this.value)">
                        <div id="edit_client_results" class="client-results"></div>
                        <div id="edit_client_selected" style="margin-top:6px; font-size:0.82rem; color:#334155; font-weight:700;"></div>
                    </div>
                    <div class="edit-field" id="field_master_qty" style="<?= $hasConfiguredBilling ? 'display:none;' : '' ?>">
                        <label id="label_master_qty"><?= htmlspecialchars($qtyLabel) ?></label>
                        <input type="number" id="edit_master_qty" step="0.25" value="<?= $isTripBased ? $qtyTripsValue : $qtyValue ?>">
                    </div>
                    <div class="edit-field" id="field_time_window" style="<?= ($isDailyBased || $job['lifecycle_type'] === 'Auto-Scheduled' || count($sessions) > 0 || $isTripBased) ? 'display:none;' : '' ?>">
                        <label>Time window</label>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <input type="time" id="edit_time_in" value="<?= $inTime->format('H:i') ?>">
                            <span>to</span>
                            <input type="time" id="edit_time_out" value="<?= $outTime->format('H:i') ?>">
                        </div>
                    </div>
                    <?php if ($canDiscount): ?>
                    <div class="edit-field">
                        <label>Discount % <span id="max_disc_label" style="font-weight:400; text-transform:none;">(Max: loading...)</span></label>
                        <input type="number" id="edit_discount_pct" step="0.1" min="0" value="<?= $savedDiscountPct ?>">
                    </div>
                    <?php else: ?>
                        <input type="hidden" id="edit_discount_pct" value="<?= $savedDiscountPct ?>">
                    <?php endif; ?>
                    <div class="edit-field">
                        <label>Delivery chit number</label>
                        <input type="text" id="edit_delivery_chit_number" maxlength="40" value="<?= htmlspecialchars($savedDeliveryChitNumber) ?>" placeholder="Optional — from driver or enter manually">
                    </div>
                    <div class="edit-field">
                        <label>Billing note</label>
                        <input type="text" id="edit_billing_note" maxlength="80" value="<?= htmlspecialchars($savedBillingNote) ?>" placeholder="Optional note appended to driver line in ERP">
                    </div>
                </div>

                <div class="edit-section" id="section_setup_fee" style="<?= ($job['setup_fee'] > 0 || $hasSetupFeeFlag || !empty($job['nom_code_setup'])) ? '' : 'display:none;' ?>">
                    <h4>Setup / mobilisation</h4>
                    <div class="edit-field">
                        <label><input type="checkbox" id="edit_apply_setup_fee" <?= $hasSetupFeeFlag ? 'checked' : '' ?>> Apply setup / mobilisation fee</label>
                        <div style="font-size:0.85rem; color:#64748b; margin-top:6px;">ERP rate: <b>€ <?= number_format($erpSetupRate, 4) ?></b> <span style="font-weight:400;">(not editable here)</span></div>
                    </div>
                </div>

                <div class="edit-section" id="section_modes" style="<?= $hasConfiguredBilling ? '' : 'display:none;' ?>">
                    <h4>Operational modes</h4>
                    <table class="edit-row-table">
                        <thead><tr><th>Mode</th><th>Qty / Hrs</th><th>ERP rate (€)</th></tr></thead>
                        <tbody id="edit_modes_body"></tbody>
                    </table>
                </div>

                <div class="edit-section" id="section_addons" style="<?= ($job['has_configurations'] == 1) ? '' : 'display:none;' ?>">
                    <h4>Configured add-ons</h4>
                    <table class="edit-row-table">
                        <thead><tr><th>Add-on</th><th>Billable qty</th><th>ERP rate (€)</th><th></th></tr></thead>
                        <tbody id="edit_addons_body"></tbody>
                    </table>
                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <select id="addon_picker" style="min-width:220px; padding:8px; border-radius:6px; border:1px solid #d6d3d1;">
                            <option value="">Add configured add-on...</option>
                        </select>
                        <button type="button" onclick="addConfiguredAddon()" style="padding:8px 12px; border:none; background:#e2e8f0; border-radius:6px; font-weight:700; cursor:pointer;">+ Add</button>
                    </div>
                </div>

                <div class="edit-section">
                    <h4>Manual add-on line</h4>
                    <table class="edit-row-table">
                        <thead><tr><th>Description</th><th>ERP code</th><th>Qty</th><th>ERP rate (€)</th><th></th></tr></thead>
                        <tbody id="edit_manual_body"></tbody>
                    </table>
                    <button type="button" onclick="addManualLine()" style="padding:8px 12px; border:none; background:#e2e8f0; border-radius:6px; font-weight:700; cursor:pointer;">+ Add manual line</button>
                </div>

                <div class="edit-actions">
                    <?php if ($erpAvailable): ?>
                        <span style="align-self:center; background:#e0e7ff; color:#4f46e5; padding:5px 10px; border-radius:6px; font-weight:bold; font-size:0.8rem;"><i class="fas fa-plug"></i> ERP Live Sync</span>
                    <?php else: ?>
                        <span style="align-self:center; background:#fef3c7; color:#b45309; padding:5px 10px; border-radius:6px; font-weight:bold; font-size:0.8rem;"><i class="fas fa-exclamation-triangle"></i> ERP Offline</span>
                    <?php endif; ?>
                    <button type="button" class="btn-preview" onclick="updatePreview()"><i class="fas fa-eye"></i> Update preview</button>
                    <button type="button" id="printBtn" class="btn-final" onclick="submitFinalRfp()" <?= $billingCompanyMissing ? 'disabled title="Assign a billing company on the plant asset first"' : '' ?>><i class="fas fa-cloud-upload-alt"></i> Save RFP &amp; push to ERP</button>
                </div>
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

    <div class="<?= $canEdit ? 'preview-wrap' : '' ?>" id="preview-document">
    <?php if ($canEdit): ?><div class="preview-label no-print">Preview — delivery note / RFP</div><?php endif; ?>

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
            <h4>Billed To (Client Details)</h4>
            
            <div id="client-display-block">
                <div class="data-row"><span class="data-label">ERP Account Name</span><span class="data-val" id="disp_client_name"><?= $clientDisplay ?></span></div>
                <div class="data-row"><span class="data-label">ERP Account Code</span><span class="data-val" id="disp_client_code">
                    <?php if ($clientCodeDisplay === 'TBC'): ?>
                        <span style="background:#fef08a; color:#854d0e; padding:2px 6px; border-radius:4px; font-size:0.8rem;">TBC - Must Update</span>
                    <?php else: ?>
                        <?= $clientCodeDisplay ?>
                    <?php endif; ?>
                </span></div>
                <div class="data-row"><span class="data-label">Project / Location</span><span class="data-val"><?= $projectDisplay ?></span></div>
                <div class="data-row"><span class="data-label">Booking Type</span><span class="data-val" style="text-transform:uppercase;"><?= $job['booking_type'] ?></span></div>
            </div>
        </div>
        <div class="box">
            <h4>Job Report (Execution Details)</h4>
            <div class="data-row"><span class="data-label">Machinery</span><span class="data-val"><?= htmlspecialchars($job['plant_name']) ?> (<?= htmlspecialchars($job['category']) ?>)</span></div>
            <div class="data-row"><span class="data-label">Reg Plate</span><span class="data-val"><?= htmlspecialchars($job['registration_plate'] ?? 'N/A') ?></span></div>
            <div class="data-row"><span class="data-label">Driver</span><span class="data-val"><?= $driverName ?></span></div>
            <div class="data-row" id="row_delivery_chit" style="<?= $savedDeliveryChitNumber === '' ? 'display:none;' : '' ?>">
                <span class="data-label">Delivery chit</span>
                <span class="data-val" id="disp_delivery_chit"><?= htmlspecialchars($savedDeliveryChitNumber) ?></span>
            </div>
            
            <div class="data-row" style="align-items: flex-start;">
                <span class="data-label">Time Logged</span>
                <span class="data-val" id="preview_time_logged">
                    <?php if ($isDailyBased || $job['lifecycle_type'] === 'Auto-Scheduled'): ?>
                        <div style="font-weight: bold; color: #0f172a;">
                            <?= date('d M Y', strtotime($job['booking_date'])) ?> to <?= !empty($job['end_date']) ? date('d M Y', strtotime($job['end_date'])) : date('d M Y', strtotime($job['booking_date'])) ?>
                        </div>
                    <?php elseif (count($sessions) > 0): ?>
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
                        </div>
                    <?php else: ?>
                        <?= $inTime->format('H:i') ?> to <?= $outTime->format('H:i') ?>
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
    </div>

    <script>
        const pricingType = '<?= $job['pricing_type'] ?>';
        const minHours = <?= (float)$job['min_hours'] ?>;
        const jobRef = '<?= $jobRef ?>';
        const rawNomFixed = '<?= htmlspecialchars($job['nom_code_fixed'] ?? '') ?>';
        const rawNomVar = '<?= htmlspecialchars($job['nom_code_variable'] ?? '') ?>';
        const rawNomSetup = '<?= htmlspecialchars($job['nom_code_setup'] ?? '0000') ?>';
        const canEdit = <?= $canEdit ? 'true' : 'false' ?>;
        const canDiscount = <?= $canDiscount ? 'true' : 'false' ?>;
        const hasConfiguredBilling = <?= $hasConfiguredBilling ? 'true' : 'false' ?>;
        const plantConfigurations = <?= json_encode($plantConfigurations) ?>;
        const modeBreakdownSeed = <?= json_encode($modeBreakdown) ?>;
        const addonBreakdownSeed = <?= json_encode($addonBreakdown) ?>;
        const savedOverrides = <?= json_encode($savedBillingOverrides) ?>;
        const erpNominalMap = <?= json_encode($erpNominalMap) ?>;
        const invCompId = '<?= addslashes($job['billing_company_id'] ?? '') ?>';
        const invBookingId = <?= $bookingId ?>;

        let invoiceErpClients = [];
        let loadingPromise = null;
        let maxAllowedDiscount = 0;
        let grossSubtotal = 0;

        const billingState = {
            client_code: '<?= addslashes($job['client_code'] ?? '') ?>',
            client_name: <?= json_encode($job['client_name'] ?? '') ?>,
            discount_pct: <?= $savedDiscountPct ?>,
            billing_note: <?= json_encode($savedBillingNote) ?>,
            delivery_chit_number: <?= json_encode($savedDeliveryChitNumber) ?>,
            master_qty: <?= $isTripBased ? $qtyTripsValue : $qtyValue ?>,
            apply_setup_fee: <?= $hasSetupFeeFlag ? 'true' : 'false' ?>,
            rate_setup: <?= (float)$erpSetupRate ?>,
            rate_fixed: <?= (float)$erpRateFixed ?>,
            rate_var: <?= (float)$erpRateVar ?>,
            modes: [],
            addons: [],
            manual_lines: [],
        };

        function initBillingState() {
            if (savedOverrides && Array.isArray(savedOverrides.modes) && savedOverrides.modes.length) {
                billingState.modes = savedOverrides.modes.map(row => {
                    const seed = modeBreakdownSeed[row.name] || {};
                    const cfg = plantConfigurations.find(c => c.name === row.name && c.type === 'mode');
                    const nomCode = seed.nom_code || cfg?.nom_code || '';
                    return {
                        name: row.name,
                        hours: parseFloat(row.hours) || 0,
                        rate: parseFloat(seed.rate) || lookupErpRate(nomCode) || parseFloat(cfg?.price) || 0,
                        nom_code: nomCode,
                    };
                });
            } else {
                billingState.modes = Object.entries(modeBreakdownSeed).map(([name, data]) => ({
                    name,
                    hours: parseFloat(data.hours) || 0,
                    rate: parseFloat(data.rate) || 0,
                    nom_code: data.nom_code || '',
                }));
            }
            if (savedOverrides && Array.isArray(savedOverrides.addons) && savedOverrides.addons.length) {
                billingState.addons = savedOverrides.addons.map(row => {
                    const seed = addonBreakdownSeed[row.name] || {};
                    const cfg = plantConfigurations.find(c => c.name === row.name && c.type === 'addon');
                    const nomCode = seed.nom_code || cfg?.nom_code || '';
                    return {
                        name: row.name,
                        qty_hours: parseFloat(row.qty_hours) || 0,
                        rate: parseFloat(seed.rate) || lookupErpRate(nomCode) || parseFloat(cfg?.price) || 0,
                        nom_code: nomCode,
                    };
                });
            } else {
                billingState.addons = Object.entries(addonBreakdownSeed).map(([name, data]) => ({
                    name,
                    qty_hours: parseFloat(data.qty_hours) || 0,
                    rate: parseFloat(data.rate) || 0,
                    nom_code: data.nom_code || '',
                }));
            }
            if (savedOverrides && Array.isArray(savedOverrides.manual_lines)) {
                billingState.manual_lines = savedOverrides.manual_lines.map(line => ({
                    description: line.description || '',
                    nom_code: line.nom_code || '',
                    qty: parseFloat(line.qty) || 0,
                    rate: lookupErpRate(line.nom_code) || parseFloat(line.rate) || 0,
                }));
            }
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function renderClientSelected() {
            const el = document.getElementById('edit_client_selected');
            if (!el) return;
            if (!billingState.client_code || billingState.client_code === 'TBC') {
                el.innerHTML = '<span style="color:#b45309;">No ERP client selected yet</span>';
            } else {
                el.textContent = `${billingState.client_name} (Code: ${billingState.client_code})`;
            }
        }

        function lookupErpRate(nomCode) {
            const code = String(nomCode || '').trim();
            if (!code || !erpNominalMap[code]) return 0;
            return parseFloat(erpNominalMap[code].rate) || 0;
        }

        function renderEditTables() {
            const modesBody = document.getElementById('edit_modes_body');
            if (modesBody) {
                modesBody.innerHTML = billingState.modes.map((mode, idx) => `
                    <tr>
                        <td><strong>${escapeHtml(mode.name)}</strong></td>
                        <td><input type="number" step="0.25" value="${mode.hours}" onchange="billingState.modes[${idx}].hours = parseFloat(this.value) || 0"></td>
                        <td style="font-weight:700;">${(parseFloat(mode.rate) || 0).toFixed(4)}</td>
                    </tr>
                `).join('');
            }

            const addonsBody = document.getElementById('edit_addons_body');
            if (addonsBody) {
                addonsBody.innerHTML = billingState.addons.map((addon, idx) => `
                    <tr>
                        <td><strong>${escapeHtml(addon.name)}</strong></td>
                        <td><input type="number" step="1" min="0" value="${addon.qty_hours}" onchange="billingState.addons[${idx}].qty_hours = parseFloat(this.value) || 0"></td>
                        <td style="font-weight:700;">${(parseFloat(addon.rate) || 0).toFixed(4)}</td>
                        <td><button type="button" onclick="removeAddon(${idx})" style="border:none;background:#fee2e2;color:#b91c1c;padding:6px 10px;border-radius:6px;cursor:pointer;">Remove</button></td>
                    </tr>
                `).join('');
            }

            const manualBody = document.getElementById('edit_manual_body');
            if (manualBody) {
                manualBody.innerHTML = billingState.manual_lines.map((line, idx) => `
                    <tr>
                        <td><input type="text" value="${escapeHtml(line.description)}" onchange="billingState.manual_lines[${idx}].description = this.value"></td>
                        <td><input type="text" value="${escapeHtml(line.nom_code)}" onchange="billingState.manual_lines[${idx}].nom_code = this.value; billingState.manual_lines[${idx}].rate = lookupErpRate(this.value); renderEditTables();"></td>
                        <td><input type="number" step="0.25" value="${line.qty}" onchange="billingState.manual_lines[${idx}].qty = parseFloat(this.value) || 0"></td>
                        <td style="font-weight:700;">${(parseFloat(line.rate) || 0).toFixed(4)}</td>
                        <td><button type="button" onclick="removeManualLine(${idx})" style="border:none;background:#fee2e2;color:#b91c1c;padding:6px 10px;border-radius:6px;cursor:pointer;">Remove</button></td>
                    </tr>
                `).join('');
            }

            const picker = document.getElementById('addon_picker');
            if (picker) {
                const existing = new Set(billingState.addons.map(a => a.name));
                picker.innerHTML = '<option value="">Add configured add-on...</option>' + plantConfigurations
                    .filter(cfg => cfg.type === 'addon' && !existing.has(cfg.name))
                    .map(cfg => `<option value="${escapeHtml(cfg.name)}">${escapeHtml(cfg.name)}</option>`)
                    .join('');
            }
        }

        function removeAddon(index) {
            billingState.addons.splice(index, 1);
            renderEditTables();
        }

        function addConfiguredAddon() {
            const picker = document.getElementById('addon_picker');
            const selectedName = picker ? picker.value : '';
            if (!selectedName) return;
            const cfg = plantConfigurations.find(c => c.name === selectedName && c.type === 'addon');
            if (!cfg) return;
            billingState.addons.push({
                name: cfg.name,
                qty_hours: 0,
                rate: lookupErpRate(cfg.nom_code) || parseFloat(cfg.price) || 0,
                nom_code: cfg.nom_code || '',
            });
            renderEditTables();
        }

        function addManualLine() {
            billingState.manual_lines.push({
                description: '',
                nom_code: rawNomVar || rawNomFixed || '',
                qty: 1,
                rate: 0,
            });
            renderEditTables();
        }

        function removeManualLine(index) {
            billingState.manual_lines.splice(index, 1);
            renderEditTables();
        }

        function syncBillingStateFromForm() {
            const qtyEl = document.getElementById('edit_master_qty');
            if (qtyEl) billingState.master_qty = parseFloat(qtyEl.value) || 0;

            const discountEl = document.getElementById('edit_discount_pct');
            if (discountEl) {
                let val = parseFloat(discountEl.value) || 0;
                if (canDiscount && val > maxAllowedDiscount) {
                    alert(`The ERP system restricts discounts for this client to a maximum of ${maxAllowedDiscount}%.`);
                    val = maxAllowedDiscount;
                    discountEl.value = val;
                }
                billingState.discount_pct = val;
            }

            const noteEl = document.getElementById('edit_billing_note');
            if (noteEl) billingState.billing_note = noteEl.value.trim();

            const chitEl = document.getElementById('edit_delivery_chit_number');
            if (chitEl) billingState.delivery_chit_number = chitEl.value.trim();

            const setupApplyEl = document.getElementById('edit_apply_setup_fee');
            if (setupApplyEl) billingState.apply_setup_fee = setupApplyEl.checked;

            const timeInEl = document.getElementById('edit_time_in');
            const timeOutEl = document.getElementById('edit_time_out');
            if (timeInEl && timeOutEl && timeInEl.value && timeOutEl.value) {
                const [hIn, mIn] = timeInEl.value.split(':').map(Number);
                const [hOut, mOut] = timeOutEl.value.split(':').map(Number);
                let diff = (hOut + mOut / 60) - (hIn + mIn / 60);
                if (diff < 0) diff += 24;
                billingState.master_qty = parseFloat(diff.toFixed(2));
                if (qtyEl) qtyEl.value = billingState.master_qty.toFixed(2);
            }
        }

        function buildBillingOverridesPayload() {
            const payload = {
                manual_lines: billingState.manual_lines
                    .filter(l => l.description && l.nom_code && l.qty > 0)
                    .map(l => ({ description: l.description, nom_code: l.nom_code, qty: l.qty })),
            };
            if (hasConfiguredBilling) {
                payload.modes = billingState.modes
                    .filter(m => m.name && m.hours > 0)
                    .map(m => ({ name: m.name, hours: m.hours }));
                payload.addons = billingState.addons
                    .filter(a => a.name && a.qty_hours > 0)
                    .map(a => ({ name: a.name, qty_hours: a.qty_hours }));
            }
            return payload;
        }

        function updatePreview() {
            syncBillingStateFromForm();

            document.getElementById('disp_client_name').textContent = billingState.client_name || 'N/A';
            const codeEl = document.getElementById('disp_client_code');
            if (codeEl) {
                if (!billingState.client_code || billingState.client_code === 'TBC') {
                    codeEl.innerHTML = '<span style="background:#fef08a; color:#854d0e; padding:2px 6px; border-radius:4px; font-size:0.8rem;">TBC - Must Update</span>';
                } else {
                    codeEl.textContent = billingState.client_code;
                }
            }

            const chitRow = document.getElementById('row_delivery_chit');
            const chitDisp = document.getElementById('disp_delivery_chit');
            if (chitRow && chitDisp) {
                if (billingState.delivery_chit_number) {
                    chitRow.style.display = '';
                    chitDisp.textContent = billingState.delivery_chit_number;
                } else {
                    chitRow.style.display = 'none';
                    chitDisp.textContent = '';
                }
            }

            renderPreviewTable();
        }

        function renderPreviewTable() {
            const tbody = document.getElementById('lines-body');
            let html = '';
            grossSubtotal = 0;
            const totalQty = parseFloat(billingState.master_qty || 0).toFixed(2);

            if (billingState.apply_setup_fee) {
                const sTotal = +(1 * billingState.rate_setup).toFixed(2);
                grossSubtotal += sTotal;
                html += `<tr><td><b>${rawNomSetup}</b></td><td>Setup / Mobilisation Fee<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td><td class="text-right">1.00</td><td class="text-right">${billingState.rate_setup.toFixed(4)}</td><td class="text-right"><b>${sTotal.toFixed(2)}</b></td></tr>`;
            }

            if (pricingType === 'fixed_then_hourly') {
                const fTotal = +(1 * billingState.rate_fixed).toFixed(2);
                grossSubtotal += fTotal;
                html += `<tr><td><b>${rawNomFixed || 'MISSING'}</b></td><td>Fixed Callout Charge<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td><td class="text-right">1.00</td><td class="text-right">${billingState.rate_fixed.toFixed(4)}</td><td class="text-right"><b>${fTotal.toFixed(2)}</b></td></tr>`;
                const extraHours = Math.max(0, totalQty - minHours);
                if (extraHours > 0) {
                    const vTotal = +(extraHours * billingState.rate_var).toFixed(2);
                    grossSubtotal += vTotal;
                    html += `<tr><td><b>${rawNomVar || 'MISSING'}</b></td><td>Additional Hourly Rate<br><i style="font-size:0.8rem; color:#64748b;">(Extra Hours > ${minHours})</i></td><td class="text-right">${extraHours.toFixed(2)}</td><td class="text-right">${billingState.rate_var.toFixed(4)}</td><td class="text-right"><b>${vTotal.toFixed(2)}</b></td></tr>`;
                }
            } else if (pricingType === 'per_trip') {
                const tTotal = +(totalQty * billingState.rate_fixed).toFixed(2);
                grossSubtotal += tTotal;
                html += `<tr><td><b>${rawNomFixed || 'MISSING'}</b></td><td>Trip Execution Charge<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td><td class="text-right">${totalQty} Trips</td><td class="text-right">${billingState.rate_fixed.toFixed(4)}</td><td class="text-right"><b>${tTotal.toFixed(2)}</b></td></tr>`;
            } else if (pricingType === 'daily') {
                const dTotal = +(totalQty * billingState.rate_fixed).toFixed(2);
                grossSubtotal += dTotal;
                html += `<tr><td><b>${rawNomFixed || 'MISSING'}</b></td><td>Daily Flat Rate<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td><td class="text-right">${totalQty} Days</td><td class="text-right">${billingState.rate_fixed.toFixed(4)}</td><td class="text-right"><b>${dTotal.toFixed(2)}</b></td></tr>`;
            } else if (hasConfiguredBilling) {
                billingState.modes.forEach(mode => {
                    if (!mode.name || mode.hours <= 0) return;
                    const mTotal = +(mode.hours * mode.rate).toFixed(2);
                    grossSubtotal += mTotal;
                    html += `<tr><td><b>${escapeHtml(mode.nom_code || 'MISSING')}</b></td><td>Primary Mode: ${escapeHtml(mode.name)}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td><td class="text-right">${mode.hours.toFixed(2)} Hrs</td><td class="text-right">${mode.rate.toFixed(4)}</td><td class="text-right"><b>${mTotal.toFixed(2)}</b></td></tr>`;
                });
                billingState.addons.forEach(addon => {
                    if (!addon.name || addon.qty_hours <= 0) return;
                    const aTotal = +(addon.qty_hours * addon.rate).toFixed(2);
                    grossSubtotal += aTotal;
                    html += `<tr><td><b>${escapeHtml(addon.nom_code || 'MISSING')}</b></td><td>Extra Add-on Surcharge: ${escapeHtml(addon.name)}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td><td class="text-right">${addon.qty_hours.toFixed(2)} Units</td><td class="text-right">${addon.rate.toFixed(4)}</td><td class="text-right"><b>${aTotal.toFixed(2)}</b></td></tr>`;
                });
            } else {
                const hTotal = +(totalQty * billingState.rate_var).toFixed(2);
                grossSubtotal += hTotal;
                html += `<tr><td><b>${rawNomVar || 'MISSING'}</b></td><td>Standard Hourly Operation<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td><td class="text-right">${totalQty} Hrs</td><td class="text-right">${billingState.rate_var.toFixed(4)}</td><td class="text-right"><b>${hTotal.toFixed(2)}</b></td></tr>`;
            }

            billingState.manual_lines.forEach(line => {
                const lineRate = lookupErpRate(line.nom_code) || parseFloat(line.rate) || 0;
                if (!line.description || !line.nom_code || line.qty <= 0 || lineRate <= 0) return;
                const mTotal = +(line.qty * lineRate).toFixed(2);
                grossSubtotal += mTotal;
                html += `<tr><td><b>${escapeHtml(line.nom_code)}</b></td><td>${escapeHtml(line.description)}<br><i style="font-size:0.8rem; color:#64748b;">(Manual line)</i></td><td class="text-right">${line.qty.toFixed(2)}</td><td class="text-right">${lineRate.toFixed(4)}</td><td class="text-right"><b>${mTotal.toFixed(2)}</b></td></tr>`;
            });

            tbody.innerHTML = html;

            const totalDiscount = +(grossSubtotal * (billingState.discount_pct / 100)).toFixed(2);
            const netSubtotal = +(grossSubtotal - totalDiscount).toFixed(2);
            const vat = +(netSubtotal * 0.18).toFixed(2);
            const finalTotal = +(netSubtotal + vat).toFixed(2);

            document.getElementById('tot_gross').innerText = '€ ' + grossSubtotal.toFixed(2);
            document.getElementById('tot_net').innerText = '€ ' + netSubtotal.toFixed(2);
            document.getElementById('tot_vat').innerText = '€ ' + vat.toFixed(2);
            document.getElementById('tot_final').innerText = finalTotal.toFixed(2);

            const discRow = document.getElementById('discount_row');
            if (totalDiscount > 0) {
                discRow.style.display = 'flex';
                document.getElementById('disp_disc_pct').innerText = billingState.discount_pct;
                document.getElementById('tot_discount').innerText = totalDiscount.toFixed(2);
            } else {
                discRow.style.display = 'none';
            }
        }

        function submitFinalRfp() {
            <?php if ($billingCompanyMissing): ?>
            alert('Assign a billing company on this plant asset in Fleet setup before pushing to ERP.');
            return;
            <?php endif; ?>

            syncBillingStateFromForm();
            updatePreview();

            if (!billingState.client_code || billingState.client_code === 'TBC') {
                alert('Select a valid ERP client before finalising.');
                return;
            }

            const btn = document.getElementById('printBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            const fd = new FormData();
            fd.append('action', 'finalize_and_invoice');
            fd.append('booking_id', invBookingId);
            fd.append('client_code', billingState.client_code);
            fd.append('client_name', billingState.client_name);
            fd.append('hours', billingState.master_qty);
            fd.append('discount_pct', billingState.discount_pct);
            fd.append('billing_note', billingState.billing_note);
            fd.append('delivery_chit_number', billingState.delivery_chit_number);
            fd.append('apply_setup_fee', billingState.apply_setup_fee ? '1' : '0');
            fd.append('billing_overrides', JSON.stringify(buildBillingOverridesPayload()));

            if (pricingType === 'per_trip') {
                fd.append('qty_trips', billingState.master_qty);
            }

            const timeInEl = document.getElementById('edit_time_in');
            const timeOutEl = document.getElementById('edit_time_out');
            if (timeInEl && timeOutEl && timeInEl.value && timeOutEl.value) {
                fd.append('time_in', timeInEl.value);
                fd.append('time_out', timeOutEl.value);
            }

            fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r => r.text()).then(res => {
                if (res.includes('OK')) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Saved!';
                    setTimeout(() => { location.reload(); }, 1200);
                } else {
                    alert(res);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Save RFP & push to ERP';
                }
            });
        }

        function ensureErpClientsLoaded() {
            if (invoiceErpClients.length > 0) return Promise.resolve(invoiceErpClients);
            if (loadingPromise) return loadingPromise;
            loadingPromise = fetch(`api/plant_actions.php?action=get_company_clients&company_id=${invCompId}`)
                .then(r => r.json())
                .then(res => {
                    invoiceErpClients = Array.isArray(res) ? res : [];
                    loadingPromise = null;
                    return invoiceErpClients;
                })
                .catch(() => {
                    loadingPromise = null;
                    invoiceErpClients = [];
                    return invoiceErpClients;
                });
            return loadingPromise;
        }

        function filterEditClients(query) {
            const resultsDiv = document.getElementById('edit_client_results');
            if (!resultsDiv) return;
            if (query.length < 2) { resultsDiv.style.display = 'none'; return; }
            ensureErpClientsLoaded().then(() => {
                const q = query.toLowerCase().trim();
                const filtered = invoiceErpClients.filter(c => (c.name || '').toLowerCase().includes(q)).slice(0, 15);
                resultsDiv.innerHTML = filtered.length === 0
                    ? '<div style="padding:15px; color:#ef4444; font-weight:bold;">No client found.</div>'
                    : filtered.map(c => c.status === 1
                        ? `<div style="padding:12px; cursor:pointer; border-bottom:1px solid #e2e8f0; font-weight:bold;" onclick="selectEditClient('${c.code}', '${String(c.name).replace(/'/g, "\\'").replace(/"/g, '&quot;')}')">${escapeHtml(c.name)}<br><span style="color:#64748b; font-weight:normal; font-size:0.8rem;">Code: ${escapeHtml(c.code)}</span></div>`
                        : `<div style="padding:12px; background:#f1f5f9; opacity:0.65;"><span style="text-decoration:line-through;">${escapeHtml(c.name)}</span><br><span style="color:#ef4444; font-weight:bold; font-size:0.8rem;">Blocked</span></div>`
                    ).join('');
                resultsDiv.style.display = 'block';
            });
        }

        function selectEditClient(code, name) {
            billingState.client_code = code;
            billingState.client_name = name;
            document.getElementById('edit_client_search').value = name;
            document.getElementById('edit_client_results').style.display = 'none';
            renderClientSelected();
            if (canDiscount) {
                fetch(`api/plant_actions.php?action=get_client_max_discount&client_code=${encodeURIComponent(code)}&company_id=${encodeURIComponent(invCompId)}`)
                    .then(r => r.json())
                    .then(data => {
                        maxAllowedDiscount = parseFloat(data.max_discount) || 0;
                        const label = document.getElementById('max_disc_label');
                        if (label) label.innerText = `(Max allowed: ${maxAllowedDiscount}%)`;
                    });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            initBillingState();

            if (!canEdit) {
                renderPreviewTable();
                return;
            }

            renderClientSelected();
            if (billingState.client_name && billingState.client_code && billingState.client_code !== 'TBC') {
                document.getElementById('edit_client_search').value = billingState.client_name;
            }
            renderEditTables();
            updatePreview();

            if (canDiscount && billingState.client_code && billingState.client_code !== 'TBC') {
                fetch(`api/plant_actions.php?action=get_client_max_discount&client_code=${encodeURIComponent(billingState.client_code)}&company_id=${encodeURIComponent(invCompId)}`)
                    .then(r => r.json())
                    .then(data => {
                        maxAllowedDiscount = parseFloat(data.max_discount) || 0;
                        const label = document.getElementById('max_disc_label');
                        if (label) label.innerText = `(Max allowed: ${maxAllowedDiscount}%)`;
                    });
            }
        });
    </script>
</body>
</html>
