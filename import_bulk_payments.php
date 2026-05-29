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

// Fetch subcontractors for the dropdown selection
$subs = $pdo->query("SELECT id, name FROM subcontractors ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $subId = $_POST['subcontractor_id'];
    $file = $_FILES['csv_file']['tmp_name'];
    
    try {
        // 1. Get all Work Orders for this subcontractor, ordered by oldest first (FIFO)
        $stmt = $pdo->prepare("SELECT id, work_reference, client_id FROM subcontractor_works WHERE subcontractor_id = ? ORDER BY id ASC");
        $stmt->execute([$subId]);
        $workOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Calculate current unpaid balances directly from the database
        $balances = [];
        foreach ($workOrders as $wo) {
            // Sum Invoices & Certifications
            $stmtInv = $pdo->prepare("SELECT SUM(amount) FROM subcontractor_transactions WHERE work_id = ? AND transaction_type IN ('Invoice', 'Certification')");
            $stmtInv->execute([$wo['id']]);
            $invoiced = (float)$stmtInv->fetchColumn();
            
            // Sum Credit Notes (to reduce balance if applicable)
            $stmtCN = $pdo->prepare("SELECT SUM(amount) FROM subcontractor_transactions WHERE work_id = ? AND transaction_type = 'Credit Note'");
            $stmtCN->execute([$wo['id']]);
            $credit = (float)$stmtCN->fetchColumn();
            
            // Sum Existing Payments
            $stmtPaid = $pdo->prepare("SELECT SUM(amount) FROM subcontractor_transactions WHERE work_id = ? AND transaction_type = 'Payment'");
            $stmtPaid->execute([$wo['id']]);
            $paid = (float)$stmtPaid->fetchColumn();
            
            $due = $invoiced - $credit - $paid;
            
            if ($due > 0.005) { // 0.005 threshold to safely handle floating point math
                $balances[] = [
                    'work_id' => $wo['id'],
                    'client_id' => $wo['client_id'],
                    'ref' => $wo['work_reference'],
                    'due' => $due
                ];
            }
        }
        
        // 3. Process the Payments CSV
        $handle = fopen($file, "r");
        $header = fgetcsv($handle); // Skip header row
        
        $transactionsToInsert = [];
        $totalAllocated = 0;
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $dateRaw = trim($data[0] ?? '');
            
            // Aggressively clean the amount string to strip Euros, commas, spaces, and quotes
            $amountRaw = str_replace(['€', ',', ' ', '"'], '', trim($data[1] ?? '0'));
            $payAmount = (float)$amountRaw;
            
            if ($payAmount <= 0) continue;
            
            // Safely Format Date from DD/MM/YYYY to YYYY-MM-DD
            $cleanDate = str_replace('/', '-', $dateRaw);
            $parsedDate = date('Y-m-d', strtotime($cleanDate));
            if ($parsedDate === '1970-01-01') $parsedDate = date('Y-m-d');
            
            // ----------------------------------------------------
            // PHASE 1: Exact Match Allocation
            // ----------------------------------------------------
            $matched = false;
            foreach ($balances as &$b) {
                if (abs($b['due'] - $payAmount) < 0.01) { // Perfect match to the cent
                    $transactionsToInsert[] = [
                        'work_id' => $b['work_id'],
                        'client_id' => $b['client_id'],
                        'date' => $parsedDate,
                        'amount' => $payAmount,
                        'notes' => "Auto-Allocated (Exact Match to {$b['ref']})"
                    ];
                    $b['due'] = 0;
                    $totalAllocated += $payAmount;
                    $payAmount = 0;
                    $matched = true;
                    break;
                }
            }
            
            // ----------------------------------------------------
            // PHASE 2: FIFO Split Allocation
            // ----------------------------------------------------
            if (!$matched && $payAmount > 0) {
                foreach ($balances as &$b) {
                    if ($b['due'] > 0.01) {
                        if ($payAmount <= $b['due']) {
                            // Payment fully absorbed by this invoice
                            $transactionsToInsert[] = [
                                'work_id' => $b['work_id'],
                                'client_id' => $b['client_id'],
                                'date' => $parsedDate,
                                'amount' => $payAmount,
                                'notes' => "Auto-Allocated (FIFO Split to {$b['ref']})"
                            ];
                            $b['due'] -= $payAmount;
                            $totalAllocated += $payAmount;
                            $payAmount = 0;
                            break; 
                        } else {
                            // Invoice fully paid, payment rolls over to next invoice
                            $transactionsToInsert[] = [
                                'work_id' => $b['work_id'],
                                'client_id' => $b['client_id'],
                                'date' => $parsedDate,
                                'amount' => $b['due'],
                                'notes' => "Auto-Allocated (FIFO Split to {$b['ref']})"
                            ];
                            $payAmount -= $b['due'];
                            $totalAllocated += $b['due'];
                            $b['due'] = 0;
                        }
                    }
                }
            }
            
            // ----------------------------------------------------
            // PHASE 3: Leftover Advance Allocation
            // ----------------------------------------------------
            if ($payAmount > 0.01) {
                $stmtAdv = $pdo->prepare("SELECT id, client_id FROM subcontractor_works WHERE subcontractor_id = ? AND work_reference = 'General On-Account Payments' LIMIT 1");
                $stmtAdv->execute([$subId]);
                $advWo = $stmtAdv->fetch(PDO::FETCH_ASSOC);
                
                if (!$advWo) {
                    $pdo->prepare("INSERT INTO subcontractor_works (subcontractor_id, work_reference, total_exc_vat, total_inc_vat) VALUES (?, 'General On-Account Payments', 0, 0)")->execute([$subId]);
                    $advId = $pdo->lastInsertId();
                    $advClientId = null;
                } else {
                    $advId = $advWo['id'];
                    $advClientId = $advWo['client_id'];
                }
                
                $transactionsToInsert[] = [
                    'work_id' => $advId,
                    'client_id' => $advClientId,
                    'date' => $parsedDate,
                    'amount' => $payAmount,
                    'notes' => "General Advance Allocation (Exceeded Invoices)"
                ];
                $totalAllocated += $payAmount;
            }
        }
        fclose($handle);
        
        // ----------------------------------------------------
        // FINAL EXECUTION
        // ----------------------------------------------------
        $pdo->beginTransaction();
        $stmtInsTrans = $pdo->prepare("
            INSERT INTO subcontractor_transactions 
            (subcontractor_id, client_id, work_id, transaction_date, transaction_type, amount, notes, created_by) 
            VALUES (?, ?, ?, ?, 'Payment', ?, ?, ?)
        ");
        
        foreach ($transactionsToInsert as $t) {
            $stmtInsTrans->execute([$subId, $t['client_id'], $t['work_id'], $t['date'], $t['amount'], $t['notes'], $_SESSION['user_id']]);
        }
        $pdo->commit();
        
        $fmtTotal = number_format($totalAllocated, 2);
        $message = "<div class='success'><i class='fas fa-check-circle'></i> Success! <b>€{$fmtTotal}</b> was successfully processed. Exact amounts were mapped natively, and the rest was perfectly FIFO-split across the remaining open balances.</div>";
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='error'><i class='fas fa-exclamation-triangle'></i> Database Error: " . $e->getMessage() . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Payment Auto-Allocator</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f3f4f6; color: #0f172a; padding: 40px; }
        .container { max-width: 700px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        h2 { margin-top: 0; color: #0f172a; font-weight: 800; }
        .btn { padding: 12px 20px; background: #3b82f6; color: #fff; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-weight: 600; font-size: 1.1rem; width: 100%; transition: opacity 0.2s;}
        .btn-green { background: #10b981; }
        .btn:hover { opacity: 0.9; }
        .success { padding: 15px; background: #d1fae5; color: #065f46; border-radius: 8px; margin-bottom: 20px; border: 1px solid #10b981; }
        .error { padding: 15px; background: #fee2e2; color: #991b1b; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ef4444; }
        .form-group { margin-bottom: 20px; }
        label { font-weight: 800; display: block; margin-bottom: 10px; color: #334155; }
        select, input[type="file"] { width: 100%; padding: 12px; border: 2px dashed #cbd5e1; border-radius: 8px; background: #fff; box-sizing: border-box; cursor: pointer; font-family: inherit; font-size: 1rem; color: #0f172a;}
        select { border-style: solid; }
    </style>
</head>
<body>

<div class="container">
    <h2><i class="fas fa-magic text-blue-500"></i> Smart Payment Auto-Allocator</h2>
    <p style="color: #475569; line-height: 1.6;">Upload a raw list of payments. The system will automatically calculate the unpaid balances of your existing invoices, match exact amounts first, and meticulously split the rest using FIFO.</p>
    
    <?php if ($message) echo $message; ?>

    <form method="POST" enctype="multipart/form-data" style="background: #f8fafc; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; margin-top: 25px;">
        <div class="form-group">
            <label>1. Select Target Subcontractor:</label>
            <select name="subcontractor_id" required>
                <option value="">-- Choose Account --</option>
                <?php foreach ($subs as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>2. Upload Payment List CSV:</label>
            <p style="font-size: 0.85rem; color: #64748b; margin-top: -5px; margin-bottom: 10px;">Must contain exactly 2 columns: <b>Date</b> & <b>Amount</b> (Euro symbols and commas are safely stripped automatically).</p>
            <input type="file" name="csv_file" accept=".csv" required>
        </div>

        <button type="submit" class="btn btn-green"><i class="fas fa-cogs"></i> Process & Auto-Allocate</button>
    </form>
    
</div>

</body>
</html>