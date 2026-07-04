<?php
require_once 'init.php';
require_once 'session-check.php';
require_once 'S3FileManager.php'; 

$quoteId = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : null;
if (!$quoteId) die("Quote ID is missing.");

$s3 = new S3FileManager();

// Fetch Quote along with Author, Approver, Contractor, and Client details
$stmt = $pdo->prepare("
    SELECT sq.*, 
           con.name as contractor_name, con.logo_path as contractor_logo, con.city as contractor_city,
           c.name as linked_client_name, c.city as linked_client_city, 
           p.name as project_name, 
           u1.first_name as author_fname, u1.last_name as author_lname, 
           u2.first_name as approver_fname, u2.last_name as approver_lname 
    FROM sales_quotes sq 
    LEFT JOIN clients con ON sq.contractor_id = con.id
    LEFT JOIN clients c ON sq.client_id = c.id 
    LEFT JOIN projects p ON sq.project_id = p.id 
    LEFT JOIN users u1 ON sq.created_by = u1.id 
    LEFT JOIN users u2 ON sq.approver_id = u2.id 
    WHERE sq.id = ?
");
$stmt->execute([$quoteId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quote) die("Quote not found.");

// Hard Block for Unapproved Quotes
if (in_array($quote['status'], ['Draft', 'Pending Approval', 'Rejected'])) {
    die("<div style='font-family: sans-serif; text-align: center; padding: 50px;'>
            <h2 style='color: #ef4444;'>Security Block: Quote Not Approved</h2>
            <p>This quote is currently in <b>" . $quote['status'] . "</b> status.</p>
            <p>It must be authorized by an Approver before it can be printed or dispatched to a client.</p>
            <a href='work_sales.php?contractor_id={$quote['contractor_id']}&quote_id=$quoteId' style='display: inline-block; padding: 10px 20px; background: #3b82f6; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px;'>Return to Quote</a>
         </div>");
}

// Fetch sorted items
$itemsStmt = $pdo->prepare("SELECT * FROM sales_quote_items WHERE quote_id = ? ORDER BY sort_order ASC, category ASC, id ASC");
$itemsStmt->execute([$quoteId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Secure Cloudflare R2 Logo retrieval for the CONTRACTOR
$logoSrc = '';
if (!empty($quote['contractor_logo'])) {
    if (strpos($quote['contractor_logo'], 'http') === false) {
        $logoSrc = $s3->getPresignedUrl($quote['contractor_logo'], '+60 minutes');
    } else {
        $logoSrc = $quote['contractor_logo'];
    }
}

// SAFE HTML HELPER (Fixes PHP 8.1+ htmlspecialchars null deprecation warning)
function safe_html($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$effectiveClientName = $quote['linked_client_name'] ?? $quote['client_name_free'] ?? '';
$effectiveProjectName = $quote['project_name'] ?? $quote['project_name_free'] ?? '';
$paNumber = $quote['pa_number'] ?? '';

function displayUnit($u) {
    $m = [
        'lump_sum' => 'Lump Sum', 'sqm' => 'sq.m', 'lm' => 'lm', 'cum' => 'cu.m', 'cu.yd' => 'cu.yd',
        'hrs' => 'Hours', 'hour' => 'Hour', 'qty' => 'Qty / Pcs',
        'visit' => 'Visit', 'participant' => 'Participant', 'procedure' => 'Procedure',
        'document' => 'Document', 'assessment' => 'Assessment',
    ];
    return $m[$u] ?? $u;
}

function quoteTypeLabel($type) {
    $labels = [
        'Demolition_Excavation' => 'Demolition & Excavation',
        'Construction' => 'Construction',
        'Finishes' => 'Finishes',
        'OHSA' => 'OHSA / Health & Safety',
    ];
    return $labels[$type] ?? str_replace('_', ' & ', $type);
}

$isFinishes = ($quote['quote_type'] === 'Finishes');
$isOhsa = ($quote['quote_type'] === 'OHSA');
$colSpan = $isFinishes ? 3 : 5;
$scheduleTitle = $isOhsa ? 'Schedule of Services' : 'Bill of Quantities';
$frontTitleSuffix = match ($quote['quote_type']) {
    'Demolition_Excavation' => '& Methodology',
    'OHSA' => '— Health & Safety Services',
    default => '',
};
$compactCover = $isOhsa && empty($quote['location_lat']);
$ohsaSinglePage = $isOhsa && $compactCover;
$attachments = json_decode($quote['attachments'] ?? '[]', true) ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quotation - <?= safe_html($quote['reference_number']) ?></title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #111827; background: #fff; line-height: 1.5; margin: 0; padding: 20px; font-size: 12px; }
        .page-container { max-width: 900px; margin: 0 auto; }
        
        /* Typography & Structure */
        h1, h2, h3 { color: #1f2937; margin: 0; }
        .no-print { display: flex; justify-content: space-between; align-items: center; background: #f3f4f6; padding: 15px; border-radius: 8px; border: 1px solid #d1d5db; margin-bottom: 20px; }
        
        /* Front Page Layout */
        .front-page { min-height: 90vh; display: flex; flex-direction: column; }
        .front-page.compact-cover { min-height: auto; padding-bottom: 20px; }
        .logo-box { max-width: 250px; max-height: 100px; margin-bottom: 40px; }
        .logo-box img { max-width: 100%; max-height: 100px; object-fit: contain; }
        
        .fp-title { font-size: 32px; text-transform: uppercase; border-bottom: 3px solid #e5e7eb; padding-bottom: 10px; margin-bottom: 30px; letter-spacing: 1px; }
        
        .fp-grid { width: 100%; font-size: 16px; line-height: 2.2; margin-bottom: 40px; }
        .fp-grid td { vertical-align: top; }
        .fp-label { font-weight: bold; width: 120px; color: #4b5563; }
        .fp-value { color: #111827; font-weight: 500; }

        .fp-map-container { flex-grow: 1; border: 2px solid #e5e7eb; border-radius: 8px; overflow: hidden; max-height: 500px; }
        .fp-map-container img { width: 100%; height: 100%; object-fit: cover; display: block; }
        
        /* Page Break */
        .page-break { page-break-before: always; padding-top: 40px; }
        .content-section { padding-top: 0; }
        .content-section.continuous { padding-top: 24px; border-top: 2px solid #e5e7eb; margin-top: 28px; }

        /* Secondary Page Header */
        .sec-header { display: flex; justify-content: space-between; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px; margin-bottom: 20px; page-break-after: avoid; break-after: avoid; }
        
        /* Table */
        .print-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 11px; table-layout: fixed; }
        .print-table thead { display: table-header-group; }
        .print-table th { background: #f3f4f6; padding: 10px; text-align: left; font-weight: bold; border-bottom: 2px solid #d1d5db; color: #374151; }
        .print-table td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; }
        .print-table .num { text-align: right; white-space: nowrap; }
        .print-table .strong { font-weight: bold; }
        .print-table .desc-col { width: <?= $isOhsa ? '52%' : ($isFinishes ? '70%' : '50%') ?>; }
        .print-table .unit-col { width: <?= $isOhsa ? '12%' : 'auto' ?>; }
        .print-table .qty-col { width: <?= $isOhsa ? '10%' : 'auto' ?>; }
        .print-table .rate-col { width: <?= $isOhsa ? '13%' : 'auto' ?>; }
        .print-table .amount-col { width: <?= $isOhsa ? '13%' : 'auto' ?>; }
        .print-table tr.cat-row td { background: #f9fafb; color: #1f2937; font-weight: bold; page-break-after: avoid; }
        .print-table.ohsa-table { font-size: 10px; }
        .print-table.ohsa-table td.desc-col { line-height: 1.45; }
        .print-table tbody tr.item-row { page-break-inside: auto; }

        /* OHSA services — block layout for long descriptions */
        .ohsa-schedule { margin-bottom: 30px; }
        .ohsa-cat { background: #f3f4f6; color: #1f2937; font-weight: bold; padding: 8px 12px; margin: 18px 0 8px; border-radius: 4px; page-break-after: avoid; font-size: 11px; }
        .ohsa-cat:first-child { margin-top: 0; }
        .ohsa-item { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; margin-bottom: 8px; page-break-inside: avoid; break-inside: avoid; }
        .ohsa-item-desc { font-size: 10.5px; line-height: 1.5; color: #111827; margin-bottom: 8px; word-wrap: break-word; overflow-wrap: break-word; }
        .ohsa-item-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 18px; font-size: 10px; color: #4b5563; border-top: 1px solid #f3f4f6; padding-top: 8px; }
        .ohsa-item-meta span strong { color: #374151; font-weight: 600; }
        .ohsa-item-meta .ohsa-amt { margin-left: auto; font-weight: bold; color: #111827; white-space: nowrap; }

        /* Totals & footer — no floats (float breaks print order) */
        .quote-footer { clear: both; margin-top: 24px; page-break-inside: avoid; break-inside: avoid; }
        .totals-wrap { display: flex; justify-content: flex-end; margin-bottom: 24px; page-break-inside: avoid; break-inside: avoid; }
        .totals-box { width: 300px; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; }
        .totals-row { display: flex; justify-content: space-between; padding: 8px 15px; border-bottom: 1px solid #e5e7eb; }
        .totals-row.grand { background: #f3f4f6; font-weight: bold; font-size: 14px; border-bottom: none; }

        /* Terms & Signatures */
        .terms-box { margin-top: 8px; padding: 15px; border: 1px solid #e5e7eb; background: #f9fafb; font-size: 10px; color: #4b5563; border-radius: 6px; page-break-inside: avoid; break-inside: avoid; }
        .signatures-grid { display: flex; justify-content: space-between; margin-top: 28px; padding-top: 20px; border-top: 1px solid #e5e7eb; page-break-inside: avoid; break-inside: avoid; }
        .signature-block { width: 30%; text-align: center; }
        .signature-line { border-bottom: 1px solid #111827; height: 40px; margin-bottom: 10px; }
        .footer { margin-top: 40px; padding-top: 10px; border-top: 1px solid #e5e7eb; text-align: center; font-size: 10px; color: #9ca3af; }

        @media print {
            body { padding: 0; }
            .page-container { width: 100%; max-width: 100%; }
            @page { margin: 1cm 1.5cm; size: A4; }
            .no-print { display: none !important; }
            .print-table { font-size: <?= $isOhsa ? '9.5px' : '11px' ?>; }
            .print-table td { orphans: 3; widows: 3; }
            .front-page { min-height: auto; <?= $ohsaSinglePage ? 'page-break-after: auto;' : 'page-break-after: always;' ?> }
            .page-break { page-break-before: always; padding-top: 0; }
            .content-section.continuous { page-break-before: avoid; border-top: none; margin-top: 20px; padding-top: 0; }
            .ohsa-schedule { page-break-before: avoid; }
            .quote-footer { page-break-before: avoid; }
        }
    </style>
</head>
<body>

<div class="page-container">

    <div class="no-print">
        <div>
            <h3>PDF Print Preview</h3>
            <p style="margin: 0; color: #6b7280;">Review the document below. Click 'Print' when ready to generate the PDF.</p>
        </div>
        <button onclick="window.print()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold;">🖨️ Print / Save as PDF</button>
    </div>

    <div class="front-page<?= $compactCover ? ' compact-cover' : '' ?>">
        <div class="logo-box">
            <?php if (!empty($logoSrc)): ?>
                <img src="<?= safe_html($logoSrc) ?>" alt="Contractor Logo">
            <?php else: ?>
                <h2><?= safe_html($quote['contractor_name']) ?></h2>
            <?php endif; ?>
        </div>

        <h1 class="fp-title">
            Quotation <?= safe_html(trim($frontTitleSuffix)) ?>
        </h1>

        <table class="fp-grid">
            <tr><td class="fp-label">To:</td><td class="fp-value"><?= safe_html($effectiveClientName) ?></td></tr>
            <tr><td class="fp-label">Date:</td><td class="fp-value"><?= date('d F Y', strtotime($quote['created_at'])) ?></td></tr>
            <tr><td class="fp-label">Re:</td><td class="fp-value"><?= safe_html(quoteTypeLabel($quote['quote_type'])) ?></td></tr>
            <tr><td class="fp-label">Our Ref:</td><td class="fp-value"><?= safe_html($quote['reference_number']) ?></td></tr>
            <tr><td class="fp-label">Site:</td><td class="fp-value"><?= safe_html($effectiveProjectName) ?></td></tr>
            <?php if ($paNumber): ?>
            <tr><td class="fp-label">PA:</td><td class="fp-value"><?= safe_html($paNumber) ?></td></tr>
            <?php endif; ?>
            <tr><td class="fp-label">Prepared By:</td><td class="fp-value"><?= safe_html($quote['author_fname'] . ' ' . $quote['author_lname']) ?></td></tr>
        </table>

        <?php if ($quote['location_lat'] && $quote['location_lng']): ?>
            <h3 style="margin-bottom: 10px; font-size: 16px; color: #4b5563;">Site Location Map:</h3>
            <div class="fp-map-container">
                <img src="https://static-maps.openplaceguide.org/render?center=<?= $quote['location_lat'] ?>,<?= $quote['location_lng'] ?>&zoom=16&size=800,500&markers=color:red|<?= $quote['location_lat'] ?>,<?= $quote['location_lng'] ?>" 
                     alt="Site Map" 
                     style="width:100%; max-width:800px; height:auto; border-radius:8px; border:1px solid #cbd5e1;">
            </div>
        <?php endif; ?>
    </div>

    <div class="<?= $ohsaSinglePage ? 'content-section continuous' : 'page-break' ?>">
        
        <div class="sec-header">
            <div>
                <h3 style="margin-bottom: 5px;"><?= safe_html($scheduleTitle) ?></h3>
                <span style="color: #6b7280; font-size: 11px;">Ref: <?= safe_html($quote['reference_number']) ?> | Site: <?= safe_html($effectiveProjectName) ?></span>
            </div>
            <?php if (!empty($logoSrc)): ?>
                <img src="<?= safe_html($logoSrc) ?>" alt="Logo" style="max-height: 40px; object-fit: contain;">
            <?php endif; ?>
        </div>

        <?php if ($isOhsa): ?>
        <div class="ohsa-schedule">
            <?php if (empty($items)): ?>
                <p style="text-align:center; color:#6b7280; padding:20px;">No services specified in this quote.</p>
            <?php else:
                $currentCat = '';
                foreach ($items as $i):
                    if ($i['category'] !== $currentCat):
                        $currentCat = $i['category'];
            ?>
                <div class="ohsa-cat"><?= safe_html($currentCat) ?></div>
            <?php endif; ?>
                <div class="ohsa-item">
                    <div class="ohsa-item-desc"><?= nl2br(safe_html($i['description'])) ?></div>
                    <div class="ohsa-item-meta">
                        <span><strong>Unit:</strong> <?= safe_html(displayUnit($i['unit'])) ?></span>
                        <span><strong>Qty:</strong> <?= (float)$i['estimated_qty'] ?></span>
                        <span><strong>Rate:</strong> €<?= number_format($i['unit_rate'], 2) ?></span>
                        <span class="ohsa-amt">€<?= number_format($i['estimated_qty'] * $i['unit_rate'], 2) ?></span>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <?php else: ?>
        <table class="print-table">
            <thead>
                <tr>
                    <th class="desc-col">Description</th>
                    <th class="unit-col">Unit</th>
                    <th class="num qty-col">Est. Qty</th>
                    <?php if (!$isFinishes): ?>
                        <th class="num rate-col">Unit Rate</th>
                        <th class="num amount-col">Amount (€)</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="<?= $colSpan ?>" style="text-align:center; padding: 20px;">No items specified in this quote.</td></tr>
                <?php else: 
                    $currentCat = '';
                    foreach($items as $i): 
                        if ($i['category'] !== $currentCat) {
                            echo "<tr class='cat-row'><td colspan='{$colSpan}'>" . safe_html($i['category']) . "</td></tr>";
                            $currentCat = $i['category'];
                        }
                ?>
                <tr class="item-row">
                    <td class="desc-col"><?= nl2br(safe_html($i['description'])) ?></td>
                    <td class="unit-col"><?= safe_html(displayUnit($i['unit'])) ?></td>
                    <td class="num qty-col"><?= (float)$i['estimated_qty'] ?></td>
                    <?php if (!$isFinishes): ?>
                        <td class="num rate-col">€<?= number_format($i['unit_rate'], 2) ?></td>
                        <td class="num strong amount-col">€<?= number_format($i['estimated_qty'] * $i['unit_rate'], 2) ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="quote-footer">
        <div class="totals-wrap">
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
        </div>
        
        <?php if(!empty($quote['terms_conditions'])): ?>
        <div class="terms-box">
            <strong style="display:block; margin-bottom: 5px; color:#111827; text-transform:uppercase;">Terms & Conditions</strong>
            <?= nl2br(safe_html($quote['terms_conditions'])) ?>
        </div>
        <?php endif; ?>

        <div class="signatures-grid">
            <div class="signature-block">
                <div class="signature-line"></div>
                <strong>Prepared By</strong><br>
                <?= safe_html($quote['author_fname'] . ' ' . $quote['author_lname']) ?>
            </div>
            <div class="signature-block">
                <div class="signature-line"></div>
                <strong>Approved By</strong><br>
                <?php if (!empty($quote['approver_fname'])): ?>
                    <?= safe_html($quote['approver_fname'] . ' ' . $quote['approver_lname']) ?>
                <?php else: ?>
                    <span style="color: #ef4444;">System Approved</span>
                <?php endif; ?>
            </div>
            <div class="signature-block">
                <div class="signature-line"></div>
                <strong>Accepted By (Client)</strong><br>
                Signature & Date
            </div>
        </div>

        <div class="footer">
            Issued by <?= safe_html($quote['contractor_name']) ?> <br>
            Estate Hub Commercial Management
        </div>
        </div><!-- .quote-footer -->
    </div>

    <?php if (count($attachments) > 0): ?>
        <div class="page-break">
            <h2 style="color: #1f2937; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; margin-bottom: 20px;">Quotation Attachments</h2>
            <div id="pdf-render-container">
                <div id="pdf-loading-indicator" style="text-align: center; color: #6b7280; padding: 20px;">
                    Loading attachments for printing...
                </div>
            </div>
        </div>
        
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
        <script>
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
            const pdfUrls = [
                <?php foreach($attachments as $att): ?>
                    "<?= $s3->getPresignedUrl($att['key'], '+60 minutes') ?>",
                <?php endforeach; ?>
            ];

            async function renderPDFs() {
                const container = document.getElementById('pdf-render-container');
                try {
                    for (let url of pdfUrls) {
                        const loadingTask = pdfjsLib.getDocument(url);
                        const pdf = await loadingTask.promise;
                        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                            const page = await pdf.getPage(pageNum);
                            // Scale 1.5 provides high-res output suited for A4 printing
                            const viewport = page.getViewport({scale: 1.5});
                            
                            const canvas = document.createElement('canvas');
                            const context = canvas.getContext('2d');
                            canvas.height = viewport.height;
                            canvas.width = viewport.width;
                            canvas.style.width = '100%';
                            canvas.style.marginBottom = '20px';
                            canvas.style.pageBreakInside = 'avoid';
                            canvas.style.border = '1px solid #e5e7eb';
                            canvas.style.boxShadow = '0 4px 6px rgba(0,0,0,0.1)';
                            
                            container.appendChild(canvas);
                            
                            const renderContext = { canvasContext: context, viewport: viewport };
                            await page.render(renderContext).promise;
                        }
                    }
                    document.getElementById('pdf-loading-indicator').style.display = 'none';
                } catch(e) { 
                    console.error("Error rendering PDF:", e);
                    document.getElementById('pdf-loading-indicator').innerText = "Failed to load attachment preview for printing. Ensure attachments are standard PDFs.";
                }
            }
            renderPDFs();
        </script>
    <?php endif; ?>

</div>

</body>
</html>
