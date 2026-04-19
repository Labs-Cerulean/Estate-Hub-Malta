<?php
require_once 'config.php';
require_once 'session-check.php';

$bookingId = $_GET['booking_id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT pb.*, p.name as plant_name, p.registration_plate, p.hourly_rate, 
           c.name as developer_name, c.logo_path as developer_logo,
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

// Calculations
$inTime = new DateTime($job['punch_in_time']);
$outTime = new DateTime($job['punch_out_time']);
$interval = $inTime->diff($outTime);
$hoursWorked = $interval->h + ($interval->i / 60);

$subtotal = round($hoursWorked * $job['hourly_rate'], 2);
$vat = round($subtotal * 0.18, 2); // Assuming 18% Malta VAT
$total = $subtotal + $vat;

$clientDisplay = $job['booking_type'] == 'in-house' ? $job['project_name'] : $job['client_name'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Request - Job #<?= $bookingId ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
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
        .signature-box { margin-top: 50px; border-top: 1px solid #000; padding-top: 10px; width: 300px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <button class="no-print" onclick="window.print()" style="padding:15px; background:#10b981; color:#fff; border:none; font-weight:bold; cursor:pointer; margin-bottom:20px; width:100%;">Print to PDF</button>

    <div class="header">
        <div>
            <?php if ($job['developer_logo']): ?>
                <img src="<?= htmlspecialchars($job['developer_logo']) ?>" class="logo">
            <?php else: ?>
                <h2><?= htmlspecialchars($job['developer_name']) ?></h2>
            <?php endif; ?>
        </div>
        <div style="text-align: right;">
            <div class="title">Request for Payment</div>
            <div>Date: <?= date('d M Y') ?></div>
            <div>Job Reference: #<?= $bookingId ?></div>
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
            Plant: <?= $job['plant_name'] ?> (<?= $job['registration_plate'] ?>)<br>
            Driver: <?= $job['first_name'] ?> <?= $job['last_name'] ?><br>
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
                <td><?= number_format($hoursWorked, 2) ?> Hrs</td>
                <td>€<?= number_format($job['hourly_rate'], 2) ?></td>
                <td>€<?= number_format($subtotal, 2) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="totals">
        <b>Subtotal:</b> €<?= number_format($subtotal, 2) ?><br>
        <b>VAT (18%):</b> €<?= number_format($vat, 2) ?><br>
        <b style="font-size: 1.5rem;">Total Due: €<?= number_format($total, 2) ?></b>
    </div>

    <div style="margin-top: 50px; display: flex; justify-content: space-between;">
        <div>
            <h4>Payment Instructions</h4>
            Payable to: <b><?= htmlspecialchars($job['developer_name']) ?></b><br>
            IBAN: MTXXXXXXXXXXXXXXXXXXXXXX<br>
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
</body>
</html>
