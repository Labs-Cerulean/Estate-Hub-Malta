<?php
require_once 'config.php';
require_once 'session-check.php';
require_once 'user-functions.php';

// Check permissions - restrict to accounting & management
$role = $_SESSION['role'] ?? '';
$isAllowed = in_array($role, ['admin', 'director', 'system_manager', 'accountant']) || hasPermission('manage_subcontractor_accounts');
if (!$isAllowed) {
    die("Unauthorized Access.");
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    
    if (is_uploaded_file($file)) {
        $handle = fopen($file, "r");
        $header = fgetcsv($handle); // Skip header row
        
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        $rowNum = 1;
        
        while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
            $rowNum++;
            
            // Map Columns
            $subName     = trim($data[0] ?? '');
            $clientName  = trim($data[1] ?? '');
            $projectName = trim($data[2] ?? '');
            $workRef     = trim($data[3] ?? '');
            $transDate   = trim($data[4] ?? '');
            $transType   = trim($data[5] ?? '');
            $amount      = floatval(trim($data[6] ?? 0));
            $reference   = trim($data[7] ?? '');
            $notes       = trim($data[8] ?? '');
            
            if (empty($subName)) {
                $errorCount++;
                $errors[] = "Row $rowNum: Missing Subcontractor Name.";
                continue;
            }
            
            // Format Date safely to match MySQL expectation
            $parsedDate = null;
            if (!empty($transDate)) {
                $parsedDate = date('Y-m-d', strtotime($transDate));
                if ($parsedDate === '1970-01-01') $parsedDate = date('Y-m-d');
            } else {
                $parsedDate = date('Y-m-d'); // fallback to today
            }

            try {
                $pdo->beginTransaction();
                
                // 1. Resolve Subcontractor (Auto-create if it doesn't exist)
                $stmt = $pdo->prepare("SELECT id FROM subcontractors WHERE name = ? LIMIT 1");
                $stmt->execute([$subName]);
                $subId = $stmt->fetchColumn();
                
                if (!$subId) {
                    $stmtIns = $pdo->prepare("INSERT INTO subcontractors (name) VALUES (?)");
                    $stmtIns->execute([$subName]);
                    $subId = $pdo->lastInsertId();
                }
                
                // 2. Resolve Client (Optional: Will map to null if not found)
                $clientId = null;
                if (!empty($clientName)) {
                    $stmt = $pdo->prepare("SELECT id FROM clients WHERE name = ? LIMIT 1");
                    $stmt->execute([$clientName]);
                    $clientId = $stmt->fetchColumn();
                }
                
                // 3. Resolve Project (Optional: Will map to null if not found)
                $projectId = null;
                if (!empty($projectName)) {
                    $stmt = $pdo->prepare("SELECT id FROM projects WHERE name = ? LIMIT 1");
                    $stmt->execute([$projectName]);
                    $projectId = $stmt->fetchColumn();
                }
                
                // 4. Resolve Work Reference / Contract (Auto-create if it doesn't exist)
                $workId = null;
                if (!empty($workRef)) {
                    $stmt = $pdo->prepare("SELECT id FROM subcontractor_works WHERE subcontractor_id = ? AND work_reference = ? LIMIT 1");
                    $stmt->execute([$subId, $workRef]);
                    $workId = $stmt->fetchColumn();
                    
                    if (!$workId) {
                        $stmtIns = $pdo->prepare("INSERT INTO subcontractor_works (subcontractor_id, client_id, project_id, work_reference) VALUES (?, ?, ?, ?)");
                        $stmtIns->execute([$subId, $clientId, $projectId, $workRef]);
                        $workId = $pdo->lastInsertId();
                    }
                }
                
                // NEW: If the user just wants to setup the Work Order without a transaction, stop here and commit.
                if (strtolower($transType) === 'work order setup' || empty($transType)) {
                    $pdo->commit();
                    $successCount++;
                    continue; 
                }

                // 5. Securely Map Transaction Type to Enum
                $validTypes = ['Certification','Payment','Invoice','Credit Note','Adjustment'];
                if (!in_array($transType, $validTypes)) {
                    $transType = 'Payment';
                }
                
                // 6. Insert Transaction
                $stmtInsTrans = $pdo->prepare("
                    INSERT INTO subcontractor_transactions 
                    (subcontractor_id, client_id, work_id, transaction_date, transaction_type, amount, reference, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtInsTrans->execute([
                    $subId, 
                    $clientId, 
                    $workId, 
                    $parsedDate, 
                    $transType, 
                    $amount, 
                    $reference, 
                    $notes, 
                    $_SESSION['user_id'] ?? 1
                ]);
                
                $pdo->commit();
                $successCount++;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $errorCount++;
                $errors[] = "Row $rowNum Database Error: " . $e->getMessage();
            }
        }
        fclose($handle);
        
        $message = "<div class='success'><i class='fas fa-check-circle'></i> Upload complete! $successCount rows processed successfully.</div>";
        if ($errorCount > 0) {
            $message .= "<div class='error'><i class='fas fa-exclamation-triangle'></i> $errorCount errors occurred:<br>" . implode("<br>", $errors) . "</div>";
        }
    } else {
        $message = "<div class='error'><i class='fas fa-exclamation-triangle'></i> File upload failed. Please ensure it is a valid CSV file.</div>";
    }
}

// Generate the CSV Template on the fly
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="subcontractor_upload_template.csv"');
    $output = fopen('php://output', 'w');
    // Headers
    fputcsv($output, ['Subcontractor Name', 'Client Name', 'Project Name', 'Work Reference', 'Transaction Date (YYYY-MM-DD)', 'Transaction Type', 'Amount', 'Reference', 'Notes']);
    // Examples
    fputcsv($output, ['Farruggia Marble', 'PRA Construction Ltd', 'Centrex', 'Centrex Phase 2', '2024-01-10', 'Work Order Setup', '0.00', '', 'Contract signed. Setup only.']);
    fputcsv($output, ['Farruggia Marble', 'PRA Construction Ltd', 'Centrex', 'Centrex Marble CP', '2024-05-15', 'Invoice', '15000.00', 'INV-001', 'First interim invoice']);
    fputcsv($output, ['Farruggia Marble', 'PRA Construction Ltd', 'Centrex', 'Centrex Marble CP', '2024-06-01', 'Payment', '15000.00', 'CHQ 12345', 'Paid via BT']);
    fputcsv($output, ['Farruggia Marble', 'PRA Construction Ltd', 'Centrex', 'Centrex Marble CP', '2024-06-15', 'Certification', '15000.00', 'CERT-01', 'Certified by Perit']);
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Subcontractor Account</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f3f4f6; color: #0f172a; padding: 40px; }
        .container { max-width: 700px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        h2 { margin-top: 0; color: #0f172a; font-weight: 800; }
        .btn { padding: 12px 20px; background: #3b82f6; color: #fff; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 600; }
        .btn-green { background: #10b981; width: 100%; justify-content: center; font-size: 1.1rem; }
        .btn:hover { opacity: 0.9; }
        .success { padding: 15px; background: #d1fae5; color: #065f46; border-radius: 8px; margin-bottom: 20px; border: 1px solid #10b981; }
        .error { padding: 15px; background: #fee2e2; color: #991b1b; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ef4444; }
        .form-group { margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="container">
    <h2><i class="fas fa-file-upload text-blue-500"></i> Import Subcontractor Transactions</h2>
    <p style="color: #475569; line-height: 1.6;">Use this tool to seamlessly mass-import historical account data (Invoices, Certifications, Payments) for your subcontractors directly from Excel.</p>
    
    <?php if ($message) echo $message; ?>

    <div style="margin-top: 30px; margin-bottom: 30px; padding: 20px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px;">
        <h4 style="margin-top: 0; color: #1e40af; font-size: 1.1rem;">Step 1: Prepare Your Excel Data</h4>
        <p style="font-size: 0.95rem; color: #1e3a8a; margin-bottom: 15px;">Your Excel file must perfectly match our system's columns. Download the template below, paste your data into it, and save it as a <b>.CSV</b> file.</p>
        <a href="?download_template=1" class="btn"><i class="fas fa-file-csv"></i> Download CSV Template</a>
    </div>

    <form method="POST" enctype="multipart/form-data" style="background: #f8fafc; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;">
        <div class="form-group">
            <label style="font-weight: 800; display: block; margin-bottom: 10px; color: #334155;">Step 2: Upload Completed CSV</label>
            <input type="file" name="csv_file" accept=".csv" required style="width: 100%; padding: 12px; border: 2px dashed #cbd5e1; border-radius: 8px; background: #fff; box-sizing: border-box; cursor: pointer;">
        </div>
        <button type="submit" class="btn btn-green"><i class="fas fa-cloud-upload-alt"></i> Run Import</button>
    </form>
    
    <div style="margin-top: 20px; font-size: 0.85rem; color: #64748b; background: #fffbeb; padding: 15px; border-radius: 8px; border: 1px solid #fde68a;">
        <strong><i class="fas fa-info-circle"></i> Smart Importer Features:</strong>
        <ul style="margin-top: 5px; margin-bottom: 0; padding-left: 20px;">
            <li>If the Subcontractor doesn't exist, the system will automatically create it.</li>
            <li>If the <b>Work Reference</b> doesn't exist, the system will auto-create it and map it to the Client and Project specified.</li>
            <li><b>Work Orders Without Transactions:</b> Set the Transaction Type to <code>Work Order Setup</code> (or leave it blank). The system will safely create the Work Order without generating a financial transaction.</li>
        </ul>
    </div>
</div>

</body>
</html>