<?php
require_once 'init.php';
require_once 'session-check.php';
require_once 'user-functions.php';
require_once 'S3FileManager.php';

// Explicit authorization check to prevent the session manager from kicking the user
$role = $_SESSION['role'] ?? '';
$hasPlantAccess = in_array($role, ['admin', 'director', 'system_manager', 'accountant', 'plant_manager', 'plant_driver']);
if (!$hasPlantAccess && !hasPermission('view_plant_bookings')) {
    die("Unauthorized Access to Invoice.");
}

$bookingId = $_GET['booking_id'] ?? 0;

// 1. Fetch Job Data (Removed the non-existent prj.locality column)
$stmt = $pdo->prepare("
    SELECT pb.*, p.name as plant_name, p.registration_plate, p.category,
           p.pricing_type, p.min_hours, p.nom_code_fixed, p.nom_code_variable, p.billing_company_id,
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

$apiKey = 'o/7b6jY815wajiIhCBbvd69etum9GykU5IX1LSG9Zfs='; 

// 2. Bulletproof API Fetcher
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    $response = curl_exec($ch); 
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

// 3. Extract exact Nominal Details directly from ERP
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

// Cloudflare S3 Logo
$s3 = new S3FileManager();
$logoPath = $job['developer_logo'];
if (!empty($logoPath) && strpos($logoPath, 'http') === false) {
    $logoPath = $s3->getPresignedUrl($logoPath, '+60 minutes');
}

$jobYear = date('Y', strtotime($job['booking_date']));
$jobRef = sprintf("PRA-%s-%04d", $jobYear, $bookingId);

// Time Calculations
$inTime = !empty($job['punch_in_time']) ? new DateTime($job['punch_in_time']) : new DateTime($job['booking_date'] . ' ' . $job['start_time']);
$outTime = !empty($job['punch_out_time']) ? new DateTime($job['punch_out_time']) : new DateTime($job['booking_date'] . ' ' . $job['end_time']);
$interval = $inTime->diff($outTime);
$hoursWorked = round($interval->h + ($interval->i / 60), 2);

$isTripBased = ($job['pricing_type'] == 'per_trip');
$qtyValue = $isTripBased ? ($job['qty_trips'] > 0 ? $job['qty_trips'] : 1) : $hoursWorked;
$qtyLabel = $isTripBased ? "Trips Executed" : "Total Hours Executed";

$clientDisplay = $job['client_name'] ? htmlspecialchars($job['client_name']) : 'N/A';
$clientCodeDisplay = $job['client_code'] ? htmlspecialchars($job['client_code']) : 'MISSING CODE';
$projectDisplay = $job['project_name'] ? htmlspecialchars($job['project_name']) : 'N/A';
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
        
        .live-calc { border: 1px solid #cbd5e1; padding: 5px; font-size: 1rem; font-family: inherit; border-radius: 6px; width: 80px; font-weight: bold; text-align: center; }
        .live-calc:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        
        @media print { 
            .no-print { display: none; } 
            body { padding: 0; font-size: 10px; } 
            .live-calc { border: none; padding: 0; text-align: left; background: transparent; width: auto; }
            .box { background: transparent; }
            .totals-box { background: transparent; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="background: #f8fafc; border: 1px solid #cbd5e1; padding: 15px; border-radius: 8px; margin-bottom: 30px; color: #475569;">
        <?php if ($job['payment_status'] === 'Pending'): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                <div><i class="fas fa-info-circle text-blue-500"></i> <b>Accountant Note:</b> The line items below are generated dynamically from the ERP Nominal setup. Adjust the final quantity/hours to recalculate the exact breakdown.</div>
                <div style="background:#e0e7ff; color:#4f46e5; padding:5px 10px; border-radius:6px; font-weight:bold;"><i class="fas fa-plug"></i> ERP Live Sync</div>
            </div>
            
            <div style="background: #fff; padding: 15px; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; gap: 15px;">
                <label style="font-weight:bold;">Final <?= $qtyLabel ?> to Bill:</label>
                <input type="number" id="calc_master_qty" class="live-calc" value="<?= $job['final_hours'] ?? $qtyValue ?>" step="0.25" oninput="renderTable()">
                <button id="printBtn" onclick="saveAndPrint()" style="padding:10px 20px; background:#10b981; color:#fff; border:none; font-weight:bold; cursor:pointer; border-radius: 8px; margin-left: auto;"><i class="fas fa-cloud-upload-alt"></i> Finalize & Push to ERP</button>
            </div>
        <?php else: ?>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div><i class="fas fa-check-circle" style="color: #10b981;"></i> <b>Invoice Finalized & Synced.</b> <br>ERP Reference: <b><?= htmlspecialchars($job['invoice_sysref'] ?? 'N/A') ?></b></div>
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
            <div style="margin-top: 5px; color: #475569;">Date: <b><?= date('d M Y') ?></b></div>
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
            <div class="data-row"><span class="data-label">Time Logged</span><span class="data-val"><?= $inTime->format('H:i') ?> to <?= $outTime->format('H:i') ?></span></div>
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
            <!-- Rendered by JS -->
        </tbody>
    </table>

    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div style="width: 45%;">
            <div style="border: 1px solid #cbd5e1; padding: 15px; border-radius: 8px; text-align: center; background: #f8fafc;">
                <h4 style="margin-top:0; margin-bottom: 10px; color: #475569; text-transform: uppercase; font-size: 0.8rem;">Client Representative Verification</h4>
                <?php if(!empty($job['signature_data'])): ?>
                    <img src="<?= $job['signature_data'] ?>" style="max-width: 100%; height: 80px; object-fit: contain;">
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
        // Data injected securely from PHP
        const pricingType = '<?= $job['pricing_type'] ?>';
        const minHours = <?= (float)$job['min_hours'] ?>;
        const isInternal = <?= $isInternal ? 'true' : 'false' ?>;
        const jobRef = '<?= $jobRef ?>';
        
        const fixedNom = <?= $fixedNom ? json_encode($fixedNom) : 'null' ?>;
        const varNom = <?= $varNom ? json_encode($varNom) : 'null' ?>;

        const isSaved = <?= $job['payment_status'] !== 'Pending' ? 'true' : 'false' ?>;
        const savedSubtotal = <?= $job['final_subtotal'] ?? 0 ?>;
        const savedHours = <?= $job['final_hours'] ?? 0 ?>;

        let currentSubtotal = 0;
        let erpPayloadRate = 0; // The primary rate passed to the backend for standard lines

        function getRate(nomObj) {
            if(!nomObj) return 0;
            return isInternal ? parseFloat(nomObj.NCDefSP1) : parseFloat(nomObj.NCDefSP2);
        }

        function renderTable() {
            let totalQty = isSaved ? savedHours : (parseFloat(document.getElementById('calc_master_qty').value) || 0);
            const tbody = document.getElementById('lines-body');
            let html = '';
            currentSubtotal = 0;

            if (pricingType === 'fixed_then_hourly') {
                // Line 1: Fixed Minimum (Always Qty 1)
                const fCode = fixedNom ? fixedNom.NCCode.trim() : 'MISSING';
                const fDesc = fixedNom ? fixedNom.NCDesc.trim() : 'Fixed Callout Charge';
                const fRate = getRate(fixedNom);
                currentSubtotal += fRate;
                
                html += `<tr>
                    <td><b>${fCode}</b></td>
                    <td>${fDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">1.00</td>
                    <td class="text-right">${fRate.toFixed(2)}</td>
                    <td class="text-right"><b>${fRate.toFixed(2)}</b></td>
                </tr>`;

                // Line 2: Variable Extra Hours
                const extraHours = Math.max(0, totalQty - minHours);
                if (extraHours > 0) {
                    const vCode = varNom ? varNom.NCCode.trim() : 'MISSING';
                    const vDesc = varNom ? varNom.NCDesc.trim() : 'Additional Hourly Rate';
                    const vRate = getRate(varNom);
                    const vTotal = extraHours * vRate;
                    currentSubtotal += vTotal;
                    
                    html += `<tr>
                        <td><b>${vCode}</b></td>
                        <td>${vDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Extra Hours > ${minHours})</i></td>
                        <td class="text-right">${extraHours.toFixed(2)}</td>
                        <td class="text-right">${vRate.toFixed(2)}</td>
                        <td class="text-right"><b>${vTotal.toFixed(2)}</b></td>
                    </tr>`;
                }
                erpPayloadRate = 0; // Not needed, backend recalculates F+V based on nominals
            } 
            else if (pricingType === 'per_trip') {
                const tCode = fixedNom ? fixedNom.NCCode.trim() : 'MISSING';
                const tDesc = fixedNom ? fixedNom.NCDesc.trim() : 'Trip Execution Charge';
                const tRate = getRate(fixedNom);
                const tTotal = totalQty * tRate;
                currentSubtotal += tTotal;
                erpPayloadRate = tRate;

                html += `<tr>
                    <td><b>${tCode}</b></td>
                    <td>${tDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">${totalQty.toFixed(2)} Trips</td>
                    <td class="text-right">${tRate.toFixed(2)}</td>
                    <td class="text-right"><b>${tTotal.toFixed(2)}</b></td>
                </tr>`;
            } 
            else {
                // Standard Hourly
                const hCode = varNom ? varNom.NCCode.trim() : 'MISSING';
                const hDesc = varNom ? varNom.NCDesc.trim() : 'Standard Hourly Operation';
                const hRate = getRate(varNom);
                const hTotal = totalQty * hRate;
                currentSubtotal += hTotal;
                erpPayloadRate = hRate;

                html += `<tr>
                    <td><b>${hCode}</b></td>
                    <td>${hDesc}<br><i style="font-size:0.8rem; color:#64748b;">(Job Ref: ${jobRef})</i></td>
                    <td class="text-right">${totalQty.toFixed(2)} Hrs</td>
                    <td class="text-right">${hRate.toFixed(2)}</td>
                    <td class="text-right"><b>${hTotal.toFixed(2)}</b></td>
                </tr>`;
            }

            tbody.innerHTML = html;

            // Final safety check if it was previously saved
            if (isSaved && savedSubtotal > 0) { currentSubtotal = savedSubtotal; }

            const vat = currentSubtotal * 0.18;
            const total = currentSubtotal + vat;

            document.getElementById('tot_subtotal').innerText = '€ ' + currentSubtotal.toFixed(2);
            document.getElementById('tot_vat').innerText = '€ ' + vat.toFixed(2);
            document.getElementById('tot_final').innerText = total.toFixed(2);
        }

        // Initial Render
        renderTable();

        function saveAndPrint() {
            const btn = document.getElementById('printBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Pushing to ERP...';
            btn.disabled = true;

            const finalQty = document.getElementById('calc_master_qty').value;

            const fd = new FormData();
            fd.append('action', 'finalize_and_invoice');
            fd.append('booking_id', <?= $bookingId ?>);
            fd.append('hours', finalQty); // Acts as QTY or Hours
            fd.append('rate', erpPayloadRate); // The backend uses this if it's standard/trip
            fd.append('subtotal', currentSubtotal); // The absolute truth for the ledger

            fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r => r.text()).then(res => {
                if (res === 'OK') {
                    btn.innerHTML = '<i class="fas fa-check"></i> Synced to ERP!';
                    setTimeout(() => { window.print(); location.reload(); }, 1200);
                } else { 
                    alert(res); 
                    btn.disabled = false; 
                    btn.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Finalize & Push to ERP';
                }
            });
        }
    </script>
</body>
</html>
