<?php
require_once 'init.php';
require_once 'session-check.php';
require_once 'S3FileManager.php'; 

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
if (!$projectId) die("Project ID is missing.");

$s3 = new S3FileManager();

// 1. Fetch Project
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) die("Project not found.");

// 2. Fetch Media Pages (Front, Terms, Specs, Back)
$docsStmt = $pdo->prepare("SELECT sub_category, file_path FROM project_documents WHERE project_id = ? AND category = 'Sales' AND sub_category LIKE 'Pricelist - %' ORDER BY created_at ASC");
$docsStmt->execute([$projectId]);
$media = [];
// This loop ensures the LATEST uploaded version of each page is used
foreach ($docsStmt->fetchAll() as $d) {
    $media[$d['sub_category']] = $s3->getPresignedUrl($d['file_path'], '+60 minutes');
}

// 3. Fetch and Group Units by Floor
$uStmt = $pdo->prepare("SELECT * FROM sales_properties WHERE project_id = ? ORDER BY floor_level ASC, unit_type ASC, unit_name ASC");
$uStmt->execute([$projectId]);
$units = $uStmt->fetchAll(PDO::FETCH_ASSOC);

$floors = [];
foreach ($units as $u) {
    $floors[$u['floor_level']][] = $u;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($project['name']) ?> - Live Pricelist</title>
    <style>
        body { margin: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background: #525659; }
        
        .page { 
            width: 210mm; 
            height: 297mm; 
            background: white; 
            margin: 20px auto; 
            box-shadow: 0 5px 15px rgba(0,0,0,0.3); 
            overflow: hidden; 
            position: relative;
            display: flex;
            flex-direction: column;
        }
        .full-img { width: 100%; height: 100%; object-fit: cover; }
        
        .data-page { padding: 40px; box-sizing: border-box; }
        .data-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #111; padding-bottom: 10px; }
        .data-header h1 { margin: 0; font-size: 28px; text-transform: uppercase; color: #111; }
        .data-header p { margin: 5px 0 0 0; color: #666; font-size: 12px; }

        .floor-section { margin-bottom: 20px; }
        .floor-title { background: #111; color: white; padding: 5px 10px; font-weight: bold; text-transform: uppercase; font-size: 14px; margin-bottom: 10px; }
        
        table { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 10px; }
        th { background: #f3f4f6; color: #333; padding: 8px; text-align: left; border-bottom: 2px solid #ccc; text-transform: uppercase; font-size: 10px; }
        td { padding: 8px; border-bottom: 1px solid #eee; color: #111; }
        .num { text-align: right; }
        .bold { font-weight: bold; }
        
        /* Status styling to match your PDF */
        .status-hold { color: #f59e0b; font-weight: bold; }
        .status-sold { color: #ef4444; font-weight: bold; }
        .status-avail { color: #10b981; font-weight: bold; }

        @media print {
            body { background: white; margin: 0; }
            .page { margin: 0; box-shadow: none; page-break-after: always; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="position: fixed; top: 20px; right: 20px; background: rgba(0,0,0,0.8); padding: 15px; border-radius: 8px; z-index: 1000; color: white; text-align: center;">
        <p style="margin: 0 0 10px 0; font-size: 14px;">Review Live Pricelist</p>
        <button onclick="window.print()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold;">🖨️ Save to PDF / Print</button>
    </div>

    <?php if (isset($media['Pricelist - Front Cover'])): ?>
    <div class="page">
        <img class="full-img" src="<?= htmlspecialchars($media['Pricelist - Front Cover']) ?>">
    </div>
    <?php endif; ?>

    <div class="page data-page">
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
                        if (strpos($u['status'], 'Hold') !== false || strpos($u['status'], 'Reserved') !== false) $statusClass = 'status-hold';
                        if (strpos($u['status'], 'Sold') !== false) $statusClass = 'status-sold';
                    ?>
                    <tr>
                        <td class="bold"><?= htmlspecialchars($u['unit_name']) ?></td>
                        <td><?= htmlspecialchars($u['description']) ?></td>
                        <td class="num"><?= $u['internal_sqm'] ?></td>
                        <td class="num"><?= $u['external_sqm'] ?></td>
                        <td class="num">€<?= number_format($u['shell_price'], 0) ?></td>
                        <td class="num"><?= $u['finishes_price'] > 0 ? '€'.number_format($u['finishes_price'], 0) : 'N/A' ?></td>
                        <td class="num <?= $statusClass ?>"><?= strtoupper($u['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (isset($media['Pricelist - Timeframes & Terms'])): ?>
    <div class="page">
        <img class="full-img" src="<?= htmlspecialchars($media['Pricelist - Timeframes & Terms']) ?>">
    </div>
    <?php endif; ?>

    <?php if (isset($media['Pricelist - Spec Sheet'])): ?>
    <div class="page">
        <img class="full-img" src="<?= htmlspecialchars($media['Pricelist - Spec Sheet']) ?>">
    </div>
    <?php endif; ?>

    <?php if (isset($media['Pricelist - Back Cover'])): ?>
    <div class="page">
        <img class="full-img" src="<?= htmlspecialchars($media['Pricelist - Back Cover']) ?>">
    </div>
    <?php endif; ?>

</body>
</html>
