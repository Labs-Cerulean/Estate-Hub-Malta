<?php
require_once 'config.php';
require_once 'session-check.php';

// Ensure only admins/managers can run this
if (!in_array($_SESSION['role'], ['admin', 'director', 'system_manager', 'sales_manager'])) {
    die("Unauthorized Access.");
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['flat_csv']) && $_FILES['flat_csv']['error'] === UPLOAD_ERR_OK) {
    
    $subName = 'KEY Contractors Ltd';
    
    // 1. Get or Create Subcontractor
    $stmt = $pdo->prepare("SELECT id FROM subcontractors WHERE name LIKE ?");
    $stmt->execute(['%KEY Contractors%']);
    $subId = $stmt->fetchColumn();
    
    if (!$subId) {
        $stmt = $pdo->prepare("INSERT INTO subcontractors (name, specialty, created_at) VALUES (?, 'Turnkey Construction', NOW())");
        $stmt->execute([$subName]);
        $subId = $pdo->lastInsertId();
    }
    
    $handle = fopen($_FILES['flat_csv']['tmp_name'], 'r');
    fgetcsv($handle); // Skip Header Row
    
    $counts = ['Work' => 0, 'Cert' => 0, 'Invoice' => 0, 'Payment' => 0];
    
    // Array to store generated Work IDs so we can attach Certs to them
    // Key: ProjectName_WorkRef => Value: work_id
    $workIdMap = [];
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        $type = trim($row[0] ?? '');
        $refName = trim($row[1] ?? '');
        $projectName = trim($row[2] ?? '');
        
        // Clean and format the date (handles "06 09 2023" or "2023-09-06" formats)
        $rawDate = trim($row[3] ?? '');
        $parsedDate = date('Y-m-d'); // Default to today
        if (!empty($rawDate)) {
            $formattedDateString = str_replace([' ', '/'], '-', $rawDate);
            $parsedDate = date('Y-m-d', strtotime($formattedDateString));
        }
        
        $excVat = floatval(str_replace(',', '', $row[4] ?? '0'));
        $incVat = floatval(str_replace(',', '', $row[5] ?? '0'));
        
        if (empty($type) || empty($refName) || empty($projectName)) continue;
        
        // Find Project ID & Client ID
        $projId = null;
        $clientId = 24; // Safe default fallback
        $pStmt = $pdo->prepare("SELECT id, clientid FROM projects WHERE name LIKE ? LIMIT 1");
        $pStmt->execute(["%" . $projectName . "%"]);
        if ($p = $pStmt->fetch()) {
            $projId = $p['id'];
            $clientId = $p['clientid'];
        }
        
        // Route the data based on the "Type" column
        if (strtolower($type) === 'work') {
            
            $wStmt = $pdo->prepare("INSERT INTO subcontractor_works (subcontractor_id, client_id, project_id, is_measured, work_reference, vat_rate, total_exc_vat, total_inc_vat, created_at, updated_at) VALUES (?, ?, ?, 0, ?, 18.00, ?, ?, NOW(), NOW())");
            $wStmt->execute([$subId, $clientId, $projId, $refName, $excVat, $incVat]);
            
            $workId = $pdo->lastInsertId();
            $workIdMap[$projectName . '_' . $refName] = $workId; // Remember this ID for the Certs
            $counts['Work']++;
            
        } elseif (strtolower($type) === 'cert') {
            
            // Try to find the matching Work Order ID
            $linkedWorkId = $workIdMap[$projectName . '_' . $refName] ?? null;
            
            $cStmt = $pdo->prepare("INSERT INTO subcontractor_transactions (subcontractor_id, client_id, work_id, transaction_date, transaction_type, amount, reference, created_by, created_at) VALUES (?, ?, ?, ?, 'Certification', ?, 'Auto-Imported', ?, NOW())");
            $cStmt->execute([$subId, $clientId, $linkedWorkId, $parsedDate, $incVat, $_SESSION['user_id']]);
            $counts['Cert']++;
            
        } elseif (strtolower($type) === 'invoice') {
            
            $iStmt = $pdo->prepare("INSERT INTO subcontractor_transactions (subcontractor_id, client_id, transaction_date, transaction_type, amount, reference, created_by, created_at) VALUES (?, ?, ?, 'Invoice', ?, ?, ?, NOW())");
            $iStmt->execute([$subId, $clientId, $parsedDate, $incVat, $refName, $_SESSION['user_id']]);
            $counts['Invoice']++;
            
        } elseif (strtolower($type) === 'payment') {
            
            $pStmt = $pdo->prepare("INSERT INTO subcontractor_transactions (subcontractor_id, client_id, transaction_date, transaction_type, amount, reference, created_by, created_at) VALUES (?, ?, ?, 'Payment', ?, ?, ?, NOW())");
            $pStmt->execute([$subId, $clientId, $parsedDate, $incVat, $refName, $_SESSION['user_id']]);
            $counts['Payment']++;
            
        }
    }
    fclose($handle);
    
    $message = "<div style='color: green; font-weight: bold; margin-bottom: 10px;'>✔ Import Complete!</div>
                <ul style='color: #fff; text-align: left;'>
                    <li><b>Works Created:</b> {$counts['Work']}</li>
                    <li><b>Certs Logged:</b> {$counts['Cert']}</li>
                    <li><b>Invoices Added:</b> {$counts['Invoice']}</li>
                    <li><b>Payments Registered:</b> {$counts['Payment']}</li>
                </ul>";
}

require_once 'header.php';
?>

<div class="main-container" style="max-width: 600px; margin: 40px auto; background: var(--bg-panel); padding: 30px; border-radius: 12px; border: 1px solid var(--border-glass); text-align: center;">
    <h2 style="color: var(--primary-color); margin-top: 0;"><i class="fas fa-file-import"></i> Unified KEY Importer</h2>
    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 25px;">Upload the consolidated CSV file containing Works, Certs, Invoices, and Payments in a single list.</p>

    <?php if ($message): ?>
        <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group" style="margin-bottom: 30px; text-align: left;">
            <label style="font-weight: bold; color: #fff;">Upload Unified CSV File</label>
            <input type="file" name="flat_csv" accept=".csv" required class="form-control" style="background: var(--bg-base); color: #fff; border: 1px solid var(--border-glass); padding: 10px; border-radius: 6px; width: 100%;">
            <small style="color: var(--text-muted); display: block; margin-top: 8px;">Expected Columns: Type | Ref / Name | Project | Date | Amount Exc VAT | Amount Inc VAT</small>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1.1rem; font-weight: bold;">
            <i class="fas fa-upload"></i> Process & Import Data
        </button>
    </form>
</div>

<?php require_once 'footer.php'; ?>
