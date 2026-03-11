<?php
require_once 'init.php';
require_once 'session-check.php';

// Check Capabilities
if (!hasPermission('view_subcontractor_accounts') && !isAdmin()) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}
$canManage = hasPermission('manage_subcontractor_accounts') || isAdmin();

$message = ''; $error = '';

// Get Accessible Clients for the User
if (isAdmin()) {
    $accessibleClients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll();
} else {
    $accessibleClients = getUserClients($pdo, getCurrentUserId());
}

$selected_client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$sub_id = isset($_GET['sub_id']) ? (int)$_GET['sub_id'] : null;

// Handling POST actions (Adding/Editing Works & Transactions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage && $selected_client_id) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_work') {
            $post_sub_id = $_POST['subcontractor_id'];
            $project_id = !empty($_POST['project_id']) ? $_POST['project_id'] : null;
            
            if (empty($_POST['work_id'])) {
                $stmt = $pdo->prepare("INSERT INTO subcontractor_works (subcontractor_id, client_id, project_id, work_reference, responsible, total_exc_vat, total_inc_vat, certified_total_inc_vat, invoiced_value, invoice_ref, payment_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([ $post_sub_id, $selected_client_id, $project_id, trim($_POST['work_reference']), trim($_POST['responsible']), $_POST['total_exc_vat'] ?: 0, $_POST['total_inc_vat'] ?: 0, $_POST['certified_total_inc_vat'] ?: 0, $_POST['invoiced_value'] ?: 0, trim($_POST['invoice_ref']), !empty($_POST['payment_date']) ? $_POST['payment_date'] : null, trim($_POST['notes']) ]);
                $message = "Work summary added successfully!";
            } else {
                $stmt = $pdo->prepare("UPDATE subcontractor_works SET project_id=?, work_reference=?, responsible=?, total_exc_vat=?, total_inc_vat=?, certified_total_inc_vat=?, invoiced_value=?, invoice_ref=?, payment_date=?, notes=? WHERE id=? AND subcontractor_id=? AND client_id=?");
                $stmt->execute([ $project_id, trim($_POST['work_reference']), trim($_POST['responsible']), $_POST['total_exc_vat'] ?: 0, $_POST['total_inc_vat'] ?: 0, $_POST['certified_total_inc_vat'] ?: 0, $_POST['invoiced_value'] ?: 0, trim($_POST['invoice_ref']), !empty($_POST['payment_date']) ? $_POST['payment_date'] : null, trim($_POST['notes']), $_POST['work_id'], $post_sub_id, $selected_client_id ]);
                $message = "Work summary updated successfully!";
            }
        } 
        elseif ($action === 'delete_work') {
            $pdo->prepare("DELETE FROM subcontractor_works WHERE id=? AND client_id=?")->execute([$_POST['work_id'], $selected_client_id]);
            $message = "Work record deleted.";
        }
        elseif ($action === 'save_transaction') {
            $post_sub_id = $_POST['subcontractor_id'];
            if (empty($_POST['transaction_id'])) {
                $stmt = $pdo->prepare("INSERT INTO subcontractor_transactions (subcontractor_id, client_id, transaction_date, transaction_type, amount, reference, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([ $post_sub_id, $selected_client_id, $_POST['transaction_date'], $_POST['transaction_type'], $_POST['amount'] ?: 0, trim($_POST['reference']), trim($_POST['notes']), getCurrentUserId() ]);
                $message = "Transaction logged successfully!";
            } else {
                $stmt = $pdo->prepare("UPDATE subcontractor_transactions SET transaction_date=?, transaction_type=?, amount=?, reference=?, notes=? WHERE id=? AND subcontractor_id=? AND client_id=?");
                $stmt->execute([ $_POST['transaction_date'], $_POST['transaction_type'], $_POST['amount'] ?: 0, trim($_POST['reference']), trim($_POST['notes']), $_POST['transaction_id'], $post_sub_id, $selected_client_id ]);
                $message = "Transaction updated successfully!";
            }
        }
        elseif ($action === 'delete_transaction') {
            $pdo->prepare("DELETE FROM subcontractor_transactions WHERE id=? AND client_id=?")->execute([$_POST['transaction_id'], $selected_client_id]);
            $message = "Transaction deleted.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Fetch global lists for modals
$allSubcontractors = $pdo->query("SELECT id, name FROM subcontractors ORDER BY name ASC")->fetchAll();
$clientProjects = [];
if ($selected_client_id) {
    $cpStmt = $pdo->prepare("SELECT id, name FROM projects WHERE clientid = ? ORDER BY name ASC");
    $cpStmt->execute([$selected_client_id]);
    $clientProjects = $cpStmt->fetchAll();
}

$pageTitle = 'Subcontractor Accounts';
require_once 'header.php';
?>

<style>
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.modal-content { background-color: var(--bg-card); margin: 5% auto; padding: 2rem; border: 1px solid var(--border-glass); border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
.close-modal { color: var(--text-muted); float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; }
.close-modal:hover { color: var(--text-primary); }
.summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.summary-card { background: var(--bg-panel); padding: 1.5rem; border-radius: 8px; border: 1px solid var(--border-glass); text-align: center; }
.summary-card h4 { margin: 0 0 0.5rem 0; color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;}
.summary-card .value { font-size: 1.5rem; font-weight: bold; color: var(--text-primary); }
.delta-positive { color: #10B981 !important; } /* Overpaid / Favorable */
.delta-negative { color: #EF4444 !important; } /* Owed / Liability */
.client-bar { background: rgba(99, 102, 241, 0.1); border: 1px solid var(--primary-color); padding: 1rem 1.5rem; border-radius: 8px; display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem; }
.client-bar select { padding: 0.5rem; border-radius: 6px; border: 1px solid var(--border-glass); background: var(--bg-card); color: var(--text-primary); font-size: 1rem; min-width: 250px; }
</style>

<div class="main-container">
    
    <div class="client-bar">
        <strong style="color: var(--primary-color);">Account Context (Client):</strong>
        <form method="GET" style="margin: 0;">
            <select name="client_id" onchange="this.form.submit()">
                <option value="">-- Select a Client --</option>
                <?php foreach($accessibleClients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selected_client_id == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($sub_id): ?>
                <input type="hidden" name="sub_id" value="<?= $sub_id ?>">
            <?php endif; ?>
        </form>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$selected_client_id): ?>
        <div class="empty-state" style="padding: 4rem 2rem; text-align: center; background: var(--bg-panel); border-radius: 8px;">
            <h2 style="color: var(--text-secondary);">Select a Client to Begin</h2>
            <p>Subcontractor accounts are strictly isolated by client. Please select a client from the dropdown above to view their specific subcontractor liabilities and payments.</p>
        </div>
    <?php else: ?>

        <?php if (!$sub_id): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <h1 class="page-title" style="margin-bottom: 0;">Master Subcontractor Ledger</h1>
                    <p style="color: var(--text-secondary); margin-top: 0.25rem;">Overview of total certified works vs total payments for this client.</p>
                </div>
                <?php if ($canManage): ?>
                    <div style="display: flex; gap: 0.5rem;">
                        <button onclick="openWorkModal()" class="btn btn-primary">+ Start Account (Add Work)</button>
                        <button onclick="openTxModal()" class="btn btn-secondary">+ Log Initial Transaction</button>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            // Fetch ONLY subcontractors that have works OR transactions linked to this client
            $ledgerStmt = $pdo->prepare("
                SELECT 
                    s.id, s.name, s.contact_person,
                    COALESCE((SELECT SUM(certified_total_inc_vat) FROM subcontractor_works w WHERE w.subcontractor_id = s.id AND w.client_id = ?), 0) as total_certified,
                    COALESCE((SELECT SUM(amount) FROM subcontractor_transactions t WHERE t.subcontractor_id = s.id AND t.transaction_type = 'Payment' AND t.client_id = ?), 0) as total_paid
                FROM subcontractors s
                WHERE EXISTS (SELECT 1 FROM subcontractor_works w WHERE w.subcontractor_id = s.id AND w.client_id = ?)
                   OR EXISTS (SELECT 1 FROM subcontractor_transactions t WHERE t.subcontractor_id = s.id AND t.client_id = ?)
                ORDER BY s.name ASC
            ");
            $ledgerStmt->execute([$selected_client_id, $selected_client_id, $selected_client_id, $selected_client_id]);
            $ledger = $ledgerStmt->fetchAll();
            ?>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Subcontractor</th>
                            <th>Contact</th>
                            <th style="text-align: right;">Total Certified (inc VAT)</th>
                            <th style="text-align: right;">Total Paid to Date</th>
                            <th style="text-align: right;">Outstanding Liability</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ledger)): ?>
                            <tr><td colspan="6" style="text-align: center; padding: 2rem;">No subcontractor accounts found for this client. Click "Start Account" above to link a subcontractor.</td></tr>
                        <?php else: ?>
                            <?php 
                            $sys_cert = 0; $sys_paid = 0;
                            foreach ($ledger as $l): 
                                $sys_cert += $l['total_certified'];
                                $sys_paid += $l['total_paid'];
                                $owed = $l['total_certified'] - $l['total_paid'];
                            ?>
                            <tr>
                                <td style="font-weight: bold; color: var(--primary-color);"><?= htmlspecialchars($l['name']) ?></td>
                                <td><?= htmlspecialchars($l['contact_person'] ?? '-') ?></td>
                                <td style="text-align: right;">€<?= number_format($l['total_certified'], 2) ?></td>
                                <td style="text-align: right; color: #10B981;">€<?= number_format($l['total_paid'], 2) ?></td>
                                <td style="text-align: right; font-weight: bold;" class="<?= $owed > 0 ? 'delta-negative' : 'delta-positive' ?>">
                                    €<?= number_format($owed, 2) ?>
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
                            <td colspan="2">CLIENT TOTALS</td>
                            <td style="text-align: right;">€<?= number_format($sys_cert, 2) ?></td>
                            <td style="text-align: right; color: #10B981;">€<?= number_format($sys_paid, 2) ?></td>
                            <td style="text-align: right;" class="<?= ($sys_cert - $sys_paid) > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($sys_cert - $sys_paid, 2) ?></td>
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

            // Fetch Client-Specific Works
            $wStmt = $pdo->prepare("SELECT w.*, p.name as project_name FROM subcontractor_works w LEFT JOIN projects p ON w.project_id = p.id WHERE w.subcontractor_id = ? AND w.client_id = ? ORDER BY w.id DESC");
            $wStmt->execute([$sub_id, $selected_client_id]);
            $works = $wStmt->fetchAll();

            // Fetch Client-Specific Transactions
            $tStmt = $pdo->prepare("SELECT * FROM subcontractor_transactions WHERE subcontractor_id = ? AND client_id = ? ORDER BY transaction_date DESC, id DESC");
            $tStmt->execute([$sub_id, $selected_client_id]);
            $transactions = $tStmt->fetchAll();

            // Calculations
            $tot_cert = array_sum(array_column($works, 'certified_total_inc_vat'));
            $tot_paid = 0;
            foreach ($transactions as $t) {
                if ($t['transaction_type'] === 'Payment') $tot_paid += $t['amount'];
            }
            $delta = $tot_cert - $tot_paid;
            ?>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <a href="subcontractor_accounts.php?client_id=<?= $selected_client_id ?>" style="color: var(--text-muted); text-decoration: none; font-size: 0.9rem;">&larr; Back to Client Ledger</a>
                    <h1 class="page-title" style="margin-bottom: 0; margin-top: 0.5rem;">Account: <?= htmlspecialchars($sub['name']) ?></h1>
                </div>
                <?php if ($canManage): ?>
                    <div style="display: flex; gap: 0.5rem;">
                        <button onclick="openWorkModal()" class="btn btn-primary">+ Add Work Certification</button>
                        <button onclick="openTxModal()" class="btn btn-secondary">+ Log Transaction</button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="summary-cards">
                <div class="summary-card">
                    <h4>Total Certified Works (Inc VAT)</h4>
                    <div class="value">€<?= number_format($tot_cert, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Total Paid to Date</h4>
                    <div class="value" style="color: #10B981;">€<?= number_format($tot_paid, 2) ?></div>
                </div>
                <div class="summary-card">
                    <h4>Current Delta (Liability)</h4>
                    <div class="value <?= $delta > 0 ? 'delta-negative' : 'delta-positive' ?>">€<?= number_format($delta, 2) ?></div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 5px;">(Positive = Owed to Subcontractor)</div>
                </div>
            </div>

            <h3 style="margin-top: 2rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Works Summary</h3>
            <div class="table-container" style="margin-bottom: 3rem;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Project / Reference</th>
                            <th>Responsible</th>
                            <th style="text-align: right;">Total Exc VAT</th>
                            <th style="text-align: right;">Certified Inc VAT</th>
                            <th style="text-align: right;">Invoiced</th>
                            <th>Invoice Ref</th>
                            <th>Target Date</th>
                            <?php if($canManage): ?><th style="text-align: right;">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($works)): ?>
                            <tr><td colspan="<?= $canManage ? '8' : '7' ?>" style="text-align: center;">No works certified for this client yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($works as $w): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?= htmlspecialchars($w['work_reference']) ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($w['project_name'] ?? 'No Specific Project') ?></div>
                                </td>
                                <td><?= htmlspecialchars($w['responsible']) ?></td>
                                <td style="text-align: right;">€<?= number_format($w['total_exc_vat'], 2) ?></td>
                                <td style="text-align: right; font-weight: bold; color: var(--primary-color);">€<?= number_format($w['certified_total_inc_vat'], 2) ?></td>
                                <td style="text-align: right;">€<?= number_format($w['invoiced_value'], 2) ?></td>
                                <td><?= nl2br(htmlspecialchars($w['invoice_ref'])) ?></td>
                                <td><?= $w['payment_date'] ? date('d M Y', strtotime($w['payment_date'])) : '-' ?></td>
                                <?php if($canManage): ?>
                                <td style="text-align: right;">
                                    <button onclick='openWorkModal(<?= json_encode($w, JSON_HEX_APOS) ?>)' class="btn btn-sm btn-secondary">Edit</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this work record?');">
                                        <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
                                        <input type="hidden" name="action" value="delete_work"><input type="hidden" name="work_id" value="<?= $w['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">X</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h3 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">Transaction History</h3>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Reference</th>
                            <th style="text-align: right;">Amount</th>
                            <th>Notes</th>
                            <?php if($canManage): ?><th style="text-align: right;">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="<?= $canManage ? '6' : '5' ?>" style="text-align: center;">No transactions logged for this client yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($transactions as $t): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($t['transaction_date'])) ?></td>
                                <td>
                                    <span style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px; font-size: 0.8rem;">
                                        <?= htmlspecialchars($t['transaction_type']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($t['reference']) ?></td>
                                <td style="text-align: right; font-weight: bold; color: <?= $t['transaction_type'] === 'Payment' ? '#10B981' : 'var(--text-primary)' ?>;">
                                    €<?= number_format($t['amount'], 2) ?>
                                </td>
                                <td><span style="font-size: 0.85rem; color: var(--text-muted);"><?= htmlspecialchars($t['notes']) ?></span></td>
                                <?php if($canManage): ?>
                                <td style="text-align: right;">
                                    <button onclick='openTxModal(<?= json_encode($t, JSON_HEX_APOS) ?>)' class="btn btn-sm btn-secondary">Edit</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this transaction?');">
                                        <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
                                        <input type="hidden" name="action" value="delete_transaction"><input type="hidden" name="transaction_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">X</button>
                                    </form>
                                </td>
                                <?php endif; ?>
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
                <h2 id="wModalTitle" style="color: var(--primary-color);">Add Work</h2>
                <form method="POST">
                    <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
                    <input type="hidden" name="action" value="save_work">
                    <input type="hidden" name="work_id" id="w_id">
                    
                    <?php if (!$sub_id): // Drodown needed for Master Ledger View ?>
                        <div class="form-group">
                            <label>Subcontractor *</label>
                            <select name="subcontractor_id" required>
                                <option value="">-- Choose Subcontractor --</option>
                                <?php foreach($allSubcontractors as $subc): ?>
                                    <option value="<?= $subc['id'] ?>"><?= htmlspecialchars($subc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: // Hidden input for Detail View ?>
                        <input type="hidden" name="subcontractor_id" value="<?= $sub_id ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>Project</label>
                        <select name="project_id" id="w_project_id">
                            <option value="">-- No Specific Project --</option>
                            <?php foreach($clientProjects as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Work Reference / Description *</label>
                        <input type="text" name="work_reference" id="w_ref" required>
                    </div>
                    <div class="form-group">
                        <label>Responsible Person</label>
                        <input type="text" name="responsible" id="w_resp">
                    </div>
                    
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                        <div class="form-group"><label>Total Exc VAT</label><input type="number" step="0.01" name="total_exc_vat" id="w_exc"></div>
                        <div class="form-group"><label>Total Inc VAT</label><input type="number" step="0.01" name="total_inc_vat" id="w_inc"></div>
                        <div class="form-group"><label style="color: var(--primary-color); font-weight:bold;">Certified Inc VAT *</label><input type="number" step="0.01" name="certified_total_inc_vat" id="w_cert" required></div>
                    </div>
                    
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group"><label>Invoiced Value</label><input type="number" step="0.01" name="invoiced_value" id="w_inv_val"></div>
                        <div class="form-group"><label>Target Payment Date</label><input type="date" name="payment_date" id="w_date"></div>
                    </div>
                    <div class="form-group"><label>Invoice References (can be multiple lines)</label><textarea name="invoice_ref" id="w_inv_ref" rows="2"></textarea></div>
                    <div class="form-group"><label>Notes</label><textarea name="notes" id="w_notes" rows="2"></textarea></div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Save Work Certification</button>
                </form>
            </div>
        </div>

        <div id="txModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('txModal')">&times;</span>
                <h2 id="tModalTitle" style="color: var(--primary-color);">Log Transaction</h2>
                <form method="POST">
                    <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
                    <input type="hidden" name="action" value="save_transaction">
                    <input type="hidden" name="transaction_id" id="t_id">
                    
                    <?php if (!$sub_id): // Dropdown needed for Master Ledger View ?>
                        <div class="form-group">
                            <label>Subcontractor *</label>
                            <select name="subcontractor_id" required>
                                <option value="">-- Choose Subcontractor --</option>
                                <?php foreach($allSubcontractors as $subc): ?>
                                    <option value="<?= $subc['id'] ?>"><?= htmlspecialchars($subc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: // Hidden input for Detail View ?>
                        <input type="hidden" name="subcontractor_id" value="<?= $sub_id ?>">
                    <?php endif; ?>
                    
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group"><label>Date *</label><input type="date" name="transaction_date" id="t_date" required value="<?= date('Y-m-d') ?>"></div>
                        <div class="form-group">
                            <label>Type *</label>
                            <select name="transaction_type" id="t_type" required>
                                <option value="Payment">Payment (Credits Subcontractor)</option>
                                <option value="Invoice">Invoice</option>
                                <option value="Credit Note">Credit Note</option>
                                <option value="Adjustment">Adjustment</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group"><label>Amount *</label><input type="number" step="0.01" name="amount" id="t_amount" required></div>
                    <div class="form-group"><label>Reference (e.g. Cheque No / Transfer ID)</label><input type="text" name="reference" id="t_ref"></div>
                    <div class="form-group"><label>Notes</label><textarea name="notes" id="t_notes" rows="2"></textarea></div>
                    
                    <button type="submit" class="btn btn-secondary" style="width: 100%; margin-top: 10px;">Save Transaction</button>
                </form>
            </div>
        </div>

        <script>
        function openWorkModal(data = null) {
            if (data) {
                document.getElementById('wModalTitle').textContent = 'Edit Work';
                document.getElementById('w_id').value = data.id;
                document.getElementById('w_project_id').value = data.project_id || '';
                document.getElementById('w_ref').value = data.work_reference;
                document.getElementById('w_resp').value = data.responsible;
                document.getElementById('w_exc').value = data.total_exc_vat;
                document.getElementById('w_inc').value = data.total_inc_vat;
                document.getElementById('w_cert').value = data.certified_total_inc_vat;
                document.getElementById('w_inv_val').value = data.invoiced_value;
                document.getElementById('w_inv_ref').value = data.invoice_ref;
                document.getElementById('w_date').value = data.payment_date;
                document.getElementById('w_notes').value = data.notes;
            } else {
                document.getElementById('wModalTitle').textContent = 'Add Work';
                document.getElementById('w_id').value = '';
                document.getElementById('w_ref').value = '';
                document.getElementById('w_cert').value = '';
            }
            document.getElementById('workModal').style.display = 'block';
        }

        function openTxModal(data = null) {
            if (data) {
                document.getElementById('tModalTitle').textContent = 'Edit Transaction';
                document.getElementById('t_id').value = data.id;
                document.getElementById('t_date').value = data.transaction_date;
                document.getElementById('t_type').value = data.transaction_type;
                document.getElementById('t_amount').value = data.amount;
                document.getElementById('t_ref').value = data.reference;
                document.getElementById('t_notes').value = data.notes;
            } else {
                document.getElementById('tModalTitle').textContent = 'Log Transaction';
                document.getElementById('t_id').value = '';
                document.getElementById('t_amount').value = '';
                document.getElementById('t_ref').value = '';
            }
            document.getElementById('txModal').style.display = 'block';
        }

        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        window.onclick = function(event) {
            if (event.target == document.getElementById('workModal')) closeModal('workModal');
            if (event.target == document.getElementById('txModal')) closeModal('txModal');
        }
        </script>
        <?php endif; ?>

    <?php endif; // End selected_client_id check ?>
</div>

<?php require_once 'footer.php'; ?>
