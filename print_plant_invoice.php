<?php
require_once 'config.php';
require_once 'session-check.php';

$bookingId = $_GET['booking_id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT pb.*, p.name as plant_name, p.registration_plate, p.inhouse_rate, p.external_rate, 
           c.name as developer_name, c.logo_path as developer_logo, 
           c.bank_name, c.iban, c.swift_bic, 
           prj.name as project_name, drv.first_name, drv.last_name
    FROM plant_bookings pb 
    JOIN plants p ON pb.plant_id = p.id
    JOIN clients c ON p.developer_client_id = c.id
    LEFT JOIN projects prj ON pb.project_id = prj.id
    LEFT JOIN users drv ON pb.driver_id = drv.id
    WHERE pb.id = ?
");
$stmt->execute([$bookingId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) die("Job not found.");

// --- CLOUDFLARE R2 LOGO PATH FIX ---
require_once 'S3FileManager.php';
$s3 = new S3FileManager();

$logoPath = $job['developer_logo'];
if (!empty($logoPath) && strpos($logoPath, 'http') === false) {
    // Generate the presigned URL so the PDF can read it
    $logoPath = $s3->getPresignedUrl($logoPath, '+60 minutes');
}

// Calculate Default Auto-Rate vs Saved Rate
if ($job['final_hours'] !== null) {
    // Load the permanently saved values if it was already invoiced!
    $hoursWorked = $job['final_hours'];
    $applicableRate = $job['final_rate'];
} else {
    // Auto-calculate for the first time
    $inTime = new DateTime($job['punch_in_time']);
    $outTime = new DateTime($job['punch_out_time']);
    $interval = $inTime->diff($outTime);
    $hoursWorked = round($interval->h + ($interval->i / 60), 2);
    $applicableRate = $job['booking_type'] == 'in-house' ? $job['inhouse_rate'] : $job['external_rate'];
}

$clientDisplay = $job['booking_type'] == 'in-house' ? $job['project_name'] : $job['client_name'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Request - Job #<?= $bookingId ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #fff; color: #000; padding: 40px; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 20px; margin-bottom: 30px; }
        .logo { max-width: 200px; max-height: 80px; }
        .title { font-size: 2rem; font-weight: 900; text-transform: uppercase; }
        .grid { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .box { padding: 15px; border: 1px solid #ccc; width: 45%; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th, td { border: 1px solid #000; padding: 12px; text-align: left; }
        th { background: #f1f5f9; }
        .totals { text-align: right; font-size: 1.2rem; }
        .live-calc { border: none; font-size: 1rem; font-family: inherit; background: transparent; width: 80px; font-weight: bold; border-bottom: 1px dashed #cbd5e1; outline: none; }
        .live-calc:focus { border-bottom: 2px solid #3b82f6; }
        
        @media print { 
            .no-print { display: none; } 
            .live-calc { border: none; margin: 0; padding: 0; width: auto; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="background: #f8fafc; border: 1px solid #cbd5e1; padding: 15px; border-radius: 8px; margin-bottom: 30px; font-size: 0.9rem; color: #475569;">
        <?php if ($job['payment_status'] === 'Pending'): ?>
            <i class="fas fa-info-circle text-blue-500"></i> <b>Accountant Note:</b> Review the Hours and Rate below. Clicking Finalize will permanently save these values to the database and mark the job as Invoiced.
            <button id="printBtn" onclick="saveAndPrint()" style="display:block; padding:15px; background:#10b981; color:#fff; border:none; font-weight:bold; cursor:pointer; margin-top:15px; width:100%; border-radius: 8px; font-size: 1.1rem;"><i class="fas fa-save"></i> Finalize & Print to PDF</button>
        <?php else: ?>
            <i class="fas fa-check-circle" style="color: #10b981;"></i> <b>Invoice Saved.</b> These values have been permanently locked into the system.
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
            <div>Job Ref: #<?= $bookingId ?></div>
        </div>
    </div>

    <div class="grid">
        <div class="box">
            <b>Billed To:</b><br>
            <?= htmlspecialchars($clientDisplay) ?><br>
            <i><?= $job['booking_type'] == 'in-house' ? 'Internal Project Allocation' : 'External Client' ?></i>
        </div>
        <div class="box">
            <b>Job Details:</b><br>
            Plant: <?= htmlspecialchars($job['plant_name']) ?> (<?= htmlspecialchars($job['registration_plate']) ?>)<br>
            Driver: <?= htmlspecialchars($job['first_name']) ?> <?= htmlspecialchars($job['last_name']) ?><br>
            Date of Execution: <?= date('d M Y', strtotime($job['booking_date'])) ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description (Delivery Note Details)</th>
                <th>Hours Worked</th>
                <th>Rate / Hr</th>
                <th>Amount (Excl. VAT)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    Heavy Plant Operation.<br>
                    Punch In: <?= date('H:i', strtotime($job['punch_in_time'])) ?><br>
                    Punch Out: <?= date('H:i', strtotime($job['punch_out_time'])) ?>
                </td>
                <td><input type="number" class="live-calc" id="calc_hours" value="<?= $hoursWorked ?>" step="0.25" oninput="recalc()" <?= $job['payment_status'] !== 'Pending' ? 'readonly' : '' ?>> Hrs</td>
                <td>€ <input type="number" class="live-calc" id="calc_rate" value="<?= $applicableRate ?>" step="0.01" oninput="recalc()" <?= $job['payment_status'] !== 'Pending' ? 'readonly' : '' ?>></td>
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
            SWIFT/BIC: <?= !empty($job['swift_bic']) ? htmlspecialchars($job['swift_bic']) : '<i>Not Provided</i>' ?><br>
            <br>
            <i>Please quote Job Ref #<?= $bookingId ?> in the transfer.</i>
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
        function recalc() {
            const hrs = parseFloat(document.getElementById('calc_hours').value) || 0;
            const rate = parseFloat(document.getElementById('calc_rate').value) || 0;
            const subtotal = hrs * rate;
            const vat = subtotal * 0.18;
            const total = subtotal + vat;

            document.getElementById('display_subtotal').innerText = subtotal.toFixed(2);
            document.getElementById('tot_subtotal').innerText = subtotal.toFixed(2);
            document.getElementById('tot_vat').innerText = vat.toFixed(2);
            document.getElementById('tot_final').innerText = total.toFixed(2);
        }
        recalc();

        function saveAndPrint() {
            const btn = document.getElementById('printBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving to Database...';
            btn.disabled = true;

            const fd = new FormData();
            fd.append('action', 'save_invoice');
            fd.append('booking_id', <?= $bookingId ?>);
            fd.append('hours', document.getElementById('calc_hours').value);
            fd.append('rate', document.getElementById('calc_rate').value);

            fetch('api/plant_actions.php', { method: 'POST', body: fd })
            .then(r => r.text())
            .then(res => {
                if (res === 'OK') {
                    btn.innerHTML = '<i class="fas fa-check"></i> Saved! Preparing PDF...';
                    setTimeout(() => {
                        window.print();
                        location.reload(); // Reload to lock the fields
                    }, 800);
                } else {
                    alert("Error saving: " + res);
                    btn.disabled = false;
                }
            });
        }
    </script>
</body>
</html>
