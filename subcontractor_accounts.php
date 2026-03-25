<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('view_subcontractor_accounts') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}
$canManage = hasPermission('manage_subcontractor_accounts') || isAdmin();

// Ensure the transactions table supports document paths safely
try {
    $pdo->exec("ALTER TABLE subcontractor_transactions ADD COLUMN document_path VARCHAR(255) DEFAULT NULL");
} catch (PDOException $e) { /* Column already exists */ }

// ==========================================
// AJAX ENDPOINTS for BoQ / Levels
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    
    if ($action === 'get_project_levels') {
        $pid = (int)$_POST['project_id'];
        $stmt = $pdo->prepare("SELECT bl.id, bl.level_name, pb.block_name FROM block_levels bl JOIN project_blocks pb ON bl.block_id = pb.id WHERE pb.project_id = ? ORDER BY pb.id ASC, bl.level_number ASC");
        $stmt->execute([$pid]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    
    if ($action === 'get_boq_progress') {
        $wid = (int)$_POST['work_id'];
        $stmt = $pdo->prepare("SELECT * FROM subcontractor_boq WHERE work_id = ? ORDER BY id ASC");
        $stmt->execute([$wid]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
}
// ==========================================

$message = ''; $error = '';

if (isAdmin()) {
    $accessibleClients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
} else {
    $accessibleClients = getUserClients($pdo, getCurrentUserId());
}

$selected_client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$sub_id = isset($_GET['sub_id']) ? (int)$_GET['sub_id'] : null;

// Handling POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage && $selected_client_id && !isset($_POST['ajax_action'])) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_work') {
            $post_sub_id = $_POST['subcontractor_id'];
            $project_id = !empty($_POST['project_id']) ? $_POST['project_id'] : null;
            $vat_rate = isset($_POST['vat_rate']) ? (float)$_POST['vat_rate'] : 18.00;
            $is_measured = isset($_POST['is_measured']) && $_POST['is_measured'] === '1' ? 1 : 0;
            
            $pdo->beginTransaction();

            if (empty($_POST['work_id'])) {
                $stmt = $pdo->prepare("INSERT INTO subcontractor_works (subcontractor_id, client_id, project_id, is_measured, work_reference, po_reference, vat_rate, responsible, total_exc_vat, total_inc_vat, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([ $post_sub_id, $selected_client_id, $project_id, $is_measured, trim($_POST['work_reference']), trim($_POST['po_reference'] ?? ''), $vat_rate, trim($_POST['responsible'] ?? ''), $_POST['total_exc_vat'] ?: 0, $_POST['total_inc_vat'] ?: 0, trim($_POST['notes'] ?? '') ]);
                $work_id = $pdo->lastInsertId();
                $message = "Work Order added successfully!";
            } else {
                $work_id = $_POST['work_id'];
                $stmt = $pdo->prepare("UPDATE subcontractor_works SET project_id=?, is_measured=?, work_reference=?, po_reference=?, vat_rate=?, responsible=?, total_exc_vat=?, total_inc_vat=?, notes=? WHERE id=? AND subcontractor_id=? AND client_id=?");
                $stmt->execute([ $project_id, $is_measured, trim($_POST['work_reference']), trim($_POST['po_reference'] ?? ''), $vat_rate, trim($_POST['responsible'] ?? ''), $_POST['total_exc_vat'] ?: 0, $_POST['total_inc_vat'] ?: 0, trim($_POST['notes'] ?? ''), $work_id, $post_sub_id, $selected_client_id ]);
                $message = "Work Order updated successfully!";
            }

            if ($is_measured && isset($_POST['boq_desc'])) {
                $submittedBoqIds = [];
                for ($i = 0; $i < count($_POST['boq_desc']); $i++) {
                    $bId = !empty($_POST['boq_item_id'][$i]) ? $_POST['boq_item_id'][$i] : null;
                    $desc = trim($_POST['boq_desc'][$i]);
                    $lvlId = !empty($_POST['boq_level_id'][$i]) ? $_POST['boq_level_id'][$i] : null;
                    $qty = (float)$_POST['boq_qty'][$i];
                    $rate = (float)$_POST['boq_rate'][$i];
                    $total = $qty * $rate;
                    
                    if ($desc !== '' && $total > 0) {
                        if ($bId) {
                            $pdo->prepare("UPDATE subcontractor_boq SET block_level_id=?, description=?, qty=?, rate=?, total_exc=? WHERE id=? AND work_id=?")->execute([$lvlId, $desc, $qty, $rate, $total, $bId, $work_id]);
                            $submittedBoqIds[] = $bId;
                        } else {
                            $pdo->prepare("INSERT INTO subcontractor_boq (work_id, block_level_id, description, qty, rate, total_exc) VALUES (?, ?, ?, ?, ?, ?)")->execute([$work_id, $lvlId, $desc, $qty, $rate, $total]);
                            $submittedBoqIds[] = $pdo->lastInsertId();
                        }
                    }
                }
                if (!empty($submittedBoqIds)) {
                    $placeholders = implode(',', array_fill(0, count($submittedBoqIds), '?'));
                    $params = $submittedBoqIds;
                    $params[] = $work_id;
                    $pdo->prepare("DELETE FROM subcontractor_boq WHERE id NOT IN ($placeholders) AND work_id=?")->execute($params);
                } else {
                    $pdo->prepare("DELETE FROM subcontractor_boq WHERE work_id=?")->execute([$work_id]);
                }
            } elseif (!$is_measured) {
                $pdo->prepare("DELETE FROM subcontractor_boq WHERE work_id=?")->execute([$work_id]);
            }
            
            $pdo->commit();
        } 
        elseif ($action === 'delete_work') {
            $pdo->prepare("DELETE FROM subcontractor_works WHERE id=? AND client_id=?")->execute([$_POST['work_id'], $selected_client_id]);
            $message = "Work Order deleted.";
        }
        elseif ($action === 'save_transaction') {
            $post_sub_id = $_POST['subcontractor_id'];
            $work_id = !empty($_POST['work_id']) ? $_POST['work_id'] : null;
            $type = $_POST['transaction_type'];

            $pdo->beginTransaction();

            $amount = $_POST['amount'] ?: 0;
            $boqDataJson = null;
            $docPath = null;

            // Handle Secure Document Upload (Invoices OR Certifications)
            if (in_array($type, ['Invoice', 'Certification']) && !empty($_FILES['invoice_file']['tmp_name'])) {
                require_once 'S3FileManager.php';
                $s3 = new S3FileManager();
                $folder = $type === 'Invoice' ? 'invoices' : 'certifications';
                $docPath = $s3->uploadFile($_FILES['invoice_file']['tmp_name'], $_FILES['invoice_file']['name'], $_FILES['invoice_file']['type'], $folder);
                
                if ($docPath && $work_id) {
                    $chkP = $pdo->prepare("SELECT project_id FROM subcontractor_works WHERE id = ?");
                    $chkP->execute([$work_id]);
                    $pid = $chkP->fetchColumn();
                    if ($pid) {
                        try {
                            $docTitle = ($type === 'Invoice' ? 'Invoice: ' : 'Cert Attachment: ') . trim($_POST['reference']);
                            $pdo->prepare("INSERT INTO project_documents (project_id, category, sub_category, title, file_path, uploaded_by) VALUES (?, 'Commercial', ?, ?, ?, ?)")->execute([$pid, $type, $docTitle, $docPath, getCurrentUserId()]);
                        } catch (Exception $e) { }
                    }
                }
            }

            // Certification Sync Logic (With Rollback Storage)
            if ($type === 'Certification' && isset($_POST['cert_boq_id'])) {
                $amountExc = 0;
                $boqDataArr = [];
                $updateBoq = $pdo->prepare("UPDATE subcontractor_boq SET pct_complete = ? WHERE id = ?");
                $updateLevel = $pdo->prepare("UPDATE block_levels SET construction_status = ?, construction_pct = ? WHERE id = ?");
                
                for ($i = 0; $i < count($_POST['cert_boq_id']); $i++) {
                    $bId = $_POST['cert_boq_id'][$i];
                    $newPct = isset($_POST['cert_new_pct'][$i]) ? (float)$_POST['cert_new_pct'][$i] : 0.0;
                    $prevPct = isset($_POST['cert_prev_pct'][$i]) ? (float)$_POST['cert_prev_pct'][$i] : 0.0;
                    $valAdded = isset($_POST['cert_val_added'][$i]) ? (float)$_POST['cert_val_added'][$i] : 0.0;
                    $lvlId = !empty($_POST['cert_level_id'][$i]) ? $_POST['cert_level_id'][$i] : null;
                    
                    if ($newPct != $prevPct || $valAdded > 0) {
                        $amountExc += $valAdded;
                        $updateBoq->execute([$newPct, $bId]);
                        
                        $boqDataArr[] = ['boq_id' => $bId, 'old_pct' => $prevPct, 'new_pct' => $newPct, 'level_id' => $lvlId];
                        
                        if ($lvlId) {
                            $cStatus = 'Pending';
                            if ($newPct >= 100) $cStatus = 'Complete';
                            elseif ($newPct > 0) $cStatus = 'In Progress';
                            $updateLevel->execute([$cStatus, $newPct, $lvlId]);
                        }
                    }
                }
                
                if (!empty($boqDataArr)) {
                    $boqDataJson = json_encode($boqDataArr);
                }
                $vatRate = (float)$_POST['work_vat_rate'];
                $amount = $amountExc + ($amountExc * ($vatRate / 100));
            }

            if (empty($_POST['transaction_id'])) {
                $stmt = $pdo->prepare("INSERT INTO subcontractor_transactions (subcontractor_id, client_id, work_id, transaction_date, transaction_type, amount, reference, notes, boq_data, document_path, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([ $post_sub_id, $selected_client_id, $work_id, $_POST['transaction_date'], $type, $amount, trim($_POST['reference']), trim($_POST['notes']), $boqDataJson, $docPath, getCurrentUserId() ]);
                $message = "Activity logged successfully!";
            } else {
                if ($docPath) {
                    $stmt = $pdo->prepare("UPDATE subcontractor_transactions SET work_id=?, transaction_date=?, transaction_type=?, amount=?, reference=?, notes=?, document_path=? WHERE id=? AND subcontractor_id=? AND client_id=?");
                    $stmt->execute([ $work_id, $_POST['transaction_date'], $type, $amount, trim($_POST['reference']), trim($_POST['notes']), $docPath, $_POST['transaction_id'], $post_sub_id, $selected_client_id ]);
                } else {
                    $stmt = $pdo->prepare("UPDATE subcontractor_transactions SET work_id=?, transaction_date=?, transaction_type=?, amount=?, reference=?, notes=? WHERE id=? AND subcontractor_id=? AND client_id=?");
                    $stmt->execute([ $work_id, $_POST['transaction_date'], $type, $amount, trim($_POST['reference']), trim($_POST['notes']), $_POST['transaction_id'], $post_sub_id, $selected_client_id ]);
                }
                $message = "Activity updated successfully!";
            }
            
            $pdo->commit();
        }
        elseif ($action === 'delete_transaction') {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT * FROM subcontractor_transactions WHERE id=? AND client_id=?");
            $stmt->execute([$_POST['transaction_id'], $selected_client_id]);
            $txn = $stmt->fetch();

            if ($txn && $txn['transaction_type'] === 'Certification' && !empty($txn['boq_data'])) {
                $boqItems = json_decode($txn['boq_data'], true);
                if (is_array($boqItems)) {
                    $revertBoq = $pdo->prepare("UPDATE subcontractor_boq SET pct_complete = ? WHERE id = ?");
                    $revertLevel = $pdo->prepare("UPDATE block_levels SET construction_status = ?, construction_pct = ? WHERE id = ?");
                    
                    foreach ($boqItems as $item) {
                        $revertBoq->execute([$item['old_pct'], $item['boq_id']]);
                        
                        if (!empty($item['level_id'])) {
                            $cStatus = 'Pending';
                            if ($item['old_pct'] >= 100) $cStatus = 'Complete';
                            elseif ($item['old_pct'] > 0) $cStatus = 'In Progress';
                            
                            $revertLevel->execute([$cStatus, $item['old_pct'], $item['level_id']]);
                        }
                    }
                }
            }
            
            $pdo->prepare("DELETE FROM subcontractor_transactions WHERE id=? AND client_id=?")->execute([$_POST['transaction_id'], $selected_client_id]);
            $message = "Activity deleted. Any related project progress was rolled back.";
            $pdo->commit();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

$allSubcontractors = $pdo->query("SELECT id, name FROM subcontractors ORDER BY name ASC")->fetchAll();
$clientProjects = [];

if ($selected_client_id) {
    $isPRA = false;
    foreach ($accessibleClients as $c) {
        if ($c['id'] == $selected_client_id && stripos($c['name'], 'PRA Construction') !== false) {
            $isPRA = true; break;
        }
    }
    if ($isPRA) {
        $cpStmt = $pdo->prepare("SELECT p.id, p.name, c.name as client_name FROM projects p LEFT JOIN clients c ON p.clientid = c.id WHERE p.clientid = ? OR c.name LIKE '%Excel%' ORDER BY c.name ASC, p.name ASC");
        $cpStmt->execute([$selected_client_id]);
        $clientProjects = $cpStmt->fetchAll();
    } else {
        $cpStmt = $pdo->prepare("SELECT id, name FROM projects WHERE clientid = ? ORDER BY name ASC");
        $cpStmt->execute([$selected_client_id]);
        $clientProjects = $cpStmt->fetchAll();
    }
}

$pageTitle = 'Subcontractor Accounts';
require_once 'header.php';
?>

<style>
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 3% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 800px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); max-height: 90vh; overflow-y: auto;}
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }
.summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.summary-card { background: var(--bg-panel); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--border-glass); text-align: center; }
.summary-card h4 { margin: 0 0 0.5rem 0; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;}
.summary-card .value { font-size: 1.5rem; font-weight: bold; color: var(--text-primary); }
.delta-positive { color: #10B981 !important; } 
.delta-negative { color: #EF4444 !important; } 
.client-bar { background: rgba(99, 102, 246, 0.1); border: 1px solid var(--primary-color); padding: 1rem 1.5rem; border-radius: 8px; display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem; }
.client-bar select { padding: 0.5rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-card); color: var(--text-primary); font-size: 1rem; min-width: 250px; }
.badge { padding: 2px 6px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
.badge-cert { background: rgba(59, 130, 246, 0.2); color: #3B82F6; }
.badge-inv { background: rgba(245, 158, 11, 0.2); color: #F59E0B; }
.badge-pay { background: rgba(16, 185, 129, 0.2); color: #10B981; }
.badge-boq { background: rgba(139, 92, 246, 0.2); color: #8B5CF6; border: 1px solid rgba(139,92,246,0.5); padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; margin-top: 4px; display: inline-block;}
.data-table th.col-divider, .data-table td.col-divider { border-left: 2px solid rgba(255,255,255,0.05); }

/* Progress Bar Styles */
.progress-wrapper { width: 100%; background-color: rgba(255,255,255,0.1); border-radius: 4px; overflow: hidden; height: 6px; margin-top: 6px; }
.progress-fill { background-color: #3B82F6; height: 100%; transition: width 0.3s ease; }
.progress-fill.over { background-color: #EF4444; } 

/* BoQ Builder Table */
.boq-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.85rem; }
.boq-table th { background: rgba(0,0,0,0.2); padding: 8px; text-align: left; color: var(--text-secondary); }
.boq-table td { padding: 5px; border-bottom: 1px solid rgba(255,255,255,0.05); }
.boq-input { width: 100%; background: #1e1e2d; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 6px; border-radius: 4px; }
.boq-input:focus { border-color: var(--primary-color); outline: none; }
</style>

<div class="main-container">
    <div class="client-bar">
        <strong style="color: var(--primary-color);">Account Context (Client):</strong>
        <form method="GET" style="margin: 0;">
            <select name="client_id" onchange="this.form.submit()">
                <option value="">-- Select a Client --</option>
                <?php foreach($accessibleClients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selected_client_id == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($sub_id): ?><input type="hidden" name="sub_id" value="<?= $sub_id ?>"><?php endif; ?>
        </form>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$selected_client_id): ?>
        <div class="empty-state" style="padding: 4rem 2rem; text-align: center; background: var(--bg-panel); border-radius: 8px;">
            <h2 style="color: var(--text-secondary);">Select a Client to Begin</h2>
            <p>Subcontractor accounts are strictly isolated by client. Please select a client from the dropdown above.</p>
        </div>
    <?php else: ?>

        <?php if (!$sub_id): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <h1 class="page-title" style="margin-bottom: 0;">Master Subcontractor Ledger</h1>
                    <p style="color: var(--text-secondary); margin-top: 0.25rem;">Overview of certified works, invoices, and payments for this client.</p>
                </div>
                <?php if ($canManage): ?>
                    <div style="display: flex; gap: 0.5rem;">
                        <button onclick="openWorkModal()" class="btn btn-primary">+ Create Work Order</button>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            $ledgerStmt = $pdo->prepare("
                SELECT 
                    s.id, s.name, s.contact_person,
                    COALESCE((SELECT SUM(amount) FROM subcontractor_transactions t WHERE t.subcontractor_id = s.id AND t.client_id = ? AND t.transaction_type = 'Certification'), 0) as total_certified,
                    COALESCE((SELECT SUM(amount) FROM subcontractor_transactions t WHERE t.subcontractor_id = s.id AND t.client_id = ? AND t.transaction_type = 'Invoice'), 0) as total_invoiced,
                    COALESCE((SELECT SUM(amount) FROM subcontractor_transactions t WHERE t.subcontractor_id = s.id AND t.transaction_type = 'Payment' AND t.client_id = ?), 0) as total_paid
                FROM subcontractors s
                WHERE EXISTS (SELECT 1 FROM subcontractor_works w WHERE w.subcontractor_id = s.id AND w.client_id = ?)
                   OR EXISTS (SELECT 1 FROM subcontractor_transactions t WHERE t.subcontractor_id = s.id AND t.client_id = ?)
                ORDER BY s.name ASC
            ");
            $ledgerStmt->execute([$selected_client_id, $selected_client_id, $selected_client_id, $selected_client_id, $selected_client_id]);
            $ledger = $ledgerStmt->fetchAll();
            ?>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Subcontractor</th>
                            <th style="text-align: right;">Total Certified</th>
                            <th style="text-align: right;">Total Invoiced</th>
                            <th style="text-align: right;">Paid to Date</th>
                            <th style="text-align: right;" class="col-divider">Due vs Certified (True)</th>
                            <th style="text-align: right;">Due vs Invoiced (Paper)</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ledger)): ?>
                            <tr><td colspan="7" style="text-align: center; padding: 2rem;">No subcontractor accounts found for this client.</td></tr>
                        <?php else: ?>
                            <?php 
                            $sys_cert = 0; $sys_inv = 0; $sys_paid = 0;
                            foreach ($ledger as $l): 
                                $sys_cert += $l['total_certified'];
                                $sys_inv += $l['total_invoiced'];
                                $sys_paid += $l['total_paid'];
                                $due_cert = $l['total_certified'] - $l['total_paid'];
                                $due_inv = $l['total_invoiced'] - $l['total_paid'];
                            ?>
                            <tr>
                                <td style="font-weight: bold; color: var(--primary-color);"><?= htmlspecialchars($l['name']) ?></td>
                                <td style="text-align: right;">€<?= number_format($l['total_certified'], 2) ?></td>
                                <td style="text-align: right;">€<?= number_format($l['total_invoiced'], 2) ?></td>
                                <td style="text-align: right; color: #10B981;">€<?= number_format($l['total_paid'], 2) ?></td>
                                <td style="text-align: right; font-weight: bold;" class="col-divider <?= $due_cert > 0 ? 'delta-negative' : 'delta-positive' ?>">
                                    €<?= number_format($due_cert, 2) ?>
                                </td>
                                <td style="text-align: right; font-weight: bold;" class="<?= $due_inv > 0 ? 'delta-negative' : 'delta-positive' ?>">
                                    €<?= number_format($due_inv, 2) ?>
                                </td>
                                <td style="text-align: center;">
                                    <a href="?client_id=<?= $selected_client_id ?>&sub_id=<?= $l['id'] ?>" class="btn btn-sm btn-secondary">View Account</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($ledger)): ?>
                    <tfoot>
                        <tr style="background: rgba(255,255,255,0.05); font-weight: bold;">
                            <td>CLIENT TOTALS</td>
                            <td style="text-align: right;">€<?= number_format($sys_cert, 2) ?></td>
                            <td style="text-align: right;">€<?= number_format($sys_inv, 2) ?></td>
                            <td style="text-align: right; color: #10B981;">€<?= number_format($sys_paid, 2) ?></td>
                            <td style="text-align: right;" class="col-divider <?= ($sys_cert - $sys_paid) > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($sys_cert - $sys_paid, 2) ?></td>
                            <td style="text-align: right;" class="<?= ($sys_inv - $sys_paid) > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($sys_inv - $sys_paid, 2) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

        <?php else: ?>
            <?php
            $stmt = $pdo->prepare("SELECT * FROM subcontractors WHERE id = ?"); 
            $stmt->execute([$sub_id]); 
            $sub = $stmt->fetch();
            if (!$sub) die("Subcontractor not found.");

            // Fetch Available Invoices for the "Match Invoice" feature
            $invStmt = $pdo->prepare("SELECT id, reference, amount FROM subcontractor_transactions WHERE subcontractor_id = ? AND client_id = ? AND transaction_type = 'Invoice' ORDER BY transaction_date DESC");
            $invStmt->execute([$sub_id, $selected_client_id]); 
            $availableInvoices = $invStmt->fetchAll();

            // Fetch Works & Calculate iteratively from transactions
            $wStmt = $pdo->prepare("
                SELECT w.*, p.name as project_name, c.name as project_client_name,
                (SELECT SUM(amount) FROM subcontractor_transactions WHERE work_id = w.id AND transaction_type = 'Certification') as cert_total,
                (SELECT SUM(amount) FROM subcontractor_transactions WHERE work_id = w.id AND transaction_type = 'Invoice') as inv_total,
                (SELECT SUM(amount) FROM subcontractor_transactions WHERE work_id = w.id AND transaction_type = 'Payment') as pay_total
                FROM subcontractor_works w 
                LEFT JOIN projects p ON w.project_id = p.id 
                LEFT JOIN clients c ON p.clientid = c.id
                WHERE w.subcontractor_id = ? AND w.client_id = ? 
                ORDER BY w.id DESC
            ");
            $wStmt->execute([$sub_id, $selected_client_id]); 
            $works = $wStmt->fetchAll();

            // Setup Filters
            $filter_work_id = isset($_GET['filter_work_id']) ? $_GET['filter_work_id'] : '';
            $filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : '';

            // Fetch All Transactions (With Filters)
            $tQuery = "
                SELECT t.*, w.work_reference 
                FROM subcontractor_transactions t
                LEFT JOIN subcontractor_works w ON t.work_id = w.id
                WHERE t.subcontractor_id = ? AND t.client_id = ?
            ";
            $tParams = [$sub_id, $selected_client_id];

            if ($filter_work_id !== '') {
                if ($filter_work_id === 'global') {
                    $tQuery .= " AND t.work_id IS NULL";
                } else {
                    $tQuery .= " AND t.work_id = ?";
                    $tParams[] = $filter_work_id;
                }
            }

            if ($filter_type !== '') {
                $tQuery .= " AND t.transaction_type = ?";
                $tParams[] = $filter_type;
            }

            $tQuery .= " ORDER BY t.transaction_date DESC, t.id DESC";

            $tStmt = $pdo->prepare($tQuery);
            $tStmt->execute($tParams); 
            $transactions = $tStmt->fetchAll();

            // Global Calculations
            $tot_cert = 0; $tot_paid = 0; $tot_inv = 0;
            foreach ($transactions as $t) {
                if ($t['transaction_type'] === 'Certification') $tot_cert += $t['amount'];
                if ($t['transaction_type'] === 'Payment') $tot_paid += $t['amount'];
                if ($t['transaction_type'] === 'Invoice') $tot_inv += $t['amount'];
            }
            $due_cert_global = $tot_cert - $tot_paid;
            $due_inv_global = $tot_inv - $tot_paid;
            ?>

          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <a href="subcontractor_accounts.php?client_id=<?= $selected_client_id ?>" style="color: var(--text-muted); text-decoration: none; font-size: 0.9rem;">&larr; Back to Client Ledger</a>
                    <h1 class="page-title" style="margin-bottom: 0; margin-top: 0.5rem;">Account: <?= htmlspecialchars($sub['name']) ?></h1>
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <a href="print_subcon_report.php?client_id=<?= $selected_client_id ?>&sub_id=<?= $sub_id ?>" target="_blank" class="btn" style="background: #ef4444; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; display: flex; align-items: center; gap: 5px; font-weight: bold;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        PDF Statement
                    </a>
                    
                    <?php if ($canManage): ?>
                        <button onclick="openWorkModal()" class="btn btn-primary">+ Create Work Order</button>
                        <button onclick="openTxModal()" class="btn btn-secondary">+ Log Global Activity</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="summary-cards">
                <div class="summary-card">
                    <h4>Total Certified (Inc VAT)</h4>
                    <div class="value" style="color: #3B82F6;">€<?= number_format($tot_cert, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Total Paid to Date</h4>
                    <div class="value" style="color: #10B981;">€<?= number_format($tot_paid, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>True Liability (Due vs Cert)</h4>
                    <div class="value <?= $due_cert_global > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($due_cert_global, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Paper Liability (Due vs Inv)</h4>
                    <div class="value <?= $due_inv_global > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($due_inv_global, 2) ?></div>
                </div>
            </div>

            <h3 style="margin-top: 2rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Work Orders & Progress</h3>
            <div class="table-container" style="margin-bottom: 3rem;">
                <table class="data-table" style="font-size: 0.85rem;">
                    <thead>
                        <tr>
                            <th>Work Ref / Project</th>
                            <th style="text-align: right;">Est. Value</th>
                            <th style="text-align: right;">Certified</th>
                            <th style="text-align: center; width: 100px;">% Progress</th>
                            <th style="text-align: right;">Invoiced</th>
                            <th style="text-align: right;">Paid</th>
                            <th style="text-align: right;" class="col-divider">Pending Inv</th>
                            <th style="text-align: right;">Due vs Cert</th>
                            <th style="text-align: right;">Due vs Inv</th>
                            <?php if($canManage): ?><th style="text-align: center;">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($works)): ?>
                            <tr><td colspan="<?= $canManage ? '10' : '9' ?>" style="text-align: center;">No Work Orders found.</td></tr>
                        <?php else: ?>
                            <?php foreach($works as $w): 
                                $c_tot = $w['cert_total'] ?: 0;
                                $i_tot = $w['inv_total'] ?: 0;
                                $p_tot = $w['pay_total'] ?: 0;
                                
                                $pend_inv = $c_tot - $i_tot;
                                $due_c = $c_tot - $p_tot;
                                $due_i = $i_tot - $p_tot;

                                // Progress Calculation
                                $prog_pct = $w['total_inc_vat'] > 0 ? ($c_tot / $w['total_inc_vat']) * 100 : 0;
                                $prog_class = $prog_pct > 100 ? 'over' : '';
                                
                                // Payload for specific modal stats (Using htmlspecialchars to prevent any HTML breakage)
                                $statsPayload = htmlspecialchars(json_encode([
                                    'tot' => $w['total_inc_vat'],
                                    'cert' => $c_tot,
                                    'inv' => $i_tot,
                                    'pay' => $p_tot
                                ]), ENT_QUOTES, 'UTF-8');
                                
                                // JSON Payload for the Edit button
                                $wJson = htmlspecialchars(json_encode($w), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; font-size: 0.95rem;"><?= htmlspecialchars($w['work_reference']) ?></div>
                                    <div style="color: var(--text-muted);">
                                        <?= htmlspecialchars($w['project_name'] ?? 'General') ?>
                                        <?php if (!empty($w['project_client_name']) && stripos($w['project_client_name'], 'Excel') !== false): ?>
                                            <span style="color: #8B5CF6;">(<?= htmlspecialchars($w['project_client_name']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if(!empty($w['po_reference'])): ?>
                                        <div style="display:inline-block; font-size:0.7rem; color:var(--text-muted); margin-top:2px;">PO: <?= htmlspecialchars($w['po_reference']) ?></div><br>
                                    <?php endif; ?>
                                    <?php if($w['is_measured']): ?>
                                        <div class="badge-boq">📐 Measured / Level BoQ</div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; color: var(--text-secondary);">€<?= number_format($w['total_inc_vat'], 2) ?></td>
                                <td style="text-align: right; font-weight: bold; color: #3B82F6;">€<?= number_format($c_tot, 2) ?></td>
                                
                                <td style="text-align: center; vertical-align: middle;">
                                    <div style="font-size: 0.8rem; font-weight: bold; margin-bottom: 2px; color: <?= $prog_pct > 100 ? '#EF4444' : '#3B82F6' ?>;">
                                        <?= number_format($prog_pct, 1) ?>%
                                    </div>
                                    <div class="progress-wrapper">
                                        <div class="progress-fill <?= $prog_class ?>" style="width: <?= min(100, $prog_pct) ?>%;"></div>
                                    </div>
                                </td>

                                <td style="text-align: right; font-weight: bold; color: #F59E0B;">€<?= number_format($i_tot, 2) ?></td>
                                <td style="text-align: right; font-weight: bold; color: #10B981;">€<?= number_format($p_tot, 2) ?></td>
                                
                                <td style="text-align: right;" class="col-divider <?= $pend_inv > 0 ? 'delta-negative' : '' ?>">€<?= number_format($pend_inv, 2) ?></td>
                                <td style="text-align: right; font-weight: bold;" class="<?= $due_c > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($due_c, 2) ?></td>
                                <td style="text-align: right; font-weight: bold;" class="<?= $due_i > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($due_i, 2) ?></td>
                                
                                <?php if($canManage): ?>
                                <td style="text-align: center; vertical-align: middle;">
                                    <div style="display: flex; gap: 4px; justify-content: center; align-items: center;">
                                        <button onclick="openTxModal(null, <?= $w['id'] ?>, 'Certification', <?= $w['is_measured'] ? 'true' : 'false' ?>, <?= $w['vat_rate'] ?>, <?= $statsPayload ?>)" class="btn btn-sm" style="background: rgba(59,130,246,0.1); color: #3B82F6; border: 1px solid #3B82F6; padding: 2px 6px;" title="Add Certificate">Cert</button>
                                        <button onclick="openTxModal(null, <?= $w['id'] ?>, 'Invoice', <?= $w['is_measured'] ? 'true' : 'false' ?>, <?= $w['vat_rate'] ?>, <?= $statsPayload ?>)" class="btn btn-sm" style="background: rgba(245,158,11,0.1); color: #F59E0B; border: 1px solid #F59E0B; padding: 2px 6px;" title="Add Invoice">Inv</button>
                                        <button onclick="openTxModal(null, <?= $w['id'] ?>, 'Payment', <?= $w['is_measured'] ? 'true' : 'false' ?>, <?= $w['vat_rate'] ?>, <?= $statsPayload ?>)" class="btn btn-sm" style="background: rgba(16,185,129,0.1); color: #10B981; border: 1px solid #10B981; padding: 2px 6px;" title="Add Payment">Pay</button>
                                        <button onclick="openWorkModal(<?= $wJson ?>)" class="btn btn-sm btn-secondary" title="Edit Work Order" style="padding: 2px 6px;">✎</button>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
                <h3 style="margin: 0; border: none; padding: 0;">Complete Activity Log</h3>
                
                <form method="GET" style="margin: 0; display: flex; gap: 10px; align-items: center; font-size: 0.85rem;">
                    <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
                    <input type="hidden" name="sub_id" value="<?= $sub_id ?>">
                    
                    <select name="filter_work_id" style="padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border-glass); background: var(--bg-card); color: var(--text-primary); max-width: 200px;">
                        <option value="">-- All Work Orders --</option>
                        <option value="global" <?= $filter_work_id === 'global' ? 'selected' : '' ?>>Global / Unlinked Activity</option>
                        <?php foreach($works as $w): ?>
                            <option value="<?= $w['id'] ?>" <?= $filter_work_id == $w['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($w['work_reference']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="filter_type" style="padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border-glass); background: var(--bg-card); color: var(--text-primary);">
                        <option value="">-- All Types --</option>
                        <option value="Certification" <?= $filter_type === 'Certification' ? 'selected' : '' ?>>Certifications</option>
                        <option value="Invoice" <?= $filter_type === 'Invoice' ? 'selected' : '' ?>>Invoices</option>
                        <option value="Payment" <?= $filter_type === 'Payment' ? 'selected' : '' ?>>Payments</option>
                    </select>

                    <button type="submit" class="btn btn-sm btn-secondary" style="padding: 4px 10px;">Filter</button>
                    <?php if($filter_work_id !== '' || $filter_type !== ''): ?>
                        <a href="?client_id=<?= $selected_client_id ?>&sub_id=<?= $sub_id ?>" class="btn btn-sm" style="background: rgba(255,255,255,0.1); color: var(--text-muted); text-decoration: none; padding: 4px 10px;">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Linked Work Order</th>
                            <th>Reference</th>
                            <th style="text-align: right;">Amount</th>
                            <th>Notes</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="<?= $canManage ? '7' : '6' ?>" style="text-align: center;">No activity logged yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($transactions as $t): 
                                $badgeClass = 'badge-pay';
                                if ($t['transaction_type'] === 'Certification') $badgeClass = 'badge-cert';
                                if ($t['transaction_type'] === 'Invoice') $badgeClass = 'badge-inv';
                                
                                // Fetch Work Data for the Edit Button to maintain IsMeasured state
                                $t_work = null;
                                foreach($works as $w) { if ($w['id'] == $t['work_id']) { $t_work = $w; break; } }
                                $t_is_measured = $t_work ? ($t_work['is_measured'] ? 'true' : 'false') : 'false';
                                $t_vat_rate = $t_work ? $t_work['vat_rate'] : 18.00;
                                
                                $tJson = htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($t['transaction_date'])) ?></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($t['transaction_type']) ?></span></td>
                                <td style="font-size: 0.85rem; color: var(--text-secondary);"><?= htmlspecialchars($t['work_reference'] ?? 'Global / Unlinked') ?></td>
                                <td><?= htmlspecialchars($t['reference']) ?></td>
                                <td style="text-align: right; font-weight: bold;">€<?= number_format($t['amount'], 2) ?></td>
                                <td><span style="font-size: 0.85rem; color: var(--text-muted);"><?= htmlspecialchars($t['notes']) ?></span></td>
                                <td style="text-align: right; min-width: 150px;">
                                    <?php if ($t['transaction_type'] === 'Certification' && $t['work_id']): ?>
                                        <a href="print_certificate.php?tx_id=<?= $t['id'] ?>" target="_blank" class="btn btn-sm" style="background: #3B82F6; color: white; margin-right: 5px;">PDF Cert</a>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($t['document_path'])): ?>
                                        <?php 
                                        $docUrl = $t['document_path'];
                                        if (strpos($docUrl, 'http') === false) {
                                            require_once 'S3FileManager.php';
                                            $s3Temp = new S3FileManager();
                                            $docUrl = $s3Temp->getPresignedUrl($t['document_path'], '+60 minutes');
                                        }
                                        ?>
                                        <?php if ($t['transaction_type'] === 'Invoice'): ?>
                                            <a href="<?= htmlspecialchars($docUrl) ?>" target="_blank" class="btn btn-sm" style="background: #8b5cf6; color: white; margin-right: 5px;">View Invoice</a>
                                        <?php else: ?>
                                            <a href="<?= htmlspecialchars($docUrl) ?>" target="_blank" class="btn btn-sm" style="background: #6b7280; color: white; margin-right: 5px;">View Details</a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if($canManage): ?>
                                        <button onclick="openTxModal(<?= $tJson ?>, <?= $t['work_id'] ?: 'null' ?>, '<?= $t['transaction_type'] ?>', <?= $t_is_measured ?>, <?= $t_vat_rate ?>)" class="btn btn-sm btn-secondary" style="margin-right: 5px;">Edit</button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure? If this is a Certification, the floors will be rolled back to their previous completion %.');">
                                            <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
                                            <input type="hidden" name="action" value="delete_transaction"><input type="hidden" name="transaction_id" value="<?= $t['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">X</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; // End Detail View ?>

        <?php if ($canManage): ?>
        
        <div id="workModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('workModal')">&times;</span>
                <h2 id="wModalTitle" style="color: var(--primary-color);">Create Work Order</h2>
                <form method="POST" id="woForm">
                    <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
                    <input type="hidden" name="action" value="save_work">
                    <input type="hidden" name="work_id" id="w_id">
                    
                    <?php if (!$sub_id): ?>
                        <div class="form-group">
                            <label>Subcontractor *</label>
                            <select name="subcontractor_id" required>
                                <option value="">-- Choose Subcontractor --</option>
                                <?php foreach($allSubcontractors as $subc): ?><option value="<?= $subc['id'] ?>"><?= htmlspecialchars($subc['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="subcontractor_id" value="<?= $sub_id ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>Project Context</label>
                        <select name="project_id" id="w_project_id" onchange="checkMeasuredSetup()">
                            <option value="">-- General / No Specific Project --</option>
                            <?php foreach($clientProjects as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> <?= isset($p['client_name']) && $p['client_name'] ? '('.htmlspecialchars($p['client_name']).')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-grid" style="grid-template-columns: 2fr 1fr; gap: 10px;">
                        <div class="form-group">
                            <label>Work Reference / Description *</label>
                            <input type="text" name="work_reference" id="w_ref" placeholder="e.g. Concrete Works Block A" required>
                        </div>
                        <div class="form-group">
                            <label>Contract Type</label>
                            <select name="is_measured" id="w_is_measured" onchange="checkMeasuredSetup()">
                                <option value="0">Lump Sum (Standard)</option>
                                <option value="1">Measured / BoQ (Linked to Levels)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid" style="grid-template-columns: 2fr 1fr; gap: 10px;">
                        <div class="form-group"><label>PO Reference (Optional)</label><input type="text" name="po_reference" id="w_po" placeholder="e.g. PO-2026-451"></div>
                        <div class="form-group"><label>Responsible Person</label><input type="text" name="responsible" id="w_resp"></div>
                    </div>

                    <div id="lumpSumFields" class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                        <div class="form-group"><label>Est. Exc VAT *</label><input type="number" step="0.01" name="total_exc_vat" id="w_exc" oninput="calcVAT()"></div>
                        <div class="form-group"><label>VAT Rate</label><select name="vat_rate" id="w_vat_rate" onchange="calcVAT()"><option value="18.00">18%</option><option value="0.00">0%</option></select></div>
                        <div class="form-group"><label>Est. Inc VAT</label><input type="number" step="0.01" name="total_inc_vat" id="w_inc" readonly style="background: rgba(255,255,255,0.05); cursor: not-allowed;"></div>
                    </div>

                    <div id="measuredFields" style="display:none; background: rgba(139,92,246,0.05); padding: 15px; border-radius: 8px; border: 1px solid rgba(139,92,246,0.3); margin-bottom: 15px;">
                        <h4 style="margin: 0 0 10px 0; color: #8B5CF6;">📊 Bill of Quantities (BoQ) Builder</h4>
                        <table class="boq-table" id="boqTable">
                            <thead><tr><th>Level / Description</th><th>Qty/Area (sq.m)</th><th>Rate (€)</th><th>Total (€)</th></tr></thead>
                            <tbody id="boqBody">
                                </tbody>
                            <tfoot>
                                <tr><th colspan="3" style="text-align: right;">Total Measured Value (Exc VAT):</th><th id="boqGrandTotal">€0.00</th></tr>
                            </tfoot>
                        </table>
                        <button type="button" onclick="addCustomBoqRow()" class="btn btn-sm" style="margin-top:10px; background: rgba(255,255,255,0.1);">+ Add Custom Extra Row</button>
                    </div>

                    <div class="form-group"><label>Notes / Contract Details</label><textarea name="notes" id="w_notes" rows="3"></textarea></div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Save Work Order</button>
                </form>
            </div>
        </div>

        <div id="txModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('txModal')">&times;</span>
                <h2 id="tModalTitle" style="color: var(--primary-color);">Log Activity</h2>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
                    <input type="hidden" name="action" value="save_transaction">
                    <input type="hidden" name="transaction_id" id="t_id">
                    
                    <div id="t_work_stats" style="display:none;"></div>
                    
                    <?php if (!$sub_id): ?>
                        <div class="form-group">
                            <label>Subcontractor *</label>
                            <select name="subcontractor_id" required>
                                <option value="">-- Choose Subcontractor --</option>
                                <?php foreach($allSubcontractors as $subc): ?><option value="<?= $subc['id'] ?>"><?= htmlspecialchars($subc['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="subcontractor_id" value="<?= $sub_id ?>">
                        <div class="form-group">
                            <label>Link to Specific Work Order</label>
                            <select name="work_id" id="t_work_id">
                                <option value="">-- Global / Unlinked --</option>
                                <?php if(!empty($works)): foreach($works as $w): ?>
                                    <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['work_reference']) ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <input type="hidden" name="work_vat_rate" id="t_vat_rate" value="18">

                    <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group"><label>Date *</label><input type="date" name="transaction_date" id="t_date" required value="<?= date('Y-m-d') ?>"></div>
                        <div class="form-group">
                            <label>Activity Type *</label>
                            <select name="transaction_type" id="t_type" required onchange="toggleInvoiceMatch(); checkMeasuredCert();">
                                <option value="Certification">1. Certification (Increases Owed)</option>
                                <option value="Invoice">2. Invoice Received (Paper Trail)</option>
                                <option value="Payment">3. Payment Made (Reduces Owed)</option>
                                <option value="Credit Note">Credit Note</option>
                                <option value="Adjustment">Adjustment</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" id="t_file_group" style="display:none; background: rgba(139, 92, 246, 0.1); padding: 15px; border-radius: 8px; border: 1px dashed var(--border-glass); margin-bottom: 15px;">
                        <label style="color: #8B5CF6;" id="t_file_label">📎 Upload Document</label>
                        <input type="file" name="invoice_file" id="t_invoice_file" accept="application/pdf,image/*" style="width: 100%; color: var(--text-primary); margin-top: 5px;">
                        <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 5px;" id="t_file_desc">This file will be attached to the transaction.</p>
                    </div>

                    <?php if ($sub_id && !empty($availableInvoices)): ?>
                    <div class="form-group" id="t_invoice_match_group" style="display:none; background: rgba(59, 130, 246, 0.1); padding: 10px; border-radius: 6px; border: 1px solid rgba(59, 130, 246, 0.3);">
                        <label style="color: #3B82F6;">⚡ Match to Existing Invoice (Auto-fill)</label>
                        <select id="t_invoice_match" onchange="applyInvoiceMatch()">
                            <option value="">-- Select an Invoice to Pay --</option>
                            <?php foreach($availableInvoices as $inv): ?>
                                <option value="<?= $inv['amount'] ?>" data-ref="<?= htmlspecialchars($inv['reference']) ?>">
                                    Inv: <?= htmlspecialchars($inv['reference'] ?: 'No Ref') ?> (€<?= number_format($inv['amount'], 2) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group" id="t_amount_group">
                        <label>Amount (Inc VAT) *</label>
                        <input type="number" step="0.01" name="amount" id="t_amount">
                    </div>

                    <div id="measuredCertGrid" style="display:none; background: rgba(59,130,246,0.05); padding: 15px; border-radius: 8px; border: 1px solid rgba(59,130,246,0.3); margin-bottom: 15px;">
                        <h4 style="margin: 0 0 10px 0; color: #3B82F6;">📈 Progress Certification</h4>
                        <table class="boq-table">
                            <thead><tr><th>Level / Item</th><th>Total Val</th><th>Prev %</th><th>New % Complete</th><th>€ to Certify</th></tr></thead>
                            <tbody id="certBody">
                                </tbody>
                            <tfoot>
                                <tr><th colspan="4" style="text-align: right;">Total This Certificate (Exc VAT):</th><th id="certExcTotal">€0.00</th></tr>
                                <tr><th colspan="4" style="text-align: right; color: var(--primary-color);">Total This Certificate (Inc VAT):</th><th id="certIncTotal" style="color: var(--primary-color);">€0.00</th></tr>
                            </tfoot>
                        </table>
                        <p style="font-size:0.75rem; color:var(--text-muted); margin-top:5px;">* Reaching 100% on a level will automatically mark the project physical block level as Complete.</p>
                    </div>

                    <div class="form-group"><label>Reference (e.g. Cert #2, Inv #100, Chq #555)</label><input type="text" name="reference" id="t_ref"></div>
                    <div class="form-group"><label>Notes</label><textarea name="notes" id="t_notes" rows="2"></textarea></div>
                    
                    <button type="submit" class="btn btn-secondary" style="width: 100%; margin-top: 10px;">Save Activity</button>
                </form>
            </div>
        </div>

        <script>
        // --- SAFE WRAPPERS ---
        function openWorkModal(data = null) {
            try {
                document.getElementById('wModalTitle').textContent = data ? 'Edit Work Order' : 'Create Work Order';
                
                document.getElementById('w_id').value = data ? data.id : '';
                document.getElementById('w_project_id').value = (data && data.project_id) ? data.project_id : '';
                document.getElementById('w_ref').value = data ? data.work_reference : '';
                document.getElementById('w_po').value = (data && data.po_reference) ? data.po_reference : '';
                document.getElementById('w_resp').value = (data && data.responsible) ? data.responsible : '';
                
                let vat = (data && data.vat_rate) ? parseFloat(data.vat_rate).toFixed(2) : '18.00';
                document.getElementById('w_vat_rate').value = vat;
                
                document.getElementById('w_exc').value = (data && data.total_exc_vat) ? data.total_exc_vat : '';
                document.getElementById('w_inc').value = (data && data.total_inc_vat) ? data.total_inc_vat : '';
                document.getElementById('w_is_measured').value = (data && data.is_measured) ? data.is_measured : '0';
                document.getElementById('w_notes').value = (data && data.notes) ? data.notes : '';
                
                checkMeasuredSetup(data ? data.id : null); 
                document.getElementById('workModal').style.display = 'block';
            } catch (e) {
                console.error("Error opening Work Modal:", e);
                alert("An error occurred opening the Work Order form. Check console.");
            }
        }

        // --- BOQ BUILDER LOGIC ---
        function calcVAT() {
            let exc = parseFloat(document.getElementById('w_exc').value) || 0;
            let rate = parseFloat(document.getElementById('w_vat_rate').value) || 0;
            document.getElementById('w_inc').value = (exc + (exc * (rate / 100))).toFixed(2);
        }

        async function checkMeasuredSetup(work_id = null) {
            try {
                const isMeasured = document.getElementById('w_is_measured').value === '1';
                const pid = document.getElementById('w_project_id').value;
                
                document.getElementById('lumpSumFields').style.display = isMeasured ? 'none' : 'grid';
                document.getElementById('measuredFields').style.display = isMeasured ? 'block' : 'none';
                
                if (isMeasured) {
                    document.getElementById('w_exc').readOnly = true; 
                    
                    if (work_id) {
                        const formData = new URLSearchParams({ ajax_action: 'get_boq_progress', work_id: work_id });
                        const res = await fetch('subcontractor_accounts.php', { method: 'POST', body: formData });
                        const boq = await res.json();
                        
                        let html = '';
                        boq.forEach(b => {
                            html += `<tr>
                                <td>
                                    <input type="hidden" name="boq_item_id[]" value="${b.id}">
                                    <input type="hidden" name="boq_level_id[]" value="${b.block_level_id || ''}">
                                    <input type="text" name="boq_desc[]" value="${b.description}" class="boq-input" ${b.block_level_id ? 'readonly' : ''}>
                                </td>
                                <td><input type="number" step="0.01" name="boq_qty[]" class="boq-input b-qty" value="${b.qty}" oninput="calcBoq()"></td>
                                <td><input type="number" step="0.01" name="boq_rate[]" class="boq-input b-rate" value="${b.rate}" oninput="calcBoq()"></td>
                                <td class="b-total">€${b.total_exc}</td>
                            </tr>`;
                        });
                        document.getElementById('boqBody').innerHTML = html;
                        calcBoq(); 
                    } 
                    else if (pid) {
                        const formData = new URLSearchParams({ ajax_action: 'get_project_levels', project_id: pid });
                        const res = await fetch('subcontractor_accounts.php', { method: 'POST', body: formData });
                        const levels = await res.json();
                        
                        let html = '';
                        levels.forEach(l => {
                            html += `<tr>
                                <td>
                                    <input type="hidden" name="boq_item_id[]" value="">
                                    <input type="hidden" name="boq_level_id[]" value="${l.id}">
                                    <input type="text" name="boq_desc[]" value="${l.block_name} - ${l.level_name}" class="boq-input" readonly>
                                </td>
                                <td><input type="number" step="0.01" name="boq_qty[]" class="boq-input b-qty" oninput="calcBoq()"></td>
                                <td><input type="number" step="0.01" name="boq_rate[]" class="boq-input b-rate" oninput="calcBoq()"></td>
                                <td class="b-total">€0.00</td>
                            </tr>`;
                        });
                        document.getElementById('boqBody').innerHTML = html;
                        calcBoq(); 
                    } else {
                        document.getElementById('boqBody').innerHTML = '<tr><td colspan="4">Please select a project first.</td></tr>';
                    }
                } else {
                    document.getElementById('w_exc').readOnly = false;
                }
            } catch(e) {
                console.error("Error in checkMeasuredSetup:", e);
            }
        }

        function addCustomBoqRow() {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <input type="hidden" name="boq_item_id[]" value="">
                    <input type="hidden" name="boq_level_id[]" value="">
                    <input type="text" name="boq_desc[]" placeholder="e.g. Foundation Extra" class="boq-input">
                </td>
                <td><input type="number" step="0.01" name="boq_qty[]" class="boq-input b-qty" oninput="calcBoq()"></td>
                <td><input type="number" step="0.01" name="boq_rate[]" class="boq-input b-rate" oninput="calcBoq()"></td>
                <td class="b-total">€0.00</td>
            `;
            document.getElementById('boqBody').appendChild(tr);
        }

        function calcBoq() {
            let totalExc = 0;
            const rows = document.querySelectorAll('#boqBody tr');
            rows.forEach(row => {
                let q = parseFloat(row.querySelector('.b-qty')?.value) || 0;
                let r = parseFloat(row.querySelector('.b-rate')?.value) || 0;
                let t = q * r;
                totalExc += t;
                if(row.querySelector('.b-total')) row.querySelector('.b-total').innerText = '€' + t.toFixed(2);
            });
            document.getElementById('boqGrandTotal').innerText = '€' + totalExc.toFixed(2);
            document.getElementById('w_exc').value = totalExc.toFixed(2);
            calcVAT(); // Trigger VAT sync
        }

        // --- CERTIFICATION PROGRESS LOGIC ---
        let currentModalIsMeasured = false;
        let isEditMode = false;

        const workDropdown = document.getElementById('t_work_id');
        if (workDropdown) {
            workDropdown.addEventListener('change', function() {
                document.getElementById('t_work_stats').style.display = 'none';
            });
        }

        async function openTxModal(data, work_id, type, isMeasured, vatRate, stats = null) {
            try {
                currentModalIsMeasured = isMeasured === true || isMeasured === 'true' || isMeasured === 1;
                isEditMode = (data !== null);
                
                document.getElementById('t_work_id').value = work_id || '';
                document.getElementById('t_type').value = type || 'Certification';
                document.getElementById('t_vat_rate').value = vatRate || 18;
                
                const fileInput = document.getElementById('t_invoice_file');
                if(fileInput) fileInput.value = '';

                const statsContainer = document.getElementById('t_work_stats');
                if (stats && stats.tot > 0) {
                    const c_pct = ((stats.cert / stats.tot) * 100).toFixed(1);
                    const i_pct = ((stats.inv / stats.tot) * 100).toFixed(1);
                    const p_pct = ((stats.pay / stats.tot) * 100).toFixed(1);
                    
                    statsContainer.innerHTML = `
                        <div style="display:flex; justify-content: space-between; text-align: center; font-size: 0.8rem; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 6px; margin-bottom: 15px; border: 1px solid var(--border-glass);">
                            <div><span style="color:var(--text-muted)">Order Value</span><br><strong>€${parseFloat(stats.tot).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</strong></div>
                            <div><span style="color:#3B82F6">Certified</span><br><strong>€${parseFloat(stats.cert).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}<br>(${c_pct}%)</strong></div>
                            <div><span style="color:#F59E0B">Invoiced</span><br><strong>€${parseFloat(stats.inv).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}<br>(${i_pct}%)</strong></div>
                            <div><span style="color:#10B981">Paid</span><br><strong>€${parseFloat(stats.pay).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}<br>(${p_pct}%)</strong></div>
                        </div>
                    `;
                    statsContainer.style.display = 'block';
                } else {
                    statsContainer.style.display = 'none';
                }

                if (data) {
                    document.getElementById('tModalTitle').textContent = 'Edit Activity';
                    document.getElementById('t_id').value = data.id;
                    document.getElementById('t_date').value = data.transaction_date;
                    document.getElementById('t_amount').value = data.amount;
                    document.getElementById('t_ref').value = data.reference || '';
                    document.getElementById('t_notes').value = data.notes || '';
                } else {
                    document.getElementById('tModalTitle').textContent = 'Log ' + (type || 'Activity');
                    document.getElementById('t_id').value = '';
                    document.getElementById('t_amount').value = '';
                    document.getElementById('t_ref').value = '';
                    document.getElementById('t_notes').value = '';
                }

                toggleInvoiceMatch();
                await checkMeasuredCert();
                
                document.getElementById('txModal').style.display = 'block';
            } catch(e) {
                console.error("Error opening TX Modal:", e);
                alert("An error occurred opening the Activity form. Check console.");
            }
        }

        async function checkMeasuredCert() {
            try {
                const type = document.getElementById('t_type').value;
                const work_id = document.getElementById('t_work_id').value;

                const existingWarn = document.getElementById('edit_cert_warning');
                if (existingWarn) existingWarn.remove();

                if (currentModalIsMeasured && type === 'Certification' && work_id) {
                    
                    if (isEditMode) {
                        document.getElementById('t_amount_group').style.display = 'block';
                        document.getElementById('t_amount').setAttribute('readonly', 'true');
                        document.getElementById('measuredCertGrid').style.display = 'none';
                        
                        let warn = document.createElement('div');
                        warn.id = 'edit_cert_warning';
                        warn.style.cssText = 'color: #f59e0b; font-size: 0.8rem; margin-top: 5px; padding: 10px; background: rgba(245,158,11,0.1); border-radius:6px; border: 1px solid rgba(245,158,11,0.3);';
                        warn.innerText = '⚠️ Quantities cannot be modified during an edit to preserve historical integrity. To alter the certified %, please delete this log (which will instantly restore the previous %) and create a new log.';
                        document.getElementById('t_amount_group').appendChild(warn);

                    } else {
                        document.getElementById('t_amount_group').style.display = 'none';
                        document.getElementById('t_amount').removeAttribute('required'); 
                        document.getElementById('t_amount').removeAttribute('readonly'); 
                        document.getElementById('measuredCertGrid').style.display = 'block';
                        
                        const formData = new URLSearchParams({ ajax_action: 'get_boq_progress', work_id: work_id });
                        const res = await fetch('subcontractor_accounts.php', { method: 'POST', body: formData });
                        const boq = await res.json();
                        
                        let html = '';
                        boq.forEach(b => {
                            const prevPct = parseFloat(b.pct_complete) || 0;
                            const totalExc = parseFloat(b.total_exc) || 0;

                            html += `<tr>
                                <td>
                                    <input type="hidden" name="cert_boq_id[]" value="${b.id}">
                                    <input type="hidden" name="cert_level_id[]" value="${b.block_level_id || ''}">
                                    <input type="hidden" name="cert_prev_pct[]" class="c-prev" value="${prevPct}">
                                    <input type="hidden" class="c-total" value="${totalExc}">
                                    ${b.description}
                                </td>
                                <td>€${totalExc.toFixed(2)}</td>
                                <td style="font-weight: bold; color: var(--text-secondary);">${prevPct.toFixed(1)}%</td>
                                <td>
                                    <input type="number" step="0.01" name="cert_new_pct[]" class="boq-input c-new" value="${prevPct}" oninput="calcCert()" onblur="enforceCertRules(this, ${prevPct})">
                                </td>
                                <td>
                                    <input type="hidden" name="cert_val_added[]" class="c-added-val" value="0">
                                    <span class="c-val-text" style="color: var(--primary-color); font-weight: bold;">€0.00</span>
                                </td>
                            </tr>`;
                        });
                        document.getElementById('certBody').innerHTML = html;
                        calcCert(); 
                    }
                } else {
                    document.getElementById('t_amount_group').style.display = 'block';
                    document.getElementById('t_amount').setAttribute('required', 'true');
                    document.getElementById('t_amount').removeAttribute('readonly');
                    document.getElementById('measuredCertGrid').style.display = 'none';
                }
            } catch(e) {
                console.error("Error in checkMeasuredCert:", e);
            }
        }

        function calcCert() {
            let certExc = 0;
            const vatRate = parseFloat(document.getElementById('t_vat_rate').value) || 0;
            const rows = document.querySelectorAll('#certBody tr');
            
            rows.forEach(row => {
                let tot = parseFloat(row.querySelector('.c-total').value) || 0;
                let prev = parseFloat(row.querySelector('.c-prev').value) || 0;
                let newPctInput = row.querySelector('.c-new');
                
                let current = parseFloat(newPctInput.value);
                if (isNaN(current)) current = 0;

                let effectivePct = current;
                if (effectivePct > 100) effectivePct = 100;
                if (effectivePct < prev) effectivePct = prev;

                let diff = effectivePct - prev;
                let val = 0;
                if (diff > 0) {
                    val = tot * (diff / 100);
                }
                
                certExc += val;
                row.querySelector('.c-added-val').value = val.toFixed(2);
                row.querySelector('.c-val-text').innerText = '+ €' + val.toFixed(2);
            });

            let certInc = certExc + (certExc * (vatRate / 100));
            document.getElementById('certExcTotal').innerText = '€' + certExc.toFixed(2);
            document.getElementById('certIncTotal').innerText = '€' + certInc.toFixed(2);
            
            document.getElementById('t_amount').value = certInc.toFixed(2);
        }

        function enforceCertRules(el, prev) {
            let val = parseFloat(el.value);
            if (isNaN(val) || val < prev) el.value = prev;
            if (val > 100) el.value = 100;
            calcCert();
        }

        function toggleInvoiceMatch() {
            try {
                var matchGroup = document.getElementById('t_invoice_match_group');
                var typeSelect = document.getElementById('t_type');
                var fileGroup = document.getElementById('t_file_group');
                var fileLabel = document.getElementById('t_file_label');
                var fileDesc = document.getElementById('t_file_desc');
                
                if(matchGroup && typeSelect) {
                    matchGroup.style.display = typeSelect.value === 'Payment' ? 'block' : 'none';
                }
                
                if(fileGroup && typeSelect) {
                    if (typeSelect.value === 'Invoice') {
                        fileGroup.style.display = 'block';
                        if(fileLabel) fileLabel.innerText = '📎 Upload Invoice Document';
                        if(fileDesc) fileDesc.innerText = 'This file will be attached and automatically saved to the Project Documentation vault.';
                    } else if (typeSelect.value === 'Certification') {
                        fileGroup.style.display = 'block';
                        if(fileLabel) fileLabel.innerText = '📎 Upload Supporting Details (PDF)';
                        if(fileDesc) fileDesc.innerText = 'This PDF will be automatically appended to the generated Certificate.';
                    } else {
                        fileGroup.style.display = 'none';
                    }
                }
            } catch(e) {
                console.error("Error in toggleInvoiceMatch:", e);
            }
        }

        function applyInvoiceMatch() {
            var sel = document.getElementById('t_invoice_match');
            if(sel && sel.value) {
                var opt = sel.options[sel.selectedIndex];
                document.getElementById('t_amount').value = sel.value;
                document.getElementById('t_ref').value = opt.getAttribute('data-ref') ? 'Paid ' + opt.getAttribute('data-ref') : 'Payment for Invoice';
            }
        }

        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        window.onclick = function(event) {
            if (event.target == document.getElementById('workModal')) closeModal('workModal');
            if (event.target == document.getElementById('txModal')) closeModal('txModal');
        }
        </script>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
