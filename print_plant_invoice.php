<?php
require_once 'config.php';
require_once 'session-check.php';
require_once 'S3FileManager.php';

$bookingId = $_GET['booking_id'] ?? 0;

// 1. Fetch Job Data using the NEW billing_company_id
$stmt = $pdo->prepare("
    SELECT pb.*, p.name as plant_name, p.registration_plate, 
           p.pricing_type, p.min_hours, p.nom_code_fixed, p.nom_code_variable, p.billing_company_id,
           bc.name as developer_name, bc.logo_path as developer_logo, 
           bc.bank_name, bc.iban, bc.swift_bic, 
           prj.name as project_name, drv.first_name, drv.last_name
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

// 2. Fetch Live Pricing from J2 ERP
$apiKey = 'PASTE_YOUR_API_KEY_HERE'; 
$companyCode = ($job['billing_company_id'] == 26) ? 'PRAX' : 'PRA';

function getERPPrice($nomCode, $companyCode, $apiKey, $isInternal) {
    if(empty($nomCode)) return 0;
    $url = "https://j2api.agiusgroup.com/api/public/nominalcateg";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Accept: application/json", "Authorization: Bearer " . $apiKey, "Company: " . $companyCode]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    $res = json_decode(curl_exec($ch), true); curl_close($ch);
    
    if($res) {
        foreach($res as $n) {
            if(trim($n['NCCode']) == $nomCode) return $isInternal ? (float)$n['NCDefSP1'] : (float)$n['NCDefSP2'];
        }
    }
    return 0;
}

$isInternal = ($job['booking_type'] == 'in-house');
$fixedRate = getERPPrice($job['nom_code_fixed'], $companyCode, $apiKey, $isInternal);
$varRate = getERPPrice($job['nom_code_variable'], $companyCode, $apiKey, $isInternal);

// Determine the primary rate to show the accountant
$applicableRate = $fixedRate > 0 ? $fixedRate : $varRate;

// --- CLOUDFLARE R2 LOGO ---
$s3 = new S3FileManager();
$logoPath = $job['developer_logo'];
if (!empty($logoPath) && strpos($logoPath, 'http') === false) {
    $logoPath = $s3->getPresignedUrl($logoPath, '+60 minutes');
}

// Generate Professional Job Reference
$jobYear = date('Y', strtotime($job['booking_date']));
$jobRef = sprintf("PRA-%s-%04d", $jobYear, $bookingId);

// Time / Trip Calculation
$inTime = new DateTime($job['punch_in_time']);
$outTime = new DateTime($job['punch_out_time']);
$interval = $inTime->diff($outTime);
$hoursWorked = round($interval->h + ($interval->i / 60), 2);

$isTripBased = ($job['pricing_type'] == 'per_trip');
$qtyValue = $isTripBased ? ($job['qty_trips'] > 0 ? $job['qty_trips'] : 1) : $hoursWorked;
$qtyLabel = $isTripBased ? "Trips Executed" : "Hours Worked";

$clientDisplay = $job['booking_type'] == 'in-house' ? $job['project_name'] : $job['client_name'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Request - <?= $jobRef ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #fff; color: #000; padding: 40px; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 20px; margin-bottom: 30px; }
        .logo { max-width: 200px; max-height: 80px; object-fit: contain; }
        .title { font-size: 2rem; font-weight: 900; text-transform: uppercase; }
        .grid { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .box { padding: 15px; border: 1px solid #ccc; width: 45%; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th, td { border: 1px solid #000; padding: 12px; text-align: left; }
        th { background: #f1f5f9; }
        .totals { text-align: right; font-size: 1.2rem; }
        .live-calc { border: none; font-size: 1rem; font-family: inherit; background: transparent; width: 80px; font-weight: bold; border-bottom: 1px dashed #cbd5e1; outline: none; }
        .live-calc:focus { border-bottom: 2px solid #3b82f6; }
        @media print { .no-print { display: none; } .live-calc { border: none; margin: 0; padding: 0; width: auto; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="no-print" style="background: #f8fafc; border: 1px solid #cbd5e1; padding: 15px; border-radius: 8px; margin-bottom: 30px; font-size: 0.9rem; color: #475569;">
        <?php if ($job['payment_status'] === 'Pending'): ?>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div><i class="fas fa-info-circle text-blue-500"></i> <b>Accountant Note:</b> Pricing successfully synced from J2 ERP (<?= $companyCode ?>). Clicking Finalize will push the invoice payload to the ERP automatically.</div>
                <div style="background:#e0e7ff; color:#4f46e5; padding:5px 10px; border-radius:6px; font-weight:bold;"><i class="fas fa-plug"></i> ERP Live</div>
            </div>
            <button id="printBtn" onclick="saveAndPrint()" style="display:block; padding:15px; background:#10b981; color:#fff; border:none; font-weight:bold; cursor:pointer; margin-top:15px; width:100%; border-radius: 8px; font-size: 1.1rem;"><i class="fas fa-cloud-upload-alt"></i> Finalize & Push to ERP</button>
        <?php else: ?>
            <i class="fas fa-check-circle" style="color: #10b981;"></i> <b>Invoice Saved & Synced.</b> <br>ERP System Reference: <b><?= htmlspecialchars($job['invoice_sysref'] ?? 'N/A') ?></b>
            <button onclick="window.print()" style="display:block; padding:15px; background:#64748b; color:#fff; border:none; font-weight:bold; cursor:pointer; margin-top:15px; width:100%; border-radius: 8px; font-size: 1.1rem;"><i class="fas fa-print"></i> Re-Print PDF</button>
        <?php endif; ?>
    </div>

    <div class="header">
        <div>
            <?php if (!empty($logoPath)): ?>
                <img src="<?= htmlspecialchars($logoPath) ?>" class="logo">
            <?php else: ?>
                <h2><?= htmlspecialchars($job['developer_name']) ?></h2>
            <?php endif; ?>
        </div>
        <div style="text-align: right;">
            <div class="title">Request for Payment</div>
            <div>Date: <?= date('d M Y') ?></div>
            <div>Job Ref: <b><?= $jobRef ?></b></div>
        </div>
    </div>

    <div class="grid">
        <div class="box">
            <b>Billed To:</b><br>
            <?= htmlspecialchars($clientDisplay) ?><br>
            <i><?= $job['booking_type'] == 'in-house' ? 'Internal Project Allocation' : 'External Client (ERP)' ?></i>
        </div>
        <div class="box">
            <b>Job Details:</b><br>
            Plant: <?= htmlspecialchars($job['plant_name'] ?? '') ?> (<?= htmlspecialchars($job['registration_plate'] ?? 'N/A') ?>)<br>
            Driver: <?= htmlspecialchars($job['first_name'] ?? 'Unassigned') ?> <?= htmlspecialchars($job['last_name'] ?? '') ?><br>
            Date of Execution: <?= date('d M Y', strtotime($job['booking_date'])) ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description (Delivery Note Details)</th>
                <th><?= $qtyLabel ?></th>
                <th>Rate Profile</th>
                <th>Amount (Excl. VAT)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    Heavy Plant Operation.<br>
                    Start: <?= date('H:i', strtotime($job['punch_in_time'])) ?><br>
                    End: <?= date('H:i', strtotime($job['punch_out_time'])) ?>
                </td>
                <td><input type="number" class="live-calc" id="calc_qty" value="<?= $job['final_hours'] ?? $qtyValue ?>" step="0.25" oninput="recalc()" <?= $job['payment_status'] !== 'Pending' ? 'readonly' : '' ?>> <?= $isTripBased ? 'Trips' : 'Hrs' ?></td>
                
                <td>
                    <div id="rate_desc_label" style="font-size:0.85rem; color:#475569; font-weight:bold; margin-bottom:5px;"></div>
                    € <input type="number" class="live-calc" id="calc_rate" value="<?= $job['final_rate'] ?? $applicableRate ?>" step="0.01" oninput="recalc()" <?= $job['payment_status'] !== 'Pending' ? 'readonly' : '' ?>>
                </td>
                
                <td>€ <span id="display_subtotal">0.00</span></td>
            </tr>
        </tbody>
    </table>

    <div class="totals">
        <b>Subtotal:</b> € <span id="tot_subtotal">0.00</span><br>
        <b>VAT (18%):</b> € <span id="tot_vat">0.00</span><br>
        <b style="font-size: 1.5rem;">Total Due: € <span id="tot_final">0.00</span></b>
    </div>

    <div style="margin-top: 50px; display: flex; justify-content: space-between;">
        <div>
            <h4>Payment Instructions</h4>
            Payable to: <b><?= htmlspecialchars($job['developer_name']) ?></b><br>
            Bank: <?= !empty($job['bank_name']) ? htmlspecialchars($job['bank_name']) : '<i>Not Provided</i>' ?><br>
            IBAN: <?= !empty($job['iban']) ? htmlspecialchars($job['iban']) : '<i>Not Provided</i>' ?><br>
            SWIFT/BIC: <?= !empty($job['swift_bic']) ? htmlspecialchars($job['swift_bic']) : '<i>Not Provided</i>' ?><br><br>
            <i>Please quote Job Ref <b><?= $jobRef ?></b> in the transfer.</i>
        </div>
        
        <div>
            <h4>Client Acknowledgment</h4>
            <div style="border: 1px solid #ccc; padding: 10px; text-align: center;">
                <img src="<?= $job['signature_data'] ?>" style="max-width: 250px; max-height: 100px;"><br>
                <b>Signed by:</b> <?= htmlspecialchars($job['client_rep_name']) ?><br>
                <b>ID:</b> <?= htmlspecialchars($job['client_rep_id_card']) ?>
            </div>
        </div>
    </div>

    <script>
        const pricingType = '<?= $job['pricing_type'] ?>';
        const minHours = <?= (float)$job['min_hours'] ?>;
        
        // Feed the secondary variable rate in for advanced hybrid logic
        const varRate = <?= $varRate ?>; 
        const isTripBased = <?= $isTripBased ? 'true' : 'false' ?>;
        
        const isSaved = <?= $job['payment_status'] !== 'Pending' ? 'true' : 'false' ?>;
        const savedSubtotal = <?= $job['final_subtotal'] ?? 0 ?>;

        let currentSubtotal = 0;

        function recalc() {
            const qty = parseFloat(document.getElementById('calc_qty').value) || 0;
            const primaryRate = parseFloat(document.getElementById('calc_rate').value) || 0;

            if (isSaved && savedSubtotal > 0) {
                currentSubtotal = savedSubtotal;
                document.getElementById('rate_desc_label').innerText = pricingType === 'fixed_then_hourly' ? "Minimum/Hourly Mix" : (isTripBased ? "Per Trip Rate" : "Standard Hourly");
            } else {
                if (pricingType === 'fixed_then_hourly') {
                    if (qty <= minHours) {
                        currentSubtotal = primaryRate; // The fixed "Minimum" price
                        document.getElementById('rate_desc_label').innerText = `ERP Fixed Minimum (≤ ${minHours} hrs)`;
                    } else {
                        // Base fixed rate PLUS any extra hours multiplied by the variable rate
                        currentSubtotal = primaryRate + ((qty - minHours) * varRate);
                        document.getElementById('rate_desc_label').innerText = `Fixed + ${(qty - minHours).toFixed(2)} Extra Hrs (@ €${varRate})`;
                    }
                } else if (pricingType === 'per_trip') {
                    currentSubtotal = qty * primaryRate;
                    document.getElementById('rate_desc_label').innerText = `ERP Fixed Rate (Per Trip)`;
                } else {
                    currentSubtotal = qty * primaryRate;
                    document.getElementById('rate_desc_label').innerText = `Standard Hourly`;
                }
            }

            const vat = currentSubtotal * 0.18;
            const total = currentSubtotal + vat;

            document.getElementById('display_subtotal').innerText = currentSubtotal.toFixed(2);
            document.getElementById('tot_subtotal').innerText = currentSubtotal.toFixed(2);
            document.getElementById('tot_vat').innerText = vat.toFixed(2);
            document.getElementById('tot_final').innerText = total.toFixed(2);
        }
        recalc();

        function saveAndPrint() {
            const btn = document.getElementById('printBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Pushing to ERP...';
            btn.disabled = true;

            const fd = new FormData();
            fd.append('action', 'finalize_and_invoice');
            fd.append('booking_id', <?= $bookingId ?>);
            fd.append('hours', document.getElementById('calc_qty').value); // Acts as QTY or Hours
            fd.append('rate', document.getElementById('calc_rate').value);
            fd.append('subtotal', currentSubtotal);

            fetch('api/plant_actions.php', { method: 'POST', body: fd }).then(r => r.text()).then(res => {
                if (res === 'OK') {
                    btn.innerHTML = '<i class="fas fa-check"></i> Synced to ERP!';
                    setTimeout(() => { window.print(); location.reload(); }, 1200);
                } else { 
                    alert(res); // Shows exactly what the ERP rejected
                    btn.disabled = false; 
                    btn.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Finalize & Push to ERP';
                }
            });
        }
    </script>
</body>
</html>
