<?php
require_once 'init.php';
require_once 'session-check.php';
require_once 'S3FileManager.php'; 

$claimId = isset($_GET['claim_id']) ? (int)$_GET['claim_id'] : null;
if (!$claimId) die("Claim ID is missing.");

$s3 = new S3FileManager();

// Fetch the Claim
$stmt = $pdo->prepare("SELECT * FROM sales_claims WHERE id = ?");
$stmt->execute([$claimId]);
$claim = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$claim) die("Claim not found.");

// Fetch Quote along with Contractor and Client details
$qStmt = $pdo->prepare("
    SELECT sq.*, 
           con.name as contractor_name, con.logo_path as contractor_logo, con.city as contractor_city,
           c.name as linked_client_name, c.city as linked_client_city, 
           p.name as project_name
    FROM sales_quotes sq 
    LEFT JOIN clients con ON sq.contractor_id = con.id
    LEFT JOIN clients c ON sq.client_id = c.id 
    LEFT JOIN projects p ON sq.project_id = p.id 
    WHERE sq.id = ?
");
$qStmt->execute([$claim['quote_id']]);
$quote = $qStmt->fetch(PDO::FETCH_ASSOC);
if (!$quote) die("Associated quote not found.");

// Fetch Previously Claimed Amount (Strictly BEFORE this claim)
$prevStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_inc_vat), 0) FROM sales_claims WHERE quote_id = ? AND id < ?");
$prevStmt->execute([$claim['quote_id'], $claimId]);
$prev_claimed = (float)$prevStmt->fetchColumn();

// Secure Cloudflare R2 Logo retrieval for the CONTRACTOR
$logoSrc = '';
if (!empty($quote['contractor_logo'])) {
    if (strpos($quote['contractor_logo'], 'http') === false) {
        $logoSrc = $s3->getPresignedUrl($quote['contractor_logo'], '+60 minutes');
    } else {
        $logoSrc = $quote['contractor_logo'];
    }
}

$effectiveClientName = !empty($quote['linked_client_name']) ? $quote['linked_client_name'] : $quote['client_name_free'];
$effectiveClientCity = !empty($quote['linked_client_city']) ? $quote['linked_client_city'] : '';

$this_claim = (float)$claim['amount_inc_vat'];
$total_to_date = $prev_claimed + $this_claim;
$balance_remaining = (float)$quote['total_inc_vat'] - $total_to_date;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Request for Payment - <?= htmlspecialchars($quote['reference_number']) ?>-C<?= $claimId ?></title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #111827; background: #fff; line-height: 1.5; margin: 0; padding: 20px; font-size: 12px; }
        .page-container { max-width: 1000px; margin: 0 auto; }
        
        .header-section { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #e5e7eb; padding-bottom: 20px; margin-bottom: 20px; }
        .logo-box { max-width: 250px; max-height: 80px; }
        .logo-box img { max-width: 100%; max-height: 80px; object-fit: contain; }
        
        .title-box { text-align: right; }
        .title-box h1 { margin: 0; font-size: 24px; color: #3b82f6; letter-spacing: 0.5px; text-transform: uppercase; }
        .title-box p { margin: 5px 0 0 0; color: #6b7280; font-size: 12px; }
        
        .info-grid { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .info-block { width: 48%; }
        .info-block h3 { margin: 0 0 5px 0; font-size: 11px; color: #6b7280; text-transform: uppercase; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; }
        .info-block p { margin: 3px 0; font-size: 14px; font-weight: bold; color: #111827; }
        .info-block span { font-weight: normal; font-size: 12px; color: #4b5563; }

        .summary-grid { display: flex; gap: 15px; margin-bottom: 30px; }
        .summary-box { flex: 1; padding: 15px; border-radius: 6px; text-align: center; border: 1px solid #e5e7eb; background: #f9fafb; }
        .summary-box h4 { margin: 0 0 5px 0; font-size: 11px; color: #6b7280; text-transform: uppercase; }
        .summary-box .val { font-size: 16px; font-weight: bold; color: #111827; }

        .print-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 12px; }
        .print-table th { background: #f3f4f6; padding: 12px 10px; text-align: left; font-weight: bold; border-bottom: 2px solid #d1d5db; color: #374151; }
        .print-table td { padding: 12px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .print-table .num { text-align: right; }
        .print-table .strong { font-weight: bold; }

        .totals-box { float: right; width: 300px; margin-bottom: 30px; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; }
        .totals-row { display: flex; justify-content: space-between; padding: 8px 15px; border-bottom: 1px solid #e5e7eb; }
        .totals-row.grand { background: #eff6ff; font-weight: bold; font-size: 16px; border-bottom: none; color: #1e3a8a; }
        
        .footer { clear: both; margin-top: 60px; padding-top: 10px; border-top: 1px solid #e5e7eb; text-align: center; font-size: 10px; color: #9ca3af; }

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
            <h3 style="margin: 0; color: #1f2937;">PDF Print Preview</h3>
            <p style="margin: 5px 0 0 0; color: #6b7280; font-size: 12px;">Review the document below. Click 'Print' when ready to generate the RFP PDF.</p>
        </div>
        <button onclick="window.print()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold;">🖨️ Print / Save as PDF</button>
    </div>

    <div class="header-section">
        <div class="logo-box">
            <?php if (!empty($logoSrc)): ?>
                <img src="<?= htmlspecialchars($logoSrc) ?>" alt="Contractor Logo">
            <?php else: ?>
                <h2 style="margin:0; color:#374151; font-size: 20px;"><?= htmlspecialchars($quote['contractor_name']) ?></h2>
            <?php endif; ?>
        </div>
        <div class="title-box">
            <h1>Request For Payment</h1>
            <p>Application Date: <?= date('d F Y', strtotime($claim['created_at'])) ?></p>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-block">
            <h3>Billed To (Client)</h3>
            <p><?= htmlspecialchars($effectiveClientName) ?></p>
            <span><?= htmlspecialchars($effectiveClientCity) ?></span>
        </div>
        <div class="info-block" style="text-align: right;">
            <h3>Project Details</h3>
            <p><?= htmlspecialchars($quote['project_name']) ?></p>
            <span>Quote Ref: <?= htmlspecialchars($quote['reference_number']) ?></span><br>
            <span>Scope: <?= str_replace('_', ' & ', $quote['quote_type']) ?></span>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-box">
            <h4>Total Order Value</h4>
            <div class="val">€<?= number_format($quote['total_inc_vat'], 2) ?></div>
        </div>
        <div class="summary-box">
            <h4>Previously Claimed</h4>
            <div class="val" style="color: #6b7280;">€<?= number_format($prev_claimed, 2) ?></div>
        </div>
        <div class="summary-box" style="border-color: #3b82f6; background: rgba(59,130,246,0.05);">
            <h4 style="color: #3b82f6;">Value This Application</h4>
            <div class="val" style="color: #3b82f6;">€<?= number_format($this_claim, 2) ?></div>
        </div>
        <div class="summary-box">
            <h4>Balance Remaining</h4>
            <div class="val">€<?= number_format(max(0, $balance_remaining), 2) ?></div>
        </div>
    </div>

    <h3 style="font-size: 14px; margin-bottom: 10px; color: #111827; border-bottom: 1px solid #111827; padding-bottom: 5px;">Application Breakdown</h3>
    <table class="print-table">
        <thead>
            <tr>
                <th style="width: 25%;">Claim Type</th>
                <th style="width: 50%;">Description / Stage</th>
                <th class="num">Amount (€)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="font-weight: bold; color: #1e3a8a;"><?= htmlspecialchars($claim['claim_type']) ?></td>
                <td><?= nl2br(htmlspecialchars($claim['description'])) ?></td>
                <td class="num strong">€<?= number_format($claim['amount_exc_vat'], 2) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="totals-box">
        <div class="totals-row">
            <span>Subtotal (Exc. VAT)</span>
            <span>€<?= number_format($claim['amount_exc_vat'], 2) ?></span>
        </div>
        <div class="totals-row">
            <span>VAT (<?= (float)$quote['vat_rate'] ?>%)</span>
            <span>€<?= number_format($claim['amount_inc_vat'] - $claim['amount_exc_vat'], 2) ?></span>
        </div>
        <div class="totals-row grand">
            <span>Amount Due</span>
            <span>€<?= number_format($claim['amount_inc_vat'], 2) ?></span>
        </div>
    </div>

    <div class="footer">
        Payment Application Issued by <?= htmlspecialchars($quote['contractor_name']) ?> <br>
        Estate Hub Commercial Management
    </div>

</div>

</body>
</html>
