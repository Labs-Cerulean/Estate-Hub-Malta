<?php
require_once 'init.php';
require_once 'session-check.php';

// Verification
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$sub_id = isset($_GET['sub_id']) ? (int)$_GET['sub_id'] : null;

if (!$client_id || !$sub_id) die("Missing required parameters.");

// 1. Fetch Client details (and Logo)
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch();
if (!$client) die("Client not found.");

// 2. Fetch Subcontractor Details
$stmt = $pdo->prepare("SELECT * FROM subcontractors WHERE id = ?");
$stmt->execute([$sub_id]);
$sub = $stmt->fetch();

// 3. Fetch Works
$wStmt = $pdo->prepare("
    SELECT w.*, p.name as project_name, c.name as project_client_name,
    (SELECT SUM(amount) FROM subcontractor_transactions WHERE work_id = w.id AND transaction_type = 'Certification') as cert_total,
    (SELECT SUM(amount) FROM subcontractor_transactions WHERE work_id = w.id AND transaction_type = 'Invoice') as inv_total,
    (SELECT SUM(amount) FROM subcontractor_transactions WHERE work_id = w.id AND transaction_type = 'Payment') as pay_total
    FROM subcontractor_works w 
    LEFT JOIN projects p ON w.project_id = p.id 
    LEFT JOIN clients c ON p.clientid = c.id
    WHERE w.subcontractor_id = ? AND w.client_id = ? 
    ORDER BY w.id ASC
");
$wStmt->execute([$sub_id, $client_id]); 
$works = $wStmt->fetchAll();

// 4. Fetch Transactions
$tStmt = $pdo->prepare("
    SELECT t.*, w.work_reference 
    FROM subcontractor_transactions t
    LEFT JOIN subcontractor_works w ON t.work_id = w.id
    WHERE t.subcontractor_id = ? AND t.client_id = ? 
    ORDER BY t.transaction_date DESC, t.id DESC
");
$tStmt->execute([$sub_id, $client_id]); 
$transactions = $tStmt->fetchAll();

// Global Calcs
$tot_cert = 0; $tot_paid = 0; $tot_inv = 0;
foreach ($transactions as $t) {
    if ($t['transaction_type'] === 'Certification') $tot_cert += $t['amount'];
    if ($t['transaction_type'] === 'Payment') $tot_paid += $t['amount'];
    if ($t['transaction_type'] === 'Invoice') $tot_inv += $t['amount'];
}
$due_cert_global = $tot_cert - $tot_paid;
$due_inv_global = $tot_inv - $tot_paid;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Statement - <?= htmlspecialchars($sub['name']) ?> - <?= htmlspecialchars($client['name']) ?></title>
    <style>
        /* Print-Optimized Styles */
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #111827; background: #fff; line-height: 1.5; margin: 0; padding: 20px; font-size: 12px; }
        .page-container { max-width: 1000px; margin: 0 auto; }
        
        .header-section { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #e5e7eb; padding-bottom: 20px; margin-bottom: 20px; }
        .logo-box { max-width: 250px; max-height: 80px; }
        .logo-box img { max-width: 100%; max-height: 80px; object-fit: contain; }
        
        .title-box { text-align: right; }
        .title-box h1 { margin: 0; font-size: 24px; color: #1f2937; letter-spacing: 0.5px; text-transform: uppercase; }
        .title-box p { margin: 5px 0 0 0; color: #6b7280; font-size: 12px; }
        
        .info-grid { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .info-block { width: 48%; }
        .info-block h3 { margin: 0 0 5px 0; font-size: 11px; color: #6b7280; text-transform: uppercase; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; }
        .info-block p { margin: 3px 0; font-size: 14px; font-weight: bold; color: #111827; }
        .info-block span { font-weight: normal; font-size: 12px; color: #4b5563; }

        .summary-grid { display: flex; gap: 15px; margin-bottom: 30px; }
        .summary-box { flex: 1; padding: 15px; border-radius: 6px; text-align: center; border: 1px solid #e5e7eb; background: #f9fafb; }
        .summary-box h4 { margin: 0 0 5px 0; font-size: 11px; color: #6b7280; text-transform: uppercase; }
        .summary-box .val { font-size: 18px; font-weight: bold; color: #111827; }
        
        .print-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 11px; }
        .print-table th { background: #f3f4f6; padding: 10px; text-align: left; font-weight: bold; border-bottom: 2px solid #d1d5db; color: #374151; }
        .print-table td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .print-table .num { text-align: right; }
        .print-table .strong { font-weight: bold; }

        .footer { margin-top: 40px; padding-top: 10px; border-top: 1px solid #e5e7eb; text-align: center; font-size: 10px; color: #9ca3af; }

        @media print {
            body { padding: 0; }
            .page-container { width: 100%; max-width: 100%; }
            @page { margin: 1.5cm; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body onload="window.print()">

<div class="page-container">

    <div class="header-section">
        <div class="logo-box">
            <?php if (!empty($client['logo_path'])): ?>
                <img src="<?= htmlspecialchars($client['logo_path']) ?>" alt="Client Logo">
            <?php else: ?>
                <h2 style="margin:0; color:#374151; font-size: 20px;"><?= htmlspecialchars($client['name']) ?></h2>
            <?php endif; ?>
        </div>
        <div class="title-box">
            <h1>Subcontractor Account Statement</h1>
            <p>Generated on: <?= date('d F Y') ?></p>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-block">
            <h3>Contracting Client</h3>
            <p><?= htmlspecialchars($client['name']) ?></p>
            <span><?= htmlspecialchars($client['city'] ?? '') ?></span>
        </div>
        <div class="info-block" style="text-align: right;">
            <h3>Subcontractor / Payee</h3>
            <p><?= htmlspecialchars($sub['name']) ?></p>
            <span>Attn: <?= htmlspecialchars($sub['contact_person'] ?? 'N/A') ?></span><br>
            <span><?= htmlspecialchars($sub['email'] ?? '') ?></span>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-box">
            <h4>Total Certified (Inc VAT)</h4>
            <div class="val">€<?= number_format($tot_cert, 2) ?></div>
        </div>
        <div class="summary-box">
            <h4>Total Invoiced</h4>
            <div class="val">€<?= number_format($tot_inv, 2) ?></div>
        </div>
        <div class="summary-box">
            <h4>Total Paid to Date</h4>
            <div class="val">€<?= number_format($tot_paid, 2) ?></div>
        </div>
        <div class="summary-box" style="border-color: #374151; background: #f3f4f6;">
            <h4 style="color: #111827;">True Liability (Owed)</h4>
            <div class="val">€<?= number_format($due_cert_global, 2) ?></div>
        </div>
    </div>

    <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #111827; border-bottom: 1px solid #111827; padding-bottom: 5px;">Schedule of Works</h3>
    <table class="print-table">
        <thead>
            <tr>
                <th>Work Reference</th>
                <th>Project</th>
                <th class="num">Order Value</th>
                <th class="num">Certified</th>
                <th class="num">Invoiced</th>
                <th class="num">Paid</th>
                <th class="num">Due (True)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($works)): ?>
                <tr><td colspan="7" style="text-align:center; padding: 20px;">No works recorded.</td></tr>
            <?php else: ?>
                <?php foreach($works as $w): 
                    $c_tot = $w['cert_total'] ?: 0; $i_tot = $w['inv_total'] ?: 0; $p_tot = $w['pay_total'] ?: 0;
                    $due = $c_tot - $p_tot;
                ?>
                <tr>
                    <td>
                        <strong style="color: #111827;"><?= htmlspecialchars($w['work_reference']) ?></strong>
                        <?php if(!empty($w['po_reference'])): ?><br><span style="color:#6b7280; font-size:9px;">PO: <?= htmlspecialchars($w['po_reference']) ?></span><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($w['project_name'] ?? 'General') ?></td>
                    <td class="num">€<?= number_format($w['total_inc_vat'], 2) ?></td>
                    <td class="num strong">€<?= number_format($c_tot, 2) ?></td>
                    <td class="num">€<?= number_format($i_tot, 2) ?></td>
                    <td class="num">€<?= number_format($p_tot, 2) ?></td>
                    <td class="num strong" style="color: <?= $due > 0 ? '#b91c1c' : '#15803d' ?>;">€<?= number_format($due, 2) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #111827; border-bottom: 1px solid #111827; padding-bottom: 5px;">Transaction Ledger</h3>
    <table class="print-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Linked Work Order</th>
                <th>Reference / Invoice #</th>
                <th>Notes</th>
                <th class="num">Amount (Inc VAT)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($transactions)): ?>
                <tr><td colspan="6" style="text-align:center; padding: 20px;">No transactions recorded.</td></tr>
            <?php else: ?>
                <?php foreach($transactions as $t): ?>
                <tr>
                    <td><?= date('d M Y', strtotime($t['transaction_date'])) ?></td>
                    <td><strong><?= htmlspecialchars($t['transaction_type']) ?></strong></td>
                    <td style="color:#4b5563;"><?= htmlspecialchars($t['work_reference'] ?? 'Global / Unlinked') ?></td>
                    <td><?= htmlspecialchars($t['reference']) ?></td>
                    <td style="color:#6b7280; font-size: 10px; max-width: 250px;"><?= htmlspecialchars($t['notes']) ?></td>
                    <td class="num strong">€<?= number_format($t['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="footer">
        This is a system-generated statement intended for internal accounting and reconciliation. <br>
        Estate Hub Management System
    </div>

    <div class="no-print" style="text-align: center; margin-top: 30px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">Print / Save to PDF</button>
    </div>

</div>

</body>
</html>
