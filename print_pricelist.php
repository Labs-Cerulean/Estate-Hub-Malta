<?php
require_once 'init.php';
require_once 'session-check.php';
require_once 'S3FileManager.php'; 

// --- PDF STREAMING PROXY ---
// Bypasses browser memory crashes and Cloudflare CORS security blocks
if (isset($_GET['proxy_doc_id'])) {
    $docId = (int)$_GET['proxy_doc_id'];
    $stmt = $pdo->prepare("SELECT file_path, project_id FROM project_documents WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($doc && hasSalesProjectAccess($pdo, (int)$doc['project_id']) && salesProjectPassesListingVisibility($pdo, (int)$doc['project_id'])) {
        $s3 = new S3FileManager();
        $url = $s3->getPresignedUrl($doc['file_path'], '+10 minutes');
        
        header("Content-Type: application/pdf");
        
        // Stream the file byte-by-byte to save server RAM
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        curl_close($ch);
        exit;
    }
    http_response_code(404);
    exit;
}
// -----------------------

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
if (!$projectId) die("Project ID is missing.");
if (!hasSalesProjectAccess($pdo, $projectId)) {
    http_response_code(403);
    die("Access denied.");
}

if (!salesProjectPassesListingVisibility($pdo, $projectId)) {
    http_response_code(403);
    die("This project is not listed for sale.");
}

$s3 = new S3FileManager();

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) die("Project not found.");

// Fetch Media & determine file types
$docsStmt = $pdo->prepare("SELECT id, sub_category, file_path, file_type FROM project_documents WHERE project_id = ? AND category = 'Sales' AND sub_category LIKE 'Pricelist - %' ORDER BY created_at ASC");
$docsStmt->execute([$projectId]);
$media = [];

foreach ($docsStmt->fetchAll() as $d) {
    $media[$d['sub_category']] = [
        'id' => $d['id'],
        'url' => $s3->getPresignedUrl($d['file_path'], '+60 minutes'),
        'type' => strtolower($d['file_type'])
    ];
}

// FIXED: Removed the double "ORDER BY" syntax
$uStmt = $pdo->prepare("
    SELECT * FROM sales_properties 
    WHERE project_id = ? 
    ORDER BY 
        CAST(floor_level AS SIGNED) ASC, 
        FIELD(unit_type, 'garage', 'parking space', 'commercial', 'maisonette', 'apartment', 'penthouse', 'villa', 'house'), 
        unit_name ASC
");
$uStmt->execute([$projectId]);
$units = $uStmt->fetchAll(PDO::FETCH_ASSOC);

$canViewSoldPricing = salesCanViewSoldUnitPricing();

$floors = [];
foreach ($units as $u) {
    $floors[$u['floor_level']][] = $u;
}

// Render helper
function renderMediaPage($subCat, $mediaData) {
    if (!isset($mediaData[$subCat])) return;
    
    $docId = $mediaData[$subCat]['id'];
    $url = $mediaData[$subCat]['url'];
    $type = $mediaData[$subCat]['type'];

    if ($type === 'pdf') {
        // Drop an invisible anchor for the JS PDF Engine to find
        echo "<div class='pdf-render-target' data-doc-id='{$docId}'></div>";
    } else {
        echo "<div class='print-page img-page'><img class='full-img' src='" . htmlspecialchars($url) . "'></div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($project['name']) ?> - Live Pricelist</title>
    <style>
        body { margin: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background: #525659; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        
        .print-page { 
            width: 210mm; 
            background: white; 
            margin: 20px auto; 
            box-shadow: 0 5px 15px rgba(0,0,0,0.3); 
            position: relative;
        }
        
        .img-page { height: 297mm; overflow: hidden; background: #fff; }
        .full-img { width: 100%; height: 100%; object-fit: contain; } /* 'contain' ensures large PDF pages don't stretch weirdly */
        
        .data-page { padding: 15mm; box-sizing: border-box; min-height: 297mm; }
        .data-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #111; padding-bottom: 10px; }
        .data-header h1 { margin: 0; font-size: 28px; text-transform: uppercase; color: #111; }
        .data-header p { margin: 5px 0 0 0; color: #666; font-size: 12px; }

        .floor-section { margin-bottom: 25px; page-break-inside: avoid; }
        .floor-title { background: #111; color: white; padding: 5px 10px; font-weight: bold; text-transform: uppercase; font-size: 14px; margin-bottom: 10px; }
        
        table { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 10px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { background: #f3f4f6; color: #333; padding: 10px 8px; text-align: left; border-bottom: 2px solid #ccc; text-transform: uppercase; font-size: 10px; }
        td { padding: 8px; border-bottom: 1px solid #eee; color: #111; }
        .num { text-align: right; }
        .bold { font-weight: bold; }
        
        .status-hold { color: #64748b; font-weight: bold; }
        .status-proc { color: #f59e0b; font-weight: bold; }
        .status-sold { color: #ef4444; font-weight: bold; }
        .status-avail { color: #10b981; font-weight: bold; }

        body.pricelist-loading .data-page { display: none !important; }

        @media print {
            @page { size: A4; margin: 0; }
            body { background: transparent; margin: 0; }
            .print-page { margin: 0; box-shadow: none; page-break-after: always; }
            .data-page { min-height: auto; } 
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="position: fixed; top: 20px; right: 20px; background: rgba(0,0,0,0.8); padding: 15px; border-radius: 8px; z-index: 1000; color: white; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.5);">
        <p style="margin: 0 0 10px 0; font-size: 14px;">Review Live Pricelist</p>
        <button id="printBtn" onclick="window.print()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold;">🖨️ Save to PDF / Print</button>
    </div>

    <?php renderMediaPage('Pricelist - Front Cover', $media); ?>

    <div class="print-page data-page">
        <div class="data-header">
            <h1>PRICE LIST</h1>
            <p>Generated: <?= date('d F Y H:i') ?> | Prices and availability subject to change without notice.</p>
        </div>

        <?php foreach ($floors as $lvl => $floorUnits): ?>
        <div class="floor-section">
            <div class="floor-title">LEVEL <?= htmlspecialchars($lvl) ?></div>
            <table>
                <thead>
                    <tr>
                        <th>Unit No.</th>
                        <th>Description</th>
                        <th class="num">Int. SQM</th>
                        <th class="num">Ext. SQM</th>
                        <th class="num">Shell Price</th>
                        <th class="num">Finishes (C/P+S/F)</th>
                        <th class="num">Availability</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($floorUnits as $u):
                        $statusClass = 'status-avail';
                        $unitStatus = (string)($u['status'] ?? '');
                        if (stripos($unitStatus, 'Proceeding') !== false) {
                            $statusClass = 'status-proc';
                        } elseif (stripos($unitStatus, 'Hold') !== false || stripos($unitStatus, 'Reserved') !== false) {
                            $statusClass = 'status-hold';
                        } elseif (stripos($unitStatus, 'Sold') !== false) {
                            $statusClass = 'status-sold';
                        }
                        $hideSoldPrice = salesUnitStatusIsSold($unitStatus) && !$canViewSoldPricing;
                    ?>
                    <tr>
                        <td class="bold"><?= htmlspecialchars($u['unit_name']) ?></td>
                        <td><?= htmlspecialchars($u['description']) ?></td>
                        <td class="num"><?= $u['internal_sqm'] ?></td>
                        <td class="num"><?= $u['external_sqm'] ?></td>
                        <td class="num"><?= $hideSoldPrice ? '—' : '€' . number_format($u['shell_price'], 0) ?></td>
                        <td class="num"><?= $hideSoldPrice ? '—' : ($u['finishes_price'] > 0 ? '€' . number_format($u['finishes_price'], 0) : 'N/A') ?></td>
                        <td class="num <?= $statusClass ?>"><?= strtoupper($u['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </div>

    <?php renderMediaPage('Pricelist - Timeframes & Terms', $media); ?>

    <?php renderMediaPage('Pricelist - Spec Sheet', $media); ?>

    <?php renderMediaPage('Pricelist - Back Cover', $media); ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const pdfTargets = document.querySelectorAll('.pdf-render-target');
            if (pdfTargets.length > 0) {
                document.body.classList.add('pricelist-loading');

                const printBtn = document.getElementById('printBtn');
                if(printBtn) {
                    printBtn.disabled = true;
                    printBtn.innerHTML = "⏳ Processing PDF pages...";
                    printBtn.style.background = "#6b7280";
                }

                const script = document.createElement('script');
                script.src = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js";
                script.onload = async () => {
                    pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js";
                    
                    for (let target of pdfTargets) {
                        const docId = target.getAttribute('data-doc-id');
                        const proxyUrl = 'print_pricelist.php?proxy_doc_id=' + docId;

                        try {
                            const loadingTask = pdfjsLib.getDocument(proxyUrl);
                            const pdf = await loadingTask.promise;
                            
                            // Loop through every page in the PDF!
                            for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                                const page = await pdf.getPage(pageNum);
                                const scale = 2.0; // High resolution
                                const viewport = page.getViewport({scale: scale});
                                
                                const wrapper = document.createElement('div');
                                wrapper.className = 'print-page img-page';
                                
                                // Create the canvas to draw the PDF
                                const canvas = document.createElement('canvas');
                                const context = canvas.getContext('2d');
                                canvas.height = viewport.height;
                                canvas.width = viewport.width;
                                
                                // Force a white background (fixes transparent PDF bugs)
                                context.fillStyle = '#ffffff';
                                context.fillRect(0, 0, canvas.width, canvas.height);
                                
                                // Render the PDF page onto the canvas
                                await page.render({ canvasContext: context, viewport: viewport }).promise;
                                
                                // CRITICAL FIX: Convert the canvas into a standard Image so the browser prints it perfectly!
                                const img = document.createElement('img');
                                img.className = 'full-img';
                                img.src = canvas.toDataURL('image/jpeg', 0.95); 
                                
                                // Add the image to the wrapper, and insert into the page
                                wrapper.appendChild(img);
                                target.parentNode.insertBefore(wrapper, target);
                            }
                        } catch(e) {
                            console.error("Error rendering PDF", e);
                            target.insertAdjacentHTML('beforebegin', "<div class='print-page'><h3 style='color:red; text-align:center; padding-top:100px;'>Error rendering PDF. Cloudflare block or corrupted file.</h3></div>");
                        }
                        target.remove(); // Clean up the invisible target
                    }
                    
                    if(printBtn) {
                        printBtn.disabled = false;
                        printBtn.innerHTML = "🖨️ Save to PDF / Print";
                        printBtn.style.background = "#2563eb";
                    }
                    document.body.classList.remove('pricelist-loading');
                };
                document.head.appendChild(script);
            }
        });
    </script>
</body>
</html>
