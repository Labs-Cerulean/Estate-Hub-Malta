<?php
require_once 'init.php';
require_once 'session-check.php';
require_once 'S3FileManager.php'; 

$quoteId = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : null;
if (!$quoteId) die("Quote ID is missing.");

$s3 = new S3FileManager();

$stmt = $pdo->prepare("SELECT sq.*, c.name as client_name, c.logo_path, c.city as client_city, p.name as project_name FROM sales_quotes sq LEFT JOIN clients c ON sq.client_id = c.id LEFT JOIN projects p ON sq.project_id = p.id WHERE sq.id = ?");
$stmt->execute([$quoteId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quote) die("Quote not found.");

$itemsStmt = $pdo->prepare("SELECT * FROM sales_quote_items WHERE quote_id = ? ORDER BY category ASC, sort_order ASC, id ASC");
$itemsStmt->execute([$quoteId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Secure Cloudflare R2 Logo retrieval
$logoSrc = '';
if (!empty($quote['logo_path'])) {
    if (strpos($quote['logo_path'], 'http') === false) {
        $logoSrc = $s3->getPresignedUrl($quote['logo_path'], '+60 minutes');
    } else {
        $logoSrc = $quote['logo_path'];
    }
}

function displayUnit($u) {
    $m = ['lump_sum'=>'Lump Sum', 'sqm'=>'sq.m', 'lm'=>'lm', 'cum'=>'cu.m', 'cu.yd'=>'cu.yd', 'hrs'=>'Hours', 'qty'=>'Qty / Pcs'];
    return $m[$u] ?? $u;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Estimate - <?= htmlspecialchars($quote['reference_number']) ?></title>
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

        .print-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 11px; }
        .print-table th { background: #f3f4f6; padding: 10px; text-align: left; font-weight: bold; border-bottom: 2px solid #d1d5db; color: #374151; }
        .print-table td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .print-table .num { text-align: right; }
        .print-table .strong { font-weight: bold; }

        .totals-box { float: right; width: 300px; margin-bottom: 30px; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; }
        .totals-row { display: flex; justify-content: space-between; padding: 8px 15px; border-bottom: 1px solid #e5e7eb; }
        .totals-row.grand { background: #f3f4f6; font-weight: bold; font-size: 14px; border-bottom: none; }
        
        .terms-box { clear: both; margin-top: 40px; padding: 15px; border: 1px solid #e5e7eb; background: #f9fafb; font-size: 10px; color: #4b5563; border-radius: 6px; }

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
            <?php if (!empty($logoSrc)): ?>
                <img src="<?= htmlspecialchars($logoSrc) ?>" alt="Logo">
            <?php else: ?>
                <h2 style="margin:0; color:#374151; font-size: 20px;">Estate Hub</h2>
            <?php endif; ?>
        </div>
        <div class="title-box">
            <h1>Estimate / Quotation</h1>
            <p>Date: <?= date('d F Y', strtotime($quote['created_at'])) ?></p>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-block">
            <h3>Client Details</h3>
            <p><?= htmlspecialchars($quote['client_name']) ?></p>
            <span><?= htmlspecialchars($quote['client_city'] ?? '') ?></span>
        </div>
        <div class="info-block" style="text-align: right;">
            <h3>Quote Reference</h3>
            <p><?= htmlspecialchars($quote['reference_number']) ?></p>
            <span>Project: <?= htmlspecialchars($quote['project_name'] ?? 'General Specification') ?></span><br>
            <span>Scope: <?= str_replace('_', ' & ', $quote['quote_type']) ?></span>
        </div>
    </div>

    <table class="print-table">
        <thead>
            <tr>
                <th style="width: 50%;">Description</th>
                <th>Unit</th>
                <th class="num">Est. Qty</th>
                <th class="num">Unit Rate</th>
                <th class="num">Amount (€)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="5" style="text-align:center; padding: 20px;">No items specified in this quote.</td></tr>
            <?php else: 
                $currentCat = '';
                foreach($items as $i): 
                    if ($i['category'] !== $currentCat) {
                        echo "<tr><td colspan='5' style='background: #f9fafb; color: #1f2937; font-weight: bold;'>".htmlspecialchars($i['category'])."</td></tr>";
                        $currentCat = $i['category'];
                    }
            ?>
            <tr>
                <td><?= nl2br(htmlspecialchars($i['description'])) ?></td>
                <td><?= displayUnit($i['unit']) ?></td>
                <td class="num"><?= (float)$i['estimated_qty'] ?></td>
                <td class="num">€<?= number_format($i['unit_rate'], 2) ?></td>
                <td class="num strong">€<?= number_format($i['estimated_qty'] * $i['unit_rate'], 2) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="totals-box">
        <div class="totals-row">
            <span>Subtotal (Exc. VAT)</span>
            <span>€<?= number_format($quote['total_exc_vat'], 2) ?></span>
        </div>
        <div class="totals-row">
            <span>VAT (<?= (float)$quote['vat_rate'] ?>%)</span>
            <span>€<?= number_format($quote['total_inc_vat'] - $quote['total_exc_vat'], 2) ?></span>
        </div>
        <div class="totals-row grand">
            <span>Total (Inc. VAT)</span>
            <span>€<?= number_format($quote['total_inc_vat'], 2) ?></span>
        </div>
    </div>
    
    <?php if(!empty($quote['terms_conditions'])): ?>
    <div class="terms-box">
        <strong style="display:block; margin-bottom: 5px; color:#111827; text-transform:uppercase;">Terms & Conditions</strong>
        <?= nl2br(htmlspecialchars($quote['terms_conditions'])) ?>
    </div>
    <?php endif; ?>

    <div class="footer">
        Estate Hub Commercial Management
    </div>

    <div class="no-print" style="text-align: center; margin-top: 30px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">Print / Save to PDF</button>
    </div>

</div>

</body>
</html>
