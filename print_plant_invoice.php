<?php
require_once 'init.php';
require_once 'session-check.php';
require_once 'user-functions.php';
require_once 'S3FileManager.php';

// Auto-deploy database updates for new columns to save custom rates locally
try { 
    $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN final_rate_fixed DECIMAL(10,2) DEFAULT NULL"); 
    $pdo->exec("ALTER TABLE plant_bookings ADD COLUMN final_rate_var DECIMAL(10,2) DEFAULT NULL"); 
} catch(PDOException $e) {}

$role = $_SESSION['role'] ?? '';
$isAdmin = ($role === 'admin');

$hasPlantAccess = in_array($role, ['admin', 'director', 'system_manager', 'accountant', 'plant_manager', 'plant_driver']);
if (!$hasPlantAccess && !hasPermission('view_plant_bookings')) {
    die("Unauthorized Access to Invoice.");
}

$bookingId = (int)($_GET['booking_id'] ?? 0);

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
    JOIN clients bc ON p.billing_company_id = bc.id
    LEFT JOIN projects prj ON pb.project_id = prj.id
    LEFT JOIN users drv ON pb.driver_id = drv.id
    WHERE pb.id = ?
");
$stmt->execute([$bookingId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) die("Job not found.");

$praApiKey = getenv('J2_API_KEY_PRA');
$praxApiKey = getenv('J2_API_KEY_PRAX');

if (!$praApiKey || !$praxApiKey) {
    die("Critical Error: ERP API keys are missing from environment configuration.");
}

$apiKeys = [
    '24' => $praApiKey,  
    '26' => $praxApiKey, 
    'default' => $praApiKey 
];
$apiKey = $apiKeys[$job['billing_company_id']] ?? $apiKeys['default'];

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

$allNominals = getJ2ApiData('/nominalcateg', $apiKey);
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
$logoPath = $job['developer_logo'];
if (!empty($logoPath) && strpos($logoPath, 'http') === false) {
    $logoPath = $s3->getPresignedUrl($logoPath, '+60 minutes');
}

$jobYear = date('Y', strtotime($job['booking_date']));
$jobRef = sprintf("PRA-%s-%04d", $jobYear, $bookingId);

$inTime = !empty($job['punch_in_time']) ? new DateTime($job['punch_in_time']) : new DateTime($job['booking_date'] . ' ' . $job['start_time']);
$outTime = !empty($job['punch_out_time']) ? new DateTime($job['punch_out_time']) : new DateTime($job['booking_date'] . ' ' . $job['end_time']);
$interval = $inTime->diff($outTime);
$hoursWorked = round($interval->h + ($interval->i / 60), 2);

$isTripBased = ($job['pricing_type'] == 'per_trip');
$qtyValue = $isTripBased ? ($job['qty_trips'] > 0 ? $job['qty_trips'] : 1) : $hoursWorked;
$qtyLabel = $isTripBased ? "Trips Executed" : "Total Hours Executed";

if ($isInternal) {
    $clientDisplay = htmlspecialchars($job['developer_name']);
    $clientCodeDisplay = "IN-HOUSE (" . htmlspecialchars($job['project_name']) . ")";
} else {
    $clientDisplay = !empty($job['client_name']) ? htmlspecialchars($job['client_name']) : 'N/A';
    $clientCodeDisplay = !empty($job['client_code']) ? htmlspecialchars($job['client_code']) : 'MISSING CODE';
}

$projectDisplay = $job['project_name'] ? htmlspecialchars($job['project_name']) : 'N/A';

// Determine Edit Lock State
$sysRef = $job['invoice_sysref'] ?? '';
$isSynced = !empty($sysRef) && !in_array($sysRef, ['N/A', 'SUCCESS_NO_REF']);

// Allow edit if it's the very first time (Pending) OR if an Admin is editing a Local Only RFP
$canEdit = ($job['payment_status'] === 'Pending') || ($isAdmin && !$isSynced);
?>

<!DOCTYPE html>
<html>
<head>
    <title>RFP / Delivery Note - <?= $jobRef ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #fff; color: #000; padding: 40px; font-size: 0.95rem; }
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
        <?php if ($canEdit): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                <div><i class="fas fa-edit text-blue-500"></i> <b>Edit Mode:</b> You can adjust the times, quantities, and rates below before pushing the final RFP to the ERP.</div>
                <div style="background:#e0e7ff; color:#4f46e5; padding:5px 10px; border-radius:6px; font-weight:bold;"><i class="fas fa-plug"></i> ERP Live Sync</div>
            </div>
            
            <div style="background: #fff; padding: 15px; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; gap: 15px;">
                <label style="font-weight:bold;">Final <?= $qtyLabel ?> to Bill:</label>
                <input type="number" id="calc_master_qty" class="live-calc" value="<?= $job['final_hours'] ?? $qtyValue ?>" step="0.25" oninput="renderTable()">
                <button id="printBtn" onclick="saveAndPrint()" style="padding:10px 20px; background:#10b981; color:#fff; border:none; font-weight:bold; cursor:pointer; border-radius: 8px; margin-left: auto;"><i class="fas fa-cloud-upload-alt"></i> Save RFP & Push to ERP</button>
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
                <h2 style="margin:0;"><?= htmlspecialchars($job['developer_name']) ?></h2>
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
            <div class="data-row"><span class="data-label">ERP Account Name</span><span class="data-val"><?= $clientDisplay ?></span></div>
            <div class="data-row"><span class="data-label">ERP Account Code</span><span class="data-val"><?= $clientCodeDisplay ?></span></div>
            <div class="data-row"><span class="data-label">Project / Location</span><span class="data-val"><?= $projectDisplay ?></span></div>
            <div class="data-row"><span class="data-label">Booking Type</span><span class="data-val" style="text-transform:uppercase;"><?= $job['booking_type'] ?></span></div>
        </div>
        <div class="box">
            <h4>Job Report (Execution Details)</h4>
            <div class="data-row"><span class="data-label">Machinery</span><span class="data-val"><?= htmlspecialchars($job['plant_name']) ?> (<?= htmlspecialchars($job['category']) ?>)</span></div>
            <div class="data-row"><span class="data-label">Reg Plate</span><span class="data-val"><?= htmlspecialchars($job['registration_plate'] ?? 'N/A') ?></span></div>
            <div class="data-row"><span class="data-label">Driver</span><span class="data-val"><?= htmlspecialchars($job['first_name'] ?? 'Unassigned') ?> <?= htmlspecialchars($job['last_name'] ?? '') ?></span></div>
            
            <div class="data-row">
                <span class="data-label">Time Logged</span>
                <span class="data-val">
                    <?php if ($canEdit && !$isTripBased): ?>
                        <input type="time" id="edit_time_in" class="live-calc" value="<?= $inTime->format('H:i') ?>" onchange="recalcHours()"> to 
                        <input type="time" id="edit_time_out" class="live-calc" value="<?= $outTime->format('H:i') ?>" onchange="recalcHours()">
                    <?php else: ?>
                        <?= $inTime->format('H:i') ?> to <?= $outTime->format('H:i') ?>
                        <input type="hidden" id="edit_time_in" value="<?= $inTime->format('H:i') ?>">
                        <input type="hidden" id="edit_time_out" value="<?= $outTime->format('H:i') ?>">
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
            
            <div style="margin-top: 20px; font-size: 0.85rem; color: #475569;">
                <b>Payment Instructions:</b> Payable to <?= htmlspecialchars($job['developer_name']) ?>.<br>
                Bank: <?= htmlspecialchars($job['bank_name'] ?? 'N/A') ?> | IBAN: <?= htmlspecialchars($job['iban'] ?? 'N/A') ?>
            </div>
        </div>
        
        <div class="totals-box">
            <div class="totals-row"><span class="data-label">Subtotal</span><span class="data-val" id="tot_subtotal">€ 0.00</span></div>
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
        
        const savedHours = <?= $job['final_hours'] ?? 0 ?>;
        
        // Determine if Setup Fee applies
        const hasSetupFee = <?= (!empty($job['apply_setup_fee']) && $job['apply_setup_fee'] == 1) ? 'true' : 'false' ?>;

        // Smart Rate Retrieval: Pulls from DB if explicitly saved, otherwise gracefully defaults to API/Zero
        let rateFixed = <?= isset($job['final_rate_fixed']) && $job['final_rate_fixed'] !== null ? (float)$job['final_rate_fixed'] : ($fixedNom ? (float)($isInternal ? $fixedNom['NCDefSP1'] : $fixedNom['NCDefSP2']) : 0) ?>;
        let rateVar = <?= isset($job['final_rate_var']) && $job['final_rate_var'] !== null ? (float)$job['final_rate_var'] : ($varNom ? (float)($isInternal ? $varNom['NCDefSP1'] : $varNom['NCDefSP2']) : 0) ?>;
        
        // Retrieve the setup fee value specifically
        let rateSetup = <?= isset($job['final_setup_fee']) && $job['final_setup_fee'] !== null ? (float)$job['final_setup_fee'] : (float)($job['setup_fee'] ?? 0) ?>;
        
        let currentSubtotal = 0;

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
            let totalQty = qtyInput ? (parseFloat(qtyInput.value) || 0) : savedHours;
            
            const tbody = document.getElementById('lines-body');
            let html = '';
            currentSubtotal = 0;

            const fRateInput = canEdit ? `<input type="number" class="live-calc text-right" style="width:75px;" value="${rateFixed.toFixed(2)}" onchange="rateFixed = parseFloat(this.value) || 0; renderTable();">` : rateFixed.toFixed(2);
            const vRateInput = canEdit ? `<input type="number" class="live-calc text-right" style="width:75px;" value="${rateVar.toFixed(2)}" onchange="rateVar = parseFloat(this.value) || 0; renderTable();">` : rateVar.toFixed(2);
            
            // Render Setup Fee first if applicable
            if (hasSetupFee) {
                const sRateInput = canEdit ? `<input type="number" class="live-calc text-right" style="width:75px;" value="${rateSetup.toFixed(2)}" onchange="rateSetup = parseFloat(this.value) || 0; renderTable();">` : rateSetup.toFixed(2);
                currentSubtotal += rateSetup;
                
                html += `<tr>
                    <td><b>${rawNomSetup}</b></td>
                    <td>Setup / Mobilisation Fee<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">1.00</td>
                    <td class="text-right">${sRateInput}</td>
                    <td class="text-right"><b>${rateSetup.toFixed(2)}</b></td>
                </tr>`;
            }

            if (pricingType === 'fixed_then_hourly') {
                const fCode = rawNomFixed || 'MISSING';
                const fDesc = 'Fixed Callout Charge';
                currentSubtotal += rateFixed;
                
                html += `<tr>
                    <td><b>${fCode}</b></td>
                    <td>${fDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">1.00</td>
                    <td class="text-right">${fRateInput}</td>
                    <td class="text-right"><b>${rateFixed.toFixed(2)}</b></td>
                </tr>`;

                const extraHours = Math.max(0, totalQty - minHours);
                if (extraHours > 0) {
                    const vCode = rawNomVar || 'MISSING';
                    const vDesc = 'Additional Hourly Rate';
                    const vTotal = extraHours * rateVar;
                    currentSubtotal += vTotal;
                    
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
                const tTotal = totalQty * rateFixed;
                currentSubtotal += tTotal;

                html += `<tr>
                    <td><b>${tCode}</b></td>
                    <td>${tDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">${totalQty.toFixed(2)} Trips</td>
                    <td class="text-right">${fRateInput}</td>
                    <td class="text-right"><b>${tTotal.toFixed(2)}</b></td>
                </tr>`;
            } 
            else {
                const hCode = rawNomVar || 'MISSING';
                const hDesc = 'Standard Hourly Operation';
                const hTotal = totalQty * rateVar;
                currentSubtotal += hTotal;

                html += `<tr>
                    <td><b>${hCode}</b></td>
                    <td>${hDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">${totalQty.toFixed(2)} Hrs</td>
                    <td class="text-right">${vRateInput}</td>
                    <td class="text-right"><b>${hTotal.toFixed(2)}</b></td>
                </tr>`;
            }

            tbody.innerHTML = html;

            const vat = currentSubtotal * 0.18;
            const total = currentSubtotal + vat;

            document.getElementById('tot_subtotal').innerText = '€ ' + currentSubtotal.toFixed(2);
            document.getElementById('tot_vat').innerText = '€ ' + vat.toFixed(2);
            document.getElementById('tot_final').innerText = total.toFixed(2);
        }

        renderTable();

        function saveAndPrint() {
            const btn = document.getElementById('printBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            const finalQty = document.getElementById('calc_master_qty').value;

            const fd = new FormData();
            fd.append('action', 'finalize_and_invoice');
            fd.append('booking_id', <?= $bookingId ?>);
            fd.append('hours', finalQty); 
            fd.append('subtotal', currentSubtotal); 
            
            // Pass the explicitly modified rates to be saved permanently
            fd.append('rate_fixed', rateFixed);
            fd.append('rate_var', rateVar);
            
            if (hasSetupFee) {
                fd.append('setup_fee', rateSetup);
            }
            
            // Pass the explicitly modified times
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
    </script>
</body>
</html>