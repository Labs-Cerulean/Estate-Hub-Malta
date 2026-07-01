<?php
/**
 * print_plant_pricelist.php - Plant Hub Capabilities & Pricing Matrix
 * Features: Lifecycle Auditing, JSON Configuration Mapping, and Live ERP Rates
 */
require_once 'init.php';
require_once 'session-check.php';
require_once 'user-functions.php';
require_once 'S3FileManager.php';

// Strict Role and Permission Authorization Protection
$role = $_SESSION['role'] ?? '';
$hasPlantAccess = in_array($role, ['admin', 'director', 'system_manager', 'accountant', 'plant_manager']);
if (!$hasPlantAccess && !hasPermission('view_plant_bookings') && !hasPermission('manage_plant_fleet')) {
    die("Unauthorized Access to Plant Price List.");
}

// 1. Fetch Secure Environment API Keys
$apiKeys = [
    '24' => getenv('J2_API_KEY_PRA'),  
    '26' => getenv('J2_API_KEY_PRAX'), 
    'default' => getenv('J2_API_KEY_PRA')
];

if (!$apiKeys['24'] || !$apiKeys['26']) {
    die("Critical Error: ERP API keys are missing from environment configuration.");
}

// 2. Bulletproof Secure API Fetcher with Health Tracking
function getNominalCatalog($apiKey, &$apiHealthFlag) {
    $url = "https://j2api.agiusgroup.com/api/public/nominalcateg";
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); 
    
    $response = curl_exec($ch); 
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        $apiHealthFlag = true;
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }
    
    $apiHealthFlag = false;
    return [];
}

// 3. Fetch Cloudflare R2 Logos
$s3 = new S3FileManager();
$headerLogos = [];
try {
    $logoStmt = $pdo->query("SELECT name, logo_path FROM clients WHERE id IN (24, 26) AND logo_path IS NOT NULL AND logo_path != ''");
    while ($cl = $logoStmt->fetch(PDO::FETCH_ASSOC)) {
        $lPath = $cl['logo_path'];
        if (strpos($lPath, 'http') === false) {
            $lPath = $s3->getPresignedUrl($lPath, '+60 minutes');
        }
        $headerLogos[] = [
            'name' => $cl['name'],
            'url'  => $lPath
        ];
    }
} catch (Exception $e) {}

// 4. Fetch All Active Machinery with New Capabilities
try {
    $query = "SELECT p.*, c.name as owner_name, bc.name as billing_company_name 
              FROM plants p 
              LEFT JOIN clients c ON p.developer_client_id = c.id 
              LEFT JOIN clients bc ON p.billing_company_id = bc.id 
              WHERE p.status = 'Active' 
              ORDER BY p.category, p.name ASC";
    $plants = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database lookup failed: " . $e->getMessage());
}

// 5. Batch Indexing & API Health Matrix
$nominalCache = [];
$apiHealthMatrix = [];

foreach ($plants as $p) {
    $bcId = $p['billing_company_id'] ?? 'default';
    if (!isset($nominalCache[$bcId])) {
        $targetKey = $apiKeys[$bcId] ?? $apiKeys['default'];
        
        $apiIsHealthy = false;
        $rawNominals = getNominalCatalog($targetKey, $apiIsHealthy);
        
        $apiHealthMatrix[$bcId] = $apiIsHealthy;
        $indexedData = [];
        
        if ($apiIsHealthy && is_array($rawNominals)) {
            foreach ($rawNominals as $n) {
                if (isset($n['NCCode'])) {
                    $indexedData[trim((string)$n['NCCode'])] = $n;
                }
            }
        }
        $nominalCache[$bcId] = $indexedData;
    }
}

// 6. Pre-calculate Fleet Health Metrics
$metrics = [
    'total' => count($plants),
    'healthy' => 0,
    'action_required' => 0,
    'erp_errors' => 0
];

foreach ($plants as &$p) {
    $bcProfile = $p['billing_company_id'] ?? 'default';
    $companyCatalog = $nominalCache[$bcProfile] ?? [];
    $erpOnline = $apiHealthMatrix[$bcProfile] ?? false;
    
    $p['fixed_data'] = (!empty($p['nom_code_fixed']) && isset($companyCatalog[trim($p['nom_code_fixed'])])) ? $companyCatalog[trim($p['nom_code_fixed'])] : null;
    $p['var_data'] = (!empty($p['nom_code_variable']) && isset($companyCatalog[trim($p['nom_code_variable'])])) ? $companyCatalog[trim($p['nom_code_variable'])] : null;
    $p['setup_data'] = (!empty($p['nom_code_setup']) && isset($companyCatalog[trim($p['nom_code_setup'])])) ? $companyCatalog[trim($p['nom_code_setup'])] : null;
    
    // Parse the new JSON Configurations into an array
    $p['parsed_configs'] = [];
    if ($p['has_configurations'] == 1 && !empty($p['configurations'])) {
        $decoded = json_decode($p['configurations'], true);
        if (is_array($decoded)) {
            foreach ($decoded as &$cfg) {
                $cfgNom = trim($cfg['nom_code'] ?? '');
                $cfg['erp_data'] = isset($companyCatalog[$cfgNom]) ? $companyCatalog[$cfgNom] : null;
            }
            $p['parsed_configs'] = $decoded;
        }
    }

    $p['is_valid_model'] = in_array($p['pricing_type'], ['fixed_then_hourly', 'hourly', 'per_trip', 'daily']);
    $p['is_fixed_req'] = in_array($p['pricing_type'], ['fixed_then_hourly', 'per_trip', 'daily']);
    $p['is_var_req'] = in_array($p['pricing_type'], ['fixed_then_hourly', 'hourly']);
    
    $isSetupMisconfigured = false;
    if ((float)$p['setup_fee'] > 0 && empty($p['nom_code_setup'])) {
        $isSetupMisconfigured = true;
    }
    
    $p['is_misconfigured'] = !$p['is_valid_model'] || ($p['is_fixed_req'] && !$p['fixed_data']) || ($p['is_var_req'] && !$p['var_data']) || $isSetupMisconfigured;
    $p['erp_online'] = $erpOnline;

    if (!$erpOnline) {
        $metrics['erp_errors']++;
    } elseif ($p['is_misconfigured']) {
        $metrics['action_required']++;
    } else {
        $metrics['healthy']++;
    }
}
unset($p);

function formatPricingModel($type) {
    switch ($type) {
        case 'fixed_then_hourly': return 'Fixed + Hourly Overtime';
        case 'per_trip':          return 'Per Trip Logged';
        case 'hourly':            return 'Standard Hourly Rate';
        case 'daily':             return 'Daily Flat Rate';
        default:                  return 'Not Defined';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fleet Capabilities & Pricing Matrix</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #0f172a; padding: 30px; font-size: 0.9rem; line-height: 1.4; }
        .page-container { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); max-width: 1600px; margin: 0 auto; }
        
        .no-print-bar { display: flex; justify-content: space-between; align-items: center; background: #fff; border: 1px solid #e2e8f0; padding: 15px 25px; border-radius: 10px; margin-bottom: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .print-btn { background: #3b82f6; color: #fff; font-weight: 700; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; transition: background 0.2s; }
        .print-btn:hover { background: #2563eb; }
        .edit-btn { background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; margin-left: 10px; }
        .edit-btn:hover { background: #e2e8f0; color: #0f172a; }

        .metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 35px; }
        .metric-card { background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px; text-align: center; }
        .metric-val { font-size: 1.8rem; font-weight: 900; color: #0f172a; }
        .metric-label { font-size: 0.8rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 5px; }
        
        .header-section { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 3px solid #0f172a; padding-bottom: 15px; margin-bottom: 25px; }
        .print-logos { display: flex; gap: 20px; align-items: center; padding-right: 20px; border-right: 2px solid #e2e8f0; }
        .print-logos img { height: 45px; max-width: 140px; object-fit: contain; }
        
        .title-block h1 { font-size: 1.8rem; font-weight: 900; margin: 0; text-transform: uppercase; letter-spacing: -0.5px; }
        .title-block p { color: #64748b; margin: 3px 0 0 0; font-weight: 500; }
        .meta-block { text-align: right; color: #475569; font-size: 0.85rem; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 40px; font-size: 0.82rem; }
        th { background: #0f172a; color: #fff; text-transform: uppercase; font-weight: 700; padding: 12px 10px; font-size: 0.78rem; letter-spacing: 0.5px; }
        td { padding: 15px 10px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        tr:nth-child(even) td { background: #f8fafc; }
        
        .vehicle-info b { font-size: 1rem; color: #1e3a8a; }
        .company-tag { display: block; font-size: 0.8rem; color: #334155; margin-top: 8px; border-left: 2px solid #cbd5e1; padding-left: 8px; }
        
        /* Badges */
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 0.75rem; border: 1px solid transparent; margin-bottom: 6px; }
        .badge-driver-yes { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .badge-driver-no { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
        
        .badge-life-std { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        .badge-life-multi { background: #fdf4ff; color: #a21caf; border-color: #f5d0fe; }
        .badge-life-auto { background: #fff7ed; color: #b45309; border-color: #fde68a; }

        .badge-price { background: #f8fafc; color: #334155; border-color: #cbd5e1; }
        .badge-error { background: #fef2f2; color: #ef4444; border: 1px dashed #ef4444; }
        
        .nominal-cell { background: #fff; border-radius: 6px; padding: 10px; border: 1px solid #e2e8f0; box-shadow: 0 1px 2px rgba(0,0,0,0.02); margin-bottom: 8px; }
        .nominal-cell strong { display: block; color: #0f172a; font-family: monospace; font-size: 0.9rem; }
        .nominal-desc { color: #64748b; font-size: 0.75rem; display: block; margin-bottom: 6px; }
        .rate-grid { display: flex; gap: 10px; font-size: 0.75rem; border-top: 1px solid #e2e8f0; padding-top: 6px; margin-top: 4px; }
        .rate-item { flex: 1; }
        .rate-label { color: #64748b; font-weight: 500; }
        .rate-value { font-weight: 800; color: #0f172a; font-size: 0.8rem; }
        
        .config-box { background: #f8fafc; border: 1px dashed #cbd5e1; padding: 10px; border-radius: 6px; margin-top: 10px; }
        .config-box h5 { margin: 0 0 8px 0; color: #0f172a; font-size: 0.8rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
        .config-row { display: flex; justify-content: space-between; font-size: 0.75rem; border-bottom: 1px solid #e2e8f0; padding: 4px 0; }
        .config-row:last-child { border-bottom: none; }
        .config-tag { font-weight: 800; color: #3b82f6; text-transform: uppercase; font-size: 0.65rem; background: #eff6ff; padding: 2px 4px; border-radius: 4px; }
        
        .error-msg { color: #ef4444; font-weight: 800; font-size: 0.75rem; display: flex; align-items: center; gap: 6px; }
        .warning-row td { background: #fef2f2 !important; }
        
        @media print {
            @page { size: landscape; margin: 10mm; }
            .no-print-bar, .no-print, .metrics-grid { display: none !important; }
            body { padding: 0; background: #fff; font-size: 10px; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .page-container { padding: 0; box-shadow: none; max-width: 100%; border: none; }
            th { padding: 8px !important; }
            td { padding: 8px !important; }
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="no-print-bar">
        <div>
            <span style="font-weight: 800; color: #0f172a; font-size: 1.1rem;"><i class="fas fa-shield-alt text-blue-500"></i> Capabilities Matrix Console</span>
            <p style="margin: 3px 0 0 0; font-size: 0.85rem; color: #64748b;">Mapping internal assets against dispatch workflows and ERP ledger rules.</p>
        </div>
        <button class="print-btn" onclick="window.print()"><i class="fas fa-file-pdf"></i> Generate System Map</button>
    </div>

    <div class="metrics-grid no-print">
        <div class="metric-card">
            <div class="metric-val"><?= $metrics['total'] ?></div>
            <div class="metric-label">Total Fleet</div>
        </div>
        <div class="metric-card" style="border-bottom: 3px solid #10b981;">
            <div class="metric-val text-green-600"><?= $metrics['healthy'] ?></div>
            <div class="metric-label">Fully Configured</div>
        </div>
        <div class="metric-card" style="border-bottom: 3px solid #ef4444;">
            <div class="metric-val" style="color: <?= $metrics['action_required'] > 0 ? '#ef4444' : '#0f172a' ?>;"><?= $metrics['action_required'] ?></div>
            <div class="metric-label">Action Required</div>
        </div>
        <div class="metric-card" style="border-bottom: 3px solid #f59e0b;">
            <div class="metric-val" style="color: <?= $metrics['erp_errors'] > 0 ? '#f59e0b' : '#0f172a' ?>;"><?= $metrics['erp_errors'] ?></div>
            <div class="metric-label">ERP Offline Fails</div>
        </div>
    </div>

    <div class="header-section">
        <div style="display: flex; align-items: center; gap: 20px;">
            <?php if (!empty($headerLogos)): ?>
                <div class="print-logos">
                    <?php foreach ($headerLogos as $logo): ?>
                        <img src="<?= htmlspecialchars($logo['url']) ?>" alt="<?= htmlspecialchars($logo['name']) ?> Logo">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <div class="title-block">
                <h1>Capabilities & Pricing Matrix</h1>
                <p>System Workflows, Operational Flags & Active Ledger Mapping</p>
            </div>
        </div>
        
        <div class="meta-block">
            <div>Run Date: <b><?= date('d M Y (H:i)') ?></b></div>
            <div>Authorized By: <b><?= htmlspecialchars($_SESSION['username']) ?></b></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 20%; text-align: left;">Asset & Billing Profile</th>
                <th style="width: 20%; text-align: left;">Operational Logic</th>
                <th style="width: 20%; text-align: left;">Pricing Matrix & Setup</th>
                <th style="width: 40%; text-align: left;">ERP Live Rates & Custom Configurations</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($plants)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; font-weight: bold; padding: 40px; color: #64748b; font-size: 1rem;">
                        No active vehicles found in the fleet database.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($plants as $p): 
                    $rowClass = ($p['is_misconfigured'] && $p['erp_online']) ? 'warning-row' : '';
                    
                    // Driver Badge
                    $reqDriver = $p['requires_driver'] !== null ? (int)$p['requires_driver'] : 1;
                    $driverBadge = $reqDriver ? '<span class="badge badge-driver-yes"><i class="fas fa-user-check"></i> Driver Required</span>' : '<span class="badge badge-driver-no"><i class="fas fa-robot"></i> Auto/Static (No Driver)</span>';
                    
                    // Lifecycle Badge
                    $lifeCycle = $p['lifecycle_type'] ?: 'Standard';
                    if ($lifeCycle === 'Standard') $lifeBadge = '<span class="badge badge-life-std"><i class="fas fa-sun"></i> Std Shift (1-Day)</span>';
                    elseif ($lifeCycle === 'Multi-Day') $lifeBadge = '<span class="badge badge-life-multi"><i class="fas fa-calendar-alt"></i> Multi-Day Continuous</span>';
                    else $lifeBadge = '<span class="badge badge-life-auto"><i class="fas fa-stopwatch"></i> Auto-Scheduled</span>';
                ?>
                    <tr class="<?= $rowClass ?>">
                        
                        <td>
                            <div class="vehicle-info">
                                <b><?= htmlspecialchars($p['name']) ?></b>
                                <a href="plant_bookings.php" class="edit-btn no-print" title="Manage Asset">Edit</a>
                                <span style="font-size: 0.75rem; color: #64748b; display:block; margin-top:2px; font-weight:600;">Cat: <?= htmlspecialchars($p['category'] ?: 'General') ?> | Reg: <?= htmlspecialchars($p['registration_plate'] ?: 'N/A') ?></span>
                            </div>
                            <div class="company-tag">
                                <b>Billing:</b> <?= htmlspecialchars($p['billing_company_name'] ?: 'Not Assigned') ?><br>
                                <b>Owner:</b> <?= htmlspecialchars($p['owner_name'] ?: 'Not Assigned') ?>
                            </div>
                        </td>

                        <td>
                            <?= $lifeBadge ?><br>
                            <?= $driverBadge ?>
                            
                            <?php if ($p['has_configurations'] == 1): ?>
                                <br><span class="badge" style="background:#fefce8; color:#a16207; border:1px solid #fef08a;"><i class="fas fa-sliders-h"></i> Advanced Configs Active</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge badge-price"><i class="fas fa-money-bill-wave"></i> <?= formatPricingModel($p['pricing_type']) ?></span>
                            
                            <?php if ($p['pricing_type'] === 'fixed_then_hourly' && $p['min_hours'] > 0): ?>
                                <div style="font-size: 0.75rem; color: #475569; margin-top: 4px; font-weight: 600;">
                                    Min Threshold: <b><?= (float)$p['min_hours'] ?> Hrs</b>
                                </div>
                            <?php endif; ?>

                            <?php if ((float)$p['setup_fee'] > 0): ?>
                                <div style="margin-top: 10px; border-top: 1px dashed #cbd5e1; padding-top: 8px;">
                                    <span style="font-size: 0.75rem; color: #1e3a8a; font-weight: 800;"><i class="fas fa-truck-loading"></i> Setup / Mob. Fee</span>
                                    
                                    <?php if (!$p['erp_online']): ?>
                                        <div class="error-msg" style="margin-top:4px;"><i class="fas fa-wifi"></i> ERP Offline</div>
                                    <?php elseif ($p['setup_data']): ?>
                                        <div class="nominal-cell" style="margin-top: 4px; border-color: #bfdbfe; background: #eff6ff; padding: 6px;">
                                            <strong style="color: #1e40af; font-size: 0.8rem;"><?= htmlspecialchars(trim($p['setup_data']['NCCode'])) ?></strong>
                                            <div class="rate-grid" style="border-color: #bfdbfe; margin-top: 2px; padding-top: 2px;">
                                                <div class="rate-item"><span class="rate-label" style="color: #3b82f6;">Hse:</span> <span class="rate-value" style="color: #1e40af;">€<?= number_format((float)$p['setup_data']['NCDefSP1'], 2) ?></span></div>
                                                <div class="rate-item"><span class="rate-label" style="color: #3b82f6;">Com:</span> <span class="rate-value" style="color: #1e40af;">€<?= number_format((float)$p['setup_data']['NCDefSP2'], 2) ?></span></div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="error-msg" style="margin-top:4px;"><i class="fas fa-exclamation-triangle"></i> Code Missing</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div style="display:flex; gap:10px; margin-bottom: 10px;">
                                <div style="flex:1;">
                                    <?php if ($p['is_fixed_req']): ?>
                                        <?php if (!$p['erp_online']): ?>
                                            <div class="error-msg"><i class="fas fa-wifi"></i> Offline</div>
                                        <?php elseif ($p['fixed_data']): ?>
                                            <div class="nominal-cell">
                                                <strong><span style="color:#64748b; font-size:0.7rem; font-family:sans-serif;">BASE:</span> <?= htmlspecialchars(trim($p['fixed_data']['NCCode'])) ?></strong>
                                                <span class="nominal-desc" title="<?= htmlspecialchars($p['fixed_data']['NCDesc']) ?>"><?= htmlspecialchars($p['fixed_data']['NCDesc']) ?></span>
                                                <div class="rate-grid">
                                                    <div class="rate-item"><span class="rate-label">Hse:</span> <span class="rate-value">€<?= number_format((float)$p['fixed_data']['NCDefSP1'], 2) ?></span></div>
                                                    <div class="rate-item"><span class="rate-label">Com:</span> <span class="rate-value">€<?= number_format((float)$p['fixed_data']['NCDefSP2'], 2) ?></span></div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="error-msg"><i class="fas fa-exclamation-triangle"></i> Fixed Nom Required</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <div style="flex:1;">
                                    <?php if ($p['is_var_req']): ?>
                                        <?php if (!$p['erp_online']): ?>
                                            <div class="error-msg"><i class="fas fa-wifi"></i> Offline</div>
                                        <?php elseif ($p['var_data']): ?>
                                            <div class="nominal-cell">
                                                <strong><span style="color:#64748b; font-size:0.7rem; font-family:sans-serif;">VAR:</span> <?= htmlspecialchars(trim($p['var_data']['NCCode'])) ?></strong>
                                                <span class="nominal-desc" title="<?= htmlspecialchars($p['var_data']['NCDesc']) ?>"><?= htmlspecialchars($p['var_data']['NCDesc']) ?></span>
                                                <div class="rate-grid">
                                                    <div class="rate-item"><span class="rate-label">Hse:</span> <span class="rate-value">€<?= number_format((float)$p['var_data']['NCDefSP1'], 2) ?></span></div>
                                                    <div class="rate-item"><span class="rate-label">Com:</span> <span class="rate-value">€<?= number_format((float)$p['var_data']['NCDefSP2'], 2) ?></span></div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="error-msg"><i class="fas fa-exclamation-triangle"></i> Var Nom Required</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($p['has_configurations'] == 1 && !empty($p['parsed_configs'])): ?>
                                <div class="config-box">
                                    <h5><i class="fas fa-sliders-h" style="color:#3b82f6;"></i> Selectable Modes / Add-ons</h5>
                                    <?php foreach ($p['parsed_configs'] as $cfg): ?>
                                        <div class="config-row">
                                            <div style="flex:1;">
                                                <span class="config-tag"><?= htmlspecialchars($cfg['type']) ?></span> 
                                                <b><?= htmlspecialchars($cfg['name']) ?></b>
                                            </div>
                                            <div style="flex:1; text-align:right; color:#475569;">
                                                <?php if (!empty($cfg['erp_data'])): ?>
                                                    [Code: <b><?= htmlspecialchars(trim($cfg['nom_code'])) ?></b>] &nbsp; 
                                                    <b style="color:#0f172a;">€<?= number_format((float)$cfg['erp_data']['NCDefSP2'], 2) ?></b>
                                                <?php else: ?>
                                                    [Code: <b><?= htmlspecialchars($cfg['nom_code'] ?? 'None') ?></b>] &nbsp; 
                                                    <b style="color:#ef4444;"><i class="fas fa-exclamation-triangle"></i> Offline/Error</b>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>
