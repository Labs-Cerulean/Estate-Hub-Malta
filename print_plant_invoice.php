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
require_once __DIR__ . '/includes/j2_erp_health.php';

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
$erpHealth = j2ErpGetHealth();
$erpAvailable = $erpHealth['available'] === true;

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
$setupNom = null;

if (!empty($allNominals)) {
    foreach($allNominals as $n) {
        if (!empty($job['nom_code_fixed']) && trim($n['NCCode']) == trim($job['nom_code_fixed'])) $fixedNom = $n;
        if (!empty($job['nom_code_variable']) && trim($n['NCCode']) == trim($job['nom_code_variable'])) $varNom = $n;
        if (!empty($job['nom_code_setup']) && trim($n['NCCode']) == trim($job['nom_code_setup'])) $setupNom = $n;
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
$cfgs = ($job['has_configurations'] == 1 && !empty($job['configurations'])) ? json_decode($job['configurations'], true) : null;
$hasConfiguredModeTypes = false;
$modeEditorOrder = [];

if ($job['has_configurations'] == 1 && is_array($cfgs)) {
    foreach ($cfgs as $cfg) {
        if (($cfg['type'] ?? '') === 'mode' && !empty($cfg['name'])) {
            $hasConfiguredModeTypes = true;
            break;
        }
    }
}

if ($job['has_configurations'] == 1 && is_array($cfgs) && count($sessions) > 0) {
    foreach ($sessions as $s) {
        // 1. Group Primary Operational Modes
        $mName = !empty($s['mode_name']) ? $s['mode_name'] : 'Standard Operation';
        if (!isset($modeBreakdown[$mName])) {
            $modeBreakdown[$mName] = ['hours' => 0, 'nom_code' => '', 'rate' => 0];
        }
        $modeBreakdown[$mName]['hours'] += (float)$s['hours'];

        // 2. Group Extra Add-ons (flat quantity — charged once for the whole job, not per hour)
        if (!empty($s['addons_used'])) {
            $sAddons = json_decode($s['addons_used'], true);
            if (is_array($sAddons)) {
                foreach ($sAddons as $sa) {
                    $saName = $sa['name'];
                    $saQty = (int)$sa['qty'];
                    if ($saQty > 0) {
                        if (!isset($addonBreakdown[$saName])) {
                            $addonBreakdown[$saName] = ['qty' => 0, 'nom_code' => '', 'rate' => 0];
                        }
                        $addonBreakdown[$saName]['qty'] += $saQty;
                    }
                }
            }
        }
    }
    
    foreach ($modeBreakdown as $mName => &$data) {
        $matchedCfg = null;
        foreach ($cfgs as $c) { if ($c['name'] === $mName && $c['type'] === 'mode') { $matchedCfg = $c; break; } }
        if ($matchedCfg) {
            $data['nom_code'] = $matchedCfg['nom_code'];
            $nCodeTrim = trim($matchedCfg['nom_code']); $erpRate = 0;
            if (!empty($allNominals)) {
                foreach($allNominals as $n) { if (trim($n['NCCode']) === $nCodeTrim) { $erpRate = $isInternal ? $n['NCDefSP1'] : $n['NCDefSP2']; break; } }
            }
            $data['rate'] = $erpRate > 0 ? $erpRate : (float)$matchedCfg['price'];
        } else {
            $data['nom_code'] = $job['nom_code_variable'];
            $data['rate'] = $varNom ? ($isInternal ? $varNom['NCDefSP1'] : $varNom['NCDefSP2']) : 0;
        }
    }
    unset($data);

    foreach ($addonBreakdown as $saName => &$data) {
        $matchedCfg = null;
        foreach ($cfgs as $c) { if ($c['name'] === $saName && $c['type'] === 'addon') { $matchedCfg = $c; break; } }
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

// Always seed base + configured modes for the RFP editor, even when sessions omitted a mode.
if ($hasConfiguredModeTypes && is_array($cfgs)) {
    $baseModeName = 'Standard Operation';
    $baseVarRate = $varNom ? ($isInternal ? (float)$varNom['NCDefSP1'] : (float)$varNom['NCDefSP2']) : 0;
    if (!isset($modeBreakdown[$baseModeName])) {
        $modeBreakdown[$baseModeName] = [
            'hours' => 0,
            'nom_code' => (string)($job['nom_code_variable'] ?? ''),
            'rate' => $baseVarRate,
        ];
    } else {
        if (empty($modeBreakdown[$baseModeName]['nom_code'])) {
            $modeBreakdown[$baseModeName]['nom_code'] = (string)($job['nom_code_variable'] ?? '');
        }
        if ((float)$modeBreakdown[$baseModeName]['rate'] <= 0) {
            $modeBreakdown[$baseModeName]['rate'] = $baseVarRate;
        }
    }

    foreach ($cfgs as $cfg) {
        if (($cfg['type'] ?? '') !== 'mode' || empty($cfg['name'])) {
            continue;
        }
        $modeName = (string)$cfg['name'];
        if (isset($modeBreakdown[$modeName])) {
            continue;
        }
        $nCodeTrim = trim((string)($cfg['nom_code'] ?? ''));
        $erpRate = 0;
        if ($nCodeTrim !== '' && !empty($allNominals)) {
            foreach ($allNominals as $n) {
                if (trim((string)$n['NCCode']) === $nCodeTrim) {
                    $erpRate = $isInternal ? (float)$n['NCDefSP1'] : (float)$n['NCDefSP2'];
                    break;
                }
            }
        }
        $modeBreakdown[$modeName] = [
            'hours' => 0,
            'nom_code' => (string)($cfg['nom_code'] ?? ''),
            'rate' => $erpRate > 0 ? $erpRate : (float)($cfg['price'] ?? 0),
        ];
    }
}

$plantAddonConfigs = [];
if ($job['has_configurations'] == 1 && !empty($job['configurations'])) {
    $decodedCfgs = json_decode($job['configurations'], true);
    if (is_array($decodedCfgs)) {
        foreach ($decodedCfgs as $cfg) {
            if (($cfg['type'] ?? '') !== 'addon' || empty($cfg['name'])) {
                continue;
            }
            $nCodeTrim = trim((string)($cfg['nom_code'] ?? ''));
            $erpRate = 0;
            if ($nCodeTrim !== '' && !empty($allNominals)) {
                foreach ($allNominals as $n) {
                    if (trim((string)$n['NCCode']) === $nCodeTrim) {
                        $erpRate = $isInternal ? (float)$n['NCDefSP1'] : (float)$n['NCDefSP2'];
                        break;
                    }
                }
            }
            $plantAddonConfigs[] = [
                'name' => (string)$cfg['name'],
                'nom_code' => (string)($cfg['nom_code'] ?? ''),
                'rate' => $erpRate > 0 ? $erpRate : (float)($cfg['price'] ?? 0),
            ];
        }
    }
}
$hasAddonConfigs = count($plantAddonConfigs) > 0;

$hasConfiguredModes = $hasConfiguredModeTypes;
$modeDisplayLabels = [
    'Standard Operation' => trim($job['plant_name'] ?? '') !== '' ? trim($job['plant_name']) . ' (Base)' : 'Base Operation',
];
if ($hasConfiguredModeTypes && is_array($cfgs)) {
    $modeEditorOrder[] = 'Standard Operation';
    foreach ($cfgs as $cfg) {
        if (($cfg['type'] ?? '') === 'mode' && !empty($cfg['name'])) {
            $modeDisplayLabels[(string)$cfg['name']] = (string)$cfg['name'];
            $modeEditorOrder[] = (string)$cfg['name'];
        }
    }
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
$erpRateFixed = $fixedNom ? (float)($isInternal ? $fixedNom['NCDefSP1'] : $fixedNom['NCDefSP2']) : 0;
$erpRateVar = $varNom ? (float)($isInternal ? $varNom['NCDefSP1'] : $varNom['NCDefSP2']) : 0;
$erpRateSetup = $setupNom ? (float)($isInternal ? $setupNom['NCDefSP1'] : $setupNom['NCDefSP2']) : (float)($job['setup_fee'] ?? 0);
$hasSetupFeeFlag = $canEdit
    ? (!empty($job['apply_setup_fee']) && (int)$job['apply_setup_fee'] === 1)
    : (((float)($job['final_setup_fee'] ?? 0) > 0) || (!empty($job['apply_setup_fee']) && (int)$job['apply_setup_fee'] === 1));
$canToggleSetupFee = $canEdit && (
    (float)($job['setup_fee'] ?? 0) > 0 || trim((string)($job['nom_code_setup'] ?? '')) !== ''
);
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
        .edit-panel { background: #fffbeb; border: 2px solid #f59e0b; border-radius: 12px; padding: 18px; margin-bottom: 0; }
        .edit-panel h3 { margin: 0 0 6px; color: #92400e; font-size: 1rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .edit-panel p { margin: 0; color: #78716c; font-size: 0.85rem; }
        .edit-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-top: 16px; }
        .edit-field label { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #57534e; margin-bottom: 4px; }
        .edit-field input { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d6d3d1; border-radius: 6px; font: inherit; background: #fff; }
        .edit-total-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 16px; padding: 12px 16px; background: #ecfdf5; border: 1px solid #6ee7b7; border-radius: 10px; flex-wrap: wrap; }
        .edit-total-bar .lbl { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: #047857; }
        .edit-total-bar .val { font-size: 1.35rem; font-weight: 900; color: #065f46; }
        .live-badge { display: inline-flex; align-items: center; gap: 6px; background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; }
        .warn-inline { color: #b45309; font-size: 0.78rem; font-weight: 600; margin-top: 6px; display: none; }
        .erp-rate-note { font-size: 0.78rem; color: #64748b; margin-top: 4px; }
        .edit-section { border-top: 1px dashed #d6d3d1; padding-top: 14px; margin-top: 14px; }
        .edit-section h4 { margin: 0 0 10px; font-size: 0.8rem; color: #44403c; text-transform: uppercase; }
        .edit-row-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 10px; }
        .edit-row-table th, .edit-row-table td { border: 1px solid #e7e5e4; padding: 8px; text-align: left; }
        .edit-row-table th { background: #fafaf9; font-size: 0.72rem; text-transform: uppercase; color: #57534e; }
        .edit-row-table input[type="number"] { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #d6d3d1; border-radius: 6px; font: inherit; }
        .client-results { display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #fff; border: 2px solid #6366f1; z-index: 100; max-height: 200px; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-radius: 8px; }
        .btn-secondary { padding: 8px 12px; border: none; background: #e2e8f0; border-radius: 6px; font-weight: 700; cursor: pointer; }
        .btn-danger-soft { border: none; background: #fee2e2; color: #b91c1c; padding: 6px 10px; border-radius: 6px; cursor: pointer; font-weight: 700; }
        .btn-final { padding: 12px 22px; background: #10b981; color: #fff; border: none; border-radius: 8px; font-weight: 800; cursor: pointer; font-size: 1rem; }
        .btn-final:hover { background: #059669; }
        .btn-final:disabled { background: #94a3b8; cursor: not-allowed; }
        .preview-wrap { border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; background: #fff; margin-top: 24px; }
        .preview-label { font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: #64748b; margin-bottom: 12px; letter-spacing: 0.05em; }
        .rate-readonly { font-weight: 700; color: #0f172a; }
        #erp-status-banner { background: #fef2f2; border: 2px solid #fecaca; color: #991b1b; padding: 14px 18px; border-radius: 10px; margin-bottom: 18px; font-size: 0.9rem; line-height: 1.5; }
        #erp-status-banner b { display: block; margin-bottom: 4px; }
        
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
        <?php if ($canEdit && !$erpAvailable): ?>
            <div id="erp-status-banner" class="no-print" role="alert">
                <b><i class="fas fa-plug-circle-xmark"></i> ERP connection unavailable</b>
                <?= htmlspecialchars($erpHealth['message'] ?? 'This RFP cannot be pushed or edited for billing until the ERP link is restored. You can view and print locally only.') ?>
            </div>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <div class="edit-panel">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                    <div>
                        <h3><i class="fas fa-sliders-h"></i> Billing adjustments</h3>
                        <p>Adjust quantities below. Rates always come from the ERP and cannot be edited here. The preview updates live.</p>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span class="live-badge"><i class="fas fa-bolt"></i> Live preview</span>
                        <?php if ($erpAvailable): ?>
                            <span style="background:#e0e7ff; color:#4f46e5; padding:5px 10px; border-radius:6px; font-weight:bold; font-size:0.8rem;"><i class="fas fa-plug"></i> ERP Live</span>
                        <?php else: ?>
                            <span style="background:#fef3c7; color:#b45309; padding:5px 10px; border-radius:6px; font-weight:bold; font-size:0.8rem;"><i class="fas fa-exclamation-triangle"></i> ERP Offline</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="edit-grid">
                    <div class="edit-field" style="position:relative;">
                        <label>ERP Client</label>
                        <input type="text" id="panel_client_search" placeholder="Search ERP client..." autocomplete="off" onkeyup="filterPanelClients(this.value)" <?= !$erpAvailable ? 'disabled' : '' ?>>
                        <div id="panel_client_results" class="client-results"></div>
                        <div id="panel_client_selected" class="erp-rate-note" style="margin-top:6px; font-weight:700; color:#334155;"></div>
                    </div>

                    <div class="edit-field" id="field_master_qty" style="<?= $hasConfiguredModes ? 'display:none;' : '' ?>">
                        <label>Final <?= htmlspecialchars($qtyLabel) ?></label>
                        <input type="number" id="calc_master_qty" value="<?= $qtyValue ?>" step="0.25" oninput="renderTable()" <?= !$erpAvailable ? 'disabled' : '' ?>>
                    </div>

                    <?php if ($canDiscount): ?>
                    <div class="edit-field">
                        <label>Discount % <span id="max_disc_label" style="font-weight:400; text-transform:none;">(Max: loading...)</span></label>
                        <input type="number" id="edit_discount_pct" value="<?= $savedDiscountPct ?>" step="0.1" min="0" oninput="validateAndRenderDiscount()" <?= !$erpAvailable ? 'disabled' : '' ?>>
                        <div class="warn-inline" id="discount_warn"></div>
                    </div>
                    <?php else: ?>
                        <input type="hidden" id="edit_discount_pct" value="<?= $savedDiscountPct ?>">
                    <?php endif; ?>

                    <div class="edit-field">
                        <label>Delivery chit #</label>
                        <input type="text" id="edit_delivery_chit_number" maxlength="40" value="<?= htmlspecialchars($job['delivery_chit_number'] ?? '') ?>" placeholder="Optional" <?= !$erpAvailable ? 'disabled' : '' ?>>
                    </div>

                    <?php if ($canToggleSetupFee): ?>
                    <div class="edit-field" style="display:flex; align-items:center; gap:10px; padding-top:22px;">
                        <input type="checkbox" id="edit_apply_setup_fee" value="1" style="width:20px; height:20px; cursor:pointer;" <?= $hasSetupFeeFlag ? 'checked' : '' ?> onchange="toggleSetupFee(this.checked)" <?= !$erpAvailable ? 'disabled' : '' ?>>
                        <label for="edit_apply_setup_fee" style="margin:0; cursor:pointer; text-transform:none; font-size:0.85rem;">
                            Apply one-time setup fee (<span id="setup_fee_display_amount">€<?= number_format((float)$erpRateSetup, 2) ?></span>)
                        </label>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($hasConfiguredModes): ?>
                <div class="edit-section" id="section_modes">
                    <h4><i class="fas fa-layer-group"></i> Operational modes (hours)</h4>
                    <p class="erp-rate-note" style="margin-bottom:10px;">Adjust billed hours per mode below. Rates always come from ERP nominal codes.</p>
                    <table class="edit-row-table">
                        <thead>
                            <tr>
                                <th>Mode</th>
                                <th style="width:120px;">Hours</th>
                                <th style="width:120px;">Rate (€)</th>
                            </tr>
                        </thead>
                        <tbody id="edit_modes_body"></tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if ($hasAddonConfigs): ?>
                <div class="edit-section" id="section_addons">
                    <h4><i class="fas fa-puzzle-piece"></i> Extra add-ons (flat quantity)</h4>
                    <p class="erp-rate-note" style="margin-bottom:10px;">Adjust quantities below. Rates are sourced from ERP and cannot be edited here.</p>
                    <table class="edit-row-table">
                        <thead>
                            <tr>
                                <th>Add-on</th>
                                <th style="width:120px;">Qty</th>
                                <th style="width:120px;">Rate (€)</th>
                                <th style="width:90px;"></th>
                            </tr>
                        </thead>
                        <tbody id="edit_addons_body"></tbody>
                    </table>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <select id="addon_picker" style="min-width:220px; padding:8px; border-radius:6px; border:1px solid #d6d3d1;" <?= !$erpAvailable ? 'disabled' : '' ?>>
                            <option value="">Add configured add-on...</option>
                        </select>
                        <button type="button" class="btn-secondary" onclick="addConfiguredAddon()" <?= !$erpAvailable ? 'disabled' : '' ?>>+ Add</button>
                    </div>
                </div>
                <?php endif; ?>

                <div class="edit-total-bar">
                    <div>
                        <div class="lbl">Preview total due (incl. VAT)</div>
                        <div class="erp-rate-note"><i class="fas fa-lock"></i> All line rates sourced from ERP nominal codes</div>
                    </div>
                    <div class="val">€ <span id="edit_panel_total">0.00</span></div>
                    <button id="printBtn" class="btn-final" onclick="saveAndPrint()" <?= ($billingCompanyMissing || !$erpAvailable) ? 'disabled title="' . htmlspecialchars($billingCompanyMissing ? 'Assign a billing company on the plant asset first' : ($erpHealth['message'] ?? 'ERP offline')) . '"' : '' ?>><i class="fas fa-cloud-upload-alt"></i> Save RFP &amp; Push to ERP</button>
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
            <h4>Billed To (Client Details)
                <?php if ($canEdit && $erpAvailable): ?>
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
        const erpAvailable = <?= $erpAvailable ? 'true' : 'false' ?>;
        const erpDownMessage = <?= json_encode($erpHealth['message'] ?? 'ERP is offline.') ?>;
        
        const savedHours = <?= $job['final_hours'] ?? 0 ?>;
        let applySetupFee = <?= $hasSetupFeeFlag ? 'true' : 'false' ?>;
        const hasConfiguredModes = <?= $hasConfiguredModes ? 'true' : 'false' ?>;
        const modeDisplayLabels = <?= json_encode($modeDisplayLabels, JSON_HEX_APOS | JSON_HEX_TAG) ?>;
        const modeEditorOrder = <?= json_encode($modeEditorOrder, JSON_HEX_APOS | JSON_HEX_TAG) ?>;
        const plantAddonConfigs = <?= json_encode($plantAddonConfigs, JSON_HEX_APOS | JSON_HEX_TAG) ?>;
        const hasAddonConfigs = <?= $hasAddonConfigs ? 'true' : 'false' ?>;

        let modeState = {};
        (function initModeState() {
            const seed = <?= json_encode($modeBreakdown, JSON_HEX_APOS | JSON_HEX_TAG) ?>;
            Object.entries(seed).forEach(([name, data]) => {
                modeState[name] = {
                    hours: parseFloat(data.hours) || 0,
                    rate: parseFloat(data.rate) || 0,
                    nom_code: data.nom_code || '',
                };
            });
        })();

        let addonState = {};
        (function initAddonState() {
            const seed = <?= json_encode($addonBreakdown, JSON_HEX_APOS | JSON_HEX_TAG) ?>;
            Object.entries(seed).forEach(([name, data]) => {
                addonState[name] = {
                    qty: parseFloat(data.qty) || 0,
                    rate: parseFloat(data.rate) || 0,
                    nom_code: data.nom_code || '',
                };
            });
        })();

        const rateFixed = <?= (float)$erpRateFixed ?>;
        const rateVar = <?= (float)$erpRateVar ?>;
        const rateSetup = <?= (float)$erpRateSetup ?>;
        
        let currentDiscountPct = <?= $savedDiscountPct ?>;
        let maxAllowedDiscount = null;
        let grossSubtotal = 0;
        let panelClientCode = <?= json_encode($job['client_code'] ?? '') ?>;
        let panelClientName = <?= json_encode($job['client_name'] ?? '') ?>;

        function escapeHtml(value) {
            return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function renderPanelClientSelected() {
            const el = document.getElementById('panel_client_selected');
            if (!el) return;
            if (!panelClientCode || panelClientCode === 'TBC') {
                el.innerHTML = '<span style="color:#b45309;">No ERP client selected — search above before pushing</span>';
            } else {
                el.textContent = `${panelClientName} (Code: ${panelClientCode})`;
            }
        }

        const PLANT_BASE_MODE = 'Standard Operation';

        function getMasterQty() {
            if (hasConfiguredModes && Object.keys(modeState).length > 0) {
                if (modeState[PLANT_BASE_MODE]) {
                    return parseFloat(modeState[PLANT_BASE_MODE].hours) || 0;
                }
                return Object.values(modeState).reduce((sum, data) => sum + (parseFloat(data.hours) || 0), 0);
            }
            const qtyInput = document.getElementById('calc_master_qty');
            return parseFloat(qtyInput ? (parseFloat(qtyInput.value) || 0) : <?= $qtyValue ?>);
        }

        function renderAddonEditTable() {
            const addonsBody = document.getElementById('edit_addons_body');
            if (!addonsBody) return;

            const rows = Object.entries(addonState);
            addonsBody.innerHTML = rows.map(([name, data]) => `
                <tr>
                    <td><strong>${escapeHtml(name)}</strong></td>
                    <td><input type="number" step="1" min="0" value="${data.qty}" data-addon-name="${escapeHtml(name)}" onchange="setAddonQty(this.getAttribute('data-addon-name'), this.value)"></td>
                    <td><span class="rate-readonly">${(parseFloat(data.rate) || 0).toFixed(4)}</span></td>
                    <td><button type="button" class="btn-danger-soft" data-addon-name="${escapeHtml(name)}" onclick="removeAddon(this.getAttribute('data-addon-name'))">Remove</button></td>
                </tr>
            `).join('');

            const picker = document.getElementById('addon_picker');
            if (picker) {
                const existing = new Set(Object.keys(addonState));
                picker.innerHTML = '<option value="">Add configured add-on...</option>' + plantAddonConfigs
                    .filter(cfg => !existing.has(cfg.name))
                    .map(cfg => `<option value="${escapeHtml(cfg.name)}">${escapeHtml(cfg.name)}</option>`)
                    .join('');
            }
        }

        function setAddonQty(name, value) {
            if (!addonState[name]) return;
            addonState[name].qty = Math.max(0, parseInt(value, 10) || 0);
            renderAddonEditTable();
            renderTable();
        }

        function removeAddon(name) {
            delete addonState[name];
            renderAddonEditTable();
            renderTable();
        }

        function addConfiguredAddon() {
            const picker = document.getElementById('addon_picker');
            const selectedName = picker ? picker.value : '';
            if (!selectedName) return;
            const cfg = plantAddonConfigs.find(c => c.name === selectedName);
            if (!cfg) return;
            addonState[cfg.name] = {
                qty: 1,
                rate: parseFloat(cfg.rate) || 0,
                nom_code: cfg.nom_code || '',
            };
            renderAddonEditTable();
            renderTable();
        }

        function buildAddonOverridesPayload() {
            return Object.entries(addonState)
                .filter(([, data]) => (parseInt(data.qty, 10) || 0) > 0)
                .map(([name, data]) => ({ name, qty: parseInt(data.qty, 10) || 0 }));
        }

        function modeDisplayName(name) {
            return modeDisplayLabels[name] || name;
        }

        function orderedModeNames() {
            const names = [];
            modeEditorOrder.forEach(name => {
                if (modeState[name]) names.push(name);
            });
            Object.keys(modeState).forEach(name => {
                if (!names.includes(name)) names.push(name);
            });
            return names;
        }

        function toggleSetupFee(checked) {
            applySetupFee = !!checked;
            renderTable();
        }

        function renderModeEditTable() {
            const modesBody = document.getElementById('edit_modes_body');
            if (!modesBody) return;

            modesBody.innerHTML = orderedModeNames().map(name => {
                const data = modeState[name];
                return `
                <tr>
                    <td><strong>${escapeHtml(modeDisplayName(name))}</strong></td>
                    <td><input type="number" step="0.25" min="0" value="${(parseFloat(data.hours) || 0).toFixed(2)}" data-mode="${escapeHtml(name)}" onchange="setModeHours(this.getAttribute('data-mode'), this.value)"></td>
                    <td><span class="rate-readonly">${(parseFloat(data.rate) || 0).toFixed(4)}</span></td>
                </tr>
            `;
            }).join('');
        }

        function setModeHours(name, value) {
            if (!modeState[name]) return;
            modeState[name].hours = Math.max(0, parseFloat(value) || 0);
            renderModeEditTable();
            renderTable();
        }

        function buildModeOverridesPayload() {
            return Object.entries(modeState)
                .filter(([, data]) => (parseFloat(data.hours) || 0) > 0)
                .map(([name, data]) => ({ name, hours: parseFloat(parseFloat(data.hours).toFixed(2)) || 0 }));
        }

        function formatRate(value) {
            return `<span class="rate-readonly">${(parseFloat(value) || 0).toFixed(4)}</span>`;
        }

        if (canEdit && canDiscount) {
            const clientCode = '<?= addslashes($job['client_code'] ?? '') ?>';
            const companyId = '<?= addslashes($job['billing_company_id'] ?? '') ?>';
            if (clientCode && clientCode !== 'TBC') {
                fetch(`api/plant_actions.php?action=get_client_max_discount&client_code=${clientCode}&company_id=${companyId}`)
                .then(r => r.json())
                .then(data => {
                    maxAllowedDiscount = parseFloat(data.max_discount);
                    if (isNaN(maxAllowedDiscount)) maxAllowedDiscount = 0;
                    document.getElementById('max_disc_label').innerText = `(Max allowed: ${maxAllowedDiscount}%)`;
                    validateAndRenderDiscount(); 
                });
            }
        }

        function validateAndRenderDiscount() {
            if (!canDiscount) return;
            const inputEl = document.getElementById('edit_discount_pct');
            const warnEl = document.getElementById('discount_warn');
            let val = parseFloat(inputEl.value) || 0;

            if (val < 0) {
                val = 0;
                inputEl.value = 0;
            }

            if (maxAllowedDiscount !== null && val > maxAllowedDiscount) {
                val = maxAllowedDiscount;
                inputEl.value = val;
                if (warnEl) {
                    warnEl.style.display = 'block';
                    warnEl.textContent = `Discount capped at ERP maximum of ${maxAllowedDiscount}% for this client.`;
                }
            } else if (warnEl) {
                warnEl.style.display = 'none';
                warnEl.textContent = '';
            }

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
            let totalQty = getMasterQty().toFixed(2);
            
            const tbody = document.getElementById('lines-body');
            let html = '';
            grossSubtotal = 0;

            if (applySetupFee) {
                let sTotal = +(1 * rateSetup).toFixed(2);
                grossSubtotal += sTotal;
                
                html += `<tr>
                    <td><b>${rawNomSetup}</b></td>
                    <td>Setup / Mobilisation Fee<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">1.00</td>
                    <td class="text-right">${formatRate(rateSetup)}</td>
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
                    <td class="text-right">${formatRate(rateFixed)}</td>
                    <td class="text-right"><b>${fTotal.toFixed(2)}</b></td>
                </tr>`;

                const extraHours = Math.max(0, parseFloat(totalQty) - minHours);
                if (extraHours > 0) {
                    const vCode = rawNomVar || 'MISSING';
                    const vDesc = 'Additional Hourly Rate';
                    let vTotal = +(extraHours * rateVar).toFixed(2);
                    grossSubtotal += vTotal;
                    
                    html += `<tr>
                        <td><b>${vCode}</b></td>
                        <td>${vDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Extra Hours > ${minHours})</i></td>
                        <td class="text-right">${extraHours.toFixed(2)}</td>
                        <td class="text-right">${formatRate(rateVar)}</td>
                        <td class="text-right"><b>${vTotal.toFixed(2)}</b></td>
                    </tr>`;
                }

                if (hasConfiguredModes) {
                    for (const [modeName, data] of Object.entries(modeState)) {
                        if (modeName === PLANT_BASE_MODE) continue;
                        const mCode = data.nom_code || 'MISSING';
                        let mQty = parseFloat(data.hours) || 0;
                        let mRate = parseFloat(data.rate) || 0;
                        if (mQty <= 0) continue;
                        let mTotal = +(mQty * mRate).toFixed(2);
                        grossSubtotal += mTotal;

                        html += `<tr>
                            <td><b>${mCode}</b></td>
                            <td>Primary Mode: ${escapeHtml(modeDisplayName(modeName))}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                            <td class="text-right">${mQty.toFixed(2)} Hrs</td>
                            <td class="text-right">${formatRate(mRate)}</td>
                            <td class="text-right"><b>${mTotal.toFixed(2)}</b></td>
                        </tr>`;
                    }
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
                    <td class="text-right">${formatRate(rateFixed)}</td>
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
                    <td class="text-right">${formatRate(rateFixed)}</td>
                    <td class="text-right"><b>${dTotal.toFixed(2)}</b></td>
                </tr>`;
            }
            else {
                if (Object.keys(modeState).length > 0) {
                    for (const [modeName, data] of Object.entries(modeState)) {
                        const mCode = data.nom_code || 'MISSING';
                        let mQty = parseFloat(data.hours) || 0;
                        let mRate = parseFloat(data.rate) || 0;
                        if (mQty <= 0) continue;
                        let mTotal = +(mQty * mRate).toFixed(2);
                        grossSubtotal += mTotal;

                        html += `<tr>
                            <td><b>${mCode}</b></td>
                            <td>Primary Mode: ${escapeHtml(modeDisplayName(modeName))}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                            <td class="text-right">${mQty.toFixed(2)} Hrs</td>
                            <td class="text-right">${formatRate(mRate)}</td>
                            <td class="text-right"><b>${mTotal.toFixed(2)}</b></td>
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
                        <td class="text-right">${formatRate(rateVar)}</td>
                        <td class="text-right"><b>${hTotal.toFixed(2)}</b></td>
                    </tr>`;
                }
            }

            // Universal add-ons: flat quantity, charged on top of the base pricing for any plant type.
            for (const [addonName, data] of Object.entries(addonState)) {
                const aCode = data.nom_code || 'MISSING';
                let aQty = parseFloat(data.qty) || 0;
                let aRate = parseFloat(data.rate) || 0;
                if (aQty <= 0) continue;
                let aTotal = +(aQty * aRate).toFixed(2);
                grossSubtotal += aTotal;

                html += `<tr>
                    <td><b>${aCode}</b></td>
                    <td>Extra Add-on: ${addonName}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">${aQty.toFixed(2)}</td>
                    <td class="text-right">${formatRate(aRate)}</td>
                    <td class="text-right"><b>${aTotal.toFixed(2)}</b></td>
                </tr>`;
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

            const panelTotal = document.getElementById('edit_panel_total');
            if (panelTotal) panelTotal.textContent = finalTotal.toFixed(2);

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
        renderPanelClientSelected();
        if (hasConfiguredModes) renderModeEditTable();
        if (hasAddonConfigs) renderAddonEditTable();

        function saveAndPrint() {
            <?php if ($billingCompanyMissing): ?>
            alert('Assign a billing company on this plant asset in Fleet setup before pushing to ERP.');
            return;
            <?php endif; ?>
            if (!erpAvailable) {
                alert(erpDownMessage);
                return;
            }
            if (!panelClientCode || panelClientCode === 'TBC') {
                alert('Assign a valid ERP client before pushing this RFP.');
                return;
            }
            const btn = document.getElementById('printBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            const finalQty = getMasterQty();

            const fd = new FormData();
            fd.append('action', 'finalize_and_invoice');
            fd.append('booking_id', <?= $bookingId ?>);
            fd.append('hours', finalQty);
            fd.append('discount_pct', currentDiscountPct);

            const chitEl = document.getElementById('edit_delivery_chit_number');
            if (chitEl) fd.append('delivery_chit_number', chitEl.value.trim());

            if (hasConfiguredModes) {
                fd.append('mode_overrides', JSON.stringify(buildModeOverridesPayload()));
            }
            if (hasAddonConfigs) {
                fd.append('addon_overrides', JSON.stringify(buildAddonOverridesPayload()));
            }
            fd.append('apply_setup_fee', applySetupFee ? '1' : '0');
            
            const timeIn = document.getElementById('edit_time_in');
            const timeOut = document.getElementById('edit_time_out');
            if (timeIn && timeOut) {
                fd.append('time_in', timeIn.value);
                fd.append('time_out', timeOut.value);
            }

            fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r => r.text()).then(res => {
                if (res.trim() === 'OK') {
                    btn.innerHTML = '<i class="fas fa-check"></i> Saved!';
                    setTimeout(() => { location.reload(); }, 1200);
                } else { 
                    alert(res.includes('ERP') ? res : ('Could not push to ERP: ' + res)); 
                    btn.disabled = false; 
                    btn.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Save RFP & Push to ERP';
                }
            });
        }

        let invoiceErpClients = [];
        const invCompId = '<?= addslashes($job['billing_company_id'] ?? '') ?>';
        const invBookingId = <?= $bookingId ?>;

        function loadInvoiceClients(callback) {
            if (!erpAvailable) return;
            fetch(`api/plant_actions.php?action=get_company_clients&company_id=${invCompId}`)
            .then(r => r.json())
            .then(res => {
                if (res && res.error === 'ERP_UNAVAILABLE') {
                    invoiceErpClients = [];
                } else {
                    invoiceErpClients = Array.isArray(res) ? res : (res.clients || []);
                }
                if (typeof callback === 'function') callback();
            })
            .catch(() => { invoiceErpClients = []; });
        }

        if (canEdit && erpAvailable) {
            loadInvoiceClients(() => {
                const panelInput = document.getElementById('panel_client_search');
                if (panelInput) panelInput.placeholder = 'Start typing client name...';
            });
        }

        function filterPanelClients(query) {
            const resultsDiv = document.getElementById('panel_client_results');
            if (!resultsDiv) return;
            if (query.length < 2) { resultsDiv.style.display = 'none'; return; }

            const q = query.toLowerCase().trim();
            const filtered = invoiceErpClients.filter(c => (c.name || '').toLowerCase().includes(q)).slice(0, 15);

            if (filtered.length === 0) {
                resultsDiv.innerHTML = '<div style="padding:15px; color:#ef4444; font-weight:bold;">No client found.</div>';
            } else {
                resultsDiv.innerHTML = filtered.map(c => {
                    if (c.status === 1) {
                        return `<div style="padding:12px; cursor:pointer; border-bottom:1px solid #e2e8f0; font-weight:bold; color:#0f172a;" onclick="saveNewClient('${c.code}', '${c.name.replace(/'/g, "\\'")}')">${escapeHtml(c.name)}<br><span style="color:#64748b; font-weight:normal; font-size:0.8rem;">Code: ${escapeHtml(c.code)}</span></div>`;
                    }
                    return `<div style="padding:12px; cursor:not-allowed; background:#f1f5f9; opacity:0.65;"><span style="font-weight:bold; color:#64748b; text-decoration:line-through;">${escapeHtml(c.name)}</span><br><span style="color:#ef4444; font-weight:bold; font-size:0.8rem;"><i class="fas fa-lock"></i> Blocked</span></div>`;
                }).join('');
            }
            resultsDiv.style.display = 'block';
        }

        function openClientEdit() {
            if (!erpAvailable) { alert(erpDownMessage); return; }
            document.getElementById('client-display-block').style.display = 'none';
            document.getElementById('client-edit-block').style.display = 'block';
            const input = document.getElementById('inv_client_search');

            if (invoiceErpClients.length === 0) {
                input.disabled = true;
                input.placeholder = "Loading ERP clients...";
                loadInvoiceClients(() => {
                    input.disabled = false;
                    input.placeholder = "Start typing client name...";
                    input.focus();
                });
            } else {
                input.disabled = false;
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

            document.getElementById('panel_client_results') && (document.getElementById('panel_client_results').style.display = 'none');
            document.getElementById('inv_client_results') && (document.getElementById('inv_client_results').style.display = 'none');

            const panelInput = document.getElementById('panel_client_search');
            const invInput = document.getElementById('inv_client_search');
            if (panelInput) { panelInput.value = 'Saving...'; panelInput.disabled = true; }
            if (invInput) { invInput.value = 'Saving...'; invInput.disabled = true; }

            const fd = new FormData();
            fd.append('action', 'update_job_client');
            fd.append('booking_id', invBookingId);
            fd.append('client_code', code);
            fd.append('client_name', name);

            fetch('api/plant_actions.php', { method: 'POST', body: fd })
            .then(r => r.text())
            .then(res => {
                if (res === 'OK') {
                    panelClientCode = code;
                    panelClientName = name;
                    document.getElementById('disp_client_name').textContent = name;
                    renderPanelClientSelected();
                    if (canDiscount && code && code !== 'TBC') {
                        fetch(`api/plant_actions.php?action=get_client_max_discount&client_code=${encodeURIComponent(code)}&company_id=${encodeURIComponent(invCompId)}`)
                        .then(r => r.json())
                        .then(data => {
                            maxAllowedDiscount = parseFloat(data.max_discount);
                            if (isNaN(maxAllowedDiscount)) maxAllowedDiscount = 0;
                            const label = document.getElementById('max_disc_label');
                            if (label) label.innerText = `(Max allowed: ${maxAllowedDiscount}%)`;
                            validateAndRenderDiscount();
                        });
                    }
                    cancelClientEdit();
                    if (panelInput) { panelInput.value = ''; panelInput.disabled = false; }
                } else {
                    alert(res);
                    cancelClientEdit();
                    if (panelInput) { panelInput.value = ''; panelInput.disabled = false; }
                    if (invInput) { invInput.value = ''; invInput.disabled = false; }
                }
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
