<?php
/**
 * print_plant_pricelist.php - Plant Hub Pricing Audit Tool & Price List
 * Features: API State Awareness, Health Metrics, and Cloudflare R2 Logo Integration
 */
require_once 'init.php';
require_once 'session-check.php';
require_once 'user-functions.php';
require_once 'S3FileManager.php'; // Required for Cloudflare R2 secure image loading

// Strict Role and Permission Authorization Protection
$role = $_SESSION['role'] ?? '';
$hasPlantAccess = in_array($role, ['admin', 'director', 'system_manager', 'accountant', 'plant_manager']);
if (!$hasPlantAccess && !hasPermission('view_plant_bookings') && !hasPermission('manage_plant_fleet')) {
    die("Unauthorized Access to Plant Price List.");
}

// 1. Fetch Secure Environment API Keys
$apiKeys = [
    '24' => getenv('J2_API_KEY_PRA'),  // PRA API Key
    '26' => getenv('J2_API_KEY_PRAX'), // PRAX API Key
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

// 3. Fetch Cloudflare R2 Logos for PRA (24) and PRAX (26)
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
} catch (Exception $e) {
    // Fail silently on logos so it doesn't break the whole page
}

// 4. Fetch All Active Machinery
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

// 6. Pre-calculate Fleet Health Metrics for Dashboard
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
    
    // NEW: Support for Setup/Mobilisation Data mapping
    $p['setup_data'] = (!empty($p['nom_code_setup']) && isset($companyCatalog[trim($p['nom_code_setup'])])) ? $companyCatalog[trim($p['nom_code_setup'])] : null;
    
    $p['is_valid_model'] = in_array($p['pricing_type'], ['fixed_then_hourly', 'hourly', 'per_trip']);
    $p['is_fixed_req'] = in_array($p['pricing_type'], ['fixed_then_hourly', 'per_trip']);
    $p['is_var_req'] = in_array($p['pricing_type'], ['fixed_then_hourly', 'hourly']);
    
    // Check if setup code was explicitly entered but is missing from the ERP
    $isSetupMisconfigured = (!empty($p['nom_code_setup']) && !$p['setup_data']);
    
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
        case 'fixed_then_hourly': return 'Fixed + Hourly Extra';
        case 'per_trip':          return 'Per Trip Logged';
        case 'hourly':            return 'Standard Hourly Rate';
        default:                  return 'Not Defined';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Plant Fleet Master Price List</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #0f172a; padding: 30px; font-size: 0.9rem; line-height: 1.4; }
        .page-container { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); max-width: 1500px; margin: 0 auto; }
        
        .no-print-bar { display: flex; justify-content: space-between; align-items: center; background: #fff; border: 1px solid #e2e8f0; padding: 15px 25px; border-radius: 10px; margin-bottom: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .print-btn { background: #3b82f6; color: #fff; font-weight: 700; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; transition: background 0.2s; }
        .print-btn:hover { background: #2563eb; }
        .edit-btn { background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; text-decoration: none; }
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
        
        .vehicle-info b { font-size: 0.95rem; color: #1e3a8a; display: block; }
        .vehicle-info span { font-size: 0.75rem; color: #64748b; font-weight: 600; display: block; margin-top: 2px; }
        .company-tag { display: block; font-size: 0.8rem; color: #334155; }
        .company-tag small { color: #94a3b8; display: block; font-size: 0.7rem; }
        
        .badge { display: inline-block; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 0.75rem; text-align: center; }
        .badge-fixed { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .badge-trip { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .badge-hourly { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .badge-error { background: #fef2f2; color: #ef4444; border: 1px dashed #ef4444; }
        
        .nominal-cell { background: #fff; border-radius: 6px; padding: 10px; border: 1px solid #e2e8f0; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .nominal-cell strong { display: block; color: #0f172a; font-family: monospace; font-size: 0.9rem; }
        .nominal-desc { color: #64748b; font-size: 0.75rem; display: block; margin-bottom: 6px; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rate-grid { display: flex; gap: 10px; font-size: 0.75rem; border-top: 1px solid #e2e8f0; padding-top: 6px; margin-top: 4px; }
        .rate-item { flex: 1; }
        .rate-label { color: #64748b; font-weight: 500; }
        .rate-value { font-weight: 800; color: #0f172a; font-size: 0.8rem; }
        
        .error-msg { background: #fef2f2; border: 1px dashed #ef4444; color: #ef4444; padding: 8px 12px; border-radius: 6px; font-weight: 800; font-size: 0.75rem; text-transform: uppercase; display: flex; align-items: center; gap: 6px; }
        .offline-msg { background: #fffbeb; border: 1px dashed #f59e0b; color: #d97706; padding: 8px 12px; border-radius: 6px; font-weight: 800; font-size: 0.75rem; text-transform: uppercase; display: flex; align-items: center; gap: 6px; }
        .warning-row td { background: #fef2f2 !important; }
        
        @media print {
            .no-print-bar, .no-print, .metrics-grid { display: none !important; }
            body { 
                padding: 0; 
                background: #fff; 
                color: #000; 
                font-size: 0.75rem; 
                -webkit-print-color-adjust: exact !important; 
                print-color-adjust: exact !important; 
            }
            .page-container { padding: 0; box-shadow: none; max-width: 100%; border: none; }
            th { background: #0f172a !important; color: #fff !important; padding: 10px 8px !important; border-bottom: none !important; }
            td { border-bottom: 1px solid #e2e8f0 !important; padding: 10px 8px !important; }
            tr:nth-child(even) td { background: #f8fafc !important; }
            .warning-row td { background: #fef2f2 !important; }
            .error-msg { background: #fef2f2 !important; border: 1px solid #ef4444 !important; color: #ef4444 !important; }
            .offline-msg { background: #fffbeb !important; border: 1px solid #f59e0b !important; color: #b45309 !important; }
            .nominal-cell { background: #fff !important; border: 1px solid #e2e8f0 !important; padding: 8px !important; }
            .rate-grid { border-top: 1px solid #e2e8f0 !important; }
            .print-logos { border-right: 2px solid #e2e8f0 !important; }
            .print-logos img { max-height: 40px !important; max-width: 130px !important; object-fit: contain !important; }
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="no-print-bar">
        <div>
            <span style="font-weight: 800; color: #0f172a; font-size: 1.1rem;"><i class="fas fa-shield-alt text-blue-500"></i> Fleet Pricing Audit Console</span>
            <p style="margin: 3px 0 0 0; font-size: 0.85rem; color: #64748b;">Mapping internal assets against live ERP ledger rules in real-time.</p>
        </div>
        <button class="print-btn" onclick="window.print()"><i class="fas fa-file-pdf"></i> Generate PDF Report</button>
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
                <h1>Plant Fleet Master Price List</h1>
                <p>Internal Ledger Configuration & Active Pricing Matrix</p>
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
                <th style="width: 18%; text-align: left;">Machinery Details</th>
                <th style="width: 15%; text-align: left;">Company Assignments</th>
                <th style="width: 13%; text-align: center;">Pricing Model</th>
                <th style="width: 18%; text-align: left;">Setup / Mob. Nominal</th>
                <th style="width: 18%; text-align: left;">Fixed Callout Nominal</th>
                <th style="width: 18%; text-align: left;">Variable / Hourly Nominal</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($plants)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; font-weight: bold; padding: 40px; color: #64748b; font-size: 1rem;">
                        No active vehicles found in the fleet database.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($plants as $p): 
                    $rowClass = ($p['is_misconfigured'] && $p['erp_online']) ? 'warning-row' : '';
                    
                    $badgeStyle = 'badge-hourly';
                    if ($p['pricing_type'] === 'fixed_then_hourly') $badgeStyle = 'badge-fixed';
                    if ($p['pricing_type'] === 'per_trip') $badgeStyle = 'badge-trip';
                    if (!$p['is_valid_model']) $badgeStyle = 'badge-error';
                ?>
                    <tr class="<?= $rowClass ?>">
                        <td class="vehicle-info">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <b><?= htmlspecialchars($p['name']) ?></b>
                                <a href="plant_bookings.php" class="edit-btn no-print" title="Manage Asset"><i class="fas fa-edit"></i></a>
                            </div>
                            <span>Category: <?= htmlspecialchars($p['category'] ?: 'General') ?></span>
                            <?php if (!empty($p['registration_plate'])): ?>
                                <span style="font-family: monospace; color: #0f172a; font-size: 0.75rem; margin-top: 6px; background: #e2e8f0; display: inline-block; padding: 2px 6px; border-radius: 4px; border: 1px solid #cbd5e1;">
                                    <?= htmlspecialchars($p['registration_plate']) ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="company-tag">
                                <small>Owning Profile</small>
                                <?= htmlspecialchars($p['owner_name'] ?: 'Not Assigned') ?>
                            </span>
                            <span class="company-tag" style="margin-top: 8px;">
                                <small>ERP Billing Profile</small>
                                <b><?= htmlspecialchars($p['billing_company_name'] ?: 'Not Assigned') ?></b>
                            </span>
                        </td>

                        <td style="text-align: center;">
                            <span class="badge <?= $badgeStyle ?>">
                                <?= formatPricingModel($p['pricing_type']) ?>
                            </span>
                            <?php if ($p['pricing_type'] === 'fixed_then_hourly' && $p['min_hours'] > 0): ?>
                                <div style="font-size: 0.7rem; color: #475569; margin-top: 6px; font-weight: 600;">
                                    Threshold: <b><?= (float)$p['min_hours'] ?> Hrs</b>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($p['nom_code_setup'])): ?>
                                <?php if (!$p['erp_online']): ?>
                                    <span class="offline-msg"><i class="fas fa-wifi"></i> ERP Offline</span>
                                <?php elseif ($p['setup_data']): ?>
                                    <div class="nominal-cell" style="border-color: #bfdbfe; background: #eff6ff;">
                                        <strong style="color: #1e40af;"><?= htmlspecialchars(trim($p['setup_data']['NCCode'])) ?></strong>
                                        <span class="nominal-desc" title="<?= htmlspecialchars($p['setup_data']['NCDesc']) ?>"><?= htmlspecialchars($p['setup_data']['NCDesc']) ?></span>
                                        <div class="rate-grid" style="border-color: #bfdbfe;">
                                            <div class="rate-item">
                                                <span class="rate-label" style="color: #3b82f6;">In-Hse:</span>
                                                <span class="rate-value" style="color: #1e40af;">€<?= number_format((float)$p['setup_data']['NCDefSP1'], 2) ?></span>
                                            </div>
                                            <div class="rate-item">
                                                <span class="rate-label" style="color: #3b82f6;">Comm:</span>
                                                <span class="rate-value" style="color: #1e40af;">€<?= number_format((float)$p['setup_data']['NCDefSP2'], 2) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="error-msg"><i class="fas fa-exclamation-triangle"></i> Code Missing</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: #94a3b8; font-style: italic; font-size: 0.8rem;">No Setup Fee</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($p['is_fixed_req']): ?>
                                <?php if (!$p['erp_online']): ?>
                                    <span class="offline-msg"><i class="fas fa-wifi"></i> ERP Offline</span>
                                <?php elseif ($p['fixed_data']): ?>
                                    <div class="nominal-cell">
                                        <strong><?= htmlspecialchars(trim($p['fixed_data']['NCCode'])) ?></strong>
                                        <span class="nominal-desc" title="<?= htmlspecialchars($p['fixed_data']['NCDesc']) ?>"><?= htmlspecialchars($p['fixed_data']['NCDesc']) ?></span>
                                        <div class="rate-grid">
                                            <div class="rate-item">
                                                <span class="rate-label">In-Hse:</span>
                                                <span class="rate-value">€<?= number_format((float)$p['fixed_data']['NCDefSP1'], 2) ?></span>
                                            </div>
                                            <div class="rate-item">
                                                <span class="rate-label">Comm:</span>
                                                <span class="rate-value">€<?= number_format((float)$p['fixed_data']['NCDefSP2'], 2) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="error-msg"><i class="fas fa-exclamation-triangle"></i> Code required</span>
                                <?php endif; ?>
                            <?php elseif (!$p['is_valid_model']): ?>
                                <span class="error-msg"><i class="fas fa-ban"></i> Invalid Setup</span>
                            <?php else: ?>
                                <span style="color: #94a3b8; font-style: italic; font-size: 0.8rem;">Not Used by Model</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($p['is_var_req']): ?>
                                <?php if (!$p['erp_online']): ?>
                                    <span class="offline-msg"><i class="fas fa-wifi"></i> ERP Offline</span>
                                <?php elseif ($p['var_data']): ?>
                                    <div class="nominal-cell">
                                        <strong><?= htmlspecialchars(trim($p['var_data']['NCCode'])) ?></strong>
                                        <span class="nominal-desc" title="<?= htmlspecialchars($p['var_data']['NCDesc']) ?>"><?= htmlspecialchars($p['var_data']['NCDesc']) ?></span>
                                        <div class="rate-grid">
                                            <div class="rate-item">
                                                <span class="rate-label">In-Hse:</span>
                                                <span class="rate-value">€<?= number_format((float)$p['var_data']['NCDefSP1'], 2) ?></span>
                                            </div>
                                            <div class="rate-item">
                                                <span class="rate-label">Comm:</span>
                                                <span class="rate-value">€<?= number_format((float)$p['var_data']['NCDefSP2'], 2) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="error-msg"><i class="fas fa-exclamation-triangle"></i> Code required</span>
                                <?php endif; ?>
                            <?php elseif (!$p['is_valid_model']): ?>
                                <span class="error-msg"><i class="fas fa-ban"></i> Invalid Setup</span>
                            <?php else: ?>
                                <span style="color: #94a3b8; font-style: italic; font-size: 0.8rem;">Not Used by Model</span>
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