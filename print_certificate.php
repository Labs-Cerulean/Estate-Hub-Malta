<?php
require_once 'init.php';
require_once 'session-check.php';
require_once 'S3FileManager.php';

$txId = isset($_GET['tx_id']) ? (int)$_GET['tx_id'] : null;
if (!$txId) die("Transaction ID is missing.");

$s3 = new S3FileManager();

// Fetch the Certificate Transaction
$txStmt = $pdo->prepare("SELECT * FROM subcontractor_transactions WHERE id = ? AND transaction_type = 'Certification'");
$txStmt->execute([$txId]);
$tx = $txStmt->fetch(PDO::FETCH_ASSOC);

if (!$tx) die("Certification record not found.");
if (!$tx['work_id']) die("Certifications must be linked to a Work Order to generate a certificate.");

// Fetch the Linked Work Order
$wStmt = $pdo->prepare("SELECT w.*, p.name as project_name FROM subcontractor_works w LEFT JOIN projects p ON w.project_id = p.id WHERE w.id = ?");
$wStmt->execute([$tx['work_id']]);
$work = $wStmt->fetch(PDO::FETCH_ASSOC);
if (!$work) die("Linked Work Order not found.");

// Fetch Client & Logo
$cStmt = $pdo->prepare("SELECT name, city, logo_path FROM clients WHERE id = ?");
$cStmt->execute([$work['client_id']]);
$client = $cStmt->fetch(PDO::FETCH_ASSOC);

$logoSrc = '';
if (!empty($client['logo_path'])) {
    if (strpos($client['logo_path'], 'http') === false) {
        $logoSrc = $s3->getPresignedUrl($client['logo_path'], '+60 minutes');
    } else {
        $logoSrc = $client['logo_path'];
    }
}

// Fetch Subcontractor
$sStmt = $pdo->prepare("SELECT name, contact_person, email FROM subcontractors WHERE id = ?");
$sStmt->execute([$work['subcontractor_id']]);
$sub = $sStmt->fetch(PDO::FETCH_ASSOC);

// ==========================================
// POINT-IN-TIME HISTORICAL CALCULATIONS
// ==========================================

// Fetch Historical Certificates for the Audit Trail AND Sum (FIXED: Using 'notes' instead of 'description')
$historyStmt = $pdo->prepare("
    SELECT id, transaction_date, reference, notes, amount as amount_inc_vat 
    FROM subcontractor_transactions 
    WHERE work_id = ? 
      AND transaction_type = 'Certification' 
      AND (transaction_date < ? OR (transaction_date = ? AND id < ?))
    ORDER BY transaction_date ASC, id ASC
");
$historyStmt->execute([$work['id'], $tx['transaction_date'], $tx['transaction_date'], $txId]);
$historicalCerts = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$prev_cert = 0;
foreach ($historicalCerts as $hc) {
    $prev_cert += (float)$hc['amount_inc_vat'];
}

$this_cert = (float)$tx['amount'];
$total_to_date = $prev_cert + $this_cert;

// Parse the JSON BoQ data if it was a measured claim
$boqProgress = [];
if ($work['is_measured'] && !empty($tx['boq_data'])) {
    $boqProgress = json_decode($tx['boq_data'], true);
    
    // We need the descriptions from the main boq table
    $boqDescStmt = $pdo->prepare("SELECT id, description, total_exc FROM subcontractor_boq WHERE work_id = ?");
    $boqDescStmt->execute([$work['id']]);
    $boqDescriptions = [];
    foreach ($boqDescStmt->fetchAll(PDO::FETCH_ASSOC) as $bd) {
        $boqDescriptions[$bd['id']] = $bd;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Certificate - <?= htmlspecialchars($tx['reference'] ?: $txId) ?></title>
    <style>
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
        .print-table td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: middle; }
        .print-table .num { text-align: right; }
        .print-table .strong { font-weight: bold; }

        .history-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 10px; }
        .history-table th { background: #f9fafb; padding: 8px; text-align: left; border-bottom: 1px solid #d1d5db; color: #4b5563; }
        .history-table td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; color: #4b5563; }

        .signatures-grid { display: flex; justify-content: space-between; margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; }
        .signature-block { width: 30%; text-align: center; }
        .signature-line { border-bottom: 1px solid #111827; height: 40px; margin-bottom: 10px; }

        .footer { margin-top: 40px; padding-top: 10px; border-top: 1px solid #e5e7eb; text-align: center; font-size: 10px; color: #9ca3af; }

        @media print {
            body { padding: 0; }
            .page-container { width: 100%; max-width: 100%; }
            @page { margin: 1.5cm; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="page-container">

    <div class="no-print" style="margin-bottom: 20px; padding: 15px; background: #f3f4f6; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #d1d5db;">
        <div>
            <h3 style="margin: 0; color: #1f2937;">Certificate Print Preview</h3>
            <p style="margin: 5px 0 0 0; color: #6b7280; font-size: 12px;">This document reflects the exact status on the date of certification.</p>
        </div>
        <button onclick="window.print()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold;">🖨️ Print / Save as PDF</button>
    </div>

    <div class="header-section">
        <div class="logo-box">
            <?php if (!empty($logoSrc)): ?>
                <img src="<?= htmlspecialchars($logoSrc) ?>" alt="Client Logo">
            <?php else: ?>
                <h2 style="margin:0; color:#374151; font-size: 20px;"><?= htmlspecialchars($client['name']) ?></h2>
            <?php endif; ?>
        </div>
        <div class="title-box">
            <h1>Payment Certificate</h1>
            <p>Date of Certification: <?= date('d F Y', strtotime($tx['transaction_date'])) ?></p>
            <p style="font-weight: bold; color: #3b82f6;">Cert Ref: <?= htmlspecialchars($tx['reference'] ?: 'C-'.$txId) ?></p>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-block">
            <h3>Contracting Client</h3>
            <p><?= htmlspecialchars($client['name']) ?></p>
            <span><?= htmlspecialchars($client['city'] ?? '') ?></span><br><br>
            <h3>Project Reference</h3>
            <p><?= htmlspecialchars($work['project_name'] ?? 'General') ?></p>
            <span>Work Order: <?= htmlspecialchars($work['work_reference']) ?></span>
            <?php if(!empty($work['po_reference'])): ?><br><span>PO: <?= htmlspecialchars($work['po_reference']) ?></span><?php endif; ?>
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
            <h4>Total Order Value</h4>
            <div class="val">€<?= number_format($work['total_inc_vat'], 2) ?></div>
        </div>
        <div class="summary-box">
            <h4>Previously Certified</h4>
            <div class="val" style="color: #6b7280;">€<?= number_format($prev_cert, 2) ?></div>
        </div>
        <div class="summary-box" style="border-color: #3b82f6; background: rgba(59,130,246,0.05);">
            <h4 style="color: #3b82f6;">Value This Certificate</h4>
            <div class="val" style="color: #3b82f6;">€<?= number_format($this_cert, 2) ?></div>
        </div>
        <div class="summary-box">
            <h4>Total Certified to Date</h4>
            <div class="val">€<?= number_format($total_to_date, 2) ?></div>
        </div>
    </div>

    <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #111827; border-bottom: 1px solid #111827; padding-bottom: 5px;">Certification Breakdown</h3>
    
    <?php if ($work['is_measured'] && !empty($boqProgress)): ?>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width: 40%;">Description / Level</th>
                    <th class="num">Item Total Value</th>
                    <th class="num" style="color: #6b7280;">Prev %</th>
                    <th class="num" style="color: #3b82f6;">This Cert %</th>
                    <th class="num">New Total %</th>
                    <th class="num">Value Certified (€)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $calcExc = 0;
                foreach($boqProgress as $bp): 
                    $descInfo = $boqDescriptions[$bp['boq_id']] ?? null;
                    if (!$descInfo) continue;
                    
                    $old = (float)$bp['old_pct'];
                    $new = (float)$bp['new_pct'];
                    $diff = $new - $old;
                    if ($diff <= 0) continue; // Only show items that actually progressed
                    
                    $valAdded = ((float)$descInfo['total_exc']) * ($diff / 100);
                    $calcExc += $valAdded;
                ?>
                <tr>
                    <td><?= htmlspecialchars($descInfo['description']) ?></td>
                    <td class="num">€<?= number_format($descInfo['total_exc'], 2) ?></td>
                    <td class="num" style="color: #6b7280;"><?= number_format($old, 1) ?>%</td>
                    <td class="num strong" style="color: #3b82f6;">+<?= number_format($diff, 1) ?>%</td>
                    <td class="num"><?= number_format($new, 1) ?>%</td>
                    <td class="num strong">€<?= number_format($valAdded, 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" class="num">Subtotal (Exc. VAT)</td>
                    <td class="num">€<?= number_format($calcExc, 2) ?></td>
                </tr>
                <tr>
                    <td colspan="5" class="num">VAT (<?= (float)$work['vat_rate'] ?>%)</td>
                    <td class="num">€<?= number_format($calcExc * ($work['vat_rate']/100), 2) ?></td>
                </tr>
                <tr style="background: #f3f4f6;">
                    <td colspan="5" class="num strong">Total Value (Inc. VAT)</td>
                    <td class="num strong" style="color: #3b82f6;">€<?= number_format($this_cert, 2) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php else: ?>
        <table class="print-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="num">Amount Certified (Inc. VAT)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="padding: 20px 10px;">
                        <?= nl2br(htmlspecialchars($tx['notes'] ?: 'Interim certification against lump sum contract.')) ?>
                    </td>
                    <td class="num strong" style="color: #3b82f6; vertical-align: top; padding: 20px 10px;">
                        €<?= number_format($this_cert, 2) ?>
                    </td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <h3 style="margin: 30px 0 10px 0; font-size: 12px; color: #4b5563; text-transform: uppercase;">Certification History</h3>
    <table class="history-table">
        <thead>
            <tr>
                <th style="width: 15%;">Date</th>
                <th style="width: 20%;">Reference</th>
                <th style="width: 45%;">Description</th>
                <th style="text-align: right; width: 20%;">Amount (Inc VAT)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($historicalCerts)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; font-style: italic;">This is the first certification issued for this Work Order.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($historicalCerts as $hc): ?>
                <tr>
                    <td><?= date('d M Y', strtotime($hc['transaction_date'])) ?></td>
                    <td style="font-weight: bold;"><?= htmlspecialchars($hc['reference'] ?: 'C-'.$hc['id']) ?></td>
                    <td><?= htmlspecialchars($hc['notes'] ?: 'Interim Certification') ?></td>
                    <td style="text-align: right;">€<?= number_format($hc['amount_inc_vat'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background: #f9fafb; font-weight: bold;">
                    <td colspan="3" style="text-align: right;">Total Prior Certifications:</td>
                    <td style="text-align: right;">€<?= number_format($prev_cert, 2) ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="signatures-grid">
        <div class="signature-block">
            <div class="signature-line"></div>
            <strong>Prepared By</strong><br>
            Quantity Surveyor / PM
        </div>
        <div class="signature-block">
            <div class="signature-line"></div>
            <strong>Approved By</strong><br>
            Client / Director
        </div>
        <div class="signature-block">
            <div class="signature-line"></div>
            <strong>Accepted By</strong><br>
            Subcontractor
        </div>
    </div>

    <div class="footer">
        This document serves as formal certification of works completed up to the date stated above.<br>
        Estate Hub Management System
    </div>

</div>

</body>
</html>
